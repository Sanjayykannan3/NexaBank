<?php
session_start();
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/config/csrf.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION["user_id"];
$success = false;
$error = "";
$successAmount = 0;
$successReference = "";

function money($amount) {
    return "₹" . number_format((float)$amount, 2);
}

$stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ? AND status = 'Active' LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT id, account_number, ifsc, account_type, balance
    FROM accounts
    WHERE user_id = ? AND status = 'Active'
    ORDER BY id ASC
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();

if (!$account) {
    die("No active bank account found for this user.");
}

$beneficiaries = [];
$stmt = $conn->prepare("
    SELECT id, name, bank_name, account_number, ifsc, account_type
    FROM beneficiaries
    WHERE user_id = ?
    ORDER BY id DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $beneficiaries[] = $row;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_or_fail();

    $recipientName = trim($_POST["recipientName"] ?? "");
    $recipientAccount = trim($_POST["accountNumber"] ?? "");
    $recipientIfsc = strtoupper(trim($_POST["ifsc"] ?? ""));
    $amount = (float)($_POST["amount"] ?? 0);
    $note = trim($_POST["note"] ?? "");
    $transactionPin = trim($_POST["transaction_pin"] ?? "");

    if ($recipientName === "" || $recipientAccount === "" || $recipientIfsc === "") {
        $error = "Please enter recipient name, account number and IFSC.";
    } elseif ($amount <= 0) {
        $error = "Please enter a valid transfer amount.";
    } elseif ($amount > 100000) {
        $error = "Daily transfer limit is ₹1,00,000.";
    } elseif ($recipientAccount === $account["account_number"]) {
        $error = "You cannot transfer money to your own account.";
    } elseif (!preg_match('/^\d{4}$/', $transactionPin)) {
        $error = "Please enter your 4-digit Transaction PIN.";
    } else {
        $pinStmt = $conn->prepare("
            SELECT transaction_pin_hash
            FROM users
            WHERE id = ? AND status = 'Active'
            LIMIT 1
        ");
        $pinStmt->bind_param("i", $userId);
        $pinStmt->execute();
        $pinRow = $pinStmt->get_result()->fetch_assoc();
        $pinStmt->close();

        if (!$pinRow || empty($pinRow["transaction_pin_hash"])) {
            $error = "Transaction PIN is not configured for this account.";
        } elseif (!password_verify($transactionPin, $pinRow["transaction_pin_hash"])) {
            $error = "Incorrect Transaction PIN. Transfer was not processed.";
        } else {
            try {
                $conn->begin_transaction();

            $stmt = $conn->prepare("
                SELECT id, account_number, ifsc, account_type, balance
                FROM accounts
                WHERE id = ? AND user_id = ? AND status = 'Active'
                FOR UPDATE
            ");
            $stmt->bind_param("ii", $account["id"], $userId);
            $stmt->execute();
            $sender = $stmt->get_result()->fetch_assoc();

            if (!$sender) {
                throw new Exception("Sender account is unavailable.");
            }

            if ((float)$sender["balance"] < $amount) {
                throw new Exception("Insufficient balance. Available balance is " . money($sender["balance"]) . ".");
            }

            $stmt = $conn->prepare("
                SELECT id, user_id, account_number, ifsc, account_type, balance
                FROM accounts
                WHERE account_number = ? AND status = 'Active'
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param("s", $recipientAccount);
            $stmt->execute();
            $recipient = $stmt->get_result()->fetch_assoc();

            if (!$recipient) {
                throw new Exception("Recipient account was not found in NexaBank.");
            }

            if (strtoupper($recipient["ifsc"]) !== $recipientIfsc) {
                throw new Exception("The IFSC code does not match the recipient account.");
            }

            if ((int)$recipient["user_id"] === $userId) {
                throw new Exception("You cannot transfer money to your own account.");
            }

            $newSenderBalance = (float)$sender["balance"] - $amount;
            $newRecipientBalance = (float)$recipient["balance"] + $amount;

            $stmt = $conn->prepare("UPDATE accounts SET balance = ? WHERE id = ?");
            $stmt->bind_param("di", $newSenderBalance, $sender["id"]);
            $stmt->execute();

            $stmt = $conn->prepare("UPDATE accounts SET balance = ? WHERE id = ?");
            $stmt->bind_param("di", $newRecipientBalance, $recipient["id"]);
            $stmt->execute();

            // Generate a unique reference for the sender transaction.
            do {
                $reference = "NXB" . date("YmdHis") . strtoupper(bin2hex(random_bytes(4)));

                $check = $conn->prepare(
                    "SELECT id FROM transactions WHERE reference = ? LIMIT 1"
                );
                $check->bind_param("s", $reference);
                $check->execute();

                $referenceExists = $check->get_result()->num_rows > 0;
            } while ($referenceExists);

            // The reference column is UNIQUE, so the receiver transaction
            // needs its own unique reference.
            do {
                $receiverReference = "NXB" . date("YmdHis") . strtoupper(bin2hex(random_bytes(4)));

                $check = $conn->prepare(
                    "SELECT id FROM transactions WHERE reference = ? LIMIT 1"
                );
                $check->bind_param("s", $receiverReference);
                $check->execute();

                $receiverReferenceExists = $check->get_result()->num_rows > 0;
            } while ($receiverReferenceExists);

            $description = $note !== "" ? $note : "Money transfer to " . $recipientName;
            $senderType = "debit";
            $status = "success";

            $stmt = $conn->prepare("
                INSERT INTO transactions
                (user_id, account_id, type, amount, counterparty, category, description, status, reference)
                VALUES (?, ?, ?, ?, ?, 'Transfer', ?, ?, ?)
            ");
            $stmt->bind_param(
                "iisdssss",
                $userId,
                $sender["id"],
                $senderType,
                $amount,
                $recipientName,
                $description,
                $status,
                $reference
            );
            $stmt->execute();

            $receiverUserId = (int)$recipient["user_id"];
            $senderFullName = $user["first_name"] . " " . $user["last_name"];
            $receiverDescription = "Money received from " . $senderFullName;
            $receiverType = "credit";

            $stmt = $conn->prepare("
                INSERT INTO transactions
                (user_id, account_id, type, amount, counterparty, category, description, status, reference)
                VALUES (?, ?, ?, ?, ?, 'Transfer', ?, 'success', ?)
            ");
            $stmt->bind_param(
                "iisdsss",
                $receiverUserId,
                $recipient["id"],
                $receiverType,
                $amount,
                $senderFullName,
                $receiverDescription,
                $receiverReference
            );
            $stmt->execute();

            $notificationType = "transfer";
            $title = "Transfer Successful";
            $notificationMessage = "You transferred " . money($amount) . " to " . $recipientName . ". Reference: " . $reference;

            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $userId, $notificationType, $title, $notificationMessage);
            $stmt->execute();

            $receiverTitle = "Money Received";
            $receiverNotification = "You received " . money($amount) . " from " . $senderFullName . ". Reference: " . $receiverReference;

            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $receiverUserId, $notificationType, $receiverTitle, $receiverNotification);
            $stmt->execute();

            $action = "MONEY_TRANSFER";
            $auditDescription = "Transferred " . money($amount) . " to account " . $recipientAccount . " with reference " . $reference;
            $ip = $_SERVER["REMOTE_ADDR"] ?? "";
            $userAgent = $_SERVER["HTTP_USER_AGENT"] ?? "";

            $stmt = $conn->prepare("
                INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issss", $userId, $action, $auditDescription, $ip, $userAgent);
            $stmt->execute();

            $conn->commit();

            $success = true;
            $successAmount = $amount;
            $successReference = $reference;
            $account["balance"] = $newSenderBalance;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
        }
    }
}

$initials = strtoupper(substr($user["first_name"], 0, 1) . substr($user["last_name"], 0, 1));
$last4 = substr($account["account_number"], -4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Transfer Money — NexaBank</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="css/style.css">

    <style>

        body {
            background: #f4f7fb;
            color: #172033;
        }

        .transfer-layout {
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

        .transfer-main {
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

        /* PAGE */

        .transfer-content {
            max-width: 1100px;
            margin: auto;
            padding: 35px;
        }

        .page-heading {
            margin-bottom: 28px;
        }

        .page-heading h1 {
            font-size: 25px;
            margin-bottom: 5px;
        }

        .page-heading p {
            color: #7b8798;
            font-size: 12px;
        }

        .transfer-grid {
            display: grid;
            grid-template-columns: 1.35fr 0.75fr;
            gap: 22px;
            align-items: start;
        }

        .transfer-card,
        .summary-card {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 16px;
            padding: 27px;
        }

        /* STEPS */

        .steps {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #94a3b8;
            font-size: 10px;
        }

        .step-number {
            width: 25px;
            height: 25px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2f7;
            font-size: 10px;
            font-weight: 700;
        }

        .step.active {
            color: #2864d7;
        }

        .step.active .step-number {
            background: #2864d7;
            color: white;
        }

        .step-line {
            height: 1px;
            background: #e7ebf1;
            width: 60px;
            margin: 0 10px;
        }

        /* FORM */

        .form-section {
            margin-bottom: 28px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 17px;
        }

        .section-title-icon {
            width: 30px;
            height: 30px;
            background: #edf4ff;
            color: #4f8cff;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .section-title h3 {
            font-size: 13px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 7px;
            color: #475569;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper > i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 12px;
        }

        .input-wrapper input,
        .input-wrapper select {
            width: 100%;
            padding: 12px 37px;
            border: 1px solid #e0e5eb;
            background: #fafbfc;
            border-radius: 8px;
            outline: none;
            font-family: inherit;
            color: #172033;
            font-size: 11px;
            transition: 0.2s;
        }

        .input-wrapper input:focus,
        .input-wrapper select:focus {
            border-color: #4f8cff;
            background: white;
            box-shadow: 0 0 0 3px rgba(79,140,255,0.08);
        }

        .input-wrapper select {
            appearance: none;
            cursor: pointer;
        }

        .select-arrow {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
            font-size: 9px;
        }

        /* BALANCE */

        .balance-info {
            margin-top: 7px;
            display: flex;
            justify-content: space-between;
            color: #94a3b8;
            font-size: 9px;
        }

        .balance-info strong {
            color: #16a67d;
        }

        /* BENEFICIARIES */

        .beneficiary-row {
            display: flex;
            gap: 8px;
            margin-bottom: 14px;
            overflow-x: auto;
        }

        .beneficiary {
            min-width: 90px;
            border: 1px solid #e7ebf1;
            border-radius: 10px;
            padding: 11px 8px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
        }

        .beneficiary:hover,
        .beneficiary.selected {
            border-color: #4f8cff;
            background: #f4f8ff;
        }

        .beneficiary-avatar {
            width: 30px;
            height: 30px;
            margin: auto auto 6px;
            border-radius: 50%;
            background: #e8effc;
            color: #2864d7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            font-weight: 700;
        }

        .beneficiary strong {
            display: block;
            font-size: 9px;
        }

        .beneficiary span {
            color: #94a3b8;
            font-size: 8px;
        }

        /* AMOUNT */

        .amount-box {
            text-align: center;
            padding: 22px 10px;
            border: 1px solid #e0e5eb;
            border-radius: 10px;
            background: #fafbfc;
        }

        .amount-box span {
            color: #94a3b8;
            font-size: 10px;
        }

        .amount-input {
            width: 100%;
            border: none;
            background: transparent;
            text-align: center;
            outline: none;
            font-size: 34px;
            font-weight: 700;
            color: #172033;
            margin-top: 5px;
        }

        .amount-input::placeholder {
            color: #c4cbd4;
        }

        /* BUTTON */

        #transactionPin {
            letter-spacing: 5px;
            font-weight: 700;
            text-align: center;
        }

        .continue-btn {
            width: 100%;
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 12px;
            padding: 14px;
        }

        /* SUMMARY */

        .summary-card {
            position: sticky;
            top: 25px;
        }

        .summary-card h3 {
            font-size: 14px;
            margin-bottom: 22px;
        }

        .summary-account {
            background: #07111f;
            color: white;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .summary-account small {
            color: #8fa1b7;
            font-size: 8px;
        }

        .summary-account strong {
            display: block;
            font-size: 17px;
            margin: 4px 0;
        }

        .summary-account span {
            color: #8fa1b7;
            font-size: 9px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #edf0f4;
            font-size: 10px;
        }

        .summary-row span:first-child {
            color: #94a3b8;
        }

        .summary-row strong {
            font-weight: 600;
        }

        .summary-total {
            display: flex;
            justify-content: space-between;
            margin-top: 18px;
            padding-top: 15px;
            border-top: 1px solid #e7ebf1;
        }

        .summary-total span {
            font-size: 11px;
            color: #64748b;
        }

        .summary-total strong {
            font-size: 18px;
        }

        .secure-note {
            margin-top: 20px;
            padding: 12px;
            background: #f0fbf7;
            border: 1px solid #d6f2e7;
            border-radius: 9px;
            display: flex;
            gap: 9px;
            font-size: 9px;
            color: #64748b;
        }

        .secure-note i {
            color: #16a67d;
        }

        /* SUCCESS */

        .success-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(7,17,31,0.72);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .success-modal {
            width: 100%;
            max-width: 400px;
            background: white;
            border-radius: 18px;
            padding: 35px;
            text-align: center;
            animation: pop 0.25s ease;
        }

        @keyframes pop {
            from {
                transform: scale(0.9);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .success-icon {
            width: 70px;
            height: 70px;
            margin: auto auto 20px;
            background: #e7f9f2;
            color: #16a67d;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }

        .success-modal h2 {
            font-size: 22px;
            margin-bottom: 7px;
        }

        .success-modal p {
            color: #94a3b8;
            font-size: 11px;
        }

        .success-amount {
            font-size: 27px;
            font-weight: 700;
            margin: 18px 0;
        }

        .transaction-id {
            background: #f7f9fb;
            border-radius: 8px;
            padding: 11px;
            color: #64748b;
            font-size: 9px;
            margin-bottom: 20px;
        }

        .close-success {
            width: 100%;
            border: none;
            cursor: pointer;
        }

        /* RESPONSIVE */

        @media (max-width: 900px) {

            .transfer-grid {
                grid-template-columns: 1fr;
            }

            .summary-card {
                position: static;
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

            .transfer-main {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .transfer-content {
                padding: 25px 18px;
            }

            .topbar {
                padding: 0 18px;
            }
        }

        @media (max-width: 550px) {

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

            .transfer-card,
            .summary-card {
                padding: 20px;
            }

            .step-line {
                width: 25px;
            }

            .step span:not(.step-number) {
                display: none;
            }
        }

    </style>
</head>
<body>

<div class="transfer-layout">

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

            <a href="transfer.php" class="sidebar-link active">
                <i class="fa-solid fa-arrow-right-arrow-left"></i>
                <span>Transfer</span>
            </a>

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-credit-card"></i>
                <span>Cards</span>
            </a>

            <div class="menu-title">MANAGEMENT</div>

            <a href="transactions.php" class="sidebar-link">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Transactions</span>
            </a>

            <a href="analytics.php" class="sidebar-link">
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

    <main class="transfer-main">

        <header class="topbar">
            <h2>Transfer Money</h2>

            <div class="topbar-right">
                <div class="notification">
                    <i class="fa-regular fa-bell"></i>
                </div>

                <div class="avatar"><?= htmlspecialchars($initials) ?></div>
            </div>
        </header>

        <section class="transfer-content">

            <div class="page-heading">
                <h1>Send Money</h1>
                <p>Transfer funds securely to another NexaBank account.</p>
            </div>

            <?php if ($error): ?>
                <div style="background:#fff1f1;border:1px solid #ffd0d0;color:#c62828;padding:12px 15px;border-radius:9px;margin-bottom:18px;font-size:12px;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="transfer.php">
                <?= csrf_field() ?>
            <div class="transfer-grid">

                <div class="transfer-card">

                    <div class="steps">
                        <div class="step active">
                            <span class="step-number">1</span>
                            <span>Recipient</span>
                        </div>

                        <div class="step-line"></div>

                        <div class="step">
                            <span class="step-number">2</span>
                            <span>Amount</span>
                        </div>

                        <div class="step-line"></div>

                        <div class="step">
                            <span class="step-number">3</span>
                            <span>Confirm</span>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title">
                            <div class="section-title-icon">
                                <i class="fa-solid fa-wallet"></i>
                            </div>
                            <h3>From Account</h3>
                        </div>

                        <div class="input-wrapper">
                            <i class="fa-solid fa-building-columns"></i>

                            <select disabled>
                                <option>
                                    <?= htmlspecialchars($account["account_type"]) ?> Account — •••• <?= htmlspecialchars($last4) ?>
                                </option>
                            </select>

                            <i class="fa-solid fa-chevron-down select-arrow"></i>
                        </div>

                        <div class="balance-info">
                            <span>Available balance</span>
                            <strong><?= money($account["balance"]) ?></strong>
                        </div>
                    </div>

                    <div class="form-section">

                        <div class="section-title">
                            <div class="section-title-icon">
                                <i class="fa-solid fa-user-group"></i>
                            </div>
                            <h3>Select Beneficiary</h3>
                        </div>

                        <?php if ($beneficiaries): ?>
                            <div class="beneficiary-row">
                                <?php foreach ($beneficiaries as $b): ?>
                                    <?php $initial = strtoupper(substr($b["name"], 0, 2)); ?>
                                    <div class="beneficiary"
                                         onclick='selectBeneficiary(this, <?= json_encode($b["name"]) ?>, <?= json_encode($b["account_number"]) ?>, <?= json_encode($b["ifsc"]) ?>)'>
                                        <div class="beneficiary-avatar"><?= htmlspecialchars($initial) ?></div>
                                        <strong><?= htmlspecialchars($b["name"]) ?></strong>
                                        <span>•••• <?= htmlspecialchars(substr($b["account_number"], -4)) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:10px;color:#94a3b8;margin-bottom:14px;">
                                No saved beneficiaries yet. Enter recipient details below.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-section">
                        <div class="section-title">
                            <div class="section-title-icon">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <h3>Recipient Details</h3>
                        </div>

                        <div class="form-grid">

                            <div class="form-group">
                                <label>Recipient Name</label>
                                <div class="input-wrapper">
                                    <i class="fa-solid fa-user"></i>
                                    <input type="text" id="recipientName" name="recipientName"
                                           value="<?= htmlspecialchars($_POST["recipientName"] ?? "") ?>"
                                           placeholder="Enter recipient name" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Account Number</label>
                                <div class="input-wrapper">
                                    <i class="fa-solid fa-hashtag"></i>
                                    <input type="text" id="accountNumber" name="accountNumber"
                                           value="<?= htmlspecialchars($_POST["accountNumber"] ?? "") ?>"
                                           placeholder="Enter account number" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>IFSC Code</label>
                                <div class="input-wrapper">
                                    <i class="fa-solid fa-building"></i>
                                    <input type="text" id="ifsc" name="ifsc"
                                           value="<?= htmlspecialchars($_POST["ifsc"] ?? "NEXA0000123") ?>"
                                           placeholder="NEXA0000123" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Transfer Type</label>
                                <div class="input-wrapper">
                                    <i class="fa-solid fa-bolt"></i>
                                    <select disabled>
                                        <option>Instant Transfer</option>
                                    </select>
                                    <i class="fa-solid fa-chevron-down select-arrow"></i>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title">
                            <div class="section-title-icon">
                                <i class="fa-solid fa-indian-rupee-sign"></i>
                            </div>
                            <h3>Transfer Amount</h3>
                        </div>

                        <div class="amount-box">
                            <span>Enter amount</span>
                            <input class="amount-input"
                                   id="amount"
                                   name="amount"
                                   type="number"
                                   min="1"
                                   max="100000"
                                   step="0.01"
                                   value="<?= htmlspecialchars($_POST["amount"] ?? "") ?>"
                                   placeholder="₹ 0"
                                   required>
                        </div>

                        <div class="balance-info">
                            <span>Daily transfer limit: ₹1,00,000</span>
                            <strong>Available: <?= money($account["balance"]) ?></strong>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-group">
                            <label>
                                Transfer Note
                                <span style="font-weight:400;color:#94a3b8;">(Optional)</span>
                            </label>

                            <div class="input-wrapper">
                                <i class="fa-solid fa-note-sticky"></i>
                                <input type="text" id="note" name="note"
                                       value="<?= htmlspecialchars($_POST["note"] ?? "") ?>"
                                       placeholder="What's this payment for?">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title">
                            <div class="section-title-icon">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <h3>Transaction PIN</h3>
                        </div>

                        <div class="input-wrapper">
                            <i class="fa-solid fa-key"></i>
                            <input
                                type="password"
                                id="transactionPin"
                                name="transaction_pin"
                                inputmode="numeric"
                                pattern="[0-9]{4}"
                                minlength="4"
                                maxlength="4"
                                autocomplete="off"
                                placeholder="Enter 4-digit PIN"
                                required
                            >
                        </div>

                        <div style="margin-top:7px;color:#94a3b8;font-size:9px;">
                            Enter your 4-digit Transaction PIN to authorize this transfer.
                        </div>
                    </div>

                    <button type="submit" class="primary-btn continue-btn"> 
                        Send Money
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>

                </div>

                <aside class="summary-card">
                    <h3>Transfer Summary</h3>

                    <div class="summary-account">
                        <small>PAYING FROM</small>

                        <strong><?= money($account["balance"]) ?></strong>

                        <span>
                            <?= htmlspecialchars($account["account_type"]) ?> Account •••• <?= htmlspecialchars($last4) ?>
                        </span>
                    </div>

                    <div class="summary-row">
                        <span>Recipient</span>
                        <strong id="summaryRecipient">
                            <?= htmlspecialchars($_POST["recipientName"] ?? "Select recipient") ?>
                        </strong>
                    </div>

                    <div class="summary-row">
                        <span>Transfer fee</span>
                        <strong>₹0.00</strong>
                    </div>

                    <div class="summary-row">
                        <span>Transfer type</span>
                        <strong>Instant</strong>
                    </div>

                    <div class="summary-total">
                        <span>Total</span>
                        <strong id="summaryTotal">₹0.00</strong>
                    </div>

                    <div class="secure-note">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>
                            Transfers are protected by NexaBank transaction security.
                        </span>
                    </div>
                </aside>

            </div>
            </form>

        </section>
    </main>
</div>

<div class="success-overlay" id="successOverlay" style="<?= $success ? 'display:flex;' : '' ?>">
    <div class="success-modal">

        <div class="success-icon">
            <i class="fa-solid fa-check"></i>
        </div>

        <h2>Transfer Successful</h2>

        <p>Your transfer has been processed successfully.</p>

        <div class="success-amount" id="successAmount">
            <?= money($successAmount) ?>
        </div>

        <div class="transaction-id">
            Transaction ID:
            <strong><?= htmlspecialchars($successReference) ?></strong>
        </div>

        <button class="primary-btn close-success" type="button" onclick="closeSuccess()">
            Back to Dashboard
        </button>

    </div>
</div>

<script>
function selectBeneficiary(element, name, accountNumber, ifsc) {
    document.querySelectorAll(".beneficiary").forEach(item => {
        item.classList.remove("selected");
    });

    element.classList.add("selected");

    document.getElementById("recipientName").value = name;
    document.getElementById("accountNumber").value = accountNumber;
    document.getElementById("ifsc").value = ifsc;
    document.getElementById("summaryRecipient").textContent = name;
}

const amountInput = document.getElementById("amount");
const summaryTotal = document.getElementById("summaryTotal");
const recipientName = document.getElementById("recipientName");

function updateSummary() {
    const amount = Number(amountInput.value) || 0;

    summaryTotal.textContent = "₹" + amount.toLocaleString("en-IN", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    document.getElementById("summaryRecipient").textContent =
        recipientName.value.trim() || "Select recipient";
}

amountInput.addEventListener("input", updateSummary);
recipientName.addEventListener("input", updateSummary);
updateSummary();

function closeSuccess() {
    window.location.href = "dashboard.php";
}
</script>
