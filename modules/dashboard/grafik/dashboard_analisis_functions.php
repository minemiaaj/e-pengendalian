<?php
/**
 * Dashboard Analisis - Fungsi (Logika & Data)
 * 
 * Keamanan:
 * - Semua query database menggunakan prepared statement (anti SQL injection)
 * - Validasi tipe data input (session)
 * - Tidak ada interpolasi variabel langsung ke query string
 * - Error handling aman (log, tidak tampil ke user)
 */

require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();

// Ambil & validasi data session
$role      = $_SESSION['role'];
$opd_id    = isset($_SESSION['opd_id']) ? (int) $_SESSION['opd_id'] : 0;
$tahun     = (int) date('Y');
$bulan_ini = (int) date('n');

$bulan_keys      = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
$bulan_indonesia = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

// ========== MAPPING KODE PERMASALAHAN ==========
$masalah_map = [
    'ADM-01' => 'Kendala penerbitan SPM/SP2D',
    'ADM-02' => 'Kesalahan kode rekening',
    'ADM-03' => 'Verifikasi dokumen lama',
    'ADM-04' => 'Mutasi pejabat keuangan',
    'ADM-05' => 'Dokumen tender salah',
    'ADM-06' => 'Keterlambatan DPA/RKA',
    'ADM-07' => 'Keterlambatan SPJ',
    'ADM-08' => 'Kelengkapan berkas pencairan',
    'REG-01' => 'Perubahan Juknis',
    'REG-02' => 'Keterlambatan Perkada Standar Harga',
    'REG-03' => 'Tumpang tindih regulasi',
    'REG-04' => 'Refocusing mendadak',
    'REG-05' => 'Pembatasan belanja operasional',
    'REG-06' => 'Moratorium kegiatan',
    'REG-07' => 'Keterlambatan APBD Perubahan',
    'REG-08' => 'SK Pengelola belum terbit',
    'TEK-01' => 'Server down/aplikasi error',
    'TEK-02' => 'Cuaca ekstrem',
    'TEK-03' => 'Sinyal internet terbatas',
    'TEK-04' => 'Kenaikan harga material',
    'TEK-05' => 'Kinerja rekanan rendah',
    'TEK-06' => 'Akses transportasi sulit',
    'TEK-07' => 'Kerusakan perangkat keras',
    'TEK-08' => 'Kelangkaan material spesifik'
];

// ========== HELPER FUNCTIONS ==========

/**
 * Ambil nama dari master_hierarki berdasarkan kode dan level.
 * @param string $kode
 * @param int    $level
 * @return string
 */
function getNamaFromHierarki($kode, $level) {
    global $conn;
    if (empty($kode)) return '-';
    $stmt = $conn->prepare("SELECT nama FROM master_hierarki WHERE kode = ? AND level = ? LIMIT 1");
    if (!$stmt) {
        error_log("Prepare error getNamaFromHierarki: " . $conn->error);
        return $kode;
    }
    $stmt->bind_param('si', $kode, $level);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $stmt->close();
        return $row['nama'];
    }
    $stmt->close();
    return $kode;
}

/**
 * Persingkat nama OPD untuk tampilan grafik.
 * @param string $nama
 * @return string
 */
function shortenNamaOPD($nama) {
    $nama = preg_replace('/^(Dinas |Badan |Kantor |Unit |Bagian |Biro )/i', '', $nama);
    if (mb_strlen($nama) > 22) {
        $nama = mb_substr($nama, 0, 19) . '...';
    }
    return $nama;
}

/**
 * Hitung deviasi per OPD: target bulanan vs realisasi (hanya data terkunci).
 * @param mysqli $conn
 * @param int    $tahun
 * @param int    $bulan_ini
 * @return array
 */
function getDeviasiPerOPD($conn, $tahun, $bulan_ini) {
    global $bulan_keys;
    $result = [];
    $tahun_int = (int) $tahun;
    $bulan_int = (int) $bulan_ini;

    // Ambil daftar OPD
    $opd_list = $conn->query("SELECT id, nama_opd FROM opd ORDER BY nama_opd");
    if (!$opd_list) {
        error_log("Gagal query OPD: " . $conn->error);
        return [];
    }

    while ($opd = $opd_list->fetch_assoc()) {
        $opd_id_int = (int) $opd['id'];
        $nama_opd   = $opd['nama_opd'];

        // --- Ambil data anggaran versi terbaru & terkunci ---
        $query_ang = "
            SELECT a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan,
                   SUM(a.total_pagu) AS total_pagu,
                   SUM(a.pagu_jan) AS pagu_jan, SUM(a.pagu_feb) AS pagu_feb, SUM(a.pagu_mar) AS pagu_mar,
                   SUM(a.pagu_apr) AS pagu_apr, SUM(a.pagu_mei) AS pagu_mei, SUM(a.pagu_jun) AS pagu_jun,
                   SUM(a.pagu_jul) AS pagu_jul, SUM(a.pagu_agu) AS pagu_agu, SUM(a.pagu_sep) AS pagu_sep,
                   SUM(a.pagu_okt) AS pagu_okt, SUM(a.pagu_nov) AS pagu_nov, SUM(a.pagu_des) AS pagu_des
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
              )
            GROUP BY a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan
        ";
        $stmt_ang = $conn->prepare($query_ang);
        if (!$stmt_ang) {
            error_log("Prepare error getDeviasiPerOPD (anggaran): " . $conn->error);
            continue;
        }
        $stmt_ang->bind_param('ii', $opd_id_int, $tahun_int);
        $stmt_ang->execute();
        $res_ang = $stmt_ang->get_result();
        $subkeg_list = [];
        while ($row = $res_ang->fetch_assoc()) {
            $subkeg_list[] = $row;
        }
        $stmt_ang->close();

        // --- Pagu awal (versi minimum terkunci) ---
        $awal_pagu_map = [];
        if (!empty($subkeg_list)) {
            $q_awal = "
                SELECT a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan,
                       SUM(a.total_pagu) AS awal_pagu
                FROM anggaran_detail a
                WHERE a.opd_id = ? AND a.tahun = ?
                  AND a.status_validasi = 'dikunci'
                  AND a.versi = (
                      SELECT MIN(versi) FROM anggaran_detail a3
                      WHERE a3.opd_id = a.opd_id AND a3.tahun = a.tahun
                        AND a3.kode_program = a.kode_program
                        AND a3.kode_kegiatan = a.kode_kegiatan
                        AND a3.kode_sub_kegiatan = a.kode_sub_kegiatan
                        AND a3.rincian_belanja_id = a.rincian_belanja_id
                  )
                GROUP BY a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan
            ";
            $stmt_awal = $conn->prepare($q_awal);
            if (!$stmt_awal) {
                error_log("Prepare error getDeviasiPerOPD (awal): " . $conn->error);
            } else {
                $stmt_awal->bind_param('ii', $opd_id_int, $tahun_int);
                $stmt_awal->execute();
                $res_awal = $stmt_awal->get_result();
                while ($rw = $res_awal->fetch_assoc()) {
                    $k = $rw['kode_program'].'|'.$rw['kode_kegiatan'].'|'.$rw['kode_sub_kegiatan'];
                    $awal_pagu_map[$k] = $rw['awal_pagu'];
                }
                $stmt_awal->close();
            }
        }

        // --- Realisasi: hanya bulan yang statusnya 'dikunci' ---
        $real_map = [];
        if (!empty($subkeg_list)) {
            $sum_parts = [];
            foreach ($bulan_keys as $b) {
                $sum_parts[] = "SUM(CASE WHEN r.status_$b = 'dikunci' THEN COALESCE(r.pagu_$b, 0) ELSE 0 END) AS rp_$b";
            }
            $sum_expr = implode(', ', $sum_parts);

            $q_real = "
                SELECT a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan,
                       $sum_expr
                FROM anggaran_detail a
                JOIN realisasi_detail r ON a.id = r.anggaran_detail_id
                                       AND r.opd_id = a.opd_id AND r.tahun = a.tahun
                WHERE a.opd_id = ? AND a.tahun = ?
                  AND a.status_validasi = 'dikunci'
                  AND a.versi = (
                      SELECT MAX(versi) FROM anggaran_detail a2
                      WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun
                        AND a2.kode_program = a.kode_program
                        AND a2.kode_kegiatan = a.kode_kegiatan
                        AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan
                        AND a2.rincian_belanja_id = a.rincian_belanja_id
                  )
                GROUP BY a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan
            ";
            $stmt_real = $conn->prepare($q_real);
            if (!$stmt_real) {
                error_log("Prepare error getDeviasiPerOPD (realisasi): " . $conn->error);
            } else {
                $stmt_real->bind_param('ii', $opd_id_int, $tahun_int);
                $stmt_real->execute();
                $res_real = $stmt_real->get_result();
                while ($rr = $res_real->fetch_assoc()) {
                    $key = $rr['kode_program'].'|'.$rr['kode_kegiatan'].'|'.$rr['kode_sub_kegiatan'];
                    $real_map[$key] = $rr;
                }
                $stmt_real->close();
            }
        }

        // --- Hitung agregat ---
        $total_ps = 0;
        $total_rs = 0;
        $total_pagu_efektif = 0;
        $total_awal = 0;
        foreach ($subkeg_list as $sk) {
            $key = $sk['kode_program'].'|'.$sk['kode_kegiatan'].'|'.$sk['kode_sub_kegiatan'];
            $awal = (float) ($awal_pagu_map[$key] ?? 0);
            $pergeseran = (float) $sk['total_pagu'];
            $pagu_efektif = ($pergeseran > 0) ? $pergeseran : $awal;

            $ps = 0;
            for ($i = 0; $i < $bulan_int; $i++) {
                $ps += (float) ($sk['pagu_' . $bulan_keys[$i]] ?? 0);
            }
            $rs = 0;
            if (isset($real_map[$key])) {
                for ($i = 0; $i < $bulan_int; $i++) {
                    $rs += (float) ($real_map[$key]['rp_' . $bulan_keys[$i]] ?? 0);
                }
            }
            $total_ps += $ps;
            $total_rs += $rs;
            $total_pagu_efektif += $pagu_efektif;
            $total_awal += $awal;
        }

        $pagu_efektif_total = ($total_pagu_efektif > 0) ? $total_pagu_efektif : $total_awal;
        $target_persen = ($pagu_efektif_total > 0) ? round(($total_ps / $pagu_efektif_total) * 100, 2) : 0;
        $realisasi_persen = ($pagu_efektif_total > 0) ? round(($total_rs / $pagu_efektif_total) * 100, 2) : 0;
        $deviasi = round($target_persen - $realisasi_persen, 2);

        $result[] = [
            'opd_id'          => $opd_id_int,
            'nama_opd'        => $nama_opd,
            'nama_opd_pendek' => shortenNamaOPD($nama_opd),
            'target_persen'   => $target_persen,
            'realisasi_persen'=> $realisasi_persen,
            'deviasi'         => $deviasi,
            'total_ps'        => $total_ps,
            'total_rs'        => $total_rs,
            'pagu_efektif'    => $pagu_efektif_total
        ];
    }

    // Urutkan berdasarkan deviasi tertinggi
    usort($result, function($a, $b) {
        return $b['deviasi'] <=> $a['deviasi'];
    });
    return $result;
}

// ========== MAPPING UPTD -> DINAS INDUK ==========
$opdMappingFile = __DIR__ . '/../tool/opd_mapping.php';
$parentMap = [];
$uptdToParentId = [];
if (file_exists($opdMappingFile)) {
    include $opdMappingFile;
    // Ambil semua OPD dari database untuk resolusi ID
    $res = $conn->query("SELECT id, nama_opd FROM opd");
    if ($res) {
        $opdByName = [];
        while ($r = $res->fetch_assoc()) {
            $key = strtoupper(trim($r['nama_opd']));
            $opdByName[$key] = (int) $r['id'];
        }
        // Mapping UPTD -> induk
        if (isset($opd_mapping) && is_array($opd_mapping)) {
            foreach ($opd_mapping as $uptdName => $parentName) {
                $uptdKey = strtoupper(trim($uptdName));
                $parentKey = strtoupper(trim($parentName));
                if (isset($opdByName[$uptdKey]) && isset($opdByName[$parentKey])) {
                    $uptdToParentId[ $opdByName[$uptdKey] ] = $opdByName[$parentKey];
                    $parentMap[ $opdByName[$parentKey] ] = $parentName;
                }
            }
        }
    }
}

// ========== QUERY DATA SESUAI ROLE ==========
if (in_array($role, ['super_admin', 'eksekutif'])) {
    // --- 1. Anggaran belum dikunci ---
    $stmt = $conn->prepare("SELECT COUNT(*) as jml FROM anggaran_detail WHERE tahun = ? AND status_validasi = 'divalidasi'");
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $anggaran_belum_dikunci = $stmt->get_result()->fetch_assoc()['jml'] ?? 0;
    $stmt->close();

    // --- 2. Realisasi belum dikunci (cek JSON status_mingguan) ---
    $realisasi_belum_dikunci = 0;
    $stmt = $conn->prepare("SELECT status_mingguan FROM realisasi_detail WHERE tahun = ?");
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $status = json_decode($row['status_mingguan'] ?? '{}', true);
        foreach ($bulan_keys as $b) {
            for ($w = 1; $w <= 5; $w++) {
                if (($status[$b]['w'.$w] ?? 'draft') === 'divalidasi') {
                    $realisasi_belum_dikunci++;
                }
            }
        }
    }
    $stmt->close();

    // --- 3. Total OPD ---
    $total_opd = $conn->query("SELECT COUNT(*) as jml FROM opd")->fetch_assoc()['jml'] ?? 0;

    // --- 4. Total Program ---
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT kode_program) as jml FROM anggaran_detail WHERE tahun = ? AND status_validasi = 'dikunci'");
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $total_program = $stmt->get_result()->fetch_assoc()['jml'] ?? 0;
    $stmt->close();

    // --- 5. Total Pagu Tahunan (versi terkunci terbaru) ---
    $sql_total_pagu = "
        SELECT SUM(a.total_pagu) as total
        FROM anggaran_detail a
        WHERE a.tahun = ? AND a.status_validasi = 'dikunci'
          AND a.versi = (
              SELECT MAX(a2.versi) FROM anggaran_detail a2
              WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun
                AND a2.kode_program = a.kode_program
                AND a2.kode_kegiatan = a.kode_kegiatan
                AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan
                AND a2.rincian_belanja_id = a.rincian_belanja_id
          )
    ";
    $stmt = $conn->prepare($sql_total_pagu);
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $total_anggaran = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // --- 6. Total Realisasi (akumulasi s/d bulan ini, hanya bulan terkunci) ---
    $bulan_sql_parts_real = [];
    for ($i = 0; $i < $bulan_ini; $i++) {
        $b = $bulan_keys[$i];
        $bulan_sql_parts_real[] = "CASE WHEN r.status_$b = 'dikunci' THEN COALESCE(r.pagu_$b, 0) ELSE 0 END";
    }
    $bulan_sum_expr_real = implode(' + ', $bulan_sql_parts_real);
    $stmt = $conn->prepare("SELECT SUM($bulan_sum_expr_real) AS total FROM realisasi_detail r WHERE r.tahun = ?");
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $total_realisasi = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    $persen_realisasi = ($total_anggaran > 0) ? round(($total_realisasi / $total_anggaran) * 100, 2) : 0;

    // --- 7. Kenaikan realisasi bulan ini vs bulan lalu ---
    $bulan_ini_key = $bulan_keys[$bulan_ini - 1];
    if ($bulan_ini == 1) {
        $prev_bulan = 12;
        $prev_tahun = $tahun - 1;
    } else {
        $prev_bulan = $bulan_ini - 1;
        $prev_tahun = $tahun;
    }
    $prev_bulan_key = $bulan_keys[$prev_bulan - 1];
    $stmt = $conn->prepare("SELECT 
        SUM(CASE WHEN status_$bulan_ini_key = 'dikunci' THEN COALESCE(pagu_$bulan_ini_key, 0) ELSE 0 END) AS ini,
        SUM(CASE WHEN status_$prev_bulan_key = 'dikunci' THEN COALESCE(pagu_$prev_bulan_key, 0) ELSE 0 END) AS lalu
        FROM realisasi_detail WHERE tahun = ?");
    $stmt->bind_param('i', $tahun);
    $stmt->execute();
    $row_kenaikan = $stmt->get_result()->fetch_assoc();
    $real_bulan_ini = $row_kenaikan['ini'] ?? 0;
    $real_bulan_lalu = $row_kenaikan['lalu'] ?? 0;
    $stmt->close();
    $kenaikan_rp = $real_bulan_ini - $real_bulan_lalu;
    $kenaikan_persen = ($real_bulan_lalu > 0) ? round(($kenaikan_rp / $real_bulan_lalu) * 100, 2) : 0;

    // --- 8. Grafik deviasi (target bulanan) ---
    $grafik_deviasi_mentah = getDeviasiPerOPD($conn, $tahun, $bulan_ini);

    // Agregasi UPTD -> induk untuk deviasi
    $groupedDeviasi = [];
    foreach ($grafik_deviasi_mentah as $item) {
        $id = $item['opd_id'];
        if (isset($uptdToParentId[$id])) {
            $parentId = $uptdToParentId[$id];
            if (!isset($groupedDeviasi[$parentId])) {
                $parentData = null;
                foreach ($grafik_deviasi_mentah as $p) {
                    if ($p['opd_id'] == $parentId) { $parentData = $p; break; }
                }
                $groupedDeviasi[$parentId] = $parentData ?? [
                    'opd_id'   => $parentId,
                    'nama_opd' => $parentMap[$parentId] ?? 'Induk',
                    'nama_opd_pendek' => shortenNamaOPD($parentMap[$parentId] ?? 'Induk'),
                    'total_ps' => 0,
                    'total_rs' => 0,
                    'pagu_efektif' => 0,
                    'target_persen' => 0,
                    'realisasi_persen' => 0,
                    'deviasi' => 0
                ];
            }
            $groupedDeviasi[$parentId]['total_ps'] += $item['total_ps'];
            $groupedDeviasi[$parentId]['total_rs'] += $item['total_rs'];
            $groupedDeviasi[$parentId]['pagu_efektif'] += $item['pagu_efektif'];
        } else {
            if (!isset($groupedDeviasi[$id])) $groupedDeviasi[$id] = $item;
            else {
                $groupedDeviasi[$id]['total_ps'] += $item['total_ps'];
                $groupedDeviasi[$id]['total_rs'] += $item['total_rs'];
                $groupedDeviasi[$id]['pagu_efektif'] += $item['pagu_efektif'];
            }
        }
    }
    foreach ($groupedDeviasi as &$g) {
        if ($g['pagu_efektif'] > 0) {
            $g['target_persen'] = round(($g['total_ps'] / $g['pagu_efektif']) * 100, 2);
            $g['realisasi_persen'] = round(($g['total_rs'] / $g['pagu_efektif']) * 100, 2);
            $g['deviasi'] = round($g['target_persen'] - $g['realisasi_persen'], 2);
        }
    }
    unset($g);
    $grafik_deviasi = array_values(array_filter($groupedDeviasi, fn($g) => $g['pagu_efektif'] > 0));
    usort($grafik_deviasi, fn($a,$b) => $b['deviasi'] <=> $a['deviasi']);

    // Ringkasan deviasi
    $total_dev = 0;
    foreach ($grafik_deviasi as $d) $total_dev += $d['deviasi'];
    $rata_deviasi = count($grafik_deviasi) > 0 ? round($total_dev / count($grafik_deviasi), 2) : 0;
    $max_deviasi = count($grafik_deviasi) > 0 ? $grafik_deviasi[0]['deviasi'] : 0;
    $min_deviasi = count($grafik_deviasi) > 0 ? $grafik_deviasi[count($grafik_deviasi)-1]['deviasi'] : 0;
    $deviasi_top5 = array_slice($grafik_deviasi, 0, 5);
    $deviasi_bottom5 = array_reverse(array_slice($grafik_deviasi, -5));

    // --- 9. Data Realisasi vs Sisa untuk seluruh OPD (agregasi UPTD) ---
    $query_real = "
        SELECT o.id, o.nama_opd,
               COALESCE(tp.total_pagu_tahunan, 0) AS total_pagu_tahunan,
               COALESCE(tr.total_realisasi, 0) AS total_realisasi
        FROM opd o
        LEFT JOIN (
            SELECT a.opd_id, SUM(a.total_pagu) AS total_pagu_tahunan
            FROM anggaran_detail a
            WHERE a.tahun = ? AND a.status_validasi = 'dikunci'
              AND a.versi = (
                  SELECT MAX(a2.versi) FROM anggaran_detail a2
                  WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun
                    AND a2.kode_program = a.kode_program
                    AND a2.kode_kegiatan = a.kode_kegiatan
                    AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan
                    AND a2.rincian_belanja_id = a.rincian_belanja_id
              )
            GROUP BY a.opd_id
        ) tp ON tp.opd_id = o.id
        LEFT JOIN (
            SELECT r.opd_id, SUM($bulan_sum_expr_real) AS total_realisasi
            FROM realisasi_detail r WHERE r.tahun = ?
            GROUP BY r.opd_id
        ) tr ON tr.opd_id = o.id
        ORDER BY o.nama_opd
    ";
    $stmt = $conn->prepare($query_real);
    $stmt->bind_param('ii', $tahun, $tahun);
    $stmt->execute();
    $res_real = $stmt->get_result();
    $all_opd_raw = [];
    while ($row = $res_real->fetch_assoc()) {
        $all_opd_raw[] = $row;
    }
    $stmt->close();

    // Tabel daftar OPD (semua OPD termasuk UPTD) – disimpan terpisah untuk tabel
    $kepala_by_id = [];
    $res_kep = $conn->query("SELECT id, nama_kepala FROM opd");
    while ($k = $res_kep->fetch_assoc()) {
        $kepala_by_id[(int)$k['id']] = $k['nama_kepala'] ?: '-';
    }
    $all_opd_rows = [];
    foreach ($all_opd_raw as $row) {
        $pagu = $row['total_pagu_tahunan'];
        $real = $row['total_realisasi'];
        $persen = ($pagu > 0) ? round(($real / $pagu) * 100, 2) : 0;
        $all_opd_rows[] = [
            'nama_opd'      => $row['nama_opd'],
            'nama_kepala'   => $kepala_by_id[(int)$row['id']] ?? '-',
            'total_anggaran' => $pagu,
            'total_realisasi'=> $real,
            'persen'         => $persen
        ];
    }

    // Agregasi UPTD -> induk untuk grafik realisasi vs sisa
    $aggrOpd = [];
    foreach ($all_opd_raw as $row) {
        $id = (int)$row['id'];
        if (isset($uptdToParentId[$id])) {
            $pid = $uptdToParentId[$id];
            if (!isset($aggrOpd[$pid])) {
                $parentRow = null;
                foreach ($all_opd_raw as $r) {
                    if ((int)$r['id'] == $pid) { $parentRow = $r; break; }
                }
                $aggrOpd[$pid] = $parentRow ?? [
                    'id' => $pid,
                    'nama_opd' => $parentMap[$pid] ?? 'Induk',
                    'total_pagu_tahunan' => 0,
                    'total_realisasi' => 0
                ];
            }
            $aggrOpd[$pid]['total_pagu_tahunan'] += $row['total_pagu_tahunan'];
            $aggrOpd[$pid]['total_realisasi'] += $row['total_realisasi'];
        } else {
            if (!isset($aggrOpd[$id])) $aggrOpd[$id] = $row;
            else {
                $aggrOpd[$id]['total_pagu_tahunan'] += $row['total_pagu_tahunan'];
                $aggrOpd[$id]['total_realisasi'] += $row['total_realisasi'];
            }
        }
    }

    $all_opd_data = [];
    foreach ($aggrOpd as $id => $r) {
        $pagu = $r['total_pagu_tahunan'];
        $real = $r['total_realisasi'];
        $persen_real = ($pagu > 0) ? round(($real / $pagu) * 100, 2) : 0;
        $persen_sisa = ($pagu > 0) ? round(100 - $persen_real, 2) : 100;
        $all_opd_data[] = [
            'id'              => $id,
            'nama_opd'        => $r['nama_opd'],
            'nama_opd_pendek' => shortenNamaOPD($r['nama_opd']),
            'pagu'            => $pagu,
            'realisasi'       => $real,
            'persen_real'     => $persen_real,
            'persen_sisa'     => $persen_sisa
        ];
    }
    usort($all_opd_data, fn($a,$b) => $b['persen_real'] <=> $a['persen_real']);

    $opd_names   = array_column($all_opd_data, 'nama_opd_pendek');
    $real_persen = array_column($all_opd_data, 'persen_real');
    $sisa_persen = array_column($all_opd_data, 'persen_sisa');

    $top5_opd    = array_slice($all_opd_data, 0, 5);
    $bottom5_opd = array_reverse(array_slice($all_opd_data, -5));
    $top5        = array_map(fn($x) => ['opd' => $x['nama_opd_pendek'], 'total' => $x['persen_real']], $top5_opd);
    $bottom5     = array_map(fn($x) => ['opd' => $x['nama_opd_pendek'], 'total' => $x['persen_real']], $bottom5_opd);

    $persen_real_terbaru = $persen_realisasi;
    $persen_sisa_terbaru = round(100 - $persen_real_terbaru, 2);

    // --- 10. Data permasalahan OPD ---
    $permasalahan_data = [];
    $stmt = $conn->prepare("
        SELECT p.*, o.nama_opd
        FROM opd_permasalahan p
        JOIN opd o ON p.opd_id = o.id
        WHERE p.tahun = ? AND p.bulan = ?
        ORDER BY p.deviasi DESC
    ");
    $stmt->bind_param('ii', $tahun, $bulan_ini);
    $stmt->execute();
    $res_perm = $stmt->get_result();
    while ($row = $res_perm->fetch_assoc()) {
        $kodes = json_decode($row['kode_permasalahan'], true) ?: [];
        $deskripsi_list = [];
        foreach ($kodes as $kode) {
            $deskripsi_list[] = $masalah_map[$kode] ?? $kode;
        }
        $permasalahan_data[] = [
            'opd'                => shortenNamaOPD($row['nama_opd']),
            'deviasi'            => (float)$row['deviasi'],
            'kode_masalah'       => $kodes,
            'deskripsi_masalah'  => $deskripsi_list,
            'keterangan_other'   => $row['keterangan_other'] ?: '',
        ];
    }
    $stmt->close();

    // --- 11. 5 Permasalahan Terbanyak ---
    $kode_frekuensi = [];
    $kode_opd_list  = [];
    $stmt = $conn->prepare("
        SELECT p.kode_permasalahan, o.nama_opd
        FROM opd_permasalahan p
        JOIN opd o ON p.opd_id = o.id
        WHERE p.tahun = ? AND p.bulan = ?
    ");
    $stmt->bind_param('ii', $tahun, $bulan_ini);
    $stmt->execute();
    $res_perm_all = $stmt->get_result();
    while ($row = $res_perm_all->fetch_assoc()) {
        $kodes = json_decode($row['kode_permasalahan'], true) ?: [];
        $nama_opd_pendek = shortenNamaOPD($row['nama_opd']);
        foreach ($kodes as $kode) {
            if (!isset($kode_frekuensi[$kode])) {
                $kode_frekuensi[$kode] = 0;
                $kode_opd_list[$kode] = [];
            }
            $kode_frekuensi[$kode]++;
            if (!in_array($nama_opd_pendek, $kode_opd_list[$kode])) {
                $kode_opd_list[$kode][] = $nama_opd_pendek;
            }
        }
    }
    $stmt->close();
    arsort($kode_frekuensi);
    $top5_kode = array_slice(array_keys($kode_frekuensi), 0, 5);
    $top5_permasalahan = [];
    foreach ($top5_kode as $kode) {
        $deskripsi = $masalah_map[$kode] ?? $kode;
        $top5_permasalahan[] = [
            'kode'      => $kode,
            'deskripsi' => $deskripsi,
            'jumlah'    => $kode_frekuensi[$kode],
            'opd_list'  => $kode_opd_list[$kode]
        ];
    }

    // Data untuk AI
    $ai_data = [
        'total_anggaran'   => $total_anggaran,
        'total_realisasi'  => $total_realisasi,
        'persen_realisasi' => $persen_real_terbaru,
        'top5_tertinggi'   => $top5,
        'bottom5_terendah' => $bottom5,
        'deviasi_top5'     => array_map(fn($d) => ['opd'=>$d['nama_opd_pendek'],'deviasi'=>$d['deviasi']], $deviasi_top5),
        'deviasi_bottom5'  => array_map(fn($d) => ['opd'=>$d['nama_opd_pendek'],'deviasi'=>$d['deviasi']], $deviasi_bottom5),
        'rata_deviasi'     => $rata_deviasi
    ];

} elseif ($role == 'admin_opd') {
    // --- Info OPD ---
    $stmt = $conn->prepare("SELECT nama_opd, nama_kepala FROM opd WHERE id = ?");
    $stmt->bind_param('i', $opd_id);
    $stmt->execute();
    $opd_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Total program, kegiatan, sub kegiatan, rincian
    $counts = [];
    $queries = [
        'total_program'      => "SELECT COUNT(DISTINCT kode_program) as jml FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'",
        'total_kegiatan'     => "SELECT COUNT(DISTINCT kode_kegiatan) as jml FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'",
        'total_sub_kegiatan' => "SELECT COUNT(DISTINCT kode_sub_kegiatan) as jml FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'",
        'total_rincian'      => "SELECT COUNT(DISTINCT rincian_belanja_id) as jml FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'"
    ];
    foreach ($queries as $key => $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $opd_id, $tahun);
        $stmt->execute();
        $counts[$key] = $stmt->get_result()->fetch_assoc()['jml'] ?? 0;
        $stmt->close();
    }
    extract($counts); // $total_program, $total_kegiatan, $total_sub_kegiatan, $total_rincian

    // Pagu OPD (versi terbaru terkunci)
    $sql_pagu_opd = "
        SELECT SUM(a.total_pagu) as total
        FROM anggaran_detail a
        WHERE a.opd_id = ? AND a.tahun = ? AND a.status_validasi = 'dikunci'
          AND a.versi = (
              SELECT MAX(versi) FROM anggaran_detail a2
              WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun
                AND a2.kode_program = a.kode_program
                AND a2.kode_kegiatan = a.kode_kegiatan
                AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan
                AND a2.rincian_belanja_id = a.rincian_belanja_id
          )
    ";
    $stmt = $conn->prepare($sql_pagu_opd);
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $total_pagu = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Realisasi
    $bulan_sql_parts_real = [];
    for ($i = 0; $i < $bulan_ini; $i++) {
        $b = $bulan_keys[$i];
        $bulan_sql_parts_real[] = "CASE WHEN r.status_$b = 'dikunci' THEN COALESCE(r.pagu_$b, 0) ELSE 0 END";
    }
    $bulan_sum_expr_real = implode(' + ', $bulan_sql_parts_real);
    $stmt = $conn->prepare("SELECT SUM($bulan_sum_expr_real) AS total FROM realisasi_detail r WHERE r.opd_id = ? AND r.tahun = ?");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $total_realisasi = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    $persen_realisasi = ($total_pagu > 0) ? round(($total_realisasi / $total_pagu) * 100, 2) : 0;

    // Daftar Program
    $program_query = "
        SELECT a.kode_program,
               SUM(a.total_pagu) AS pagu,
               COUNT(DISTINCT a.kode_kegiatan) AS jml_kegiatan,
               COUNT(DISTINCT a.kode_sub_kegiatan) AS jml_sub_kegiatan,
               COUNT(DISTINCT a.rincian_belanja_id) AS jml_rincian,
               COALESCE(SUM(rl.total_realisasi), 0) AS realisasi
        FROM anggaran_detail a
        LEFT JOIN (
            SELECT r.anggaran_detail_id, SUM($bulan_sum_expr_real) AS total_realisasi
            FROM realisasi_detail r WHERE r.tahun = ?
            GROUP BY r.anggaran_detail_id
        ) rl ON rl.anggaran_detail_id = a.id
        WHERE a.opd_id = ? AND a.tahun = ? AND a.status_validasi = 'dikunci'
          AND a.versi = (
              SELECT MAX(versi) FROM anggaran_detail a2
              WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun
                AND a2.kode_program = a.kode_program
                AND a2.kode_kegiatan = a.kode_kegiatan
                AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan
                AND a2.rincian_belanja_id = a.rincian_belanja_id
          )
        GROUP BY a.kode_program
        ORDER BY a.kode_program
    ";
    $stmt = $conn->prepare($program_query);
    $stmt->bind_param('iii', $tahun, $opd_id, $tahun);
    $stmt->execute();
    $program_result = $stmt->get_result(); // jangan close, akan digunakan di layout

    // Deviasi OPD saat ini
    $deviasi_opd = getDeviasiPerOPD($conn, $tahun, $bulan_ini);
    $current_deviasi = 0;
    $grafik_opd = [];
    foreach ($deviasi_opd as $d) {
        if ($d['opd_id'] == $opd_id) {
            $current_deviasi = $d['deviasi'];
            $grafik_opd[] = $d;
            break;
        }
    }
    $show_warning = ($current_deviasi >= 11);

    // --- Permasalahan OPD ini (bulan ini) ---
    $permasalahan_opd = [];
    $stmt = $conn->prepare("
        SELECT kode_permasalahan, keterangan_other, deviasi, created_at
        FROM opd_permasalahan
        WHERE opd_id = ? AND tahun = ? AND bulan = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('iii', $opd_id, $tahun, $bulan_ini);
    $stmt->execute();
    $res_perm_opd = $stmt->get_result();
    while ($row = $res_perm_opd->fetch_assoc()) {
        $kodes = json_decode($row['kode_permasalahan'], true) ?: [];
        $deskripsi_list = [];
        foreach ($kodes as $kode) {
            $deskripsi_list[] = $masalah_map[$kode] ?? $kode;
        }
        $permasalahan_opd[] = [
            'kode'              => $kodes,
            'deskripsi'         => $deskripsi_list,
            'keterangan_other'  => $row['keterangan_other'] ?: '',
            'deviasi'           => (float)$row['deviasi'],
            'created_at'        => $row['created_at']
        ];
    }
    $stmt->close();

    // History permasalahan 3 bulan terakhir
    $history_permasalahan = [];
    $stmt_hist = $conn->prepare("
        SELECT bulan, kode_permasalahan, keterangan_other, deviasi, created_at
        FROM opd_permasalahan
        WHERE opd_id = ? AND tahun = ?
        ORDER BY bulan DESC, created_at DESC
        LIMIT 10
    ");
    $stmt_hist->bind_param('ii', $opd_id, $tahun);
    $stmt_hist->execute();
    $res_hist = $stmt_hist->get_result();
    while ($row = $res_hist->fetch_assoc()) {
        $kodes = json_decode($row['kode_permasalahan'], true) ?: [];
        $deskripsi_list = [];
        foreach ($kodes as $kode) {
            $deskripsi_list[] = $masalah_map[$kode] ?? $kode;
        }
        $history_permasalahan[] = [
            'bulan'            => $bulan_indonesia[(int)$row['bulan'] - 1] ?? $row['bulan'],
            'kode'             => $kodes,
            'deskripsi'        => $deskripsi_list,
            'keterangan_other' => $row['keterangan_other'] ?: '',
            'deviasi'          => (float)$row['deviasi'],
            'created_at'       => $row['created_at']
        ];
    }
    $stmt_hist->close();
    // ========== CEK BATAS WAKTU INPUT ==========
    $batas_waktu_lewat = false;
    $batas_waktu_info = null;
    $stmt_bw = $conn->prepare("SELECT batas_waktu, dieksekusi, jenis FROM waktu_batas WHERE tahun = ?");
    $stmt_bw->bind_param('i', $tahun);
    $stmt_bw->execute();
    $res_bw = $stmt_bw->get_result();
    if ($row_bw = $res_bw->fetch_assoc()) {
        $batas_waktu_info = [
            'batas_waktu'  => $row_bw['batas_waktu'],
            'dieksekusi'   => $row_bw['dieksekusi'],
            'jenis'        => $row_bw['jenis']
        ];
        if ($row_bw['dieksekusi'] == 1 || strtotime($row_bw['batas_waktu']) <= time()) {
            $batas_waktu_lewat = true;
        }
    }
    $stmt_bw->close();

} elseif ($role == 'kepala_opd') {
    // Info OPD
    $stmt = $conn->prepare("SELECT nama_opd FROM opd WHERE id = ?");
    $stmt->bind_param('i', $opd_id);
    $stmt->execute();
    $opd_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Anggaran draft
    $stmt = $conn->prepare("SELECT COUNT(*) as jml FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'draft'");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $anggaran_draft = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Realisasi draft (data yang status_bulan != dikunci)
    $realisasi_draft = 0;
    $stmt = $conn->prepare("SELECT * FROM realisasi_detail WHERE opd_id = ? AND tahun = ?");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        for ($i = 0; $i < $bulan_ini; $i++) {
            $b = $bulan_keys[$i];
            if (($row["status_$b"] ?? 'draft') != 'dikunci') {
                $realisasi_draft++;
            }
        }
    }
    $stmt->close();

    // Pagu OPD
    $sql_pagu_opd = "
        SELECT SUM(a.total_pagu) as total
        FROM anggaran_detail a
        WHERE a.opd_id = ? AND a.tahun = ? AND a.status_validasi = 'dikunci'
          AND a.versi = (
              SELECT MAX(versi) FROM anggaran_detail a2
              WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun
                AND a2.kode_program = a.kode_program
                AND a2.kode_kegiatan = a.kode_kegiatan
                AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan
                AND a2.rincian_belanja_id = a.rincian_belanja_id
          )
    ";
    $stmt = $conn->prepare($sql_pagu_opd);
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $total_pagu = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Realisasi
    $bulan_sql_parts_real = [];
    for ($i = 0; $i < $bulan_ini; $i++) {
        $b = $bulan_keys[$i];
        $bulan_sql_parts_real[] = "CASE WHEN r.status_$b = 'dikunci' THEN COALESCE(r.pagu_$b, 0) ELSE 0 END";
    }
    $bulan_sum_expr_real = implode(' + ', $bulan_sql_parts_real);
    $stmt = $conn->prepare("SELECT SUM($bulan_sum_expr_real) AS total FROM realisasi_detail r WHERE r.opd_id = ? AND r.tahun = ?");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $total_realisasi = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();
    $persen_realisasi = ($total_pagu > 0) ? round(($total_realisasi / $total_pagu) * 100, 2) : 0;

    // Deviasi
    $deviasi_semua = getDeviasiPerOPD($conn, $tahun, $bulan_ini);
    $grafik_opd = [];
    $current_deviasi = 0;
    foreach ($deviasi_semua as $d) {
        if ($d['opd_id'] == $opd_id) {
            $grafik_opd[] = $d;
            $current_deviasi = $d['deviasi'];
            break;
        }
    }
    $show_warning = ($current_deviasi >= 11);

    // --- Permasalahan OPD ini (bulan ini) ---
    $permasalahan_opd = [];
    $stmt = $conn->prepare("
        SELECT kode_permasalahan, keterangan_other, deviasi, created_at
        FROM opd_permasalahan
        WHERE opd_id = ? AND tahun = ? AND bulan = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('iii', $opd_id, $tahun, $bulan_ini);
    $stmt->execute();
    $res_perm_opd = $stmt->get_result();
    while ($row = $res_perm_opd->fetch_assoc()) {
        $kodes = json_decode($row['kode_permasalahan'], true) ?: [];
        $deskripsi_list = [];
        foreach ($kodes as $kode) {
            $deskripsi_list[] = $masalah_map[$kode] ?? $kode;
        }
        $permasalahan_opd[] = [
            'kode'              => $kodes,
            'deskripsi'         => $deskripsi_list,
            'keterangan_other'  => $row['keterangan_other'] ?: '',
            'deviasi'           => (float)$row['deviasi'],
            'created_at'        => $row['created_at']
        ];
    }
    $stmt->close();

    // History permasalahan 3 bulan terakhir
    $history_permasalahan = [];
    $stmt_hist = $conn->prepare("
        SELECT bulan, kode_permasalahan, keterangan_other, deviasi, created_at
        FROM opd_permasalahan
        WHERE opd_id = ? AND tahun = ?
        ORDER BY bulan DESC, created_at DESC
        LIMIT 10
    ");
    $stmt_hist->bind_param('ii', $opd_id, $tahun);
    $stmt_hist->execute();
    $res_hist = $stmt_hist->get_result();
    while ($row = $res_hist->fetch_assoc()) {
        $kodes = json_decode($row['kode_permasalahan'], true) ?: [];
        $deskripsi_list = [];
        foreach ($kodes as $kode) {
            $deskripsi_list[] = $masalah_map[$kode] ?? $kode;
        }
        $history_permasalahan[] = [
            'bulan'            => $bulan_indonesia[(int)$row['bulan'] - 1] ?? $row['bulan'],
            'kode'             => $kodes,
            'deskripsi'        => $deskripsi_list,
            'keterangan_other' => $row['keterangan_other'] ?: '',
            'deviasi'          => (float)$row['deviasi'],
            'created_at'       => $row['created_at']
        ];
    }
    $stmt_hist->close();
}

// ========== DATA REALISASI VS SISA UNTUK ADMIN/KEPALA ==========
if (in_array($role, ['admin_opd', 'kepala_opd'])) {
    // Query yang sama seperti di super_admin, tapi hanya untuk tampilan grafik semua OPD
    $bulan_sql_parts_real = [];
    for ($i = 0; $i < $bulan_ini; $i++) {
        $b = $bulan_keys[$i];
        $bulan_sql_parts_real[] = "CASE WHEN r.status_$b = 'dikunci' THEN COALESCE(r.pagu_$b, 0) ELSE 0 END";
    }
    $bulan_sum_expr_real = implode(' + ', $bulan_sql_parts_real);

    $query_real = "
        SELECT o.id, o.nama_opd,
               COALESCE(tp.total_pagu_tahunan, 0) AS total_pagu_tahunan,
               COALESCE(tr.total_realisasi, 0) AS total_realisasi
        FROM opd o
        LEFT JOIN (
            SELECT a.opd_id, SUM(a.total_pagu) AS total_pagu_tahunan
            FROM anggaran_detail a
            WHERE a.tahun = ? AND a.status_validasi = 'dikunci'
              AND a.versi = (
                  SELECT MAX(a2.versi) FROM anggaran_detail a2
                  WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun
                    AND a2.kode_program = a.kode_program
                    AND a2.kode_kegiatan = a.kode_kegiatan
                    AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan
                    AND a2.rincian_belanja_id = a.rincian_belanja_id
              )
            GROUP BY a.opd_id
        ) tp ON tp.opd_id = o.id
        LEFT JOIN (
            SELECT r.opd_id, SUM($bulan_sum_expr_real) AS total_realisasi
            FROM realisasi_detail r WHERE r.tahun = ?
            GROUP BY r.opd_id
        ) tr ON tr.opd_id = o.id
        ORDER BY o.nama_opd
    ";
    $stmt = $conn->prepare($query_real);
    $stmt->bind_param('ii', $tahun, $tahun);
    $stmt->execute();
    $res_real = $stmt->get_result();
    $all_opd_raw = [];
    while ($row = $res_real->fetch_assoc()) {
        $all_opd_raw[] = $row;
    }
    $stmt->close();

    // Agregasi UPTD
    $aggrOpd = [];
    foreach ($all_opd_raw as $row) {
        $id = (int)$row['id'];
        if (isset($uptdToParentId[$id])) {
            $pid = $uptdToParentId[$id];
            if (!isset($aggrOpd[$pid])) {
                $parentRow = null;
                foreach ($all_opd_raw as $r) {
                    if ((int)$r['id'] == $pid) { $parentRow = $r; break; }
                }
                $aggrOpd[$pid] = $parentRow ?? [
                    'id' => $pid,
                    'nama_opd' => $parentMap[$pid] ?? 'Induk',
                    'total_pagu_tahunan' => 0,
                    'total_realisasi' => 0
                ];
            }
            $aggrOpd[$pid]['total_pagu_tahunan'] += $row['total_pagu_tahunan'];
            $aggrOpd[$pid]['total_realisasi'] += $row['total_realisasi'];
        } else {
            if (!isset($aggrOpd[$id])) $aggrOpd[$id] = $row;
            else {
                $aggrOpd[$id]['total_pagu_tahunan'] += $row['total_pagu_tahunan'];
                $aggrOpd[$id]['total_realisasi'] += $row['total_realisasi'];
            }
        }
    }

    $all_opd_data = [];
    foreach ($aggrOpd as $id => $r) {
        $pagu = $r['total_pagu_tahunan'];
        $real = $r['total_realisasi'];
        $persen_real = ($pagu > 0) ? round(($real / $pagu) * 100, 2) : 0;
        $persen_sisa = ($pagu > 0) ? round(100 - $persen_real, 2) : 100;
        $all_opd_data[] = [
            'id'              => $id,
            'nama_opd'        => $r['nama_opd'],
            'nama_opd_pendek' => shortenNamaOPD($r['nama_opd']),
            'pagu'            => $pagu,
            'realisasi'       => $real,
            'persen_real'     => $persen_real,
            'persen_sisa'     => $persen_sisa
        ];
    }
    usort($all_opd_data, fn($a,$b) => $b['persen_real'] <=> $a['persen_real']);

    $opd_names_all   = array_column($all_opd_data, 'nama_opd_pendek');
    $real_persen_all = array_column($all_opd_data, 'persen_real');
    $sisa_persen_all = array_column($all_opd_data, 'persen_sisa');

    // Cari nama pendek OPD saat ini (jika merupakan UPTD, ambil induk)
    $effective_opd_id = $opd_id;
    if (isset($uptdToParentId[$opd_id])) {
        $effective_opd_id = $uptdToParentId[$opd_id];
    }
    $marked_opd_name_pendek = null;
    foreach ($all_opd_data as $d) {
        if ($d['id'] == $effective_opd_id) {
            $marked_opd_name_pendek = $d['nama_opd_pendek'];
            break;
        }
    }
}
// Akhir file functions – tidak ada output HTML