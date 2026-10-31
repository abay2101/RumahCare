<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h_pembayaran(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$methods = ['Tunai', 'Transfer Bank', 'QRIS'];
$action = $_GET['action'] ?? 'list';
$success = isset($_GET['saved']) ? 'Pembayaran berhasil dicatat.' : '';
$error = '';
$selectedOrder = filter_input(INPUT_GET, 'order', FILTER_VALIDATE_INT) ?: 0;
$amountInput = '';
$methodInput = '';
$dateInput = date('Y-m-d\TH:i');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman.');
    }

    $selectedOrder = filter_var($_POST['id_pesanan'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $amountInput = trim((string) ($_POST['jumlah_bayar'] ?? ''));
    $methodInput = (string) ($_POST['metode_pembayaran'] ?? '');
    $dateInput = (string) ($_POST['tanggal_pembayaran'] ?? '');
    $amount = filter_var($amountInput, FILTER_VALIDATE_FLOAT);
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $dateInput);
    $dateErrors = DateTime::getLastErrors();

    if ($selectedOrder < 1) {
        $error = 'Pilih pesanan yang akan dibayar.';
    } elseif ($amount === false || $amount <= 0 || $amount > 9999999999.99) {
        $error = 'Jumlah pembayaran harus lebih dari Rp 0.';
    } elseif (!in_array($methodInput, $methods, true)) {
        $error = 'Pilih metode pembayaran yang tersedia.';
    } elseif (!$date || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))) {
        $error = 'Tanggal pembayaran tidak valid.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id_pesanan, total_biaya, status_pekerjaan FROM pesanan WHERE id_pesanan = ? FOR UPDATE');
            $stmt->execute([$selectedOrder]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new DomainException('Pesanan tidak ditemukan.');
            }
            if ($order['status_pekerjaan'] === 'Dibatalkan') {
                throw new DomainException('Pembayaran baru tidak dapat dicatat untuk pesanan yang dibatalkan.');
            }

            $stmt = $pdo->prepare('SELECT COALESCE(SUM(jumlah_bayar), 0) FROM pembayaran WHERE id_pesanan = ?');
            $stmt->execute([$selectedOrder]);
            $paidCents = (int) round((float) $stmt->fetchColumn() * 100);
            $totalCents = (int) round((float) $order['total_biaya'] * 100);
            $amountCents = (int) round((float) $amount * 100);
            if ($amountCents < 1) {
                throw new DomainException('Jumlah pembayaran minimal Rp 0,01.');
            }
            $remainingCents = $totalCents - $paidCents;
            if ($remainingCents < 1) {
                throw new DomainException('Pesanan ini sudah lunas.');
            }
            if ($amountCents > $remainingCents) {
                throw new DomainException('Jumlah pembayaran melebihi sisa tagihan Rp ' . number_format($remainingCents / 100, 2, ',', '.') . '.');
            }

            $newStatus = $amountCents === $remainingCents ? 'Lunas' : 'Sebagian';
            $stmt = $pdo->prepare('INSERT INTO pembayaran (id_pesanan, tanggal_pembayaran, jumlah_bayar, metode_pembayaran, status_pembayaran) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$selectedOrder, $date->format('Y-m-d H:i:s'), number_format($amountCents / 100, 2, '.', ''), $methodInput, $newStatus]);
            $stmt = $pdo->prepare('UPDATE pembayaran SET status_pembayaran = ? WHERE id_pesanan = ?');
            $stmt->execute([$newStatus, $selectedOrder]);
            $pdo->commit();
            header('Location: pembayaran.php?saved=1');
            exit;
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
            $action = 'create';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            $error = 'Pembayaran gagal dicatat. Periksa data lalu coba kembali.';
            $action = 'create';
        }
    }
    if ($error !== '') $action = 'create';
}

$orders = [];
if ($action === 'create') {
    $stmt = $pdo->query(
        "SELECT p.id_pesanan, p.total_biaya, p.status_pekerjaan, c.nama AS nama_pelanggan,
                COALESCE(pb.total_dibayar, 0) AS total_dibayar,
                GROUP_CONCAT(DISTINCT l.nama_layanan ORDER BY l.nama_layanan SEPARATOR ', ') AS layanan
         FROM pesanan p
         JOIN pelanggan c ON c.id_pelanggan = p.id_pelanggan
         LEFT JOIN detail_pesanan dp ON dp.id_pesanan = p.id_pesanan
         LEFT JOIN layanan l ON l.id_layanan = dp.id_layanan
         LEFT JOIN (SELECT id_pesanan, SUM(jumlah_bayar) AS total_dibayar FROM pembayaran GROUP BY id_pesanan) pb ON pb.id_pesanan = p.id_pesanan
         WHERE p.status_pekerjaan <> 'Dibatalkan'
         GROUP BY p.id_pesanan
         HAVING total_dibayar < p.total_biaya
         ORDER BY p.waktu_layanan ASC"
    );
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$payments = [];
if ($action === 'list') {
    $stmt = $pdo->query(
        "SELECT b.id_pembayaran, b.id_pesanan, b.tanggal_pembayaran, b.jumlah_bayar,
                b.metode_pembayaran, b.status_pembayaran,
                c.nama AS nama_pelanggan, p.total_biaya,
                COALESCE(pb.total_dibayar, 0) AS total_dibayar
         FROM pembayaran b
         JOIN pesanan p ON p.id_pesanan = b.id_pesanan
         JOIN pelanggan c ON c.id_pelanggan = p.id_pelanggan
         LEFT JOIN (SELECT id_pesanan, SUM(jumlah_bayar) AS total_dibayar FROM pembayaran GROUP BY id_pesanan) pb ON pb.id_pesanan = p.id_pesanan
         ORDER BY b.tanggal_pembayaran DESC, b.id_pembayaran DESC"
    );
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$activePage = 'pembayaran';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pembayaran | RumahCare</title><link rel="stylesheet" href="assets/css/app.css?v=pembayaran-20261031"><script src="assets/js/sidebar.js" defer></script>
</head>
<body><div class="app-shell"><?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content">
<header class="page-header"><div><p class="breadcrumb">RumahCare / Operasional</p><h1>Pembayaran</h1></div><?php if ($action === 'list'): ?><a class="btn btn-primary" href="pembayaran.php?action=create">＋ Catat Pembayaran</a><?php else: ?><a class="btn btn-secondary" href="pembayaran.php">← Kembali</a><?php endif; ?></header>
<?php if ($success): ?><div class="notice success"><?= h_pembayaran($success) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= h_pembayaran($error) ?></div><?php endif; ?>
<?php if ($action === 'create'): ?>
<a class="back-link" href="pembayaran.php">← Kembali ke daftar pembayaran</a>
<section class="form-card"><div class="panel-header"><h2>Catat Pembayaran</h2><p>Catat metode, jumlah, dan tanggal pembayaran yang diterima.</p></div>
<?php if (!$orders): ?><div class="empty">Tidak ada pesanan dengan sisa tagihan yang dapat dibayar.</div><?php else: ?>
<form method="post" action="pembayaran.php?action=create"><input type="hidden" name="csrf_token" value="<?= h_pembayaran($_SESSION['csrf_token']) ?>">
<div class="form-grid">
<label class="field full">Pesanan <b>*</b><select name="id_pesanan" required><option value="">Pilih pesanan</option><?php foreach ($orders as $order): $remaining = (float) $order['total_biaya'] - (float) $order['total_dibayar']; ?><option value="<?= (int) $order['id_pesanan'] ?>" <?= $selectedOrder === (int) $order['id_pesanan'] ? 'selected' : '' ?>>#<?= (int) $order['id_pesanan'] ?> · <?= h_pembayaran($order['nama_pelanggan']) ?> · <?= h_pembayaran($order['layanan'] ?: 'Layanan RumahCare') ?> · Sisa Rp <?= number_format($remaining, 0, ',', '.') ?></option><?php endforeach; ?></select></label>
<label class="field">Jumlah dibayar (Rp) <b>*</b><input type="number" name="jumlah_bayar" min="0.01" step="0.01" required value="<?= h_pembayaran($amountInput) ?>" placeholder="Contoh: 75000"></label>
<label class="field">Metode pembayaran <b>*</b><select name="metode_pembayaran" required><option value="">Pilih metode</option><?php foreach ($methods as $method): ?><option value="<?= h_pembayaran($method) ?>" <?= $methodInput === $method ? 'selected' : '' ?>><?= h_pembayaran($method) ?></option><?php endforeach; ?></select></label>
<label class="field">Tanggal dan waktu pembayaran <b>*</b><input type="datetime-local" name="tanggal_pembayaran" required value="<?= h_pembayaran($dateInput) ?>"></label>
</div><div class="form-actions"><a class="btn btn-secondary" href="pembayaran.php">Batal</a><button class="btn btn-primary" type="submit">Simpan Pembayaran</button></div>
</form><?php endif; ?></section>
<?php else: ?>
<section class="table-panel"><div class="toolbar"><span class="muted">Riwayat pembayaran yang dicatat admin.</span><span class="result-count"><?= count($payments) ?> transaksi</span></div>
<div class="table-scroll"><table><thead><tr><th>Tanggal</th><th>Pesanan</th><th>Pelanggan</th><th>Metode</th><th>Jumlah</th><th>Status pembayaran</th><th>Sisa tagihan</th></tr></thead><tbody>
<?php if (!$payments): ?><tr><td colspan="7" class="empty">Belum ada pembayaran yang dicatat.</td></tr><?php else: foreach ($payments as $payment): $remaining = max(0, (float) $payment['total_biaya'] - (float) $payment['total_dibayar']); ?><tr><td><?= h_pembayaran(date('d-m-Y H:i', strtotime($payment['tanggal_pembayaran']))) ?></td><td><strong>#<?= (int) $payment['id_pesanan'] ?></strong></td><td><?= h_pembayaran($payment['nama_pelanggan']) ?></td><td><?= h_pembayaran($payment['metode_pembayaran']) ?></td><td><strong>Rp <?= number_format((float) $payment['jumlah_bayar'], 0, ',', '.') ?></strong></td><td><span class="status-pill"><?= h_pembayaran($payment['status_pembayaran']) ?></span></td><td>Rp <?= number_format($remaining, 0, ',', '.') ?></td></tr><?php endforeach; endif; ?>
</tbody></table></div><div class="table-footer">Pembayaran dicatat manual. Tidak ada unggah bukti atau proses pembayaran otomatis.</div></section>
<?php endif; ?>
</main></div></body></html>
