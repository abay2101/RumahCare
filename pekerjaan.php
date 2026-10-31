<?php
require_once __DIR__ . '/includes/auth.php';
require_role('petugas');
require_once __DIR__ . '/config/database.php';

$technicianId = (int) ($_SESSION['id_petugas'] ?? 0);
if ($technicianId < 1) {
    http_response_code(403);
    exit('Akun petugas belum terhubung dengan data teknisi. Hubungi administrator.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h_pekerjaan(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    exit('Pekerjaan tidak ditemukan.');
}
$success = isset($_GET['saved']) ? 'Status pekerjaan berhasil diperbarui.' : '';
$error = '';
$allowedStatuses = ['Diproses', 'Selesai'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $newStatus = $_POST['status_pekerjaan'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman.');
    }
    if (!is_string($newStatus) || !in_array($newStatus, $allowedStatuses, true)) {
        $error = 'Pilih status pekerjaan yang valid.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT j.id_jadwal, j.id_pesanan, j.status_jadwal, p.status_pekerjaan
                 FROM jadwal j JOIN pesanan p ON p.id_pesanan = j.id_pesanan
                 WHERE j.id_jadwal = ? AND j.id_petugas = ? FOR UPDATE'
            );
            $stmt->execute([$id, $technicianId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                throw new RuntimeException('Pekerjaan tidak ditemukan pada akun teknisi ini.');
            }
            if (in_array($job['status_pekerjaan'], ['Selesai', 'Dibatalkan'], true)) {
                throw new RuntimeException('Pekerjaan yang sudah selesai atau dibatalkan tidak dapat diperbarui.');
            }
            if ($newStatus === 'Selesai' && $job['status_pekerjaan'] !== 'Diproses') {
                throw new RuntimeException('Mulai pekerjaan dengan status Diproses sebelum menandainya selesai.');
            }

            $stmt = $pdo->prepare(
                "UPDATE pesanan
                 SET status_pekerjaan = ?,
                     waktu_status_akhir = CASE
                         WHEN ? IN ('Selesai', 'Dibatalkan') THEN COALESCE(waktu_status_akhir, NOW())
                         ELSE NULL
                     END
                 WHERE id_pesanan = ?"
            );
            $stmt->execute([$newStatus, $newStatus, $job['id_pesanan']]);
            $scheduleStatus = $newStatus === 'Selesai' ? 'Selesai' : 'Berlangsung';
            $stmt = $pdo->prepare('UPDATE jadwal SET status_jadwal = ? WHERE id_jadwal = ?');
            $stmt->execute([$scheduleStatus, $id]);
            $pdo->commit();
            header('Location: pekerjaan.php?id=' . $id . '&saved=1');
            exit;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $error = 'Status gagal diperbarui. Coba lagi.';
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT j.id_jadwal, j.id_pesanan, j.waktu_mulai,
            j.status_jadwal, p.status_pekerjaan, p.waktu_status_akhir, p.alamat_layanan,
            c.nama AS nama_pelanggan, c.nomor_telepon,
            GROUP_CONCAT(DISTINCT l.nama_layanan ORDER BY l.nama_layanan SEPARATOR ', ') AS layanan,
            GROUP_CONCAT(DISTINCT l.deskripsi SEPARATOR ' / ') AS deskripsi_layanan
     FROM jadwal j
     JOIN pesanan p ON p.id_pesanan = j.id_pesanan
     JOIN pelanggan c ON c.id_pelanggan = p.id_pelanggan
     LEFT JOIN detail_pesanan dp ON dp.id_pesanan = p.id_pesanan
     LEFT JOIN layanan l ON l.id_layanan = dp.id_layanan
     WHERE j.id_jadwal = ? AND j.id_petugas = ?
     GROUP BY j.id_jadwal"
);
$stmt->execute([$id, $technicianId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    http_response_code(404);
    exit('Pekerjaan tidak ditemukan pada akun teknisi ini.');
}
$activePage = 'pekerjaan';
$canUpdate = !in_array($job['status_pekerjaan'], ['Selesai', 'Dibatalkan'], true);
?>
<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Detail Pekerjaan | RumahCare</title><link rel="stylesheet" href="assets/css/app.css?v=teknisi-20261031"><script src="assets/js/sidebar.js" defer></script></head>
<body><div class="app-shell"><?php require __DIR__ . '/includes/sidebar.php'; ?><main class="main-content">
<header class="page-header"><div><p class="breadcrumb">RumahCare / Pekerjaan Saya</p><h1>Detail Pekerjaan</h1></div></header>
<?php if ($success): ?><div class="notice success"><?= h_pekerjaan($success) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= h_pekerjaan($error) ?></div><?php endif; ?>
<a class="back-link" href="<?= in_array($job['status_pekerjaan'], ['Selesai', 'Dibatalkan'], true) ? 'riwayat_pekerjaan.php' : 'teknisi.php#daftar-pekerjaan' ?>">← Kembali ke <?= in_array($job['status_pekerjaan'], ['Selesai', 'Dibatalkan'], true) ? 'Riwayat Pekerjaan' : 'Pekerjaan Saya' ?></a>
<div class="job-detail-layout"><section class="form-card job-detail-card"><div class="panel-header"><p class="job-order-id">Pesanan #<?= (int) $job['id_pesanan'] ?></p><h2><?= h_pekerjaan($job['layanan'] ?: 'Layanan RumahCare') ?></h2><span class="status-pill"><?= h_pekerjaan($job['status_pekerjaan']) ?></span></div>
<div class="job-detail-grid"><section><h3>Informasi Pelanggan</h3><dl><dt>Nama</dt><dd><?= h_pekerjaan($job['nama_pelanggan']) ?></dd><dt>Nomor telepon</dt><dd><?= h_pekerjaan($job['nomor_telepon']) ?></dd></dl></section><section><h3>Informasi Layanan</h3><dl><dt>Layanan</dt><dd><?= h_pekerjaan($job['layanan'] ?: '-') ?></dd><dt>Catatan layanan</dt><dd><?= h_pekerjaan($job['deskripsi_layanan'] ?: '-') ?></dd></dl></section><section class="job-location"><h3>Lokasi & Jadwal</h3><dl><dt>Alamat</dt><dd><?= h_pekerjaan($job['alamat_layanan']) ?></dd><dt>Waktu mulai</dt><dd><?= h_pekerjaan(date('d-m-Y H:i', strtotime($job['waktu_mulai']))) ?></dd></dl></section></div></section>
<section class="form-card status-update-card"><?php if ($canUpdate): ?><div class="panel-header"><h2>Perbarui Status</h2><p>Simpan perkembangan pekerjaan Anda.</p></div><form method="post" action="pekerjaan.php?id=<?= (int) $id ?>"><input type="hidden" name="csrf_token" value="<?= h_pekerjaan($_SESSION['csrf_token']) ?>"><label class="field">Status pekerjaan<select name="status_pekerjaan" required><option value="Diproses" <?= $job['status_pekerjaan'] === 'Diproses' ? 'selected' : '' ?>>Diproses</option><option value="Selesai" <?= $job['status_pekerjaan'] === 'Selesai' ? 'selected' : '' ?>>Selesai</option></select></label><div class="form-actions"><button class="btn btn-primary" type="submit">Simpan Status</button></div></form><?php else: ?><div class="panel-header"><h2>Pekerjaan Ditutup</h2><p>Status akhir pekerjaan sudah dikonfirmasi.</p></div><div class="job-closed-body"><strong><?= h_pekerjaan($job['status_pekerjaan']) ?></strong><?php if (!empty($job['waktu_status_akhir'])): ?><p class="muted">Waktu <?= $job['status_pekerjaan'] === 'Selesai' ? 'selesai' : 'pembatalan' ?>: <?= h_pekerjaan(date('d-m-Y H:i', strtotime($job['waktu_status_akhir']))) ?></p><?php endif; ?></div><?php endif; ?></section></div>
</main></div></body></html>
