<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function count_rows(PDO $pdo, string $table): int {
    // Table names here are fixed constants, not user input.
    return (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

$activePage = 'dashboard';
$userName = $_SESSION['user_name'] ?? 'Pengguna';
$role = $_SESSION['role'] ?? '';
$roleLabel = $role === 'admin' ? 'Admin / Pengelola' : 'Petugas / Teknisi';
$counts = [
    'pelanggan' => count_rows($pdo, 'pelanggan'),
    'layanan' => count_rows($pdo, 'layanan'),
    'pesanan' => count_rows($pdo, 'pesanan'),
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | RumahCare</title>
<link rel="stylesheet" href="assets/css/app.css">
<script src="assets/js/sidebar.js" defer></script>
</head>
<body>
<div class="app-shell">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content">
  <header class="page-header">
    <div><p class="breadcrumb">RumahCare / Overview</p><h1>Dashboard</h1></div>
    <span class="muted"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
  </header>

  <div class="dashboard-grid">
    <section class="stat-card">
      <div class="stat-label">Total Pelanggan</div>
      <div class="stat-value"><?= $counts['pelanggan'] ?></div>
      <div class="stat-note">Data pelanggan terdaftar</div>
    </section>
    <section class="stat-card">
      <div class="stat-label">Total Layanan</div>
      <div class="stat-value"><?= $counts['layanan'] ?></div>
      <div class="stat-note">Jenis layanan tersedia</div>
    </section>
    <section class="stat-card">
      <div class="stat-label">Total Pesanan</div>
      <div class="stat-value"><?= $counts['pesanan'] ?></div>
      <div class="stat-note">Pesanan tersimpan di database</div>
    </section>
  </div>

  <section class="dashboard-section">
    <h2>Selamat datang, <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></h2>
    <p>Gunakan navigasi di sebelah kiri untuk mengelola data RumahCare. Ringkasan di atas dibaca langsung dari database.</p>
    <div class="quick-links">
      <a class="btn btn-primary" href="pelanggan.php">Kelola Pelanggan</a>
      <a class="btn btn-secondary" href="layanan.php">Lihat Layanan</a>
      <a class="btn btn-secondary" href="pesanan.php">Lihat Pesanan</a>
    </div>
  </section>
</main>
</div>
</body>
</html>
