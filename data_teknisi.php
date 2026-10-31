<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h_teknisi(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_teknisi(string $url): never
{
    header('Location: ' . $url);
    exit;
}

$action = $_GET['action'] ?? 'list';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';
$success = '';
$form = ['id_petugas' => '', 'nama' => '', 'nomor_telepon' => '', 'email_login' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman.');
    }

    $form['id_petugas'] = trim((string) ($_POST['id_petugas'] ?? ''));
    $form['nama'] = trim((string) ($_POST['nama'] ?? ''));
    $form['nomor_telepon'] = trim((string) ($_POST['nomor_telepon'] ?? ''));
    $form['email_login'] = trim((string) ($_POST['email_login'] ?? ''));
    $password = $_POST['password_login'] ?? '';
    $password = is_string($password) ? $password : '';
    $technicianId = $form['id_petugas'] === '' ? null : filter_var($form['id_petugas'], FILTER_VALIDATE_INT);
    $action = $technicianId === null ? 'create' : 'edit';

    if ($technicianId !== null && (!$technicianId || $technicianId < 1)) {
        $error = 'ID teknisi tidak valid.';
    } elseif ($form['nama'] === '' || mb_strlen($form['nama']) > 100) {
        $error = 'Nama teknisi wajib diisi dan maksimal 100 karakter.';
    } elseif ($form['nomor_telepon'] === '' || mb_strlen($form['nomor_telepon']) > 20 ||
              !preg_match('/^[0-9+()\\-\\s]+$/', $form['nomor_telepon'])) {
        $error = 'Masukkan nomor HP yang valid (maksimal 20 karakter).';
    } elseif ($technicianId === null && (($form['email_login'] === '') !== ($password === ''))) {
        $error = 'Isi email dan kata sandi bersama-sama untuk membuat akun portal.';
    } elseif ($form['email_login'] !== '' && (!filter_var($form['email_login'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email_login']) > 150)) {
        $error = 'Masukkan alamat email yang valid.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = 'Kata sandi akun portal harus minimal 8 karakter.';
    } else {
        try {
            $pdo->beginTransaction();
            if ($technicianId === null) {
                $stmt = $pdo->prepare('INSERT INTO petugas (nama, nomor_telepon) VALUES (?, ?)');
                $stmt->execute([$form['nama'], $form['nomor_telepon']]);
                $technicianId = (int) $pdo->lastInsertId();
                if ($form['email_login'] !== '' && $password !== '') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO pengguna (nama, email, password_hash, role, id_petugas)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$form['nama'], $form['email_login'], password_hash($password, PASSWORD_DEFAULT), 'petugas', $technicianId]);
                }
            } else {
                $stmt = $pdo->prepare('UPDATE petugas SET nama = ?, nomor_telepon = ? WHERE id_petugas = ?');
                $stmt->execute([$form['nama'], $form['nomor_telepon'], $technicianId]);
                if ($stmt->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM petugas WHERE id_petugas = ?');
                    $exists->execute([$technicianId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('Data teknisi tidak ditemukan.');
                    }
                }

                $accountStmt = $pdo->prepare(
                    "SELECT id_pengguna, email FROM pengguna
                     WHERE id_petugas = ? AND role = 'petugas'
                     ORDER BY id_pengguna LIMIT 1 FOR UPDATE"
                );
                $accountStmt->execute([$technicianId]);
                $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
                if ($account) {
                    $email = $form['email_login'] !== '' ? $form['email_login'] : $account['email'];
                    if ($password !== '') {
                        $stmt = $pdo->prepare('UPDATE pengguna SET nama = ?, email = ?, password_hash = ? WHERE id_pengguna = ?');
                        $stmt->execute([$form['nama'], $email, password_hash($password, PASSWORD_DEFAULT), $account['id_pengguna']]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE pengguna SET nama = ?, email = ? WHERE id_pengguna = ?');
                        $stmt->execute([$form['nama'], $email, $account['id_pengguna']]);
                    }
                } elseif ($form['email_login'] !== '' || $password !== '') {
                    if ($form['email_login'] === '' || $password === '') {
                        throw new RuntimeException('Isi email dan kata sandi untuk membuat akun portal teknisi.');
                    }
                    $stmt = $pdo->prepare(
                        'INSERT INTO pengguna (nama, email, password_hash, role, id_petugas)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$form['nama'], $form['email_login'], password_hash($password, PASSWORD_DEFAULT), 'petugas', $technicianId]);
                }
            }
            $pdo->commit();
            redirect_teknisi('data_teknisi.php?saved=1');
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
            $error = $e->getCode() === '23000'
                ? 'Email akun portal sudah digunakan. Gunakan email lain.'
                : 'Data teknisi gagal disimpan. Periksa kembali data dan koneksi database.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && in_array($action, ['create', 'edit'], true)) {
    if ($action === 'edit') {
        if (!$id || $id < 1) {
            redirect_teknisi('data_teknisi.php');
        }
        $stmt = $pdo->prepare(
            "SELECT t.id_petugas, t.nama, t.nomor_telepon, u.email AS email_login
             FROM petugas t
             LEFT JOIN pengguna u ON u.id_petugas = t.id_petugas AND u.role = 'petugas'
             WHERE t.id_petugas = ?
             ORDER BY u.id_pengguna LIMIT 1"
        );
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            http_response_code(404);
            exit('Data teknisi tidak ditemukan.');
        }
        $form = $existing;
    }
}

if (isset($_GET['saved'])) {
    $success = 'Data teknisi berhasil disimpan.';
}

$search = trim((string) ($_GET['q'] ?? ''));
$technicians = [];
if ($action === 'list') {
    $sql = "SELECT t.id_petugas, t.nama, t.nomor_telepon, MAX(u.email) AS email_login,
                   COUNT(DISTINCT j.id_jadwal) AS jumlah_jadwal
            FROM petugas t
            LEFT JOIN jadwal j ON j.id_petugas = t.id_petugas
            LEFT JOIN pengguna u ON u.id_petugas = t.id_petugas AND u.role = 'petugas'";
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE t.nama LIKE ? OR t.nomor_telepon LIKE ?';
        $term = '%' . $search . '%';
        $params = [$term, $term];
    }
    $sql .= ' GROUP BY t.id_petugas, t.nama, t.nomor_telepon ORDER BY t.nama';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$activePage = 'teknisi_admin';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Teknisi | RumahCare</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/sidebar.js" defer></script>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="page-header">
            <div>
                <p class="breadcrumb">RumahCare / Operasional</p>
                <h1>Data Teknisi</h1>
            </div>
            <?php if ($action === 'list'): ?>
                <a class="btn btn-primary" href="data_teknisi.php?action=create">＋ Tambah Teknisi</a>
            <?php else: ?>
                <a class="btn btn-secondary" href="data_teknisi.php">← Kembali</a>
            <?php endif; ?>
        </header>

        <?php if ($success): ?><div class="notice success"><?= h_teknisi($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= h_teknisi($error) ?></div><?php endif; ?>

        <?php if (in_array($action, ['create', 'edit'], true)): ?>
            <a class="back-link" href="data_teknisi.php">← Kembali ke daftar teknisi</a>
            <section class="form-card">
                <div class="panel-header">
                    <h2><?= $action === 'edit' ? 'Edit Teknisi' : 'Tambah Teknisi' ?></h2>
                    <p>Isi nama dan nomor HP. Semua teknisi dapat menangani semua jenis layanan.</p>
                </div>
                <form method="post" action="data_teknisi.php">
                    <input type="hidden" name="csrf_token" value="<?= h_teknisi($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="id_petugas" value="<?= h_teknisi($form['id_petugas']) ?>">
                    <div class="form-grid">
                        <label class="field">Nama teknisi <b>*</b>
                            <input type="text" name="nama" maxlength="100" required value="<?= h_teknisi($form['nama']) ?>" autocomplete="name">
                        </label>
                        <label class="field">Nomor HP <b>*</b>
                            <input type="tel" name="nomor_telepon" maxlength="20" required value="<?= h_teknisi($form['nomor_telepon']) ?>" autocomplete="tel" placeholder="Contoh: 081234567890">
                        </label>
                        <label class="field">Email akun portal
                            <input type="email" name="email_login" maxlength="150" value="<?= h_teknisi($form['email_login'] ?? '') ?>" autocomplete="username" placeholder="Isi jika teknisi perlu login">
                            <small>Kosongkan jika akun portal belum dibuat.</small>
                        </label>
                        <label class="field">Kata sandi akun portal
                            <input type="password" name="password_login" minlength="8" autocomplete="new-password" placeholder="<?= !empty($form['email_login']) ? 'Kosongkan agar kata sandi tetap' : 'Minimal 8 karakter' ?>">
                            <small><?= !empty($form['email_login']) ? 'Isi hanya jika ingin mengganti kata sandi.' : 'Isi bersama email untuk membuat akun login teknisi.' ?></small>
                        </label>
                    </div>
                    <div class="form-actions">
                        <a class="btn btn-secondary" href="data_teknisi.php">Batal</a>
                        <button class="btn btn-primary" type="submit">Simpan Teknisi</button>
                    </div>
                </form>
            </section>
        <?php elseif ($action === 'list'): ?>
            <section class="table-panel">
                <div class="toolbar">
                    <form class="search-form" method="get" action="data_teknisi.php">
                        <input type="search" name="q" value="<?= h_teknisi($search) ?>" placeholder="Cari nama atau nomor HP">
                        <button class="btn btn-secondary" type="submit">Cari</button>
                        <?php if ($search !== ''): ?><a class="btn btn-secondary" href="data_teknisi.php">Reset</a><?php endif; ?>
                    </form>
                    <span class="result-count"><?= count($technicians) ?> teknisi</span>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>No.</th><th>Nama Teknisi</th><th>Nomor HP</th><th>Akun Portal</th><th>Jumlah Jadwal</th><th>Aksi</th></tr></thead>
                        <tbody>
                        <?php if (!$technicians): ?>
                            <tr><td colspan="6" class="empty">Belum ada data teknisi.</td></tr>
                        <?php else: ?>
                            <?php foreach ($technicians as $index => $technician): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><strong><?= h_teknisi($technician['nama']) ?></strong></td>
                                    <td><?= h_teknisi($technician['nomor_telepon']) ?></td>
                                    <td><?= h_teknisi($technician['email_login'] ?: 'Belum dibuat') ?></td>
                                    <td><?= (int) $technician['jumlah_jadwal'] ?></td>
                                    <td><a class="edit-link" href="data_teknisi.php?action=edit&id=<?= (int) $technician['id_petugas'] ?>">Edit</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">Data teknisi yang sudah memiliki jadwal tidak dihapus dari sistem agar riwayat penugasan tetap utuh.</div>
            </section>
        <?php else: ?>
            <?php http_response_code(400); ?>
            <div class="notice error">Halaman tidak ditemukan.</div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
