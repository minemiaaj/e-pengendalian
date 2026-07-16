<?php
/**
 * ajax_groq.php — Endpoint AI yang aman dan langsung berfungsi
 *
 * Fitur keamanan:
 * - Hanya bisa diakses oleh pengguna yang sudah login (session)
 * - CSRF protection (dapat diaktifkan)
 * - Rate limiting per session
 * - Whitelist tab
 * - Tidak menampilkan detail error ke pengguna
 *
 * Agar langsung berjalan seperti file pertama, beberapa pengaturan keamanan
 * dinonaktifkan sementara. Aktifkan sesuai petunjuk di bawah.
 */

// Pastikan tidak ada output sebelum header JSON
if (ob_get_length()) ob_clean();

// Mulai session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===================== [ KONFIGURASI AWAL ] =====================

// 1. API KEY LANGSUNG DARI FILE PERTAMA (ganti jika perlu)
define('GROQ_API_KEY', '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');

// 2. Model & URL
$model = 'llama-3.1-8b-instant';
$url   = 'https://api.groq.com/openai/v1/chat/completions';

// 3. Keamanan: login (sesuaikan key session jika berbeda)
$login_session_key = 'user_id';        // Ganti sesuai sistem Anda
$login_required    = true;             // Matikan jika tidak ingin login

// 4. Keamanan: CSRF (ubah jadi true setelah frontend mengirim token)
$csrf_required = false;                // Saat ini false agar kompatibel dengan frontend lama
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// 5. Rate limiting
$max_requests = 10;                    // Maks permintaan per sesi
$time_window  = 60;                    // dalam detik

// 6. Whitelist tab
$allowed_tabs = ['dashboard_grafik', 'permasalahan_analysis'];

// ===================== [ LOGIN CHECK ] =====================
if ($login_required && empty($_SESSION[$login_session_key])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Silakan login terlebih dahulu.']);
    exit;
}

// ===================== [ VALIDASI PERMINTAAN ] =====================
header('Content-Type: application/json; charset=utf-8');

// Hanya POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metode tidak diizinkan']);
    exit;
}

// Baca input JSON
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!$input || !isset($input['tab'], $input['data'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Input tidak valid']);
    exit;
}

// CSRF check (hanya jika diaktifkan)
if ($csrf_required) {
    $clientToken = isset($input['csrf_token']) ? trim($input['csrf_token']) : '';
    if (!hash_equals($csrf_token, $clientToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token keamanan tidak valid']);
        exit;
    }
}

// Whitelist tab
$tab = $input['tab'];
if (!in_array($tab, $allowed_tabs, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Tab tidak dikenal']);
    exit;
}

// Batasi ukuran data mentah
if (strlen($rawInput) > 10000) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Ukuran data terlalu besar']);
    exit;
}

// ===================== [ RATE LIMITING ] =====================
if (!isset($_SESSION['groq_requests'])) {
    $_SESSION['groq_requests'] = [];
}
$now = time();
$_SESSION['groq_requests'] = array_filter($_SESSION['groq_requests'], function($t) use ($now, $time_window) {
    return ($now - $t) < $time_window;
});
if (count($_SESSION['groq_requests']) >= $max_requests) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Terlalu banyak permintaan. Silakan coba beberapa saat lagi.']);
    exit;
}
$_SESSION['groq_requests'][] = $now;
session_write_close();

// ===================== [ PERSIAPAN DATA ] =====================
$dataRingkas = $input['data'];
$jsonData = json_encode($dataRingkas, JSON_UNESCAPED_UNICODE);
if (strlen($jsonData) > 4500) {
    if (isset($dataRingkas['opd_teratas']) && is_array($dataRingkas['opd_teratas'])) {
        $dataRingkas['opd_teratas'] = array_slice($dataRingkas['opd_teratas'], 0, 10);
    }
    $jsonData = json_encode($dataRingkas, JSON_UNESCAPED_UNICODE);
}

$prompt = "Anda adalah asisten analis keuangan daerah. Data berikut adalah ringkasan realisasi anggaran OPD hingga bulan berjalan:\n" .
          $jsonData .
          "\n\nBerikan analisis 2-3 kalimat dalam bahasa Indonesia tentang performa realisasi, deviasi, dan OPD yang perlu perhatian.";

$payload = json_encode([
    'model'       => $model,
    'messages'    => [['role' => 'user', 'content' => $prompt]],
    'temperature' => 0.3,
    'max_tokens'  => 250
]);

// ===================== [ KIRIM KE GROQ ] =====================
try {
    if (!function_exists('curl_init')) {
        throw new Exception('Ekstensi cURL tidak tersedia.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer " . GROQ_API_KEY,
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        // Nonaktifkan verifikasi SSL agar tidak error di server yang CA-nya tidak lengkap
        // (sama seperti file pertama). Untuk keamanan lebih, aktifkan jika server Anda
        // memiliki sertifikat CA yang terpasang dengan benar.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr !== '') {
        throw new Exception('Gagal menghubungi server AI: ' . $curlErr);
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200 || isset($data['error'])) {
        $apiMsg = $data['error']['message'] ?? "HTTP $httpCode";
        throw new Exception('Server AI error: ' . $apiMsg);
    }

    $analysis = $data['choices'][0]['message']['content'] ?? '';
    if (empty($analysis)) {
        throw new Exception('Respons AI kosong');
    }

    $analysis = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $analysis);
    echo json_encode(['success' => true, 'analysis' => $analysis], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log error asli (jangan tampilkan ke user)
    error_log('Groq endpoint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Tidak dapat menghasilkan analisis saat ini.']);
}
exit;
