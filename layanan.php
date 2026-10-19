<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function layanan_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function layanan_redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

$error = '';
$success = '';
$action = $_GET['action'] ?? 'list';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$form = [
    'id_layanan' => '',
    'nama_layanan' => '',
    'deskripsi' => '',
    'harga' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    }

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        $deleteId = filter_input(INPUT_POST, 'id_layanan', FILTER_VALIDATE_INT);
        if (!$deleteId || $deleteId < 1) {
            $error = 'ID layanan tidak valid.';
            $action = 'list';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM layanan WHERE id_layanan = ?');
                $stmt->execute([$deleteId]);
                layanan_redirect('layanan.php?deleted=1');
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'Layanan tidak dapat dihapus karena mungkin sudah digunakan oleh pesanan.';
                $action = 'list';
            }
        }
    } elseif ($postAction === 'save') {
        $rawId = trim((string) ($_POST['id_layanan'] ?? ''));
        $form['id_layanan'] = $rawId;
        foreach (['nama_layanan', 'deskripsi', 'harga'] as $field) {
            $value = $_POST[$field] ?? '';
            $form[$field] = is_string($value) ? trim($value) : '';
        }

        $formId = $rawId === '' ? null : filter_var($rawId, FILTER_VALIDATE_INT);
        $action = $rawId === '' ? 'create' : 'edit';

        if ($form['nama_layanan'] === '' || mb_strlen($form['nama_layanan']) > 100) {
            $error = 'Nama layanan wajib diisi dan maksimal 100 karakter.';
        } elseif ($form['harga'] === '' || !is_numeric($form['harga']) || (float) $form['harga'] < 0) {
            $error = 'Harga harus berupa angka nol atau lebih.';
        } elseif (strlen((string) $form['harga']) > 15 || (float) $form['harga'] > 9999999999.99) {
            $error = 'Harga terlalu besar.';
        } elseif ($rawId !== '' && (!$formId || $formId < 1)) {
            $error = 'ID layanan tidak valid.';
        } else {
            try {
                $price = number_format((float) $form['harga'], 2, '.', '');
                if ($formId === null) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO layanan (nama_layanan, deskripsi, harga) VALUES (?, ?, ?)'
                    );
                    $stmt->execute([
                        $form['nama_layanan'],
                        $form['deskripsi'] === '' ? null : $form['deskripsi'],
                        $price
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE layanan SET nama_layanan = ?, deskripsi = ?, harga = ? WHERE id_layanan = ?'
                    );
                    $stmt->execute([
                        $form['nama_layanan'],
                        $form['deskripsi'] === '' ? null : $form['deskripsi'],
                        $price,
                        $formId
                    ]);
                }
                layanan_redirect('layanan.php?saved=1');
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'Data layanan gagal disimpan. Periksa koneksi database dan data yang dimasukkan.';
            }
        }
    } else {
        http_response_code(400);
        exit('Aksi tidak dikenal.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && in_array($action, ['create', 'edit'], true)) {
    if ($action === 'edit') {
        if (!$id || $id < 1) {
            layanan_redirect('layanan.php');
        }
        $stmt = $pdo->prepare(
            'SELECT id_layanan, nama_layanan, deskripsi, harga FROM layanan WHERE id_layanan = ?'
        );
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            http_response_code(404);
            exit('Data layanan tidak ditemukan.');
        }
        $form = $existing;
    }
}

if (isset($_GET['saved'])) {
    $success = 'Data layanan berhasil disimpan.';
} elseif (isset($_GET['deleted'])) {
    $success = 'Data layanan berhasil dihapus.';
}

$search = trim((string) ($_GET['q'] ?? ''));
$services = [];
if ($action === 'list') {
    if ($search !== '') {
        $stmt = $pdo->prepare(
            'SELECT id_layanan, nama_layanan, deskripsi, harga
             FROM layanan
             WHERE nama_layanan LIKE ? OR deskripsi LIKE ?
             ORDER BY id_layanan DESC'
        );
        $term = '%' . $search . '%';
        $stmt->execute([$term, $term]);
    } else {
        $stmt = $pdo->query(
            'SELECT id_layanan, nama_layanan, deskripsi, harga
             FROM layanan
             ORDER BY id_layanan DESC'
        );
    }
    $services = $stmt->fetchAll();
}

$activePage = 'layanan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Layanan | RumahCare</title>
<link rel="stylesheet" href="assets/css/app.css">
<script src="assets/js/sidebar.js" defer></script>
<style>
.price { white-space: nowrap; font-weight: 650; color: #19362a; }
.description { min-width: 200px; max-width: 360px; line-height: 1.5; white-space: normal; }
</style>
</head>
<body>
<div class="app-shell">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content">
<header class="page-header">
    <div><p class="breadcrumb">RumahCare / Master Data</p><h1>Data Layanan</h1></div>
    <?php if ($action === 'list'): ?>
        <a class="btn btn-primary" href="layanan.php?action=create">＋ Tambah Layanan</a>
    <?php endif; ?>
</header>

<?php if ($success !== ''): ?><div class="notice success"><?= layanan_h($success) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= layanan_h($error) ?></div><?php endif; ?>

<?php if (in_array($action, ['create', 'edit'], true)): ?>
    <a class="back-link" href="layanan.php">← Kembali ke daftar layanan</a>
    <section class="form-card">
        <div class="panel-header">
            <h2><?= $action === 'edit' ? 'Edit Layanan' : 'Tambah Layanan' ?></h2>
            <p>Masukkan informasi layanan dan harga yang berlaku.</p>
        </div>
        <form method="post" action="layanan.php">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= layanan_h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="id_layanan" value="<?= layanan_h($form['id_layanan']) ?>">
            <div class="form-grid">
                <label class="field">Nama layanan <b>*</b>
                    <input name="nama_layanan" maxlength="100" required
                           value="<?= layanan_h($form['nama_layanan']) ?>"
                           placeholder="Contoh: Perawatan AC">
                </label>
                <label class="field">Harga (Rp) <b>*</b>
                    <input name="harga" type="number" min="0" step="0.01" required
                           value="<?= layanan_h($form['harga']) ?>"
                           placeholder="Contoh: 75000">
                </label>
                <label class="field full">Deskripsi
                    <textarea name="deskripsi" rows="4"
                              placeholder="Jelaskan layanan yang diberikan"><?= layanan_h($form['deskripsi']) ?></textarea>
                </label>
            </div>
            <div class="form-actions">
                <a class="btn btn-secondary" href="layanan.php">Batal</a>
                <button class="btn btn-primary" type="submit">Simpan Layanan</button>
            </div>
        </form>
    </section>
<?php else: ?>
    <section class="table-panel">
        <div class="toolbar">
            <form class="search-form" method="get" action="layanan.php">
                <input type="search" name="q" value="<?= layanan_h($search) ?>"
                       placeholder="Cari nama atau deskripsi layanan...">
                <button class="btn btn-secondary" type="submit">Cari</button>
                <?php if ($search !== ''): ?><a class="btn btn-secondary" href="layanan.php">Reset</a><?php endif; ?>
            </form>
            <span class="result-count"><?= count($services) ?> data</span>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>No.</th><th>Nama Layanan</th><th>Deskripsi</th><th>Harga</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$services): ?>
                    <tr><td colspan="5" class="empty">Tidak ada data layanan yang cocok.</td></tr>
                <?php else: $nomor = 1; foreach ($services as $service): ?>
                    <tr>
                        <td><?= $nomor++ ?></td>
                        <td><strong><?= layanan_h($service['nama_layanan']) ?></strong></td>
                        <td class="description"><?= layanan_h($service['deskripsi'] ?? '') ?: '<span style="color:#97a29d">Belum ada deskripsi</span>' ?></td>
                        <td class="price">Rp <?= number_format((float) $service['harga'], 0, ',', '.') ?></td>
                        <td>
                            <div class="actions">
                                <a class="edit-link" href="layanan.php?action=edit&id=<?= (int) $service['id_layanan'] ?>">Edit</a>
                                <form method="post" action="layanan.php"
                                      onsubmit="return confirm('Yakin ingin menghapus layanan ini?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id_layanan" value="<?= (int) $service['id_layanan'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= layanan_h($_SESSION['csrf_token']) ?>">
                                    <button class="delete-link" type="submit">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">Data layanan tersimpan di database RumahCare.</div>
    </section>
<?php endif; ?>
</main>
</div>
</body>
</html>
