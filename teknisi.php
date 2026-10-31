<?php
require_once __DIR__ . '/includes/auth.php';
require_role('petugas');
require_once __DIR__ . '/config/database.php';

$technicianId = (int) ($_SESSION['id_petugas'] ?? 0);
if ($technicianId < 1) {
    http_response_code(403);
    exit('Akun petugas belum terhubung dengan data teknisi. Hubungi administrator.');
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-01 00:00:00', strtotime('+1 month'));
$summaryStmt = $pdo->prepare(
    "SELECT
        SUM(p.waktu_layanan >= ? AND p.waktu_layanan < ?) AS tugas_bulan_ini,
        SUM(DATE(p.waktu_layanan) = ? AND p.status_pekerjaan IN ('Dijadwalkan', 'Diproses')) AS tugas_hari_ini,
        SUM(p.status_pekerjaan = 'Diproses') AS sedang_berlangsung,
        SUM(p.status_pekerjaan = 'Selesai' AND p.waktu_status_akhir >= ? AND p.waktu_status_akhir < ?) AS selesai_bulan_ini
     FROM jadwal j JOIN pesanan p ON p.id_pesanan = j.id_pesanan WHERE j.id_petugas = ?"
);
$summaryStmt->execute([$monthStart, $monthEnd, $today, $monthStart, $monthEnd, $technicianId]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stmt = $pdo->prepare(
    "SELECT j.id_jadwal, j.id_pesanan, j.waktu_mulai,
            j.status_jadwal, p.status_pekerjaan, p.alamat_layanan,
            c.nama AS nama_pelanggan,
            GROUP_CONCAT(DISTINCT l.nama_layanan ORDER BY l.nama_layanan SEPARATOR ', ') AS layanan
     FROM jadwal j
     JOIN pesanan p ON p.id_pesanan = j.id_pesanan
     JOIN pelanggan c ON c.id_pelanggan = p.id_pelanggan
     LEFT JOIN detail_pesanan dp ON dp.id_pesanan = p.id_pesanan
     LEFT JOIN layanan l ON l.id_layanan = dp.id_layanan
     WHERE j.id_petugas = ? AND p.status_pekerjaan IN ('Dijadwalkan', 'Diproses')
     GROUP BY j.id_jadwal
     ORDER BY (p.status_pekerjaan = 'Diproses') DESC, j.waktu_mulai ASC"
);
$stmt->execute([$technicianId]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$activePage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pekerjaan Saya | RumahCare</title><link rel="stylesheet" href="assets/css/app.css?v=teknisi-20261031"><script src="assets/js/sidebar.js" defer></script>
</head>
<body><div class="app-shell"><?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content">
<header class="page-header"><div><p class="breadcrumb">Selamat bekerja, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Teknisi', ENT_QUOTES, 'UTF-8') ?></p><h1>Pekerjaan Saya</h1></div><span class="muted"><?= htmlspecialchars(date('d-m-Y'), ENT_QUOTES, 'UTF-8') ?></span></header>
<section class="dashboard-grid technician-stats">
<article class="stat-card"><div class="stat-label">Ditugaskan bulan ini</div><div class="stat-value"><?= (int) ($summary['tugas_bulan_ini'] ?? 0) ?></div></article>
<article class="stat-card"><div class="stat-label">Jadwal hari ini</div><div class="stat-value"><?= (int) ($summary['tugas_hari_ini'] ?? 0) ?></div></article>
<article class="stat-card"><div class="stat-label">Sedang dikerjakan</div><div class="stat-value"><?= (int) ($summary['sedang_berlangsung'] ?? 0) ?></div></article>
<article class="stat-card"><div class="stat-label">Selesai bulan ini</div><div class="stat-value"><?= (int) ($summary['selesai_bulan_ini'] ?? 0) ?></div></article>
</section>
<section class="table-panel technician-jobs" id="daftar-pekerjaan"><div class="panel-header"><h2>Pekerjaan Aktif</h2><p>Pekerjaan yang sedang berjalan ditampilkan lebih dulu, diikuti jadwal berikutnya.</p></div>
<?php if (!$jobs): ?><div class="empty">Tidak ada pekerjaan aktif saat ini.</div><?php else: ?><div class="technician-job-list">
<?php foreach ($jobs as $job): ?><article class="technician-job"><div class="job-date"><strong><?= htmlspecialchars(date('d M', strtotime($job['waktu_mulai'])), ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars(date('H:i', strtotime($job['waktu_mulai'])), ENT_QUOTES, 'UTF-8') ?></span></div><div class="job-info"><div><strong>Pesanan #<?= (int) $job['id_pesanan'] ?></strong> <span class="status-pill"><?= htmlspecialchars($job['status_pekerjaan'], ENT_QUOTES, 'UTF-8') ?></span></div><h3><?= htmlspecialchars($job['layanan'] ?: 'Layanan RumahCare', ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars($job['nama_pelanggan'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($job['alamat_layanan'], ENT_QUOTES, 'UTF-8') ?></p></div><a class="btn btn-secondary" href="pekerjaan.php?id=<?= (int) $job['id_jadwal'] ?>">Lihat Detail →</a></article><?php endforeach; ?>
</div><?php endif; ?></section>
</main></div></body></html>
