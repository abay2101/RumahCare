<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';
$now = time();

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

// Hanya menghitung percobaan dalam 5 menit terakhir.
$_SESSION['login_attempts'] = array_values(array_filter(
    $_SESSION['login_attempts'],
    fn ($time) => $time > $now - 300
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Permintaan tidak valid. Muat ulang halaman.';
    } elseif (count($_SESSION['login_attempts']) >= 5) {
        $error = 'Terlalu banyak percobaan. Coba lagi dalam 5 menit.';
    } else {
        $emailInput = $_POST['email'] ?? '';
        $passwordInput = $_POST['password'] ?? '';

        $email = is_string($emailInput)
            ? trim($emailInput)
            : '';

        $password = is_string($passwordInput)
            ? $passwordInput
            : '';

        $_SESSION['login_attempts'][] = $now;

        if ($email !== '' && $password !== '') {
            $stmt = $pdo->prepare(
                'SELECT id_pengguna, nama, email, password_hash, role
                 FROM pengguna
                 WHERE email = ?
                 LIMIT 1'
            );

            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify(
                $password,
                $user['password_hash']
            )) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id_pengguna'];
                $_SESSION['user_name'] = $user['nama'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                unset($_SESSION['login_attempts']);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }
        }

        $error = 'Email atau password tidak valid.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | RumahCare</title>
    <style>
        * { box-sizing: border-box; }

        :root {
            --green: #18794e;
            --green-dark: #105f3c;
            --green-soft: #e7f4ed;
            --text: #18231f;
            --muted: #6f7c76;
            --border: #e2e8e5;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            color: var(--text);
            background: #fff;
        }

        .login-page {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 45% 55%;
        }

        .brand-panel {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 12%;
            overflow: hidden;
            color: #fff;
            background: linear-gradient(145deg, #105f3c, #18794e);
        }

        .brand-panel::after {
            content: "";
            position: absolute;
            width: 360px;
            height: 360px;
            right: -200px;
            bottom: -180px;
            border: 60px solid rgba(255,255,255,.07);
            border-radius: 50%;
        }

        .brand-logo {
            position: absolute;
            top: 35px;
            left: 10%;
            font-size: 25px;
            font-weight: 750;
        }

        .brand-logo span { color: #c7f0d8; }

        .brand-panel h2 {
            max-width: 390px;
            margin: 0;
            font-size: clamp(25px, 3vw, 36px);
            line-height: 1.3;
        }

        .brand-panel > p {
            max-width: 360px;
            color: #d8eee1;
            line-height: 1.7;
            font-size: 14px;
        }

        .brand-feature {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,.25);
            font-size: 13px;
            line-height: 1.7;
        }

        .form-area {
            display: grid;
            place-items: center;
            padding: 35px 24px;
        }

        .login-card { width: min(100%, 410px); }

        .kicker {
            color: var(--green);
            font-size: 11px;
            font-weight: 750;
            letter-spacing: 1.5px;
        }

        h1 {
            margin: 10px 0 8px;
            font-size: 30px;
        }

        .subtitle {
            margin: 0 0 30px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .field {
            display: block;
            margin-bottom: 19px;
            font-size: 13px;
            font-weight: 650;
        }

        .field input {
            display: block;
            width: 100%;
            height: 45px;
            margin-top: 8px;
            padding: 0 12px;
            border: 1px solid #d8e0dc;
            border-radius: 8px;
            outline: none;
            font: inherit;
            font-weight: 400;
        }

        .field input:focus {
            border-color: #71aa8d;
            box-shadow: 0 0 0 3px #e3f1e9;
        }

        .submit-button {
            width: 100%;
            min-height: 45px;
            margin-top: 5px;
            border: 0;
            border-radius: 8px;
            color: #fff;
            background: var(--green);
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .submit-button:hover { background: var(--green-dark); }

        .error {
            margin-bottom: 18px;
            padding: 12px;
            border: 1px solid #f0caca;
            border-radius: 8px;
            color: #a12e2e;
            background: #fff0f0;
            font-size: 13px;
        }

        .footer {
            margin-top: 30px;
            color: #8a9690;
            font-size: 11px;
            text-align: center;
        }

        @media (max-width: 760px) {
            .login-page { grid-template-columns: 1fr; }
            .brand-panel { display: none; }
            .form-area { min-height: 100vh; }
        }
    </style>
</head>
<body>
<div class="login-page">
    <section class="brand-panel">
        <div class="brand-logo">Rumah<span>Care</span></div>
        <h2>Perawatan rumah, lebih mudah dan terorganisasi.</h2>
        <p>
            Kelola layanan, pesanan, jadwal, dan pekerjaan
            melalui satu sistem.
        </p>
        <div class="brand-feature">
            <strong>RumahCare Management System</strong><br>
            Sistem Informasi Jasa Perawatan Rumah
        </div>
    </section>

    <main class="form-area">
        <div class="login-card">
            <div class="kicker">RUMAHCARE MANAGEMENT</div>
            <h1>Selamat datang</h1>
            <p class="subtitle">
                Masuk menggunakan akun RumahCare Anda.
            </p>

            <?php if ($error !== ''): ?>
                <div class="error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION['csrf_token'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <label class="field">
                    Email
                    <input
                        type="email"
                        name="email"
                        value="<?= htmlspecialchars(
                            $email,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        placeholder="nama@rumahcare.id"
                        autocomplete="username"
                        maxlength="150"
                        required
                    >
                </label>

                <label class="field">
                    Password
                    <input
                        type="password"
                        name="password"
                        placeholder="Masukkan password"
                        autocomplete="current-password"
                        required
                    >
                </label>

                <button class="submit-button" type="submit">
                    Masuk ke RumahCare
                </button>
            </form>

            <div class="footer">
                &copy; <?= date('Y') ?> RumahCare
            </div>
        </div>
    </main>
</div>
</body>
</html>