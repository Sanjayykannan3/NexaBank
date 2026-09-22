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

$stmt = $conn->prepare("
    SELECT first_name, last_name, email, role
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $currentUserId);
$stmt->execute();

$currentUser = $stmt->get_result()->fetch_assoc();

if (!$currentUser || $currentUser["role"] !== "admin") {
    http_response_code(403);
    exit("Access denied.");
}

$adminName = trim(
    $currentUser["first_name"] . " " . $currentUser["last_name"]
);

$adminInitials = strtoupper(
    substr($currentUser["first_name"], 0, 1) .
    substr($currentUser["last_name"], 0, 1)
);


/* =========================================================
   FILTERS
========================================================= */

$search = trim($_GET["search"] ?? "");
$actionFilter = trim($_GET["action"] ?? "");


/* =========================================================
   STATISTICS
========================================================= */

$totalLogs = 0;
$todayLogs = 0;
$adminActions = 0;
$securityActions = 0;

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM audit_logs
");

if ($result) {
    $row = $result->fetch_assoc();
    $totalLogs = (int) $row["total"];
}


$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE DATE(created_at) = CURDATE()
");

if ($result) {
    $row = $result->fetch_assoc();
    $todayLogs = (int) $row["total"];
}


$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE user_id IS NOT NULL
");

if ($result) {
    $row = $result->fetch_assoc();
    $adminActions = (int) $row["total"];
}


$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM audit_logs
    WHERE
        LOWER(action) LIKE '%block%'
        OR LOWER(action) LIKE '%delete%'
        OR LOWER(action) LIKE '%security%'
        OR LOWER(action) LIKE '%password%'
        OR LOWER(action) LIKE '%card%'
");

if ($result) {
    $row = $result->fetch_assoc();
    $securityActions = (int) $row["total"];
}


/* =========================================================
   AUDIT LOG QUERY
========================================================= */

$sql = "
    SELECT
        a.id,
        a.user_id,
        a.action,
        a.description,
        a.ip_address,
        a.user_agent,
        a.created_at,
        u.first_name,
        u.last_name,
        u.email
    FROM audit_logs a
    LEFT JOIN users u
        ON u.id = a.user_id
    WHERE 1 = 1
";

$params = [];
$types = "";


/* SEARCH */

if ($search !== "") {

    $sql .= "
        AND (
            a.action LIKE ?
            OR a.description LIKE ?
            OR a.ip_address LIKE ?
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


/* ACTION FILTER */

if ($actionFilter !== "") {

    $sql .= "
        AND a.action = ?
    ";

    $params[] = $actionFilter;
    $types .= "s";
}


$sql .= "
    ORDER BY a.created_at DESC
";


$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$logs = $stmt->get_result();


/* =========================================================
   GET ACTION TYPES
========================================================= */

$actionResult = $conn->query("
    SELECT DISTINCT action
    FROM audit_logs
    WHERE action IS NOT NULL
      AND action <> ''
    ORDER BY action ASC
");

$actionTypes = [];

if ($actionResult) {

    while ($row = $actionResult->fetch_assoc()) {
        $actionTypes[] = $row["action"];
    }
}


/* =========================================================
   HELPERS
========================================================= */

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        "UTF-8"
    );
}


function actionIcon(string $action): string
{
    $action = strtolower($action);

    if (strpos($action, "login") !== false) {
        return "fa-right-to-bracket";
    }

    if (strpos($action, "logout") !== false) {
        return "fa-right-from-bracket";
    }

    if (
        strpos($action, "block") !== false ||
        strpos($action, "security") !== false
    ) {
        return "fa-shield-halved";
    }

    if (strpos($action, "card") !== false) {
        return "fa-credit-card";
    }

    if (strpos($action, "notification") !== false) {
        return "fa-bell";
    }

    if (strpos($action, "transaction") !== false) {
        return "fa-money-bill-transfer";
    }

    if (strpos($action, "account") !== false) {
        return "fa-building-columns";
    }

    if (strpos($action, "password") !== false) {
        return "fa-key";
    }

    if (
        strpos($action, "delete") !== false ||
        strpos($action, "remove") !== false
    ) {
        return "fa-trash";
    }

    return "fa-gear";
}


function actionClass(string $action): string
{
    $action = strtolower($action);

    if (
        strpos($action, "block") !== false ||
        strpos($action, "delete") !== false ||
        strpos($action, "security") !== false
    ) {
        return "danger";
    }

    if (
        strpos($action, "transaction") !== false ||
        strpos($action, "account") !== false
    ) {
        return "transaction";
    }

    if (
        strpos($action, "notification") !== false ||
        strpos($action, "card") !== false
    ) {
        return "system";
    }

    if (
        strpos($action, "login") !== false ||
        strpos($action, "logout") !== false
    ) {
        return "login";
    }

    return "general";
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

<title>NexaBank Admin - Audit Logs</title>

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

    background:
        linear-gradient(
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
    background: rgba(37,99,235,.18);
    color: #60a5fa;
}

.nav-link.logout {
    color: #f87171;
}

.nav-link.logout:hover {
    background: rgba(239,68,68,.10);
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

    background:
        linear-gradient(
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
   STATISTICS
========================================================= */

.stats-grid {
    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

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

    background: rgba(37,99,235,.12);

    color: #60a5fa;

    font-size: 12px;
}

.stat-number {
    margin-top: 14px;

    font-size: 22px;

    font-weight: 800;
}


/* =========================================================
   FILTERS
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
        1fr 220px auto auto;

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
   TABLE
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

    min-width: 1150px;
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
   ACTION BADGE
========================================================= */

.action-badge {
    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 6px 9px;

    border-radius: 7px;

    font-size: 9px;

    font-weight: 800;

    white-space: nowrap;
}

.action-badge.danger {
    color: #f87171;

    background: rgba(239,68,68,.10);
}

.action-badge.transaction {
    color: #4ade80;

    background: rgba(34,197,94,.10);
}

.action-badge.system {
    color: #c084fc;

    background: rgba(168,85,247,.10);
}

.action-badge.login {
    color: #60a5fa;

    background: rgba(59,130,246,.10);
}

.action-badge.general {
    color: #94a3b8;

    background: rgba(148,163,184,.10);
}


/* =========================================================
   USER
========================================================= */

.user-cell {
    display: flex;

    align-items: center;

    gap: 9px;
}

.user-avatar {
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

.user-name {
    font-weight: 700;
}

.user-email {
    color: #64748b;

    font-size: 9px;

    margin-top: 2px;
}


/* =========================================================
   DESCRIPTION
========================================================= */

.description {
    color: #cbd5e1;

    max-width: 360px;

    line-height: 1.5;
}

.description-sub {
    color: #64748b;

    font-size: 9px;

    margin-top: 4px;
}


/* =========================================================
   IP
========================================================= */

.ip {
    color: #94a3b8;

    font-family: Consolas, monospace;

    font-size: 10px;
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
   EMPTY
========================================================= */

.empty {
    text-align: center;

    padding: 65px 20px;

    color: #64748b;
}

.empty i {
    font-size: 32px;

    margin-bottom: 13px;
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
            repeat(2, minmax(0, 1fr));
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


    <a
        href="dashboard.php"
        class="nav-link"
    >
        <i class="fa-solid fa-gauge-high"></i>
        <span>Dashboard</span>
    </a>


    <a
        href="users.php"
        class="nav-link"
    >
        <i class="fa-solid fa-users"></i>
        <span>Customers</span>
    </a>


    <a
        href="accounts.php"
        class="nav-link"
    >
        <i class="fa-solid fa-building-columns"></i>
        <span>Accounts</span>
    </a>


    <a
        href="transactions.php"
        class="nav-link"
    >
        <i class="fa-solid fa-money-bill-transfer"></i>
        <span>Transactions</span>
    </a>


    <a
        href="cards.php"
        class="nav-link"
    >
        <i class="fa-solid fa-credit-card"></i>
        <span>Cards</span>
    </a>


    <a
        href="notifications.php"
        class="nav-link"
    >
        <i class="fa-solid fa-bell"></i>
        <span>Notifications</span>
    </a>


    <a
        href="audit_logs.php"
        class="nav-link active"
    >
        <i class="fa-solid fa-clock-rotate-left"></i>
        <span>Audit Logs</span>
    </a>


    <div class="menu-title">
        Customer Portal
    </div>


    <a
        href="../dashboard.php"
        class="nav-link"
    >
        <i class="fa-solid fa-arrow-up-right-from-square"></i>
        <span>Open Customer Portal</span>
    </a>


    <a
        href="logout.php"
        class="nav-link logout"
    >
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
                Audit Logs
            </h1>

            <p>
                Monitor administrative and system activity
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


        <!-- =================================================
             STATISTICS
        ================================================== -->

        <div class="stats-grid">


            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Total Logs
                    </div>

                    <div class="stat-icon">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>

                </div>

                <div class="stat-number">
                    <?= number_format($totalLogs) ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Today
                    </div>

                    <div class="stat-icon">
                        <i class="fa-solid fa-calendar-day"></i>
                    </div>

                </div>

                <div class="stat-number">
                    <?= number_format($todayLogs) ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        User Actions
                    </div>

                    <div class="stat-icon">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>

                </div>

                <div class="stat-number">
                    <?= number_format($adminActions) ?>
                </div>

            </div>


            <div class="stat-card">

                <div class="stat-top">

                    <div class="stat-label">
                        Security Actions
                    </div>

                    <div class="stat-icon">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>

                </div>

                <div class="stat-number">
                    <?= number_format($securityActions) ?>
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
                    placeholder="Search action, description, user or IP..."
                    value="<?= e($search) ?>"
                >


                <select
                    name="action"
                    class="select"
                >

                    <option value="">
                        All Actions
                    </option>

                    <?php foreach ($actionTypes as $action): ?>

                        <option
                            value="<?= e($action) ?>"
                            <?= $actionFilter === $action ? "selected" : "" ?>
                        >

                            <?= e(
                                ucwords(
                                    str_replace(
                                        "_",
                                        " ",
                                        $action
                                    )
                                )
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>


                <button
                    type="submit"
                    class="btn btn-primary"
                >

                    <i class="fa-solid fa-filter"></i>

                    Filter

                </button>


                <a
                    href="audit_logs.php"
                    class="btn btn-secondary"
                >

                    <i class="fa-solid fa-rotate-left"></i>

                    Reset

                </a>


            </form>

        </div>



        <!-- =================================================
             AUDIT TABLE
        ================================================== -->

        <div class="table-card">


            <div class="table-header">

                <div>

                    <h2>
                        System Activity
                    </h2>

                    <span>
                        <?= number_format($logs->num_rows) ?>
                        log(s) found
                    </span>

                </div>

            </div>



            <div class="table-wrap">


                <?php if ($logs->num_rows > 0): ?>


                    <table>

                        <thead>

                            <tr>

                                <th>
                                    User
                                </th>

                                <th>
                                    Action
                                </th>

                                <th>
                                    Description
                                </th>

                                <th>
                                    IP Address
                                </th>

                                <th>
                                    Browser
                                </th>

                                <th>
                                    Date
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php while ($log = $logs->fetch_assoc()): ?>


                            <?php

                            $firstName =
                                $log["first_name"] ?? "";

                            $lastName =
                                $log["last_name"] ?? "";

                            $userName =
                                trim(
                                    $firstName .
                                    " " .
                                    $lastName
                                );

                            if ($userName === "") {
                                $userName = "System";
                            }

                            $initials =
                                strtoupper(
                                    substr(
                                        $firstName,
                                        0,
                                        1
                                    ) .
                                    substr(
                                        $lastName,
                                        0,
                                        1
                                    )
                                );

                            if ($initials === "") {
                                $initials = "SYS";
                            }

                            $action =
                                $log["action"] ?? "unknown";

                            $actionClass =
                                actionClass($action);

                            $actionIcon =
                                actionIcon($action);

                            $createdTimestamp =
                                strtotime(
                                    $log["created_at"]
                                );

                            $userAgent =
                                $log["user_agent"] ?? "";

                            /* Short browser display */

                            $browserName = "Unknown";

                            if (
                                stripos(
                                    $userAgent,
                                    "Edg"
                                ) !== false
                            ) {

                                $browserName = "Microsoft Edge";

                            } elseif (
                                stripos(
                                    $userAgent,
                                    "Chrome"
                                ) !== false
                            ) {

                                $browserName = "Google Chrome";

                            } elseif (
                                stripos(
                                    $userAgent,
                                    "Firefox"
                                ) !== false
                            ) {

                                $browserName = "Mozilla Firefox";

                            } elseif (
                                stripos(
                                    $userAgent,
                                    "Safari"
                                ) !== false
                            ) {

                                $browserName = "Safari";
                            }

                            ?>


                            <tr>


                                <!-- USER -->

                                <td>

                                    <div class="user-cell">

                                        <div class="user-avatar">
                                            <?= e($initials) ?>
                                        </div>

                                        <div>

                                            <div class="user-name">
                                                <?= e($userName) ?>
                                            </div>

                                            <div class="user-email">

                                                <?= e(
                                                    $log["email"] ??
                                                    "System"
                                                ) ?>

                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <!-- ACTION -->

                                <td>

                                    <span
                                        class="
                                            action-badge
                                            <?= e($actionClass) ?>
                                        "
                                    >

                                        <i
                                            class="
                                                fa-solid
                                                <?= e($actionIcon) ?>
                                            "
                                        ></i>

                                        <?= e(
                                            ucwords(
                                                str_replace(
                                                    "_",
                                                    " ",
                                                    $action
                                                )
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <!-- DESCRIPTION -->

                                <td>

                                    <div class="description">

                                        <?= e(
                                            $log["description"] ??
                                            "No description"
                                        ) ?>

                                    </div>

                                    <div class="description-sub">

                                        Log ID:
                                        #<?= (int) $log["id"] ?>

                                    </div>

                                </td>


                                <!-- IP -->

                                <td>

                                    <div class="ip">

                                        <?= e(
                                            $log["ip_address"] ??
                                            "Unknown"
                                        ) ?>

                                    </div>

                                </td>


                                <!-- BROWSER -->

                                <td>

                                    <div class="date">

                                        <?= e(
                                            $browserName
                                        ) ?>

                                    </div>

                                    <div
                                        class="description-sub"
                                        title="<?= e($userAgent) ?>"
                                    >

                                        View details

                                    </div>

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


                            </tr>


                        <?php endwhile; ?>


                        </tbody>

                    </table>


                <?php else: ?>


                    <div class="empty">

                        <i
                            class="fa-solid fa-clock-rotate-left"
                        ></i>

                        <h3>
                            No audit logs found
                        </h3>

                        <p>
                            Activity recorded by the system will
                            appear here.
                        </p>

                    </div>


                <?php endif; ?>


            </div>

        </div>


    </section>

</main>

