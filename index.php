
<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    header('Location: ' . ($role === 'petugas' ? 'teknisi.php' : ($role === 'pelanggan' ? 'portal_pelanggan.php' : 'dashboard.php')));
} else {
    header('Location: login.php');
}

exit;

$pelanggan = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RumahCare - Tes Database</title>
</head>
<body>
    <h1>RumahCare</h1>
    <p>Koneksi database berhasil.</p>

    <h2>Data Pelanggan</h2>
    <ul>
        <?php foreach ($pelanggan as $item): ?>
            <li>
                <?= htmlspecialchars($item['nama'], ENT_QUOTES, 'UTF-8') ?>
                —
                <?= htmlspecialchars($item['nomor_telepon'], ENT_QUOTES, 'UTF-8') ?>
            </li>
        <?php endforeach; ?>
    </ul>
</body>
</html>
