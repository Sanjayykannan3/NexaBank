<?php
session_start();

$isLoggedIn = isset($_SESSION["user_id"]);
$userName = $_SESSION["user_name"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>NexaBank — Digital Banking</title>

    <meta name="description"
          content="NexaBank - Secure, modern digital banking experience.">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: Inter, Arial, Helvetica, sans-serif;
            background: #080b14;
            color: #ffffff;
            line-height: 1.6;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* NAVBAR */

        .navbar {
            width: 100%;
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(8, 11, 20, 0.92);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .nav-container {
            max-width: 1200px;
            margin: auto;
            height: 76px;
            padding: 0 24px;

            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: linear-gradient(135deg, #5b5cff, #7c3aed);
            box-shadow: 0 8px 30px rgba(92, 92, 255, 0.3);

            font-size: 20px;
        }

        .logo span {
            color: #8b8dff;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .nav-links a {
            color: #aeb2c4;
            font-size: 14px;
            transition: 0.2s;
        }

        .nav-links a:hover {
            color: white;
        }

        .nav-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 11px 20px;
            border-radius: 10px;

            font-size: 14px;
            font-weight: 700;

            transition: 0.2s;
        }

        .btn-outline {
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.03);
            color: white;
        }

        .btn-outline:hover {
            background: rgba(255,255,255,0.08);
        }

        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white;
            box-shadow: 0 10px 30px rgba(99,102,241,0.25);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 35px rgba(99,102,241,0.35);
        }

        /* HERO */

        .hero {
            max-width: 1200px;
            margin: auto;
            padding: 105px 24px 90px;

            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 70px;
            align-items: center;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;

            padding: 7px 12px;
            border-radius: 30px;

            background: rgba(99,102,241,0.1);
            border: 1px solid rgba(99,102,241,0.25);

            color: #a5a7ff;
            font-size: 12px;
            font-weight: 700;

            margin-bottom: 24px;
        }

        .badge-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #7c7fff;
            box-shadow: 0 0 10px #7c7fff;
        }

        .hero h1 {
            font-size: clamp(45px, 6vw, 74px);
            line-height: 1.03;
            letter-spacing: -3px;
            margin-bottom: 25px;
        }

        .hero h1 .gradient {
            background: linear-gradient(90deg, #818cf8, #a78bfa, #c084fc);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .hero p {
            max-width: 620px;
            color: #a7acbd;
            font-size: 17px;
            line-height: 1.8;
            margin-bottom: 32px;
        }

        .hero-buttons {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .hero-buttons .btn {
            padding: 14px 23px;
        }

        /* BANK CARD */

        .card-area {
            display: flex;
            justify-content: center;
        }

        .bank-card {
            width: 390px;
            max-width: 100%;
            height: 245px;

            padding: 27px;

            border-radius: 24px;

            position: relative;
            overflow: hidden;

            background:
                linear-gradient(
                    135deg,
                    rgba(92,92,255,0.95),
                    rgba(124,58,237,0.92)
                );

            box-shadow:
                0 35px 80px rgba(0,0,0,0.45),
                0 0 70px rgba(99,102,241,0.18);

            transform: rotate(2deg);
        }

        .bank-card::before {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            border-radius: 50%;

            right: -100px;
            top: -100px;

            background: rgba(255,255,255,0.09);
        }

        .bank-card::after {
            content: "";
            position: absolute;
            width: 200px;
            height: 200px;
            border-radius: 50%;

            left: -80px;
            bottom: -110px;

            background: rgba(255,255,255,0.07);
        }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;

            position: relative;
            z-index: 2;
        }

        .card-name {
            font-size: 18px;
            font-weight: 800;
        }

        .card-type {
            font-size: 12px;
            opacity: 0.8;
            letter-spacing: 1px;
        }

        .chip {
            width: 47px;
            height: 36px;
            border-radius: 8px;

            background: linear-gradient(135deg,#e6d39c,#fff0bb);

            margin-top: 42px;

            position: relative;
            z-index: 2;
        }

        .card-number {
            margin-top: 24px;

            font-size: 21px;
            letter-spacing: 3px;
            font-family: monospace;

            position: relative;
            z-index: 2;
        }

        .card-bottom {
            position: absolute;
            bottom: 25px;
            left: 27px;
            right: 27px;

            display: flex;
            justify-content: space-between;

            z-index: 2;
        }

        .small-label {
            font-size: 9px;
            text-transform: uppercase;
            opacity: 0.65;
            letter-spacing: 1px;
        }

        .small-value {
            font-size: 12px;
            margin-top: 3px;
        }

        /* FEATURES */

        .section {
            max-width: 1200px;
            margin: auto;
            padding: 80px 24px;
        }

        .section-heading {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 45px;
        }

        .section-heading h2 {
            font-size: 38px;
            letter-spacing: -1.5px;
            margin-bottom: 12px;
        }

        .section-heading p {
            color: #9297aa;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .feature {
            padding: 28px;

            background: rgba(255,255,255,0.025);
            border: 1px solid rgba(255,255,255,0.08);

            border-radius: 18px;

            transition: 0.25s;
        }

        .feature:hover {
            transform: translateY(-5px);
            border-color: rgba(124,58,237,0.35);
            background: rgba(255,255,255,0.04);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            border-radius: 13px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: rgba(99,102,241,0.12);
            border: 1px solid rgba(99,102,241,0.2);

            font-size: 21px;
            margin-bottom: 20px;
        }

        .feature h3 {
            font-size: 18px;
            margin-bottom: 10px;
        }

        .feature p {
            color: #9095a8;
            font-size: 14px;
            line-height: 1.7;
        }

        /* STATS */

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;

            padding: 25px;

            border-radius: 20px;

            background: rgba(255,255,255,0.025);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .stat {
            text-align: center;
            padding: 15px;
        }

        .stat strong {
            display: block;
            font-size: 27px;
        }

        .stat span {
            color: #858b9f;
            font-size: 13px;
        }

        /* CTA */

        .cta {
            max-width: 1200px;
            margin: 30px auto 80px;
            padding: 60px 30px;

            text-align: center;

            border-radius: 25px;

            background:
                radial-gradient(
                    circle at center,
                    rgba(99,102,241,0.2),
                    rgba(255,255,255,0.02) 55%
                );

            border: 1px solid rgba(255,255,255,0.08);
        }

        .cta h2 {
            font-size: 38px;
            margin-bottom: 14px;
        }

        .cta p {
            color: #9297aa;
            margin-bottom: 25px;
        }

        /* FOOTER */

        footer {
            border-top: 1px solid rgba(255,255,255,0.07);
            padding: 30px 24px;
        }

        .footer-inner {
            max-width: 1200px;
            margin: auto;

            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 20px;
        }

        .footer-text {
            color: #70768a;
            font-size: 13px;
        }

        .footer-links {
            display: flex;
            gap: 20px;
        }

        .footer-links a {
            color: #858b9e;
            font-size: 13px;
        }

        .footer-links a:hover {
            color: white;
        }

        /* MOBILE */

        @media (max-width: 900px) {

            .nav-links {
                display: none;
            }

            .hero {
                grid-template-columns: 1fr;
                text-align: center;
                padding-top: 75px;
            }

            .hero p {
                margin-left: auto;
                margin-right: auto;
            }

            .hero-buttons {
                justify-content: center;
            }

            .features {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .card-area {
                margin-top: 20px;
            }
        }

        @media (max-width: 520px) {

            .nav-buttons .btn-outline {
                display: none;
            }

            .nav-container {
                padding: 0 16px;
            }

            .hero {
                padding-left: 18px;
                padding-right: 18px;
            }

            .hero h1 {
                font-size: 45px;
                letter-spacing: -2px;
            }

            .bank-card {
                height: 215px;
                padding: 22px;
            }

            .card-number {
                font-size: 17px;
            }

            .section {
                padding-left: 18px;
                padding-right: 18px;
            }

            .section-heading h2,
            .cta h2 {
                font-size: 30px;
            }

            .footer-inner {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>

<body>

<!-- NAVBAR -->

<nav class="navbar">
    <div class="nav-container">

        <a href="index.php" class="logo">
            <div class="logo-icon">N</div>
            Nexa<span>Bank</span>
        </a>

        <div class="nav-links">
            <a href="#features">Features</a>
            <a href="#security">Security</a>
            <a href="#about">About</a>
        </div>

        <div class="nav-buttons">

            <?php if ($isLoggedIn): ?>

                <a href="dashboard.php" class="btn btn-outline">
                    Dashboard
                </a>

                <a href="logout.php" class="btn btn-primary">
                    Logout
                </a>

            <?php else: ?>

                <a href="login.php" class="btn btn-outline">
                    Login
                </a>

                <a href="register.php" class="btn btn-primary">
                    Open Account
                </a>

            <?php endif; ?>

        </div>

    </div>
</nav>


<!-- HERO -->

<section class="hero">

    <div>

        <div class="badge">
            <span class="badge-dot"></span>
            Modern Digital Banking
        </div>

        <h1>
            Banking built for
            <span class="gradient">your future.</span>
        </h1>

        <p>
            Experience simple, secure and intelligent digital banking
            with NexaBank. Manage your money, transfer funds,
            track transactions and control your cards from one place.
        </p>

        <div class="hero-buttons">

            <?php if ($isLoggedIn): ?>

                <a href="dashboard.php" class="btn btn-primary">
                    Go to Dashboard →
                </a>

                <a href="transactions.php" class="btn btn-outline">
                    View Transactions
                </a>

            <?php else: ?>

                <a href="register.php" class="btn btn-primary">
                    Get Started →
                </a>

                <a href="login.php" class="btn btn-outline">
                    Sign In
                </a>

            <?php endif; ?>

        </div>

    </div>


    <!-- CARD -->

    <div class="card-area">

        <div class="bank-card">

            <div class="card-top">
                <div class="card-name">NexaBank</div>
                <div class="card-type">VISA</div>
            </div>

            <div class="chip"></div>

            <div class="card-number">
                •••• &nbsp; •••• &nbsp; •••• &nbsp; 4821
            </div>

            <div class="card-bottom">

                <div>
                    <div class="small-label">Card Holder</div>
                    <div class="small-value">
                        NEXABANK USER
                    </div>
                </div>

                <div>
                    <div class="small-label">Valid Thru</div>
                    <div class="small-value">
                        12/30
                    </div>
                </div>

            </div>

        </div>

    </div>

</section>


<!-- FEATURES -->

<section class="section" id="features">

    <div class="section-heading">

        <h2>Everything you need</h2>

        <p>
            A complete digital banking experience designed
            around simplicity, control and security.
        </p>

    </div>


    <div class="features">

        <div class="feature">

            <div class="feature-icon">↗</div>

            <h3>Instant Transfers</h3>

            <p>
                Transfer money between accounts and registered
                beneficiaries through a simple banking interface.
            </p>

        </div>


        <div class="feature">

            <div class="feature-icon">▣</div>

            <h3>Smart Dashboard</h3>

            <p>
                Get a clear overview of your accounts, balances,
                recent activity and financial information.
            </p>

        </div>


        <div class="feature" id="security">

            <div class="feature-icon">✓</div>

            <h3>Secure Banking</h3>

            <p>
                Authentication, transaction PIN protection,
                account controls and security-focused workflows.
            </p>

        </div>


        <div class="feature">

            <div class="feature-icon">▤</div>

            <h3>Transaction History</h3>

            <p>
                Review your account activity and keep track of
                credits, debits and transaction references.
            </p>

        </div>


        <div class="feature">

            <div class="feature-icon">◇</div>

            <h3>Card Controls</h3>

            <p>
                Manage your card settings including online,
                ATM, contactless and international usage.
            </p>

        </div>


        <div class="feature">

            <div class="feature-icon">◉</div>

            <h3>Notifications</h3>

            <p>
                Stay informed with banking notifications about
                account activity and important events.
            </p>

        </div>

    </div>

</section>


<!-- STATS -->

<section class="section" id="about">

    <div class="stats">

        <div class="stat">
            <strong>24/7</strong>
            <span>Digital Access</span>
        </div>

        <div class="stat">
            <strong>256-bit</strong>
            <span>Data Protection</span>
        </div>

        <div class="stat">
            <strong>OTP</strong>
            <span>Login Verification</span>
        </div>

        <div class="stat">
            <strong>₹</strong>
            <span>Easy Money Management</span>
        </div>

    </div>

</section>


<!-- CTA -->

<section class="cta">

    <h2>
        Ready to bank smarter?
    </h2>

    <p>
        Create your NexaBank account and explore your digital
        banking dashboard.
    </p>

    <?php if ($isLoggedIn): ?>

        <a href="dashboard.php" class="btn btn-primary">
            Open Dashboard →
        </a>

    <?php else: ?>

        <a href="register.php" class="btn btn-primary">
            Open Your Account →
        </a>

    <?php endif; ?>

</section>


<!-- FOOTER -->

<footer>

    <div class="footer-inner">

        <div class="footer-text">
            © <?php echo date("Y"); ?> NexaBank.
            Digital banking simulation project.
        </div>

        <div class="footer-links">
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>

            <?php if ($isLoggedIn): ?>
                <a href="dashboard.php">Dashboard</a>
            <?php endif; ?>
        </div>

    </div>

</footer>

</body>
</html>