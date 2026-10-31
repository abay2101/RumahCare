<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h_jadwal(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_jadwal(string $url): never
{
    header('Location: ' . $url);
    exit;
}

$statuses = ['Dijadwalkan', 'Diproses', 'Selesai', 'Dibatalkan'];
$listStatuses = ['Dijadwalkan', 'Diproses'];
$scheduleStatusByOrder = [
    'Dijadwalkan' => 'Terjadwal',
    'Diproses' => 'Berlangsung',
    'Selesai' => 'Selesai',
    'Dibatalkan' => 'Dibatalkan',
];
$orderStatusBySchedule = [
    'Terjadwal' => 'Dijadwalkan',
    'Berlangsung' => 'Diproses',
    'Selesai' => 'Selesai',
    'Dibatalkan' => 'Dibatalkan',
];
$action = $_GET['action'] ?? 'list';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';
$success = isset($_GET['saved']) ? 'Jadwal berhasil disimpan.' : '';
$form = [
    'id_jadwal' => '',
    'id_pesanan' => (string) ($_GET['order'] ?? ''),
    'id_petugas' => '',
    'waktu_mulai' => '',
    'status_jadwal' => 'Dijadwalkan',
    'alasan_perubahan' => '',
    'persetujuan_pelanggan' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman.');
    }

    foreach (array_keys($form) as $field) {
        $value = $_POST[$field] ?? '';
        $form[$field] = is_string($value) ? trim($value) : '';
    }
    $form['persetujuan_pelanggan'] = isset($_POST['persetujuan_pelanggan']) ? '1' : '';
    $scheduleId = $form['id_jadwal'] === '' ? null : filter_var($form['id_jadwal'], FILTER_VALIDATE_INT);
    $orderId = filter_var($form['id_pesanan'], FILTER_VALIDATE_INT);
    $technicianId = filter_var($form['id_petugas'], FILTER_VALIDATE_INT);
    $start = DateTime::createFromFormat('!Y-m-d\\TH:i', $form['waktu_mulai']);
    $validStart = $start && $start->format('Y-m-d\\TH:i') === $form['waktu_mulai'];

    $action = $scheduleId === null ? 'create' : 'edit';
    if (!$orderId || $orderId < 1) {
        $error = 'Pilih pesanan yang valid.';
    } elseif (!$technicianId || $technicianId < 1) {
        $error = 'Pilih teknisi yang valid.';
    } elseif (!$validStart) {
        $error = 'Waktu mulai tidak valid.';
    } elseif (!in_array($form['status_jadwal'], $statuses, true)) {
        $error = 'Status jadwal tidak valid.';
    } elseif ($scheduleId !== null && (!$scheduleId || $scheduleId < 1)) {
        $error = 'ID jadwal tidak valid.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id_pesanan, status_pekerjaan FROM pesanan WHERE id_pesanan = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new RuntimeException('Pesanan tidak ditemukan.');
            }
            if (in_array($order['status_pekerjaan'], ['Selesai', 'Dibatalkan'], true)) {
                throw new RuntimeException('Jadwal tidak dapat diubah karena pesanan sudah selesai atau dibatalkan.');
            }

            if ($scheduleId === null) {
                $form['status_jadwal'] = $order['status_pekerjaan'];
            }

            $stmt = $pdo->prepare('SELECT id_petugas FROM petugas WHERE id_petugas = ? FOR UPDATE');
            $stmt->execute([$technicianId]);
            if (!$stmt->fetch()) {
                throw new RuntimeException('Teknisi tidak ditemukan.');
            }

            $current = null;
            if ($scheduleId !== null) {
                $stmt = $pdo->prepare('SELECT * FROM jadwal WHERE id_jadwal = ? FOR UPDATE');
                $stmt->execute([$scheduleId]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$current || (int) $current['id_pesanan'] !== (int) $orderId) {
                    throw new RuntimeException('Jadwal tidak ditemukan untuk pesanan tersebut.');
                }
            } else {
                $stmt = $pdo->prepare('SELECT id_jadwal FROM jadwal WHERE id_pesanan = ? LIMIT 1');
                $stmt->execute([$orderId]);
                if ($stmt->fetch()) {
                    throw new RuntimeException('Pesanan ini sudah memiliki jadwal. Gunakan aksi Ubah Jadwal.');
                }
            }

            $sql = "SELECT j.id_jadwal
                    FROM jadwal j
                    JOIN pesanan p ON p.id_pesanan = j.id_pesanan
                    WHERE j.id_petugas = ?
                      AND p.status_pekerjaan IN ('Dijadwalkan', 'Diproses')";
            $params = [$technicianId];
            if ($scheduleId !== null) {
                $sql .= ' AND j.id_jadwal <> ?';
                $params[] = $scheduleId;
            }
            $sql .= ' LIMIT 1 FOR UPDATE';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetch()) {
                throw new RuntimeException('Teknisi masih dalam pekerjaan, pilih lainnya.');
            }

            $startSql = $start->format('Y-m-d H:i:s');
            if ($current) {
                $timeChanged = $current['waktu_mulai'] !== $startSql;
                if ($timeChanged && $form['alasan_perubahan'] === '') {
                    throw new RuntimeException('Isi alasan perubahan jadwal.');
                }
                if ($timeChanged && $form['persetujuan_pelanggan'] !== '1') {
                    throw new RuntimeException('Konfirmasikan bahwa pelanggan sudah menyetujui waktu baru.');
                }

                if ($timeChanged) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO riwayat_perubahan_jadwal
                            (id_jadwal, waktu_lama, waktu_baru, waktu_perubahan, alasan_perubahan)
                         VALUES (?, ?, ?, NOW(), ?)'
                    );
                    $stmt->execute([
                        $scheduleId,
                        $current['waktu_mulai'],
                        $startSql,
                        'Alasan: ' . $form['alasan_perubahan'] . ' | Persetujuan pelanggan sudah dikonfirmasi.',
                    ]);
                }

                $stmt = $pdo->prepare(
                    'UPDATE jadwal
                     SET id_petugas = ?, waktu_mulai = ?, waktu_selesai = NULL, status_jadwal = ?
                     WHERE id_jadwal = ?'
                );
                $stmt->execute([$technicianId, $startSql, $scheduleStatusByOrder[$form['status_jadwal']], $scheduleId]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO jadwal (id_pesanan, id_petugas, waktu_mulai, status_jadwal)
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$orderId, $technicianId, $startSql, $scheduleStatusByOrder[$form['status_jadwal']]]);
            }

            $stmt = $pdo->prepare(
                "UPDATE pesanan
                 SET waktu_layanan = ?, status_pekerjaan = ?,
                     waktu_status_akhir = CASE
                         WHEN ? IN ('Selesai', 'Dibatalkan') THEN COALESCE(waktu_status_akhir, NOW())
                         ELSE NULL
                     END
                 WHERE id_pesanan = ?"
            );
            $stmt->execute([$startSql, $form['status_jadwal'], $form['status_jadwal'], $orderId]);

            $pdo->commit();
            redirect_jadwal('jadwal.php?saved=1');
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
            $error = 'Jadwal gagal disimpan. Periksa data dan koneksi database.';
        }
    }
}

$technicians = $pdo->query('SELECT id_petugas, nama, keahlian FROM petugas ORDER BY nama')->fetchAll();
$orders = $pdo->query(
    "SELECT p.id_pesanan, c.nama AS nama_pelanggan, p.waktu_layanan, p.status_pekerjaan
     FROM pesanan p
     JOIN pelanggan c ON c.id_pelanggan = p.id_pelanggan
     WHERE p.status_pekerjaan NOT IN ('Selesai', 'Dibatalkan')
       AND NOT EXISTS (SELECT 1 FROM jadwal j WHERE j.id_pesanan = p.id_pesanan)
     ORDER BY p.waktu_layanan, p.id_pesanan"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && in_array($action, ['create', 'edit'], true)) {
    if ($action === 'create' && $form['id_pesanan'] !== '') {
        $stmt = $pdo->prepare('SELECT status_pekerjaan FROM pesanan WHERE id_pesanan = ?');
        $stmt->execute([(int) $form['id_pesanan']]);
        $selectedOrderStatus = $stmt->fetchColumn();
        if (in_array($selectedOrderStatus, $listStatuses, true)) {
            $form['status_jadwal'] = $selectedOrderStatus;
        }
    } elseif ($action === 'edit') {
        if (!$id || $id < 1) {
            redirect_jadwal('jadwal.php');
        }
        $stmt = $pdo->prepare('SELECT * FROM jadwal WHERE id_jadwal = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            http_response_code(404);
            exit('Jadwal tidak ditemukan.');
        }
        $form = array_merge($form, $existing);
        $form['status_jadwal'] = $orderStatusBySchedule[$existing['status_jadwal']] ?? 'Dijadwalkan';
        $form['waktu_mulai'] = date('Y-m-d\\TH:i', strtotime($existing['waktu_mulai']));
    }
}

$currentScheduleId = $action === 'edit' ? (int) ($form['id_jadwal'] ?? $id ?? 0) : 0;
$busySql = "SELECT DISTINCT j.id_petugas
            FROM jadwal j
            JOIN pesanan p ON p.id_pesanan = j.id_pesanan
            WHERE p.status_pekerjaan IN ('Dijadwalkan', 'Diproses')";
$busyParams = [];
if ($currentScheduleId > 0) {
    $busySql .= ' AND j.id_jadwal <> ?';
    $busyParams[] = $currentScheduleId;
}
$busyStmt = $pdo->prepare($busySql);
$busyStmt->execute($busyParams);
$busyTechnicianIds = array_map('intval', $busyStmt->fetchAll(PDO::FETCH_COLUMN));

$search = trim((string) ($_GET['q'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$rows = [];
if ($action === 'list') {
            $sql = "SELECT j.id_jadwal, p.id_pesanan, COALESCE(j.waktu_mulai, p.waktu_layanan) AS waktu_mulai,
                   j.status_jadwal, p.status_pekerjaan, p.alamat_layanan,
                   c.nama AS nama_pelanggan, t.nama AS nama_teknisi,
                   GROUP_CONCAT(DISTINCT l.nama_layanan ORDER BY l.nama_layanan SEPARATOR ', ') AS layanan
            FROM pesanan p
            JOIN pelanggan c ON c.id_pelanggan = p.id_pelanggan
            LEFT JOIN jadwal j ON j.id_pesanan = p.id_pesanan
            LEFT JOIN petugas t ON t.id_petugas = j.id_petugas
            LEFT JOIN detail_pesanan dp ON dp.id_pesanan = p.id_pesanan
            LEFT JOIN layanan l ON l.id_layanan = dp.id_layanan";
    $conditions = ["p.status_pekerjaan IN ('Dijadwalkan', 'Diproses')"];
    $params = [];
    if ($search !== '') {
        $conditions[] = '(c.nama LIKE ? OR t.nama LIKE ? OR CAST(p.id_pesanan AS CHAR) LIKE ? OR l.nama_layanan LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term);
    }
    if (in_array($filterStatus, $listStatuses, true)) {
        $conditions[] = 'p.status_pekerjaan = ?';
        $params[] = $filterStatus;
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' GROUP BY p.id_pesanan, j.id_jadwal ORDER BY COALESCE(j.waktu_mulai, p.waktu_layanan) DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$activePage = 'jadwal';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jadwal Pekerjaan | RumahCare</title>
<link rel="stylesheet" href="assets/css/app.css"><script src="assets/js/sidebar.js" defer></script>
<style>
.busy-toast { position:fixed; z-index:100; top:18px; left:18px; max-width:min(360px, calc(100vw - 36px)); padding:13px 17px; border-radius:10px; color:#fff; background:#24352d; box-shadow:0 8px 24px #13251d40; font-size:13px; font-weight:650; opacity:0; transform:translate(-120%, -12px); pointer-events:none; transition:transform .28s ease, opacity .28s ease; }
.busy-toast.show { opacity:1; transform:translate(0, 0); }
</style>
</head>
<body><div class="app-shell">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<div class="busy-toast" id="busyToast" role="status" aria-live="polite" data-show="<?= str_contains($error, 'Teknisi masih dalam pekerjaan') ? '1' : '0' ?>">Teknisi masih dalam pekerjaan, pilih lainnya</div>
<main class="main-content">
<header class="page-header"><div><p class="breadcrumb">RumahCare / Operasional</p><h1>Jadwal Pekerjaan</h1></div>
<?php if ($action === 'list'): ?><a class="btn btn-primary" href="jadwal.php?action=create">＋ Atur Jadwal</a><?php endif; ?></header>
<?php if ($success): ?><div class="notice success"><?= h_jadwal($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h_jadwal($error) ?></div><?php endif; ?>

<?php if (in_array($action, ['create', 'edit'], true)): ?>
<a class="back-link" href="jadwal.php">← Kembali ke daftar jadwal</a>
<section class="form-card">
<div class="panel-header"><h2><?= $action === 'edit' ? 'Ubah Jadwal' : 'Atur Jadwal Pesanan' ?></h2><p>Tentukan teknisi dan waktu mulai. Teknisi yang masih menangani pekerjaan aktif tidak dapat dipilih.</p></div>
<form method="post" action="jadwal.php">
<input type="hidden" name="csrf_token" value="<?= h_jadwal($_SESSION['csrf_token']) ?>">
<input type="hidden" name="id_jadwal" value="<?= h_jadwal($form['id_jadwal']) ?>">
<div class="form-grid">
<label class="field">Pesanan <b>*</b>
<?php if ($action === 'edit'): ?><input type="hidden" name="id_pesanan" value="<?= (int) $form['id_pesanan'] ?>"><input value="Pesanan #<?= (int) $form['id_pesanan'] ?>" disabled>
<?php else: ?><select name="id_pesanan" id="orderSelect" required><option value="">Pilih pesanan yang belum dijadwalkan</option><?php foreach ($orders as $order): ?><option value="<?= (int) $order['id_pesanan'] ?>" data-schedule-status="<?= h_jadwal($order['status_pekerjaan']) ?>" <?= (string) $form['id_pesanan'] === (string) $order['id_pesanan'] ? 'selected' : '' ?>>#<?= (int) $order['id_pesanan'] ?> · <?= h_jadwal($order['nama_pelanggan']) ?> · <?= h_jadwal(date('d-m-Y H:i', strtotime($order['waktu_layanan']))) ?> · <?= h_jadwal($order['status_pekerjaan']) ?></option><?php endforeach; ?></select><?php endif; ?></label>
<label class="field">Teknisi <b>*</b><select name="id_petugas" id="technicianSelect" required><option value="">Pilih teknisi</option><?php foreach ($technicians as $technician): $isBusy = in_array((int) $technician['id_petugas'], $busyTechnicianIds, true); ?><option value="<?= (int) $technician['id_petugas'] ?>" data-busy="<?= $isBusy ? '1' : '0' ?>" <?= (string) $form['id_petugas'] === (string) $technician['id_petugas'] ? 'selected' : '' ?>><?= h_jadwal($technician['nama']) ?><?= $isBusy ? ' (sedang bekerja)' : '' ?></option><?php endforeach; ?></select></label>
<label class="field">Waktu mulai <b>*</b><input type="datetime-local" name="waktu_mulai" required value="<?= h_jadwal($form['waktu_mulai']) ?>"></label>
<label class="field">Status jadwal <b>*</b><select name="status_jadwal" required><?php foreach ($statuses as $status): ?><option value="<?= h_jadwal($status) ?>" <?= $form['status_jadwal'] === $status ? 'selected' : '' ?>><?= h_jadwal($status) ?></option><?php endforeach; ?></select></label>
<?php if ($action === 'edit'): ?><label class="field full">Alasan perubahan waktu<input name="alasan_perubahan" maxlength="500" value="<?= h_jadwal($form['alasan_perubahan']) ?>" placeholder="Wajib diisi jika waktu diubah"></label><label class="approval-check field full"><input type="checkbox" name="persetujuan_pelanggan" value="1" <?= $form['persetujuan_pelanggan'] === '1' ? 'checked' : '' ?>> Saya sudah mengonfirmasi persetujuan pelanggan atas waktu baru (wajib jika waktu diubah).</label><?php endif; ?>
</div><div class="form-actions"><a class="btn btn-secondary" href="jadwal.php">Batal</a><button class="btn btn-primary" type="submit">Simpan Jadwal</button></div>
</form></section>
<?php if ($action === 'create'): ?><script>
const orderSelect = document.getElementById('orderSelect');
const scheduleStatus = document.querySelector('[name="status_jadwal"]');
function syncScheduleStatus() {
    const selected = orderSelect.options[orderSelect.selectedIndex];
    if (selected && selected.dataset.scheduleStatus) scheduleStatus.value = selected.dataset.scheduleStatus;
}
orderSelect.addEventListener('change', syncScheduleStatus);
syncScheduleStatus();
</script><?php endif; ?>
<script>
const technicianSelect = document.getElementById('technicianSelect');
const busyToast = document.getElementById('busyToast');
let availableTechnician = technicianSelect.value;
let busyToastTimer;
function showBusyToast() {
    busyToast.classList.add('show');
    window.clearTimeout(busyToastTimer);
    busyToastTimer = window.setTimeout(() => busyToast.classList.remove('show'), 3500);
}
technicianSelect.addEventListener('change', () => {
    const selected = technicianSelect.options[technicianSelect.selectedIndex];
    if (selected && selected.dataset.busy === '1') {
        technicianSelect.value = availableTechnician;
        showBusyToast();
        return;
    }
    availableTechnician = technicianSelect.value;
});
if (busyToast.dataset.show === '1') showBusyToast();
</script>
<?php else: ?>
<section class="table-panel"><div class="toolbar"><form class="search-form" method="get" action="jadwal.php"><input type="search" name="q" value="<?= h_jadwal($search) ?>" placeholder="Cari pesanan, pelanggan, teknisi, layanan"><select name="status"><option value="">Semua Status Aktif</option><?php foreach ($listStatuses as $status): ?><option value="<?= h_jadwal($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= h_jadwal($status) ?></option><?php endforeach; ?></select><button class="btn btn-secondary" type="submit">Cari</button></form><span class="result-count"><?= count($rows) ?> pekerjaan</span></div>
<div class="table-scroll"><table><thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Layanan</th><th>Waktu</th><th>Teknisi</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="empty">Belum ada jadwal dengan pesanan berstatus Dijadwalkan atau Diproses.</td></tr><?php else: foreach ($rows as $row): ?><tr>
<td><strong>#<?= (int) $row['id_pesanan'] ?></strong></td><td><?= h_jadwal($row['nama_pelanggan']) ?></td><td><?= h_jadwal($row['layanan'] ?: '-') ?></td>
<td><?= h_jadwal(date('d-m-Y H:i', strtotime($row['waktu_mulai']))) ?></td>
<td><?= h_jadwal($row['nama_teknisi'] ?: 'Belum ditentukan') ?></td><td><span class="status-pill"><?= h_jadwal($row['id_jadwal'] ? ($orderStatusBySchedule[$row['status_jadwal']] ?? $row['status_pekerjaan']) : 'Belum dijadwalkan · ' . $row['status_pekerjaan']) ?></span></td>
<td><?php if ($row['id_jadwal']): ?><a class="edit-link" href="jadwal.php?action=edit&id=<?= (int) $row['id_jadwal'] ?>">Ubah Jadwal</a><?php else: ?><a class="edit-link" href="jadwal.php?action=create&order=<?= (int) $row['id_pesanan'] ?>">Atur Jadwal</a><?php endif; ?></td></tr><?php endforeach; endif; ?>
</tbody></table></div><div class="table-footer">Teknisi hanya dapat memiliki satu pekerjaan aktif sampai statusnya Selesai atau Dibatalkan.</div></section>
<?php endif; ?>
</main></div></body></html>
