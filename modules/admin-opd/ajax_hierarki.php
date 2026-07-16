<?php
/**
 * ajax_hierarki.php – Endpoint AJAX untuk data hierarki & rincian belanja
 * Keamanan: Prepared statement, validasi input ketat, header JSON
 */
require_once __DIR__ . '/../../config/database.php';

// Whitelist type untuk menghindari pemanggilan sembarang
$allowedTypes = ['kegiatan', 'subkegiatan', 'rincian'];
$type = isset($_POST['type']) && in_array($_POST['type'], $allowedTypes, true) 
        ? $_POST['type'] 
        : '';

// Hanya proses jika type valid dan koneksi database tersedia
if ($type === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Parameter type tidak valid']);
    exit;
}

// Kode parent hanya digunakan untuk kegiatan/subkegiatan
if (in_array($type, ['kegiatan', 'subkegiatan'])) {
    $kode_parent = $_POST['kode_parent'] ?? '';
    // Validasi format kode (contoh: digit dan titik), cegah karakter aneh
    if (!preg_match('/^[0-9.]+$/', $kode_parent)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Format kode_parent tidak valid']);
        exit;
    }
}

// Proses berdasarkan type
if ($type === 'kegiatan' || $type === 'subkegiatan') {
    $level = ($type === 'kegiatan') ? 4 : 5;

    // Prepared statement dengan LIKE menggunakan CONCAT
    $stmt = $conn->prepare("SELECT kode, nama FROM master_hierarki 
                            WHERE level = ? AND kode LIKE CONCAT(?, '.%') 
                            ORDER BY kode");
    if (!$stmt) {
        error_log('Prepare error ajax_hierarki: ' . $conn->error);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error']);
        exit;
    }
    $stmt->bind_param('is', $level, $kode_parent);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
} 
elseif ($type === 'rincian') {
    // Query statis, tidak ada input user, aman langsung
    $query = "SELECT id, kode, nama FROM master_belanja 
              WHERE kode REGEXP '^[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+$'
              ORDER BY kode";
    $res = $conn->query($query);
    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}