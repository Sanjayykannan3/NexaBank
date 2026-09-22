<?php
session_start();
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/config/csrf.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION["user_id"];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $out = "";
    foreach ($parts as $part) {
        if ($part !== "") $out .= strtoupper(substr($part, 0, 1));
        if (strlen($out) >= 2) break;
    }
    return $out ?: "U";
}

$stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

$fullName = trim($user["first_name"] . " " . $user["last_name"]);
$avatar = initials($fullName);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_or_fail();
    $action = $_POST["action"] ?? "";

    if ($action === "read") {
        $id = (int)($_POST["id"] ?? 0);
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id, $userId);
        $stmt->execute();
    } elseif ($action === "all") {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
    } elseif ($action === "delete") {
        $id = (int)($_POST["id"] ?? 0);
        $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id, $userId);
        $stmt->execute();
    }

    header("Location: notifications.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT id, type, title, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalNotifications = count($notifications);
$unreadNotifications = 0;
$securityAlerts = 0;

foreach ($notifications as $n) {
    if (!(bool)$n["is_read"]) $unreadNotifications++;
    if (strtolower($n["type"]) === "security") $securityAlerts++;
}

function notificationStyle($type) {
    $type = strtolower((string)$type);
    if ($type === "security") return ["security", "fa-shield-halved"];
    if ($type === "warning") return ["warning", "fa-triangle-exclamation"];
    if ($type === "transaction" || $type === "success") return ["success", "fa-arrow-right-arrow-left"];
    return ["info", "fa-circle-info"];
}

function notificationTime($date) {
    $timestamp = strtotime($date);
    if (!$timestamp) return "";
    $seconds = time() - $timestamp;
    if ($seconds < 60) return "Just now";
    if ($seconds < 3600) return floor($seconds / 60) . " min ago";
    if ($seconds < 86400) return floor($seconds / 3600) . " hour" . (floor($seconds / 3600) == 1 ? "" : "s") . " ago";
    if ($seconds < 172800) return "Yesterday";
    if ($seconds < 604800) return floor($seconds / 86400) . " days ago";
    return date("d M Y", $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — NexaBank</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>


        body {
            background: #f4f7fb;
            color: #172033;
        }

        .bank-layout {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */

        .sidebar {
            width: 245px;
            background: #07111f;
            color: white;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            padding: 25px 15px;
            z-index: 100;
        }

        .sidebar .logo {
            padding: 0 12px;
            margin-bottom: 45px;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .menu-title {
            color: #64748b;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            margin: 20px 12px 8px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 12px;
            border-radius: 9px;
            color: #8fa1b7;
            font-size: 12px;
            transition: 0.25s;
        }

        .sidebar-link:hover,
        .sidebar-link.active {
            background: rgba(79,140,255,0.15);
            color: white;
        }

        .sidebar-link.active {
            border-left: 3px solid #4f8cff;
        }

        .sidebar-link i {
            width: 18px;
            text-align: center;
        }

        .sidebar-bottom {
            position: absolute;
            bottom: 25px;
            left: 15px;
            right: 15px;
        }

        /* MAIN */

        .main {
            margin-left: 245px;
            width: calc(100% - 245px);
        }

        .topbar {
            height: 75px;
            background: white;
            border-bottom: 1px solid #e7ebf1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
        }

        .topbar h2 {
            font-size: 16px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .notification-top {
            width: 35px;
            height: 35px;
            border: 1px solid #e7ebf1;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4f8cff;
            position: relative;
        }

        .notification-dot {
            position: absolute;
            width: 7px;
            height: 7px;
            background: #ef4444;
            border-radius: 50%;
            top: 7px;
            right: 7px;
            border: 1px solid white;
        }

        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #dbe8ff;
            color: #2864d7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
        }

        /* CONTENT */

        .content {
            padding: 32px 35px 50px;
            max-width: 1050px;
        }

        .heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 25px;
        }

        .heading h1 {
            font-size: 25px;
            margin-bottom: 5px;
        }

        .heading p {
            color: #7b8798;
            font-size: 12px;
        }

        .mark-all {
            border: 1px solid #dfe5ec;
            background: white;
            color: #4f8cff;
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 9px;
            font-weight: 600;
        }

        .mark-all:hover {
            background: #edf4ff;
        }

        /* SUMMARY */

        .summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 14px;
            padding: 18px;
        }

        .summary-icon {
            width: 35px;
            height: 35px;
            border-radius: 9px;
            background: #edf4ff;
            color: #4f8cff;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .summary-card span {
            color: #94a3b8;
            font-size: 9px;
        }

        .summary-card strong {
            display: block;
            font-size: 20px;
            margin-top: 4px;
        }

        /* FILTERS */

        .notification-panel {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 15px;
            overflow: hidden;
        }

        .filters {
            padding: 17px 20px;
            border-bottom: 1px solid #edf0f4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .filter-tabs {
            display: flex;
            gap: 5px;
        }

        .filter-tab {
            border: none;
            background: transparent;
            color: #94a3b8;
            padding: 8px 11px;
            border-radius: 7px;
            cursor: pointer;
            font-family: inherit;
            font-size: 9px;
        }

        .filter-tab:hover,
        .filter-tab.active {
            background: #edf4ff;
            color: #2864d7;
        }

        .search-box {
            position: relative;
            width: 210px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 10px;
        }

        .search-box input {
            width: 100%;
            height: 34px;
            border: 1px solid #e1e6ed;
            border-radius: 8px;
            padding: 0 10px 0 32px;
            outline: none;
            font-family: inherit;
            font-size: 9px;
        }

        .search-box input:focus {
            border-color: #4f8cff;
        }

        /* NOTIFICATION ITEM */

        .notification-list {
            display: flex;
            flex-direction: column;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 19px 22px;
            border-bottom: 1px solid #edf0f4;
            transition: 0.2s;
            cursor: pointer;
            position: relative;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item:hover {
            background: #fafcff;
        }

        .notification-item.unread {
            background: #f8fbff;
        }

        .notification-item.unread::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: #4f8cff;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification-icon.success {
            background: #e9faf4;
            color: #16a67d;
        }

        .notification-icon.security {
            background: #fff3e8;
            color: #f59e0b;
        }

        .notification-icon.info {
            background: #edf4ff;
            color: #4f8cff;
        }

        .notification-icon.warning {
            background: #fff0f0;
            color: #ef4444;
        }

        .notification-body {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 4px;
        }

        .notification-title strong {
            font-size: 10px;
        }

        .unread-badge {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #4f8cff;
        }

        .notification-body p {
            color: #7b8798;
            font-size: 9px;
            line-height: 1.5;
            max-width: 700px;
        }

        .notification-time {
            color: #a1adbc;
            font-size: 8px;
            white-space: nowrap;
            margin-top: 2px;
        }

        .notification-menu {
            border: none;
            background: transparent;
            color: #a1adbc;
            cursor: pointer;
            padding: 5px;
        }

        .empty-state {
            display: none;
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            display: block;
            font-size: 35px;
            margin-bottom: 12px;
        }

        .empty-state strong {
            display: block;
            color: #475569;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .empty-state span {
            font-size: 9px;
        }

        /* RESPONSIVE */

        @media (max-width: 800px) {

            .summary {
                grid-template-columns: 1fr;
            }

            .filters {
                align-items: stretch;
                flex-direction: column;
            }

            .filter-tabs {
                overflow-x: auto;
            }

            .search-box {
                width: 100%;
            }

        }

        @media (max-width: 750px) {

            .sidebar {
                width: 70px;
                padding: 20px 10px;
            }

            .sidebar .logo span {
                display: none;
            }

            .sidebar .logo {
                justify-content: center;
                padding: 0;
            }

            .menu-title,
            .sidebar-link span {
                display: none;
            }

            .sidebar-link {
                justify-content: center;
            }

            .sidebar-bottom {
                left: 10px;
                right: 10px;
            }

            .main {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .content {
                padding: 25px 18px;
            }

            .topbar {
                padding: 0 18px;
            }

        }

        @media (max-width: 500px) {

            .heading {
                align-items: flex-start;
                flex-direction: column;
                gap: 14px;
            }

            .notification-item {
                padding: 16px;
                gap: 10px;
            }

            .notification-time {
                display: none;
            }

            .notification-icon {
                width: 36px;
                height: 36px;
            }

        }

    
        .db-action-form { margin: 0; padding: 0; }
        .notification-menu { border: none; }
    </style>
</head>
<body>


<div class="bank-layout">


    <!-- SIDEBAR -->

    <aside class="sidebar">

        <a href="dashboard.php" class="logo">

            <div class="logo-icon">
                <i class="fa-solid fa-building-columns"></i>
            </div>

            <span>Nexa<span>Bank</span></span>

        </a>


        <div class="sidebar-menu">

            <div class="menu-title">MAIN</div>

            <a href="dashboard.php" class="sidebar-link">
                <i class="fa-solid fa-grid-2"></i>
                <span>Dashboard</span>
            </a>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-wallet"></i>
                <span>My Accounts</span>
            </a>

            <a href="transfer.php" class="sidebar-link">
                <i class="fa-solid fa-arrow-right-arrow-left"></i>
                <span>Transfer</span>
            </a>

            <a href="cards.html" class="sidebar-link">
                <i class="fa-solid fa-credit-card"></i>
                <span>Cards</span>
            </a>


            <div class="menu-title">MANAGEMENT</div>

            <a href="transactions.php" class="sidebar-link">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Transactions</span>
            </a>

            <a href="analytics.html" class="sidebar-link">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Analytics</span>
            </a>

            <a href="beneficiaries.php" class="sidebar-link">
                <i class="fa-solid fa-user-group"></i>
                <span>Beneficiaries</span>
            </a>


            <div class="menu-title">SETTINGS</div>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-user"></i>
                <span>Profile</span>
            </a>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-gear"></i>
                <span>Settings</span>
            </a>

        </div>


        <div class="sidebar-bottom">

            <a href="logout.php" class="sidebar-link">

                <i class="fa-solid fa-right-from-bracket"></i>

                <span>Logout</span>

            </a>

        </div>

    </aside>


    <!-- MAIN -->

    <main class="main">


        <header class="topbar">

            <h2>Notifications</h2>

            <div class="topbar-right">

                <div class="notification-top">

                    <i class="fa-regular fa-bell"></i>

                    <span
                        class="notification-dot"
                        id="topDot"
                    ></span>

                </div>

                <div class="avatar"><?= e($avatar) ?></div>

            </div>

        </header>


        <section class="content">


            <!-- HEADING -->

            <div class="heading">

                <div>

                    <h1>Notifications</h1>

                    <p>
                        Stay updated with your account activity and security alerts.
                    </p>

                </div>


                <form method="post" style="margin:0;">
                <?= csrf_field() ?>
                    <input type="hidden" name="action" value="all">
                    <button type="submit" class="mark-all">
                        <i class="fa-solid fa-check-double"></i>
                        Mark all as read
                    </button>
                </form>

            </div>


            <!-- SUMMARY -->

            <div class="summary">


                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="fa-regular fa-bell"></i>
                    </div>

                    <span>Total Notifications</span>

                    <strong id="totalNotifications">
                        <?= $totalNotifications ?>
                    </strong>

                </div>


                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="fa-solid fa-envelope"></i>
                    </div>

                    <span>Unread</span>

                    <strong id="unreadNotifications">
                        <?= $unreadNotifications ?>
                    </strong>

                </div>


                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>

                    <span>Security Alerts</span>

                    <strong>
                        <?= $securityAlerts ?>
                    </strong>

                </div>

            </div>


            <!-- NOTIFICATIONS -->

            <div class="notification-panel">


                <div class="filters">

                    <div class="filter-tabs">

                        <button
                            class="filter-tab active"
                            data-filter="all"
                            onclick="filterNotifications('all', this)"
                        >
                            All
                        </button>

                        <button
                            class="filter-tab"
                            data-filter="unread"
                            onclick="filterNotifications('unread', this)"
                        >
                            Unread
                        </button>

                        <button
                            class="filter-tab"
                            data-filter="transaction"
                            onclick="filterNotifications('transaction', this)"
                        >
                            Transactions
                        </button>

                        <button
                            class="filter-tab"
                            data-filter="security"
                            onclick="filterNotifications('security', this)"
                        >
                            Security
                        </button>

                    </div>


                    <div class="search-box">

                        <i class="fa-solid fa-magnifying-glass"></i>

                        <input
                            type="text"
                            id="searchInput"
                            placeholder="Search notifications..."
                            oninput="applyFilters()"
                        >

                    </div>

                </div>


                
<div class="notification-list" id="notificationList">
<?php foreach ($notifications as $n): ?>
    <?php [$styleClass, $iconClass] = notificationStyle($n["type"]); ?>
    <div class="notification-item<?= !(bool)$n["is_read"] ? " unread" : "" ?>"
         data-type="<?= e(strtolower($n["type"])) ?>"
         data-search="<?= e(strtolower($n["title"] . " " . $n["message"] . " " . $n["type"])) ?>"
         data-id="<?= (int)$n["id"] ?>">

        <div class="notification-icon <?= e($styleClass) ?>">
            <i class="fa-solid <?= e($iconClass) ?>"></i>
        </div>

        <div class="notification-body">
            <div class="notification-title">
                <strong><?= e($n["title"]) ?></strong>
                <?php if (!(bool)$n["is_read"]): ?>
                    <span class="unread-badge"></span>
                <?php endif; ?>
            </div>
            <p><?= e($n["message"]) ?></p>
        </div>

        <span class="notification-time"><?= e(notificationTime($n["created_at"])) ?></span>

        <form method="post" class="db-action-form" onclick="event.stopPropagation();">
                <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$n["id"] ?>">
            <button type="submit" class="notification-menu"
                    title="Delete notification"
                    onclick="return confirm('Delete this notification?');">
                <i class="fa-solid fa-ellipsis-vertical"></i>
            </button>
        </form>
    </div>
<?php endforeach; ?>
</div>


<div
                    class="empty-state"
                    id="emptyState"
                    style="<?= $totalNotifications === 0 ? "display:block;" : "" ?>"
                >

                    <i class="fa-regular fa-bell-slash"></i>

                    <strong>
                        No notifications found
                    </strong>

                    <span>
                        Try changing your filter or search.
                    </span>

                </div>

            </div>

        </section>

    </main>

</div>



<script>
let currentFilter = "all";

function updateCounts() {
    const items = document.querySelectorAll(".notification-item");
    const unread = document.querySelectorAll(".notification-item.unread");
    document.getElementById("totalNotifications").textContent = items.length;
    document.getElementById("unreadNotifications").textContent = unread.length;
    const dot = document.getElementById("topDot");
    if (dot) dot.style.display = unread.length ? "block" : "none";
}

function markOneRead(item) {
    if (!item.classList.contains("unread")) return;

    const form = document.createElement("form");
    form.method = "POST";
    form.action = "notifications.php";
    form.style.display = "none";

    const action = document.createElement("input");
    action.type = "hidden";
    action.name = "action";
    action.value = "read";

    const id = document.createElement("input");
    id.type = "hidden";
    id.name = "id";
    id.value = item.dataset.id;

    form.appendChild(action);
    form.appendChild(id);
    document.body.appendChild(form);
    form.submit();
}

function filterNotifications(filter, button) {
    currentFilter = filter;
    document.querySelectorAll(".filter-tab").forEach(tab => tab.classList.remove("active"));
    button.classList.add("active");
    applyFilters();
}

function applyFilters() {
    const search = document.getElementById("searchInput").value.toLowerCase().trim();
    const items = document.querySelectorAll(".notification-item");
    let visible = 0;

    items.forEach(item => {
        const type = item.dataset.type || "";
        const text = item.dataset.search || "";

        let filterMatch = true;
        if (currentFilter === "unread") filterMatch = item.classList.contains("unread");
        else if (currentFilter === "transaction") filterMatch = type === "transaction" || type === "success";
        else if (currentFilter === "security") filterMatch = type === "security";

        const show = filterMatch && text.includes(search);
        item.style.display = show ? "flex" : "none";
        if (show) visible++;
    });

    document.getElementById("emptyState").style.display = visible === 0 ? "block" : "none";
}

document.querySelectorAll(".notification-item").forEach(item => {
    item.addEventListener("click", function(event) {
        if (event.target.closest("form")) return;
        markOneRead(this);
    });
});

document.getElementById("searchInput").addEventListener("input", applyFilters);

updateCounts();
applyFilters();
</script>
