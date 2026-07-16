<?php
/**
 * functions_waktu.php - Fungsi untuk manajemen waktu batas input
 * 
 * Digunakan oleh header.php secara otomatis (cek_dan_eksekusi_batas_waktu)
 * serta dipanggil oleh file lock manual jika diperlukan.
 */

/**
 * Cek dan eksekusi batas waktu yang sudah lewat dan belum dieksekusi.
 * Dipanggil setiap kali halaman diakses.
 */
function cek_dan_eksekusi_batas_waktu($conn) {
    $stmt = $conn->prepare("SELECT id, tahun, batas_waktu, jenis FROM waktu_batas WHERE dieksekusi = 0 AND batas_waktu <= NOW()");
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $tahun    = (int)$row['tahun'];
        $waktu_id = (int)$row['id'];
        $jenis    = $row['jenis'] ?? 'anggaran';

        $conn->begin_transaction();
        try {
            if ($jenis === 'anggaran') {
                eksekusiKunciAnggaran($conn, $tahun);
            } else {
                eksekusiKunciRealisasiBertahap($conn, $tahun);
            }

            // Tandai sudah dieksekusi
            $stmt_upd = $conn->prepare("UPDATE waktu_batas SET dieksekusi = 1 WHERE id = ?");
            $stmt_upd->bind_param('i', $waktu_id);
            $stmt_upd->execute();
            $stmt_upd->close();

            // Log eksekusi
            $pesan = "Eksekusi otomatis jenis $jenis tahun $tahun berhasil.";
            $stmt_log = $conn->prepare("INSERT INTO log_waktu_batas (tahun, waktu_eksekusi, pesan) VALUES (?, NOW(), ?)");
            $stmt_log->bind_param('is', $tahun, $pesan);
            $stmt_log->execute();
            $stmt_log->close();

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Gagal eksekusi batas waktu: " . $e->getMessage());
        }
    }
    $stmt->close();
}

/**
 * Kunci seluruh data anggaran: validasi draft, lalu kunci per OPD.
 */
function eksekusiKunciAnggaran($conn, $tahun) {
    // Validasi semua draft
    $stmt = $conn->prepare("UPDATE anggaran_detail SET status_validasi = 'divalidasi', tanggal_validasi = NOW() WHERE tahun = ? AND status_validasi = 'draft'");
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $stmt->close();

    // Ambil semua OPD yang punya data divalidasi
    $stmt = $conn->prepare("SELECT DISTINCT opd_id FROM anggaran_detail WHERE tahun = ? AND status_validasi = 'divalidasi'");
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        kunciAnggaranOpd($conn, (int)$row['opd_id'], $tahun);
    }
    $stmt->close();
}

/**
 * Kunci realisasi bertahap: dari Januari sampai bulan sekarang, dan minggu sesuai tanggal eksekusi.
 */
function eksekusiKunciRealisasiBertahap($conn, $tahun) {
    $now = new DateTime();
    $bulan_sekarang = (int)$now->format('n');
    $minggu_sekarang = getMingguKe($now);

    $stmt = $conn->prepare("SELECT id, status_mingguan FROM realisasi_detail WHERE tahun = ?");
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $result = $stmt->get_result();

    $bulan_keys = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
    $count = 0;

    while ($row = $result->fetch_assoc()) {
        $status = json_decode($row['status_mingguan'] ?? '{}', true);
        $modified = false;

        foreach ($bulan_keys as $index => $b) {
            $bulan_ke = $index + 1;
            if ($bulan_ke < $bulan_sekarang) {
                // Bulan‑bulan sebelum bulan ini → semua minggu dikunci
                for ($w = 1; $w <= 5; $w++) {
                    if (($status[$b]["w$w"] ?? 'draft') !== 'dikunci') {
                        $status[$b]["w$w"] = 'dikunci';
                        $modified = true;
                    }
                }
            } elseif ($bulan_ke == $bulan_sekarang) {
                // Bulan sekarang → hanya minggu 1 s/d minggu_sekarang
                for ($w = 1; $w <= $minggu_sekarang; $w++) {
                    if (($status[$b]["w$w"] ?? 'draft') !== 'dikunci') {
                        $status[$b]["w$w"] = 'dikunci';
                        $modified = true;
                    }
                }
            }
            // Bulan setelah sekarang diabaikan
        }

        if ($modified) {
            $new_json = json_encode($status);
            $upd = $conn->prepare("UPDATE realisasi_detail SET status_mingguan = ? WHERE id = ?");
            $upd->bind_param('si', $new_json, $row['id']);
            $upd->execute();
            $upd->close();

            // Perbarui kolom volume/pagu bulanan dan total
            updateBulananFromMingguan($conn, $row['id']);
            $count++;
        }
    }
    $stmt->close();
}

/**
 * Tentukan pekan ke‑berapa dalam bulan berdasarkan tanggal.
 * Pekan 1: tgl 1‑7, pekan 2: 8‑14, pekan 3: 15‑21, pekan 4: 22‑28, pekan 5: 29‑31.
 */
function getMingguKe(DateTime $date) {
    $day = (int)$date->format('j');
    if ($day <= 7) return 1;
    if ($day <= 14) return 2;
    if ($day <= 21) return 3;
    if ($day <= 28) return 4;
    return 5;
}

/**
 * Kunci data anggaran satu OPD (dipanggil dari lock manual maupun otomatis).
 */
function kunciAnggaranOpd($conn, $opd_id, $tahun) {
    $stmt_max = $conn->prepare("SELECT MAX(versi) AS max_v FROM anggaran_detail WHERE opd_id = ? AND tahun = ?");
    $stmt_max->bind_param('ii', $opd_id, $tahun);
    $stmt_max->execute();
    $max_v = $stmt_max->get_result()->fetch_assoc()['max_v'] ?? 0;
    $stmt_max->close();
    $next_versi = $max_v + 1;

    // Update versi 0 → naikkan versi dan kunci
    $stmt1 = $conn->prepare("UPDATE anggaran_detail SET status_validasi = 'dikunci', tanggal_kunci = NOW(), versi = ? WHERE opd_id = ? AND tahun = ? AND status_validasi = 'divalidasi' AND versi = 0");
    $stmt1->bind_param('iii', $next_versi, $opd_id, $tahun);
    $stmt1->execute();
    $stmt1->close();

    // Update versi > 0 → kunci, versi tetap
    $stmt2 = $conn->prepare("UPDATE anggaran_detail SET status_validasi = 'dikunci', tanggal_kunci = NOW() WHERE opd_id = ? AND tahun = ? AND status_validasi = 'divalidasi' AND versi > 0");
    $stmt2->bind_param('ii', $opd_id, $tahun);
    $stmt2->execute();
    $stmt2->close();

    // Sinkronisasi ke realisasi_detail (pakai ON DUPLICATE KEY UPDATE)
    syncAnggaranToRealisasi($conn, $opd_id, $tahun);
}

/**
 * Sinkronisasi data anggaran ke realisasi (INSERT … ON DUPLICATE KEY UPDATE).
 * Digunakan oleh lock anggaran manual maupun otomatis.
 */
function syncAnggaranToRealisasi($conn, $opd_id, $tahun) {
    $sql = "
        INSERT INTO realisasi_detail (
            opd_id, tahun, kode_program, kode_kegiatan, kode_sub_kegiatan, rincian_belanja_id,
            total_volume, total_pagu,
            volume_jan, volume_feb, volume_mar, volume_apr, volume_mei, volume_jun,
            volume_jul, volume_agu, volume_sep, volume_okt, volume_nov, volume_des,
            pagu_jan, pagu_feb, pagu_mar, pagu_apr, pagu_mei, pagu_jun,
            pagu_jul, pagu_agu, pagu_sep, pagu_okt, pagu_nov, pagu_des,
            anggaran_detail_id
        )
        SELECT
            a.opd_id, a.tahun, a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan, a.rincian_belanja_id,
            0, 0,
            0,0,0,0,0,0,0,0,0,0,0,0,
            0,0,0,0,0,0,0,0,0,0,0,0,
            a.id
        FROM anggaran_detail a
        WHERE a.opd_id = ? AND a.tahun = ?
          AND a.status_validasi = 'dikunci'
          AND a.versi = (
              SELECT MAX(versi) FROM anggaran_detail a2
              WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun
                AND a2.kode_program = a.kode_program
                AND a2.kode_kegiatan = a.kode_kegiatan
                AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan
                AND a2.rincian_belanja_id = a.rincian_belanja_id
                AND a2.status_validasi = 'dikunci'
          )
        ON DUPLICATE KEY UPDATE
            anggaran_detail_id = VALUES(anggaran_detail_id)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $stmt->close();
}

/**
 * Update kolom bulanan (volume_xxx, pagu_xxx, status_xxx, total_volume, total_pagu)
 * berdasarkan mingguan yang sudah dikunci.
 */
function updateBulananFromMingguan($conn, $realisasi_id) {
    $stmt = $conn->prepare("SELECT * FROM realisasi_detail WHERE id = ?");
    $stmt->bind_param('i', $realisasi_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return;

    $status = json_decode($row['status_mingguan'] ?? '{}', true);
    $bulan_keys = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
    $total_volume_tahun = 0;
    $total_pagu_tahun = 0;
    $updates = [];
    $params = [];
    $types = '';

    foreach ($bulan_keys as $b) {
        $vol_bulan = 0.0;
        $pag_bulan = 0.0;
        for ($w = 1; $w <= 5; $w++) {
            if (($status[$b]["w$w"] ?? 'draft') === 'dikunci') {
                $vol_bulan += (float)($row["volume_{$b}_w$w"] ?? 0);
                $pag_bulan += (float)($row["pagu_{$b}_w$w"] ?? 0);
            }
        }
        $updates[] = "volume_$b = ?";
        $updates[] = "pagu_$b = ?";
        $params[] = $vol_bulan;
        $params[] = $pag_bulan;
        $types .= 'dd';

        $status_bulan = 'draft';
        for ($w = 1; $w <= 5; $w++) {
            if (($status[$b]["w$w"] ?? 'draft') === 'dikunci') {
                $status_bulan = 'dikunci';
                break;
            }
        }
        $updates[] = "status_$b = ?";
        $params[] = $status_bulan;
        $types .= 's';

        $total_volume_tahun += $vol_bulan;
        $total_pagu_tahun += $pag_bulan;
    }

    $updates[] = "total_volume = ?";
    $updates[] = "total_pagu = ?";
    $params[] = $total_volume_tahun;
    $params[] = $total_pagu_tahun;
    $types .= 'dd';
    $params[] = $realisasi_id;
    $types .= 'i';

    $sql = "UPDATE realisasi_detail SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt_upd = $conn->prepare($sql);
    $stmt_upd->bind_param($types, ...$params);
    $stmt_upd->execute();
    $stmt_upd->close();
}

/**
 * Cek apakah batas waktu untuk tahun tertentu sudah lewat (atau sudah dieksekusi).
 */
function isBatasWaktuLewat($conn, $tahun) {
    $stmt = $conn->prepare("SELECT batas_waktu, dieksekusi FROM waktu_batas WHERE tahun = ?");
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && $row['dieksekusi'] == 1) {
        return true;
    }
    if ($row && strtotime($row['batas_waktu']) <= time()) {
        return true;
    }
    return false;
}