<?php
/**
 * input_realisasi.php - Form input realisasi mingguan per bulan
 * 
 * Keamanan:
 * - Semua query database menggunakan prepared statement (anti SQL injection)
 * - CSRF token di setiap form POST
 * - Validasi input ketat (tipe, rentang)
 * - Output escaping dengan htmlspecialchars()
 * - Tidak ada interpolasi langsung variabel ke query
 */

require_once __DIR__ . '/../../includes/header.php';

// ========== OTORISASI ==========
if ($_SESSION['role'] !== 'admin_opd') {
    header('Location: ../dashboard/index.php');
    exit();
}

$opd_id = (int) $_SESSION['opd_id'];
$tahun  = isset($_GET['tahun'])  ? (int) $_GET['tahun']  : (int) date('Y');
$bulan  = isset($_GET['bulan'])  ? (int) $_GET['bulan']  : 0;
$anggaran_id_awal = isset($_GET['anggaran_id']) ? (int) $_GET['anggaran_id'] : 0;

// Validasi parameter
if ($bulan < 1 || $bulan > 12 || $anggaran_id_awal <= 0) {
    http_response_code(400);
    die("Parameter tidak valid.");
}

$bulan_keys      = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
$bulan_indonesia = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$bulan_key       = $bulan_keys[$bulan - 1];
$bulan_label     = $bulan_indonesia[$bulan - 1];

// ========== CSRF TOKEN ==========
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ========== FUNGSI PEMBANTU ==========

/**
 * Cek apakah minggu tertentu sudah dikunci secara global oleh Super Admin.
 * (Prepared statement dengan JSON_EXTRACT, sudah aman)
 */
function isGlobalLocked($conn, $opd_id, $tahun, $bulan_key, $minggu) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM realisasi_detail 
                            WHERE opd_id = ? AND tahun = ? 
                            AND JSON_EXTRACT(status_mingguan, ?) = 'dikunci'");
    $path = '$."' . $bulan_key . '"."' . $minggu . '"';
    $stmt->bind_param('iis', $opd_id, $tahun, $path);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($result['cnt'] > 0);
}

// ========== AMBIL DATA RINCIAN DARI ID AWAL ==========
$stmt_rincian = $conn->prepare("SELECT rincian_belanja_id, kode_program, kode_kegiatan, kode_sub_kegiatan 
                                FROM anggaran_detail WHERE id = ? AND opd_id = ?");
$stmt_rincian->bind_param('ii', $anggaran_id_awal, $opd_id);
$stmt_rincian->execute();
$rincian = $stmt_rincian->get_result()->fetch_assoc();
$stmt_rincian->close();

if (!$rincian) {
    http_response_code(404);
    die("Data anggaran tidak ditemukan.");
}

$rincian_id        = (int) $rincian['rincian_belanja_id'];
$kode_program      = $rincian['kode_program'];
$kode_kegiatan     = $rincian['kode_kegiatan'];
$kode_sub_kegiatan = $rincian['kode_sub_kegiatan'];

// Cari versi terbaru untuk kombinasi ini (prepared statement)
$stmt_latest = $conn->prepare("SELECT id FROM anggaran_detail 
                               WHERE opd_id = ? AND tahun = ? 
                                 AND rincian_belanja_id = ? 
                                 AND kode_program = ? 
                                 AND kode_kegiatan = ? 
                                 AND kode_sub_kegiatan = ? 
                               ORDER BY versi DESC LIMIT 1");
$stmt_latest->bind_param('iiisss', $opd_id, $tahun, $rincian_id, $kode_program, $kode_kegiatan, $kode_sub_kegiatan);
$stmt_latest->execute();
$latest = $stmt_latest->get_result()->fetch_assoc();
$stmt_latest->close();

if (!$latest) {
    http_response_code(404);
    die("Data anggaran versi terbaru tidak ditemukan.");
}

$anggaran_id = (int) $latest['id'];

// Ambil data anggaran lengkap (prepared statement)
$stmt_anggaran = $conn->prepare("
    SELECT ad.*, mb.kode as kode_rincian, mb.nama as nama_rincian
    FROM anggaran_detail ad
    JOIN master_belanja mb ON ad.rincian_belanja_id = mb.id
    WHERE ad.id = ? AND ad.opd_id = ? AND ad.tahun = ?
");
$stmt_anggaran->bind_param('iii', $anggaran_id, $opd_id, $tahun);
$stmt_anggaran->execute();
$anggaran = $stmt_anggaran->get_result()->fetch_assoc();
$stmt_anggaran->close();

if (!$anggaran) {
    http_response_code(404);
    die("Data anggaran tidak ditemukan.");
}

// Cek status validasi anggaran
$error_akses = null;
if ($anggaran['status_validasi'] !== 'dikunci') {
    $error_akses = "Anggaran ini belum dikunci oleh Super Admin. Status saat ini: <strong>" . htmlspecialchars($anggaran['status_validasi']) . "</strong>. Anda tidak dapat mengisi realisasi. Silakan hubungi Super Admin.";
}

// Inisialisasi variabel tampilan
$target_vol_bulan     = 0.0;
$target_pag_bulan     = 0.0;
$total_volume_tahun   = 0.0;
$total_pagu_tahun     = 0.0;
$target_vol_kum       = 0.0;
$target_pag_kum       = 0.0;
$realisasi            = null;
$success              = '';
$error                = '';
$real_vol_kum_bulan_lalu = 0.0;
$real_pag_kum_bulan_lalu = 0.0;
$vol_w1 = $vol_w2 = $vol_w3 = $vol_w4 = $vol_w5 = null;
$pag_w1 = $pag_w2 = $pag_w3 = $pag_w4 = $pag_w5 = null;
$total_vol_bulan_ini  = 0.0;
$total_pag_bulan_ini  = 0.0;
$sisa_vol             = 0.0;
$sisa_pag             = 0.0;
$readonly_flags       = [false, false, false, false, false];
$all_status           = [];
$current_status       = ['w1' => 'draft', 'w2' => 'draft', 'w3' => 'draft', 'w4' => 'draft', 'w5' => 'draft'];

// ========== PROSES DATA JIKA TIDAK ADA ERROR AKSES ==========
if (!$error_akses) {
    $target_vol_bulan   = (float)($anggaran["volume_$bulan_key"] ?? 0);
    $target_pag_bulan   = (float)($anggaran["pagu_$bulan_key"] ?? 0);
    $total_volume_tahun = (float)($anggaran['total_volume'] ?? 0);
    $total_pagu_tahun   = (float)($anggaran['total_pagu'] ?? 0);

    // Hitung target kumulatif s/d bulan ini
    $target_vol_kum = 0.0;
    $target_pag_kum = 0.0;
    for ($i = 0; $i < $bulan; $i++) {
        $b = $bulan_keys[$i];
        $target_vol_kum += (float)($anggaran["volume_$b"] ?? 0);
        $target_pag_kum += (float)($anggaran["pagu_$b"] ?? 0);
    }

    // ========== SINKRONISASI DATA REALISASI ==========
    // Ambil semua record realisasi untuk kombinasi ini (prepared)
    $stmt_all_reals = $conn->prepare("SELECT * FROM realisasi_detail 
                                      WHERE opd_id = ? AND tahun = ? 
                                        AND rincian_belanja_id = ?
                                        AND kode_program = ? 
                                        AND kode_kegiatan = ? 
                                        AND kode_sub_kegiatan = ?");
    $stmt_all_reals->bind_param('iiisss', $opd_id, $tahun, $rincian_id, $kode_program, $kode_kegiatan, $kode_sub_kegiatan);
    $stmt_all_reals->execute();
    $all_reals_result = $stmt_all_reals->get_result();

    $existing_for_latest = null;
    $migrated_data       = null;
    $status_from_old     = null;
    $records_to_delete   = [];

    while ($row = $all_reals_result->fetch_assoc()) {
        if ((int)$row['anggaran_detail_id'] === $anggaran_id) {
            $existing_for_latest = $row;
        } else {
            if (!$migrated_data) {
                $migrated_data   = $row;
                $status_from_old = $row['status_mingguan'];
            } else {
                // Gabungkan volume/pagu
                foreach ($bulan_keys as $b) {
                    for ($w = 1; $w <= 5; $w++) {
                        $migrated_data["volume_{$b}_w$w"] = (float)($migrated_data["volume_{$b}_w$w"] ?? 0) + (float)($row["volume_{$b}_w$w"] ?? 0);
                        $migrated_data["pagu_{$b}_w$w"]   = (float)($migrated_data["pagu_{$b}_w$w"] ?? 0) + (float)($row["pagu_{$b}_w$w"] ?? 0);
                    }
                }
                // Gabungkan status
                $old_status = json_decode($row['status_mingguan'] ?? '{}', true);
                $cur_status = json_decode($status_from_old ?? '{}', true);
                foreach ($bulan_keys as $b) {
                    for ($w = 1; $w <= 5; $w++) {
                        $st_old = $old_status[$b]["w$w"] ?? 'draft';
                        $st_cur = $cur_status[$b]["w$w"] ?? 'draft';
                        if ($st_old === 'dikunci' || ($st_old === 'divalidasi' && $st_cur !== 'dikunci')) {
                            $cur_status[$b]["w$w"] = $st_old;
                        } elseif ($st_cur === 'draft' && $st_old === 'divalidasi') {
                            $cur_status[$b]["w$w"] = 'divalidasi';
                        }
                    }
                }
                $status_from_old = json_encode($cur_status);
            }
            $records_to_delete[] = (int) $row['id'];
        }
    }
    $stmt_all_reals->close();

    // Jika sudah ada realisasi untuk versi terbaru
    if ($existing_for_latest) {
        $realisasi = $existing_for_latest;
    } else {
        // Migrasi data dari versi lama jika ada
        if ($migrated_data) {
            $conn->begin_transaction();
            try {
                // Hapus record versi lama (prepared)
                if (!empty($records_to_delete)) {
                    $placeholders = implode(',', array_fill(0, count($records_to_delete), '?'));
                    $types = str_repeat('i', count($records_to_delete));
                    $stmt_del = $conn->prepare("DELETE FROM realisasi_detail WHERE id IN ($placeholders)");
                    $stmt_del->bind_param($types, ...$records_to_delete);
                    $stmt_del->execute();
                    $stmt_del->close();
                }

                // Insert data gabungan (prepared statement)
                $status_json = $status_from_old ?? '{}';
                $insert_fields = ["anggaran_detail_id", "opd_id", "tahun", "kode_program", "kode_kegiatan", "kode_sub_kegiatan", "rincian_belanja_id", "status_mingguan"];
                $insert_values = [$anggaran_id, $opd_id, $tahun, $kode_program, $kode_kegiatan, $kode_sub_kegiatan, $rincian_id, $status_json];
                $types_list = "iiisssss";
                
                foreach ($bulan_keys as $b) {
                    for ($w = 1; $w <= 5; $w++) {
                        $insert_fields[] = "volume_{$b}_w$w";
                        $insert_values[] = (float)($migrated_data["volume_{$b}_w$w"] ?? 0);
                        $types_list .= "d";
                    }
                    for ($w = 1; $w <= 5; $w++) {
                        $insert_fields[] = "pagu_{$b}_w$w";
                        $insert_values[] = (float)($migrated_data["pagu_{$b}_w$w"] ?? 0);
                        $types_list .= "d";
                    }
                }
                
                $placeholders_arr = array_fill(0, count($insert_values), '?');
                $sql_insert = "INSERT INTO realisasi_detail (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $placeholders_arr) . ")";
                $stmt_ins = $conn->prepare($sql_insert);
                $stmt_ins->bind_param($types_list, ...$insert_values);
                $stmt_ins->execute();
                $new_id = (int) $stmt_ins->insert_id;
                $stmt_ins->close();
                
                $conn->commit();

                // Ambil data hasil migrasi
                $stmt_new = $conn->prepare("SELECT * FROM realisasi_detail WHERE id = ?");
                $stmt_new->bind_param('i', $new_id);
                $stmt_new->execute();
                $realisasi = $stmt_new->get_result()->fetch_assoc();
                $stmt_new->close();
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Gagal melakukan migrasi data: " . htmlspecialchars($e->getMessage());
            }
        } else {
            // Tidak ada data lama, buat record baru
            $default_status = [];
            foreach ($bulan_keys as $b) {
                $default_status[$b] = ['w1' => 'draft', 'w2' => 'draft', 'w3' => 'draft', 'w4' => 'draft', 'w5' => 'draft'];
            }
            $json_default = json_encode($default_status);
            $stmt_new = $conn->prepare("INSERT INTO realisasi_detail
                (anggaran_detail_id, opd_id, tahun, kode_program, kode_kegiatan, kode_sub_kegiatan, rincian_belanja_id, status_mingguan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_new->bind_param('iiisssss', $anggaran_id, $opd_id, $tahun, $kode_program, $kode_kegiatan, $kode_sub_kegiatan, $rincian_id, $json_default);
            $stmt_new->execute();
            $stmt_new->close();
            
            $stmt_get = $conn->prepare("SELECT * FROM realisasi_detail WHERE anggaran_detail_id = ? AND opd_id = ? AND tahun = ?");
            $stmt_get->bind_param('iii', $anggaran_id, $opd_id, $tahun);
            $stmt_get->execute();
            $realisasi = $stmt_get->get_result()->fetch_assoc();
            $stmt_get->close();
        }
    }

    // Jika setelah sinkronisasi masih null
    if (!$realisasi) {
        $error_akses = $error ?: "Gagal memuat data realisasi. Silakan coba lagi.";
    } else {
        // Sinkronkan kunci global
        $all_status = json_decode($realisasi['status_mingguan'] ?? '{}', true);
        $need_update = false;
        foreach ($bulan_keys as $b) {
            foreach (['w1','w2','w3','w4','w5'] as $w) {
                if (isGlobalLocked($conn, $opd_id, $tahun, $b, $w)) {
                    if (($all_status[$b][$w] ?? 'draft') !== 'dikunci') {
                        $all_status[$b][$w] = 'dikunci';
                        $need_update = true;
                    }
                }
            }
        }
        if ($need_update) {
            $new_json = json_encode($all_status);
            $stmt_upd = $conn->prepare("UPDATE realisasi_detail SET status_mingguan = ? WHERE id = ?");
            $stmt_upd->bind_param('si', $new_json, $realisasi['id']);
            $stmt_upd->execute();
            $stmt_upd->close();
            $realisasi['status_mingguan'] = $new_json;
            $all_status = json_decode($new_json, true);
        }

        // ========== PROSES SIMPAN (POST) ==========
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan'])) {
            // Verifikasi CSRF
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
                $error = "Token keamanan tidak valid. Silakan muat ulang halaman.";
            } else {
                $vol = [];
                $pag = [];
                for ($w = 1; $w <= 5; $w++) {
                    $vol[$w] = (float) ($_POST["vol_w{$w}_hidden"] ?? 0);
                    $pag[$w] = (float) ($_POST["pag_w{$w}_hidden"] ?? 0);
                }

                $current_status = $all_status[$bulan_key] ?? ['w1' => 'draft', 'w2' => 'draft', 'w3' => 'draft', 'w4' => 'draft', 'w5' => 'draft'];

                // Tentukan readonly
                $readonly = [];
                for ($w = 1; $w <= 5; $w++) {
                    $ws = $current_status["w$w"] ?? 'draft';
                    $existing_vol = (float)($realisasi["volume_{$bulan_key}_w$w"] ?? 0);
                    if ($ws === 'dikunci' || ($ws === 'divalidasi' && $existing_vol != 0) || isGlobalLocked($conn, $opd_id, $tahun, $bulan_key, 'w'.$w)) {
                        $readonly[$w] = true;
                    } else {
                        $readonly[$w] = false;
                    }
                }

                $final_vol = [];
                $final_pag = [];
                for ($w = 1; $w <= 5; $w++) {
                    if ($readonly[$w]) {
                        $final_vol[$w] = (float)($realisasi["volume_{$bulan_key}_w$w"] ?? 0);
                        $final_pag[$w] = (float)($realisasi["pagu_{$bulan_key}_w$w"] ?? 0);
                    } else {
                        $final_vol[$w] = $vol[$w];
                        $final_pag[$w] = $pag[$w];
                    }
                }

                $total_vol_input = array_sum($final_vol);
                $total_pag_input = array_sum($final_pag);

                // Total seluruh tahun sebelum update
                $real_vol_all_before = 0.0;
                $real_pag_all_before = 0.0;
                for ($i = 1; $i <= 12; $i++) {
                    $b = $bulan_keys[$i-1];
                    for ($w = 1; $w <= 5; $w++) {
                        $real_vol_all_before += (float)($realisasi["volume_{$b}_w$w"] ?? 0);
                        $real_pag_all_before += (float)($realisasi["pagu_{$b}_w$w"] ?? 0);
                    }
                }
                $existing_vol_bulan = 0.0;
                $existing_pag_bulan = 0.0;
                for ($w = 1; $w <= 5; $w++) {
                    $existing_vol_bulan += (float)($realisasi["volume_{$bulan_key}_w$w"] ?? 0);
                    $existing_pag_bulan += (float)($realisasi["pagu_{$bulan_key}_w$w"] ?? 0);
                }

                $total_vol_kumulatif = ($real_vol_all_before - $existing_vol_bulan) + $total_vol_input;
                $total_pag_kumulatif = ($real_pag_all_before - $existing_pag_bulan) + $total_pag_input;
                $real_vol_kum_lalu   = $real_vol_all_before - $existing_vol_bulan;
                $real_pag_kum_lalu   = $real_pag_all_before - $existing_pag_bulan;
                $sisa_vol_bulan_ini  = $target_vol_kum - $real_vol_kum_lalu;
                $sisa_pag_bulan_ini  = $target_pag_kum - $real_pag_kum_lalu;

                // Validasi
                if (round($total_vol_kumulatif, 2) > round($total_volume_tahun, 2)) {
                    $error = "Total volume realisasi kumulatif melebihi total volume tahunan (" . number_format($total_volume_tahun, 2, ',', '.') . ").";
                } elseif (round($total_pag_kumulatif, 2) > round($total_pagu_tahun, 2)) {
                    $error = "Total pagu realisasi kumulatif melebihi total pagu tahunan (Rp " . number_format($total_pagu_tahun, 0, ',', '.') . ").";
                } elseif (round($total_vol_input, 2) > round($sisa_vol_bulan_ini, 2)) {
                    $error = "Total volume realisasi bulan ini melebihi sisa kumulatif (" . number_format($sisa_vol_bulan_ini, 2, ',', '.') . ").";
                } elseif (round($total_pag_input, 2) > round($sisa_pag_bulan_ini, 2)) {
                    $error = "Total pagu realisasi bulan ini melebihi sisa kumulatif (Rp " . number_format($sisa_pag_bulan_ini, 0, ',', '.') . ").";
                }

                if (!$error) {
                    // Simpan data mingguan (prepared statement)
                    $stmt_simpan = $conn->prepare("UPDATE realisasi_detail SET
                        volume_{$bulan_key}_w1 = ?, volume_{$bulan_key}_w2 = ?, volume_{$bulan_key}_w3 = ?, volume_{$bulan_key}_w4 = ?, volume_{$bulan_key}_w5 = ?,
                        pagu_{$bulan_key}_w1 = ?, pagu_{$bulan_key}_w2 = ?, pagu_{$bulan_key}_w3 = ?, pagu_{$bulan_key}_w4 = ?, pagu_{$bulan_key}_w5 = ?
                        WHERE anggaran_detail_id = ? AND opd_id = ? AND tahun = ?");
                    $stmt_simpan->bind_param(
                        'ddddddddddiii',
                        $final_vol[1], $final_vol[2], $final_vol[3], $final_vol[4], $final_vol[5],
                        $final_pag[1], $final_pag[2], $final_pag[3], $final_pag[4], $final_pag[5],
                        $anggaran_id, $opd_id, $tahun
                    );

                    if ($stmt_simpan->execute()) {
                        $success = "Data berhasil disimpan.";
                        // Refresh data realisasi
                        $stmt_refresh = $conn->prepare("SELECT * FROM realisasi_detail WHERE id = ?");
                        $stmt_refresh->bind_param('i', $realisasi['id']);
                        $stmt_refresh->execute();
                        $realisasi = $stmt_refresh->get_result()->fetch_assoc();
                        $stmt_refresh->close();
                    } else {
                        error_log("Gagal simpan realisasi: " . $stmt_simpan->error);
                        $error = "Gagal menyimpan data. Silakan coba lagi.";
                    }
                    $stmt_simpan->close();
                }

                // Jika error, kembalikan nilai final untuk ditampilkan di form
                if ($error) {
                    $vol_w1 = $final_vol[1]; $vol_w2 = $final_vol[2]; $vol_w3 = $final_vol[3]; $vol_w4 = $final_vol[4]; $vol_w5 = $final_vol[5];
                    $pag_w1 = $final_pag[1]; $pag_w2 = $final_pag[2]; $pag_w3 = $final_pag[3]; $pag_w4 = $final_pag[4]; $pag_w5 = $final_pag[5];
                }
            }
        }

        // Ambil nilai tampilan jika belum di-set
        if ($vol_w1 === null) {
            $vol_w1 = (float)($realisasi["volume_{$bulan_key}_w1"] ?? 0);
            $vol_w2 = (float)($realisasi["volume_{$bulan_key}_w2"] ?? 0);
            $vol_w3 = (float)($realisasi["volume_{$bulan_key}_w3"] ?? 0);
            $vol_w4 = (float)($realisasi["volume_{$bulan_key}_w4"] ?? 0);
            $vol_w5 = (float)($realisasi["volume_{$bulan_key}_w5"] ?? 0);
            $pag_w1 = (float)($realisasi["pagu_{$bulan_key}_w1"] ?? 0);
            $pag_w2 = (float)($realisasi["pagu_{$bulan_key}_w2"] ?? 0);
            $pag_w3 = (float)($realisasi["pagu_{$bulan_key}_w3"] ?? 0);
            $pag_w4 = (float)($realisasi["pagu_{$bulan_key}_w4"] ?? 0);
            $pag_w5 = (float)($realisasi["pagu_{$bulan_key}_w5"] ?? 0);
        }

        $total_vol_bulan_ini = $vol_w1 + $vol_w2 + $vol_w3 + $vol_w4 + $vol_w5;
        $total_pag_bulan_ini = $pag_w1 + $pag_w2 + $pag_w3 + $pag_w4 + $pag_w5;

        // Hitung realisasi sampai bulan lalu
        $real_vol_kum_bulan_lalu = 0.0;
        $real_pag_kum_bulan_lalu = 0.0;
        for ($i = 1; $i <= $bulan - 1; $i++) {
            $b = $bulan_keys[$i-1];
            for ($w = 1; $w <= 5; $w++) {
                $real_vol_kum_bulan_lalu += (float)($realisasi["volume_{$b}_w$w"] ?? 0);
                $real_pag_kum_bulan_lalu += (float)($realisasi["pagu_{$b}_w$w"] ?? 0);
            }
        }

        $real_vol_kum_bulan_ini = $real_vol_kum_bulan_lalu + $total_vol_bulan_ini;
        $real_pag_kum_bulan_ini = $real_pag_kum_bulan_lalu + $total_pag_bulan_ini;
        $sisa_vol = $target_vol_kum - $real_vol_kum_bulan_ini;
        $sisa_pag = $target_pag_kum - $real_pag_kum_bulan_ini;

        $all_status = json_decode($realisasi['status_mingguan'] ?? '{}', true);
        $current_status = $all_status[$bulan_key] ?? ['w1'=>'draft','w2'=>'draft','w3'=>'draft','w4'=>'draft','w5'=>'draft'];
        $readonly_flags = [];
        for ($w = 1; $w <= 5; $w++) {
            $ws = $current_status["w$w"] ?? 'draft';
            $vol_val = ${"vol_w$w"};
            if ($ws === 'dikunci' || isGlobalLocked($conn, $opd_id, $tahun, $bulan_key, 'w'.$w) || ($ws === 'divalidasi' && $vol_val != 0)) {
                $readonly_flags[$w-1] = true;
            } else {
                $readonly_flags[$w-1] = false;
            }
        }
    }
}
// --- END PROSES DATA ---

// Hitung nilai per satuan volume dan siapkan untuk JS
$nilai_per_satuan = ($total_volume_tahun > 0) ? $total_pagu_tahun / $total_volume_tahun : 0;
$display_nilai = ($total_volume_tahun > 0) ? 'Rp ' . number_format($nilai_per_satuan, 0, ',', '.') : '-';
$nilai_per_satuan_js = ($total_volume_tahun > 0) ? $nilai_per_satuan : 0;
?>

<!-- ==================== TAMPILAN HTML ==================== -->
<div class="d-flex" style="margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <h3><i class="bi bi-pencil-square"></i> Input Realisasi Mingguan – <?= htmlspecialchars($bulan_label) ?> <?= $tahun ?></h3>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="data_realisasi.php?tahun=<?= $tahun ?>&bulan=<?= $bulan ?>">Data Realisasi</a></li>
                    <li class="breadcrumb-item active">Input Mingguan</li>
                </ol>
            </nav>

            <?php if ($error_akses): ?>
                <div class="alert alert-warning">
                    <h4><i class="bi bi-lock-fill"></i> Akses Ditolak</h4>
                    <p><?= $error_akses ?></p>
                    <a href="data_realisasi.php?tahun=<?= $tahun ?>&bulan=<?= $bulan ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Kembali ke Data Realisasi
                    </a>
                </div>
            <?php else: ?>
                <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5>Informasi Anggaran</h5>
                                <table class="table table-sm">
                                    <tr><th>Program</th><td><?= htmlspecialchars($anggaran['kode_program']) ?></td></tr>
                                    <tr><th>Kegiatan</th><td><?= htmlspecialchars($anggaran['kode_kegiatan']) ?></td></tr>
                                    <tr><th>Sub Kegiatan</th><td><?= htmlspecialchars($anggaran['kode_sub_kegiatan']) ?></td></tr>
                                    <tr><th>Rincian</th><td><?= htmlspecialchars($anggaran['kode_rincian']) ?> – <?= htmlspecialchars($anggaran['nama_rincian']) ?></td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h5>Target & Sisa Kumulatif</h5>
                                <table class="table table-sm">
                                    <tr class="table-primary"><th>Total Volume Tahun <?= $tahun ?></th><td class="text-end"><?= number_format($total_volume_tahun, 2, ',', '.') ?></td></tr>
                                    <tr class="table-primary"><th>Total Pagu Tahun <?= $tahun ?></th><td class="text-end">Rp <?= number_format($total_pagu_tahun, 0, ',', '.') ?></td></tr>
                                    <tr class="table-primary"><th>Nilai Kegiatan per Satuan Volume</th><td class="text-end"><?= $display_nilai ?></td></tr>
                                    <tr><th>Target Volume Bulan Ini</th><td class="text-end"><?= number_format($target_vol_bulan, 2, ',', '.') ?></td></tr>
                                    <tr><th>Target Pagu Bulan Ini</th><td class="text-end">Rp <?= number_format($target_pag_bulan, 0, ',', '.') ?></td></tr>
                                    <tr class="table-info"><th>Sisa Kumulatif Volume s/d Bulan Ini</th><td class="text-end"><?= number_format($sisa_vol, 2, ',', '.') ?></td></tr>
                                    <tr class="table-info"><th>Sisa Kumulatif Pagu s/d Bulan Ini</th><td class="text-end">Rp <?= number_format($sisa_pag, 0, ',', '.') ?></td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========== KALKULATOR VOLUME REALTIME ========== -->
                <div class="card mb-3">
                    <div class="card-header">
                        <strong><i class="bi bi-calculator"></i> Kalkulator Volume</strong>
                    </div>
                    <div class="card-body">
                        <?php if ($nilai_per_satuan > 0): ?>
                        <p class="text-muted small mb-2">Masukkan nilai anggaran untuk menghitung volume berdasarkan <strong>Nilai Kegiatan per Satuan Volume: <?= $display_nilai ?></strong>.</p>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label for="kalkAnggaran" class="form-label">Anggaran (Rp)</label>
                                <input type="text" id="kalkAnggaran" class="form-control" placeholder="contoh: 3000000">
                            </div>
                            <div class="col-md-3">
                                <label for="kalkVolume" class="form-label">Volume (Hasil)</label>
                                <input type="text" id="kalkVolume" class="form-control" readonly>
                            </div>
                            <div class="col-md-2">
                                <label for="targetMinggu" class="form-label">Target Minggu ke-</label>
                                <select id="targetMinggu" class="form-select">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                    <option value="5">5</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="button" id="btnSetVolume" class="btn btn-outline-primary w-100">
                                    <i class="bi bi-check-circle"></i> Set Volume
                                </button>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info mb-0">Kalkulator tidak tersedia karena total volume tahunan 0.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><strong>Realisasi Mingguan</strong></div>
                    <div class="card-body">
                        <form method="POST" id="formRealisasi">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <div id="alertContainer"></div>
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr><th>Minggu</th><th>Volume</th><th>Anggaran (Rp)</th><th>Status</th><th>Keterangan</th></tr>
                                </thead>
                                <tbody>
                                    <?php for ($w = 1; $w <= 4; $w++):
                                        $readonly = $readonly_flags[$w-1];
                                        $vol_val = ${"vol_w$w"};
                                        $pag_val = ${"pag_w$w"};
                                        $ws = $current_status["w$w"] ?? 'draft';
                                        $badge = $ws === 'dikunci' ? 'dark' : ($ws === 'divalidasi' ? 'success' : 'secondary');
                                    ?>
                                    <tr>
                                        <td>Pekan ke-<?= $w ?></td>
                                        <td><input type="text" class="form-control number-format-volume" value="<?= number_format($vol_val, 2, ',', '.') ?>" <?= $readonly ? 'readonly' : '' ?> data-target="vol_w<?= $w ?>_hidden"><input type="hidden" name="vol_w<?= $w ?>_hidden" id="vol_w<?= $w ?>_hidden" value="<?= $vol_val ?>"></td>
                                        <td><input type="text" class="form-control number-format-pagu" value="<?= number_format($pag_val, 0, ',', '.') ?>" <?= $readonly ? 'readonly' : '' ?> data-target="pag_w<?= $w ?>_hidden"><input type="hidden" name="pag_w<?= $w ?>_hidden" id="pag_w<?= $w ?>_hidden" value="<?= $pag_val ?>"></td>
                                        <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($ws) ?></span></td>
                                        <td><?= $readonly ? '<span class="badge bg-secondary">Terkunci</span>' : '<span class="badge bg-success">Dapat diisi</span>' ?></td>
                                    </tr>
                                    <?php endfor; ?>

                                    <!-- Tombol Tambah Minggu ke-5 -->
                                    <tr id="row_tambah_m5">
                                        <td colspan="5" class="text-center">
                                            <button type="button" id="btnTambahM5" class="btn btn-outline-info btn-sm">
                                                <i class="bi bi-plus-circle"></i> Tambah Minggu ke-5
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Baris Minggu ke-5 (hidden default) -->
                                    <?php
                                    $w = 5;
                                    $readonly = $readonly_flags[$w-1];
                                    $vol_val = $vol_w5;
                                    $pag_val = $pag_w5;
                                    $ws = $current_status["w$w"] ?? 'draft';
                                    $badge = $ws === 'dikunci' ? 'dark' : ($ws === 'divalidasi' ? 'success' : 'secondary');
                                    ?>
                                    <tr id="row_m5" style="display:none;">
                                        <td>Pekan ke-5</td>
                                        <td><input type="text" class="form-control number-format-volume" value="<?= number_format($vol_val, 2, ',', '.') ?>" <?= $readonly ? 'readonly' : '' ?> data-target="vol_w5_hidden"><input type="hidden" name="vol_w5_hidden" id="vol_w5_hidden" value="<?= $vol_val ?>"></td>
                                        <td><input type="text" class="form-control number-format-pagu" value="<?= number_format($pag_val, 0, ',', '.') ?>" <?= $readonly ? 'readonly' : '' ?> data-target="pag_w5_hidden"><input type="hidden" name="pag_w5_hidden" id="pag_w5_hidden" value="<?= $pag_val ?>"></td>
                                        <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($ws) ?></span></td>
                                        <td><?= $readonly ? '<span class="badge bg-secondary">Terkunci</span>' : '<span class="badge bg-success">Dapat diisi</span>' ?></td>
                                    </tr>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr><th>Total Realisasi Bulan Ini</th><th class="text-end" id="total_vol"><?= number_format($total_vol_bulan_ini, 2, ',', '.') ?></th><th class="text-end" id="total_pag">Rp <?= number_format($total_pag_bulan_ini, 0, ',', '.') ?></th><th></th><th></th></tr>
                                </tfoot>
                            </table>
                            <button type="submit" name="simpan" class="btn btn-primary" id="btnSimpan"><i class="bi bi-save"></i> Simpan</button>
                            <a href="data_realisasi.php?tahun=<?= $tahun ?>&bulan=<?= $bulan ?>" class="btn btn-secondary">Kembali</a>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$error_akses): ?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function(){
    var targetVolKum = <?= $target_vol_kum ?>;
    var targetPagKum = <?= $target_pag_kum ?>;
    var realVolKum = <?= $real_vol_kum_bulan_lalu ?>;
    var realPagKum = <?= $real_pag_kum_bulan_lalu ?>;
    var totalVolTahun = <?= $total_volume_tahun ?>;
    var totalPagTahun = <?= $total_pagu_tahun ?>;
    var nilaiPerSatuan = <?= $nilai_per_satuan_js ?>;

    function formatVolume(val) {
        if (isNaN(val)) return '0,00';
        return new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val);
    }
    function formatPagu(val) {
        if (isNaN(val)) return '0';
        return new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(val);
    }
    function parseHiddenToFloat(str) {
        if (!str) return 0;
        str = str.toString().replace(',', '.');
        var num = parseFloat(str);
        return isNaN(num) ? 0 : num;
    }
    function parseInputToFloat(str) {
        if (!str) return 0;
        str = str.toString().replace(/\./g, '').replace(',', '.');
        if (str === '-') return 0;
        var num = parseFloat(str);
        return isNaN(num) ? 0 : num;
    }
    function toPlainDecimal(val, decimals) {
        if (isNaN(val)) return '0' + (decimals > 0 ? ',' + '0'.repeat(decimals) : '');
        var fixed = val.toFixed(decimals);
        return fixed.replace('.', ',');
    }

    // ---------- Kalkulator Volume Realtime ----------
    if (nilaiPerSatuan > 0) {
        $('#kalkAnggaran').on('input', function() {
            var raw = $(this).val();
            // Terima angka dengan pemisah ribuan (titik) dan desimal (koma)
            var bersih = raw.replace(/\./g, '').replace(',', '.');
            var num = parseFloat(bersih);
            if (!isNaN(num) && num >= 0) {
                var vol = num / nilaiPerSatuan;
                $('#kalkVolume').val(formatVolume(vol));
            } else {
                $('#kalkVolume').val('');
            }
        });

        $('#btnSetVolume').on('click', function() {
            var w = parseInt($('#targetMinggu').val());
            // Cek apakah minggu ke-5 sudah ditampilkan
            if (w === 5 && $('#row_m5').is(':hidden')) {
                alert('Tambahkan Minggu ke-5 terlebih dahulu dengan tombol "Tambah Minggu ke-5".');
                return;
            }
            var volStr = $('#kalkVolume').val();
            if (!volStr) {
                alert('Hitung volume terlebih dahulu dengan mengisi Anggaran.');
                return;
            }
            // Konversi tampilan volume (misal "0,09") ke float
            var volNum = parseInputToFloat(volStr);
            // Cek readonly pada minggu target
            var targetInput = $('input.number-format-volume[data-target="vol_w'+w+'_hidden"]');
            if (targetInput.prop('readonly')) {
                alert('Minggu ke-' + w + ' terkunci, tidak dapat diubah.');
                return;
            }
            // Set nilai hidden dan perbarui tampilan
            $('#vol_w'+w+'_hidden').val(volNum);
            targetInput.val(formatVolume(volNum));
            updateTotals();
        });
    } else {
        // Nonaktifkan jika nilai per satuan tidak tersedia
        $('#kalkAnggaran, #btnSetVolume').prop('disabled', true);
    }

    $('.number-format-volume, .number-format-pagu').on('focus', function(){
        var targetId = $(this).data('target');
        var hiddenVal = $('#' + targetId).val();
        var decimals = $(this).hasClass('number-format-volume') ? 2 : 0;
        var plain = toPlainDecimal(parseHiddenToFloat(hiddenVal), decimals);
        $(this).val(plain);
        $(this).select();
    });
    $('.number-format-volume, .number-format-pagu').on('blur', function(){
        var raw = $(this).val();
        var num = parseInputToFloat(raw);
        var decimals = $(this).hasClass('number-format-volume') ? 2 : 0;
        var formatted = decimals === 2 ? formatVolume(num) : formatPagu(num);
        $(this).val(formatted);
        $('#' + $(this).data('target')).val(num);
        updateTotals();
    });
    $('.number-format-volume, .number-format-pagu').on('input', function(e){
        var input = $(this);
        var val = input.val();
        var filtered = val.replace(/[^0-9,\-.]/g, '');
        if (filtered.indexOf('-') > 0) {
            filtered = filtered.replace(/-/g, '');
            if (val.charAt(0) === '-') filtered = '-' + filtered;
        }
        var parts = filtered.split(/[,.]/);
        if (parts.length > 2) {
            filtered = parts[0] + ',' + parts.slice(1).join('');
        }
        input.val(filtered);
    });

    $('#btnTambahM5').on('click', function(){
        $('#row_m5').toggle();
        $(this).html(function(i, text){
            return text.trim().indexOf('Tambah') !== -1 ? '<i class="bi bi-dash-circle"></i> Sembunyikan Minggu ke-5' : '<i class="bi bi-plus-circle"></i> Tambah Minggu ke-5';
        });
        updateTotals();
    });

    function updateTotals(){
        var tv = 0, tp = 0;
        for(var w = 1; w <= 5; w++){
            tv += parseHiddenToFloat($('#vol_w'+w+'_hidden').val()) || 0;
            tp += parseHiddenToFloat($('#pag_w'+w+'_hidden').val()) || 0;
        }

        var totalVolKumTahun = realVolKum + tv;
        var totalPagKumTahun = realPagKum + tp;

        var sisaVol = targetVolKum - realVolKum;
        var sisaPag = targetPagKum - realPagKum;

        $('#total_vol').text(formatVolume(tv));
        $('#total_pag').text('Rp ' + formatPagu(tp));

        var a = '', v = true;
        if(totalVolKumTahun > totalVolTahun + 0.001){
            a = '<div class="alert alert-danger">Total volume realisasi kumulatif melebihi total volume tahunan ('+formatVolume(totalVolTahun)+').</div>'; v = false;
        } else if(totalPagKumTahun > totalPagTahun + 0.001){
            a = '<div class="alert alert-danger">Total pagu realisasi kumulatif melebihi total pagu tahunan (Rp '+formatPagu(totalPagTahun)+').</div>'; v = false;
        } else if(tv > sisaVol + 0.001){
            a = '<div class="alert alert-danger">Volume bulan ini melebihi sisa kumulatif ('+formatVolume(sisaVol)+').</div>'; v = false;
        } else if(tp > sisaPag + 0.001){
            a = '<div class="alert alert-danger">Pagu bulan ini melebihi sisa kumulatif (Rp '+formatPagu(sisaPag)+').</div>'; v = false;
        }
        $('#alertContainer').html(a);
        $('#btnSimpan').prop('disabled', !v);
    }

    $('.number-format-volume').each(function(){
        var hid = $('#' + $(this).data('target')).val();
        $(this).val(formatVolume(parseHiddenToFloat(hid)));
    });
    $('.number-format-pagu').each(function(){
        var hid = $('#' + $(this).data('target')).val();
        $(this).val(formatPagu(parseHiddenToFloat(hid)));
    });
    updateTotals();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>