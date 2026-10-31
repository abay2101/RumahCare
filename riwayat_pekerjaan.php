<?php
require_once __DIR__ . '/includes/auth.php';
require_role('petugas');
require_once __DIR__ . '/config/database.php';

$technicianId = (int) ($_SESSION['id_petugas'] ?? 0);
if ($technicianId < 1) {
    http_response_code(403);
    exit('Akun petugas belum terhubung dengan data teknisi. Hubungi administrator.');
}

$status = $_GET['status'] ?? '';
if (!is_string($status) || !in_array($status, ['', 'Selesai', 'Dibatalkan'], true)) {
    $status = '';
}
$whereStatus = $status !== '' ? ' AND p.status_pekerjaan = ?' : '';
$stmt = $pdo->prepare(
    "SELECT j.id_jadwal, j.id_pesanan, j.waktu_mulai,
            p.status_pekerjaan, p.waktu_status_akhir, p.alamat_layanan,
            c.nama AS nama_pelanggan,
            GROUP_CONCAT(DISTINCT l.nama_layanan ORDER BY l.nama_layanan SEPARATOR ', ') AS layanan
     FROM jadwal j
     JOIN pesanan p ON p.id_pesanan = j.id_pesanan
     JOIN pelanggan c ON c.id_pelanggan = p.id_pelanggan
     LEFT JOIN detail_pesanan dp ON dp.id_pesanan = p.id_pesanan
     LEFT JOIN layanan l ON l.id_layanan = dp.id_layanan
     WHERE j.id_petugas = ? AND p.status_pekerjaan IN ('Selesai', 'Dibatalkan') $whereStatus
     GROUP BY j.id_jadwal
     ORDER BY COALESCE(p.waktu_status_akhir, j.waktu_mulai) DESC, j.id_jadwal DESC"
);
$stmt->execute($status !== '' ? [$technicianId, $status] : [$technicianId]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$activePage = 'riwayat_pekerjaan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Riwayat Pekerjaan | RumahCare</title><link rel="stylesheet" href="assets/css/app.css?v=teknisi-20261031"><script src="assets/js/sidebar.js" defer></script>
</head>
<body><div class="app-shell"><?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content">
<header class="page-header"><div><p class="breadcrumb">RumahCare / Portal Teknisi</p><h1>Riwayat Pekerjaan</h1></div></header>
<section class="table-panel technician-jobs"><div class="panel-header"><h2>Pekerjaan Selesai dan Dibatalkan</h2><p>Daftar pekerjaan yang sudah tidak aktif. Detail hanya dapat dilihat.</p></div>
<form class="toolbar" method="get" action="riwayat_pekerjaan.php">
<label for="filterStatus">Status</label>
<select id="filterStatus" name="status" onchange="this.form.submit()">
<option value="" <?= $status === '' ? 'selected' : '' ?>>Semua status</option>
<option value="Selesai" <?= $status === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
<option value="Dibatalkan" <?= $status === 'Dibatalkan' ? 'selected' : '' ?>>Dibatalkan</option>
</select><span class="result-count"><?= count($jobs) ?> pekerjaan</span>
</form>
<?php if (!$jobs): ?><div class="empty">Belum ada riwayat pekerjaan untuk filter ini.</div><?php else: ?><div class="technician-job-list">
<?php foreach ($jobs as $job): $statusTime = $job['waktu_status_akhir'] ?: null; ?>
<article class="technician-job"><div class="job-date"><strong><?= htmlspecialchars($statusTime ? date('d M', strtotime($statusTime)) : '—', ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($statusTime ? date('H:i', strtotime($statusTime)) : 'Tanpa waktu', ENT_QUOTES, 'UTF-8') ?></span></div><div class="job-info"><div><strong>Pesanan #<?= (int) $job['id_pesanan'] ?></strong><span class="status-pill"><?= htmlspecialchars($job['status_pekerjaan'], ENT_QUOTES, 'UTF-8') ?></span></div><h3><?= htmlspecialchars($job['layanan'] ?: 'Layanan RumahCare', ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars($job['nama_pelanggan'] . ' · ' . $job['alamat_layanan'], ENT_QUOTES, 'UTF-8') ?></p><p class="muted">Waktu selesai/batal: <?= htmlspecialchars($statusTime ? date('d-m-Y H:i', strtotime($statusTime)) : 'Belum tercatat', ENT_QUOTES, 'UTF-8') ?></p></div><a class="btn btn-secondary" href="pekerjaan.php?id=<?= (int) $job['id_jadwal'] ?>">Lihat Detail →</a></article>
<?php endforeach; ?></div><?php endif; ?>
</section>
</main></div></body></html>
