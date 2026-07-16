<?php
if (!defined('IN_APP')) {
    define('IN_APP', true);
}

// === SECURITY ENHANCEMENTS ===
// Set session cookie parameters sebelum start (hanya jika session belum aktif)
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = [
        'lifetime' => 0,               // sampai browser ditutup
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,            // hanya kirim melalui HTTPS (wajib di production)
        'httponly' => true,            // tidak bisa diakses JavaScript
        'samesite' => 'Strict'         // cegah CSRF dari situs lain
    ];
    // Jika tidak menggunakan HTTPS, set secure ke false agar tidak error
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        $cookieParams['secure'] = false;
    }
    session_set_cookie_params($cookieParams);
    session_start();
}

// Regenerasi ID session secara berkala (tiap request atau tiap 5 menit)
if (!isset($_SESSION['last_regeneration'])) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_waktu.php';

define('ROOT_DIR', dirname(__DIR__));  // definisi root direktori

requireLogin();  // ← CUKUP SATU KALI

// === EKSEKUSI OTOMATIS BATAS WAKTU ===
// Cek dan jalankan penguncian jika ada batas waktu yang sudah lewat
cek_dan_eksekusi_batas_waktu($conn);

// Tentukan base path untuk menu sidebar berdasarkan role
if (hasRole('super_admin')) {
    $menu_base_path = '../super-admin/';
} elseif (hasRole('admin_opd')) {
    $menu_base_path = '../admin-opd/';
} elseif (hasRole('kepala_opd')) {
    $menu_base_path = '../kepala-opd/';
} elseif (hasRole('eksekutif')) {
    $menu_base_path = '../eksekutif/';
} else {
    $menu_base_path = '';
}

// Security headers (kirim sebelum output HTML)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
// HSTS hanya jika situs full HTTPS (uncomment jika sudah)
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>E-Pengendalian - Prov. Sulawesi Tenggara</title>
    <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar-top">
    <div class="navbar-brand">
        <button class="hamburger" id="hamburgerBtn">☰</button>
        <img src="../../assets/images/logo.png" alt="Logo" class="logo-img">
        <div class="brand-text">
            <h5>E-PENGENDALIAN Ver. 2.0</h5>
            <small>Provinsi Sulawesi Tenggara</small>
        </div>
    </div>
    <div class="navbar-user">
        <span class="user-name">
            <?php 
            $foto_user = $_SESSION['foto'] ?? null;
            $foto_full_path = ROOT_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $foto_user);
            if ($foto_user && file_exists($foto_full_path)):
            ?>
                <img src="<?= BASE_URL . '/' . htmlspecialchars($foto_user) ?>" 
                    style="width:32px; height:32px; object-fit:cover; border-radius:50%; border:1px solid #fff; margin-right:8px;">
            <?php else: ?>
                <i class="bi bi-person-circle" style="font-size:1.5rem; margin-right:8px;"></i>
            <?php endif; ?>
            <span><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></span>
        </span>
        <a href="../../logout.php" class="btn btn-outline-light">Keluar</a>
    </div>
</nav>