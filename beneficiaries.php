<?php
session_start();
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/config/csrf.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT id, first_name, last_name, email
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

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $out = "";
    foreach ($parts as $part) {
        if ($part !== "") {
            $out .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($out) >= 2) break;
    }
    return $out ?: "U";
}

$fullName = trim($user["first_name"] . " " . $user["last_name"]);
$avatar = initials($fullName);

$banks = [
    "hdfc" => "HDFC Bank",
    "sbi" => "State Bank of India",
    "icici" => "ICICI Bank",
    "axis" => "Axis Bank"
];

$message = "";
$error = "";

/* ADD / EDIT / DELETE */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_or_fail();
    $action = $_POST["action"] ?? "";

    if ($action === "add" || $action === "edit") {
        $name = trim($_POST["name"] ?? "");
        $bankKey = strtolower(trim($_POST["bank"] ?? ""));
        $accountNumber = trim($_POST["account_number"] ?? "");
        $ifsc = strtoupper(trim($_POST["ifsc"] ?? ""));
        $accountType = ($_POST["account_type"] ?? "Savings") === "Current" ? "Current" : "Savings";

        if ($name === "" || !isset($banks[$bankKey]) || $accountNumber === "" || $ifsc === "") {
            $error = "Please fill all beneficiary fields.";
        } elseif (!preg_match('/^[0-9]{6,18}$/', $accountNumber)) {
            $error = "Account number must contain 6 to 18 digits.";
        } elseif (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            $error = "Enter a valid IFSC code.";
        } else {
            $bankName = $banks[$bankKey];

            if ($action === "add") {
                $stmt = $conn->prepare("
                    INSERT INTO beneficiaries
                    (user_id, name, bank_name, account_number, ifsc, account_type)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "isssss",
                    $userId, $name, $bankName, $accountNumber, $ifsc, $accountType
                );

                if ($stmt->execute()) {
                    $message = $name . " has been added as a beneficiary.";
                } else {
                    $error = "Could not add beneficiary.";
                }
            } else {
                $id = (int)($_POST["id"] ?? 0);

                $stmt = $conn->prepare("
                    UPDATE beneficiaries
                    SET name = ?, bank_name = ?, account_number = ?, ifsc = ?, account_type = ?
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->bind_param(
                    "sssssii",
                    $name, $bankName, $accountNumber, $ifsc, $accountType, $id, $userId
                );

                if ($stmt->execute()) {
                    $message = "Beneficiary updated successfully.";
                } else {
                    $error = "Could not update beneficiary.";
                }
            }
        }
    } elseif ($action === "delete") {
        $id = (int)($_POST["id"] ?? 0);

        $stmt = $conn->prepare("
            DELETE FROM beneficiaries
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $id, $userId);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "Beneficiary deleted successfully.";
        } else {
            $error = "Beneficiary could not be deleted.";
        }
    }

    header("Location: beneficiaries.php?" . http_build_query([
        "message" => $message,
        "error" => $error
    ]));
    exit;
}

$message = $_GET["message"] ?? "";
$error = $_GET["error"] ?? "";

$stmt = $conn->prepare("
    SELECT id, name, bank_name, account_number, ifsc, account_type, created_at
    FROM beneficiaries
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$beneficiaries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalBeneficiaries = count($beneficiaries);
$bankAccounts = $totalBeneficiaries;

$monthStart = date("Y-m-01 00:00:00");
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM transactions
    WHERE user_id = ?
      AND created_at >= ?
      AND type = 'debit'
      AND status = 'success'
");
$stmt->bind_param("is", $userId, $monthStart);
$stmt->execute();
$transferRow = $stmt->get_result()->fetch_assoc();
$transfersThisMonth = (int)($transferRow["total"] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beneficiaries — NexaBank</title>
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
        }

        .heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
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

        .add-btn {
            border: none;
            background: #4f8cff;
            color: white;
            padding: 12px 17px;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 10px;
            font-weight: 600;
        }

        .add-btn:hover {
            background: #2864d7;
        }

        .add-btn i {
            margin-right: 6px;
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
            padding: 19px;
        }

        .summary-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
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
            margin-top: 5px;
        }

        /* SEARCH */

        .tools {
            background: white;
            border: 1px solid #e7ebf1;
            padding: 17px;
            border-radius: 14px 14px 0 0;
            display: flex;
            gap: 12px;
        }

        .search-box {
            flex: 1;
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 11px;
        }

        .search-box input {
            width: 100%;
            height: 39px;
            border: 1px solid #e1e6ed;
            border-radius: 8px;
            padding: 0 12px 0 35px;
            outline: none;
            font-family: inherit;
            font-size: 10px;
        }

        .search-box input:focus {
            border-color: #4f8cff;
        }

        .filter {
            height: 39px;
            border: 1px solid #e1e6ed;
            border-radius: 8px;
            background: white;
            padding: 0 12px;
            font-family: inherit;
            font-size: 10px;
            color: #475569;
            outline: none;
        }

        /* BENEFICIARIES */

        .beneficiary-container {
            background: white;
            border: 1px solid #e7ebf1;
            border-top: none;
            border-radius: 0 0 14px 14px;
            padding: 20px;
        }

        .beneficiary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .beneficiary {
            border: 1px solid #e6ebf1;
            border-radius: 13px;
            padding: 18px;
            transition: 0.2s;
        }

        .beneficiary:hover {
            border-color: #b8d0ff;
            box-shadow: 0 7px 20px rgba(30,70,120,0.06);
        }

        .beneficiary-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .person {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .person-avatar {
            width: 39px;
            height: 39px;
            border-radius: 50%;
            background: #e8f0ff;
            color: #2864d7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
        }

        .person-info strong {
            display: block;
            font-size: 11px;
        }

        .person-info span {
            color: #94a3b8;
            font-size: 8px;
        }

        .menu-btn {
            border: none;
            background: transparent;
            color: #94a3b8;
            cursor: pointer;
            font-size: 15px;
        }

        .bank-info {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #edf0f4;
        }

        .bank-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .bank-row:last-child {
            margin-bottom: 0;
        }

        .bank-row span {
            color: #94a3b8;
            font-size: 8px;
        }

        .bank-row strong {
            font-size: 9px;
            color: #475569;
        }

        .beneficiary-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 7px;
            margin-top: 15px;
        }

        .beneficiary-actions button {
            border: 1px solid #e1e6ed;
            background: white;
            color: #475569;
            border-radius: 7px;
            padding: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 8px;
        }

        .beneficiary-actions button:hover {
            border-color: #4f8cff;
            color: #2864d7;
        }

        .empty-state {
            display: none;
            text-align: center;
            padding: 50px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 30px;
            margin-bottom: 12px;
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
            max-width: 460px;
            background: white;
            border-radius: 17px;
            padding: 28px;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
        }

        .modal-header h2 {
            font-size: 18px;
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

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-size: 9px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
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
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #4f8cff;
        }

        .form-actions {
            display: flex;
            gap: 9px;
            margin-top: 20px;
        }

        .form-actions button {
            flex: 1;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 10px;
        }

        .cancel {
            border: 1px solid #e1e6ed;
            background: white;
            color: #64748b;
        }

        .save {
            border: none;
            background: #4f8cff;
            color: white;
        }

        /* RESPONSIVE */

        @media (max-width: 1050px) {

            .beneficiary-grid {
                grid-template-columns: repeat(2, 1fr);
            }

        }

        @media (max-width: 800px) {

            .summary {
                grid-template-columns: 1fr;
            }

            .heading {
                align-items: flex-start;
                gap: 15px;
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

        @media (max-width: 600px) {

            .beneficiary-grid {
                grid-template-columns: 1fr;
            }

            .tools {
                flex-direction: column;
            }

            .filter {
                width: 100%;
            }

        }

        @media (max-width: 450px) {

            .heading {
                flex-direction: column;
            }

            .add-btn {
                width: 100%;
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

            <a href="#" class="sidebar-link">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Analytics</span>
            </a>

            <a href="beneficiaries.php" class="sidebar-link active">
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

            <h2>Beneficiaries</h2>

            <div class="topbar-right">

                <div class="notification">

                    <i class="fa-regular fa-bell"></i>

                    <span class="notification-dot"></span>

                </div>

                <div class="avatar">
                    <?= e($avatar) ?>
                </div>

            </div>

        </header>


        
<section class="content">
<?php if ($message): ?>
    <div style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;padding:12px 15px;border-radius:10px;margin-bottom:18px;font-size:11px;">
        <i class="fa-solid fa-circle-check"></i> <?= e($message) ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:12px 15px;border-radius:10px;margin-bottom:18px;font-size:11px;">
        <i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?>
    </div>
<?php endif; ?>



            <!-- HEADING -->

            <div class="heading">

                <div>

                    <h1>Saved Beneficiaries</h1>

                    <p>
                        Manage people and bank accounts you frequently transfer money to.
                    </p>

                </div>


                <button
                    class="add-btn"
                    onclick="openModal()"
                >

                    <i class="fa-solid fa-plus"></i>
                    Add Beneficiary

                </button>

            </div>


            <!-- SUMMARY -->

            <div class="summary">

                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="fa-solid fa-users"></i>
                    </div>

                    <span>Total Beneficiaries</span>

                    <strong id="totalCount"><?= $totalBeneficiaries ?></strong>

                </div>


                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="fa-solid fa-building-columns"></i>
                    </div>

                    <span>Bank Accounts</span>

                    <strong><?= $bankAccounts ?></strong>

                </div>


                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="fa-solid fa-arrow-right-arrow-left"></i>
                    </div>

                    <span>Transfers This Month</span>

                    <strong><?= $transfersThisMonth ?></strong>

                </div>

            </div>


            <!-- SEARCH -->

            <div class="tools">

                <div class="search-box">

                    <i class="fa-solid fa-magnifying-glass"></i>

                    <input
                        type="text"
                        id="searchInput"
                        placeholder="Search beneficiary..."
                        oninput="searchBeneficiaries()"
                    >

                </div>


                <select
                    class="filter"
                    id="bankFilter"
                    onchange="searchBeneficiaries()"
                >

                    <option value="all">All Banks</option>
                    <option value="hdfc">HDFC Bank</option>
                    <option value="sbi">State Bank of India</option>
                    <option value="icici">ICICI Bank</option>
                    <option value="axis">Axis Bank</option>

                </select>

            </div>


            <!-- BENEFICIARY LIST -->

            <div class="beneficiary-container">

                
<div class="beneficiary-grid" id="beneficiaryGrid">
<?php foreach ($beneficiaries as $b): ?>
    <?php
        $bankKey = array_search($b["bank_name"], $banks, true);
        $bankKey = $bankKey ?: strtolower(preg_replace('/[^a-z]/', '', $b["bank_name"]));
        $initial = initials($b["name"]);
        $masked = "•••• " . substr($b["account_number"], -4);
    ?>
    <div class="beneficiary"
         data-name="<?= e($b["name"]) ?>"
         data-bank="<?= e($bankKey) ?>">

        <div class="beneficiary-top">
            <div class="person">
                <div class="person-avatar"><?= e($initial) ?></div>
                <div class="person-info">
                    <strong><?= e($b["name"]) ?></strong>
                    <span><?= e($b["bank_name"]) ?></span>
                </div>
            </div>

            <form method="post" onsubmit="return confirm('Remove <?= e($b["name"]) ?>
                <?= csrf_field() ?> from your beneficiaries?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$b["id"] ?>">
                <button class="menu-btn" type="submit" title="Delete">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </form>
        </div>

        <div class="bank-info">
            <div class="bank-row">
                <span>Account</span>
                <strong><?= e($masked) ?></strong>
            </div>
            <div class="bank-row">
                <span>IFSC</span>
                <strong><?= e($b["ifsc"]) ?></strong>
            </div>
            <div class="bank-row">
                <span>Type</span>
                <strong><?= e($b["account_type"]) ?></strong>
            </div>
        </div>

        <div class="beneficiary-actions">
            <button type="button"
                    onclick='sendMoney(<?= json_encode($b["name"]) ?>, <?= json_encode($b["account_number"]) ?>, <?= json_encode($b["ifsc"]) ?>)'>
                <i class="fa-solid fa-paper-plane"></i> Send Money
            </button>

            <button type="button"
                    onclick='editBeneficiary(<?= json_encode($b["id"]) ?>, <?= json_encode($b["name"]) ?>, <?= json_encode($bankKey) ?>, <?= json_encode($b["account_number"]) ?>, <?= json_encode($b["ifsc"]) ?>, <?= json_encode($b["account_type"]) ?>)'>
                <i class="fa-solid fa-pen"></i> Edit
            </button>
        </div>
    </div>
<?php endforeach; ?>
</div>


<div
                    class="empty-state"
                    id="emptyState"
                >

                    <i class="fa-solid fa-user-slash"></i>

                    <div>
                        No beneficiaries found.
                    </div>

                </div>

            </div>

        </section>

    </main>

</div>


<!-- ADD BENEFICIARY MODAL -->

<div
    class="modal-overlay"
    id="addModal"
>

    <div class="modal">

        <div class="modal-header">

            <h2 id="modalTitle">Add Beneficiary</h2>

            <button
                class="close-btn"
                onclick="closeModal()"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>

        </div>


        <form id="beneficiaryForm" method="post" action="beneficiaries.php">
                <?= csrf_field() ?>
<input type="hidden" name="action" id="formAction" value="add">
<input type="hidden" name="id" id="beneficiaryId" value="">


            <div class="form-group">

                <label>Beneficiary Name</label>

                <input
                    type="text"
                    name="name" id="beneficiaryName"
                    placeholder="Enter full name"
                    required
                >

            </div>


            <div class="form-group">

                <label>Bank</label>

                <select name="bank" id="beneficiaryBank" required>

                    <option value="">
                        Select bank
                    </option>

                    <option value="hdfc">
                        HDFC Bank
                    </option>

                    <option value="sbi">
                        State Bank of India
                    </option>

                    <option value="icici">
                        ICICI Bank
                    </option>

                    <option value="axis">
                        Axis Bank
                    </option>

                </select>

            </div>


            <div class="form-group">

                <label>Account Number</label>

                <input
                    type="text"
                    name="account_number" id="accountNumber"
                    placeholder="Enter account number"
                    maxlength="18"
                    required
                >

            </div>


            <div class="form-group">

                <label>IFSC Code</label>

                <input
                    type="text"
                    name="ifsc" id="ifsc"
                    placeholder="Example: HDFC0001234"
                    maxlength="11"
                    required
                >

            </div>


            <div class="form-group">

                <label>Account Type</label>

                <select name="account_type" id="accountType">

                    <option>
                        Savings
                    </option>

                    <option>
                        Current
                    </option>

                </select>

            </div>


            <div class="form-actions">

                <button
                    type="button"
                    class="cancel"
                    onclick="closeModal()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="save" id="saveButton"
                >
                    Add Beneficiary
                </button>

            </div>

        </form>

    </div>

</div>



<script>
function openModal() {
    document.getElementById("addModal").style.display = "flex";
    document.getElementById("modalTitle").textContent = "Add Beneficiary";
    document.getElementById("formAction").value = "add";
    document.getElementById("beneficiaryId").value = "";
    document.getElementById("beneficiaryForm").reset();
    document.getElementById("saveButton").textContent = "Add Beneficiary";
}

function closeModal() {
    document.getElementById("addModal").style.display = "none";
}

function editBeneficiary(id, name, bank, account, ifsc, type) {
    document.getElementById("addModal").style.display = "flex";
    document.getElementById("modalTitle").textContent = "Edit Beneficiary";
    document.getElementById("formAction").value = "edit";
    document.getElementById("beneficiaryId").value = id;
    document.getElementById("beneficiaryName").value = name;
    document.getElementById("beneficiaryBank").value = bank;
    document.getElementById("accountNumber").value = account;
    document.getElementById("ifsc").value = ifsc;
    document.getElementById("accountType").value = type;
    document.getElementById("saveButton").textContent = "Save Changes";
}

function sendMoney(name, account, ifsc) {
    window.location.href =
        "transfer.php?recipient=" + encodeURIComponent(name) +
        "&account=" + encodeURIComponent(account) +
        "&ifsc=" + encodeURIComponent(ifsc);
}

function searchBeneficiaries() {
    const search = document.getElementById("searchInput").value.toLowerCase();
    const bank = document.getElementById("bankFilter").value;
    const cards = document.querySelectorAll(".beneficiary");
    let visible = 0;

    cards.forEach(card => {
        const name = card.dataset.name.toLowerCase();
        const cardBank = card.dataset.bank;
        const bankText = card.innerText.toLowerCase();

        const nameMatch = name.includes(search) || bankText.includes(search);
        const bankMatch = bank === "all" || cardBank === bank;

        if (nameMatch && bankMatch) {
            card.style.display = "";
            visible++;
        } else {
            card.style.display = "none";
        }
    });

    document.getElementById("emptyState").style.display =
        visible === 0 ? "block" : "none";
}

document.getElementById("beneficiaryForm").addEventListener("submit", function () {
    document.getElementById("ifsc").value =
        document.getElementById("ifsc").value.trim().toUpperCase();
});

document.getElementById("addModal").addEventListener("click", function(event) {
    if (event.target === this) closeModal();
});
</script>
