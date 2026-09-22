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

function redirectWith($message, $error = "") {
    $params = [];
    if ($message !== "") $params["message"] = $message;
    if ($error !== "") $params["error"] = $error;
    header("Location: profile.php" . ($params ? "?" . http_build_query($params) : ""));
    exit;
}

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, email, phone, dob, password_hash,
           transaction_pin_hash, account_type, status, created_at
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT id, account_number, ifsc, account_type, balance, status, created_at
    FROM accounts
    WHERE user_id = ?
    ORDER BY id ASC
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();

$message = $_GET["message"] ?? "";
$error = $_GET["error"] ?? "";

/*
 * Profile actions:
 * update_profile  = update name, phone and DOB
 * change_password = verify old password and save a new password
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_or_fail();
    $action = $_POST["action"] ?? "";

    if ($action === "update_profile") {
        $firstName = trim($_POST["first_name"] ?? "");
        $lastName = trim($_POST["last_name"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $dob = trim($_POST["dob"] ?? "");

        if ($firstName === "" || $lastName === "" || $phone === "" || $dob === "") {
            redirectWith("", "Please complete all required profile fields.");
        }

        if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            redirectWith("", "Please enter a valid phone number.");
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            redirectWith("", "Please enter a valid date of birth.");
        }

        $stmt = $conn->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, phone = ?, dob = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssi", $firstName, $lastName, $phone, $dob, $userId);

        if ($stmt->execute()) {
            $_SESSION["user_name"] = $firstName . " " . $lastName;
            redirectWith("Profile changes saved successfully.");
        }

        redirectWith("", "Could not save profile changes.");
    }

    if ($action === "change_password") {
        $currentPassword = $_POST["current_password"] ?? "";
        $newPassword = $_POST["new_password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";

        if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
            redirectWith("", "Please fill all password fields.");
        }

        if (!password_verify($currentPassword, $user["password_hash"])) {
            redirectWith("", "Current password is incorrect.");
        }

        if (strlen($newPassword) < 8) {
            redirectWith("", "New password must contain at least 8 characters.");
        }

        if ($newPassword !== $confirmPassword) {
            redirectWith("", "New passwords do not match.");
        }

        if (password_verify($newPassword, $user["password_hash"])) {
            redirectWith("", "New password must be different from your current password.");
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            UPDATE users
            SET password_hash = ?
            WHERE id = ?
        ");
        $stmt->bind_param("si", $newHash, $userId);

        if ($stmt->execute()) {
            $logAction = "PASSWORD_CHANGED";
            $description = "User changed login password from profile page.";
            $ip = $_SERVER["REMOTE_ADDR"] ?? "";
            $ua = $_SERVER["HTTP_USER_AGENT"] ?? "";

            $log = $conn->prepare("
                INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $log->bind_param("issss", $userId, $logAction, $description, $ip, $ua);
            $log->execute();

            redirectWith("Password updated successfully.");
        }

        redirectWith("", "Could not update password.");
    }
}

/* Reload after any request/redirect */
$stmt = $conn->prepare("
    SELECT id, first_name, last_name, email, phone, dob,
           transaction_pin_hash, account_type, status, created_at
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$fullName = trim($user["first_name"] . " " . $user["last_name"]);
$avatar = initials($fullName);

$memberSince = date("F Y", strtotime($user["created_at"]));
$accountNumber = $account["account_number"] ?? "";
$maskedAccount = $accountNumber !== ""
    ? "•••••••• " . substr($accountNumber, -4)
    : "Not available";

$accountStatus = $account["status"] ?? $user["status"];
$accountType = $account["account_type"] ?? $user["account_type"];
$kycStatus = "Verified";

/* Existing schema stores the PIN hash, but no PIN status flag. */
$pinSet = !empty($user["transaction_pin_hash"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile — NexaBank</title>
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
            max-width: 1050px;
        }

        .heading {
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

        /* PROFILE HEADER */

        .profile-header {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 15px;
            padding: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .profile-user {
            display: flex;
            align-items: center;
            gap: 17px;
        }

        .profile-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(
                135deg,
                #4f8cff,
                #2864d7
            );
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
            font-weight: 700;
            position: relative;
        }

        .camera-btn {
            position: absolute;
            width: 25px;
            height: 25px;
            right: -2px;
            bottom: -2px;
            border-radius: 50%;
            border: 2px solid white;
            background: #07111f;
            color: white;
            font-size: 9px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-user h2 {
            font-size: 17px;
            margin-bottom: 4px;
        }

        .profile-user p {
            color: #94a3b8;
            font-size: 9px;
        }

        .verified {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 7px;
            color: #16a67d;
            font-size: 8px;
            font-weight: 600;
        }

        .member-since {
            text-align: right;
        }

        .member-since span {
            display: block;
            color: #94a3b8;
            font-size: 8px;
            margin-bottom: 4px;
        }

        .member-since strong {
            font-size: 10px;
        }

        /* GRID */

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .panel {
            background: white;
            border: 1px solid #e7ebf1;
            border-radius: 15px;
            padding: 23px;
        }

        .panel.full {
            grid-column: 1 / -1;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .panel-header h3 {
            font-size: 13px;
        }

        .panel-header span {
            color: #94a3b8;
            font-size: 8px;
        }

        /* FORM */

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-group {
            margin-bottom: 2px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            color: #475569;
            font-size: 9px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 10px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            height: 40px;
            border: 1px solid #e1e6ed;
            border-radius: 8px;
            padding: 0 11px;
            outline: none;
            font-family: inherit;
            font-size: 10px;
            color: #475569;
            background: white;
        }

        .input-wrap input {
            padding-left: 34px;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #4f8cff;
        }

        .form-group input:disabled {
            background: #f8fafc;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .field-note {
            display: block;
            color: #a1adbc;
            font-size: 7px;
            margin-top: 5px;
        }

        /* ACCOUNT INFO */

        .account-list {
            display: flex;
            flex-direction: column;
        }

        .account-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 13px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .account-row:last-child {
            border-bottom: none;
        }

        .account-row span {
            color: #94a3b8;
            font-size: 9px;
        }

        .account-row strong {
            font-size: 10px;
            color: #475569;
        }

        .status {
            color: #16a67d !important;
        }

        /* SECURITY */

        .security-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .security-row:last-child {
            border-bottom: none;
        }

        .security-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .security-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: #edf4ff;
            color: #4f8cff;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .security-info strong {
            display: block;
            font-size: 10px;
        }

        .security-info span {
            display: block;
            color: #94a3b8;
            font-size: 8px;
            margin-top: 3px;
        }

        .security-btn {
            border: 1px solid #dfe5ec;
            background: white;
            color: #4f8cff;
            padding: 8px 11px;
            border-radius: 7px;
            cursor: pointer;
            font-family: inherit;
            font-size: 8px;
        }

        .security-btn:hover {
            background: #edf4ff;
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

        /* SAVE */

        .save-area {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        .save-btn,
        .cancel-btn {
            height: 40px;
            padding: 0 20px;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 10px;
        }

        .save-btn {
            border: none;
            background: #4f8cff;
            color: white;
        }

        .save-btn:hover {
            background: #2864d7;
        }

        .cancel-btn {
            border: 1px solid #dfe5ec;
            background: white;
            color: #64748b;
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
            max-width: 400px;
            background: white;
            border-radius: 16px;
            padding: 28px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 17px;
        }

        .close-btn {
            border: none;
            background: #f1f5f9;
            width: 31px;
            height: 31px;
            border-radius: 50%;
            cursor: pointer;
            color: #64748b;
        }

        .modal-buttons {
            display: flex;
            gap: 9px;
            margin-top: 20px;
        }

        .modal-buttons button {
            flex: 1;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 10px;
        }

        .modal-cancel {
            border: 1px solid #dfe5ec;
            background: white;
            color: #64748b;
        }

        .modal-confirm {
            border: none;
            background: #4f8cff;
            color: white;
        }

        /* RESPONSIVE */

        @media (max-width: 900px) {

            .profile-grid {
                grid-template-columns: 1fr;
            }

            .panel.full {
                grid-column: auto;
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

        @media (max-width: 550px) {

            .profile-header {
                align-items: flex-start;
                flex-direction: column;
                gap: 20px;
            }

            .member-since {
                text-align: left;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: auto;
            }

            .save-area {
                flex-direction: column-reverse;
            }

            .save-btn,
            .cancel-btn {
                width: 100%;
            }

        }

    
        .server-message {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 11px;
        }
        .server-success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .server-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
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

            <a href="analytics.php" class="sidebar-link">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Analytics</span>
            </a>

            <a href="beneficiaries.php" class="sidebar-link">
                <i class="fa-solid fa-user-group"></i>
                <span>Beneficiaries</span>
            </a>


            <div class="menu-title">SETTINGS</div>

            <a href="profile.php" class="sidebar-link active">
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

            <h2>Profile</h2>

            <div class="topbar-right">

                <div class="notification">

                    <i class="fa-regular fa-bell"></i>

                    <span class="notification-dot"></span>

                </div>

                <div class="avatar"><?= e($avatar) ?></div>

            </div>

        </header>


        
<section class="content">
<?php if ($message): ?>
    <div class="server-message server-success">
        <i class="fa-solid fa-circle-check"></i> <?= e($message) ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="server-message server-error">
        <i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?>
    </div>
<?php endif; ?>



            <div class="heading">

                <h1>Profile & Settings</h1>

                <p>
                    Manage your personal information, account and security preferences.
                </p>

            </div>


            <!-- PROFILE HEADER -->

            <div class="profile-header">

                <div class="profile-user">

                    <div class="profile-avatar">

                        <?= e($avatar) ?>

                        <button
                            class="camera-btn"
                            onclick="changePhoto()"
                        >

                            <i class="fa-solid fa-camera"></i>

                        </button>

                    </div>


                    <div>

                        <h2><?= e($fullName) ?></h2>

                        <p><?= e($user["email"]) ?></p>

                        <div class="verified">

                            <i class="fa-solid fa-circle-check"></i>

                            Verified Account

                        </div>

                    </div>

                </div>


                <div class="member-since">

                    <span>Member since</span>

                    <strong><?= e($memberSince) ?></strong>

                </div>

            </div>


            <!-- PROFILE GRID -->

            <div class="profile-grid">


                <!-- PERSONAL INFORMATION -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>Personal Information</h3>

                        <span>
                            Basic details
                        </span>

                    </div>


                    <form id="profileForm" method="post" action="profile.php">
                <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form-grid">


                            <div class="form-group">

                                <label>First Name</label>

                                <div class="input-wrap">

                                    <i class="fa-solid fa-user"></i>

                                    <input
                                        type="text"
                                        name="first_name" id="firstName"
                                        value="<?= e($user["first_name"]) ?>"
                                        required
                                    >

                                </div>

                            </div>


                            <div class="form-group">

                                <label>Last Name</label>

                                <div class="input-wrap">

                                    <i class="fa-solid fa-user"></i>

                                    <input
                                        type="text"
                                        name="last_name" id="lastName"
                                        value="<?= e($user["last_name"]) ?>"
                                        required
                                    >

                                </div>

                            </div>


                            <div class="form-group">

                                <label>Email Address</label>

                                <div class="input-wrap">

                                    <i class="fa-solid fa-envelope"></i>

                                    <input
                                        type="email"
                                        value="<?= e($user["email"]) ?>"
                                        disabled
                                    >

                                </div>

                                <span class="field-note">
                                    Contact support to change your registered email.
                                </span>

                            </div>


                            <div class="form-group">

                                <label>Phone Number</label>

                                <div class="input-wrap">

                                    <i class="fa-solid fa-phone"></i>

                                    <input
                                        type="tel"
                                        name="phone" id="phone"
                                        value="<?= e($user["phone"]) ?>"
                                    >

                                </div>

                            </div>


                            <div class="form-group">

                                <label>Date of Birth</label>

                                <div class="input-wrap">

                                    <i class="fa-solid fa-calendar"></i>

                                    <input
                                        type="date"
                                        name="dob" id="dob"
                                        value="<?= e($user["dob"]) ?>"
                                    >

                                </div>

                            </div>


                            <div class="form-group">

                                <label>Account Type</label>

                                <select disabled><option><?= e($accountType) ?> Account</option></select>

                            </div>


                        </div>

                    </form>

                </div>


                <!-- ACCOUNT INFORMATION -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>Account Information</h3>

                        <span>
                            Account details
                        </span>

                    </div>


                    <div class="account-list">


                        <div class="account-row">

                            <span>
                                Customer ID
                            </span>

                            <strong>
                                <?= (int)$user["id"] ?>
                            </strong>

                        </div>


                        <div class="account-row">

                            <span>
                                Account Number
                            </span>

                            <strong>
                                <?= e($maskedAccount) ?>
                            </strong>

                        </div>


                        <div class="account-row">

                            <span>
                                IFSC Code
                            </span>

                            <strong>
                                <?= e($account["ifsc"] ?? "N/A") ?>
                            </strong>

                        </div>


                        <div class="account-row">

                            <span>
                                Account Type
                            </span>

                            <strong>
                                <?= e($accountType) ?>
                            </strong>

                        </div>


                        <div class="account-row">

                            <span>
                                Account Status
                            </span>

                            <strong class="status">
                                ● <?= e($accountStatus) ?>
                            </strong>

                        </div>


                        <div class="account-row">

                            <span>
                                KYC Status
                            </span>

                            <strong class="status">
                                ● Verified
                            </strong>

                        </div>


                    </div>

                </div>


                <!-- SECURITY -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>Security</h3>

                        <span>
                            Protect your account
                        </span>

                    </div>


                    <div class="security-row">

                        <div class="security-left">

                            <div class="security-icon">

                                <i class="fa-solid fa-lock"></i>

                            </div>


                            <div class="security-info">

                                <strong>
                                    Password
                                </strong>

                                <span>
                                    Your password is securely stored
                                </span>

                            </div>

                        </div>


                        <button
                            class="security-btn"
                            onclick="openPasswordModal()"
                        >
                            Change
                        </button>

                    </div>


                    <div class="security-row">

                        <div class="security-left">

                            <div class="security-icon">

                                <i class="fa-solid fa-key"></i>

                            </div>


                            <div class="security-info">

                                <strong>
                                    Transaction PIN
                                </strong>

                                <span>
                                    <?= $pinSet ? "PIN is configured" : "PIN is not configured" ?>
                                </span>

                            </div>

                        </div>


                        <button
                            class="security-btn"
                            onclick="changePin()"
                        >
                            Change
                        </button>

                    </div>


                    <div class="security-row">

                        <div class="security-left">

                            <div class="security-icon">

                                <i class="fa-solid fa-shield-halved"></i>

                            </div>


                            <div class="security-info">

                                <strong>
                                    Two-Factor Authentication
                                </strong>

                                <span>
                                    Add another layer of security
                                </span>

                            </div>

                        </div>


                        <div
                            class="toggle active"
                            onclick="toggleSecurity(this)"
                        ></div>

                    </div>


                    <div class="security-row">

                        <div class="security-left">

                            <div class="security-icon">

                                <i class="fa-solid fa-fingerprint"></i>

                            </div>


                            <div class="security-info">

                                <strong>
                                    Biometric Login
                                </strong>

                                <span>
                                    Use device biometrics
                                </span>

                            </div>

                        </div>


                        <div
                            class="toggle"
                            onclick="toggleSecurity(this)"
                        ></div>

                    </div>

                </div>


                <!-- PREFERENCES -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>Preferences</h3>

                        <span>
                            Notifications
                        </span>

                    </div>


                    <div class="security-row">

                        <div class="security-left">

                            <div class="security-icon">

                                <i class="fa-solid fa-bell"></i>

                            </div>


                            <div class="security-info">

                                <strong>
                                    Transaction Alerts
                                </strong>

                                <span>
                                    Receive alerts for transactions
                                </span>

                            </div>

                        </div>


                        <div
                            class="toggle active"
                            onclick="toggleSecurity(this)"
                        ></div>

                    </div>


                    <div class="security-row">

                        <div class="security-left">

                            <div class="security-icon">

                                <i class="fa-solid fa-envelope"></i>

                            </div>


                            <div class="security-info">

                                <strong>
                                    Email Notifications
                                </strong>

                                <span>
                                    Receive account updates by email
                                </span>

                            </div>

                        </div>


                        <div
                            class="toggle active"
                            onclick="toggleSecurity(this)"
                        ></div>

                    </div>


                    <div class="security-row">

                        <div class="security-left">

                            <div class="security-icon">

                                <i class="fa-solid fa-mobile-screen"></i>

                            </div>


                            <div class="security-info">

                                <strong>
                                    SMS Alerts
                                </strong>

                                <span>
                                    Receive important security alerts
                                </span>

                            </div>

                        </div>


                        <div
                            class="toggle active"
                            onclick="toggleSecurity(this)"
                        ></div>

                    </div>


                    <div class="security-row">

                        <div class="security-left">

                            <div class="security-icon">

                                <i class="fa-solid fa-chart-line"></i>

                            </div>


                            <div class="security-info">

                                <strong>
                                    Monthly Reports
                                </strong>

                                <span>
                                    Receive monthly spending summaries
                                </span>

                            </div>

                        </div>


                        <div
                            class="toggle"
                            onclick="toggleSecurity(this)"
                        ></div>

                    </div>

                </div>


                <!-- SAVE -->

                <div class="panel full">

                    <div class="save-area">

                        <button
                            type="reset"
                            class="cancel-btn"
                        >
                            Reset
                        </button>


                        <button
                            type="submit"
                            class="save-btn"
                        >

                            <i class="fa-solid fa-check"></i>
                            Save Changes

                        </button>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>


<!-- PASSWORD MODAL -->

<div
    class="modal-overlay"
    id="passwordModal"
>

    <div class="modal">
            <form method="post" action="profile.php">
                <?= csrf_field() ?>

        <div class="modal-header">

            <h2>Change Password</h2>

            <button
                class="close-btn"
                onclick="closePasswordModal()"
            >

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <div class="form-group">

            <label>Current Password</label>

            <input
                type="password"
                name="current_password"
                id="currentPassword"
                placeholder="Enter current password"
            >

        </div>


        <div class="form-group">

            <label>New Password</label>

            <input
                type="password"
                name="new_password"
                id="newPassword"
                placeholder="Enter new password"
            >

        </div>


        <div class="form-group">

            <label>Confirm New Password</label>

            <input
                type="password"
                name="confirm_password"
                id="confirmPassword"
                placeholder="Confirm new password"
            >

        </div>


        <div class="modal-buttons">

            <button
                class="modal-cancel"
                onclick="closePasswordModal()"
            >
                Cancel
            </button>

            <button
                type="submit"
                class="modal-confirm"
            >
                Update Password
            </button>

        </div>

    </div>

</div>



<script>
function toggleSecurity(element) {
    element.classList.toggle("active");
}

function openPasswordModal() {
    document.getElementById("passwordModal").style.display = "flex";
}

function closePasswordModal() {
    document.getElementById("passwordModal").style.display = "none";
}

document.getElementById("passwordModal").addEventListener("click", function(event) {
    if (event.target === this) closePasswordModal();
});
</script>
