<?php
session_start();

require_once __DIR__ . "/../config/db.php";

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
   SEARCH / FILTERS
   ========================= */

$search = trim($_GET["search"] ?? "");
$typeFilter = $_GET["type"] ?? "all";
$statusFilter = $_GET["status"] ?? "all";

$allowedTypes = [
    "all",
    "credit",
    "debit"
];

$allowedStatuses = [
    "all",
    "success",
    "pending",
    "failed"
];

if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = "all";
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = "all";
}

/* =========================
   TRANSACTION QUERY
   ========================= */

$transactions = [];

$sql = "
    SELECT
        t.id,
        t.type,
        t.amount,
        t.counterparty,
        t.category,
        t.description,
        t.status,
        t.reference,
        t.created_at,

        u.id AS user_id,
        u.first_name,
        u.last_name,
        u.email,

        a.account_number

    FROM transactions t

    INNER JOIN users u
        ON u.id = t.user_id

    LEFT JOIN accounts a
        ON a.id = t.account_id

    WHERE 1 = 1
";

$params = [];
$types = "";

/* Search */

if ($search !== "") {

    $sql .= "
        AND (
            t.reference LIKE ?
            OR t.counterparty LIKE ?
            OR t.category LIKE ?
            OR t.description LIKE ?
            OR u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR u.email LIKE ?
            OR a.account_number LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";

    for ($i = 0; $i < 8; $i++) {
        $params[] = $searchValue;
    }

    $types .= "ssssssss";
}

/* Type */

if ($typeFilter !== "all") {

    $sql .= " AND t.type = ? ";

    $params[] = $typeFilter;
    $types .= "s";
}

/* Status */

if ($statusFilter !== "all") {

    $sql .= " AND t.status = ? ";

    $params[] = $statusFilter;
    $types .= "s";
}

$sql .= "
    ORDER BY t.created_at DESC
";

/* =========================
   EXECUTE
   ========================= */

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}

/* =========================
   STATISTICS
   ========================= */

/* Total */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
");

$totalTransactions =
    (int)$result->fetch_assoc()["total"];


/* Credits */

$result = $conn->query("
    SELECT
        COUNT(*) AS count,
        COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE type = 'credit'
");

$creditData = $result->fetch_assoc();

$totalCredits =
    (int)$creditData["count"];

$creditAmount =
    (float)$creditData["total"];


/* Debits */

$result = $conn->query("
    SELECT
        COUNT(*) AS count,
        COALESCE(SUM(amount), 0) AS total
    FROM transactions
    WHERE type = 'debit'
");

$debitData = $result->fetch_assoc();

$totalDebits =
    (int)$debitData["count"];

$debitAmount =
    (float)$debitData["total"];


/* Pending */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
    WHERE status = 'pending'
");

$pendingTransactions =
    (int)$result->fetch_assoc()["total"];


/* Failed */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
    WHERE status = 'failed'
");

$failedTransactions =
    (int)$result->fetch_assoc()["total"];


/* =========================
   ADMIN INFO
   ========================= */

$adminName =
    $admin["first_name"] . " " . $admin["last_name"];

$adminInitials = initials(
    $admin["first_name"],
    $admin["last_name"]
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>NexaBank - Transaction Management</title>

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
    font-size: 21px;
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
    width: 140px;
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
    min-width: 1200px;
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
    width: 32px;
    height: 32px;
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

.account-number {
    font-family: monospace;
    font-size: 10px;
}

.counterparty {
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.reference {
    font-family: monospace;
    font-size: 9px;
    color: #94a3b8;
}

.credit {
    color: #4ade80;
    font-weight: 700;
}

.debit {
    color: #fb7185;
    font-weight: 700;
}

.badge {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 6px;
    font-size: 8px;
    font-weight: 700;
}

.badge-success {
    background: #0b3b2b;
    color: #4ade80;
}

.badge-pending {
    background: #3d2f0a;
    color: #facc15;
}

.badge-failed {
    background: #421d24;
    color: #fb7185;
}

.type-credit {
    color: #4ade80;
}

.type-debit {
    color: #fb7185;
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

    <a href="transactions.php" class="nav-item active">
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

            <h1>Transaction Management</h1>

            <p>
                Monitor all NexaBank customer transactions
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
         STATISTICS
         ========================= -->

    <section class="stats">

        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-arrow-right-arrow-left"></i>
            </div>

            <h3>
                <?= number_format($totalTransactions) ?>
            </h3>

            <p>
                Total Transactions
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-arrow-down"></i>
            </div>

            <h3>
                ₹<?= number_format($creditAmount, 2) ?>
            </h3>

            <p>
                Total Credits (<?= number_format($totalCredits) ?>)
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-arrow-up"></i>
            </div>

            <h3>
                ₹<?= number_format($debitAmount, 2) ?>
            </h3>

            <p>
                Total Debits (<?= number_format($totalDebits) ?>)
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-clock"></i>
            </div>

            <h3>
                <?= number_format($pendingTransactions) ?>
            </h3>

            <p>
                Pending Transactions
            </p>

        </div>

    </section>


    <!-- =========================
         TRANSACTIONS
         ========================= -->

    <section class="panel">

        <div class="panel-header">

            <h2>
                All Transactions
            </h2>

            <p>
                Search transactions by customer, account, reference or counterparty.
            </p>

        </div>


        <!-- FILTER BAR -->

        <form
            method="GET"
            action="transactions.php"
            class="filter-bar"
        >

            <div class="search-box">

                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search customer, account, reference or counterparty..."
                >

            </div>


            <select name="type">

                <option value="all"
                    <?= $typeFilter === "all" ? "selected" : "" ?>>
                    All Types
                </option>

                <option value="credit"
                    <?= $typeFilter === "credit" ? "selected" : "" ?>>
                    Credit
                </option>

                <option value="debit"
                    <?= $typeFilter === "debit" ? "selected" : "" ?>>
                    Debit
                </option>

            </select>


            <select name="status">

                <option value="all"
                    <?= $statusFilter === "all" ? "selected" : "" ?>>
                    All Status
                </option>

                <option value="success"
                    <?= $statusFilter === "success" ? "selected" : "" ?>>
                    Success
                </option>

                <option value="pending"
                    <?= $statusFilter === "pending" ? "selected" : "" ?>>
                    Pending
                </option>

                <option value="failed"
                    <?= $statusFilter === "failed" ? "selected" : "" ?>>
                    Failed
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
                        Account
                    </th>

                    <th>
                        Type
                    </th>

                    <th>
                        Amount
                    </th>

                    <th>
                        Counterparty
                    </th>

                    <th>
                        Category
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Reference
                    </th>

                    <th>
                        Date
                    </th>

                </tr>

                </thead>


                <tbody>

                <?php if (empty($transactions)): ?>

                    <tr>

                        <td colspan="9">

                            <div class="empty">

                                <i class="fa-solid fa-receipt"></i>

                                <p>
                                    No transactions found.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php else: ?>


                    <?php foreach ($transactions as $transaction): ?>

                        <?php

                        $customerInitials = initials(
                            $transaction["first_name"],
                            $transaction["last_name"]
                        );

                        $status =
                            strtolower($transaction["status"]);

                        $statusClass =
                            $status === "success"
                            ? "badge-success"
                            : (
                                $status === "pending"
                                ? "badge-pending"
                                : "badge-failed"
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
                                                $transaction["first_name"] .
                                                " " .
                                                $transaction["last_name"]
                                            ) ?>

                                        </div>

                                        <div class="user-email">

                                            <?= e(
                                                $transaction["email"]
                                            ) ?>

                                        </div>

                                    </div>

                                </div>

                            </td>


                            <!-- ACCOUNT -->

                            <td>

                                <span class="account-number">

                                    <?= e(
                                        $transaction["account_number"]
                                        ?? "-"
                                    ) ?>

                                </span>

                            </td>


                            <!-- TYPE -->

                            <td>

                                <?php if (
                                    $transaction["type"] === "credit"
                                ): ?>

                                    <span class="type-credit">

                                        <i class="fa-solid fa-arrow-down"></i>

                                        Credit

                                    </span>

                                <?php else: ?>

                                    <span class="type-debit">

                                        <i class="fa-solid fa-arrow-up"></i>

                                        Debit

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- AMOUNT -->

                            <td>

                                <span class="<?= $transaction["type"] === "credit"
                                    ? "credit"
                                    : "debit" ?>">

                                    <?= $transaction["type"] === "credit"
                                        ? "+"
                                        : "-" ?>

                                    ₹<?= number_format(
                                        (float)$transaction["amount"],
                                        2
                                    ) ?>

                                </span>

                            </td>


                            <!-- COUNTERPARTY -->

                            <td>

                                <div
                                    class="counterparty"
                                    title="<?= e(
                                        $transaction["counterparty"]
                                        ?? "-"
                                    ) ?>"
                                >

                                    <?= e(
                                        $transaction["counterparty"]
                                        ?? "-"
                                    ) ?>

                                </div>

                            </td>


                            <!-- CATEGORY -->

                            <td>

                                <?= e(
                                    $transaction["category"]
                                    ?? "-"
                                ) ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <span class="badge <?= $statusClass ?>">

                                    <?= e(
                                        ucfirst($status)
                                    ) ?>

                                </span>

                            </td>


                            <!-- REFERENCE -->

                            <td>

                                <span class="reference">

                                    <?= e(
                                        $transaction["reference"]
                                    ) ?>

                                </span>

                            </td>


                            <!-- DATE -->

                            <td>

                                <?= date(
                                    "d M Y, h:i A",
                                    strtotime(
                                        $transaction["created_at"]
                                    )
                                ) ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>
