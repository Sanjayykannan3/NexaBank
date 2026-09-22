<?php
session_start();
require_once __DIR__ . "/config/csrf.php";
require_once "config/db.php";

$success = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_or_fail();
    $firstName = trim($_POST["firstName"] ?? "");
    $lastName = trim($_POST["lastName"] ?? "");
    $email = trim($_POST["registerEmail"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $dob = $_POST["dob"] ?? "";
    $accountType = strtolower(trim($_POST["accountType"] ?? ""));
    $password = $_POST["registerPassword"] ?? "";
    $confirmPassword = $_POST["confirmPassword"] ?? "";
    $terms = isset($_POST["terms"]);

    if (
        $firstName === "" || $lastName === "" || $email === "" ||
        $phone === "" || $dob === "" || $accountType === "" ||
        $password === "" || $confirmPassword === ""
    ) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!in_array($accountType, ["savings", "current"], true)) {
        $error = "Please select a valid account type.";
    } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least 8 characters, including letters and numbers.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } elseif (!$terms) {
        $error = "Please accept the Terms & Conditions and Privacy Policy.";
    } else {
        $accountTypeDb = ucfirst($accountType);

        // Check whether the email already exists.
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "An account with this email already exists.";
            $check->close();
        } else {
            $check->close();

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            try {
                $conn->begin_transaction();

                // Create the user.
                $stmt = $conn->prepare(
                    "INSERT INTO users
                    (first_name, last_name, email, phone, dob, password_hash, account_type, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')"
                );
                $stmt->bind_param(
                    "sssssss",
                    $firstName,
                    $lastName,
                    $email,
                    $phone,
                    $dob,
                    $passwordHash,
                    $accountTypeDb
                );
                $stmt->execute();
                $userId = $stmt->insert_id;
                $stmt->close();

                // Generate a unique 12-digit demo bank account number.
                do {
                    $accountNumber = (string) random_int(100000000000, 999999999999);

                    $accountCheck = $conn->prepare(
                        "SELECT id FROM accounts WHERE account_number = ? LIMIT 1"
                    );
                    $accountCheck->bind_param("s", $accountNumber);
                    $accountCheck->execute();
                    $accountCheck->store_result();
                    $exists = $accountCheck->num_rows > 0;
                    $accountCheck->close();
                } while ($exists);

                $ifsc = "NEXA0000123";
                $initialBalance = 0.00;

                // Create the user's bank account.
                $accountStmt = $conn->prepare(
                    "INSERT INTO accounts
                    (user_id, account_number, ifsc, account_type, balance, status)
                    VALUES (?, ?, ?, ?, ?, 'Active')"
                );
                $accountStmt->bind_param(
                    "isssd",
                    $userId,
                    $accountNumber,
                    $ifsc,
                    $accountTypeDb,
                    $initialBalance
                );
                $accountStmt->execute();
                $accountStmt->close();

                $conn->commit();

                $success = "Account created successfully! Your demo account number is " . $accountNumber . ". You can now sign in.";
            } catch (Throwable $e) {
                $conn->rollback();
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Open Account — NexaBank</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="css/style.css">

    <style>

        .register-page {
            min-height: calc(100vh - 80px);
            display: flex;
            justify-content: center;
            padding: 60px 20px;
        }

        .register-container {
            width: 100%;
            max-width: 720px;
        }

        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .register-icon {
            width: 55px;
            height: 55px;
            margin: 0 auto 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            border-radius: 15px;
            font-size: 21px;
        }

        .register-header h1 {
            font-size: 34px;
            margin-bottom: 8px;
        }

        .register-header p {
            color: var(--muted);
            font-size: 14px;
        }

        .register-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 35px;
            box-shadow: 0 25px 70px rgba(0,0,0,0.25);
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .form-section-title span {
            width: 27px;
            height: 27px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(79,140,255,0.12);
            color: var(--primary);
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
        }

        .form-section-title h3 {
            font-size: 14px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group {
            margin-bottom: 2px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper > i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
        }

        .input-wrapper input,
        .input-wrapper select {
            width: 100%;
            padding: 13px 40px;
            background: #091625;
            border: 1px solid var(--border);
            border-radius: 9px;
            outline: none;
            color: white;
            font-family: inherit;
            font-size: 12px;
            transition: 0.3s;
        }

        .input-wrapper select {
            appearance: none;
            cursor: pointer;
        }

        .input-wrapper input:focus,
        .input-wrapper select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,140,255,0.1);
        }

        .password-toggle {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
        }

        .password-strength {
            height: 4px;
            margin-top: 8px;
            background: #18283a;
            border-radius: 10px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: 0.3s;
        }

        .password-hint {
            color: var(--muted);
            font-size: 9px;
            margin-top: 6px;
        }

        .terms {
            display: flex;
            gap: 9px;
            align-items: flex-start;
            margin: 10px 0 25px;
            color: var(--muted);
            font-size: 11px;
        }

        .terms input {
            margin-top: 2px;
            accent-color: var(--primary);
        }

        .terms a {
            color: #8eb8ff;
        }

        .register-submit {
            width: 100%;
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
        }

        .login-link {
            text-align: center;
            margin-top: 22px;
            color: var(--muted);
            font-size: 12px;
        }

        .login-link a {
            color: #8eb8ff;
            font-weight: 600;
        }

        .security-note {
            margin-top: 20px;
            padding: 12px;
            border-radius: 9px;
            background: rgba(56,211,159,0.06);
            border: 1px solid rgba(56,211,159,0.12);
            display: flex;
            gap: 10px;
            align-items: center;
            color: var(--muted);
            font-size: 10px;
        }

        .security-note i {
            color: var(--success);
        }

        @media (max-width: 600px) {

            .register-card {
                padding: 25px 18px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

            .register-header h1 {
                font-size: 29px;
            }
        }

    </style>
</head>

<body>

<header class="navbar">

    <a href="index.html" class="logo">

        <div class="logo-icon">
            <i class="fa-solid fa-building-columns"></i>
        </div>

        <span>Nexa<span>Bank</span></span>

    </a>

    <a href="index.html" class="secondary-btn">
        <i class="fa-solid fa-arrow-left"></i>
        Back Home
    </a>

</header>


<main class="register-page">

    <div class="register-container">

        <div class="register-header">

            <div class="register-icon">
                <i class="fa-solid fa-user-plus"></i>
            </div>

            <h1>Open your account</h1>

            <p>
                Create your NexaBank account in a few simple steps.
            </p>

        </div>


        <div class="register-card">


            <?php if ($success): ?>
                <div style="margin-bottom:20px;padding:14px;border-radius:10px;background:rgba(56,211,159,0.10);border:1px solid rgba(56,211,159,0.25);color:#38d39f;font-size:12px;">
                    <i class="fa-solid fa-circle-check"></i>
                    <?= htmlspecialchars($success) ?>
                    <br><a href="login.html" style="display:inline-block;margin-top:8px;color:#8eb8ff;">Go to Sign In →</a>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div style="margin-bottom:20px;padding:14px;border-radius:10px;background:rgba(255,82,82,0.10);border:1px solid rgba(255,82,82,0.25);color:#ff8a8a;font-size:12px;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form id="registerForm" method="POST" action="register.php">
                <?= csrf_field() ?>


                <!-- PERSONAL INFORMATION -->

                <div class="form-section">

                    <div class="form-section-title">

                        <span>01</span>

                        <h3>Personal Information</h3>

                    </div>


                    <div class="form-grid">

                        <div class="form-group">

                            <label>First Name</label>

                            <div class="input-wrapper">

                                <i class="fa-solid fa-user"></i>

                                <input
                                    type="text"
                                    id="firstName" name="firstName"
                                    placeholder="First name"
                                    required
                                >

                            </div>

                        </div>


                        <div class="form-group">

                            <label>Last Name</label>

                            <div class="input-wrapper">

                                <i class="fa-solid fa-user"></i>

                                <input
                                    type="text"
                                    id="lastName" name="lastName"
                                    placeholder="Last name"
                                    required
                                >

                            </div>

                        </div>


                        <div class="form-group">

                            <label>Email Address</label>

                            <div class="input-wrapper">

                                <i class="fa-solid fa-envelope"></i>

                                <input
                                    type="email"
                                    id="registerEmail" name="registerEmail"
                                    placeholder="you@example.com"
                                    required
                                >

                            </div>

                        </div>


                        <div class="form-group">

                            <label>Phone Number</label>

                            <div class="input-wrapper">

                                <i class="fa-solid fa-phone"></i>

                                <input
                                    type="tel"
                                    id="phone" name="phone"
                                    placeholder="+91 98765 43210"
                                    required
                                >

                            </div>

                        </div>


                        <div class="form-group">

                            <label>Date of Birth</label>

                            <div class="input-wrapper">

                                <i class="fa-solid fa-calendar"></i>

                                <input
                                    type="date"
                                    id="dob" name="dob"
                                    required
                                >

                            </div>

                        </div>


                        <div class="form-group">

                            <label>Account Type</label>

                            <div class="input-wrapper">

                                <i class="fa-solid fa-wallet"></i>

                                <select id="accountType" name="accountType" required>

                                    <option value="">
                                        Select account
                                    </option>

                                    <option value="savings">
                                        Savings Account
                                    </option>

                                    <option value="current">
                                        Current Account
                                    </option>

                                </select>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- SECURITY -->

                <div class="form-section">

                    <div class="form-section-title">

                        <span>02</span>

                        <h3>Account Security</h3>

                    </div>


                    <div class="form-grid">

                        <div class="form-group">

                            <label>Password</label>

                            <div class="input-wrapper">

                                <i class="fa-solid fa-lock"></i>

                                <input
                                    type="password"
                                    id="registerPassword" name="registerPassword"
                                    placeholder="Create password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="toggleRegisterPassword"
                                >
                                    <i class="fa-solid fa-eye"></i>
                                </button>

                            </div>

                            <div class="password-strength">
                                <div
                                    class="password-strength-bar"
                                    id="strengthBar">
                                </div>
                            </div>

                            <div class="password-hint">
                                Use at least 8 characters with letters and numbers.
                            </div>

                        </div>


                        <div class="form-group">

                            <label>Confirm Password</label>

                            <div class="input-wrapper">

                                <i class="fa-solid fa-lock"></i>

                                <input
                                    type="password"
                                    id="confirmPassword" name="confirmPassword"
                                    placeholder="Confirm password"
                                    required
                                >

                            </div>

                        </div>

                    </div>

                </div>


                <!-- TERMS -->

                <label class="terms">

                    <input
                        type="checkbox"
                        id="terms" name="terms"
                        required
                    >

                    <span>
                        I agree to the
                        <a href="#">Terms & Conditions</a>
                        and
                        <a href="#">Privacy Policy</a>
                        of NexaBank.
                    </span>

                </label>


                <button
                    type="submit"
                    class="primary-btn register-submit"
                >

                    Create Account

                    <i class="fa-solid fa-arrow-right"></i>

                </button>


            </form>


            <div class="login-link">

                Already have an account?

                <a href="login.html">
                    Sign in
                </a>

            </div>


            <div class="security-note">

                <i class="fa-solid fa-shield-halved"></i>

                <span>
                    Your personal information is protected with secure encryption.
                </span>

            </div>

        </div>

    </div>

</main>


<script>

    const password =
        document.getElementById("registerPassword");

    const togglePassword =
        document.getElementById("toggleRegisterPassword");

    const strengthBar =
        document.getElementById("strengthBar");


    togglePassword.addEventListener("click", function () {

        if (password.type === "password") {

            password.type = "text";

            this.innerHTML =
                '<i class="fa-solid fa-eye-slash"></i>';

        } else {

            password.type = "password";

            this.innerHTML =
                '<i class="fa-solid fa-eye"></i>';

        }

    });


    password.addEventListener("input", function () {

        const value = password.value;

        let strength = 0;

        if (value.length >= 8)
            strength++;

        if (/[A-Z]/.test(value))
            strength++;

        if (/[0-9]/.test(value))
            strength++;

        if (/[^A-Za-z0-9]/.test(value))
            strength++;


        if (strength === 0) {
            strengthBar.style.width = "0";
        }

        else if (strength === 1) {
            strengthBar.style.width = "25%";
        }

        else if (strength === 2) {
            strengthBar.style.width = "50%";
        }

        else if (strength === 3) {
            strengthBar.style.width = "75%";
        }

        else {
            strengthBar.style.width = "100%";
        }

    });



