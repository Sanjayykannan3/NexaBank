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
   CARD ACTIONS
   ========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verify_csrf_or_fail();

    $action = $_POST["action"] ?? "";
    $cardId = (int)($_POST["card_id"] ?? 0);

    $allowedActions = [
        "freeze",
        "unfreeze",
        "block",
        "activate"
    ];

    if (
        $cardId > 0 &&
        in_array($action, $allowedActions, true)
    ) {

        $status = null;

        if ($action === "freeze") {
            $status = "Frozen";
        } elseif ($action === "unfreeze") {
            $status = "Active";
        } elseif ($action === "block") {
            $status = "Blocked";
        } elseif ($action === "activate") {
            $status = "Active";
        }

        $cardStmt = $conn->prepare("
            SELECT
                c.card_last4,
                c.status,
                c.user_id,
                u.first_name,
                u.last_name
            FROM cards c
            INNER JOIN users u
                ON u.id = c.user_id
            WHERE c.id = ?
            LIMIT 1
        ");

        $cardStmt->bind_param("i", $cardId);
        $cardStmt->execute();

        $card = $cardStmt->get_result()->fetch_assoc();

        if (!$card) {

            $_SESSION["cards_error"] =
                "Card not found.";

            header("Location: cards.php");
            exit;
        }

        $update = $conn->prepare("
            UPDATE cards
            SET status = ?
            WHERE id = ?
        ");

        $update->bind_param(
            "si",
            $status,
            $cardId
        );

        if ($update->execute()) {

            $auditAction = "CARD_" . strtoupper($action);

            $description =
                "Administrator changed card ending in ****" .
                $card["card_last4"] .
                " to " .
                $status .
                ".";

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

            $_SESSION["cards_success"] =
                "Card status updated successfully.";

        } else {

            $_SESSION["cards_error"] =
                "Unable to update card status.";
        }

        header("Location: cards.php");
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
    "Frozen",
    "Blocked"
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = "all";
}

/* =========================
   CARD QUERY
   ========================= */

$cards = [];

$sql = "
    SELECT
        c.id,
        c.card_last4,
        c.card_type,
        c.status,
        c.online_enabled,
        c.international_enabled,
        c.contactless_enabled,
        c.atm_enabled,
        c.spending_limit,
        c.spent_amount,
        c.created_at,

        u.id AS user_id,
        u.first_name,
        u.last_name,
        u.email,

        a.account_number

    FROM cards c

    INNER JOIN users u
        ON u.id = c.user_id

    LEFT JOIN accounts a
        ON a.user_id = u.id

    WHERE u.role = 'customer'
";

$params = [];
$types = "";

if ($search !== "") {

    $sql .= "
        AND (
            c.card_last4 LIKE ?
            OR c.card_type LIKE ?
            OR u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR u.email LIKE ?
            OR a.account_number LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";

    for ($i = 0; $i < 6; $i++) {
        $params[] = $searchValue;
    }

    $types .= "ssssss";
}

if ($statusFilter !== "all") {

    $sql .= "
        AND c.status = ?
    ";

    $params[] = $statusFilter;
    $types .= "s";
}

$sql .= "
    ORDER BY c.created_at DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $cards[] = $row;
}

/* =========================
   STATISTICS
   ========================= */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM cards c
    INNER JOIN users u ON u.id = c.user_id
    WHERE u.role = 'customer'
");

$totalCards = (int)$result->fetch_assoc()["total"];


$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM cards c
    INNER JOIN users u ON u.id = c.user_id
    WHERE u.role = 'customer'
    AND c.status = 'Active'
");

$activeCards = (int)$result->fetch_assoc()["total"];


$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM cards c
    INNER JOIN users u ON u.id = c.user_id
    WHERE u.role = 'customer'
    AND c.status = 'Frozen'
");

$frozenCards = (int)$result->fetch_assoc()["total"];


$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM cards c
    INNER JOIN users u ON u.id = c.user_id
    WHERE u.role = 'customer'
    AND c.status = 'Blocked'
");

$blockedCards = (int)$result->fetch_assoc()["total"];


/* =========================
   ADMIN INFO
   ========================= */

$adminName =
    $admin["first_name"] . " " . $admin["last_name"];

$adminInitials = initials(
    $admin["first_name"],
    $admin["last_name"]
);

$successMessage = $_SESSION["cards_success"] ?? "";
$errorMessage = $_SESSION["cards_error"] ?? "";

unset($_SESSION["cards_success"]);
unset($_SESSION["cards_error"]);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>NexaBank - Card Management</title>

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
   FILTER BAR
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
    width: 145px;
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

/* =========================
   TABLE
   ========================= */

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1250px;
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
    gap: 9px;
}

.user-avatar {
    width: 33px;
    height: 33px;
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
    font-size: 8px;
    margin-top: 3px;
}

.card-number {
    font-family: monospace;
    letter-spacing: 1px;
}

.card-type {
    color: #94a3b8;
    font-size: 10px;
}

.account-number {
    font-family: monospace;
    font-size: 9px;
    color: #94a3b8;
}

.progress {
    width: 100px;
    height: 5px;
    background: #172538;
    border-radius: 5px;
    overflow: hidden;
    margin-top: 6px;
}

.progress-bar {
    height: 100%;
    background: #38bdf8;
    border-radius: 5px;
}

.spending-text {
    font-size: 9px;
    color: #64748b;
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

.badge-frozen {
    background: #3d2f0a;
    color: #facc15;
}

.badge-blocked {
    background: #421d24;
    color: #fb7185;
}

/* =========================
   ACTIONS
   ========================= */

.action-form {
    display: inline;
}

.action-btn {
    border: 0;
    border-radius: 7px;
    padding: 7px 9px;
    cursor: pointer;
    font-size: 9px;
    font-weight: 600;
    margin-right: 3px;
}

.freeze-btn {
    background: #3d2f0a;
    color: #facc15;
}

.unfreeze-btn {
    background: #0b3328;
    color: #4ade80;
}

.block-btn {
    background: #3b1820;
    color: #fb7185;
}

.activate-btn {
    background: #0b3328;
    color: #4ade80;
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

    <a href="accounts.php" class="nav-item">
        <i class="fa-solid fa-wallet"></i>
        Accounts
    </a>

    <a href="transactions.php" class="nav-item">
        <i class="fa-solid fa-arrow-right-arrow-left"></i>
        Transactions
    </a>

    <a href="cards.php" class="nav-item active">
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

            <h1>Card Management</h1>

            <p>
                Manage NexaBank customer cards and security status
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


    <!-- =========================
         ALERTS
         ========================= -->

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
                <i class="fa-solid fa-credit-card"></i>
            </div>

            <h3>
                <?= number_format($totalCards) ?>
            </h3>

            <p>
                Total Cards
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <h3>
                <?= number_format($activeCards) ?>
            </h3>

            <p>
                Active Cards
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-snowflake"></i>
            </div>

            <h3>
                <?= number_format($frozenCards) ?>
            </h3>

            <p>
                Frozen Cards
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-ban"></i>
            </div>

            <h3>
                <?= number_format($blockedCards) ?>
            </h3>

            <p>
                Blocked Cards
            </p>

        </div>

    </section>


    <!-- =========================
         CARD PANEL
         ========================= -->

    <section class="panel">

        <div class="panel-header">

            <h2>
                Customer Cards
            </h2>

            <p>
                Search and manage customer card status.
            </p>

        </div>


        <!-- FILTER -->

        <form
            method="GET"
            action="cards.php"
            class="filter-bar"
        >

            <div class="search-box">

                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search card, customer, email or account..."
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

                <option value="Frozen"
                    <?= $statusFilter === "Frozen" ? "selected" : "" ?>>
                    Frozen
                </option>

                <option value="Blocked"
                    <?= $statusFilter === "Blocked" ? "selected" : "" ?>>
                    Blocked
                </option>

            </select>


            <button
                type="submit"
                class="filter-btn"
            >

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
                        Card
                    </th>

                    <th>
                        Account
                    </th>

                    <th>
                        Spending
                    </th>

                    <th>
                        Online
                    </th>

                    <th>
                        International
                    </th>

                    <th>
                        Contactless
                    </th>

                    <th>
                        ATM
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Action
                    </th>

                </tr>

                </thead>


                <tbody>

                <?php if (empty($cards)): ?>

                    <tr>

                        <td colspan="10">

                            <div class="empty">

                                <i class="fa-solid fa-credit-card"></i>

                                <p>
                                    No cards found.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php else: ?>


                    <?php foreach ($cards as $card): ?>

                        <?php

                        $customerInitials = initials(
                            $card["first_name"],
                            $card["last_name"]
                        );

                        $limit =
                            (float)$card["spending_limit"];

                        $spent =
                            (float)$card["spent_amount"];

                        $percentage = $limit > 0
                            ? ($spent / $limit) * 100
                            : 0;

                        $percentage =
                            min(100, max(0, $percentage));

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
                                                $card["first_name"] .
                                                " " .
                                                $card["last_name"]
                                            ) ?>

                                        </div>

                                        <div class="user-email">

                                            <?= e(
                                                $card["email"]
                                            ) ?>

                                        </div>

                                    </div>

                                </div>

                            </td>


                            <!-- CARD -->

                            <td>

                                <div class="card-number">

                                    •••• <?= e(
                                        $card["card_last4"]
                                    ) ?>

                                </div>

                                <div class="card-type">

                                    <?= e(
                                        $card["card_type"]
                                    ) ?>

                                </div>

                            </td>


                            <!-- ACCOUNT -->

                            <td>

                                <span class="account-number">

                                    <?= e(
                                        $card["account_number"]
                                        ?? "-"
                                    ) ?>

                                </span>

                            </td>


                            <!-- SPENDING -->

                            <td>

                                <div class="spending-text">

                                    ₹<?= number_format(
                                        $spent,
                                        2
                                    ) ?>

                                    /
                                    ₹<?= number_format(
                                        $limit,
                                        2
                                    ) ?>

                                </div>

                                <div class="progress">

                                    <div
                                        class="progress-bar"
                                        style="width: <?= $percentage ?>%;"
                                    ></div>

                                </div>

                            </td>


                            <!-- ONLINE -->

                            <td>

                                <?php if (
                                    $card["online_enabled"]
                                ): ?>

                                    <span class="badge badge-active">
                                        ON
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-blocked">
                                        OFF
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- INTERNATIONAL -->

                            <td>

                                <?php if (
                                    $card["international_enabled"]
                                ): ?>

                                    <span class="badge badge-active">
                                        ON
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-blocked">
                                        OFF
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- CONTACTLESS -->

                            <td>

                                <?php if (
                                    $card["contactless_enabled"]
                                ): ?>

                                    <span class="badge badge-active">
                                        ON
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-blocked">
                                        OFF
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ATM -->

                            <td>

                                <?php if (
                                    $card["atm_enabled"]
                                ): ?>

                                    <span class="badge badge-active">
                                        ON
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-blocked">
                                        OFF
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <?php

                                if (
                                    $card["status"] === "Active"
                                ) {

                                    $badgeClass =
                                        "badge-active";

                                } elseif (
                                    $card["status"] === "Frozen"
                                ) {

                                    $badgeClass =
                                        "badge-frozen";

                                } else {

                                    $badgeClass =
                                        "badge-blocked";
                                }

                                ?>

                                <span
                                    class="badge <?= $badgeClass ?>"
                                >

                                    <?= e(
                                        $card["status"]
                                    ) ?>

                                </span>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <?php if (
                                    $card["status"] === "Active"
                                ): ?>

                                    <!-- FREEZE -->

                                    <form
                                        method="POST"
                                        action="cards.php"
                                        class="action-form"
                                        onsubmit="return confirm('Freeze this card?');"
                                    >

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="freeze"
                                        >

                                        <input
                                            type="hidden"
                                            name="card_id"
                                            value="<?= (int)$card["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn freeze-btn"
                                        >

                                            <i class="fa-solid fa-snowflake"></i>

                                            Freeze

                                        </button>

                                    </form>


                                    <!-- BLOCK -->

                                    <form
                                        method="POST"
                                        action="cards.php"
                                        class="action-form"
                                        onsubmit="return confirm('Permanently block this card?');"
                                    >

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="block"
                                        >

                                        <input
                                            type="hidden"
                                            name="card_id"
                                            value="<?= (int)$card["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn block-btn"
                                        >

                                            <i class="fa-solid fa-ban"></i>

                                            Block

                                        </button>

                                    </form>


                                <?php elseif (
                                    $card["status"] === "Frozen"
                                ): ?>

                                    <form
                                        method="POST"
                                        action="cards.php"
                                        class="action-form"
                                        onsubmit="return confirm('Unfreeze this card?');"
                                    >

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="unfreeze"
                                        >

                                        <input
                                            type="hidden"
                                            name="card_id"
                                            value="<?= (int)$card["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn unfreeze-btn"
                                        >

                                            <i class="fa-solid fa-unlock"></i>

                                            Unfreeze

                                        </button>

                                    </form>


                                    <form
                                        method="POST"
                                        action="cards.php"
                                        class="action-form"
                                        onsubmit="return confirm('Block this card?');"
                                    >

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="block"
                                        >

                                        <input
                                            type="hidden"
                                            name="card_id"
                                            value="<?= (int)$card["id"] ?>"
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

                                    <form
                                        method="POST"
                                        action="cards.php"
                                        class="action-form"
                                        onsubmit="return confirm('Activate this card?');"
                                    >

                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="activate"
                                        >

                                        <input
                                            type="hidden"
                                            name="card_id"
                                            value="<?= (int)$card["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn activate-btn"
                                        >

                                            <i class="fa-solid fa-check"></i>

                                            Activate

                                        </button>

                                    </form>

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
