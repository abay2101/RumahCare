<?php
require_once __DIR__ . '/includes/auth.php';
require_role('pelanggan');
require_once __DIR__ . '/config/database.php';

$customerId = (int) ($_SESSION['id_pelanggan'] ?? 0);
if ($customerId < 1) {
    http_response_code(403);
    exit('Akun belum terhubung dengan data pelanggan. Hubungi RumahCare.');
}

$tab = $_GET['tab'] ?? 'aktif';
if (!is_string($tab) || !in_array($tab, ['aktif', 'riwayat'], true)) {
    $tab = 'aktif';
}
$history = $tab === 'riwayat';
$statusCondition = $history
    ? "p.status_pekerjaan IN ('Selesai', 'Dibatalkan')"
    : "p.status_pekerjaan IN ('Dijadwalkan', 'Diproses')";

$stmt = $pdo->prepare(
    "SELECT p.id_pesanan, p.waktu_layanan, p.waktu_status_akhir,
            p.total_biaya, p.status_pekerjaan, p.alamat_layanan,
            j.waktu_mulai, t.nama AS nama_teknisi,
            GROUP_CONCAT(DISTINCT l.nama_layanan ORDER BY l.nama_layanan SEPARATOR ', ') AS layanan,
            COALESCE(pb.total_dibayar, 0) AS total_dibayar
     FROM pesanan p
     LEFT JOIN jadwal j ON j.id_pesanan = p.id_pesanan
     LEFT JOIN petugas t ON t.id_petugas = j.id_petugas
     LEFT JOIN detail_pesanan dp ON dp.id_pesanan = p.id_pesanan
     LEFT JOIN layanan l ON l.id_layanan = dp.id_layanan
     LEFT JOIN (
        SELECT id_pesanan, SUM(jumlah_bayar) AS total_dibayar
        FROM pembayaran GROUP BY id_pesanan
     ) pb ON pb.id_pesanan = p.id_pesanan
     WHERE p.id_pelanggan = ? AND $statusCondition
     GROUP BY p.id_pesanan
     ORDER BY " . ($history ? 'p.waktu_status_akhir DESC, p.id_pesanan DESC' : 'COALESCE(j.waktu_mulai, p.waktu_layanan) ASC, p.id_pesanan ASC')
);
$stmt->execute([$customerId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$activePage = 'portal_pelanggan';

function h_portal(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pesanan Saya | RumahCare</title><link rel="stylesheet" href="assets/css/app.css?v=portal-pelanggan-20261031"><script src="assets/js/sidebar.js" defer></script>
</head>
<body><div class="app-shell"><?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content">
<header class="page-header"><div><p class="breadcrumb">RumahCare / Portal Pelanggan</p><h1>Pesanan Saya</h1></div><span class="muted">Halo, <?= h_portal($_SESSION['user_name'] ?? 'Pelanggan') ?></span></header>
<section class="dashboard-grid customer-order-stats">
<article class="stat-card"><div class="stat-label">Pesanan aktif</div><div class="stat-value"><?php
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pesanan WHERE id_pelanggan = ? AND status_pekerjaan IN ('Dijadwalkan', 'Diproses')");
$countStmt->execute([$customerId]); echo (int) $countStmt->fetchColumn();
?></div><div class="stat-note">Menunggu atau sedang dikerjakan</div></article>
<article class="stat-card"><div class="stat-label">Riwayat pesanan</div><div class="stat-value"><?php
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pesanan WHERE id_pelanggan = ? AND status_pekerjaan IN ('Selesai', 'Dibatalkan')");
$countStmt->execute([$customerId]); echo (int) $countStmt->fetchColumn();
?></div><div class="stat-note">Pesanan selesai atau dibatalkan</div></article>
</section>
<section class="table-panel customer-orders">
<div class="panel-header"><h2><?= $history ? 'Riwayat Pesanan' : 'Pesanan Aktif' ?></h2><p>Lihat layanan, jadwal, teknisi, biaya, dan status pembayaran pesanan Anda.</p></div>
<nav class="customer-order-tabs" aria-label="Filter pesanan">
<a class="btn <?= !$history ? 'btn-primary' : 'btn-secondary' ?>" href="portal_pelanggan.php?tab=aktif">Pesanan Aktif</a>
<a class="btn <?= $history ? 'btn-primary' : 'btn-secondary' ?>" href="portal_pelanggan.php?tab=riwayat">Riwayat</a>
</nav>
<?php if (!$orders): ?><div class="empty"><?= $history ? 'Belum ada riwayat pesanan.' : 'Belum ada pesanan aktif.' ?></div><?php else: ?>
<div class="table-scroll"><table class="customer-order-table">
<thead><tr><th>Pesanan</th><th>Layanan</th><th><?= $history ? 'Jadwal' : 'Jadwal layanan' ?></th><?php if ($history): ?><th>Waktu selesai/batal</th><?php endif; ?><th>Teknisi</th><th>Biaya</th><th>Pembayaran</th><th>Status</th></tr></thead>
<tbody><?php foreach ($orders as $order):
    $paid = (float) $order['total_dibayar'];
    $total = (float) $order['total_biaya'];
    $paymentLabel = $paid >= $total ? 'Lunas' : ($paid > 0 ? 'Sebagian' : 'Belum dibayar');
    $serviceTime = $order['waktu_mulai'] ?: $order['waktu_layanan'];
?><tr>
<td><strong>#<?= (int) $order['id_pesanan'] ?></strong></td>
<td><?= h_portal($order['layanan'] ?: 'Layanan RumahCare') ?></td>
<td><?= h_portal(date('d-m-Y H:i', strtotime($serviceTime))) ?><br><span class="muted"><?= h_portal($order['alamat_layanan']) ?></span></td>
<?php if ($history): ?><td><?= $order['waktu_status_akhir'] ? h_portal(date('d-m-Y H:i', strtotime($order['waktu_status_akhir']))) : '—' ?></td><?php endif; ?>
<td><?= h_portal($order['nama_teknisi'] ?: 'Belum ditentukan') ?></td>
<td><strong>Rp <?= number_format($total, 0, ',', '.') ?></strong></td>
<td><?= h_portal($paymentLabel) ?><?php if ($paid > 0 && $paid < $total): ?><br><span class="muted">Rp <?= number_format($paid, 0, ',', '.') ?> dibayar</span><?php endif; ?></td>
<td><span class="status-pill <?= $order['status_pekerjaan'] === 'Dibatalkan' ? 'customer-status-cancelled' : '' ?>"><?= h_portal($order['status_pekerjaan']) ?></span></td>
</tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<div class="table-footer">Informasi pesanan ini hanya dapat dilihat oleh akun pelanggan yang terkait.</div>
</section>
</main></div></body></html>
