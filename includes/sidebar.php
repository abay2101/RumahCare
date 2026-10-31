<?php
// includes/sidebar.php
// Set $activePage before including this file to mark the current section.
$activePage = $activePage ?? '';
$sidebarName = $_SESSION['user_name'] ?? 'Pengguna';
$sidebarRole = $_SESSION['role'] ?? '';
$isTechnician = $sidebarRole === 'petugas';
?>
<aside class="sidebar" id="appSidebar">
    <div class="sidebar-top">
        <div class="brand-row">
            <button class="brand-toggle" id="sidebarToggle" type="button"
                    aria-label="Tutup sidebar" aria-expanded="true"
                    title="Tutup sidebar">
                <span class="brand-mark">R</span>
                <span class="brand-name">Rumah<span>Care</span></span>
            </button>
        </div>

        <div class="workspace-label"><?= $isTechnician ? 'PORTAL TEKNISI' : 'WORKSPACE' ?></div>
        <nav class="sidebar-nav" aria-label="Navigasi utama">
            <?php if (($_SESSION['role'] ?? '') === 'pelanggan'): ?>
            <a class="nav-item <?= $activePage === 'portal_pelanggan' ? 'active' : '' ?>" href="portal_pelanggan.php" title="Pesanan Saya">
                <span class="nav-icon">▣</span><span class="nav-label">Pesanan Saya</span>
            </a>
            <?php elseif ($isTechnician): ?>
            <a class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>"
               href="teknisi.php" title="Dashboard">
                <span class="nav-icon">⌂</span><span class="nav-label">Dashboard</span>
            </a>
            <a class="nav-item <?= $activePage === 'pekerjaan' ? 'active' : '' ?>"
               href="teknisi.php#daftar-pekerjaan" title="Pekerjaan Saya">
                <span class="nav-icon">▣</span><span class="nav-label">Pekerjaan Saya</span>
            </a>
            <a class="nav-item <?= $activePage === 'riwayat_pekerjaan' ? 'active' : '' ?>"
               href="riwayat_pekerjaan.php" title="Riwayat Pekerjaan">
                <span class="nav-icon">◷</span><span class="nav-label">Riwayat Pekerjaan</span>
            </a>
            <?php else: ?>
            <a class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>"
               href="dashboard.php" title="Dashboard">
                <span class="nav-icon">⌂</span><span class="nav-label">Dashboard</span>
            </a>
            <a class="nav-item <?= $activePage === 'pelanggan' ? 'active' : '' ?>"
               href="pelanggan.php" title="Pelanggan">
                <span class="nav-icon">♙</span><span class="nav-label">Pelanggan</span>
            </a>
            <a class="nav-item <?= $activePage === 'layanan' ? 'active' : '' ?>"
               href="layanan.php" title="Layanan">
                <span class="nav-icon">▤</span><span class="nav-label">Layanan</span>
            </a>
            <a class="nav-item <?= $activePage === 'pesanan' ? 'active' : '' ?>"
               href="pesanan.php" title="Pesanan">
                <span class="nav-icon">▣</span><span class="nav-label">Pesanan</span>
            </a>
            <a class="nav-item <?= $activePage === 'pembayaran' ? 'active' : '' ?>"
               href="pembayaran.php" title="Pembayaran">
                <span class="nav-icon">Rp</span><span class="nav-label">Pembayaran</span>
            </a>
            <a class="nav-item <?= $activePage === 'jadwal' ? 'active' : '' ?>"
               href="jadwal.php" title="Jadwal">
                <span class="nav-icon">▦</span><span class="nav-label">Jadwal</span>
            </a>
            <a class="nav-item <?= $activePage === 'teknisi_admin' ? 'active' : '' ?>"
               href="data_teknisi.php" title="Teknisi">
                <span class="nav-icon">♟</span><span class="nav-label">Teknisi</span>
            </a>
            <?php endif; ?>
        </nav>
    </div>

    <div class="sidebar-bottom">
        <div class="sidebar-profile">
            <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($sidebarName, 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="profile-text">
                <strong><?= htmlspecialchars($sidebarName, ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars($sidebarRole, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <form method="post" action="logout.php" class="sidebar-logout">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" title="Logout">
                <span class="nav-icon">⇥</span><span class="nav-label">Logout</span>
            </button>
        </form>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
