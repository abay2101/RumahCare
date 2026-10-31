<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_pesanan(string $url): never
{
    header('Location: ' . $url);
    exit;
}

$error = '';
$success = '';
$action = $_GET['action'] ?? 'list';
$archiveView = ($_GET['arsip'] ?? '') === '1' && $action === 'list';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$form = [
    'id_pesanan' => '',
    'id_pelanggan' => '',
    'alamat_layanan' => '',
    'waktu_layanan' => '',
    'status_pekerjaan' => 'Dijadwalkan',
];
$selectedServiceIds = [];

$statuses = ['Dijadwalkan', 'Diproses', 'Selesai', 'Dibatalkan'];

// Proses form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman.');
    }

    $postAction = $_POST['action'] ?? '';

    // Arsip hanya disembunyikan; relasi jadwal, pembayaran, dan riwayat tetap utuh.
    if ($postAction === 'hide_archive') {
        $rawIds = $_POST['id_pesanan'] ?? [];
        $archiveIds = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $rawArchiveId) {
                $parsedId = filter_var((string)$rawArchiveId, FILTER_VALIDATE_INT);
                if ($parsedId && $parsedId > 0) $archiveIds[] = (int)$parsedId;
            }
        }
        $archiveIds = array_values(array_unique($archiveIds));
        if (!$archiveIds) {
            $error = 'Pilih minimal satu pesanan untuk dikeluarkan dari arsip.';
            $archiveView = true;
        } else {
            try {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($archiveIds), '?'));
                $stmt = $pdo->prepare("UPDATE pesanan SET dihapus_dari_arsip = 1 WHERE id_pesanan IN ($placeholders) AND status_pekerjaan IN ('Selesai', 'Dibatalkan') AND dihapus_dari_arsip = 0");
                $stmt->execute($archiveIds);
                $pdo->commit();
                redirect_pesanan('pesanan.php?arsip=1&hidden=1');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($e->getMessage());
                $error = 'Pesanan gagal dikeluarkan dari arsip.';
                $archiveView = true;
            }
        }
        $action = 'list';
    }

    // TAMBAH DAN EDIT PESANAN
    elseif ($postAction === 'save') {
        $rawId = trim((string)($_POST['id_pesanan'] ?? ''));
        $form['id_pesanan'] = $rawId;

        foreach (['id_pelanggan', 'alamat_layanan', 'waktu_layanan', 'status_pekerjaan'] as $field) {
            $value = $_POST[$field] ?? '';
            $form[$field] = is_string($value) ? trim($value) : '';
        }

        // Checkbox mengirim array id_layanan[]. Validasi dilakukan di server.
        $rawServiceIds = $_POST['id_layanan'] ?? [];
        if (is_array($rawServiceIds)) {
            foreach ($rawServiceIds as $rawServiceId) {
                if (is_string($rawServiceId) || is_int($rawServiceId)) {
                    $parsedId = filter_var((string)$rawServiceId, FILTER_VALIDATE_INT);
                    if ($parsedId && $parsedId > 0) {
                        $selectedServiceIds[] = (int)$parsedId;
                    }
                }
            }
        }
        $selectedServiceIds = array_values(array_unique($selectedServiceIds));

        $formId = $rawId === '' ? null : filter_var($rawId, FILTER_VALIDATE_INT);
        $action = $rawId === '' ? 'create' : 'edit';
        $customerId = filter_var($form['id_pelanggan'], FILTER_VALIDATE_INT);

        $date = DateTime::createFromFormat('!Y-m-d\\TH:i', $form['waktu_layanan']);
        $validDate = $date && $date->format('Y-m-d\\TH:i') === $form['waktu_layanan'];

        if ($formId) {
            $check = $pdo->prepare('SELECT status_pekerjaan FROM pesanan WHERE id_pesanan = ?');
            $check->execute([$formId]);
            $currentOrder = $check->fetch(PDO::FETCH_ASSOC);
            if (!$currentOrder || in_array($currentOrder['status_pekerjaan'], ['Selesai', 'Dibatalkan'], true)) {
                $error = 'Pesanan selesai atau dibatalkan tidak dapat diedit.';
            }
        }

        if ($error !== '') {
            // Penolakan status terminal di atas mencegah perubahan data.
        } elseif (!$customerId || $customerId < 1) {
            $error = 'Pilih pelanggan yang valid.';
        } elseif (count($selectedServiceIds) < 1) {
            $error = 'Pilih minimal satu layanan.';
        } elseif ($form['alamat_layanan'] === '') {
            $error = 'Alamat layanan wajib diisi.';
        } elseif (!$validDate) {
            $error = 'Tanggal dan waktu layanan tidak valid.';
        } elseif (!in_array($form['status_pekerjaan'], $statuses, true)) {
            $error = 'Status pekerjaan tidak valid.';
        } elseif ($rawId !== '' && (!$formId || $formId < 1)) {
            $error = 'ID pesanan tidak valid.';
        } else {
            try {
                // Pastikan pelanggan memang ada.
                $stmt = $pdo->prepare('SELECT id_pelanggan FROM pelanggan WHERE id_pelanggan = ?');
                $stmt->execute([$customerId]);
                if (!$stmt->fetch()) {
                    throw new RuntimeException('Pelanggan tidak ditemukan.');
                }

                // Ambil harga langsung dari database, bukan dari browser.
                $placeholders = implode(',', array_fill(0, count($selectedServiceIds), '?'));
                $stmt = $pdo->prepare(
                    "SELECT id_layanan, harga FROM layanan WHERE id_layanan IN ($placeholders)"
                );
                $stmt->execute($selectedServiceIds);
                $serviceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($serviceRows) !== count($selectedServiceIds)) {
                    throw new RuntimeException('Satu atau lebih layanan tidak ditemukan. Muat ulang halaman dan pilih layanan yang tersedia.');
                }

                $pricesById = [];
                $totalBiaya = 0.0;
                foreach ($serviceRows as $serviceRow) {
                    $serviceId = (int)$serviceRow['id_layanan'];
                    $pricesById[$serviceId] = (float)$serviceRow['harga'];
                    $totalBiaya += (float)$serviceRow['harga'];
                }

                // Simpan pesanan utama dan detailnya secara atomik.
                $pdo->beginTransaction();
                $datetime = $date->format('Y-m-d H:i:s');

                if ($formId === null) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO pesanan (
                            id_pelanggan, alamat_layanan, waktu_layanan,
                            total_biaya, status_pekerjaan
                        ) VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $customerId,
                        $form['alamat_layanan'],
                        $datetime,
                        $totalBiaya,
                        $form['status_pekerjaan'],
                    ]);
                    $savedOrderId = (int)$pdo->lastInsertId();
                } else {
                    // Pastikan pesanan yang diedit ada.
                    $stmt = $pdo->prepare("SELECT id_pesanan FROM pesanan WHERE id_pesanan = ? AND status_pekerjaan NOT IN ('Selesai', 'Dibatalkan')");
                    $stmt->execute([$formId]);
                    if (!$stmt->fetch()) {
                        throw new RuntimeException('Pesanan yang akan diedit tidak ditemukan.');
                    }

                    $stmt = $pdo->prepare(
                        'UPDATE pesanan SET
                            id_pelanggan = ?, alamat_layanan = ?, waktu_layanan = ?,
                            total_biaya = ?, status_pekerjaan = ?
                         WHERE id_pesanan = ?'
                    );
                    $stmt->execute([
                        $customerId,
                        $form['alamat_layanan'],
                        $datetime,
                        $totalBiaya,
                        $form['status_pekerjaan'],
                        $formId,
                    ]);

                    $savedOrderId = (int)$formId;
                    $stmt = $pdo->prepare('DELETE FROM detail_pesanan WHERE id_pesanan = ?');
                    $stmt->execute([$savedOrderId]);
                }

                $insertDetail = $pdo->prepare(
                    'INSERT INTO detail_pesanan (id_pesanan, id_layanan, harga_saat_pesan)
                     VALUES (?, ?, ?)'
                );
                foreach ($selectedServiceIds as $serviceId) {
                    $insertDetail->execute([$savedOrderId, $serviceId, $pricesById[$serviceId]]);
                }

                $stmt = $pdo->prepare(
                    "UPDATE pesanan
                     SET waktu_status_akhir = CASE
                         WHEN status_pekerjaan IN ('Selesai', 'Dibatalkan') THEN COALESCE(waktu_status_akhir, NOW())
                         ELSE NULL
                     END
                     WHERE id_pesanan = ?"
                );
                $stmt->execute([$savedOrderId]);

                // Status jadwal mengikuti status pekerjaan pada pesanan.
                if ($formId !== null) {
                    $scheduleStatusByOrder = [
                        'Dijadwalkan' => 'Terjadwal',
                        'Diproses' => 'Berlangsung',
                        'Selesai' => 'Selesai',
                        'Dibatalkan' => 'Dibatalkan',
                    ];
                    $stmt = $pdo->prepare('UPDATE jadwal SET status_jadwal = ? WHERE id_pesanan = ?');
                    $stmt->execute([$scheduleStatusByOrder[$form['status_pekerjaan']], $savedOrderId]);
                }

                $pdo->commit();
                redirect_pesanan('pesanan.php' . (in_array($form['status_pekerjaan'], ['Selesai', 'Dibatalkan'], true) ? '?arsip=1&saved=1' : '?saved=1'));
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $error = 'Pesanan gagal disimpan. Periksa struktur tabel dan database.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $error = 'Terjadi kesalahan saat menyimpan pesanan.';
            }
        }
    } else {
        http_response_code(400);
        exit('Aksi tidak dikenal.');
    }
}

// Ambil data untuk form edit. Daftar layanan lama diambil dari detail_pesanan.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && in_array($action, ['create', 'edit'], true)) {
    if ($action === 'edit') {
        if (!$id || $id < 1) {
            redirect_pesanan('pesanan.php');
        }

        $stmt = $pdo->prepare('SELECT * FROM pesanan WHERE id_pesanan = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            exit('Pesanan tidak ditemukan.');
        }

        if (in_array($existing['status_pekerjaan'], ['Selesai', 'Dibatalkan'], true)) {
            redirect_pesanan('pesanan.php?arsip=1');
        }

        $form = $existing;
        $form['waktu_layanan'] = date('Y-m-d\\TH:i', strtotime($existing['waktu_layanan']));

        $stmt = $pdo->prepare('SELECT id_layanan FROM detail_pesanan WHERE id_pesanan = ?');
        $stmt->execute([$id]);
        $selectedServiceIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (isset($_GET['saved'])) {
    $success = $archiveView
        ? 'Pesanan berhasil disimpan dan masuk ke arsip status.'
        : 'Pesanan berhasil disimpan.';
} elseif (isset($_GET['hidden'])) {
    $success = 'Pesanan dikeluarkan dari arsip. Jadwal dan pembayaran tetap tersimpan.';
}

// Ambil pelanggan dan layanan untuk form.
$customers = $pdo->query(
    'SELECT id_pelanggan, nama, alamat FROM pelanggan ORDER BY nama'
)->fetchAll(PDO::FETCH_ASSOC);

$services = $pdo->query(
    'SELECT id_layanan, nama_layanan, harga FROM layanan ORDER BY nama_layanan'
)->fetchAll(PDO::FETCH_ASSOC);

// Daftar pesanan: GROUP_CONCAT menampilkan banyak layanan dalam satu baris.
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = $_GET['status'] ?? '';
$orders = [];

if ($action === 'list') {
    $sql = '
        SELECT
            ps.id_pesanan,
            ps.alamat_layanan,
            ps.waktu_layanan,
            ps.waktu_status_akhir,
            ps.total_biaya,
            ps.status_pekerjaan,
            p.nama AS nama_pelanggan,
            GROUP_CONCAT(l.nama_layanan ORDER BY l.nama_layanan SEPARATOR ", ") AS nama_layanan
        FROM pesanan ps
        JOIN pelanggan p ON ps.id_pelanggan = p.id_pelanggan
        JOIN detail_pesanan dp ON dp.id_pesanan = ps.id_pesanan
        JOIN layanan l ON dp.id_layanan = l.id_layanan
    ';

    $conditions = $archiveView
        ? "ps.status_pekerjaan IN ('Selesai', 'Dibatalkan') AND ps.dihapus_dari_arsip = 0"
        : "ps.status_pekerjaan NOT IN ('Selesai', 'Dibatalkan') AND ps.dihapus_dari_arsip = 0";
    $params = [];
    if ($archiveView && in_array($statusFilter, ['Selesai', 'Dibatalkan'], true)) {
        $conditions .= ' AND ps.status_pekerjaan = ?';
        $params[] = $statusFilter;
    }
    if ($search !== '') {
        $conditions .= ' AND (p.nama LIKE ? OR EXISTS (
                SELECT 1
                FROM detail_pesanan dp2
                JOIN layanan l2 ON l2.id_layanan = dp2.id_layanan
                WHERE dp2.id_pesanan = ps.id_pesanan
                  AND l2.nama_layanan LIKE ?
            ))';
        $term = '%' . $search . '%';
        $params[] = $term;
        $params[] = $term;
    }
    $stmt = $pdo->prepare(
        $sql . "\nWHERE $conditions
        GROUP BY ps.id_pesanan, ps.alamat_layanan, ps.waktu_layanan,
                 ps.waktu_status_akhir, ps.total_biaya, ps.status_pekerjaan, p.nama
        ORDER BY ps.id_pesanan DESC"
    );
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$activePage = 'pesanan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Pesanan | RumahCare</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/sidebar.js" defer></script>
    <style>
        .order-detail { min-width: 170px; line-height: 1.5; }
        .status { display: inline-block; padding: 5px 9px; border-radius: 20px; background: #e7f4ed; color: #105f3c; font-size: 11px; white-space: nowrap; }
        .status.cancelled { background: #fff0f0; color: #a12e2e; }
        .price { white-space: nowrap; font-weight: 650; }
        /* Kartu layanan: maksimal 4 kolom dan tetap di tengah jika jumlahnya kurang. */
        .service-checklist {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: stretch;
            gap: 8px;
            width: 100%;
        }
        .service-option {
            box-sizing: border-box;
            flex: 0 1 calc((100% - 24px) / 4);
            min-width: 190px;
            max-width: 260px;
            min-height: 62px;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 9px 11px;
            border: 1px solid #dce4df;
            border-radius: 10px;
            cursor: pointer;
            background: #fff;
        }
        .service-option:has(input:checked) {
            border-color: #27845b;
            background: #f0f8f3;
        }
        .service-option input {
            box-sizing: border-box;
            width: 18px;
            height: 18px;
            min-width: 18px;
            flex: 0 0 18px;
            margin: 0;
            accent-color: #16804b;
        }
        .service-option span {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-width: 0;
            gap: 3px;
        }
        .service-option strong {
            font-size: 13px;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }
        .service-option small {
            color: #68766f;
            font-size: 12px;
            line-height: 1.3;
        }
        .service-total { font-weight: 700; }
        .archive-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .archive-controls { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .archive-controls select { min-height:38px; padding:0 10px; border:1px solid #dce4df; border-radius:8px; background:#fff; }
        .select-col, .select-cell { width:38px; text-align:center; }
        .select-col input, .select-cell input { width:16px; height:16px; accent-color:#16804b; }
        @media (max-width: 1050px) {
            .service-option { flex-basis: calc((100% - 16px) / 3); }
        }
        @media (max-width: 760px) {
            .service-option { flex-basis: calc((100% - 8px) / 2); min-width: 0; }
        }
        @media (max-width: 480px) {
            .service-option { flex-basis: 100%; max-width: 360px; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="page-header">
            <div>
                <p class="breadcrumb">RumahCare / Operasional</p>
                <h1>Data Pesanan</h1>
            </div>
            <?php if ($action === 'list' && $archiveView): ?>
                <a class="btn btn-secondary" href="pesanan.php">← Kembali</a>
            <?php elseif ($action === 'list'): ?>
                <div class="archive-actions">
                    <a class="btn btn-secondary" href="pesanan.php?arsip=1">Selesai / Batal</a>
                <a class="btn btn-primary" href="pesanan.php?action=create">＋ Tambah Pesanan</a>
                </div>
            <?php endif; ?>
        </header>

        <?php if ($success): ?><div class="notice success"><?= h($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

        <?php if (in_array($action, ['create', 'edit'], true)): ?>
            <a class="back-link" href="pesanan.php">← Kembali ke daftar pesanan</a>
            <section class="form-card">
                <div class="panel-header">
                    <h2><?= $action === 'edit' ? 'Edit Pesanan' : 'Tambah Pesanan' ?></h2>
                    <p>Pilih pelanggan dan satu atau beberapa layanan untuk satu kunjungan.</p>
                </div>

                <form method="post" action="pesanan.php" data-order-form data-initial-status="<?= h($form['status_pekerjaan'] ?? 'Dijadwalkan') ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="id_pesanan" value="<?= h($form['id_pesanan'] ?? '') ?>">

                    <div class="form-grid">
                        <label class="field">
                            Pelanggan <b>*</b>
                            <select name="id_pelanggan" id="customerSelect" required>
                                <option value="">Pilih pelanggan</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= (int)$customer['id_pelanggan'] ?>"
                                        data-address="<?= h($customer['alamat']) ?>"
                                        <?= (string)($form['id_pelanggan'] ?? '') === (string)$customer['id_pelanggan'] ? 'selected' : '' ?>>
                                        <?= h($customer['nama']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div class="field full">
                            <span>Layanan <b>*</b></span>
                            <div class="service-checklist">
                                <?php foreach ($services as $service): ?>
                                    <label class="service-option">
                                        <input
                                            type="checkbox"
                                            name="id_layanan[]"
                                            value="<?= (int)$service['id_layanan'] ?>"
                                            data-price="<?= h($service['harga']) ?>"
                                            <?= in_array((int)$service['id_layanan'], $selectedServiceIds, true) ? 'checked' : '' ?>>
                                        <span>
                                            <strong><?= h($service['nama_layanan']) ?></strong>
                                            <small>Rp <?= number_format((float)$service['harga'], 0, ',', '.') ?></small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small>Pilih minimal satu layanan. Layanan yang sama tidak dapat dipilih dua kali.</small>
                        </div>

                        <label class="field full">
                            Alamat layanan <b>*</b>
                            <textarea id="serviceAddress" name="alamat_layanan" rows="3" required><?= h($form['alamat_layanan'] ?? '') ?></textarea>
                        </label>

                        <label class="field">
                            Waktu layanan <b>*</b>
                            <input type="datetime-local" name="waktu_layanan" required value="<?= h($form['waktu_layanan'] ?? '') ?>">
                        </label>

                        <label class="field">
                            Estimasi total biaya
                            <input id="priceDisplay" type="text" readonly value="Rp 0">
                            <small>Total final tetap dihitung ulang oleh server.</small>
                        </label>

                        <label class="field">
                            Status pekerjaan <b>*</b>
                            <select name="status_pekerjaan" required>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= h($status) ?>" <?= ($form['status_pekerjaan'] ?? 'Dijadwalkan') === $status ? 'selected' : '' ?>>
                                        <?= h($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="form-actions">
                        <a class="btn btn-secondary" href="pesanan.php">Batal</a>
                        <button class="btn btn-primary" type="submit">Simpan Pesanan</button>
                    </div>
                </form>
            </section>

            <script>
                const serviceCheckboxes = document.querySelectorAll('input[name="id_layanan[]"]');
                const priceDisplay = document.getElementById('priceDisplay');

                function updatePrice() {
                    let total = 0;
                    serviceCheckboxes.forEach((checkbox) => {
                        if (checkbox.checked) total += Number(checkbox.dataset.price || 0);
                    });
                    priceDisplay.value = 'Rp ' + total.toLocaleString('id-ID');
                }
                serviceCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updatePrice));
                updatePrice();

                const customerSelect = document.getElementById('customerSelect');
                const serviceAddress = document.getElementById('serviceAddress');
                customerSelect.addEventListener('change', () => {
                    const option = customerSelect.options[customerSelect.selectedIndex];
                    serviceAddress.value = option && option.value ? (option.dataset.address || '') : '';
                });
                const orderForm = document.querySelector('[data-order-form]');
                orderForm.addEventListener('submit', (event) => {
                    const statusSelect = orderForm.querySelector('[name="status_pekerjaan"]');
                    const nextStatus = statusSelect.value;
                    const oldStatus = orderForm.dataset.initialStatus;
                    if (nextStatus !== oldStatus && ['Selesai', 'Dibatalkan'].includes(nextStatus)) {
                        const confirmed = confirm(`Konfirmasi status pesanan menjadi ${nextStatus}? Setelah disimpan, pesanan masuk ke arsip dan tidak dapat diedit.`);
                        if (!confirmed) event.preventDefault();
                    }
                });
            </script>
        <?php else: ?>
            <section class="table-panel">
                <div class="toolbar">
                    <form class="search-form" method="get" action="pesanan.php">
                        <?php if ($archiveView): ?><input type="hidden" name="arsip" value="1"><?php endif; ?>
                        <input type="search" name="q" value="<?= h($search) ?>" placeholder="Cari pelanggan atau layanan...">
                        <button class="btn btn-secondary" type="submit">Cari</button>
                        <?php if ($search !== '' || $statusFilter !== ''): ?><a class="btn btn-secondary" href="pesanan.php<?= $archiveView ? '?arsip=1' : '' ?>">Reset</a><?php endif; ?>
                    </form>
                    <?php if ($archiveView): ?>
                        <div class="archive-controls">
                            <form method="get" action="pesanan.php">
                                <input type="hidden" name="arsip" value="1">
                                <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= h($search) ?>"><?php endif; ?>
                                <select name="status" aria-label="Filter status" onchange="this.form.submit()">
                                    <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>Semua status</option>
                                    <option value="Selesai" <?= $statusFilter === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                    <option value="Dibatalkan" <?= $statusFilter === 'Dibatalkan' ? 'selected' : '' ?>>Dibatalkan</option>
                                </select>
                            </form>
                            <button class="btn btn-secondary" type="button" id="toggleSelection">Pilih untuk dihapus</button>
                        </div>
                    <?php endif; ?>
                    <span class="result-count"><?= count($orders) ?> data</span>
                </div>

                <?php if ($archiveView): ?>
                <form method="post" action="pesanan.php?arsip=1" id="archiveForm">
                    <input type="hidden" name="action" value="hide_archive">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <?php endif; ?>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <?php if ($archiveView): ?><th class="select-col selection-column" hidden><input type="checkbox" id="selectAll" aria-label="Pilih semua"></th><?php endif; ?>
                                <th>No.</th><th>Pelanggan</th><th>Layanan</th><th>Jadwal</th>
                                <?php if ($archiveView): ?><th>Waktu selesai/batal</th><?php endif; ?>
                                <th>Total Biaya</th><th>Status</th><?php if (!$archiveView): ?><th>Aksi</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$orders): ?>
                            <tr><td colspan="<?= $archiveView ? 8 : 7 ?>" class="empty">Belum ada pesanan atau hasil pencarian tidak ditemukan.</td></tr>
                        <?php else: ?>
                            <?php $nomor = 1; ?>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <?php if ($archiveView): ?><td class="select-cell selection-column" hidden><input type="checkbox" name="id_pesanan[]" value="<?= (int)$order['id_pesanan'] ?>" aria-label="Pilih pesanan <?= (int)$order['id_pesanan'] ?>"></td><?php endif; ?>
                                    <td><?= $nomor++ ?></td>
                                    <td><strong><?= h($order['nama_pelanggan']) ?></strong></td>
                                    <td><?= h($order['nama_layanan'] ?? '-') ?></td>
                                    <td class="order-detail">
                                        <?= h(date('d-m-Y H:i', strtotime($order['waktu_layanan']))) ?><br>
                                        <span style="color:#6f7c76"><?= h($order['alamat_layanan']) ?></span>
                                    </td>
                                    <?php if ($archiveView): ?>
                                        <td><?= $order['waktu_status_akhir'] ? h(date('d-m-Y H:i', strtotime($order['waktu_status_akhir']))) : '—' ?></td>
                                    <?php endif; ?>
                                    <td class="price">Rp <?= number_format((float)$order['total_biaya'], 0, ',', '.') ?></td>
                                    <td><span class="status <?= $order['status_pekerjaan'] === 'Dibatalkan' ? 'cancelled' : '' ?>"><?= h($order['status_pekerjaan']) ?></span></td>
                                    <?php if (!$archiveView): ?><td>
                                        <div class="actions">
                                            <a class="edit-link" href="pesanan.php?action=edit&id=<?= (int)$order['id_pesanan'] ?>">Edit</a>
                                        </div>
                                    </td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($archiveView): ?>
                    <div class="table-footer archive-controls">
                        <span>Waktu selesai/batal tercatat saat status dikonfirmasi. Pengeluaran dari arsip tidak menghapus jadwal atau pembayaran.</span>
                        <button class="btn btn-danger" type="submit" id="deleteSelected" disabled hidden>Hapus terpilih (0)</button>
                    </div>
                </form>
                <?php else: ?>
                    <div class="table-footer">Satu pesanan dapat mencakup beberapa layanan dan tetap terhubung dengan jadwal serta pembayaran RumahCare.</div>
                <?php endif; ?>
            </section>
            <?php if ($archiveView): ?>
            <script>
                const archiveForm = document.getElementById('archiveForm');
                const toggleSelection = document.getElementById('toggleSelection');
                const deleteSelected = document.getElementById('deleteSelected');
                const checks = [...archiveForm.querySelectorAll('input[name="id_pesanan[]"]')];
                const selectAll = document.getElementById('selectAll');
                let selectionMode = false;
                function updateSelection() {
                    const count = checks.filter((item) => item.checked).length;
                    deleteSelected.textContent = `Hapus terpilih (${count})`;
                    deleteSelected.disabled = count === 0;
                    deleteSelected.hidden = !selectionMode;
                    archiveForm.querySelectorAll('.selection-column').forEach((item) => item.hidden = !selectionMode);
                    toggleSelection.textContent = selectionMode ? 'Batal pilih' : 'Pilih untuk dihapus';
                }
                toggleSelection.addEventListener('click', () => { selectionMode = !selectionMode; updateSelection(); });
                checks.forEach((item) => item.addEventListener('change', updateSelection));
                selectAll.addEventListener('change', () => { checks.forEach((item) => item.checked = selectAll.checked); updateSelection(); });
                archiveForm.addEventListener('submit', (event) => {
                    const count = checks.filter((item) => item.checked).length;
                    if (!selectionMode || count === 0 || !confirm(`Keluarkan ${count} pesanan terpilih dari arsip? Jadwal dan pembayaran tetap tersimpan.`)) event.preventDefault();
                });
            </script>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
