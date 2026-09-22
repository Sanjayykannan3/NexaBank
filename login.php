<?php

session_start();

require_once "config/db.php";
require_once "config/send_otp.php";

$error = "";


/* =========================================================
   LOGIN PROCESSING
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";


    /* =====================================================
       VALIDATION
    ===================================================== */

    if ($email === "" || $password === "") {

        $error = "Please enter your email and password.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } else {


        /* =================================================
           FIND USER
        ================================================= */

        $stmt = $conn->prepare(
            "SELECT
                id,
                first_name,
                last_name,
                email,
                password_hash,
                status,
                role
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->bind_param("s", $email);

        $stmt->execute();

        $result = $stmt->get_result();

        $user = $result->fetch_assoc();

        $stmt->close();


        /* =================================================
           VERIFY PASSWORD
        ================================================= */

        if (
            !$user ||
            !password_verify(
                $password,
                $user["password_hash"]
            )
        ) {

            $error = "Invalid email or password.";

        } elseif ($user["status"] !== "Active") {

            $error =
                "Your account is not active. Please contact support.";

        } else {


            /* =================================================
               GENERATE 6-DIGIT OTP
            ================================================= */

            $otp = (string) random_int(
                100000,
                999999
            );


            /* =================================================
               HASH OTP
            ================================================= */

            $otpHash = password_hash(
                $otp,
                PASSWORD_DEFAULT
            );


            /* =================================================
               OTP EXPIRY
               5 MINUTES
            ================================================= */

            $otpExpiresAt = date(
                "Y-m-d H:i:s",
                time() + 300
            );


            /* =================================================
               SAVE OTP TO DATABASE
            ================================================= */

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
                $user["id"]
            );


            if (!$update->execute()) {

                $error =
                    "Unable to generate verification code.";

                $update->close();

            } else {

                $update->close();


                /* =================================================
                   SEND OTP THROUGH GMAIL
                ================================================= */

                $otpSent = sendOtpEmail(
                    $user["email"],
                    $user["first_name"] .
                    " " .
                    $user["last_name"],
                    $otp
                );


                /* =================================================
                   EMAIL FAILED
                ================================================= */

                if (!$otpSent) {

                    /*
                     * Clear the OTP because it was not
                     * successfully sent.
                     */

                    $clearOtp = $conn->prepare(
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
                        $user["id"]
                    );

                    $clearOtp->execute();

                    $clearOtp->close();


                    $error =
                        "Unable to send the OTP email. Please check your email configuration and try again.";

                } else {


                    /* =================================================
                       CREATE TEMPORARY OTP SESSION
                    ================================================= */

                    session_regenerate_id(true);


                    $_SESSION["otp_user_id"] =
                        (int) $user["id"];

                    $_SESSION["otp_user_email"] =
                        $user["email"];

                    $_SESSION["otp_user_role"] =
                        $user["role"];

                    $_SESSION["otp_user_name"] =
                        $user["first_name"] .
                        " " .
                        $user["last_name"];


                    /* =================================================
                       REDIRECT TO OTP VERIFICATION
                    ================================================= */

                    header(
                        "Location: verify_otp.php"
                    );

                    exit;
                }
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

    <title>Login — NexaBank</title>


    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <link
        rel="stylesheet"
        href="css/style.css"
    >


    <style>

        .auth-page {
            min-height: calc(100vh - 80px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
        }


        .auth-container {
            width: 100%;
            max-width: 460px;
        }


        .auth-header {
            text-align: center;
            margin-bottom: 35px;
        }


        .auth-icon {
            width: 55px;
            height: 55px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            border-radius: 15px;
            font-size: 22px;
        }


        .auth-header h1 {
            font-size: 34px;
            margin-bottom: 8px;
        }


        .auth-header p {
            color: var(--muted);
            font-size: 14px;
        }


        .auth-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 35px;
            box-shadow:
                0 25px 70px
                rgba(0,0,0,0.25);
        }


        .form-group {
            margin-bottom: 20px;
        }


        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 8px;
        }


        .input-wrapper {
            position: relative;
        }


        .input-wrapper i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
        }


        .input-wrapper input {
            width: 100%;
            padding: 14px 45px;
            background: #091625;
            border: 1px solid var(--border);
            border-radius: 9px;
            outline: none;
            color: white;
            font-size: 13px;
            transition: 0.3s;
        }


        .input-wrapper input:focus {
            border-color: var(--primary);
            box-shadow:
                0 0 0 3px
                rgba(79,140,255,0.1);
        }


        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
        }


        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 5px 0 25px;
            font-size: 12px;
        }


        .remember {
            display: flex;
            gap: 7px;
            align-items: center;
            color: var(--muted);
        }


        .remember input {
            accent-color: var(--primary);
        }


        .forgot {
            color: #8eb8ff;
        }


        .login-submit {
            width: 100%;
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
        }


        .divider {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 25px 0;
            color: var(--muted);
            font-size: 11px;
        }


        .divider::before,
        .divider::after {
            content: "";
            height: 1px;
            background: var(--border);
            flex: 1;
        }


        .social-login {
            width: 100%;
            padding: 13px;
            border-radius: 9px;
            background: transparent;
            border: 1px solid var(--border);
            color: white;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }


        .social-login:hover {
            background: #12243a;
        }


        .signup-text {
            text-align: center;
            margin-top: 25px;
            color: var(--muted);
            font-size: 12px;
        }


        .signup-text a {
            color: #8eb8ff;
            font-weight: 600;
        }


        .security-note {
            margin-top: 20px;
            padding: 12px;
            border-radius: 9px;
            background:
                rgba(56,211,159,0.06);
            border:
                1px solid
                rgba(56,211,159,0.12);
            display: flex;
            gap: 10px;
            align-items: center;
            color: var(--muted);
            font-size: 10px;
        }


        .security-note i {
            color: var(--success);
        }


        .error-message {
            margin-bottom: 20px;
            padding: 14px;
            border-radius: 10px;
            background:
                rgba(255,82,82,0.10);
            border:
                1px solid
                rgba(255,82,82,0.25);
            color: #ff8a8a;
            font-size: 12px;
            line-height: 1.5;
        }


        @media (max-width: 500px) {

            .auth-card {
                padding: 25px 20px;
            }


            .auth-header h1 {
                font-size: 29px;
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

            <i class="fa-solid fa-building-columns"></i>

        </div>


        <span>
            Nexa<span>Bank</span>
        </span>

    </a>


    <a
        href="index.html"
        class="secondary-btn"
    >

        <i class="fa-solid fa-arrow-left"></i>

        Back Home

    </a>

</header>



<main class="auth-page">


    <div class="auth-container">


        <div class="auth-header">


            <div class="auth-icon">

                <i class="fa-solid fa-lock"></i>

            </div>


            <h1>
                Welcome back
            </h1>


            <p>
                Sign in to access your NexaBank account.
            </p>

        </div>



        <div class="auth-card">


            <?php if ($error !== ""): ?>

                <div class="error-message">

                    <i
                        class="fa-solid fa-circle-exclamation"
                    ></i>

                    <?= htmlspecialchars($error) ?>

                </div>

            <?php endif; ?>



            <form
                id="loginForm"
                method="POST"
                action="login.php"
            >


                <div class="form-group">

                    <label for="email">
                        Email Address
                    </label>


                    <div class="input-wrapper">

                        <i
                            class="fa-solid fa-envelope"
                        ></i>


                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="you@example.com"
                            autocomplete="email"
                            value="<?= htmlspecialchars($email ?? "") ?>"
                            required
                        >

                    </div>

                </div>



                <div class="form-group">

                    <label for="password">
                        Password
                    </label>


                    <div class="input-wrapper">

                        <i
                            class="fa-solid fa-lock"
                        ></i>


                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >


                        <button
                            type="button"
                            class="password-toggle"
                            id="togglePassword"
                            aria-label="Show password"
                        >

                            <i
                                class="fa-solid fa-eye"
                            ></i>

                        </button>

                    </div>

                </div>



                <div class="form-options">


                    <label class="remember">

                        <input
                            type="checkbox"
                            name="remember"
                        >

                        Remember me

                    </label>


                    <a
                        href="#"
                        class="forgot"
                    >
                        Forgot password?
                    </a>


                </div>



                <button
                    type="submit"
                    class="primary-btn login-submit"
                >

                    Continue

                    <i
                        class="fa-solid fa-arrow-right"
                    ></i>

                </button>


            </form>



            <div class="divider">
                OR
            </div>



            <button
                type="button"
                class="social-login"
            >

                <i
                    class="fa-brands fa-google"
                ></i>

                Continue with Google

            </button>



            <div class="signup-text">

                Don't have an account?

                <a href="register.php">
                    Create one
                </a>

            </div>



            <div class="security-note">

                <i
                    class="fa-solid fa-shield-halved"
                ></i>

                <span>
                    Your connection is protected with secure encryption.
                </span>

            </div>


        </div>

    </div>

</main>



<script>

const togglePassword =
    document.getElementById("togglePassword");

const password =
    document.getElementById("password");


togglePassword.addEventListener(
    "click",
    function () {

        if (password.type === "password") {

            password.type = "text";

            this.innerHTML =
                '<i class="fa-solid fa-eye-slash"></i>';

            this.setAttribute(
                "aria-label",
                "Hide password"
            );

        } else {

            password.type = "password";

            this.innerHTML =
                '<i class="fa-solid fa-eye"></i>';

            this.setAttribute(
                "aria-label",
                "Show password"
            );

        }

    }
);

</script>

