<?php
// NexaBank CSRF protection helper.
// This file creates one session token and verifies it on state-changing POST requests.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") .
        '">';
}

function verify_csrf_or_fail(): void
{
    $sessionToken = $_SESSION["csrf_token"] ?? "";
    $submittedToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($sessionToken) ||
        !is_string($submittedToken) ||
        $sessionToken === "" ||
        $submittedToken === "" ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);
        exit("Invalid security token. Please refresh the page and try again.");
    }
}
?>
