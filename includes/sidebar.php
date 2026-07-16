<?php
/**
 * sidebar.php - Menu navigasi sidebar berdasarkan role
 * Dipanggil setelah header.php, jadi session & $menu_base_path sudah tersedia
 */

$current_file = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));
$is_dashboard_active = ($current_dir == 'dashboard' && $current_file == 'index.php');

// Fungsi isActive (sudah di functions.php, tapi kita definisikan ulang di sini jika perlu)
// Sebaiknya panggil dari functions.php, tapi jika tidak, pastikan tersedia:
if (!function_exists('isActive')) {
    function isActive($fileName, $submenuItems = []) {
        global $current_file;
        if ($current_file == $fileName) return true;
        if (in_array($current_file, $submenuItems)) return true;
        return false;
    }
}

global $menu_base_path;
if (!isset($menu_base_path)) {
    if (hasRole('super_admin')) $menu_base_path = '../super-admin/';
    elseif (hasRole('admin_opd')) $menu_base_path = '../admin-opd/';
    elseif (hasRole('kepala_opd')) $menu_base_path = '../kepala-opd/';
    else $menu_base_path = '';
}
?>

<div class="sidebar" id="mainSidebar">
    <ul class="nav flex-column">
        <!-- DASHBOARD -->
        <li class="nav-item">
            <a class="nav-link <?= $is_dashboard_active ? 'active' : '' ?>" href="../dashboard/index.php">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </li>

        <?php if (hasRole('super_admin')): ?>
            <!-- KUNCI DATA -->
            <li class="nav-item">
                <a class="nav-link has-submenu <?= isActive('', ['lock-anggaran.php','lock-realisasi.php']) ? 'active' : '' ?>" 
                   href="#kunciDataSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                    <i class="bi bi-lock-fill"></i> Kunci Data
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="collapse" id="kunciDataSubmenu">
                    <div class="submenu">
                        <a class="submenu-item <?= isActive('lock-anggaran.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>lock-anggaran.php">
                            <i class="bi bi-lock-fill"></i> Anggaran
                        </a>
                        <a class="submenu-item <?= isActive('lock-realisasi.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>lock-realisasi.php">
                            <i class="bi bi-lock-fill"></i> Realisasi
                        </a>
                    </div>
                </div>
            </li>

            <!-- LAPORAN -->
            <li class="nav-item">
                <a class="nav-link has-submenu <?= isActive('', ['rincian_belanja.php','apbd.php']) ? 'active' : '' ?>" 
                   href="#laporanSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                    <i class="bi bi-file-text"></i> Laporan
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="collapse" id="laporanSubmenu">
                    <div class="submenu">
                        <a class="submenu-item <?= isActive('apbd.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>apbd.php">
                            <i class="bi bi-bar-chart-steps"></i> APBD
                        </a>
                        <a class="submenu-item <?= isActive('rincian_belanja.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>rincian_belanja.php">
                            <i class="bi bi-receipt"></i> Rincian Belanja
                        </a>
                    </div>
                </div>
            </li>

            <!-- REKENING -->
            <li class="nav-item">
                <a class="nav-link has-submenu <?= isActive('', ['program.php','belanja.php']) ? 'active' : '' ?>" 
                   href="#rekeningSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                    <i class="bi bi-cash-stack"></i> Rekening
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="collapse" id="rekeningSubmenu">
                    <div class="submenu">
                        <a class="submenu-item <?= isActive('program.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>program.php">
                            <i class="bi bi-diagram-3"></i> Program
                        </a>
                        <a class="submenu-item <?= isActive('belanja.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>belanja.php">
                            <i class="bi bi-receipt"></i> Belanja
                        </a>
                    </div>
                </div>
            </li>

            <!-- MANAJEMEN -->
            <li class="nav-item">
                <a class="nav-link has-submenu <?= isActive('', ['opd-management.php','user-management.php']) ? 'active' : '' ?>" 
                   href="#manajemenSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                    <i class="bi bi-gear"></i> Manajemen
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="collapse" id="manajemenSubmenu">
                    <div class="submenu">
                        <a class="submenu-item <?= isActive('manajemen_waktu.php') ? 'active' : '' ?>" 
                        href="<?= $menu_base_path ?>manajemen_waktu.php">
                            <i class="bi bi-clock-history"></i> Waktu Kunci
                        </a>
                        <a class="submenu-item <?= isActive('opd-management.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>opd-management.php">
                            <i class="bi bi-building"></i> OPD
                        </a>
                        <a class="submenu-item <?= isActive('user-management.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>user-management.php">
                            <i class="bi bi-people-fill"></i> User
                        </a>
                    </div>
                </div>
            </li>

        <?php elseif (hasRole('admin_opd')): ?>
            <!-- ADMIN OPD: Input Anggaran -->
            <li class="nav-item">
                <a class="nav-link has-submenu <?= isActive('', ['input_anggaran.php','import_excel.php','data_anggaran.php']) ? 'active' : '' ?>" 
                   href="#inputAnggaranSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                    <i class="bi bi-calculator-fill"></i> Input Anggaran
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="collapse" id="inputAnggaranSubmenu">
                    <div class="submenu">
                        <a class="submenu-item <?= isActive('input_anggaran.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>input_anggaran.php">
                            <i class="bi bi-pencil-square"></i> Input Manual
                        </a>
                        <a class="submenu-item <?= isActive('import_excel.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>import_excel.php">
                            <i class="bi bi-file-excel-fill"></i> Import Data
                        </a>
                        <a class="submenu-item <?= isActive('data_anggaran.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>data_anggaran.php">
                            <i class="bi bi-table"></i> Data
                        </a>
                    </div>
                </div>
            </li>

            <!-- INPUT REALISASI -->
            <li class="nav-item">
                <a class="nav-link has-submenu <?= isActive('', ['input_realisasi.php','data_realisasi.php']) ? 'active' : '' ?>" 
                   href="#inputRealisasiSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                    <i class="bi bi-pencil-square"></i> Input Realisasi
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="collapse" id="inputRealisasiSubmenu">
                    <div class="submenu">
                        <a class="submenu-item <?= isActive('data_realisasi.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>data_realisasi.php">
                            <i class="bi bi-table"></i> Data
                        </a>
                    </div>
                </div>
            </li>

            <!-- LAPORAN ADMIN -->
            <li class="nav-item">
                <a class="nav-link has-submenu <?= isActive('', ['apbd_opd.php','rincian_belanja_opd.php']) ? 'active' : '' ?>" 
                   href="#laporanAdminSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                    <i class="bi bi-file-text"></i> Laporan
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="collapse" id="laporanAdminSubmenu">
                    <div class="submenu">
                        <a class="submenu-item <?= isActive('apbd_opd.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>apbd_opd.php">
                            <i class="bi bi-bar-chart-steps"></i> APBD
                        </a>
                        <a class="submenu-item <?= isActive('rincian_belanja_opd.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>rincian_belanja_opd.php">
                            <i class="bi bi-receipt"></i> Rincian Belanja
                        </a>
                    </div>
                </div>
            </li>

            <!-- Permasalahan -->
            <li class="nav-item">
                <a class="nav-link <?= isActive('permasalahan.php') ? 'active' : '' ?>" 
                   href="<?= $menu_base_path ?>permasalahan.php">
                    <i class="bi bi-exclamation-triangle"></i> Permasalahan
                </a>
            </li>

        <?php elseif (hasRole('kepala_opd')): ?>
            <!-- VALIDASI -->
            <li class="nav-item">
                <a class="nav-link has-submenu <?= isActive('', ['validasi_anggaran.php','validasi_realisasi.php']) ? 'active' : '' ?>" 
                   href="#validasiSubmenu" data-bs-toggle="collapse" aria-expanded="false">
                    <i class="bi bi-check-circle"></i> Validasi
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="collapse" id="validasiSubmenu">
                    <div class="submenu">
                        <a class="submenu-item <?= isActive('validasi_anggaran.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>validasi_anggaran.php?tahun=<?= date('Y') ?>">
                            <i class="bi bi-bar-chart-steps"></i> Anggaran
                        </a>
                        <a class="submenu-item <?= isActive('validasi_realisasi.php') ? 'active' : '' ?>" 
                           href="<?= $menu_base_path ?>validasi_realisasi.php?tahun=<?= date('Y') ?>">
                            <i class="bi bi-pencil-square"></i> Realisasi
                        </a>
                    </div>
                </div>
            </li>

            <li class="nav-item">
                <a class="nav-link <?= isActive('ttd.php') ? 'active' : '' ?>" 
                   href="<?= $menu_base_path ?>ttd.php">
                    <i class="bi bi-pen"></i> Tanda Tangan
                </a>
            </li>
        <?php endif; ?>
    
        <!-- PROFIL (untuk semua role) -->
        <li class="nav-item">
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : '' ?>" 
               href="../profile/profile.php">
                <i class="bi bi-person-circle"></i> Profil Saya
            </a>
        </li>
    </ul>
</div>

<style>
/* Sidebar dengan warna cerah */
.sidebar {
    background: #ffffff;
    border-right: 1px solid #e9ecef;
}
.sidebar .nav-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1.2rem;
    color: #2c3e50;
    font-weight: 500;
    transition: all 0.2s;
    cursor: pointer;
    text-decoration: none;
}
.sidebar .nav-link i:first-child {
    width: 1.5rem;
    font-size: 1.2rem;
    color: #5a6e7c;
}
.sidebar .nav-link .ms-auto {
    margin-left: auto;
    transition: transform 0.2s;
    color: #8a9aa8;
}
.sidebar .nav-link[aria-expanded="true"] .ms-auto {
    transform: rotate(180deg);
}
.sidebar .nav-link:hover {
    background: #f1f5f9;
    color: #1a3a5c;
}
.sidebar .nav-link:hover i:first-child {
    color: #c9a84c;
}
.sidebar .nav-link.active {
    background: #fef9e6;
    color: #b08a2c;
    border-left: 3px solid #c9a84c;
}
.sidebar .nav-link.active i:first-child {
    color: #c9a84c;
}

/* Submenu styling - warna soft */
.sidebar .submenu {
    background: #f8fafc;
    border-radius: 0 0 10px 10px;
    padding: 0.25rem 0;
    margin-left: 0.5rem;
    border-left: 2px solid #e2e8f0;
}
.sidebar .submenu-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 1rem 0.6rem 2.5rem;
    color: #334155;
    text-decoration: none;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.sidebar .submenu-item i {
    width: 1.3rem;
    font-size: 0.9rem;
    color: #7e8c9e;
}
.sidebar .submenu-item:hover {
    background: #eef2ff;
    color: #1a3a5c;
}
.sidebar .submenu-item:hover i {
    color: #c9a84c;
}
.sidebar .submenu-item.active {
    background: #fef9e6;
    color: #b08a2c;
    border-left: 3px solid #c9a84c;
}
.sidebar .submenu-item.active i {
    color: #c9a84c;
}
/* Animasi collapse */
.collapse {
    transition: all 0.25s ease;
}
/* Responsif */
@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        position: fixed;
        z-index: 1040;
        width: 260px;
        height: 100%;
        top: 70px;
        left: 0;
        overflow-y: auto;
    }
    .sidebar.mobile-open {
        transform: translateX(0);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inisialisasi collapse Bootstrap
    var collapseElements = document.querySelectorAll('.sidebar .collapse');
    collapseElements.forEach(function(collapse) {
        new bootstrap.Collapse(collapse, { toggle: false });
    });
});
</script>