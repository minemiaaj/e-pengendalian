<?php
/**
 * Konfigurasi Database - Keamanan Tinggi
 * Jangan ubah fungsionalitas inti, hanya tambah lapisan perlindungan.
 */

// 1. Cegah akses langsung ke file ini
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Akses langsung tidak diizinkan.');
}

// 2. Mode produksi: nonaktifkan tampilan error, catat ke log
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Pastikan path log bisa ditulis oleh server
ini_set('error_log', __DIR__ . '/../logs/error_php.log'); // sesuaikan path

// 3. Muat kredensial dari file luar (jika ada), fallback ke hardcoded
$secureConfigPath = __DIR__ . '/../../secure_config.php'; // di luar public_html
if (file_exists($secureConfigPath)) {
    include $secureConfigPath;
} else {
    // Fallback nilai default (jangan gunakan password kosong di production)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root'); // ganti dengan user khusus
    define('DB_PASS', '');
    define('DB_NAME', 'epengendalian');
}

// 4. Pastikan konstanta sudah terdefinisi
if (!defined('DB_HOST') || !defined('DB_NAME')) {
    error_log('Konfigurasi database tidak lengkap.');
    http_response_code(500);
    die('Terjadi kesalahan konfigurasi. Silakan hubungi administrator.');
}

// 5. Koneksi database dengan error handling aman
// Nonaktifkan mysqli_report agar tidak menampilkan error mentah
mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    error_log('Gagal koneksi database: ' . mysqli_connect_error());
    http_response_code(503);
    die('Layanan sementara tidak tersedia. Silakan coba lagi nanti.');
}

// 6. Set charset yang aman
if (!mysqli_set_charset($conn, 'utf8mb4')) {
    error_log('Gagal set charset: ' . mysqli_error($conn));
}

// 7. Zona waktu
date_default_timezone_set('Asia/Makassar');

// ========== BASE URL ke root aplikasi ==========
$protocol = 'http';
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $protocol = 'https';
}
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $protocol = 'https';
}
$host = $_SERVER['HTTP_HOST'];

// Hitung path aplikasi relatif terhadap document root
$doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$app_root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/'); // folder root aplikasi (satu level di atas config)
$relative_path = str_replace($doc_root, '', $app_root); // contoh: /e-pengendalian
$relative_path = $relative_path ?: '/'; // fallback jika di root

define('BASE_URL', $protocol . '://' . $host . $relative_path);

// 8. Header keamanan dasar (dikirim jika output belum dimulai)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // HSTS hanya jika HTTPS selalu digunakan
    // header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
?>