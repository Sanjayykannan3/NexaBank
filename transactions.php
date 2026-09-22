<?php
session_start();
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION["user_id"];

function money($amount) {
    return "₹" . number_format((float)$amount, 2);
}

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, email
    FROM users
    WHERE id = ? AND status = 'Active'
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT id, account_number, account_type, balance
    FROM accounts
    WHERE user_id = ? AND status = 'Active'
    ORDER BY id ASC
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();

if (!$account) {
    die("No active bank account found.");
}

/* Current month statistics */
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN type = 'credit' AND status = 'success' THEN amount ELSE 0 END), 0) AS received,
        COALESCE(SUM(CASE WHEN type = 'debit' AND status = 'success' THEN amount ELSE 0 END), 0) AS spent,
        COUNT(*) AS total
    FROM transactions
    WHERE user_id = ?
      AND YEAR(created_at) = YEAR(CURDATE())
      AND MONTH(created_at) = MONTH(CURDATE())
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

/* Load transactions */
$stmt = $conn->prepare("
    SELECT
        id,
        type,
        amount,
        counterparty,
        category,
        description,
        status,
        reference,
        created_at
    FROM transactions
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}

$initials = strtoupper(
    substr($user["first_name"], 0, 1) .
    substr($user["last_name"], 0, 1)
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Transactions — NexaBank</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="css/style.css">

    <style>

        body {
            background: #f4f7fb;
            color: #172033;
        }

        .transactions-layout {
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

        .transactions-main {
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

        .notification {
            width: 35px;
            height: 35px;
            border: 1px solid #e7ebf1;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            position: relative;
        }

        .notification-dot {
            position: absolute;
            width: 7px;
            height: 7px;
            background: #ef4444;
            border-radius: 50%;
            right: 7px;
            top: 7px;
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

        .transactions-content {
            padding: 32px 35px 50px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-size: 25px;
            margin-bottom: 5px;
        }

        .page-heading p {
            color: #7b8798;
            font-size: 12px;
        }

        /* STAT CARDS */

        .transaction-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 17px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 14px;
            padding: 20px;
        }

        .stat-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-card-top span {
            color: #7b8798;
            font-size: 10px;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: #edf4ff;
            color: #4f8cff;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-card h3 {
            font-size: 21px;
            margin-top: 13px;
        }

        .stat-card small {
            display: block;
            color: #94a3b8;
            font-size: 9px;
            margin-top: 3px;
        }

        .green {
            color: #16a67d !important;
        }

        .red {
            color: #ef4444 !important;
        }

        /* TRANSACTION CARD */

        .transaction-card {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 15px;
            overflow: hidden;
        }

        .transaction-card-header {
            padding: 20px 22px;
            border-bottom: 1px solid #edf0f4;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .transaction-card-header h3 {
            font-size: 14px;
        }

        /* FILTERS */

        .filters {
            padding: 18px 22px;
            background: #fafbfc;
            border-bottom: 1px solid #edf0f4;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 200px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 11px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 35px;
            border: 1px solid #e0e5eb;
            border-radius: 8px;
            outline: none;
            background: white;
            font-family: inherit;
            font-size: 10px;
        }

        .filter-select {
            padding: 10px 30px 10px 12px;
            border: 1px solid #e0e5eb;
            border-radius: 8px;
            background: white;
            font-family: inherit;
            font-size: 10px;
            color: #475569;
            outline: none;
            cursor: pointer;
        }

        .date-btn {
            padding: 10px 14px;
            border: 1px solid #e0e5eb;
            border-radius: 8px;
            background: white;
            color: #475569;
            font-family: inherit;
            font-size: 10px;
            cursor: pointer;
        }

        .date-btn i {
            margin-right: 5px;
            color: #4f8cff;
        }

        /* TABLE */

        .transaction-table {
            width: 100%;
            border-collapse: collapse;
        }

        .transaction-table th {
            text-align: left;
            padding: 13px 20px;
            background: #fafbfc;
            color: #94a3b8;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .transaction-table td {
            padding: 15px 20px;
            border-top: 1px solid #edf0f4;
            font-size: 10px;
        }

        .transaction-table tbody tr {
            transition: 0.15s;
            cursor: pointer;
        }

        .transaction-table tbody tr:hover {
            background: #fafcff;
        }

        .transaction-name {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .transaction-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
        }

        .transaction-name strong {
            display: block;
            font-size: 10px;
        }

        .transaction-name span {
            display: block;
            color: #94a3b8;
            font-size: 8px;
            margin-top: 2px;
        }

        .amount {
            font-weight: 700;
        }

        .amount.credit {
            color: #16a67d;
        }

        .amount.debit {
            color: #ef4444;
        }

        .status {
            display: inline-flex;
            padding: 5px 8px;
            border-radius: 20px;
            font-size: 8px;
            font-weight: 600;
        }

        .status.success {
            background: #e8f8f2;
            color: #15946e;
        }

        .status.pending {
            background: #fff7df;
            color: #b68100;
        }

        .status.failed {
            background: #ffeded;
            color: #dc3d3d;
        }

        .action-btn {
            width: 29px;
            height: 29px;
            border: 1px solid #e7ebf1;
            border-radius: 7px;
            background: white;
            color: #64748b;
            cursor: pointer;
        }

        .action-btn:hover {
            color: #4f8cff;
            border-color: #4f8cff;
        }

        /* PAGINATION */

        .pagination {
            padding: 17px 20px;
            border-top: 1px solid #edf0f4;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pagination-text {
            color: #94a3b8;
            font-size: 9px;
        }

        .pagination-buttons {
            display: flex;
            gap: 5px;
        }

        .page-btn {
            width: 29px;
            height: 29px;
            border: 1px solid #e7ebf1;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            color: #64748b;
            font-size: 9px;
        }

        .page-btn.active {
            background: #4f8cff;
            color: white;
            border-color: #4f8cff;
        }

        /* MODAL */

        .detail-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(7,17,31,0.65);
            z-index: 500;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .detail-modal {
            width: 100%;
            max-width: 420px;
            background: white;
            border-radius: 17px;
            padding: 28px;
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
        }

        .detail-header h2 {
            font-size: 17px;
        }

        .close-btn {
            border: none;
            background: #f1f5f9;
            width: 30px;
            height: 30px;
            border-radius: 7px;
            cursor: pointer;
            color: #64748b;
        }

        .detail-icon {
            width: 55px;
            height: 55px;
            margin: 0 auto 15px;
            border-radius: 14px;
            background: #edf4ff;
            color: #4f8cff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .detail-amount {
            text-align: center;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .detail-status {
            text-align: center;
            margin-bottom: 25px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #edf0f4;
            font-size: 10px;
        }

        .detail-row span:first-child {
            color: #94a3b8;
        }

        .detail-row strong {
            text-align: right;
        }

        /* RESPONSIVE */

        @media (max-width: 850px) {

            .transaction-stats {
                grid-template-columns: 1fr;
            }

            .transaction-table {
                min-width: 750px;
            }

            .table-wrapper {
                overflow-x: auto;
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

            .transactions-main {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .transactions-content {
                padding: 25px 18px;
            }

            .topbar {
                padding: 0 18px;
            }
        }

    </style>
</head>
<body>

<div class="transactions-layout">

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

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-credit-card"></i>
                <span>Cards</span>
            </a>

            <div class="menu-title">MANAGEMENT</div>

            <a href="transactions.php" class="sidebar-link active">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Transactions</span>
            </a>

            <a href="analytics.html" class="sidebar-link">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Analytics</span>
            </a>

            <a href="beneficiaries.html" class="sidebar-link">
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

    <main class="transactions-main">

        <header class="topbar">

            <h2>Transactions</h2>

            <div class="topbar-right">

                <div class="notification">
                    <i class="fa-regular fa-bell"></i>
                </div>

                <div class="avatar">
                    <?= htmlspecialchars($initials) ?>
                </div>

            </div>

        </header>

        <section class="transactions-content">

            <div class="page-heading">

                <h1>Transaction History</h1>

                <p>
                    View and manage all your account transactions.
                </p>

            </div>

            <div class="transaction-stats">

                <div class="stat-card">

                    <div class="stat-card-top">

                        <span>Total Received</span>

                        <div class="stat-icon">
                            <i class="fa-solid fa-arrow-down"></i>
                        </div>

                    </div>

                    <h3 class="green">
                        <?= money($stats["received"]) ?>
                    </h3>

                    <small>This month</small>

                </div>

                <div class="stat-card">

                    <div class="stat-card-top">

                        <span>Total Spent</span>

                        <div class="stat-icon">
                            <i class="fa-solid fa-arrow-up"></i>
                        </div>

                    </div>

                    <h3 class="red">
                        <?= money($stats["spent"]) ?>
                    </h3>

                    <small>This month</small>

                </div>

                <div class="stat-card">

                    <div class="stat-card-top">

                        <span>Transactions</span>

                        <div class="stat-icon">
                            <i class="fa-solid fa-receipt"></i>
                        </div>

                    </div>

                    <h3>
                        <?= (int)$stats["total"] ?>
                    </h3>

                    <small>This month</small>

                </div>

            </div>

            <div class="transaction-card">

                <div class="transaction-card-header">

                    <h3>All Transactions</h3>

                    <button class="date-btn" type="button">
                        <i class="fa-regular fa-calendar"></i>
                        <?= date("F Y") ?>
                    </button>

                </div>

                <div class="filters">

                    <div class="search-box">

                        <i class="fa-solid fa-magnifying-glass"></i>

                        <input
                            type="text"
                            id="searchInput"
                            placeholder="Search transactions..."
                            onkeyup="filterTransactions()"
                        >

                    </div>

                    <select
                        class="filter-select"
                        id="typeFilter"
                        onchange="filterTransactions()"
                    >
                        <option value="all">All Types</option>
                        <option value="credit">Money In</option>
                        <option value="debit">Money Out</option>
                    </select>

                    <select
                        class="filter-select"
                        id="statusFilter"
                        onchange="filterTransactions()"
                    >
                        <option value="all">All Status</option>
                        <option value="success">Success</option>
                        <option value="pending">Pending</option>
                        <option value="failed">Failed</option>
                    </select>

                </div>

                <div class="table-wrapper">

                    <table class="transaction-table">

                        <thead>

                            <tr>
                                <th>Transaction</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th></th>
                            </tr>

                        </thead>

                        <tbody id="transactionBody">

                        <?php if (empty($transactions)): ?>

                            <tr>
                                <td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">
                                    No transactions yet.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($transactions as $t): ?>

                                <?php
                                $type = $t["type"];
                                $status = $t["status"];
                                $name = $t["counterparty"] ?: ($type === "credit" ? "Money Received" : "Transfer");
                                $category = $t["category"] ?: "Transaction";

                                if ($type === "credit") {
                                    $icon = "fa-solid fa-arrow-down";
                                } elseif (stripos($category, "transfer") !== false) {
                                    $icon = "fa-solid fa-arrow-right-arrow-left";
                                } else {
                                    $icon = "fa-solid fa-receipt";
                                }

                                $date = date("d M Y", strtotime($t["created_at"]));
                                $fullDate = date("d M Y, h:i A", strtotime($t["created_at"]));
                                $displayAmount = ($type === "credit" ? "+ " : "- ") . money($t["amount"]);
                                ?>

                                <tr
                                    data-type="<?= htmlspecialchars($type) ?>"
                                    data-status="<?= htmlspecialchars($status) ?>"
                                    onclick='showDetails(
                                        <?= json_encode($name) ?>,
                                        <?= json_encode($type) ?>,
                                        <?= json_encode($displayAmount) ?>,
                                        <?= json_encode($fullDate) ?>,
                                        <?= json_encode($category) ?>,
                                        <?= json_encode($t["reference"]) ?>,
                                        <?= json_encode(ucfirst($status)) ?>
                                    )'
                                >

                                    <td>

                                        <div class="transaction-name">

                                            <div class="transaction-icon">
                                                <i class="<?= htmlspecialchars($icon) ?>"></i>
                                            </div>

                                            <div>

                                                <strong>
                                                    <?= htmlspecialchars($name) ?>
                                                </strong>

                                                <span>
                                                    <?= htmlspecialchars($category) ?>
                                                </span>

                                            </div>

                                        </div>

                                    </td>

                                    <td>
                                        <?= htmlspecialchars($date) ?>
                                    </td>

                                    <td>
                                        <?= ucfirst(htmlspecialchars($type)) ?>
                                    </td>

                                    <td class="amount <?= htmlspecialchars($type) ?>">
                                        <?= htmlspecialchars($displayAmount) ?>
                                    </td>

                                    <td>

                                        <span class="status <?= htmlspecialchars($status) ?>">
                                            <?= ucfirst(htmlspecialchars($status)) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <button
                                            class="action-btn"
                                            type="button"
                                            onclick="event.stopPropagation();"
                                        >
                                            <i class="fa-solid fa-ellipsis"></i>
                                        </button>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <div class="pagination">

                    <span class="pagination-text" id="paginationText">
                        Showing <?= count($transactions) ?> transaction<?= count($transactions) === 1 ? "" : "s" ?>
                    </span>

                    <div class="pagination-buttons">

                        <button class="page-btn active" type="button">
                            1
                        </button>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

<div class="detail-overlay" id="detailOverlay">

    <div class="detail-modal">

        <div class="detail-header">

            <h2>Transaction Details</h2>

            <button
                class="close-btn"
                type="button"
                onclick="closeDetails()"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>

        </div>

        <div class="detail-icon">

            <i id="detailIcon" class="fa-solid fa-receipt"></i>

        </div>

        <div class="detail-amount" id="detailAmount">
            ₹0.00
        </div>

        <div class="detail-status">

            <span
                class="status success"
                id="detailStatus"
            >
                Success
            </span>

        </div>

        <div class="detail-row">

            <span>Transaction</span>

            <strong id="detailName">-</strong>

        </div>

        <div class="detail-row">

            <span>Category</span>

            <strong id="detailCategory">-</strong>

        </div>

        <div class="detail-row">

            <span>Date</span>

            <strong id="detailDate">-</strong>

        </div>

        <div class="detail-row">

            <span>Transaction ID</span>

            <strong id="detailId">-</strong>

        </div>

    </div>

</div>

<script>

function filterTransactions() {

    const search =
        document
            .getElementById("searchInput")
            .value
            .toLowerCase();

    const type =
        document
            .getElementById("typeFilter")
            .value;

    const status =
        document
            .getElementById("statusFilter")
            .value;

    const rows =
        document.querySelectorAll(
            "#transactionBody tr[data-type]"
        );

    let visible = 0;

    rows.forEach(row => {

        const text =
            row.textContent.toLowerCase();

        const rowType =
            row.dataset.type;

        const rowStatus =
            row.dataset.status;

        const matchesSearch =
            text.includes(search);

        const matchesType =
            type === "all" ||
            rowType === type;

        const matchesStatus =
            status === "all" ||
            rowStatus === status;

        if (
            matchesSearch &&
            matchesType &&
            matchesStatus
        ) {
            row.style.display = "";
            visible++;
        } else {
            row.style.display = "none";
        }

    });

    document.getElementById("paginationText").textContent =
        "Showing " + visible + " transaction" + (visible === 1 ? "" : "s");

}

function showDetails(
    name,
    type,
    amount,
    date,
    category,
    transactionId,
    status
) {

    document.getElementById("detailName").textContent = name;

    document.getElementById("detailAmount").textContent = amount;

    document.getElementById("detailDate").textContent = date;

    document.getElementById("detailCategory").textContent = category;

    document.getElementById("detailId").textContent = transactionId;

    const statusElement =
        document.getElementById("detailStatus");

    statusElement.textContent = status;

    statusElement.className =
        "status " + status.toLowerCase();

    const icon =
        document.getElementById("detailIcon");

    if (type === "credit") {

        icon.className =
            "fa-solid fa-arrow-down";

    } else {

        icon.className =
            "fa-solid fa-arrow-up";

    }

    document.getElementById("detailOverlay").style.display = "flex";

}

function closeDetails() {

    document.getElementById("detailOverlay").style.display = "none";

}

document
    .getElementById("detailOverlay")
    .addEventListener(
        "click",
        function(event) {

            if (event.target === this) {
                closeDetails();
            }

        }
    );

</script>

</body>
</html>
