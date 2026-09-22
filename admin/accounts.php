<?php
session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/csrf.php";

/* =========================
   ADMIN SECURITY
   ========================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$userId = (int)$_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, email, role
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();

if (!$admin || $admin["role"] !== "admin") {
    http_response_code(403);
    exit("Access denied. Admin privileges required.");
}

/* =========================
   HELPERS
   ========================= */

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function initials($first, $last)
{
    return strtoupper(
        substr($first, 0, 1) .
        substr($last, 0, 1)
    );
}

/* =========================
   BLOCK / UNBLOCK ACCOUNT
   ========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verify_csrf_or_fail();

    $action = $_POST["action"] ?? "";
    $accountId = (int)($_POST["account_id"] ?? 0);

    if (
        $accountId > 0 &&
        in_array($action, ["block", "unblock"], true)
    ) {

        $newStatus = $action === "block"
            ? "Blocked"
            : "Active";

        /*
         * Get account owner first.
         */
        $ownerStmt = $conn->prepare("
            SELECT user_id, account_number
            FROM accounts
            WHERE id = ?
            LIMIT 1
        ");

        $ownerStmt->bind_param("i", $accountId);
        $ownerStmt->execute();

        $accountOwner = $ownerStmt
            ->get_result()
            ->fetch_assoc();

        if (!$accountOwner) {

            $_SESSION["accounts_error"] =
                "Account not found.";

            header("Location: accounts.php");
            exit;
        }

        /*
         * Update account status.
         */
        $stmt = $conn->prepare("
            UPDATE accounts
            SET status = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "si",
            $newStatus,
            $accountId
        );

        if ($stmt->execute()) {

            $auditAction = $action === "block"
                ? "ACCOUNT_BLOCKED"
                : "ACCOUNT_UNBLOCKED";

            $description =
                ($action === "block"
                    ? "Administrator blocked account "
                    : "Administrator unblocked account ")
                . $accountOwner["account_number"];

            $ip = $_SERVER["REMOTE_ADDR"] ?? null;
            $agent = $_SERVER["HTTP_USER_AGENT"] ?? null;

            $audit = $conn->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    description,
                    ip_address,
                    user_agent
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $audit->bind_param(
                "issss",
                $userId,
                $auditAction,
                $description,
                $ip,
                $agent
            );

            $audit->execute();

            $_SESSION["accounts_success"] =
                $action === "block"
                ? "Account blocked successfully."
                : "Account unblocked successfully.";

        } else {

            $_SESSION["accounts_error"] =
                "Unable to update account status.";
        }

        header("Location: accounts.php");
        exit;
    }
}

/* =========================
   SEARCH / FILTER
   ========================= */

$search = trim($_GET["search"] ?? "");
$statusFilter = $_GET["status"] ?? "all";

$allowedStatuses = [
    "all",
    "Active",
    "Blocked",
    "Closed"
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = "all";
}

/* =========================
   ACCOUNT QUERY
   ========================= */

$accounts = [];

$sql = "
    SELECT
        a.id,
        a.account_number,
        a.ifsc,
        a.account_type,
        a.balance,
        a.status,
        a.created_at,

        u.id AS user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.status AS user_status

    FROM accounts a

    INNER JOIN users u
        ON u.id = a.user_id

    WHERE u.role = 'customer'
";

$params = [];
$types = "";

if ($search !== "") {

    $sql .= "
        AND (
            a.account_number LIKE ?
            OR a.ifsc LIKE ?
            OR a.account_type LIKE ?
            OR u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";

    for ($i = 0; $i < 7; $i++) {
        $params[] = $searchValue;
    }

    $types .= "sssssss";
}

if ($statusFilter !== "all") {

    $sql .= " AND a.status = ? ";

    $params[] = $statusFilter;
    $types .= "s";
}

$sql .= "
    ORDER BY a.created_at DESC
";

/* =========================
   EXECUTE QUERY
   ========================= */

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $accounts[] = $row;
}

/* =========================
   STATISTICS
   ========================= */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM accounts a
    INNER JOIN users u ON u.id = a.user_id
    WHERE u.role = 'customer'
");

$totalAccounts = (int)$result->fetch_assoc()["total"];

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM accounts a
    INNER JOIN users u ON u.id = a.user_id
    WHERE u.role = 'customer'
    AND a.status = 'Active'
");

$activeAccounts = (int)$result->fetch_assoc()["total"];

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM accounts a
    INNER JOIN users u ON u.id = a.user_id
    WHERE u.role = 'customer'
    AND a.status = 'Blocked'
");

$blockedAccounts = (int)$result->fetch_assoc()["total"];

$result = $conn->query("
    SELECT COALESCE(SUM(a.balance), 0) AS total
    FROM accounts a
    INNER JOIN users u ON u.id = a.user_id
    WHERE u.role = 'customer'
    AND a.status = 'Active'
");

$totalBalance = (float)$result->fetch_assoc()["total"];

/* =========================
   ADMIN INFO
   ========================= */

$adminName =
    $admin["first_name"] . " " . $admin["last_name"];

$adminInitials = initials(
    $admin["first_name"],
    $admin["last_name"]
);

$successMessage = $_SESSION["accounts_success"] ?? "";
$errorMessage = $_SESSION["accounts_error"] ?? "";

unset($_SESSION["accounts_success"]);
unset($_SESSION["accounts_error"]);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>NexaBank - Account Management</title>

<link rel="preconnect"
      href="https://fonts.googleapis.com">

<link rel="preconnect"
      href="https://fonts.gstatic.com"
      crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
      rel="stylesheet">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "Inter", sans-serif;
    background: #07111f;
    color: #e5edf7;
    min-height: 100vh;
}

a {
    color: inherit;
    text-decoration: none;
}

button,
input,
select {
    font-family: inherit;
}

/* =========================
   SIDEBAR
   ========================= */

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 250px;
    height: 100vh;
    background: #0b1727;
    border-right: 1px solid #1d2c40;
    padding: 25px 16px;
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 5px 12px 30px;
}

.logo-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #2563eb, #06b6d4);
    display: flex;
    align-items: center;
    justify-content: center;
}

.logo-text {
    font-size: 20px;
    font-weight: 800;
}

.logo-text span {
    color: #38bdf8;
}

.admin-label {
    font-size: 10px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    padding: 0 14px 12px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 12px 14px;
    margin-bottom: 5px;
    border-radius: 10px;
    color: #94a3b8;
    font-size: 13px;
}

.nav-item i {
    width: 18px;
    text-align: center;
}

.nav-item:hover,
.nav-item.active {
    background: #13253b;
    color: #fff;
}

.nav-item.active {
    border-left: 3px solid #38bdf8;
}

.sidebar-bottom {
    position: absolute;
    bottom: 22px;
    left: 16px;
    right: 16px;
}

/* =========================
   MAIN
   ========================= */

.main {
    margin-left: 250px;
    padding: 28px 32px;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
}

.page-title h1 {
    font-size: 25px;
    font-weight: 800;
}

.page-title p {
    margin-top: 5px;
    font-size: 12px;
    color: #64748b;
}

.admin-profile {
    display: flex;
    align-items: center;
    gap: 11px;
}

.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #06b6d4);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
}

.admin-info {
    text-align: right;
}

.admin-info strong {
    display: block;
    font-size: 12px;
}

.admin-info span {
    color: #64748b;
    font-size: 10px;
}

/* =========================
   ALERTS
   ========================= */

.alert {
    padding: 13px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 11px;
}

.alert-success {
    background: #0b3328;
    border: 1px solid #155e4a;
    color: #4ade80;
}

.alert-error {
    background: #3b1820;
    border: 1px solid #6b2432;
    color: #fb7185;
}

/* =========================
   STATS
   ========================= */

.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 22px;
}

.stat-card {
    background: #0c192a;
    border: 1px solid #1d2c40;
    border-radius: 14px;
    padding: 18px;
}

.stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #13253b;
    color: #38bdf8;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-card h3 {
    margin-top: 14px;
    font-size: 22px;
}

.stat-card p {
    margin-top: 4px;
    color: #64748b;
    font-size: 10px;
}

/* =========================
   PANEL
   ========================= */

.panel {
    background: #0c192a;
    border: 1px solid #1d2c40;
    border-radius: 15px;
    overflow: hidden;
}

.panel-header {
    padding: 18px 20px;
    border-bottom: 1px solid #1d2c40;
}

.panel-header h2 {
    font-size: 15px;
}

.panel-header p {
    margin-top: 4px;
    color: #64748b;
    font-size: 10px;
}

/* =========================
   FILTER
   ========================= */

.filter-bar {
    display: flex;
    gap: 10px;
    padding: 17px 20px;
    border-bottom: 1px solid #1d2c40;
}

.search-box {
    flex: 1;
    position: relative;
}

.search-box i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 12px;
}

.search-box input {
    width: 100%;
    height: 40px;
    padding: 0 14px 0 36px;
    border: 1px solid #24364d;
    border-radius: 9px;
    background: #081523;
    color: #e5edf7;
    outline: none;
    font-size: 11px;
}

.search-box input:focus {
    border-color: #2563eb;
}

.filter-bar select {
    width: 150px;
    height: 40px;
    padding: 0 12px;
    border: 1px solid #24364d;
    border-radius: 9px;
    background: #081523;
    color: #e5edf7;
    outline: none;
    font-size: 11px;
}

.filter-btn {
    height: 40px;
    padding: 0 17px;
    border: 0;
    border-radius: 9px;
    background: #2563eb;
    color: white;
    cursor: pointer;
    font-size: 11px;
    font-weight: 600;
}

.filter-btn:hover {
    background: #1d4ed8;
}

/* =========================
   TABLE
   ========================= */

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1050px;
}

th {
    text-align: left;
    padding: 13px 16px;
    color: #64748b;
    font-size: 9px;
    text-transform: uppercase;
    border-bottom: 1px solid #1d2c40;
}

td {
    padding: 14px 16px;
    font-size: 11px;
    border-bottom: 1px solid #132236;
    vertical-align: middle;
}

tr:last-child td {
    border-bottom: 0;
}

.user-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #16304d;
    color: #7dd3fc;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 700;
}

.user-name {
    font-weight: 600;
}

.user-email {
    color: #64748b;
    font-size: 9px;
    margin-top: 3px;
}

.account-number {
    font-family: monospace;
    letter-spacing: .5px;
}

.ifsc {
    margin-top: 4px;
    color: #64748b;
    font-size: 9px;
}

.balance {
    font-weight: 700;
}

.badge {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 6px;
    font-size: 8px;
    font-weight: 700;
}

.badge-active {
    background: #0b3b2b;
    color: #4ade80;
}

.badge-blocked {
    background: #421d24;
    color: #fb7185;
}

.badge-closed {
    background: #263142;
    color: #94a3b8;
}

/* =========================
   ACTION
   ========================= */

.action-form {
    display: inline;
}

.action-btn {
    border: 0;
    border-radius: 7px;
    padding: 7px 10px;
    cursor: pointer;
    font-size: 9px;
    font-weight: 600;
}

.block-btn {
    background: #3b1820;
    color: #fb7185;
}

.block-btn:hover {
    background: #55202b;
}

.unblock-btn {
    background: #0b3328;
    color: #4ade80;
}

.unblock-btn:hover {
    background: #104938;
}

/* =========================
   EMPTY
   ========================= */

.empty {
    text-align: center;
    padding: 55px 20px;
    color: #64748b;
}

.empty i {
    font-size: 30px;
    margin-bottom: 12px;
}

.empty p {
    font-size: 11px;
}

/* =========================
   RESPONSIVE
   ========================= */

@media (max-width: 1100px) {

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 800px) {

    .sidebar {
        width: 210px;
    }

    .main {
        margin-left: 210px;
        padding: 20px;
    }

    .filter-bar {
        flex-wrap: wrap;
    }

    .search-box {
        flex-basis: 100%;
    }
}

@media (max-width: 600px) {

    .sidebar {
        position: static;
        width: 100%;
        height: auto;
    }

    .sidebar-bottom {
        position: static;
        margin-top: 20px;
    }

    .main {
        margin-left: 0;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .admin-info {
        display: none;
    }
}

</style>

</head>

<body>

<!-- =========================
     SIDEBAR
     ========================= -->

<aside class="sidebar">

    <div class="logo">

        <div class="logo-icon">
            <i class="fa-solid fa-building-columns"></i>
        </div>

        <div class="logo-text">
            Nexa<span>Bank</span>
        </div>

    </div>

    <div class="admin-label">
        Administration
    </div>

    <a href="dashboard.php" class="nav-item">
        <i class="fa-solid fa-chart-pie"></i>
        Dashboard
    </a>

    <a href="users.php" class="nav-item">
        <i class="fa-solid fa-users"></i>
        Users
    </a>

    <a href="accounts.php" class="nav-item active">
        <i class="fa-solid fa-wallet"></i>
        Accounts
    </a>

    <a href="transactions.php" class="nav-item">
        <i class="fa-solid fa-arrow-right-arrow-left"></i>
        Transactions
    </a>

    <a href="cards.php" class="nav-item">
        <i class="fa-solid fa-credit-card"></i>
        Cards
    </a>

    <a href="notifications.php" class="nav-item">
        <i class="fa-solid fa-bell"></i>
        Notifications
    </a>

    <a href="audit_logs.php" class="nav-item">
        <i class="fa-solid fa-shield-halved"></i>
        Audit Logs
    </a>

    <div class="sidebar-bottom">

        <a href="../dashboard.php" class="nav-item">
            <i class="fa-solid fa-arrow-left"></i>
            Customer Portal
        </a>

        <a href="logout.php" class="nav-item">
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </a>

    </div>

</aside>


<!-- =========================
     MAIN
     ========================= -->

<main class="main">

    <div class="topbar">

        <div class="page-title">

            <h1>Account Management</h1>

            <p>
                Manage NexaBank customer accounts
            </p>

        </div>

        <div class="admin-profile">

            <div class="admin-info">

                <strong>
                    <?= e($adminName) ?>
                </strong>

                <span>
                    Administrator
                </span>

            </div>

            <div class="avatar">
                <?= e($adminInitials) ?>
            </div>

        </div>

    </div>


    <!-- ALERTS -->

    <?php if ($successMessage): ?>

        <div class="alert alert-success">

            <i class="fa-solid fa-circle-check"></i>

            <?= e($successMessage) ?>

        </div>

    <?php endif; ?>


    <?php if ($errorMessage): ?>

        <div class="alert alert-error">

            <i class="fa-solid fa-circle-exclamation"></i>

            <?= e($errorMessage) ?>

        </div>

    <?php endif; ?>


    <!-- =========================
         STATISTICS
         ========================= -->

    <section class="stats">

        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-wallet"></i>
            </div>

            <h3>
                <?= number_format($totalAccounts) ?>
            </h3>

            <p>
                Total Accounts
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <h3>
                <?= number_format($activeAccounts) ?>
            </h3>

            <p>
                Active Accounts
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-ban"></i>
            </div>

            <h3>
                <?= number_format($blockedAccounts) ?>
            </h3>

            <p>
                Blocked Accounts
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-indian-rupee-sign"></i>
            </div>

            <h3>
                ₹<?= number_format($totalBalance, 2) ?>
            </h3>

            <p>
                Active Account Balance
            </p>

        </div>

    </section>


    <!-- =========================
         ACCOUNT PANEL
         ========================= -->

    <section class="panel">

        <div class="panel-header">

            <h2>
                Bank Accounts
            </h2>

            <p>
                Search and manage customer bank accounts.
            </p>

        </div>


        <!-- FILTER -->

        <form method="GET"
              action="accounts.php"
              class="filter-bar">

            <div class="search-box">

                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search account, IFSC, customer or email..."
                >

            </div>


            <select name="status">

                <option value="all"
                    <?= $statusFilter === "all" ? "selected" : "" ?>>
                    All Status
                </option>

                <option value="Active"
                    <?= $statusFilter === "Active" ? "selected" : "" ?>>
                    Active
                </option>

                <option value="Blocked"
                    <?= $statusFilter === "Blocked" ? "selected" : "" ?>>
                    Blocked
                </option>

                <option value="Closed"
                    <?= $statusFilter === "Closed" ? "selected" : "" ?>>
                    Closed
                </option>

            </select>


            <button type="submit"
                    class="filter-btn">

                <i class="fa-solid fa-filter"></i>
                Filter

            </button>

        </form>


        <!-- TABLE -->

        <div class="table-wrapper">

            <table>

                <thead>

                <tr>

                    <th>
                        Customer
                    </th>

                    <th>
                        Account Number
                    </th>

                    <th>
                        IFSC
                    </th>

                    <th>
                        Type
                    </th>

                    <th>
                        Balance
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Created
                    </th>

                    <th>
                        Action
                    </th>

                </tr>

                </thead>


                <tbody>

                <?php if (empty($accounts)): ?>

                    <tr>

                        <td colspan="8">

                            <div class="empty">

                                <i class="fa-solid fa-wallet"></i>

                                <p>
                                    No accounts found.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php else: ?>


                    <?php foreach ($accounts as $account): ?>

                        <?php
                        $customerInitials = initials(
                            $account["first_name"],
                            $account["last_name"]
                        );
                        ?>

                        <tr>

                            <!-- CUSTOMER -->

                            <td>

                                <div class="user-cell">

                                    <div class="user-avatar">

                                        <?= e(
                                            $customerInitials
                                        ) ?>

                                    </div>

                                    <div>

                                        <div class="user-name">

                                            <?= e(
                                                $account["first_name"] .
                                                " " .
                                                $account["last_name"]
                                            ) ?>

                                        </div>

                                        <div class="user-email">

                                            <?= e(
                                                $account["email"]
                                            ) ?>

                                        </div>

                                    </div>

                                </div>

                            </td>


                            <!-- ACCOUNT NUMBER -->

                            <td>

                                <div class="account-number">

                                    <?= e(
                                        $account["account_number"]
                                    ) ?>

                                </div>

                            </td>


                            <!-- IFSC -->

                            <td>

                                <?= e(
                                    $account["ifsc"]
                                ) ?>

                            </td>


                            <!-- TYPE -->

                            <td>

                                <?= e(
                                    $account["account_type"]
                                ) ?>

                            </td>


                            <!-- BALANCE -->

                            <td>

                                <span class="balance">

                                    ₹<?= number_format(
                                        (float)$account["balance"],
                                        2
                                    ) ?>

                                </span>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <?php

                                if (
                                    $account["status"] === "Active"
                                ) {

                                    $badgeClass =
                                        "badge-active";

                                } elseif (
                                    $account["status"] === "Blocked"
                                ) {

                                    $badgeClass =
                                        "badge-blocked";

                                } else {

                                    $badgeClass =
                                        "badge-closed";
                                }

                                ?>

                                <span class="badge <?= $badgeClass ?>">

                                    <?= e(
                                        $account["status"]
                                    ) ?>

                                </span>

                            </td>


                            <!-- CREATED -->

                            <td>

                                <?= date(
                                    "d M Y",
                                    strtotime(
                                        $account["created_at"]
                                    )
                                ) ?>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <?php if (
                                    $account["status"] === "Blocked"
                                ): ?>

                                    <form
                                        method="POST"
                                        action="accounts.php"
                                        class="action-form"
                                        onsubmit="return confirm('Unblock this account?');"
                                    >

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="unblock"
                                        >

                                        <input
                                            type="hidden"
                                            name="account_id"
                                            value="<?= (int)$account["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn unblock-btn"
                                        >

                                            <i class="fa-solid fa-unlock"></i>

                                            Unblock

                                        </button>

                                    </form>

                                <?php elseif (
                                    $account["status"] === "Active"
                                ): ?>

                                    <form
                                        method="POST"
                                        action="accounts.php"
                                        class="action-form"
                                        onsubmit="return confirm('Block this account?');"
                                    >

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="block"
                                        >

                                        <input
                                            type="hidden"
                                            name="account_id"
                                            value="<?= (int)$account["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn block-btn"
                                        >

                                            <i class="fa-solid fa-ban"></i>

                                            Block

                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="muted">
                                        Closed
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

