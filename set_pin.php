<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/config/db.php";

$userId = (int) $_SESSION["user_id"];

// Demo PIN: 1234
$pin = "1234";

$hash = password_hash($pin, PASSWORD_DEFAULT);

$stmt = $conn->prepare("
    UPDATE users
    SET transaction_pin_hash = ?
    WHERE id = ?
");

$stmt->bind_param("si", $hash, $userId);
$stmt->execute();
$stmt->close();

echo "<h2>Transaction PIN configured successfully.</h2>";
echo "<p>Your demo Transaction PIN is: <strong>1234</strong></p>";
echo "<p><a href='transfer.php'>Go to Transfer</a></p>";
?>