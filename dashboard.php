<?php
session_start();
require_once "config/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

// Get logged-in user's basic information.
$userStmt = $conn->prepare(
    "SELECT first_name, last_name, email, account_type
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$fullName = $user["first_name"] . " " . $user["last_name"];
$firstName = $user["first_name"];
$initials = strtoupper(substr($user["first_name"], 0, 1) . substr($user["last_name"], 0, 1));

// Get the user's account.
$accountStmt = $conn->prepare(
    "SELECT id, account_number, ifsc, account_type, balance
     FROM accounts
     WHERE user_id = ? AND status = 'Active'
     ORDER BY id ASC
     LIMIT 1"
);
$accountStmt->bind_param("i", $userId);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc();
$accountStmt->close();

$accountId = $account ? (int) $account["id"] : 0;
$balance = $account ? (float) $account["balance"] : 0.00;
$accountType = $account["account_type"] ?? $user["account_type"] ?? "Savings";
$last4 = $account ? substr($account["account_number"], -4) : "----";

// Current month income and spending.
$income = 0.00;
$spending = 0.00;

if ($accountId > 0) {
    $incomeStmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM transactions
         WHERE account_id = ?
           AND type = 'credit'
           AND status = 'success'
           AND YEAR(created_at) = YEAR(CURDATE())
           AND MONTH(created_at) = MONTH(CURDATE())"
    );
    $incomeStmt->bind_param("i", $accountId);
    $incomeStmt->execute();
    $incomeStmt->bind_result($income);
    $incomeStmt->fetch();
    $incomeStmt->close();

    $spendingStmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM transactions
         WHERE account_id = ?
           AND type = 'debit'
           AND status = 'success'
           AND YEAR(created_at) = YEAR(CURDATE())
           AND MONTH(created_at) = MONTH(CURDATE())"
    );
    $spendingStmt->bind_param("i", $accountId);
    $spendingStmt->execute();
    $spendingStmt->bind_result($spending);
    $spendingStmt->fetch();
    $spendingStmt->close();
}

// Get the five latest successful/pending/failed transactions.
$transactions = [];

if ($accountId > 0) {
    $txStmt = $conn->prepare(
        "SELECT type, amount, counterparty, category, description, status, created_at
         FROM transactions
         WHERE account_id = ?
         ORDER BY created_at DESC
         LIMIT 5"
    );
    $txStmt->bind_param("i", $accountId);
    $txStmt->execute();
    $txResult = $txStmt->get_result();

    while ($row = $txResult->fetch_assoc()) {
        $transactions[] = $row;
    }

    $txStmt->close();
}

// Get the last 7 days of debit totals for the spending chart.
$dailySpending = array_fill(0, 7, 0.00);

if ($accountId > 0) {
    $chartStmt = $conn->prepare(
        "SELECT DATE(created_at) AS tx_date, COALESCE(SUM(amount), 0) AS total
         FROM transactions
         WHERE account_id = ?
           AND type = 'debit'
           AND status = 'success'
           AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at)"
    );
    $chartStmt->bind_param("i", $accountId);
    $chartStmt->execute();
    $chartResult = $chartStmt->get_result();

    $chartValues = [];
    while ($row = $chartResult->fetch_assoc()) {
        $chartValues[$row["tx_date"]] = (float) $row["total"];
    }
    $chartStmt->close();

    for ($i = 0; $i < 7; $i++) {
        $date = date("Y-m-d", strtotime("-" . (6 - $i) . " days"));
        $dailySpending[$i] = $chartValues[$date] ?? 0.00;
    }
}

$maxSpending = max($dailySpending);
if ($maxSpending <= 0) {
    $maxSpending = 1;
}

function money($amount): string {
    return "₹" . number_format((float) $amount, 2);
}

function transactionDate($date): string {
    return date("M j, g:i A", strtotime($date));
}

function transactionLabel($tx): string {
    if (!empty($tx["counterparty"])) {
        return $tx["counterparty"];
    }
    if (!empty($tx["description"])) {
        return $tx["description"];
    }
    return ucfirst($tx["type"]) . " Transaction";
}

function transactionCategory($tx): string {
    return !empty($tx["category"]) ? $tx["category"] : "Banking";
}

function transactionIcon($tx): string {
    $category = strtolower((string) ($tx["category"] ?? ""));
    $name = strtolower((string) ($tx["counterparty"] ?? ""));

    if (str_contains($name, "amazon")) return "fa-brands fa-amazon";
    if (str_contains($name, "swiggy")) return "fa-solid fa-cart-shopping";
    if (str_contains($category, "utility") || str_contains($category, "bill")) return "fa-solid fa-bolt";
    if ($tx["type"] === "credit") return "fa-solid fa-arrow-down";
    return "fa-solid fa-arrow-up";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard — NexaBank</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="css/style.css">

    <style>

        body {
            background: #f4f7fb;
            color: #172033;
        }

        .dashboard-layout {
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

        .dashboard-main {
            margin-left: 245px;
            width: calc(100% - 245px);
        }

        .dashboard-header {
            height: 75px;
            padding: 0 35px;
            background: white;
            border-bottom: 1px solid #e7ebf1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-search {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #94a3b8;
            font-size: 13px;
        }

        .header-search input {
            border: none;
            outline: none;
            width: 250px;
            font-family: inherit;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 22px;
        }

        .notification {
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e7ebf1;
            border-radius: 9px;
            color: #64748b;
            position: relative;
            cursor: pointer;
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

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
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
            font-weight: 700;
            font-size: 12px;
        }

        .user-info strong {
            display: block;
            font-size: 12px;
        }

        .user-info span {
            display: block;
            color: #94a3b8;
            font-size: 10px;
        }

        /* CONTENT */

        .dashboard-content {
            padding: 32px 35px 50px;
        }

        .welcome {
            margin-bottom: 28px;
        }

        .welcome h1 {
            font-size: 25px;
            color: #172033;
            margin-bottom: 4px;
        }

        .welcome p {
            color: #7b8798;
            font-size: 12px;
        }

        /* BALANCE CARDS */

        .balance-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr;
            gap: 18px;
            margin-bottom: 22px;
        }

        .balance-main {
            background: linear-gradient(135deg, #2864d7, #4f8cff);
            color: white;
            padding: 25px;
            border-radius: 15px;
            position: relative;
            overflow: hidden;
        }

        .balance-main::after {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            right: -50px;
            top: -70px;
        }

        .balance-label {
            font-size: 11px;
            opacity: 0.8;
        }

        .balance-amount {
            font-size: 34px;
            font-weight: 700;
            margin: 8px 0 18px;
        }

        .balance-account {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            opacity: 0.85;
        }

        .stat-card {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 15px;
            padding: 23px;
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-icon {
            width: 38px;
            height: 38px;
            background: #edf4ff;
            color: #4f8cff;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-card h3 {
            margin: 17px 0 4px;
            font-size: 22px;
        }

        .stat-card p {
            color: #94a3b8;
            font-size: 10px;
        }

        .positive {
            color: #16a67d;
        }

        /* QUICK ACTIONS */

        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .quick-action {
            background: white;
            border: 1px solid #e7ebf1;
            padding: 12px 17px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            gap: 9px;
            color: #344054;
            font-size: 11px;
            cursor: pointer;
            transition: 0.2s;
        }

        .quick-action:hover {
            border-color: #4f8cff;
            color: #2864d7;
        }

        .quick-action i {
            color: #4f8cff;
        }

        /* LOWER GRID */

        .lower-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 20px;
        }

        .dashboard-card {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 15px;
            padding: 22px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
        }

        .card-header h3 {
            font-size: 14px;
        }

        .card-header a {
            color: #4f8cff;
            font-size: 10px;
        }

        /* TRANSACTIONS */

        .transaction {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 13px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .transaction:last-child {
            border-bottom: none;
        }

        .transaction-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .transaction-icon {
            width: 37px;
            height: 37px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #f1f5f9;
            color: #475569;
        }

        .transaction-info strong {
            display: block;
            font-size: 11px;
        }

        .transaction-info span {
            color: #94a3b8;
            font-size: 9px;
        }

        .transaction-amount {
            font-size: 11px;
            font-weight: 700;
        }

        .debit {
            color: #ef4444;
        }

        .credit {
            color: #16a67d;
        }

        /* SPENDING */

        .chart {
            height: 190px;
            display: flex;
            align-items: flex-end;
            gap: 12px;
            padding: 15px 5px;
            border-bottom: 1px solid #edf0f4;
        }

        .bar {
            flex: 1;
            background: #dbe8ff;
            border-radius: 5px 5px 0 0;
            transition: 0.3s;
        }

        .bar:hover {
            background: #4f8cff;
        }

        .chart-labels {
            display: flex;
            justify-content: space-between;
            color: #94a3b8;
            font-size: 8px;
            margin-top: 9px;
        }

        /* MOBILE */

        .mobile-menu {
            display: none;
        }

        @media (max-width: 1050px) {

            .balance-grid {
                grid-template-columns: 1fr 1fr;
            }

            .balance-main {
                grid-column: 1 / -1;
            }

            .lower-grid {
                grid-template-columns: 1fr;
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

            .dashboard-main {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .dashboard-header {
                padding: 0 18px;
            }

            .header-search input {
                width: 120px;
            }

            .dashboard-content {
                padding: 25px 18px;
            }

            .balance-grid {
                grid-template-columns: 1fr;
            }

            .balance-main {
                grid-column: auto;
            }

            .quick-actions {
                overflow-x: auto;
            }
        }

        @media (max-width: 500px) {

            .user-info {
                display: none;
            }

            .header-search {
                display: none;
            }

            .welcome h1 {
                font-size: 21px;
            }

            .balance-amount {
                font-size: 29px;
            }
        }

    </style>
</head>


<body>

<div class="dashboard-layout">


    <!-- SIDEBAR -->

    <aside class="sidebar">

        <a href="dashboard.php" class="logo">

            <div class="logo-icon">
                <i class="fa-solid fa-building-columns"></i>
            </div>

            <span>Nexa<span>Bank</span></span>

        </a>


        <div class="sidebar-menu">

            <div class="menu-title">
                MAIN
            </div>

            <a href="#" class="sidebar-link active">
                <i class="fa-solid fa-grid-2"></i>
                <span>Dashboard</span>
            </a>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-wallet"></i>
                <span>My Accounts</span>
            </a>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-arrow-right-arrow-left"></i>
                <span>Transfer</span>
            </a>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-credit-card"></i>
                <span>Cards</span>
            </a>


            <div class="menu-title">
                MANAGEMENT
            </div>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Transactions</span>
            </a>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Analytics</span>
            </a>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-user-group"></i>
                <span>Beneficiaries</span>
            </a>


            <div class="menu-title">
                SETTINGS
            </div>

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

    <main class="dashboard-main">


        <!-- HEADER -->

        <header class="dashboard-header">

            <div class="header-search">

                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="text"
                    placeholder="Search transactions..."
                >

            </div>


            <div class="header-right">

                <div class="notification">

                    <i class="fa-regular fa-bell"></i>

                    <span class="notification-dot"></span>

                </div>


                <div class="user-profile">

                    <div class="avatar">
                        <?= htmlspecialchars($initials) ?>
                    </div>

                    <div class="user-info">

                        <strong><?= htmlspecialchars($fullName) ?></strong>

                        <span>Premium Customer</span>

                    </div>

                    <i class="fa-solid fa-chevron-down"
                       style="font-size:9px;color:#94a3b8;">
                    </i>

                </div>

            </div>

        </header>


        <!-- CONTENT -->

        <section class="dashboard-content">


            <div class="welcome">

                <h1>
                    Good evening, <?= htmlspecialchars($firstName) ?> 👋
                </h1>

                <p>
                    Here's what's happening with your money today.
                </p>

            </div>


            <!-- BALANCES -->

            <div class="balance-grid">


                <div class="balance-main">

                    <span class="balance-label">
                        TOTAL AVAILABLE BALANCE
                    </span>

                    <div class="balance-amount">
                        <?= money($balance) ?>
                    </div>

                    <div class="balance-account">

                        <span>
                            <?= htmlspecialchars($accountType) ?> Account
                        </span>

                        <span>
                            •••• <?= htmlspecialchars($last4) ?>
                        </span>

                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-top">

                        <span style="font-size:11px;color:#64748b;">
                            This Month
                        </span>

                        <div class="stat-icon">
                            <i class="fa-solid fa-arrow-down"></i>
                        </div>

                    </div>

                    <h3>
                        <?= money($income) ?>
                    </h3>

                    <p class="positive">
                        +12.4% income
                    </p>

                </div>


                <div class="stat-card">

                    <div class="stat-top">

                        <span style="font-size:11px;color:#64748b;">
                            This Month
                        </span>

                        <div class="stat-icon">
                            <i class="fa-solid fa-arrow-up"></i>
                        </div>

                    </div>

                    <h3>
                        <?= money($spending) ?>
                    </h3>

                    <p>
                        Total spending
                    </p>

                </div>


            </div>


            <!-- QUICK ACTIONS -->

            <div class="quick-actions">

                <button class="quick-action"
                        onclick="window.location.href='transfer.html'">

                    <i class="fa-solid fa-paper-plane"></i>

                    Send Money

                </button>


                <button class="quick-action">

                    <i class="fa-solid fa-plus"></i>

                    Add Money

                </button>


                <button class="quick-action">

                    <i class="fa-solid fa-qrcode"></i>

                    Scan & Pay

                </button>


                <button class="quick-action">

                    <i class="fa-solid fa-receipt"></i>

                    Pay Bills

                </button>

            </div>


            <!-- LOWER CONTENT -->

            <div class="lower-grid">


                <!-- TRANSACTIONS -->

                <div class="dashboard-card">

                    <div class="card-header">

                        <h3>
                            Recent Transactions
                        </h3>

                        <a href="transactions.html">
                            View all
                        </a>

                    </div>


                    <div class="transaction">

                        <div class="transaction-left">

                            <div class="transaction-icon">
                                <i class="fa-brands fa-amazon"></i>
                            </div>

                            <div class="transaction-info">

                                <strong>
                                    Amazon
                                </strong>

                                <span>
                                    Shopping • Today, 11:42 AM
                                </span>

                            </div>

                        </div>

                        <span class="transaction-amount debit">
                            - ₹1,299
                        </span>

                    </div>


                    <div class="transaction">

                        <div class="transaction-left">

                            <div class="transaction-icon">
                                <i class="fa-solid fa-arrow-down"></i>
                            </div>

                            <div class="transaction-info">

                                <strong>
                                    Received from Arun
                                </strong>

                                <span>
                                    Transfer • Yesterday
                                </span>

                            </div>

                        </div>

                        <span class="transaction-amount credit">
                            + ₹5,000
                        </span>

                    </div>


                    <div class="transaction">

                        <div class="transaction-left">

                            <div class="transaction-icon">
                                <i class="fa-solid fa-bolt"></i>
                            </div>

                            <div class="transaction-info">

                                <strong>
                                    Electricity Bill
                                </strong>

                                <span>
                                    Utilities • Sep 18
                                </span>

                            </div>

                        </div>

                        <span class="transaction-amount debit">
                            - ₹2,340
                        </span>

                    </div>


                    <div class="transaction">

                        <div class="transaction-left">

                            <div class="transaction-icon">
                                <i class="fa-solid fa-cart-shopping"></i>
                            </div>

                            <div class="transaction-info">

                                <strong>
                                    Swiggy
                                </strong>

                                <span>
                                    Food • Sep 17
                                </span>

                            </div>

                        </div>

                        <span class="transaction-amount debit">
                            - ₹540
                        </span>

                    </div>


                    <div class="transaction">

                        <div class="transaction-left">

                            <div class="transaction-icon">
                                <i class="fa-solid fa-arrow-down"></i>
                            </div>

                            <div class="transaction-info">

                                <strong>
                                    Salary Credit
                                </strong>

                                <span>
                                    Income • Sep 15
                                </span>

                            </div>

                        </div>

                        <span class="transaction-amount credit">
                            + ₹25,000
                        </span>

                    </div>

                </div>


                <!-- SPENDING -->

                <div class="dashboard-card">

                    <div class="card-header">

                        <h3>
                            Spending Overview
                        </h3>

                        <span style="font-size:10px;color:#94a3b8;">
                            Sep 2026
                        </span>

                    </div>


                    <div class="chart">

                        <?php foreach ($dailySpending as $daySpend): ?>
                            <div class="bar" style="height:<?= max(4, ($daySpend / $maxSpending) * 100) ?>%;"></div>
                        <?php endforeach; ?>

                    </div>


                    <div class="chart-labels">

                        <?php for ($i = 6; $i >= 0; $i--): ?>
                            <span><?= date("D", strtotime("-" . $i . " days")) ?></span>
                        <?php endfor; ?>

                    </div>

                </div>


            </div>

        </section>

    </main>

</div>

</body>
</html>
