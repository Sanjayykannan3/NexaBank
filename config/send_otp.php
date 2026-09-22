<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/../vendor/autoload.php";


function sendOtpEmail(
    string $recipientEmail,
    string $recipientName,
    string $otp
): bool {

    $mailConfig = require __DIR__ . "/mail.php";

    $mail = new PHPMailer(true);

    try {

        /* =========================================
           SMTP SETTINGS
        ========================================= */

        $mail->isSMTP();

        $mail->Host =
            $mailConfig["host"];

        $mail->SMTPAuth = true;

        $mail->Username =
            $mailConfig["username"];

        $mail->Password =
            $mailConfig["password"];

        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port =
            $mailConfig["port"];


        /* =========================================
           SENDER
        ========================================= */

        $mail->setFrom(
            $mailConfig["from_email"],
            $mailConfig["from_name"]
        );


        /* =========================================
           RECIPIENT
        ========================================= */

        $mail->addAddress(
            $recipientEmail,
            $recipientName
        );


        /* =========================================
           EMAIL
        ========================================= */

        $mail->isHTML(true);

        $mail->Subject =
            "Your NexaBank Login OTP";


        $mail->Body = "

        <div style=\"
            font-family:Arial,sans-serif;
            background:#f5f7fb;
            padding:30px;
        \">

            <div style=\"
                max-width:520px;
                margin:auto;
                background:white;
                padding:35px;
                border-radius:15px;
                border:1px solid #e5e7eb;
            \">

                <h2 style=\"
                    color:#2563eb;
                    margin-bottom:10px;
                \">
                    NexaBank
                </h2>

                <p>
                    Hello " .
                    htmlspecialchars($recipientName) .
                    ",
                </p>

                <p>
                    We received a request to sign in
                    to your NexaBank account.
                </p>

                <p>
                    Your one-time verification code is:
                </p>

                <div style=\"
                    text-align:center;
                    margin:25px 0;
                    padding:18px;
                    background:#f1f5ff;
                    border-radius:10px;
                    font-size:32px;
                    font-weight:bold;
                    letter-spacing:8px;
                    color:#2563eb;
                \">

                    " . htmlspecialchars($otp) . "

                </div>

                <p>
                    This OTP is valid for
                    <strong>5 minutes</strong>.
                </p>

                <p style=\"
                    color:#6b7280;
                    font-size:13px;
                \">

                    If you did not attempt to sign in,
                    you can safely ignore this email.

                </p>

                <hr>

                <p style=\"
                    color:#9ca3af;
                    font-size:11px;
                    text-align:center;
                \">

                    This is an automated message from
                    NexaBank.

                </p>

            </div>

        </div>
        ";


        $mail->AltBody =
            "Your NexaBank login OTP is: "
            . $otp
            . ". It is valid for 5 minutes.";


        /* =========================================
           SEND
        ========================================= */

        return $mail->send();

    } catch (Exception $e) {

        error_log(
            "NexaBank OTP email error: "
            . $mail->ErrorInfo
        );

        return false;
    }
}