<?php
/**
 * logout.php - Proses logout dan penghancuran session
 * 
 * Keamanan:
 * - Session dihancurkan sepenuhnya (data + cookie)
 * - Cookie session dihapus dengan parameter yang aman
 * - Redirect ke halaman login menggunakan BASE_URL
 */

// Pastikan session dimulai dengan aman
if (session_status() === PHP_SESSION_NONE) {
    // Terapkan pengaturan cookie yang aman (sama seperti di header.php)
    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict'
    ];
    session_set_cookie_params($cookieParams);
    session_start();
}

// Muat konfigurasi database (untuk mendapatkan BASE_URL)
require_once __DIR__ . '/config/database.php';

// Hapus semua data session
$_SESSION = [];

// Hapus cookie session jika ada
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict'
        ]
    );
}

// Hancurkan session
session_destroy();

// Opsional: header untuk membersihkan cache browser (modern browser)
header('Clear-Site-Data: "cookies", "storage"');

// Redirect ke halaman login
header('Location: ' . BASE_URL . '/modules/auth/login.php');
exit();