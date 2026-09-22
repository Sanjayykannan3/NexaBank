<?php
session_start();
require_once __DIR__ . "/config/db.php";

$message = "";
$error = "";

try {
    // Find or create demo sender user
    $senderEmail = "demo.sender@nexabank.local";
    $senderPassword = "Test@12345";

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $senderEmail);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $senderId = (int)$row["id"];
    } else {
        $hash = password_hash($senderPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("
            INSERT INTO users
            (first_name, last_name, email, phone, dob, password_hash, account_type, status)
            VALUES (?, ?, ?, ?, ?, ?, 'Savings', 'Active')
        ");
        $first = "Demo";
        $last = "Sender";
        $phone = "9000000001";
        $dob = "2000-01-01";
        $stmt->bind_param("ssssss", $first, $last, $senderEmail, $phone, $dob, $hash);
        $stmt->execute();
        $senderId = $conn->insert_id;
    }

    // Find or create sender account
    $stmt = $conn->prepare("SELECT id, account_number FROM accounts WHERE user_id = ? AND status = 'Active' LIMIT 1");
    $stmt->bind_param("i", $senderId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $senderAccountId = (int)$row["id"];
        $senderAccountNumber = $row["account_number"];
    } else {
        do {
            $senderAccountNumber = (string)random_int(100000000000, 999999999999);
            $check = $conn->prepare("SELECT id FROM accounts WHERE account_number = ?");
            $check->bind_param("s", $senderAccountNumber);
            $check->execute();
        } while ($check->get_result()->num_rows > 0);

        $ifsc = "NEXA0000123";
        $type = "Savings";
        $balance = 5000.00;

        $stmt = $conn->prepare("
            INSERT INTO accounts
            (user_id, account_number, ifsc, account_type, balance, status)
            VALUES (?, ?, ?, ?, ?, 'Active')
        ");
        $stmt->bind_param("isssd", $senderId, $senderAccountNumber, $ifsc, $type, $balance);
        $stmt->execute();
        $senderAccountId = $conn->insert_id;
    }

    // Always reset demo sender to ₹5,000 for repeatable testing
    $stmt = $conn->prepare("UPDATE accounts SET balance = 5000.00, status = 'Active' WHERE id = ?");
    $stmt->bind_param("i", $senderAccountId);
    $stmt->execute();

    // Find or create demo receiver user
    $receiverEmail = "demo.receiver@nexabank.local";
    $receiverPassword = "Test@12345";

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $receiverEmail);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $receiverId = (int)$row["id"];
    } else {
        $hash = password_hash($receiverPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("
            INSERT INTO users
            (first_name, last_name, email, phone, dob, password_hash, account_type, status)
            VALUES (?, ?, ?, ?, ?, ?, 'Savings', 'Active')
        ");
        $first = "Demo";
        $last = "Receiver";
        $phone = "9000000002";
        $dob = "2000-01-01";
        $stmt->bind_param("ssssss", $first, $last, $receiverEmail, $phone, $dob, $hash);
        $stmt->execute();
        $receiverId = $conn->insert_id;
    }

    // Find or create receiver account
    $stmt = $conn->prepare("SELECT id, account_number FROM accounts WHERE user_id = ? AND status = 'Active' LIMIT 1");
    $stmt->bind_param("i", $receiverId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $receiverAccountId = (int)$row["id"];
        $receiverAccountNumber = $row["account_number"];
    } else {
        do {
            $receiverAccountNumber = (string)random_int(100000000000, 999999999999);
            $check = $conn->prepare("SELECT id FROM accounts WHERE account_number = ?");
            $check->bind_param("s", $receiverAccountNumber);
            $check->execute();
        } while ($check->get_result()->num_rows > 0);

        $ifsc = "NEXA0000123";
        $type = "Savings";
        $balance = 0.00;

        $stmt = $conn->prepare("
            INSERT INTO accounts
            (user_id, account_number, ifsc, account_type, balance, status)
            VALUES (?, ?, ?, ?, ?, 'Active')
        ");
        $stmt->bind_param("isssd", $receiverId, $receiverAccountNumber, $ifsc, $type, $balance);
        $stmt->execute();
        $receiverAccountId = $conn->insert_id;
    }

    // Reset receiver so every fresh setup starts at ₹0
    $stmt = $conn->prepare("UPDATE accounts SET balance = 0.00, status = 'Active' WHERE id = ?");
    $stmt->bind_param("i", $receiverAccountId);
    $stmt->execute();

    $message = "Demo setup completed successfully.";
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>NexaBank Demo Setup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #07111f;
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .box {
            width: 90%;
            max-width: 650px;
            background: #101d2f;
            border: 1px solid #263852;
            border-radius: 18px;
            padding: 30px;
            box-sizing: border-box;
        }
        h1 { margin-top: 0; }
        .success { color: #5ee6a8; }
        .error { color: #ff7777; }
        .data {
            background: #07111f;
            padding: 18px;
            border-radius: 12px;
            line-height: 1.8;
            margin-top: 20px;
        }
        code {
            background: #182941;
            padding: 3px 7px;
            border-radius: 5px;
        }
        a {
            display: inline-block;
            margin-top: 20px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            padding: 12px 18px;
            border-radius: 9px;
        }
    </style>
</head>
<body>
<div class="box">
    <?php if ($error): ?>
        <h1 class="error">Demo setup failed</h1>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php else: ?>
        <h1 class="success">✅ Demo setup completed</h1>
        <p><?= htmlspecialchars($message) ?></p>

        <div class="data">
            <strong>Sender account</strong><br>
            Email: <code>demo.sender@nexabank.local</code><br>
            Password: <code>Test@12345</code><br>
            Account: <code><?= htmlspecialchars($senderAccountNumber) ?></code><br>
            Balance: <strong>₹5,000</strong><br><br>

            <strong>Receiver account</strong><br>
            Name: Demo Receiver<br>
            Email: <code>demo.receiver@nexabank.local</code><br>
            Account: <code><?= htmlspecialchars($receiverAccountNumber) ?></code><br>
            IFSC: <code>NEXA0000123</code><br>
            Balance: <strong>₹0</strong>
        </div>

        <a href="login.php">Go to Login</a>
        <a href="transfer.php">Go to Transfer</a>
    <?php endif; ?>
</div>
</body>
</html>
