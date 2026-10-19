<?php
// includes/sidebar.php
// Set $activePage before including this file: dashboard, pelanggan, layanan, or pesanan.
$activePage = $activePage ?? '';
$sidebarName = $_SESSION['user_name'] ?? 'Pengguna';
$sidebarRole = $_SESSION['role'] ?? '';
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

        <div class="workspace-label">WORKSPACE</div>
        <nav class="sidebar-nav" aria-label="Navigasi utama">
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
