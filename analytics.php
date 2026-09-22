<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/config/db.php";

$userId = (int)$_SESSION["user_id"];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function money($value) {
    return "₹" . number_format((float)$value, 2);
}

$period = isset($_GET["period"]) ? (int)$_GET["period"] : 6;
if (!in_array($period, [1, 6, 12], true)) {
    $period = 6;
}

$monthsBack = $period - 1;
$startDate = date("Y-m-01", strtotime("-{$monthsBack} months"));
$endDate = date("Y-m-d", strtotime("+1 month", strtotime(date("Y-m-01"))));

$stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fullName = trim(($user["first_name"] ?? "") . " " . ($user["last_name"] ?? ""));
$initials = strtoupper(substr($user["first_name"] ?? "U", 0, 1) . substr($user["last_name"] ?? "", 0, 1));
$initials = $initials ?: "U";

$income = 0.0;
$expense = 0.0;

$stmt = $conn->prepare("
    SELECT type, COALESCE(SUM(amount),0) AS total
    FROM transactions
    WHERE user_id = ? AND status = 'success'
      AND created_at >= ? AND created_at < ?
    GROUP BY type
");
$stmt->bind_param("iss", $userId, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    if ($row["type"] === "credit") $income = (float)$row["total"];
    if ($row["type"] === "debit") $expense = (float)$row["total"];
}
$stmt->close();

$savings = $income - $expense;
$savingsRate = $income > 0 ? ($savings / $income) * 100 : 0;
$averageMonthlySpend = $period > 0 ? $expense / $period : 0;

$monthly = [];
$cursor = new DateTime($startDate);
$endCursor = new DateTime(date("Y-m-01"));

while ($cursor <= $endCursor) {
    $key = $cursor->format("Y-m");
    $monthly[$key] = [
        "label" => $cursor->format("M"),
        "income" => 0.0,
        "expense" => 0.0
    ];
    $cursor->modify("+1 month");
}

$stmt = $conn->prepare("
    SELECT DATE_FORMAT(created_at,'%Y-%m') AS month_key,
           type, COALESCE(SUM(amount),0) AS total
    FROM transactions
    WHERE user_id = ? AND status = 'success'
      AND created_at >= ? AND created_at < ?
    GROUP BY month_key, type
    ORDER BY month_key
");
$stmt->bind_param("iss", $userId, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    if (!isset($monthly[$row["month_key"]])) continue;
    if ($row["type"] === "credit") $monthly[$row["month_key"]]["income"] = (float)$row["total"];
    if ($row["type"] === "debit") $monthly[$row["month_key"]]["expense"] = (float)$row["total"];
}
$stmt->close();

$maxChart = 0.0;
foreach ($monthly as $m) {
    $maxChart = max($maxChart, $m["income"], $m["expense"]);
}
if ($maxChart <= 0) $maxChart = 1;

$categories = [];
$stmt = $conn->prepare("
    SELECT COALESCE(NULLIF(category,''),'Other') AS category_name,
           COALESCE(SUM(amount),0) AS total
    FROM transactions
    WHERE user_id = ? AND type = 'debit' AND status = 'success'
      AND created_at >= ? AND created_at < ?
    GROUP BY category_name
    ORDER BY total DESC
    LIMIT 6
");
$stmt->bind_param("iss", $userId, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $categories[] = [
        "name" => $row["category_name"],
        "amount" => (float)$row["total"]
    ];
}
$stmt->close();

$categoryTotal = array_sum(array_column($categories, "amount"));

function categoryIcon($category) {
    $c = strtolower((string)$category);
    if (strpos($c, "food") !== false || strpos($c, "dining") !== false || strpos($c, "restaurant") !== false) return "fa-solid fa-utensils";
    if (strpos($c, "shop") !== false) return "fa-solid fa-cart-shopping";
    if (strpos($c, "transport") !== false || strpos($c, "travel") !== false || strpos($c, "fuel") !== false) return "fa-solid fa-car";
    if (strpos($c, "bill") !== false || strpos($c, "util") !== false) return "fa-solid fa-house";
    if (strpos($c, "entertain") !== false) return "fa-solid fa-film";
    return "fa-solid fa-wallet";
}

$budgetTargets = [
    "Food & Dining" => 8000,
    "Shopping" => 10000,
    "Entertainment" => 5000,
    "Transport" => 5000
];

$budgetRows = [];
$matchedCategoryAmount = 0.0;

foreach ($budgetTargets as $name => $target) {
    $spent = 0.0;

    foreach ($categories as $cat) {
        if (strcasecmp($cat["name"], $name) === 0) {
            $spent = $cat["amount"];
            break;
        }
    }

    $matchedCategoryAmount += $spent;

    $pct = $target > 0 ? min(100, ($spent / $target) * 100) : 0;

    $budgetRows[] = [
        "name" => $name,
        "spent" => $spent,
        "target" => $target,
        "pct" => $pct
    ];
}

$otherSpent = max(0, $categoryTotal - $matchedCategoryAmount);
if ($otherSpent > 0) {
    $otherTarget = 10000.0;
    $otherPct = min(100, ($otherSpent / $otherTarget) * 100);

    $budgetRows[] = [
        "name" => "Other / Transfers",
        "spent" => $otherSpent,
        "target" => $otherTarget,
        "pct" => $otherPct
    ];
}

$topCategory = $categories[0] ?? null;
$budgetAlertRows = array_filter($budgetRows, function($b) {
    return $b["pct"] >= 80;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Analytics — NexaBank</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

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

        .period-select {
            height: 38px;
            border: 1px solid #e1e6ed;
            border-radius: 8px;
            background: white;
            padding: 0 12px;
            font-family: inherit;
            font-size: 10px;
            color: #475569;
            outline: none;
        }

        /* SUMMARY */

        .summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 14px;
            padding: 19px;
        }

        .summary-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        }

        .summary-card span {
            display: block;
            color: #94a3b8;
            font-size: 9px;
            margin-top: 13px;
        }

        .summary-card strong {
            display: block;
            font-size: 20px;
            margin-top: 4px;
        }

        .change {
            color: #16a67d;
            font-size: 8px;
            margin-top: 5px;
        }

        .change.down {
            color: #ef4444;
        }

        /* GRID */

        .analytics-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .panel {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 15px;
            padding: 22px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .panel-header h3 {
            font-size: 13px;
        }

        .panel-header span {
            color: #94a3b8;
            font-size: 8px;
        }

        /* BAR CHART */

        .chart {
            height: 250px;
            display: flex;
            align-items: flex-end;
            justify-content: space-around;
            gap: 12px;
            padding: 15px 5px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .month {
            height: 100%;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
        }

        .bars {
            width: 100%;
            max-width: 45px;
            height: 205px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 4px;
        }

        .bar {
            width: 17px;
            border-radius: 5px 5px 0 0;
            transition: 0.3s;
        }

        .bar:hover {
            opacity: 0.75;
        }

        .income-bar {
            background: #38d39f;
        }

        .expense-bar {
            background: #4f8cff;
        }

        .month label {
            color: #94a3b8;
            font-size: 8px;
        }

        .legend {
            display: flex;
            gap: 18px;
            margin-top: 15px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #64748b;
            font-size: 8px;
        }

        .legend-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
        }

        .income-dot {
            background: #38d39f;
        }

        .expense-dot {
            background: #4f8cff;
        }

        /* CATEGORY */

        .category-list {
            display: flex;
            flex-direction: column;
            gap: 17px;
        }

        .category-row {
            display: grid;
            grid-template-columns: 34px 1fr auto;
            gap: 10px;
            align-items: center;
        }

        .category-icon {
            width: 34px;
            height: 34px;
            background: #f1f5f9;
            color: #4f8cff;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .category-info strong {
            display: block;
            font-size: 10px;
        }

        .category-info span {
            color: #94a3b8;
            font-size: 8px;
        }

        .category-amount {
            font-size: 9px;
            font-weight: 700;
        }

        .category-progress {
            grid-column: 2 / 4;
            height: 5px;
            background: #edf0f4;
            border-radius: 10px;
            overflow: hidden;
            margin-top: -5px;
        }

        .category-progress div {
            height: 100%;
            border-radius: 10px;
            background: #4f8cff;
        }

        /* LOWER GRID */

        .lower-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* BUDGET */

        .budget-item {
            margin-bottom: 20px;
        }

        .budget-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 7px;
        }

        .budget-header span {
            color: #64748b;
            font-size: 9px;
        }

        .budget-header strong {
            font-size: 9px;
        }

        .budget-bar {
            height: 7px;
            background: #edf0f4;
            border-radius: 10px;
            overflow: hidden;
        }

        .budget-fill {
            height: 100%;
            border-radius: 10px;
            background: #4f8cff;
        }

        .budget-fill.warning {
            background: #f59e0b;
        }

        .budget-fill.danger {
            background: #ef4444;
        }

        /* INSIGHTS */

        .insight {
            display: flex;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .insight:last-child {
            border-bottom: none;
        }

        .insight-icon {
            width: 34px;
            height: 34px;
            flex-shrink: 0;
            background: #edf4ff;
            color: #4f8cff;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .insight strong {
            display: block;
            font-size: 10px;
            margin-bottom: 4px;
        }

        .insight p {
            color: #94a3b8;
            font-size: 8px;
            line-height: 1.5;
        }

        /* RESPONSIVE */

        @media (max-width: 1100px) {

            .summary {
                grid-template-columns: repeat(2, 1fr);
            }

            .analytics-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 850px) {

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

            .heading {
                align-items: flex-start;
                flex-direction: column;
                gap: 15px;
            }

        }

        @media (max-width: 520px) {

            .summary {
                grid-template-columns: 1fr;
            }

            .chart {
                gap: 4px;
            }

            .bars {
                gap: 2px;
            }

            .bar {
                width: 11px;
            }

            .income-bar {
                max-width: 15px;
            }

            .expense-bar {
                max-width: 15px;
            }

        }

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

            <a href="cards.php" class="sidebar-link">
                <i class="fa-solid fa-credit-card"></i>
                <span>Cards</span>
            </a>


            <div class="menu-title">MANAGEMENT</div>

            <a href="transactions.php" class="sidebar-link">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Transactions</span>
            </a>

            <a href="analytics.php" class="sidebar-link active">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Analytics</span>
            </a>

            <a href="beneficiaries.php" class="sidebar-link">
                <i class="fa-solid fa-user-group"></i>
                <span>Beneficiaries</span>
            </a>


            <div class="menu-title">SETTINGS</div>

            <a href="profile.php" class="sidebar-link">
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

            <h2>Analytics</h2>

            <div class="topbar-right">

                <div class="notification">

                    <i class="fa-regular fa-bell"></i>

                    <span class="notification-dot"></span>

                </div>

                <div class="avatar">
                    <?= e($initials) ?>
                </div>

            </div>

        </header>


        <section class="content">


            <!-- HEADING -->

            <div class="heading">

                <div>

                    <h1>Financial Analytics</h1>

                    <p>
                        Understand your income, spending and financial habits.
                    </p>

                </div>


                <select
                    class="period-select"
                    id="periodSelect"
                    onchange="window.location.href='analytics.php?period=' + this.value"
                >

                    <option value="6" <?= $period === 6 ? "selected" : "" ?>>
                        Last 6 Months
                    </option>

                    <option value="12" <?= $period === 12 ? "selected" : "" ?>>
                        Last 12 Months
                    </option>

                    <option value="1" <?= $period === 1 ? "selected" : "" ?>>
                        This Month
                    </option>

                </select>

            </div>


            <!-- SUMMARY -->

            <div class="summary">


                <div class="summary-card">

                    <div class="summary-top">

                        <div class="summary-icon">
                            <i class="fa-solid fa-arrow-down"></i>
                        </div>

                    </div>

                    <span>Total Income</span>

                    <strong id="incomeValue"><?= money($income) ?></strong>

                    <div class="change"><?= $period ?> month<?= $period === 1 ? "" : "s" ?> tracked</div>

                </div>


                <div class="summary-card">

                    <div class="summary-top">

                        <div class="summary-icon">
                            <i class="fa-solid fa-arrow-up"></i>
                        </div>

                    </div>

                    <span>Total Expenses</span>

                    <strong id="expenseValue"><?= money($expense) ?></strong>

                    <div class="change down"><?= $expense > 0 ? "Tracked debit activity" : "No expenses recorded" ?></div>

                </div>


                <div class="summary-card">

                    <div class="summary-top">

                        <div class="summary-icon">
                            <i class="fa-solid fa-piggy-bank"></i>
                        </div>

                    </div>

                    <span>Total Savings</span>

                    <strong id="savingValue"><?= money($savings) ?></strong>

                    <div class="change"><?= number_format($savingsRate, 1) ?>% savings rate</div>

                </div>


                <div class="summary-card">

                    <div class="summary-top">

                        <div class="summary-icon">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>

                    </div>

                    <span>Average Monthly Spend</span>

                    <strong id="averageValue"><?= money($averageMonthlySpend) ?></strong>

                    <div class="change"><?= $expense > 0 ? "Average per selected month" : "No spending recorded" ?></div>

                </div>

            </div>


            <!-- CHARTS -->

            <div class="analytics-grid">


                <!-- INCOME EXPENSE -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>
                            Income vs Expenses
                        </h3>

                        <span>
                            Monthly comparison
                        </span>

                    </div>


                    <div class="chart">
                        <?php foreach ($monthly as $m): ?>
                            <?php
                                $incomeHeight = ($m["income"] / $maxChart) * 100;
                                $expenseHeight = ($m["expense"] / $maxChart) * 100;
                            ?>
                            <div class="month">
                                <div class="bars" title="Income: <?= e(money($m["income"])) ?> | Expenses: <?= e(money($m["expense"])) ?>">
                                    <div class="bar income-bar" style="height:<?= $m["income"] > 0 ? max(2, $incomeHeight) : 0 ?>%"></div>
                                    <div class="bar expense-bar" style="height:<?= $m["expense"] > 0 ? max(2, $expenseHeight) : 0 ?>%"></div>
                                </div>
                                <label><?= e($m["label"]) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="legend">

                        <div class="legend-item">

                            <span class="legend-dot income-dot"></span>

                            Income

                        </div>


                        <div class="legend-item">

                            <span class="legend-dot expense-dot"></span>

                            Expenses

                        </div>

                    </div>

                </div>


                <!-- SPENDING CATEGORY -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>
                            Spending by Category
                        </h3>

                        <span>
                            <?= money($categoryTotal) ?> total
                        </span>

                    </div>


                    <div class="category-list">
                        <?php if ($categories): ?>
                            <?php foreach ($categories as $cat): ?>
                                <?php
                                    $share = $categoryTotal > 0 ? ($cat["amount"] / $categoryTotal) * 100 : 0;
                                ?>
                                <div class="category-row">
                                    <div class="category-icon">
                                        <i class="<?= e(categoryIcon($cat["name"])) ?>"></i>
                                    </div>
                                    <div class="category-info">
                                        <strong><?= e($cat["name"]) ?></strong>
                                        <span><?= number_format($share, 1) ?>% of spending</span>
                                    </div>
                                    <div class="category-amount">
                                        <?= money($cat["amount"]) ?>
                                    </div>
                                    <div class="category-progress">
                                        <div style="width:<?= min(100, $share) ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding:20px;text-align:center;color:#94a3b8;font-size:10px;">
                                No expense categories recorded for this period.
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

            </div>


            <!-- LOWER SECTION -->

            <div class="lower-grid">


                <!-- BUDGET -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>
                            Budget Progress
                        </h3>

                        <span>
                            September
                        </span>

                    </div>


                    <?php foreach ($budgetRows as $budget): ?>
                            <?php
                                $fillClass = $budget["pct"] >= 95 ? "danger" : ($budget["pct"] >= 80 ? "warning" : "");
                            ?>
                            <div class="budget-item">
                                <div class="budget-header">
                                    <span><?= e($budget["name"]) ?></span>
                                    <strong><?= money($budget["spent"]) ?> / <?= money($budget["target"]) ?></strong>
                                </div>
                                <div class="budget-bar">
                                    <div class="budget-fill <?= e($fillClass) ?>" style="width:<?= $budget["pct"] ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?></div>


                <!-- INSIGHTS -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>
                            Smart Insights
                        </h3>

                        <span>
                            Based on your activity
                        </span>

                    </div>


                    <?php if ($expense <= 0): ?>
                        <div class="insight">
                            <div class="insight-icon"><i class="fa-solid fa-chart-line"></i></div>
                            <div>
                                <strong>No expenses recorded</strong>
                                <p>No successful debit transactions were found in the selected period.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="insight">
                            <div class="insight-icon"><i class="fa-solid fa-wallet"></i></div>
                            <div>
                                <strong>Tracked spending</strong>
                                <p>You recorded <?= money($expense) ?> in successful debit transactions during the selected period.</p>
                            </div>
                        </div>

                        <?php if ($topCategory): ?>
                            <div class="insight">
                                <div class="insight-icon"><i class="<?= e(categoryIcon($topCategory["name"])) ?>"></i></div>
                                <div>
                                    <strong>Largest spending category</strong>
                                    <p><?= e($topCategory["name"]) ?> accounts for <?= money($topCategory["amount"]) ?> of tracked spending.</p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="insight">
                            <div class="insight-icon"><i class="fa-solid fa-piggy-bank"></i></div>
                            <div>
                                <strong>Tracked savings rate</strong>
                                <p>Your income minus expenses gives a savings rate of <?= number_format($savingsRate, 1) ?>% for this period.</p>
                            </div>
                        </div>

                        <?php if ($budgetAlertRows): ?>
                            <div class="insight">
                                <div class="insight-icon"><i class="fa-solid fa-bell"></i></div>
                                <div>
                                    <strong>Budget usage</strong>
                                    <p><?= count($budgetAlertRows) ?> tracked budget categor<?= count($budgetAlertRows) === 1 ? "y is" : "ies are" ?> at or above 80% of the demo target.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?></div>

                </div>

            </div>

        </section>

    </main>

</div>


<script>
        // Analytics period is handled by PHP using the ?period= parameter.
    </script>
