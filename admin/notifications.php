<?php
session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/csrf.php";

/* =========================================================
   ADMIN SECURITY
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

$currentUserId = (int) $_SESSION["user_id"];

$stmt = $conn->prepare("SELECT first_name, last_name, email, role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $currentUserId);
$stmt->execute();

$currentUser = $stmt->get_result()->fetch_assoc();

if (!$currentUser || $currentUser["role"] !== "admin") {
    http_response_code(403);
    exit("Access denied.");
}

$adminName = trim($currentUser["first_name"] . " " . $currentUser["last_name"]);
$adminInitials = strtoupper(
    substr($currentUser["first_name"], 0, 1) .
    substr($currentUser["last_name"], 0, 1)
);


/* =========================================================
   HANDLE ACTIONS
========================================================= */

$successMessage = "";
$errorMessage = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verify_csrf_or_fail();

    $action = $_POST["action"] ?? "";
    $notificationId = isset($_POST["notification_id"])
        ? (int) $_POST["notification_id"]
        : 0;

    /* ---------------- MARK AS READ ---------------- */

    if ($action === "mark_read" && $notificationId > 0) {

        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read = TRUE
            WHERE id = ?
        ");

        $stmt->bind_param("i", $notificationId);

        if ($stmt->execute()) {

            $successMessage = "Notification marked as read.";

            $auditDescription =
                "Admin marked notification #" .
                $notificationId .
                " as read.";

            $audit = $conn->prepare("
                INSERT INTO audit_logs
                (user_id, action, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");

            $auditAction = "notification_read";
            $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "";
            $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? "";

            $audit->bind_param(
                "issss",
                $currentUserId,
                $auditAction,
                $auditDescription,
                $ipAddress,
                $userAgent
            );

            $audit->execute();
        } else {
            $errorMessage = "Unable to update notification.";
        }
    }


    /* ---------------- MARK ALL AS READ ---------------- */

    elseif ($action === "mark_all_read") {

        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read = TRUE
            WHERE is_read = FALSE
        ");

        if ($stmt->execute()) {

            $successMessage = "All unread notifications marked as read.";

            $auditDescription =
                "Admin marked all unread notifications as read.";

            $audit = $conn->prepare("
                INSERT INTO audit_logs
                (user_id, action, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");

            $auditAction = "notifications_mark_all_read";
            $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "";
            $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? "";

            $audit->bind_param(
                "issss",
                $currentUserId,
                $auditAction,
                $auditDescription,
                $ipAddress,
                $userAgent
            );

            $audit->execute();
        } else {
            $errorMessage = "Unable to update notifications.";
        }
    }


    /* ---------------- DELETE ---------------- */

    elseif ($action === "delete" && $notificationId > 0) {

        $stmt = $conn->prepare("
            DELETE FROM notifications
            WHERE id = ?
        ");

        $stmt->bind_param("i", $notificationId);

        if ($stmt->execute()) {

            $successMessage = "Notification deleted.";

            $auditDescription =
                "Admin deleted notification #" .
                $notificationId .
                ".";

            $audit = $conn->prepare("
                INSERT INTO audit_logs
                (user_id, action, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");

            $auditAction = "notification_deleted";
            $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "";
            $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? "";

            $audit->bind_param(
                "issss",
                $currentUserId,
                $auditAction,
                $auditDescription,
                $ipAddress,
                $userAgent
            );

            $audit->execute();
        } else {
            $errorMessage = "Unable to delete notification.";
        }
    }
}


/* =========================================================
   FILTERS
========================================================= */

$search = trim($_GET["search"] ?? "");
$typeFilter = trim($_GET["type"] ?? "");
$readFilter = trim($_GET["read"] ?? "");


/* =========================================================
   STATISTICS
========================================================= */

$totalNotifications = 0;
$unreadNotifications = 0;
$readNotifications = 0;
$transactionNotifications = 0;
$securityNotifications = 0;

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM notifications
");

if ($result) {
    $row = $result->fetch_assoc();
    $totalNotifications = (int) $row["total"];
}

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE is_read = FALSE
");

if ($result) {
    $row = $result->fetch_assoc();
    $unreadNotifications = (int) $row["total"];
}

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE is_read = TRUE
");

if ($result) {
    $row = $result->fetch_assoc();
    $readNotifications = (int) $row["total"];
}

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE LOWER(type) = 'transaction'
");

if ($result) {
    $row = $result->fetch_assoc();
    $transactionNotifications = (int) $row["total"];
}

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE LOWER(type) = 'security'
");

if ($result) {
    $row = $result->fetch_assoc();
    $securityNotifications = (int) $row["total"];
}


/* =========================================================
   BUILD NOTIFICATION QUERY
========================================================= */

$sql = "
    SELECT
        n.id,
        n.user_id,
        n.type,
        n.title,
        n.message,
        n.is_read,
        n.created_at,
        u.first_name,
        u.last_name,
        u.email
    FROM notifications n
    INNER JOIN users u
        ON u.id = n.user_id
    WHERE 1 = 1
";

$params = [];
$types = "";


/* SEARCH */

if ($search !== "") {

    $sql .= "
        AND (
            n.title LIKE ?
            OR n.message LIKE ?
            OR n.type LIKE ?
            OR u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR u.email LIKE ?
        )
    ";

    $searchValue = "%" . $search . "%";

    for ($i = 0; $i < 6; $i++) {
        $params[] = $searchValue;
        $types .= "s";
    }
}


/* TYPE FILTER */

if ($typeFilter !== "") {

    $sql .= " AND n.type = ? ";

    $params[] = $typeFilter;
    $types .= "s";
}


/* READ FILTER */

if ($readFilter === "read") {

    $sql .= " AND n.is_read = TRUE ";

} elseif ($readFilter === "unread") {

    $sql .= " AND n.is_read = FALSE ";
}


$sql .= " ORDER BY n.created_at DESC ";


/* =========================================================
   EXECUTE QUERY
========================================================= */

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$notifications = $stmt->get_result();


/* =========================================================
   GET NOTIFICATION TYPES
========================================================= */

$typeResult = $conn->query("
    SELECT DISTINCT type
    FROM notifications
    WHERE type IS NOT NULL
      AND type <> ''
    ORDER BY type ASC
");

$notificationTypes = [];

if ($typeResult) {

    while ($row = $typeResult->fetch_assoc()) {
        $notificationTypes[] = $row["type"];
    }
}


/* =========================================================
   HELPERS
========================================================= */

function notificationIcon(string $type): string
{
    $type = strtolower($type);

    if ($type === "transaction") {
        return "fa-money-bill-transfer";
    }

    if ($type === "security") {
        return "fa-shield-halved";
    }

    if ($type === "login") {
        return "fa-right-to-bracket";
    }

    if ($type === "card") {
        return "fa-credit-card";
    }

    if ($type === "account") {
        return "fa-building-columns";
    }

    return "fa-bell";
}


function notificationClass(string $type): string
{
    $type = strtolower($type);

    if ($type === "transaction") {
        return "transaction";
    }

    if ($type === "security") {
        return "security";
    }

    if ($type === "login") {
        return "login";
    }

    if ($type === "card") {
        return "card";
    }

    return "general";
}


function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>NexaBank Admin - Notifications</title>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family:
        Inter,
        Segoe UI,
        Arial,
        sans-serif;

    background: #07111f;
    color: #e5edf7;
    min-height: 100vh;
}

a {
    text-decoration: none;
    color: inherit;
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;

    width: 245px;

    background: #0b1627;

    border-right: 1px solid #1b2a3e;

    padding: 22px 15px;

    z-index: 100;
}

.logo {
    display: flex;
    align-items: center;
    gap: 11px;

    padding: 5px 10px 25px;
}

.logo-icon {
    width: 40px;
    height: 40px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 11px;

    background: linear-gradient(
        135deg,
        #16a6ff,
        #2563eb
    );

    color: white;
    font-size: 18px;
}

.logo-text {
    font-size: 20px;
    font-weight: 800;
    letter-spacing: .2px;
}

.logo-text span {
    color: #38bdf8;
}

.menu-title {
    color: #64748b;
    font-size: 10px;
    font-weight: 700;

    text-transform: uppercase;
    letter-spacing: 1.2px;

    padding: 13px 12px 8px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 12px;

    padding: 11px 12px;

    border-radius: 9px;

    color: #94a3b8;

    font-size: 13px;
    font-weight: 600;

    margin-bottom: 4px;

    transition: .2s;
}

.nav-link i {
    width: 18px;
    text-align: center;
}

.nav-link:hover {
    background: #111f33;
    color: #e2e8f0;
}

.nav-link.active {
    background: rgba(37, 99, 235, .18);
    color: #60a5fa;
}

.nav-link.logout {
    color: #f87171;
}

.nav-link.logout:hover {
    background: rgba(239, 68, 68, .10);
}


/* =========================================================
   MAIN
========================================================= */

.main {
    margin-left: 245px;
    min-height: 100vh;
}


/* =========================================================
   TOPBAR
========================================================= */

.topbar {
    height: 72px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 0 30px;

    background: #091522;

    border-bottom: 1px solid #1b2a3e;
}

.page-title h1 {
    font-size: 21px;
    font-weight: 750;
}

.page-title p {
    color: #64748b;
    font-size: 11px;
    margin-top: 3px;
}

.admin-profile {
    display: flex;
    align-items: center;
    gap: 10px;
}

.admin-info {
    text-align: right;
}

.admin-name {
    font-size: 12px;
    font-weight: 700;
}

.admin-role {
    color: #64748b;
    font-size: 10px;
    margin-top: 2px;
}

.avatar {
    width: 37px;
    height: 37px;

    border-radius: 50%;

    display: flex;
    align-items: center;
    justify-content: center;

    background: linear-gradient(
        135deg,
        #2563eb,
        #06b6d4
    );

    color: white;

    font-size: 11px;
    font-weight: 800;
}


/* =========================================================
   CONTENT
========================================================= */

.content {
    padding: 27px 30px 45px;
}


/* =========================================================
   ALERTS
========================================================= */

.alert {
    padding: 12px 15px;
    border-radius: 9px;

    margin-bottom: 20px;

    font-size: 12px;
    font-weight: 600;
}

.alert-success {
    background: rgba(34, 197, 94, .10);
    border: 1px solid rgba(34, 197, 94, .25);
    color: #4ade80;
}

.alert-error {
    background: rgba(239, 68, 68, .10);
    border: 1px solid rgba(239, 68, 68, .25);
    color: #f87171;
}


/* =========================================================
   STAT CARDS
========================================================= */

.stats-grid {
    display: grid;

    grid-template-columns:
        repeat(5, minmax(0, 1fr));

    gap: 14px;

    margin-bottom: 22px;
}

.stat-card {
    background: #0c192a;

    border: 1px solid #1b2a3e;

    border-radius: 12px;

    padding: 17px;

    min-height: 110px;
}

.stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.stat-label {
    color: #718096;
    font-size: 10px;

    text-transform: uppercase;
    letter-spacing: .7px;

    font-weight: 700;
}

.stat-icon {
    width: 31px;
    height: 31px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 8px;

    background: rgba(37, 99, 235, .12);
    color: #60a5fa;

    font-size: 12px;
}

.stat-number {
    margin-top: 14px;

    font-size: 22px;
    font-weight: 800;
}


/* =========================================================
   FILTER BAR
========================================================= */

.filter-card {
    background: #0c192a;

    border: 1px solid #1b2a3e;

    border-radius: 12px;

    padding: 15px;

    margin-bottom: 18px;
}

.filter-form {
    display: grid;

    grid-template-columns:
        1fr 180px 160px auto auto;

    gap: 9px;
}

.input,
.select {
    width: 100%;

    background: #07111f;

    border: 1px solid #23344a;

    color: #e2e8f0;

    border-radius: 8px;

    padding: 10px 11px;

    font-size: 11px;

    outline: none;
}

.input:focus,
.select:focus {
    border-color: #3b82f6;
}

.btn {
    border: 0;

    border-radius: 8px;

    padding: 10px 14px;

    cursor: pointer;

    font-size: 11px;
    font-weight: 700;

    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;

    transition: .2s;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-secondary {
    background: #17253a;
    color: #cbd5e1;
    border: 1px solid #26384f;
}

.btn-secondary:hover {
    background: #1d2d43;
}


/* =========================================================
   TABLE CARD
========================================================= */

.table-card {
    background: #0c192a;

    border: 1px solid #1b2a3e;

    border-radius: 12px;

    overflow: hidden;
}

.table-header {
    padding: 18px 19px;

    border-bottom: 1px solid #1b2a3e;

    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-header h2 {
    font-size: 14px;
}

.table-header span {
    color: #64748b;
    font-size: 10px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;

    min-width: 1050px;
}

th {
    text-align: left;

    padding: 11px 15px;

    background: #0a1625;

    color: #64748b;

    font-size: 9px;

    text-transform: uppercase;
    letter-spacing: .8px;

    font-weight: 800;
}

td {
    padding: 14px 15px;

    border-top: 1px solid #152438;

    font-size: 11px;

    vertical-align: middle;
}

tr:hover td {
    background: rgba(255,255,255,.012);
}


/* =========================================================
   CUSTOMER
========================================================= */

.customer {
    display: flex;
    align-items: center;
    gap: 9px;
}

.customer-avatar {
    width: 31px;
    height: 31px;

    border-radius: 8px;

    background: #14263c;

    display: flex;
    align-items: center;
    justify-content: center;

    color: #60a5fa;

    font-size: 9px;
    font-weight: 800;
}

.customer-name {
    font-weight: 700;
}

.customer-email {
    color: #64748b;
    font-size: 9px;
    margin-top: 2px;
}


/* =========================================================
   TYPE BADGES
========================================================= */

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    padding: 5px 8px;

    border-radius: 6px;

    font-size: 9px;

    font-weight: 800;

    text-transform: capitalize;
}

.type-badge.transaction {
    color: #4ade80;
    background: rgba(34,197,94,.10);
}

.type-badge.security {
    color: #fbbf24;
    background: rgba(245,158,11,.10);
}

.type-badge.login {
    color: #60a5fa;
    background: rgba(59,130,246,.10);
}

.type-badge.card {
    color: #c084fc;
    background: rgba(168,85,247,.10);
}

.type-badge.general {
    color: #94a3b8;
    background: rgba(148,163,184,.10);
}


/* =========================================================
   MESSAGE
========================================================= */

.notification-title {
    font-weight: 700;
    color: #e2e8f0;

    max-width: 260px;
}

.notification-message {
    color: #64748b;

    font-size: 9px;

    margin-top: 4px;

    max-width: 280px;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}


/* =========================================================
   READ STATUS
========================================================= */

.status {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    padding: 5px 8px;

    border-radius: 6px;

    font-size: 9px;
    font-weight: 800;
}

.status.unread {
    color: #60a5fa;
    background: rgba(59,130,246,.10);
}

.status.read {
    color: #64748b;
    background: rgba(100,116,139,.10);
}


/* =========================================================
   DATE
========================================================= */

.date {
    color: #94a3b8;
    font-size: 10px;
}

.time {
    color: #475569;
    font-size: 9px;
    margin-top: 3px;
}


/* =========================================================
   ACTIONS
========================================================= */

.actions {
    display: flex;
    gap: 6px;
}

.icon-btn {
    width: 30px;
    height: 30px;

    border-radius: 7px;

    border: 1px solid #24364c;

    background: #101f32;

    color: #94a3b8;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    cursor: pointer;

    font-size: 10px;

    transition: .2s;
}

.icon-btn:hover {
    color: white;
    background: #172941;
}

.icon-btn.read-btn:hover {
    color: #4ade80;
    border-color: rgba(34,197,94,.35);
}

.icon-btn.delete-btn:hover {
    color: #f87171;
    border-color: rgba(239,68,68,.35);
}


/* =========================================================
   EMPTY
========================================================= */

.empty {
    text-align: center;

    padding: 60px 20px;

    color: #64748b;
}

.empty i {
    font-size: 30px;
    margin-bottom: 12px;
}

.empty h3 {
    color: #94a3b8;
    font-size: 14px;
    margin-bottom: 5px;
}

.empty p {
    font-size: 10px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .stats-grid {
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
    }

    .filter-form {
        grid-template-columns:
            1fr 1fr;
    }
}

@media (max-width: 800px) {

    .sidebar {
        width: 70px;
        padding: 15px 8px;
    }

    .logo-text,
    .menu-title,
    .nav-link span {
        display: none;
    }

    .logo {
        justify-content: center;
        padding: 5px 0 20px;
    }

    .nav-link {
        justify-content: center;
    }

    .main {
        margin-left: 70px;
    }

    .content {
        padding: 20px 15px;
    }

    .stats-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .admin-info {
        display: none;
    }
}

@media (max-width: 500px) {

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .filter-form {
        grid-template-columns: 1fr;
    }

    .topbar {
        padding: 0 15px;
    }
}

</style>

</head>

<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar">

    <div class="logo">

        <div class="logo-icon">
            <i class="fa-solid fa-building-columns"></i>
        </div>

        <div class="logo-text">
            Nexa<span>Bank</span>
        </div>

    </div>


    <div class="menu-title">
        Administration
    </div>


    <a href="dashboard.php" class="nav-link">
        <i class="fa-solid fa-gauge-high"></i>
        <span>Dashboard</span>
    </a>


    <a href="users.php" class="nav-link">
        <i class="fa-solid fa-users"></i>
        <span>Customers</span>
    </a>


    <a href="accounts.php" class="nav-link">
        <i class="fa-solid fa-building-columns"></i>
        <span>Accounts</span>
    </a>


    <a href="transactions.php" class="nav-link">
        <i class="fa-solid fa-money-bill-transfer"></i>
        <span>Transactions</span>
    </a>


    <a href="cards.php" class="nav-link">
        <i class="fa-solid fa-credit-card"></i>
        <span>Cards</span>
    </a>


    <a href="notifications.php" class="nav-link active">
        <i class="fa-solid fa-bell"></i>
        <span>Notifications</span>
    </a>


    <a href="audit_logs.php" class="nav-link">
        <i class="fa-solid fa-clock-rotate-left"></i>
        <span>Audit Logs</span>
    </a>


    <div class="menu-title">
        Customer Portal
    </div>


    <a href="../dashboard.php" class="nav-link">
        <i class="fa-solid fa-arrow-up-right-from-square"></i>
        <span>Open Customer Portal</span>
    </a>


    <a href="logout.php" class="nav-link logout">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>Logout</span>
    </a>

</aside>



<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">


    <!-- TOPBAR -->

    <header class="topbar">

        <div class="page-title">

            <h1>
                Notifications
            </h1>

            <p>
                Manage customer notifications
            </p>

        </div>


        <div class="admin-profile">

            <div class="admin-info">

                <div class="admin-name">
                    <?= e($adminName) ?>
                </div>

                <div class="admin-role">
                    System Administrator
                </div>

            </div>

            <div class="avatar">
                <?= e($adminInitials) ?>
            </div>

        </div>

    </header>



    <!-- CONTENT -->

    <section class="content">


        <?php if ($successMessage): ?>

            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                &nbsp;
                <?= e($successMessage) ?>
            </div>

        <?php endif; ?>


        <?php if ($errorMessage): ?>

            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                &nbsp;
                <?= e($errorMessage) ?>
            </div>

        <?php endif; ?>



        <!-- =================================================
             STATISTICS
        ================================================== -->

        <div class="stats-grid">


            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Total
                    </div>

                    <div class="stat-icon">
                        <i class="fa-solid fa-bell"></i>
                    </div>

                </div>

                <div class="stat-number">
                    <?= number_format($totalNotifications) ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Unread
                    </div>

                    <div class="stat-icon">
                        <i class="fa-solid fa-envelope"></i>
                    </div>

                </div>

                <div class="stat-number">
                    <?= number_format($unreadNotifications) ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Read
                    </div>

                    <div class="stat-icon">
                        <i class="fa-solid fa-envelope-open"></i>
                    </div>

                </div>

                <div class="stat-number">
                    <?= number_format($readNotifications) ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Transactions
                    </div>

                    <div class="stat-icon">
                        <i class="fa-solid fa-money-bill-transfer"></i>
                    </div>

                </div>

                <div class="stat-number">
                    <?= number_format($transactionNotifications) ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Security
                    </div>

                    <div class="stat-icon">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>

                </div>

                <div class="stat-number">
                    <?= number_format($securityNotifications) ?>
                </div>

            </div>


        </div>



        <!-- =================================================
             FILTERS
        ================================================== -->

        <div class="filter-card">

            <form
                method="GET"
                class="filter-form"
            >

                <input
                    type="text"
                    name="search"
                    class="input"
                    placeholder="Search title, message, customer or email..."
                    value="<?= e($search) ?>"
                >


                <select
                    name="type"
                    class="select"
                >

                    <option value="">
                        All Types
                    </option>

                    <?php foreach ($notificationTypes as $type): ?>

                        <option
                            value="<?= e($type) ?>"
                            <?= $typeFilter === $type ? "selected" : "" ?>
                        >
                            <?= e(ucwords(str_replace("_", " ", $type))) ?>
                        </option>

                    <?php endforeach; ?>

                </select>


                <select
                    name="read"
                    class="select"
                >

                    <option value="">
                        All Status
                    </option>

                    <option
                        value="unread"
                        <?= $readFilter === "unread" ? "selected" : "" ?>
                    >
                        Unread
                    </option>

                    <option
                        value="read"
                        <?= $readFilter === "read" ? "selected" : "" ?>
                    >
                        Read
                    </option>

                </select>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fa-solid fa-filter"></i>
                    Filter
                </button>


                <a
                    href="notifications.php"
                    class="btn btn-secondary"
                >
                    <i class="fa-solid fa-rotate-left"></i>
                    Reset
                </a>

            </form>

        </div>



        <!-- =================================================
             TABLE
        ================================================== -->

        <div class="table-card">


            <div class="table-header">

                <div>

                    <h2>
                        Customer Notifications
                    </h2>

                    <span>
                        <?= number_format($notifications->num_rows) ?>
                        notification(s) found
                    </span>

                </div>


                <?php if ($unreadNotifications > 0): ?>

                    <form
                        method="POST"
                        style="margin:0;"
                    >

                        <?= csrf_field() ?>

                        <input
                            type="hidden"
                            name="action"
                            value="mark_all_read"
                        >

                        <button
                            type="submit"
                            class="btn btn-secondary"
                        >
                            <i class="fa-solid fa-check-double"></i>
                            Mark All Read
                        </button>

                    </form>

                <?php endif; ?>

            </div>



            <div class="table-wrap">

                <?php if ($notifications->num_rows > 0): ?>

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    Customer
                                </th>

                                <th>
                                    Type
                                </th>

                                <th>
                                    Notification
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Date
                                </th>

                                <th>
                                    Actions
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php while ($notification = $notifications->fetch_assoc()): ?>

                            <?php

                            $firstName =
                                $notification["first_name"] ?? "";

                            $lastName =
                                $notification["last_name"] ?? "";

                            $customerName =
                                trim($firstName . " " . $lastName);

                            $initials = strtoupper(
                                substr($firstName, 0, 1) .
                                substr($lastName, 0, 1)
                            );

                            if ($initials === "") {
                                $initials = "CU";
                            }

                            $type =
                                $notification["type"] ?? "general";

                            $typeClass =
                                notificationClass($type);

                            $icon =
                                notificationIcon($type);

                            $createdTimestamp =
                                strtotime($notification["created_at"]);

                            ?>

                            <tr>


                                <!-- CUSTOMER -->

                                <td>

                                    <div class="customer">

                                        <div class="customer-avatar">
                                            <?= e($initials) ?>
                                        </div>

                                        <div>

                                            <div class="customer-name">
                                                <?= e($customerName) ?>
                                            </div>

                                            <div class="customer-email">
                                                <?= e($notification["email"]) ?>
                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <!-- TYPE -->

                                <td>

                                    <span
                                        class="type-badge <?= e($typeClass) ?>"
                                    >

                                        <i
                                            class="fa-solid <?= e($icon) ?>"
                                        ></i>

                                        <?= e(
                                            ucwords(
                                                str_replace(
                                                    "_",
                                                    " ",
                                                    $type
                                                )
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <!-- NOTIFICATION -->

                                <td>

                                    <div class="notification-title">

                                        <?= e(
                                            $notification["title"]
                                        ) ?>

                                    </div>

                                    <div class="notification-message">

                                        <?= e(
                                            $notification["message"]
                                        ) ?>

                                    </div>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <?php if ($notification["is_read"]): ?>

                                        <span class="status read">

                                            <i class="fa-solid fa-check"></i>

                                            Read

                                        </span>

                                    <?php else: ?>

                                        <span class="status unread">

                                            <i class="fa-solid fa-circle"></i>

                                            Unread

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- DATE -->

                                <td>

                                    <div class="date">

                                        <?= e(
                                            date(
                                                "d M Y",
                                                $createdTimestamp
                                            )
                                        ) ?>

                                    </div>

                                    <div class="time">

                                        <?= e(
                                            date(
                                                "h:i A",
                                                $createdTimestamp
                                            )
                                        ) ?>

                                    </div>

                                </td>


                                <!-- ACTIONS -->

                                <td>

                                    <div class="actions">


                                        <?php if (!$notification["is_read"]): ?>

                                            <form
                                                method="POST"
                                                style="display:inline;"
                                            >

                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="mark_read"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="notification_id"
                                                    value="<?= (int) $notification["id"] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="icon-btn read-btn"
                                                    title="Mark as read"
                                                >

                                                    <i class="fa-solid fa-check"></i>

                                                </button>

                                            </form>

                                        <?php endif; ?>


                                        <form
                                            method="POST"
                                            style="display:inline;"
                                            onsubmit="return confirm('Delete this notification?');"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="notification_id"
                                                value="<?= (int) $notification["id"] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="icon-btn delete-btn"
                                                title="Delete notification"
                                            >

                                                <i class="fa-solid fa-trash"></i>

                                            </button>

                                        </form>


                                    </div>

                                </td>


                            </tr>

                        <?php endwhile; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="empty">

                        <i class="fa-regular fa-bell-slash"></i>

                        <h3>
                            No notifications found
                        </h3>

                        <p>
                            Try changing your search or filter.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </section>

</main>
