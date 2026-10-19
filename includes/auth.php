<?php
// includes/auth.php

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false, // Lokal HTTP; gunakan true jika memakai HTTPS.
        'path' => '/',
    ]);

    session_start();
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function require_role(string $role): void
{
    require_login();

    if (($_SESSION['role'] ?? '') !== $role) {
        http_response_code(403);
        exit('403 - Anda tidak memiliki izin untuk mengakses halaman ini.');
    }
}