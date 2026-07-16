<?php
/**
 * functions.php - Kumpulan fungsi inti e-Pengendalian
 * Keamanan: Query menggunakan prepared statement untuk mencegah SQL injection
 */

// Cek login
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Redirect jika belum login
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/modules/auth/login.php');
        exit();
    }
}

// Cek role
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Hitung persentase
function hitungPersentase($realisasi, $anggaran) {
    if ($anggaran == 0) return 0;
    return round(($realisasi / $anggaran) * 100, 2);
}

// Escape string (tetap untuk backward compatibility, gunakan dengan hati-hati)
function escape($str) {
    global $conn;
    if (!$conn) {
        error_log('Database connection lost in escape()');
        return false;
    }
    return mysqli_real_escape_string($conn, $str);
}

// ========== Fungsi untuk rekening, kegiatan, sub kegiatan ==========

/**
 * Ambil daftar rekening (query statis, aman)
 */
function getRekeningList($conn, $selected = null) {
    $query = "SELECT id, kode_rekening, nama_rekening FROM rekening ORDER BY kode_rekening";
    $result = mysqli_query($conn, $query);
    if (!$result) {
        error_log('Query error getRekeningList: ' . mysqli_error($conn));
        return '';
    }
    $options = '';
    while ($row = mysqli_fetch_assoc($result)) {
        $selectedAttr = ($selected == $row['id']) ? 'selected' : '';
        // Gunakan htmlspecialchars untuk mencegah XSS pada output
        $kode = htmlspecialchars($row['kode_rekening'], ENT_QUOTES, 'UTF-8');
        $nama = htmlspecialchars($row['nama_rekening'], ENT_QUOTES, 'UTF-8');
        $options .= "<option value='{$row['id']}' $selectedAttr>{$kode} - {$nama}</option>";
    }
    return $options;
}

/**
 * Ambil kegiatan berdasarkan rekening (menggunakan prepared statement)
 */
function getKegiatanByRekening($conn, $rekening_id) {
    $stmt = mysqli_prepare($conn, 
        "SELECT id, kode_kegiatan, nama_kegiatan 
         FROM kegiatan 
         WHERE rekening_id = ? 
         ORDER BY kode_kegiatan"
    );
    if (!$stmt) {
        error_log('Prepare error getKegiatanByRekening: ' . mysqli_error($conn));
        return [];
    }
    mysqli_stmt_bind_param($stmt, "i", $rekening_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $data;
}

/**
 * Ambil sub-kegiatan berdasarkan kegiatan (menggunakan prepared statement)
 */
function getSubKegiatanByKegiatan($conn, $kegiatan_id) {
    $stmt = mysqli_prepare($conn,
        "SELECT id, kode_sub_kegiatan, nama_sub_kegiatan 
         FROM sub_kegiatan 
         WHERE kegiatan_id = ? 
         ORDER BY kode_sub_kegiatan"
    );
    if (!$stmt) {
        error_log('Prepare error getSubKegiatanByKegiatan: ' . mysqli_error($conn));
        return [];
    }
    mysqli_stmt_bind_param($stmt, "i", $kegiatan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $data;
}

/**
 * Ekstrak teks dari PDF menggunakan Smalot/PdfParser
 */
function extractTextFromPDF($filePath) {
    if (!file_exists($filePath)) {
        error_log("File PDF tidak ditemukan: $filePath");
        return '';
    }
    require_once __DIR__ . '/../vendor/autoload.php';
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($filePath);
    return $pdf->getText();
}

/**
 * Parse teks menjadi array anggaran (regex based)
 */
function parseAnggaranFromText($text) {
    $data = [];
    $pattern = '/\b(\d+(?:\.\d+){4,})\b/';
    preg_match_all($pattern, $text, $matches);
    $kode_rekenings = array_unique($matches[0]);
    $lines = explode("\n", $text);
    foreach ($kode_rekenings as $kode) {
        $nama = '';
        foreach ($lines as $i => $line) {
            if (strpos($line, $kode) !== false && isset($lines[$i+1])) {
                $nama = trim($lines[$i+1]);
                break;
            }
        }
        $data[] = [
            'kode_rekening' => $kode,
            'nama_rekening' => $nama,
            'anggaran' => 0
        ];
    }
    return $data;
}

/**
 * Konversi format angka bertitik ke integer (untuk database)
 */
function angkaToDatabase($angka) {
    return str_replace('.', '', $angka);
}
?>