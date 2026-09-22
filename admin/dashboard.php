<?php
session_start();

require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$userId = (int)$_SESSION["user_id"];

/* Check admin role */
$stmt = $conn->prepare("SELECT id, first_name, last_name, email, role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();

if (!$admin || $admin["role"] !== "admin") {
    http_response_code(403);
    exit("Access denied. Admin privileges required.");
}

$adminName = $admin["first_name"] . " " . $admin["last_name"];

/* Helper */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

/* =========================
   DASHBOARD STATISTICS
   ========================= */

/* Total customers */
$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'customer'
");
$totalCustomers = (int)$result->fetch_assoc()["total"];

/* Total balance */
$result = $conn->query("
    SELECT COALESCE(SUM(balance), 0) AS total
    FROM accounts
    WHERE status = 'Active'
");
$totalBalance = (float)$result->fetch_assoc()["total"];

/* Active cards */
$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM cards
    WHERE status = 'Active'
");
$activeCards = (int)$result->fetch_assoc()["total"];

/* Total transactions */
$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
");
$totalTransactions = (int)$result->fetch_assoc()["total"];

/* Pending transactions */
$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM transactions
    WHERE status = 'pending'
");
$pendingTransactions = (int)$result->fetch_assoc()["total"];

/* Unread notifications */
$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE is_read = 0
");
$unreadNotifications = (int)$result->fetch_assoc()["total"];

/* =========================
   RECENT USERS
   ========================= */

$recentUsers = [];

$result = $conn->query("
    SELECT
        id,
        first_name,
        last_name,
        email,
        account_type,
        status,
        created_at
    FROM users
    WHERE role = 'customer'
    ORDER BY created_at DESC
    LIMIT 6
");

while ($row = $result->fetch_assoc()) {
    $recentUsers[] = $row;
}

/* =========================
   RECENT TRANSACTIONS
   ========================= */

$recentTransactions = [];

$result = $conn->query("
    SELECT
        t.id,
        t.type,
        t.amount,
        t.counterparty,
        t.status,
        t.reference,
        t.created_at,
        CONCAT(u.first_name, ' ', u.last_name) AS user_name
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    ORDER BY t.created_at DESC
    LIMIT 8
");

while ($row = $result->fetch_assoc()) {
    $recentTransactions[] = $row;
}

/* =========================
   RECENT AUDIT LOGS
   ========================= */

$auditLogs = [];

$result = $conn->query("
    SELECT
        a.action,
        a.description,
        a.created_at,
        CONCAT(u.first_name, ' ', u.last_name) AS user_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.created_at DESC
    LIMIT 6
");

while ($row = $result->fetch_assoc()) {
    $auditLogs[] = $row;
}

/* Admin initials */
$initials =
    strtoupper(substr($admin["first_name"], 0, 1)) .
    strtoupper(substr($admin["last_name"], 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>NexaBank Admin Dashboard</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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
    z-index: 10;
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
    font-size: 19px;
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
    transition: 0.2s;
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

/* HEADER */

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 30px;
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
   STAT CARDS
   ========================= */

.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 25px;
}

.stat-card {
    background: #0c192a;
    border: 1px solid #1d2c40;
    border-radius: 15px;
    padding: 20px;
}

.stat-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stat-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: #13253b;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #38bdf8;
}

.stat-card h3 {
    font-size: 24px;
    margin-top: 17px;
}

.stat-card p {
    color: #64748b;
    font-size: 11px;
    margin-top: 5px;
}

/* =========================
   CONTENT GRID
   ========================= */

.content-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.panel {
    background: #0c192a;
    border: 1px solid #1d2c40;
    border-radius: 15px;
    overflow: hidden;
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 20px;
    border-bottom: 1px solid #1d2c40;
}

.panel-header h2 {
    font-size: 14px;
}

.panel-header a {
    font-size: 10px;
    color: #38bdf8;
}

/* TABLE */

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    text-align: left;
    color: #64748b;
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    padding: 13px 16px;
    border-bottom: 1px solid #1d2c40;
}

td {
    padding: 13px 16px;
    font-size: 11px;
    border-bottom: 1px solid #132236;
}

tr:last-child td {
    border-bottom: none;
}

.user-cell {
    display: flex;
    align-items: center;
    gap: 9px;
}

.small-avatar {
    width: 29px;
    height: 29px;
    border-radius: 50%;
    background: #16304d;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 700;
    color: #7dd3fc;
}

.muted {
    color: #64748b;
    font-size: 9px;
    margin-top: 3px;
}

.badge {
    display: inline-block;
    padding: 4px 7px;
    border-radius: 6px;
    font-size: 8px;
    font-weight: 700;
}

.badge-active,
.badge-success {
    background: #0b3b2b;
    color: #4ade80;
}

.badge-pending {
    background: #3d2f0a;
    color: #facc15;
}

.badge-blocked,
.badge-failed {
    background: #421d24;
    color: #fb7185;
}

.credit {
    color: #4ade80;
}

.debit {
    color: #fb7185;
}

/* =========================
   AUDIT
   ========================= */

.audit-list {
    padding: 5px 20px 15px;
}

.audit-item {
    display: flex;
    gap: 12px;
    padding: 14px 0;
    border-bottom: 1px solid #132236;
}

.audit-item:last-child {
    border-bottom: none;
}

.audit-icon {
    width: 31px;
    height: 31px;
    border-radius: 9px;
    background: #13253b;
    color: #38bdf8;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.audit-text strong {
    display: block;
    font-size: 10px;
}

.audit-text p {
    color: #64748b;
    font-size: 9px;
    margin-top: 3px;
    line-height: 1.5;
}

.audit-time {
    color: #475569;
    font-size: 8px;
    margin-top: 4px;
}

/* =========================
   RESPONSIVE
   ========================= */

@media (max-width: 1000px) {

    .sidebar {
        width: 210px;
    }

    .main {
        margin-left: 210px;
    }

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {

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
        padding: 20px;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .topbar {
        gap: 15px;
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

    <a href="dashboard.php" class="nav-item active">
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
     MAIN CONTENT
     ========================= -->

<main class="main">

    <div class="topbar">

        <div class="page-title">

            <h1>Admin Dashboard</h1>

            <p>
                NexaBank system overview and administration
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
                <?= e($initials) ?>
            </div>

        </div>

    </div>


    <!-- =========================
         STATISTICS
         ========================= -->

    <section class="stats">

        <div class="stat-card">

            <div class="stat-top">

                <span>
                    <i class="fa-solid fa-users"></i>
                </span>

                <div class="stat-icon">
                    <i class="fa-solid fa-users"></i>
                </div>

            </div>

            <h3>
                <?= number_format($totalCustomers) ?>
            </h3>

            <p>
                Total Customers
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span>
                    <i class="fa-solid fa-indian-rupee-sign"></i>
                </span>

                <div class="stat-icon">
                    <i class="fa-solid fa-wallet"></i>
                </div>

            </div>

            <h3>
                ₹<?= number_format($totalBalance, 2) ?>
            </h3>

            <p>
                Total Active Balance
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span></span>

                <div class="stat-icon">
                    <i class="fa-solid fa-credit-card"></i>
                </div>

            </div>

            <h3>
                <?= number_format($activeCards) ?>
            </h3>

            <p>
                Active Cards
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span></span>

                <div class="stat-icon">
                    <i class="fa-solid fa-arrow-right-arrow-left"></i>
                </div>

            </div>

            <h3>
                <?= number_format($totalTransactions) ?>
            </h3>

            <p>
                Total Transactions
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span></span>

                <div class="stat-icon">
                    <i class="fa-solid fa-clock"></i>
                </div>

            </div>

            <h3>
                <?= number_format($pendingTransactions) ?>
            </h3>

            <p>
                Pending Transactions
            </p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span></span>

                <div class="stat-icon">
                    <i class="fa-solid fa-bell"></i>
                </div>

            </div>

            <h3>
                <?= number_format($unreadNotifications) ?>
            </h3>

            <p>
                Unread Notifications
            </p>

        </div>

    </section>


    <!-- =========================
         RECENT USERS
         ========================= -->

    <section class="content-grid">

        <div class="panel">

            <div class="panel-header">

                <h2>
                    Recent Customers
                </h2>

                <a href="users.php">
                    View All
                    <i class="fa-solid fa-arrow-right"></i>
                </a>

            </div>

            <div class="table-wrapper">

                <table>

                    <thead>

                    <tr>
                        <th>Customer</th>
                        <th>Account Type</th>
                        <th>Status</th>
                        <th>Joined</th>
                    </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($recentUsers)): ?>

                        <tr>
                            <td colspan="4">
                                No customers found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($recentUsers as $user): ?>

                            <?php
                            $userInitials =
                                strtoupper(substr($user["first_name"], 0, 1)) .
                                strtoupper(substr($user["last_name"], 0, 1));
                            ?>

                            <tr>

                                <td>

                                    <div class="user-cell">

                                        <div class="small-avatar">
                                            <?= e($userInitials) ?>
                                        </div>

                                        <div>

                                            <strong>
                                                <?= e($user["first_name"] . " " . $user["last_name"]) ?>
                                            </strong>

                                            <div class="muted">
                                                <?= e($user["email"]) ?>
                                            </div>

                                        </div>

                                    </div>

                                </td>

                                <td>
                                    <?= e($user["account_type"]) ?>
                                </td>

                                <td>

                                    <?php
                                    $statusClass =
                                        strtolower($user["status"]) === "active"
                                        ? "badge-active"
                                        : "badge-blocked";
                                    ?>

                                    <span class="badge <?= $statusClass ?>">
                                        <?= e($user["status"]) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= date("d M Y", strtotime($user["created_at"])) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- =========================
             AUDIT LOG
             ========================= -->

        <div class="panel">

            <div class="panel-header">

                <h2>
                    Recent Activity
                </h2>

                <a href="audit_logs.php">
                    View All
                </a>

            </div>

            <div class="audit-list">

                <?php if (empty($auditLogs)): ?>

                    <div class="audit-item">

                        <div class="audit-icon">
                            <i class="fa-solid fa-info"></i>
                        </div>

                        <div class="audit-text">

                            <strong>
                                No activity yet
                            </strong>

                            <p>
                                System audit activity will appear here.
                            </p>

                        </div>

                    </div>

                <?php else: ?>

                    <?php foreach ($auditLogs as $log): ?>

                        <div class="audit-item">

                            <div class="audit-icon">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>

                            <div class="audit-text">

                                <strong>
                                    <?= e($log["action"]) ?>
                                </strong>

                                <p>
                                    <?= e($log["description"] ?? "") ?>
                                </p>

                                <div class="audit-time">

                                    <?= e($log["user_name"] ?? "System") ?>

                                    •

                                    <?= date("d M Y, h:i A", strtotime($log["created_at"])) ?>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>

    </section>


    <!-- =========================
         RECENT TRANSACTIONS
         ========================= -->

    <section class="panel">

        <div class="panel-header">

            <h2>
                Recent Transactions
            </h2>

            <a href="transactions.php">
                View All
                <i class="fa-solid fa-arrow-right"></i>
            </a>

        </div>

        <div class="table-wrapper">

            <table>

                <thead>

                <tr>
                    <th>User</th>
                    <th>Type</th>
                    <th>Counterparty</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Reference</th>
                    <th>Date</th>
                </tr>

                </thead>

                <tbody>

                <?php if (empty($recentTransactions)): ?>

                    <tr>

                        <td colspan="7">
                            No transactions found.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($recentTransactions as $transaction): ?>

                        <tr>

                            <td>
                                <?= e($transaction["user_name"] ?? "Unknown") ?>
                            </td>

                            <td>

                                <?php if ($transaction["type"] === "credit"): ?>

                                    <span class="credit">
                                        <i class="fa-solid fa-arrow-down"></i>
                                        Credit
                                    </span>

                                <?php else: ?>

                                    <span class="debit">
                                        <i class="fa-solid fa-arrow-up"></i>
                                        Debit
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= e($transaction["counterparty"] ?? "-") ?>
                            </td>

                            <td>

                                <strong class="<?= $transaction["type"] === "credit" ? "credit" : "debit" ?>">

                                    <?= $transaction["type"] === "credit" ? "+" : "-" ?>

                                    ₹<?= number_format((float)$transaction["amount"], 2) ?>

                                </strong>

                            </td>

                            <td>

                                <?php
                                $status = strtolower($transaction["status"]);

                                if ($status === "success") {
                                    $statusClass = "badge-success";
                                } elseif ($status === "pending") {
                                    $statusClass = "badge-pending";
                                } else {
                                    $statusClass = "badge-failed";
                                }
                                ?>

                                <span class="badge <?= $statusClass ?>">
                                    <?= e(ucfirst($status)) ?>
                                </span>

                            </td>

                            <td>
                                <?= e($transaction["reference"]) ?>
                            </td>

                            <td>
                                <?= date("d M Y, h:i A", strtotime($transaction["created_at"])) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

