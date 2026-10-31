<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function go(string $url): never { header('Location: '.$url); exit; }

$error = ''; $success = '';
$form = ['id_pelanggan'=>'', 'nama'=>'', 'nomor_telepon'=>'', 'alamat'=>'', 'email'=>'', 'password'=>''];
$action = $_GET['action'] ?? 'list';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403); exit('Permintaan tidak valid. Muat ulang halaman.');
    }
    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'delete') {
        $deleteId = filter_input(INPUT_POST, 'id_pelanggan', FILTER_VALIDATE_INT);
        if (!$deleteId || $deleteId < 1) $error = 'ID pelanggan tidak valid.';
        else {
            try {
                $stmt = $pdo->prepare('DELETE FROM pelanggan WHERE id_pelanggan = ?');
                $stmt->execute([$deleteId]);
                go('pelanggan.php?deleted=1');
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'Pelanggan tidak dapat dihapus. Data mungkin masih digunakan oleh pesanan.';
                $action = 'list';
            }
        }
    } elseif ($postAction === 'save') {
        foreach (['nama','nomor_telepon','alamat','email','password'] as $field) {
            $v = $_POST[$field] ?? ''; $form[$field] = is_string($v) ? trim($v) : '';
        }
        $form['id_pelanggan'] = trim((string)($_POST['id_pelanggan'] ?? ''));
        $formId = $form['id_pelanggan'] === '' ? null : filter_var($form['id_pelanggan'], FILTER_VALIDATE_INT);
        $action = $formId === null ? 'create' : 'edit';

        if ($form['nama'] === '' || mb_strlen($form['nama']) > 100) $error = 'Nama wajib diisi dan maksimal 100 karakter.';
        elseif ($form['nomor_telepon'] === '' || mb_strlen($form['nomor_telepon']) > 20 || !preg_match('/^[0-9+\-\s().]{7,20}$/', $form['nomor_telepon'])) $error = 'Nomor telepon wajib diisi, maksimal 20 karakter, dan gunakan format yang valid.';
        elseif ($form['alamat'] === '') $error = 'Alamat wajib diisi.';
        elseif ($form['email'] !== '' && (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 150)) $error = 'Email akun pelanggan tidak valid.';
        elseif ($form['email'] === '' && $form['password'] !== '') $error = 'Isi email sebelum mengatur password akun portal.';
        elseif ($form['password'] !== '' && mb_strlen($form['password']) < 8) $error = 'Password akun minimal 8 karakter.';
        elseif ($formId === null && $form['email'] !== '' && $form['password'] === '') $error = 'Password wajib diisi untuk membuat akun portal pelanggan.';
        elseif ($form['id_pelanggan'] !== '' && (!$formId || $formId < 1)) $error = 'ID pelanggan tidak valid.';
        else {
            try {
                $pdo->beginTransaction();
                if ($formId === null) {
                    $stmt = $pdo->prepare('INSERT INTO pelanggan (nama, nomor_telepon, alamat) VALUES (?, ?, ?)');
                    $stmt->execute([$form['nama'], $form['nomor_telepon'], $form['alamat']]);
                    $formId = (int) $pdo->lastInsertId();
                } else {
                    $stmt = $pdo->prepare('UPDATE pelanggan SET nama = ?, nomor_telepon = ?, alamat = ? WHERE id_pelanggan = ?');
                    $stmt->execute([$form['nama'], $form['nomor_telepon'], $form['alamat'], $formId]);
                }
                $account = $pdo->prepare('SELECT id_pengguna, password_hash FROM pengguna WHERE id_pelanggan = ? FOR UPDATE');
                $account->execute([$formId]);
                $existingAccount = $account->fetch();
                if ($form['email'] === '') {
                    if ($existingAccount) {
                        $pdo->prepare('DELETE FROM pengguna WHERE id_pengguna = ?')->execute([$existingAccount['id_pengguna']]);
                    }
                } elseif ($existingAccount) {
                    $passwordHash = $form['password'] === '' ? $existingAccount['password_hash'] : password_hash($form['password'], PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE pengguna SET nama = ?, email = ?, password_hash = ?, role = 'pelanggan', id_petugas = NULL WHERE id_pengguna = ?");
                    $stmt->execute([$form['nama'], $form['email'], $passwordHash, $existingAccount['id_pengguna']]);
                } else {
                    if ($form['password'] === '') {
                        throw new DomainException('Password wajib diisi untuk membuat akun portal pelanggan.');
                    }
                    $stmt = $pdo->prepare("INSERT INTO pengguna (nama, email, password_hash, role, id_pelanggan) VALUES (?, ?, ?, 'pelanggan', ?)");
                    $stmt->execute([$form['nama'], $form['email'], password_hash($form['password'], PASSWORD_DEFAULT), $formId]);
                }
                $pdo->commit();
                go('pelanggan.php?saved=1');
            } catch (DomainException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($e->getMessage()); $error = 'Data gagal disimpan. Periksa koneksi database dan input.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($e->getMessage()); $error = 'Data gagal disimpan. Coba kembali.';
            }
        }
    } else { http_response_code(400); exit('Aksi tidak dikenal.'); }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && in_array($action, ['create','edit'], true)) {
    if ($action === 'edit') {
        if (!$id || $id < 1) go('pelanggan.php');
        $stmt = $pdo->prepare('SELECT p.id_pelanggan, p.nama, p.nomor_telepon, p.alamat, u.email, \'\' AS password FROM pelanggan p LEFT JOIN pengguna u ON u.id_pelanggan = p.id_pelanggan WHERE p.id_pelanggan = ?');
        $stmt->execute([$id]); $existing = $stmt->fetch();
        if (!$existing) { http_response_code(404); exit('Data pelanggan tidak ditemukan.'); }
        $form = $existing;
    }
}
if (isset($_GET['saved'])) $success = 'Data pelanggan berhasil disimpan.';
if (isset($_GET['deleted'])) $success = 'Data pelanggan berhasil dihapus.';

$search = trim((string)($_GET['q'] ?? '')); $customers = [];
if ($action === 'list') {
    if ($search !== '') {
        $stmt = $pdo->prepare('SELECT p.id_pelanggan, p.nama, p.nomor_telepon, p.alamat, u.email FROM pelanggan p LEFT JOIN pengguna u ON u.id_pelanggan = p.id_pelanggan WHERE p.nama LIKE ? OR p.nomor_telepon LIKE ? ORDER BY p.id_pelanggan DESC');
        $term = '%'.$search.'%'; $stmt->execute([$term, $term]);
    } else $stmt = $pdo->query('SELECT p.id_pelanggan, p.nama, p.nomor_telepon, p.alamat, u.email FROM pelanggan p LEFT JOIN pengguna u ON u.id_pelanggan = p.id_pelanggan ORDER BY p.id_pelanggan DESC');
    $customers = $stmt->fetchAll();
}
$activePage = 'pelanggan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Pelanggan | RumahCare</title>
<link rel="stylesheet" href="assets/css/app.css"><script src="assets/js/sidebar.js" defer></script>
</head>
<body><div class="app-shell">
<?php require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content">
<header class="page-header">
  <div><p class="breadcrumb">RumahCare / Master Data</p><h1>Data Pelanggan</h1></div>
  <?php if ($action === 'list'): ?><a class="btn btn-primary" href="pelanggan.php?action=create">＋ Tambah Pelanggan</a><?php endif; ?>
</header>
<?php if ($success): ?><div class="notice success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

<?php if (in_array($action, ['create','edit'], true)): ?>
<a class="back-link" href="pelanggan.php">← Kembali ke daftar pelanggan</a>
<section class="form-card">
  <div class="panel-header"><h2><?= $action === 'edit' ? 'Edit Pelanggan' : 'Tambah Pelanggan' ?></h2><p>Isi data pelanggan dengan benar.</p></div>
  <form method="post" action="pelanggan.php">
    <input type="hidden" name="action" value="save"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="id_pelanggan" value="<?= h($form['id_pelanggan']) ?>">
    <div class="form-grid">
      <label class="field">Nama pelanggan <b>*</b><input name="nama" maxlength="100" required value="<?= h($form['nama']) ?>" placeholder="Contoh: Ahmad Fauzan"></label>
      <label class="field">Nomor telepon <b>*</b><input name="nomor_telepon" maxlength="20" required value="<?= h($form['nomor_telepon']) ?>" placeholder="Contoh: 081234567890"></label>
      <label class="field full">Alamat <b>*</b><textarea name="alamat" rows="3" required placeholder="Alamat lengkap pelanggan"><?= h($form['alamat']) ?></textarea></label>
      <div class="field full"><strong>Akun Portal Pelanggan</strong><span class="muted">Opsional. Isi email dan password untuk memberi pelanggan akses melihat pesanan dan riwayatnya. Saat mengedit akun, password kosong berarti tidak diubah.</span></div>
      <label class="field">Email akun<input type="email" name="email" maxlength="150" value="<?= h($form['email']) ?>" placeholder="nama@email.com" autocomplete="off"></label>
      <label class="field">Password akun<input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Minimal 8 karakter"></label>
    </div>
    <div class="form-actions"><a class="btn btn-secondary" href="pelanggan.php">Batal</a><button class="btn btn-primary" type="submit">Simpan Pelanggan</button></div>
  </form>
</section>
<?php else: ?>
<section class="table-panel">
  <div class="toolbar"><form class="search-form" method="get" action="pelanggan.php">
    <input type="search" name="q" value="<?= h($search) ?>" placeholder="Cari nama atau nomor telepon..."><button class="btn btn-secondary" type="submit">Cari</button>
    <?php if ($search !== ''): ?><a class="btn btn-secondary" href="pelanggan.php">Reset</a><?php endif; ?>
  </form><span class="result-count"><?= count($customers) ?> data</span></div>
  <div class="table-scroll"><table>
    <thead><tr><th>No.</th><th>Nama Pelanggan</th><th>Nomor Telepon</th><th>Alamat</th><th>Akun Portal</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if (!$customers): ?><tr><td colspan="6" class="empty">Tidak ada data pelanggan yang cocok.</td></tr>
    <?php else: $nomor = 1; foreach ($customers as $customer): ?><tr>
      <td><?= $nomor++ ?></td><td><strong><?= h($customer['nama']) ?></strong></td>
      <td><?= h($customer['nomor_telepon']) ?></td><td class="address"><?= h($customer['alamat']) ?></td><td><?= $customer['email'] ? 'Aktif' : 'Belum dibuat' ?></td>
      <td><div class="actions"><a class="edit-link" href="pelanggan.php?action=edit&id=<?= (int)$customer['id_pelanggan'] ?>">Edit</a>
        <form method="post" action="pelanggan.php" onsubmit="return confirm('Yakin ingin menghapus pelanggan ini?');">
          <input type="hidden" name="action" value="delete"><input type="hidden" name="id_pelanggan" value="<?= (int)$customer['id_pelanggan'] ?>">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><button class="delete-link" type="submit">Hapus</button>
        </form></div></td>
    </tr><?php endforeach; endif; ?>
    </tbody>
  </table></div><div class="table-footer">Data pelanggan tersimpan di database RumahCare.</div>
</section>
<?php endif; ?>
</main></div></body></html>
