<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

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

    // HAPUS PESANAN. Detail ikut dihapus dalam transaksi; jika pesanan masih
    // terhubung ke jadwal/pembayaran, seluruh transaksi dibatalkan.
    if ($postAction === 'delete') {
        $deleteId = filter_input(INPUT_POST, 'id_pesanan', FILTER_VALIDATE_INT);

        if (!$deleteId || $deleteId < 1) {
            $error = 'ID pesanan tidak valid.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('DELETE FROM detail_pesanan WHERE id_pesanan = ?');
                $stmt->execute([$deleteId]);

                $stmt = $pdo->prepare('DELETE FROM pesanan WHERE id_pesanan = ?');
                $stmt->execute([$deleteId]);

                $pdo->commit();
                redirect_pesanan('pesanan.php?deleted=1');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $error = 'Pesanan tidak dapat dihapus karena masih memiliki data terkait, seperti jadwal atau pembayaran.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $error = 'Terjadi kesalahan saat menghapus pesanan.';
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

        if (!$customerId || $customerId < 1) {
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
                    $stmt = $pdo->prepare('SELECT id_pesanan FROM pesanan WHERE id_pesanan = ?');
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

                $pdo->commit();
                redirect_pesanan('pesanan.php?saved=1');
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

        $form = $existing;
        $form['waktu_layanan'] = date('Y-m-d\\TH:i', strtotime($existing['waktu_layanan']));

        $stmt = $pdo->prepare('SELECT id_layanan FROM detail_pesanan WHERE id_pesanan = ?');
        $stmt->execute([$id]);
        $selectedServiceIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (isset($_GET['saved'])) {
    $success = 'Pesanan berhasil disimpan.';
} elseif (isset($_GET['deleted'])) {
    $success = 'Pesanan berhasil dihapus.';
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
$orders = [];

if ($action === 'list') {
    $sql = '
        SELECT
            ps.id_pesanan,
            ps.alamat_layanan,
            ps.waktu_layanan,
            ps.total_biaya,
            ps.status_pekerjaan,
            p.nama AS nama_pelanggan,
            GROUP_CONCAT(l.nama_layanan ORDER BY l.nama_layanan SEPARATOR ", ") AS nama_layanan
        FROM pesanan ps
        JOIN pelanggan p ON ps.id_pelanggan = p.id_pelanggan
        JOIN detail_pesanan dp ON dp.id_pesanan = ps.id_pesanan
        JOIN layanan l ON dp.id_layanan = l.id_layanan
    ';

    if ($search !== '') {
        $stmt = $pdo->prepare(
            $sql . '
            WHERE p.nama LIKE ? OR EXISTS (
                SELECT 1
                FROM detail_pesanan dp2
                JOIN layanan l2 ON l2.id_layanan = dp2.id_layanan
                WHERE dp2.id_pesanan = ps.id_pesanan
                  AND l2.nama_layanan LIKE ?
            )
            GROUP BY ps.id_pesanan, ps.alamat_layanan, ps.waktu_layanan,
                     ps.total_biaya, ps.status_pekerjaan, p.nama
            ORDER BY ps.id_pesanan DESC'
        );
        $term = '%' . $search . '%';
        $stmt->execute([$term, $term]);
    } else {
        $stmt = $pdo->query(
            $sql . '
            GROUP BY ps.id_pesanan, ps.alamat_layanan, ps.waktu_layanan,
                     ps.total_biaya, ps.status_pekerjaan, p.nama
            ORDER BY ps.id_pesanan DESC'
        );
    }
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
            <?php if ($action === 'list'): ?>
                <a class="btn btn-primary" href="pesanan.php?action=create">＋ Tambah Pesanan</a>
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

                <form method="post" action="pesanan.php">
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
            </script>
        <?php else: ?>
            <section class="table-panel">
                <div class="toolbar">
                    <form class="search-form" method="get" action="pesanan.php">
                        <input type="search" name="q" value="<?= h($search) ?>" placeholder="Cari pelanggan atau layanan...">
                        <button class="btn btn-secondary" type="submit">Cari</button>
                        <?php if ($search !== ''): ?><a class="btn btn-secondary" href="pesanan.php">Reset</a><?php endif; ?>
                    </form>
                    <span class="result-count"><?= count($orders) ?> data</span>
                </div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th><th>Pelanggan</th><th>Layanan</th><th>Jadwal</th><th>Total Biaya</th><th>Status</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$orders): ?>
                            <tr><td colspan="7" class="empty">Belum ada pesanan atau hasil pencarian tidak ditemukan.</td></tr>
                        <?php else: ?>
                            <?php $nomor = 1; ?>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><?= $nomor++ ?></td>
                                    <td><strong><?= h($order['nama_pelanggan']) ?></strong></td>
                                    <td><?= h($order['nama_layanan'] ?? '-') ?></td>
                                    <td class="order-detail">
                                        <?= h(date('d-m-Y H:i', strtotime($order['waktu_layanan']))) ?><br>
                                        <span style="color:#6f7c76"><?= h($order['alamat_layanan']) ?></span>
                                    </td>
                                    <td class="price">Rp <?= number_format((float)$order['total_biaya'], 0, ',', '.') ?></td>
                                    <td><span class="status <?= $order['status_pekerjaan'] === 'Dibatalkan' ? 'cancelled' : '' ?>"><?= h($order['status_pekerjaan']) ?></span></td>
                                    <td>
                                        <div class="actions">
                                            <a class="edit-link" href="pesanan.php?action=edit&id=<?= (int)$order['id_pesanan'] ?>">Edit</a>
                                            <form method="post" action="pesanan.php" onsubmit="return confirm('Yakin ingin menghapus pesanan ini?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id_pesanan" value="<?= (int)$order['id_pesanan'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                                <button class="delete-link" type="submit">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">Satu pesanan dapat mencakup beberapa layanan dan tetap terhubung dengan jadwal serta pembayaran RumahCare.</div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
