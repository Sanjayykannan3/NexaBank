<?php

session_start();

require_once "config/db.php";

$error = "";
$success = "";


/* =========================================================
   CHECK PENDING OTP LOGIN
========================================================= */

if (
    empty($_SESSION["otp_user_id"]) ||
    empty($_SESSION["otp_user_email"])
) {

    header("Location: login.php");

    exit;
}


$userId =
    (int) $_SESSION["otp_user_id"];

$userEmail =
    $_SESSION["otp_user_email"];


/* =========================================================
   HANDLE POST REQUEST
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action =
        $_POST["action"] ?? "verify";


    /* =====================================================
       RESEND OTP
    ===================================================== */

    if ($action === "resend") {


        /* ---------------------------------------------
           GET USER
        --------------------------------------------- */

        $stmt = $conn->prepare(
            "SELECT
                id,
                first_name,
                last_name,
                email,
                role,
                status,
                otp_last_sent_at
             FROM users
             WHERE id = ?
             LIMIT 1"
        );


        $stmt->bind_param(
            "i",
            $userId
        );


        $stmt->execute();

        $result =
            $stmt->get_result();

        $user =
            $result->fetch_assoc();

        $stmt->close();


        /* ---------------------------------------------
           CHECK USER
        --------------------------------------------- */

        if (
            !$user ||
            $user["status"] !== "Active"
        ) {

            $_SESSION = [];

            session_destroy();

            header("Location: login.php");

            exit;
        }


        /* ---------------------------------------------
           30-SECOND RESEND COOLDOWN
        --------------------------------------------- */

        if (!empty($user["otp_last_sent_at"])) {

            $lastSent =
                strtotime(
                    $user["otp_last_sent_at"]
                );


            if (
                $lastSent !== false &&
                (time() - $lastSent) < 30
            ) {

                $remaining =
                    30 -
                    (time() - $lastSent);


                $error =
                    "Please wait " .
                    $remaining .
                    " seconds before requesting another OTP.";
            }
        }


        /* ---------------------------------------------
           GENERATE NEW OTP
        --------------------------------------------- */

        if ($error === "") {

            $otp =
                (string) random_int(
                    100000,
                    999999
                );


            $otpHash =
                password_hash(
                    $otp,
                    PASSWORD_DEFAULT
                );


            $otpExpiresAt =
                date(
                    "Y-m-d H:i:s",
                    time() + 300
                );


            /* -----------------------------------------
               SAVE NEW OTP
            ----------------------------------------- */

            $update = $conn->prepare(
                "UPDATE users
                 SET
                    otp_hash = ?,
                    otp_expires_at = ?,
                    otp_attempts = 0,
                    otp_last_sent_at = NOW()
                 WHERE id = ?"
            );


            $update->bind_param(
                "ssi",
                $otpHash,
                $otpExpiresAt,
                $userId
            );


            if (!$update->execute()) {

                $error =
                    "Unable to generate a new OTP.";

                $update->close();

            } else {

                $update->close();


                /* -----------------------------------------
                   SEND NEW OTP EMAIL
                ----------------------------------------- */

                require_once "config/send_otp.php";


                $otpSent =
                    sendOtpEmail(
                        $user["email"],
                        $user["first_name"] .
                        " " .
                        $user["last_name"],
                        $otp
                    );


                if (!$otpSent) {

                    /* Clear failed OTP */

                    $clearOtp =
                        $conn->prepare(
                            "UPDATE users
                             SET
                                otp_hash = NULL,
                                otp_expires_at = NULL,
                                otp_attempts = 0,
                                otp_last_sent_at = NULL
                             WHERE id = ?"
                        );


                    $clearOtp->bind_param(
                        "i",
                        $userId
                    );


                    $clearOtp->execute();

                    $clearOtp->close();


                    $error =
                        "Unable to send the OTP email. Please try again.";

                } else {

                    $success =
                        "A new OTP has been sent to your email.";

                }
            }
        }
    }


    /* =====================================================
       VERIFY OTP
    ===================================================== */

    else {

        $otp =
            trim(
                $_POST["otp"] ?? ""
            );


        /* ---------------------------------------------
           VALIDATE OTP FORMAT
        --------------------------------------------- */

        if (
            !preg_match(
                "/^[0-9]{6}$/",
                $otp
            )
        ) {

            $error =
                "Please enter the 6-digit OTP.";

        } else {


            /* ---------------------------------------------
               GET OTP DATA
            --------------------------------------------- */

            $stmt = $conn->prepare(
                "SELECT
                    id,
                    first_name,
                    last_name,
                    email,
                    role,
                    status,
                    otp_hash,
                    otp_expires_at,
                    otp_attempts
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );


            $stmt->bind_param(
                "i",
                $userId
            );


            $stmt->execute();


            $result =
                $stmt->get_result();


            $user =
                $result->fetch_assoc();


            $stmt->close();


            /* ---------------------------------------------
               USER CHECK
            --------------------------------------------- */

            if (
                !$user ||
                $user["status"] !== "Active"
            ) {

                $_SESSION = [];

                session_destroy();

                header(
                    "Location: login.php"
                );

                exit;
            }


            /* ---------------------------------------------
               TOO MANY ATTEMPTS
            --------------------------------------------- */

            if (
                (int) $user["otp_attempts"] >= 5
            ) {

                $error =
                    "Too many incorrect OTP attempts. Please request a new OTP.";
            }


            /* ---------------------------------------------
               OTP DOES NOT EXIST
            --------------------------------------------- */

            elseif (
                empty($user["otp_hash"])
            ) {

                $error =
                    "No active OTP found. Please request a new OTP.";
            }


            /* ---------------------------------------------
               OTP EXPIRED
            --------------------------------------------- */

            elseif (
                empty($user["otp_expires_at"]) ||
                strtotime(
                    $user["otp_expires_at"]
                ) < time()
            ) {

                $error =
                    "Your OTP has expired. Please request a new OTP.";
            }


            /* ---------------------------------------------
               VERIFY OTP
            --------------------------------------------- */

            elseif (
                !password_verify(
                    $otp,
                    $user["otp_hash"]
                )
            ) {


                /* Increase failed attempts */

                $updateAttempts =
                    $conn->prepare(
                        "UPDATE users
                         SET otp_attempts =
                             otp_attempts + 1
                         WHERE id = ?"
                    );


                $updateAttempts->bind_param(
                    "i",
                    $userId
                );


                $updateAttempts->execute();

                $updateAttempts->close();


                $error =
                    "Incorrect OTP. Please try again.";
            }


            /* ---------------------------------------------
               OTP SUCCESS
            --------------------------------------------- */

            else {


                /* -----------------------------------------
                   CLEAR OTP
                ----------------------------------------- */

                $clearOtp =
                    $conn->prepare(
                        "UPDATE users
                         SET
                            otp_hash = NULL,
                            otp_expires_at = NULL,
                            otp_attempts = 0,
                            otp_last_sent_at = NULL
                         WHERE id = ?"
                    );


                $clearOtp->bind_param(
                    "i",
                    $userId
                );


                $clearOtp->execute();

                $clearOtp->close();


                /* -----------------------------------------
                   CREATE SECURE LOGIN SESSION
                ----------------------------------------- */

                session_regenerate_id(true);


                $_SESSION["user_id"] =
                    (int) $user["id"];


                $_SESSION["user_name"] =
                    $user["first_name"] .
                    " " .
                    $user["last_name"];


                $_SESSION["user_email"] =
                    $user["email"];


                $_SESSION["user_role"] =
                    $user["role"];


                /* -----------------------------------------
                   REMOVE TEMP OTP SESSION
                ----------------------------------------- */

                unset(
                    $_SESSION["otp_user_id"],
                    $_SESSION["otp_user_email"],
                    $_SESSION["otp_user_role"],
                    $_SESSION["otp_user_name"]
                );


                /* -----------------------------------------
                   ROLE-BASED REDIRECT
                ----------------------------------------- */

                if (
                    $user["role"] === "admin"
                ) {

                    header(
                        "Location: admin/dashboard.php"
                    );

                } else {

                    header(
                        "Location: dashboard.php"
                    );
                }

                exit;
            }
        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Verify OTP — NexaBank
    </title>


    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <link
        rel="stylesheet"
        href="css/style.css"
    >


    <style>

        .otp-page {

            min-height:
                calc(100vh - 80px);

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 60px 20px;
        }


        .otp-container {

            width: 100%;

            max-width: 460px;
        }


        .otp-header {

            text-align: center;

            margin-bottom: 35px;
        }


        .otp-icon {

            width: 60px;

            height: 60px;

            margin: 0 auto 20px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: var(--primary);

            border-radius: 16px;

            font-size: 24px;
        }


        .otp-header h1 {

            font-size: 32px;

            margin-bottom: 8px;
        }


        .otp-header p {

            color: var(--muted);

            font-size: 13px;

            line-height: 1.6;
        }


        .otp-card {

            background: var(--surface);

            border:
                1px solid
                var(--border);

            border-radius: 18px;

            padding: 35px;

            box-shadow:
                0 25px 70px
                rgba(0,0,0,0.25);
        }


        .email-display {

            text-align: center;

            margin-bottom: 25px;

            padding: 13px;

            border-radius: 10px;

            background: #091625;

            color: var(--muted);

            font-size: 12px;

            line-height: 1.6;
        }


        .email-display strong {

            display: block;

            color: #8eb8ff;

            margin-top: 4px;

            word-break: break-word;
        }


        .otp-input {

            width: 100%;

            padding: 17px;

            background: #091625;

            border:
                1px solid
                var(--border);

            border-radius: 10px;

            color: white;

            text-align: center;

            font-size: 26px;

            font-weight: 700;

            letter-spacing: 10px;

            outline: none;

            box-sizing: border-box;
        }


        .otp-input:focus {

            border-color:
                var(--primary);

            box-shadow:
                0 0 0 3px
                rgba(79,140,255,0.1);
        }


        .otp-input::placeholder {

            color: #526174;

            letter-spacing: 8px;
        }


        .verify-btn {

            width: 100%;

            margin-top: 20px;

            border: none;

            cursor: pointer;

            font-size: 14px;
        }


        .message {

            padding: 13px;

            border-radius: 9px;

            margin-bottom: 20px;

            font-size: 12px;

            text-align: center;

            line-height: 1.5;
        }


        .error-message {

            background:
                rgba(255,82,82,0.10);

            border:
                1px solid
                rgba(255,82,82,0.25);

            color: #ff8a8a;
        }


        .success-message {

            background:
                rgba(56,211,159,0.08);

            border:
                1px solid
                rgba(56,211,159,0.20);

            color: #6ee7b7;
        }


        .otp-info {

            margin-top: 20px;

            padding: 13px;

            border-radius: 10px;

            background:
                rgba(79,140,255,0.06);

            border:
                1px solid
                rgba(79,140,255,0.12);

            text-align: center;

            color: var(--muted);

            font-size: 11px;

            line-height: 1.6;
        }


        .otp-info i {

            color: #8eb8ff;

            margin-right: 5px;
        }


        .resend-section {

            text-align: center;

            margin-top: 25px;

            color: var(--muted);

            font-size: 12px;
        }


        .resend-btn {

            background: none;

            border: none;

            color: #8eb8ff;

            cursor: pointer;

            font-family: inherit;

            font-weight: 600;

            padding: 0;
        }


        .resend-btn:hover {

            color: white;
        }


        .back-login {

            display: block;

            text-align: center;

            margin-top: 20px;

            color: var(--muted);

            font-size: 12px;

            text-decoration: none;
        }


        .back-login:hover {

            color: white;
        }


        @media (max-width: 500px) {

            .otp-card {

                padding: 25px 20px;
            }


            .otp-header h1 {

                font-size: 29px;
            }


            .otp-input {

                font-size: 23px;

                letter-spacing: 7px;
            }

        }

    </style>

</head>


<body>


<header class="navbar">


    <a
        href="index.html"
        class="logo"
    >

        <div class="logo-icon">

            <i
                class="fa-solid fa-building-columns"
            ></i>

        </div>


        <span>
            Nexa<span>Bank</span>
        </span>

    </a>


    <a
        href="login.php"
        class="secondary-btn"
    >

        <i
            class="fa-solid fa-arrow-left"
        ></i>

        Back

    </a>

</header>



<main class="otp-page">


    <div class="otp-container">


        <div class="otp-header">


            <div class="otp-icon">

                <i
                    class="fa-solid fa-shield-halved"
                ></i>

            </div>


            <h1>
                Verify your identity
            </h1>


            <p>
                Enter the 6-digit verification code
                sent to your email address.
            </p>

        </div>



        <div class="otp-card">


            <div class="email-display">

                <i
                    class="fa-solid fa-envelope"
                ></i>

                Verification code sent to

                <strong>
                    <?= htmlspecialchars(
                        $userEmail,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </strong>

            </div>



            <?php if ($error !== ""): ?>

                <div
                    class="message error-message"
                >

                    <i
                        class="fa-solid fa-circle-exclamation"
                    ></i>

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>

            <?php endif; ?>



            <?php if ($success !== ""): ?>

                <div
                    class="message success-message"
                >

                    <i
                        class="fa-solid fa-circle-check"
                    ></i>

                    <?= htmlspecialchars(
                        $success,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>

            <?php endif; ?>



            <form
                method="POST"
                action="verify_otp.php"
            >

                <input
                    type="hidden"
                    name="action"
                    value="verify"
                >


                <input
                    type="text"
                    name="otp"
                    class="otp-input"
                    maxlength="6"
                    minlength="6"
                    pattern="[0-9]{6}"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    placeholder="000000"
                    required
                    autofocus
                >


                <button
                    type="submit"
                    class="primary-btn verify-btn"
                >

                    Verify & Continue

                    <i
                        class="fa-solid fa-arrow-right"
                    ></i>

                </button>

            </form>



            <div class="otp-info">

                <i
                    class="fa-solid fa-clock"
                ></i>

                Your OTP is valid for
                <strong>5 minutes</strong>.

                <br>

                Never share your OTP with anyone.

            </div>



            <div class="resend-section">

                Didn't receive the code?


                <form
                    method="POST"
                    action="verify_otp.php"
                    style="display:inline;"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="resend"
                    >


                    <button
                        type="submit"
                        class="resend-btn"
                    >

                        Resend OTP

                    </button>

                </form>

            </div>



            <a
                href="login.php"
                class="back-login"
            >

                <i
                    class="fa-solid fa-arrow-left"
                ></i>

                Return to login

            </a>


        </div>

    </div>

</main>

