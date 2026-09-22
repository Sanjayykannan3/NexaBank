<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/config/csrf.php";

$userId = (int) $_SESSION["user_id"];
$message = "";
$messageType = "";

// Helper
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function redirectWith($message, $type = "success") {
    header("Location: cards.php?msg=" . urlencode($message) . "&type=" . urlencode($type));
    exit;
}

// Load current user's card.
$stmt = $conn->prepare("
    SELECT id, card_last4, card_type, status,
           online_enabled, international_enabled,
           contactless_enabled, atm_enabled,
           spending_limit, spent_amount
    FROM cards
    WHERE user_id = ?
    ORDER BY id ASC
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$card = $stmt->get_result()->fetch_assoc();
$stmt->close();

// If the user has no card yet, create a simulated card automatically.
if (!$card) {
    $last4 = str_pad((string)random_int(0, 9999), 4, "0", STR_PAD_LEFT);
    $cardType = "VISA";

    $stmt = $conn->prepare("
        INSERT INTO cards
        (user_id, card_last4, card_type, status,
         online_enabled, international_enabled,
         contactless_enabled, atm_enabled,
         spending_limit, spent_amount)
        VALUES (?, ?, ?, 'Active', 1, 0, 1, 1, 100000.00, 0.00)
    ");
    $stmt->bind_param("iss", $userId, $last4, $cardType);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT id, card_last4, card_type, status,
               online_enabled, international_enabled,
               contactless_enabled, atm_enabled,
               spending_limit, spent_amount
        FROM cards
        WHERE user_id = ?
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $card = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Handle card actions.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    $action = $_POST["action"];
    $cardId = (int)$card["id"];

    if ($action === "toggle") {
        $fieldMap = [
            "online" => "online_enabled",
            "international" => "international_enabled",
            "contactless" => "contactless_enabled",
            "atm" => "atm_enabled"
        ];

        $control = $_POST["control"] ?? "";
        if (!isset($fieldMap[$control])) {
            redirectWith("Invalid card control.", "error");
        }

        $field = $fieldMap[$control];
        $newValue = !empty($card[$field]) ? 0 : 1;

        $sql = "UPDATE cards SET {$field} = ? WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $newValue, $cardId, $userId);
        $stmt->execute();
        $stmt->close();

        redirectWith(ucwords($control) . " setting updated.");
    }

    if ($action === "freeze") {
        $newStatus = ($card["status"] === "Frozen") ? "Active" : "Frozen";

        $stmt = $conn->prepare("
            UPDATE cards
            SET status = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("sii", $newStatus, $cardId, $userId);
        $stmt->execute();
        $stmt->close();

        redirectWith(
            $newStatus === "Frozen"
                ? "Card frozen successfully."
                : "Card unfrozen successfully."
        );
    }
}

// Reload after any POST.
$stmt = $conn->prepare("
    SELECT id, card_last4, card_type, status,
           online_enabled, international_enabled,
           contactless_enabled, atm_enabled,
           spending_limit, spent_amount
    FROM cards
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $card["id"], $userId);
$stmt->execute();
$card = $stmt->get_result()->fetch_assoc();
$stmt->close();

// URL messages.
if (isset($_GET["msg"])) {
    $message = (string)$_GET["msg"];
    $messageType = ($_GET["type"] ?? "success") === "error" ? "error" : "success";
}

// User information.
$stmt = $conn->prepare("
    SELECT first_name, last_name, email
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fullName = trim(($user["first_name"] ?? "") . " " . ($user["last_name"] ?? ""));
$initials = strtoupper(
    substr($user["first_name"] ?? "U", 0, 1) .
    substr($user["last_name"] ?? "", 0, 1)
);
$initials = $initials ?: "U";

$last4 = e($card["card_last4"]);
$cardType = e($card["card_type"]);
$cardStatus = $card["status"];
$maskedCard = "•••• " . $last4;

$spendingLimit = (float)$card["spending_limit"];
$spentAmount = (float)$card["spent_amount"];
$progress = $spendingLimit > 0
    ? min(100, ($spentAmount / $spendingLimit) * 100)
    : 0;

// Card expiry is presentation-only because the current schema does not store expiry.
$expiry = date("m/y", strtotime("+3 years"));

// Recent card-related activity is based on this user's debit transactions.
// The transactions table does not contain a card_id, so these are shown as
// recent debit activity rather than claiming every debit was made by this card.
$activities = [];
$stmt = $conn->prepare("
    SELECT counterparty, amount, description, created_at, category
    FROM transactions
    WHERE user_id = ? AND type = 'debit'
    ORDER BY created_at DESC
    LIMIT 4
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activities[] = $row;
}
$stmt->close();

function activityIcon($category) {
    $category = strtolower((string)$category);
    if (strpos($category, "food") !== false || strpos($category, "restaurant") !== false) {
        return "fa-solid fa-utensils";
    }
    if (strpos($category, "fuel") !== false || strpos($category, "gas") !== false) {
        return "fa-solid fa-gas-pump";
    }
    if (strpos($category, "atm") !== false || strpos($category, "cash") !== false) {
        return "fa-solid fa-arrow-down";
    }
    return "fa-solid fa-credit-card";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Cards — NexaBank</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="css/style.css">

    <style>

        body {
            background: #f4f7fb;
            color: #172033;
        }

        .cards-layout {
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

        .cards-main {
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

        .cards-content {
            padding: 32px 35px 50px;
        }

        .page-heading {
            margin-bottom: 27px;
        }

        .page-heading h1 {
            font-size: 25px;
            margin-bottom: 5px;
        }

        .page-heading p {
            color: #7b8798;
            font-size: 12px;
        }

        /* CARD SECTION */

        .cards-top {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 22px;
            margin-bottom: 22px;
        }

        .bank-card-section {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 16px;
            padding: 25px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
        }

        .section-header h3 {
            font-size: 14px;
        }

        .card-type {
            color: #94a3b8;
            font-size: 9px;
        }

        /* BANK CARD */

        .bank-card {
            width: 100%;
            max-width: 430px;
            height: 250px;
            margin: auto;
            padding: 25px;
            border-radius: 20px;
            background: linear-gradient(
                135deg,
                #173866 0%,
                #0b1c32 55%,
                #07111f 100%
            );
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 45px rgba(7,17,31,0.28);
        }

        .bank-card::before {
            content: "";
            position: absolute;
            width: 230px;
            height: 230px;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 50%;
            right: -80px;
            top: -80px;
        }

        .bank-card::after {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 50%;
            right: -50px;
            top: -50px;
        }

        .card-brand {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .card-brand strong {
            font-size: 15px;
            letter-spacing: 1px;
        }

        .card-brand i {
            font-size: 20px;
        }

        .card-chip {
            width: 44px;
            height: 33px;
            border-radius: 7px;
            background: linear-gradient(
                135deg,
                #e0c67a,
                #a98a3f
            );
            margin-top: 37px;
            position: relative;
            z-index: 2;
        }

        .card-chip::after {
            content: "";
            position: absolute;
            width: 20px;
            height: 13px;
            border: 1px solid rgba(0,0,0,0.3);
            border-radius: 4px;
            left: 11px;
            top: 9px;
        }

        .card-number {
            font-size: 20px;
            letter-spacing: 3px;
            margin-top: 20px;
            position: relative;
            z-index: 2;
        }

        .card-bottom {
            position: absolute;
            bottom: 21px;
            left: 25px;
            right: 25px;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            z-index: 2;
        }

        .card-label {
            color: #8fa1b7;
            font-size: 7px;
            display: block;
            margin-bottom: 3px;
        }

        .card-holder {
            font-size: 10px;
            letter-spacing: 1px;
        }

        .card-expiry {
            font-size: 10px;
        }

        .visa {
            font-size: 25px;
            font-weight: 800;
            font-style: italic;
        }

        /* CARD INFO */

        .card-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 20px;
        }

        .info-item {
            padding: 13px;
            background: #f8fafc;
            border-radius: 9px;
        }

        .info-item span {
            display: block;
            color: #94a3b8;
            font-size: 8px;
            margin-bottom: 5px;
        }

        .info-item strong {
            font-size: 11px;
        }

        /* CONTROLS */

        .controls-card {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 16px;
            padding: 25px;
        }

        .control {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 17px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .control:last-child {
            border-bottom: none;
        }

        .control-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .control-icon {
            width: 36px;
            height: 36px;
            background: #edf4ff;
            color: #4f8cff;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .control-info strong {
            display: block;
            font-size: 11px;
        }

        .control-info span {
            display: block;
            color: #94a3b8;
            font-size: 8px;
            margin-top: 3px;
        }

        /* TOGGLE */

        .toggle {
            width: 39px;
            height: 21px;
            background: #dbe2ea;
            border-radius: 20px;
            position: relative;
            cursor: pointer;
            transition: 0.25s;
        }

        .toggle::after {
            content: "";
            position: absolute;
            width: 17px;
            height: 17px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: 0.25s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }

        .toggle.active {
            background: #4f8cff;
        }

        .toggle.active::after {
            left: 20px;
        }

        /* BUTTONS */

        .card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 20px;
        }

        .card-action {
            padding: 11px;
            border: 1px solid #e0e5eb;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            color: #475569;
            font-family: inherit;
            font-size: 10px;
        }

        .card-action:hover {
            border-color: #4f8cff;
            color: #2864d7;
        }

        .card-action.danger:hover {
            border-color: #ef4444;
            color: #ef4444;
        }

        /* LIMIT */

        .limit-section {
            margin-top: 20px;
        }

        .limit-header {
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            margin-bottom: 8px;
        }

        .limit-header span {
            color: #94a3b8;
        }

        .progress {
            height: 7px;
            background: #edf0f4;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            width: 38%;
            background: #4f8cff;
            border-radius: 10px;
        }

        /* ACTIVITY */

        .activity-card {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 16px;
            overflow: hidden;
        }

        .activity-header {
            padding: 20px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #edf0f4;
        }

        .activity-header h3 {
            font-size: 14px;
        }

        .activity-header a {
            color: #4f8cff;
            font-size: 9px;
        }

        .activity {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 22px;
            border-bottom: 1px solid #edf0f4;
        }

        .activity:last-child {
            border-bottom: none;
        }

        .activity-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            background: #f1f5f9;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
        }

        .activity-info strong {
            display: block;
            font-size: 10px;
        }

        .activity-info span {
            display: block;
            color: #94a3b8;
            font-size: 8px;
            margin-top: 3px;
        }

        .activity-amount {
            font-size: 10px;
            font-weight: 700;
        }

        .debit {
            color: #ef4444;
        }

        .credit {
            color: #16a67d;
        }

        /* MODAL */

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(7,17,31,0.65);
            z-index: 500;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal {
            width: 100%;
            max-width: 390px;
            background: white;
            border-radius: 17px;
            padding: 30px;
            text-align: center;
        }

        .modal-icon {
            width: 60px;
            height: 60px;
            margin: auto auto 18px;
            border-radius: 50%;
            background: #edf4ff;
            color: #4f8cff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .modal h2 {
            font-size: 19px;
            margin-bottom: 7px;
        }

        .modal p {
            color: #94a3b8;
            font-size: 10px;
            margin-bottom: 22px;
        }

        .modal-buttons {
            display: flex;
            gap: 9px;
        }

        .modal-buttons button {
            flex: 1;
            padding: 11px;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 10px;
        }

        .cancel-btn {
            border: 1px solid #e0e5eb;
            background: white;
            color: #64748b;
        }

        .confirm-btn {
            border: none;
            background: #4f8cff;
            color: white;
        }

        /* RESPONSIVE */

        @media (max-width: 950px) {

            .cards-top {
                grid-template-columns: 1fr;
            }

            .bank-card {
                max-width: 500px;
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

            .cards-main {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .cards-content {
                padding: 25px 18px;
            }

            .topbar {
                padding: 0 18px;
            }
        }

        @media (max-width: 500px) {

            .bank-card {
                height: 210px;
                padding: 20px;
            }

            .card-chip {
                margin-top: 27px;
            }

            .card-number {
                font-size: 16px;
            }

            .card-bottom {
                left: 20px;
                right: 20px;
                bottom: 17px;
            }

            .card-info {
                grid-template-columns: 1fr;
            }
        }

    </style>
</head>


<body>
<?php if ($message): ?>
    <div style="position:fixed;top:15px;right:20px;z-index:1000;padding:12px 18px;border-radius:9px;background:<?= $messageType === "error" ? "#fee2e2" : "#dcfce7" ?>;color:<?= $messageType === "error" ? "#b91c1c" : "#166534" ?>;font-size:12px;box-shadow:0 8px 25px rgba(0,0,0,.08);">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<div class="cards-layout">


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

            <a href="cards.php" class="sidebar-link active">
                <i class="fa-solid fa-credit-card"></i>
                <span>Cards</span>
            </a>


            <div class="menu-title">MANAGEMENT</div>

            <a href="transactions.php" class="sidebar-link">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Transactions</span>
            </a>

            <a href="#" class="sidebar-link">
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

    <main class="cards-main">


        <header class="topbar">

            <h2>My Cards</h2>

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


        <section class="cards-content">


            <div class="page-heading">

                <h1>Your Cards</h1>

                <p>
                    Manage your NexaBank cards and card security.
                </p>

            </div>


            <div class="cards-top">


                <!-- CARD -->

                <div class="bank-card-section">

                    <div class="section-header">

                        <h3>Primary Debit Card</h3>

                        <span class="card-type">
                            <?= e($cardType) ?> DEBIT
                        </span>

                    </div>


                    <div class="bank-card">

                        <div class="card-brand">

                            <strong>NEXABANK</strong>

                            <i class="fa-solid fa-wifi"></i>

                        </div>


                        <div class="card-chip"></div>


                        <div class="card-number">
                            •••• &nbsp;&nbsp;•••• &nbsp;&nbsp;•••• &nbsp;&nbsp;<?= $last4 ?>
                        </div>


                        <div class="card-bottom">

                            <div>

                                <span class="card-label">
                                    CARD HOLDER
                                </span>

                                <strong class="card-holder">
                                    <?= e(strtoupper($fullName)) ?>
                                </strong>

                            </div>


                            <div>

                                <span class="card-label">
                                    VALID THRU
                                </span>

                                <strong class="card-expiry">
                                    <?= e($expiry) ?>
                                </strong>

                            </div>


                            <div class="visa">
                                VISA
                            </div>

                        </div>

                    </div>


                    <div class="card-info">

                        <div class="info-item">

                            <span>Card Number</span>

                            <strong>
                                •••• 9137
                            </strong>

                        </div>


                        <div class="info-item">

                            <span>Card Status</span>

                            <strong style="color:<?= $cardStatus === "Active" ? "#16a67d" : "#ef4444" ?>;">
                                ● <?= e($cardStatus) ?>
                            </strong>

                        </div>

                    </div>


                    <div class="card-actions">

                        <button
                            type="button"
                            class="card-action"
                            onclick="copyCardNumber()"
                        >
                            <i class="fa-regular fa-copy"></i>
                            Copy Details
                        </button>

                        <button
                            type="button"
                            class="card-action danger"
                            onclick="openFreezeModal()"
                        >
                            <i class="fa-solid fa-snowflake"></i>
                            <?= $cardStatus === "Frozen" ? "Unfreeze Card" : "Freeze Card" ?>
                        </button>

                    </div>

                </div>


                <!-- CONTROLS -->

                <div class="controls-card">

                    <div class="section-header">

                        <h3>Card Controls</h3>

                    </div>


                    <div class="control">

                        <div class="control-left">

                            <div class="control-icon">
                                <i class="fa-solid fa-cart-shopping"></i>
                            </div>

                            <div class="control-info">

                                <strong>Online Payments</strong>

                                <span>
                                    Allow online card transactions
                                </span>

                            </div>

                        </div>


                        <form method="POST" style="margin:0;">
                <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="control" value="online">
                            <button type="submit"
                                    class="toggle <?= !empty($card["online_enabled"]) ? "active" : "" ?>"
                                    aria-label="Toggle Online Payments"></button>
                        </form>

                    </div>


                    <div class="control">

                        <div class="control-left">

                            <div class="control-icon">
                                <i class="fa-solid fa-globe"></i>
                            </div>

                            <div class="control-info">

                                <strong>International</strong>

                                <span>
                                    Use card outside India
                                </span>

                            </div>

                        </div>


                        <form method="POST" style="margin:0;">
                <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="control" value="international">
                            <button type="submit"
                                    class="toggle <?= !empty($card["international_enabled"]) ? "active" : "" ?>"
                                    aria-label="Toggle International"></button>
                        </form>

                    </div>


                    <div class="control">

                        <div class="control-left">

                            <div class="control-icon">
                                <i class="fa-solid fa-mobile-screen"></i>
                            </div>

                            <div class="control-info">

                                <strong>Contactless</strong>

                                <span>
                                    Tap to pay
                                </span>

                            </div>

                        </div>


                        <form method="POST" style="margin:0;">
                <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="control" value="contactless">
                            <button type="submit"
                                    class="toggle <?= !empty($card["contactless_enabled"]) ? "active" : "" ?>"
                                    aria-label="Toggle Contactless"></button>
                        </form>

                    </div>


                    <div class="control">

                        <div class="control-left">

                            <div class="control-icon">
                                <i class="fa-solid fa-credit-card"></i>
                            </div>

                            <div class="control-info">

                                <strong>ATM Withdrawals</strong>

                                <span>
                                    Allow cash withdrawals
                                </span>

                            </div>

                        </div>


                        <form method="POST" style="margin:0;">
                <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="control" value="atm">
                            <button type="submit"
                                    class="toggle <?= !empty($card["atm_enabled"]) ? "active" : "" ?>"
                                    aria-label="Toggle ATM Withdrawals"></button>
                        </form>

                    </div>


                    <div class="limit-section">

                        <div class="limit-header">

                            <span>
                                Monthly spending
                            </span>

                            <strong>
                                ₹<?= number_format($spentAmount, 2) ?> / ₹<?= number_format($spendingLimit, 2) ?>
                            </strong>

                        </div>


                        <div class="progress">

                            <div class="progress-bar" style="width:<?= e($progress) ?>%;"></div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- CARD ACTIVITY -->

            <div class="activity-card">

                <div class="activity-header">

                    <h3>
                        Recent Card Activity
                    </h3>

                    <a href="transactions.php">
                        View all
                    </a>

                </div>


                <div class="activity">

                    <div class="activity-left">

                        <div class="activity-icon">

                            <i class="fa-brands fa-amazon"></i>

                        </div>

                        <div class="activity-info">

                            <strong>
                                Amazon
                            </strong>

                            <span>
                                Today • Online payment
                            </span>

                        </div>

                    </div>

                    <span class="activity-amount debit">
                        - ₹1,299
                    </span>

                </div>


                <div class="activity">

                    <div class="activity-left">

                        <div class="activity-icon">

                            <i class="fa-solid fa-utensils"></i>

                        </div>

                        <div class="activity-info">

                            <strong>
                                Swiggy
                            </strong>

                            <span>
                                Yesterday • Contactless
                            </span>

                        </div>

                    </div>

                    <span class="activity-amount debit">
                        - ₹540
                    </span>

                </div>


                <div class="activity">

                    <div class="activity-left">

                        <div class="activity-icon">

                            <i class="fa-solid fa-gas-pump"></i>

                        </div>

                        <div class="activity-info">

                            <strong>
                                IndianOil
                            </strong>

                            <span>
                                18 Sep • Card payment
                            </span>

                        </div>

                    </div>

                    <span class="activity-amount debit">
                        - ₹2,500
                    </span>

                </div>


                <div class="activity">

                    <div class="activity-left">

                        <div class="activity-icon">

                            <i class="fa-solid fa-arrow-down"></i>

                        </div>

                        <div class="activity-info">

                            <strong>
                                Cash Withdrawal
                            </strong>

                            <span>
                                16 Sep • ATM
                            </span>

                        </div>

                    </div>

                    <span class="activity-amount debit">
                        - ₹5,000
                    </span>

                </div>

            </div>

        </section>

    </main>

</div>


<form method="POST" id="freezeForm" style="display:none;">
                <?= csrf_field() ?>
    <input type="hidden" name="action" value="freeze">
</form>

<!-- FREEZE MODAL -->

<div class="modal-overlay" id="freezeModal">

    <div class="modal">

        <div class="modal-icon">

            <i class="fa-solid fa-snowflake"></i>

        </div>

        <h2>Freeze your card?</h2>

        <p>
            Your card will temporarily stop working for
            purchases and ATM withdrawals. You can unfreeze
            it at any time.
        </p>

        <div class="modal-buttons">

            <button
                class="cancel-btn"
                onclick="closeFreezeModal()"
            >
                Cancel
            </button>

            <button
                class="confirm-btn"
                onclick="submitFreeze()"
            >
                Freeze Card
            </button>

        </div>

    </div>

</div>


<script>
    function openFreezeModal() {
        document.getElementById("freezeModal").style.display = "flex";
    }

    function closeFreezeModal() {
        document.getElementById("freezeModal").style.display = "none";
    }

    function submitFreeze() {
        document.getElementById("freezeForm").submit();
    }

    function copyCardNumber() {
        const last4 = <?= json_encode($card["card_last4"]) ?>;
        navigator.clipboard.writeText("**** **** **** " + last4)
            .then(() => alert("Masked card details copied."))
            .catch(() => alert("Copy was blocked by the browser."));
    }

    document.getElementById("freezeModal").addEventListener("click", function(event) {
        if (event.target === this) {
            closeFreezeModal();
        }
    });
</script>
