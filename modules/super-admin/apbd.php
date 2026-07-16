<?php
/**
 * apbd.php - Laporan APBD untuk Super Admin
 * 
 * Keamanan:
 * - Semua query database menggunakan prepared statement (anti SQL injection)
 * - Validasi parameter GET (opd_id, tahun, bulan, export)
 * - Proteksi path traversal pada file TTD
 * - Output escaping dengan htmlspecialchars()
 * - Header ekspor yang aman
 */

// Mulai session jika belum (header sudah handle)
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// Include mapping UPTD (dengan fallback)
$opd_mapping = [];
if (file_exists(__DIR__ . '/../dashboard/tool/opd_mapping.php')) {
    require_once __DIR__ . '/../dashboard/tool/opd_mapping.php';
    // Pastikan variabel $opd_mapping ada
    if (!isset($opd_mapping)) $opd_mapping = [];
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Dompdf\Dompdf;
use Dompdf\Options;

if ($_SESSION['role'] !== 'super_admin') {
    header('Location: ../dashboard/index.php');
    exit();
}

// ========== HELPER TTD Kepala OPD ==========
function getDataKepalaOpd($conn, $opd_id) {
    $data = [
        'ttd_path'    => null,
        'nama_kepala' => 'KEPALA OPD',
        'nama_opd'    => '',
        'tanggal'     => date('d F Y')
    ];
    if ($opd_id <= 0) return $data;

    $stmt = $conn->prepare("SELECT ttd, nama_opd FROM opd WHERE id = ?");
    if (!$stmt) return $data;
    $stmt->bind_param('i', $opd_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $data['ttd_path'] = $row['ttd'];
        $data['nama_opd'] = $row['nama_opd'];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT nama_lengkap FROM users WHERE opd_id = ? AND role = 'kepala_opd' LIMIT 1");
    if (!$stmt) return $data;
    $stmt->bind_param('i', $opd_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if (($row = $res->fetch_assoc()) && !empty($row['nama_lengkap'])) {
        $data['nama_kepala'] = $row['nama_lengkap'];
    }
    $stmt->close();

    return $data;
}

/**
 * Validasi path TTD agar aman dari path traversal dan hanya file gambar.
 */
function isValidTtdPath($path) {
    if (empty($path)) return false;
    // Cegah path traversal
    if (strpos($path, '..') !== false) return false;
    // Hanya izinkan karakter aman
    if (!preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $path)) return false;
    // Ekstensi gambar
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
}

// ========== AMBIL & VALIDASI PARAMETER ==========
$tahun     = isset($_GET['tahun'])  ? (int)$_GET['tahun']  : (int)date('Y');
$opd_id    = isset($_GET['opd_id']) ? (int)$_GET['opd_id'] : 0;
$bulan_ini = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('n');
$export    = isset($_GET['export']) && in_array($_GET['export'], ['excel','pdf',''], true) ? $_GET['export'] : '';

if ($tahun < 2000 || $tahun > 2100) $tahun = (int)date('Y');
if ($bulan_ini < 1 || $bulan_ini > 12) $bulan_ini = (int)date('n');

$bulan_keys      = ['jan', 'feb', 'mar', 'apr', 'mei', 'jun', 'jul', 'agu', 'sep', 'okt', 'nov', 'des'];
$bulan_indonesia = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

// ========== DAFTAR OPD UNTUK DROPDOWN (PREPARED) ==========
$hidden_names = array_keys($opd_mapping);
$opd_list_for_js = [];
if (!empty($hidden_names)) {
    $placeholders = implode(',', array_fill(0, count($hidden_names), '?'));
    $types = str_repeat('s', count($hidden_names));
    $stmt = $conn->prepare("SELECT id, nama_opd FROM opd WHERE nama_opd NOT IN ($placeholders) ORDER BY nama_opd");
    if ($stmt) {
        $stmt->bind_param($types, ...$hidden_names);
        $stmt->execute();
        $opd_list = $stmt->get_result();
    } else {
        $opd_list = $conn->query("SELECT id, nama_opd FROM opd ORDER BY nama_opd");
    }
} else {
    $opd_list = $conn->query("SELECT id, nama_opd FROM opd ORDER BY nama_opd");
}
if ($opd_list) {
    while ($o = $opd_list->fetch_assoc()) {
        $opd_list_for_js[] = ['id' => (int)$o['id'], 'nama' => $o['nama_opd']];
    }
    $opd_list->data_seek(0);
}

$show_table = ($opd_id > 0);

// --- helper ---
function getNama($kode, $map) { return $map[$kode] ?? '(tanpa uraian)'; }
function sumBulanan($row, $prefix, $keys, $bulan) {
    $sum = 0;
    for ($i = 0; $i < $bulan; $i++) $sum += $row[$prefix . $keys[$i]] ?? 0;
    return $sum;
}
function roman($n) {
    $map = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V'];
    return $map[$n] ?? (string)$n;
}
function getDeviasiClass($deviasi) {
    if ($deviasi <= 3.99) return 'dev-hijau';
    elseif ($deviasi <= 5.99) return 'dev-kuning';
    elseif ($deviasi <= 10.99) return 'dev-orange';
    else return 'dev-merah';
}
function getDeviasiColor($deviasi) {
    if ($deviasi <= 3.99) return 'FF28A745';
    elseif ($deviasi <= 5.99) return 'FFFFC107';
    elseif ($deviasi <= 10.99) return 'FFFD7E14';
    else return 'FFDC3545';
}

// ===================== PROSES DATA PER OPD =====================
$opd_sections = [];
$all_ids = [];
$nama_map = [];
$global_max_versi = 1;
$pergeseran_label = '';
$dinas_nama = '';
$all_max_versi = [];

if ($show_table) {
    // Nama dinas induk
    $stmt = $conn->prepare("SELECT nama_opd FROM opd WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $opd_id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $dinas_nama = $r ? $r['nama_opd'] : '';
        $stmt->close();
    }

    // Kumpulkan ID dinas + UPTD
    $all_ids = [$opd_id];
    if ($dinas_nama) {
        $uptd_names_list = [];
        foreach ($opd_mapping as $uptd => $dinas) {
            if ($dinas === $dinas_nama) {
                $uptd_names_list[] = $uptd;
            }
        }
        if (!empty($uptd_names_list)) {
            $placeholders = implode(',', array_fill(0, count($uptd_names_list), '?'));
            $types = str_repeat('s', count($uptd_names_list));
            $stmt = $conn->prepare("SELECT id FROM opd WHERE nama_opd IN ($placeholders)");
            if ($stmt) {
                $stmt->bind_param($types, ...$uptd_names_list);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($u = $res->fetch_assoc()) {
                    $all_ids[] = (int)$u['id'];
                }
                $stmt->close();
            }
        }
    }

    $all_codes_global = [];
    foreach ($all_ids as $oid) {
        $oid = (int)$oid;
        // Nama OPD section
        $stmt = $conn->prepare("SELECT nama_opd FROM opd WHERE id = ?");
        if (!$stmt) continue;
        $stmt->bind_param('i', $oid);
        $stmt->execute();
        $r_n = $stmt->get_result()->fetch_assoc();
        $nama_opd_section = $r_n ? $r_n['nama_opd'] : '';
        $stmt->close();

        // 1. Anggaran versi terbaru (dikunci) - level subkeg
        $q_ang = "
            SELECT a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan,
                   MAX(a.versi) AS max_versi,
                   SUM(a.total_volume) AS total_volume,
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
                    AND a2.status_validasi = 'dikunci'
              )
            GROUP BY a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan
            ORDER BY a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan
        ";
        $stmt_ang = $conn->prepare($q_ang);
        if (!$stmt_ang) continue;
        $stmt_ang->bind_param('ii', $oid, $tahun);
        $stmt_ang->execute();
        $res_ang = $stmt_ang->get_result();
        $subkeg_list = [];
        $max_versi_per_sub = [];
        while ($row = $res_ang->fetch_assoc()) {
            $subkeg_list[] = $row;
            $key = $row['kode_program'].'|'.$row['kode_kegiatan'].'|'.$row['kode_sub_kegiatan'];
            $max_versi_per_sub[$key] = (int)$row['max_versi'];
        }
        $stmt_ang->close();

        foreach ($max_versi_per_sub as $v) {
            $all_max_versi[] = $v;
        }

        if (empty($subkeg_list)) {
            $opd_sections[$oid] = null;
            continue;
        }

        // 2. Pagu awal (versi minimum terkunci)
        $awal_pagu_map = [];
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
                    AND a3.status_validasi = 'dikunci'
              )
            GROUP BY a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan
        ";
        $stmt_awal = $conn->prepare($q_awal);
        if ($stmt_awal) {
            $stmt_awal->bind_param('ii', $oid, $tahun);
            $stmt_awal->execute();
            $res_awal = $stmt_awal->get_result();
            while ($rw = $res_awal->fetch_assoc()) {
                $k = $rw['kode_program'].'|'.$rw['kode_kegiatan'].'|'.$rw['kode_sub_kegiatan'];
                $awal_pagu_map[$k] = $rw['awal_pagu'];
            }
            $stmt_awal->close();
        }

        // 3. Realisasi keuangan per subkeg
        $pagu_select = [];
        $vol_select  = [];
        for ($i = 1; $i <= $bulan_ini; $i++) {
            $bkey = $bulan_keys[$i - 1];
            $pagu_select[] = "SUM(CASE WHEN r.status_$bkey IN ('divalidasi','dikunci') THEN COALESCE(r.pagu_$bkey, 0) ELSE 0 END) AS rp_$bkey";
            $vol_select[]  = "SUM(CASE WHEN r.status_$bkey IN ('divalidasi','dikunci') THEN COALESCE(r.volume_$bkey, 0) ELSE 0 END) AS rv_$bkey";
        }
        for ($i = $bulan_ini + 1; $i <= 12; $i++) {
            $bkey = $bulan_keys[$i - 1];
            $pagu_select[] = "0 AS rp_$bkey";
            $vol_select[]  = "0 AS rv_$bkey";
        }

        $q_real = "
            SELECT a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan,
                   " . implode(', ', $pagu_select) . ",
                   " . implode(', ', $vol_select) . "
            FROM anggaran_detail a
            LEFT JOIN realisasi_detail r ON a.id = r.anggaran_detail_id
                                       AND r.opd_id = a.opd_id
                                       AND r.tahun = a.tahun
            WHERE a.opd_id = ?
              AND a.tahun = ?
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
            GROUP BY a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan
        ";
        $stmt_real = $conn->prepare($q_real);
        if (!$stmt_real) continue;
        $stmt_real->bind_param('ii', $oid, $tahun);
        $stmt_real->execute();
        $res_real = $stmt_real->get_result();
        $real_map = [];
        while ($rr = $res_real->fetch_assoc()) {
            $key = $rr['kode_program'].'|'.$rr['kode_kegiatan'].'|'.$rr['kode_sub_kegiatan'];
            $real_map[$key] = $rr;
        }
        $stmt_real->close();

        // 4. Data rincian untuk fisik tertimbang
        $rincian_per_subkeg = [];
        $akum_vol_parts = [];
        for ($i = 0; $i < $bulan_ini; $i++) {
            $bkey = $bulan_keys[$i];
            $akum_vol_parts[] = "CASE WHEN r.status_$bkey IN ('divalidasi','dikunci') THEN COALESCE(r.volume_$bkey, 0) ELSE 0 END";
        }
        $akum_vol_expr = implode(' + ', $akum_vol_parts);
        $q_rincian = "
            SELECT a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan,
                   a.total_pagu, a.total_volume,
                   ($akum_vol_expr) AS real_vol
            FROM anggaran_detail a
            LEFT JOIN realisasi_detail r ON a.id = r.anggaran_detail_id
                                       AND r.opd_id = a.opd_id
                                       AND r.tahun = a.tahun
            WHERE a.opd_id = ?
              AND a.tahun = ?
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
        ";
        $stmt_rincian = $conn->prepare($q_rincian);
        if (!$stmt_rincian) continue;
        $stmt_rincian->bind_param('ii', $oid, $tahun);
        $stmt_rincian->execute();
        $res_rincian = $stmt_rincian->get_result();
        while ($rinc = $res_rincian->fetch_assoc()) {
            $key = $rinc['kode_program'].'|'.$rinc['kode_kegiatan'].'|'.$rinc['kode_sub_kegiatan'];
            $rincian_per_subkeg[$key][] = [
                'pagu'     => $rinc['total_pagu'],
                'vol'      => $rinc['total_volume'],
                'real_vol' => $rinc['real_vol']
            ];
        }
        $stmt_rincian->close();

        // Gabungkan realisasi ke subkeg_list
        foreach ($subkeg_list as &$sk) {
            $key = $sk['kode_program'].'|'.$sk['kode_kegiatan'].'|'.$sk['kode_sub_kegiatan'];
            $sk['awal_pagu'] = $awal_pagu_map[$key] ?? 0;
            if (isset($real_map[$key])) {
                foreach ($bulan_keys as $b) {
                    $sk['rp_'.$b] = $real_map[$key]['rp_'.$b] ?? 0;
                    $sk['rv_'.$b] = $real_map[$key]['rv_'.$b] ?? 0;
                }
            } else {
                foreach ($bulan_keys as $b) {
                    $sk['rp_'.$b] = 0;
                    $sk['rv_'.$b] = 0;
                }
            }
        }
        unset($sk);

        // Hierarki
        $programs = [];
        foreach ($subkeg_list as $sk) {
            $prog = $sk['kode_program'];
            $keg  = $sk['kode_kegiatan'];
            $sub  = $sk['kode_sub_kegiatan'];
            if (!isset($programs[$prog])) $programs[$prog] = ['kegiatan' => []];
            if (!isset($programs[$prog]['kegiatan'][$keg])) $programs[$prog]['kegiatan'][$keg] = ['sub_kegiatan' => []];
            $programs[$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub] = $sk;
        }

        foreach ($programs as $prog => $pdata) {
            $all_codes_global[] = $prog;
            foreach ($pdata['kegiatan'] as $keg => $kdata) {
                $all_codes_global[] = $keg;
                foreach ($kdata['sub_kegiatan'] as $sub => $sdata) $all_codes_global[] = $sub;
            }
        }

        // Agregasi subkeg (fisik tertimbang)
        $subkeg_agg = [];
        foreach ($subkeg_list as $sk) {
            $key = $sk['kode_program'].'|'.$sk['kode_kegiatan'].'|'.$sk['kode_sub_kegiatan'];
            $awal = $sk['awal_pagu'] ?? 0;
            $pergeseran = $sk['total_pagu'];
            $volume = $sk['total_volume'];
            $pl = ($bulan_ini > 1) ? ($sk['pagu_'.$bulan_keys[$bulan_ini-2]] ?? 0) : 0;
            $pi = $sk['pagu_'.$bulan_keys[$bulan_ini-1]] ?? 0;
            $ps = sumBulanan($sk, 'pagu_', $bulan_keys, $bulan_ini);
            $rl = ($bulan_ini > 1) ? ($sk['rp_'.$bulan_keys[$bulan_ini-2]] ?? 0) : 0;
            $ri = $sk['rp_'.$bulan_keys[$bulan_ini-1]] ?? 0;
            $rs = sumBulanan($sk, 'rp_', $bulan_keys, $bulan_ini);
            $rvsd = sumBulanan($sk, 'rv_', $bulan_keys, $bulan_ini);

            $total_pagu_subkeg = $sk['total_pagu'];
            $tertimbang = 0;
            $rincian_items = $rincian_per_subkeg[$key] ?? [];
            if ($total_pagu_subkeg > 0 && !empty($rincian_items)) {
                foreach ($rincian_items as $rinc) {
                    $fisik_rincian = ($rinc['vol'] > 0) ? ($rinc['real_vol'] / $rinc['vol']) * 100 : 0;
                    $bobot = ($rinc['pagu'] / $total_pagu_subkeg) * 100;
                    $tertimbang += $fisik_rincian * $bobot / 100;
                }
            }
            $fisik = round($tertimbang, 2);

            $pagu_efektif = ($pergeseran > 0) ? $pergeseran : $awal;
            $keu_total = ($pagu_efektif > 0) ? round(($rs / $pagu_efektif) * 100, 2) : 0;
            $sisa = $pagu_efektif - $rs;
            $spj  = $ps - $rs;
            $deviasi = ($pagu_efektif > 0) ? round(($spj / $pagu_efektif) * 100, 2) : 0;

            $subkeg_agg[$key] = compact('awal','pergeseran','volume','pl','pi','ps','rl','ri','rs',
                'rvsd','fisik','pagu_efektif','keu_total','sisa','spj','deviasi');
        }

        // Kegiatan
        $keg_agg = [];
        foreach ($programs as $prog_code => $prog_data) {
            foreach ($prog_data['kegiatan'] as $keg_code => $keg_data) {
                $keg_total_pagu = $keg_total_vol = $keg_total_real_vol = 0;
                $keg_total_ps = $keg_total_rs = $keg_awal = 0;
                $keg_pl = $keg_pi = $keg_ps = $keg_rl = $keg_ri = $keg_rs = $keg_rvsd = 0;
                $arr_fisik_sub = [];

                foreach ($keg_data['sub_kegiatan'] as $sub_code => $sub_data) {
                    $key = $prog_code.'|'.$keg_code.'|'.$sub_code;
                    $s = $subkeg_agg[$key];
                    $keg_total_pagu += $s['pergeseran'];
                    $keg_total_vol += $s['volume'];
                    $keg_total_real_vol += $s['rvsd'];
                    $keg_total_ps += $s['ps'];
                    $keg_total_rs += $s['rs'];
                    $keg_awal += $s['awal'];
                    $keg_pl += $s['pl']; $keg_pi += $s['pi']; $keg_ps += $s['ps'];
                    $keg_rl += $s['rl']; $keg_ri += $s['ri']; $keg_rs += $s['rs'];
                    $keg_rvsd += $s['rvsd'];
                    $arr_fisik_sub[] = $s['fisik'];
                }

                $keg_fisik = count($arr_fisik_sub) > 0 ? round(array_sum($arr_fisik_sub) / count($arr_fisik_sub), 2) : 0;

                $keg_pagu_efektif = ($keg_total_pagu > 0) ? $keg_total_pagu : $keg_awal;
                $keg_keu_total = ($keg_pagu_efektif > 0) ? round(($keg_total_rs / $keg_pagu_efektif) * 100, 2) : 0;
                $keg_sisa = $keg_pagu_efektif - $keg_total_rs;
                $keg_spj  = $keg_total_ps - $keg_total_rs;
                $keg_deviasi = ($keg_pagu_efektif > 0) ? round(($keg_spj / $keg_pagu_efektif) * 100, 2) : 0;

                $keg_agg[$keg_code] = [
                    'awal' => $keg_awal, 'pergeseran' => $keg_total_pagu, 'volume' => $keg_total_vol,
                    'pl' => $keg_pl, 'pi' => $keg_pi, 'ps' => $keg_ps,
                    'rl' => $keg_rl, 'ri' => $keg_ri, 'rs' => $keg_rs,
                    'rvsd' => $keg_rvsd, 'fisik' => $keg_fisik, 'keu_total' => $keg_keu_total,
                    'sisa' => $keg_sisa, 'spj' => $keg_spj, 'deviasi' => $keg_deviasi, 'prog_code' => $prog_code
                ];
            }
        }

        // Program
        $prog_agg = [];
        foreach ($programs as $prog_code => $prog_data) {
            $prog_total_pagu = $prog_total_vol = $prog_total_real_vol = 0;
            $prog_total_ps = $prog_total_rs = $prog_awal = 0;
            $prog_pl = $prog_pi = $prog_ps = $prog_rl = $prog_ri = $prog_rs = $prog_rvsd = 0;
            $arr_fisik_keg = [];

            foreach ($prog_data['kegiatan'] as $keg_code => $keg_data) {
                $k = $keg_agg[$keg_code];
                $prog_total_pagu += $k['pergeseran'];
                $prog_total_vol += $k['volume'];
                $prog_total_real_vol += $k['rvsd'];
                $prog_total_ps += $k['ps'];
                $prog_total_rs += $k['rs'];
                $prog_awal += $k['awal'];
                $prog_pl += $k['pl']; $prog_pi += $k['pi']; $prog_ps += $k['ps'];
                $prog_rl += $k['rl']; $prog_ri += $k['ri']; $prog_rs += $k['rs'];
                $prog_rvsd += $k['rvsd'];
                $arr_fisik_keg[] = $k['fisik'];
            }

            $prog_fisik = count($arr_fisik_keg) > 0 ? round(array_sum($arr_fisik_keg) / count($arr_fisik_keg), 2) : 0;

            $prog_pagu_efektif = ($prog_total_pagu > 0) ? $prog_total_pagu : $prog_awal;
            $prog_keu_total = ($prog_pagu_efektif > 0) ? round(($prog_total_rs / $prog_pagu_efektif) * 100, 2) : 0;
            $prog_sisa = $prog_pagu_efektif - $prog_total_rs;
            $prog_spj  = $prog_total_ps - $prog_total_rs;
            $prog_deviasi = ($prog_pagu_efektif > 0) ? round(($prog_spj / $prog_pagu_efektif) * 100, 2) : 0;

            $prog_agg[$prog_code] = [
                'awal' => $prog_awal, 'pergeseran' => $prog_total_pagu, 'volume' => $prog_total_vol,
                'pl' => $prog_pl, 'pi' => $prog_pi, 'ps' => $prog_ps,
                'rl' => $prog_rl, 'ri' => $prog_ri, 'rs' => $prog_rs,
                'rvsd' => $prog_rvsd, 'fisik' => $prog_fisik, 'keu_total' => $prog_keu_total,
                'sisa' => $prog_sisa, 'spj' => $prog_spj, 'deviasi' => $prog_deviasi
            ];
        }

        $opd_sections[$oid] = [
            'nama'       => $nama_opd_section,
            'programs'   => $programs,
            'prog_agg'   => $prog_agg,
            'keg_agg'    => $keg_agg,
            'subkeg_agg' => $subkeg_agg,
        ];
    }

    // Nama map global
    $all_codes_global = array_unique($all_codes_global);
    if (!empty($all_codes_global)) {
        $query_codes = [];
        foreach ($all_codes_global as $code) {
            $query_codes[] = $code;
            if (preg_match('/^(\d+\.\d+)\.(.+)$/', $code, $m)) $query_codes[] = 'X.XX.'.$m[2];
        }
        $query_codes = array_unique($query_codes);
        $placeholders = implode(',', array_fill(0, count($query_codes), '?'));
        $types = str_repeat('s', count($query_codes));
        $stmt = $conn->prepare("SELECT kode, nama FROM master_hierarki WHERE kode IN ($placeholders)");
        if ($stmt) {
            $stmt->bind_param($types, ...$query_codes);
            $stmt->execute();
            $res = $stmt->get_result();
            $all_names = [];
            while ($nm = $res->fetch_assoc()) $all_names[$nm['kode']] = $nm['nama'];
            $stmt->close();
            foreach ($all_codes_global as $code) {
                if (isset($all_names[$code])) $nama_map[$code] = $all_names[$code];
                else {
                    $placeholder = preg_replace('/^\d+\.\d+\./', 'X.XX.', $code);
                    $nama_map[$code] = $all_names[$placeholder] ?? '(tanpa uraian)';
                }
            }
        }
    }

    $global_max_versi = !empty($all_max_versi) ? max($all_max_versi) : 1;
    $pergeseran_label = ($global_max_versi > 1) ? 'Pergeseran ' . roman($global_max_versi - 1) : '';
}

// ===================== EXPORT EXCEL =====================
if ($show_table && $export === 'excel') {
    // Bersihkan buffer sebelum output
    if (ob_get_level()) ob_end_clean();

    try {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        if (empty($all_ids)) {
            throw new Exception('Tidak ada data OPD untuk diekspor.');
        }

        foreach ($all_ids as $oid) {
            $section = $opd_sections[$oid] ?? null;
            if (!$section) continue;
            $nama_opd = $section['nama'];
            $sheetTitle = substr($nama_opd, 0, 31);
            $sheet = new Worksheet($spreadsheet, $sheetTitle);
            $spreadsheet->addSheet($sheet);

            $programs = $section['programs'];
            $prog_agg = $section['prog_agg'];
            $keg_agg = $section['keg_agg'];
            $subkeg_agg = $section['subkeg_agg'];

            // Judul
            $sheet->setCellValue('A1', 'Laporan APBD');
            $sheet->mergeCells('A1:O1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->setCellValue('A2', $bulan_indonesia[$bulan_ini-1].' '.$tahun.' | OPD: '.$nama_opd);
            $sheet->mergeCells('A2:O2');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Header (tanpa kolom Ket)
            $sheet->mergeCells('A4:A5'); $sheet->setCellValue('A4', 'Kode Rekening');
            $sheet->mergeCells('B4:B5'); $sheet->setCellValue('B4', 'Program / Kegiatan');
            $sheet->mergeCells('C4:D4'); $sheet->setCellValue('C4', 'Tahun Anggaran');
            $sheet->setCellValue('C5', 'Awal');
            $sheet->setCellValue('D5', $pergeseran_label);
            $sheet->mergeCells('E4:G4'); $sheet->setCellValue('E4', 'Anggaran s.d. '.$bulan_indonesia[$bulan_ini-1]);
            $sheet->setCellValue('E5', 'Bln Lalu'); $sheet->setCellValue('F5', 'Bln Ini'); $sheet->setCellValue('G5', '🔰 s.d.');
            $sheet->mergeCells('H4:L4'); $sheet->setCellValue('H4', 'Realisasi s.d. '.$bulan_indonesia[$bulan_ini-1]);
            $sheet->setCellValue('H5', 'Bln Lalu'); $sheet->setCellValue('I5', 'Bln Ini'); $sheet->setCellValue('J5', '🔰 s.d.');
            $sheet->setCellValue('K5', 'Keu Total (%)'); $sheet->setCellValue('L5', 'Fisik (%)');
            $sheet->mergeCells('M4:M5'); $sheet->setCellValue('M4', 'Sisa Anggaran');
            $sheet->mergeCells('N4:N5'); $sheet->setCellValue('N4', 'Harus Di-SPJ-kan');
            $sheet->mergeCells('O4:O5'); $sheet->setCellValue('O4', 'Deviasi (%)');

            $headerStyle = [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEEEEE']]
            ];
            $sheet->getStyle('A4:O5')->applyFromArray($headerStyle);

            $fmtPersen = function($v) { return number_format($v, 2, ',', '.'); };
            $row = 6;
            $gt_awal = $gt_pergeseran = $gt_pl = $gt_pi = $gt_ps = $gt_rl = $gt_ri = $gt_rs = $gt_sisa = $gt_spj = 0;
            $gt_vol = $gt_rvsd = 0;
            $opd_fisik_list = [];

            foreach ($programs as $kode_prog => $prog_data) {
                $p = $prog_agg[$kode_prog];
                $sheet->setCellValue('A'.$row, $kode_prog);
                $sheet->setCellValue('B'.$row, getNama($kode_prog, $nama_map));
                $sheet->setCellValue('C'.$row, $p['awal']);
                $sheet->setCellValue('D'.$row, $p['pergeseran']);
                $sheet->setCellValue('E'.$row, $p['pl']);
                $sheet->setCellValue('F'.$row, $p['pi']);
                $sheet->setCellValue('G'.$row, $p['ps']);
                $sheet->setCellValue('H'.$row, $p['rl']);
                $sheet->setCellValue('I'.$row, $p['ri']);
                $sheet->setCellValue('J'.$row, $p['rs']);
                $sheet->setCellValue('K'.$row, $fmtPersen($p['keu_total']));
                $sheet->setCellValue('L'.$row, $fmtPersen($p['fisik']));
                $sheet->setCellValue('M'.$row, $p['sisa']);
                $sheet->setCellValue('N'.$row, $p['spj']);
                $sheet->setCellValue('O'.$row, $fmtPersen($p['deviasi']));
                $sheet->getStyle('O'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(getDeviasiColor($p['deviasi']));
                if (in_array(getDeviasiColor($p['deviasi']), ['FF28A745','FFDC3545'])) $sheet->getStyle('O'.$row)->getFont()->getColor()->setARGB('FFFFFFFF');
                $sheet->getStyle('A'.$row.':O'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCE5FF');
                $sheet->getStyle('A'.$row.':B'.$row)->getFont()->setBold(true);
                $row++;

                $gt_awal += $p['awal']; $gt_pergeseran += $p['pergeseran'];
                $gt_pl += $p['pl']; $gt_pi += $p['pi']; $gt_ps += $p['ps'];
                $gt_rl += $p['rl']; $gt_ri += $p['ri']; $gt_rs += $p['rs'];
                $gt_sisa += $p['sisa']; $gt_spj += $p['spj'];
                $gt_vol += $p['volume']; $gt_rvsd += $p['rvsd'];
                $opd_fisik_list[] = $p['fisik'];

                foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data) {
                    $k = $keg_agg[$kode_keg];
                    $sheet->setCellValue('A'.$row, $kode_keg);
                    $sheet->setCellValue('B'.$row, getNama($kode_keg, $nama_map));
                    $sheet->setCellValue('C'.$row, $k['awal']);
                    $sheet->setCellValue('D'.$row, $k['pergeseran']);
                    $sheet->setCellValue('E'.$row, $k['pl']);
                    $sheet->setCellValue('F'.$row, $k['pi']);
                    $sheet->setCellValue('G'.$row, $k['ps']);
                    $sheet->setCellValue('H'.$row, $k['rl']);
                    $sheet->setCellValue('I'.$row, $k['ri']);
                    $sheet->setCellValue('J'.$row, $k['rs']);
                    $sheet->setCellValue('K'.$row, $fmtPersen($k['keu_total']));
                    $sheet->setCellValue('L'.$row, $fmtPersen($k['fisik']));
                    $sheet->setCellValue('M'.$row, $k['sisa']);
                    $sheet->setCellValue('N'.$row, $k['spj']);
                    $sheet->setCellValue('O'.$row, $fmtPersen($k['deviasi']));
                    $sheet->getStyle('O'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(getDeviasiColor($k['deviasi']));
                    if (in_array(getDeviasiColor($k['deviasi']), ['FF28A745','FFDC3545'])) $sheet->getStyle('O'.$row)->getFont()->getColor()->setARGB('FFFFFFFF');
                    $sheet->getStyle('A'.$row.':O'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E3E5');
                    $row++;

                    foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
                        $key = $kode_prog.'|'.$kode_keg.'|'.$kode_sub;
                        $s = $subkeg_agg[$key];
                        $sheet->setCellValue('A'.$row, $kode_sub);
                        $sheet->setCellValue('B'.$row, getNama($kode_sub, $nama_map));
                        $sheet->setCellValue('C'.$row, $s['awal']);
                        $sheet->setCellValue('D'.$row, $s['pergeseran']);
                        $sheet->setCellValue('E'.$row, $s['pl']);
                        $sheet->setCellValue('F'.$row, $s['pi']);
                        $sheet->setCellValue('G'.$row, $s['ps']);
                        $sheet->setCellValue('H'.$row, $s['rl']);
                        $sheet->setCellValue('I'.$row, $s['ri']);
                        $sheet->setCellValue('J'.$row, $s['rs']);
                        $sheet->setCellValue('K'.$row, $fmtPersen($s['keu_total']));
                        $sheet->setCellValue('L'.$row, $fmtPersen($s['fisik']));
                        $sheet->setCellValue('M'.$row, $s['sisa']);
                        $sheet->setCellValue('N'.$row, $s['spj']);
                        $sheet->setCellValue('O'.$row, $fmtPersen($s['deviasi']));
                        $sheet->getStyle('O'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(getDeviasiColor($s['deviasi']));
                        if (in_array(getDeviasiColor($s['deviasi']), ['FF28A745','FFDC3545'])) $sheet->getStyle('O'.$row)->getFont()->getColor()->setARGB('FFFFFFFF');
                        $sheet->getStyle('A'.$row.':O'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
                        $row++;
                    }
                }
            }

            // Total OPD
            $gt_fisik = count($opd_fisik_list) > 0 ? round(array_sum($opd_fisik_list) / count($opd_fisik_list), 2) : 0;
            $gt_pagu_efektif = ($gt_pergeseran > 0) ? $gt_pergeseran : $gt_awal;
            $gt_keu_total = ($gt_pagu_efektif > 0) ? round(($gt_rs / $gt_pagu_efektif) * 100, 2) : 0;
            $gt_deviasi_total = ($gt_pagu_efektif > 0) ? round(($gt_spj / $gt_pagu_efektif) * 100, 2) : 0;

            $sheet->setCellValue('A'.$row, 'TOTAL');
            $sheet->mergeCells('A'.$row.':B'.$row);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setCellValue('C'.$row, $gt_awal);
            $sheet->setCellValue('D'.$row, $gt_pergeseran);
            $sheet->setCellValue('E'.$row, $gt_pl);
            $sheet->setCellValue('F'.$row, $gt_pi);
            $sheet->setCellValue('G'.$row, $gt_ps);
            $sheet->setCellValue('H'.$row, $gt_rl);
            $sheet->setCellValue('I'.$row, $gt_ri);
            $sheet->setCellValue('J'.$row, $gt_rs);
            $sheet->setCellValue('K'.$row, $fmtPersen($gt_keu_total));
            $sheet->setCellValue('L'.$row, $fmtPersen($gt_fisik));
            $sheet->setCellValue('M'.$row, $gt_sisa);
            $sheet->setCellValue('N'.$row, $gt_spj);
            $sheet->setCellValue('O'.$row, $fmtPersen($gt_deviasi_total));
            $sheet->getStyle('O'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(getDeviasiColor($gt_deviasi_total));
            if (in_array(getDeviasiColor($gt_deviasi_total), ['FF28A745','FFDC3545'])) $sheet->getStyle('O'.$row)->getFont()->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle('A'.$row.':O'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6D8DB');
            $sheet->getStyle('A'.$row.':O'.$row)->getFont()->setBold(true);

            foreach (['C','D','E','F','G','H','I','J','M','N'] as $col) {
                $sheet->getStyle($col.'6:'.$col.$row)->getNumberFormat()->setFormatCode('#,##0');
            }
            $sheet->getStyle('A4:O'.$row)->applyFromArray(['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]]);
            foreach (range('A','O') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

            // TTD Kepala OPD dengan validasi path
            $kepala = getDataKepalaOpd($conn, $oid);
            $row += 2;
            $tgl = date('d') . ' ' . $bulan_indonesia[date('n')-1] . ' ' . date('Y');
            $sheet->mergeCells('O'.$row.':O'.($row+4));
            $ttdText = "Kendari, {$tgl}\nKEPALA " . strtoupper($kepala['nama_opd']) . "\n\n\n(" . $kepala['nama_kepala'] . ")";
            $sheet->setCellValue('O'.$row, $ttdText);
            $sheet->getStyle('O'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('O'.$row)->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
            $sheet->getStyle('O'.$row)->getAlignment()->setWrapText(true);
            if (isValidTtdPath($kepala['ttd_path'])) {
                $full_path = __DIR__ . '/../../' . $kepala['ttd_path'];
                if (file_exists($full_path)) {
                    $drawing = new Drawing();
                    $drawing->setName('TTD');
                    $drawing->setDescription('Tanda Tangan Kepala OPD');
                    $drawing->setPath($full_path);
                    $drawing->setHeight(40);
                    $drawing->setCoordinates('O' . ($row + 2));
                    $drawing->setOffsetX(5);
                    $drawing->setWorksheet($sheet);
                }
            }
        }

        // Output Excel
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $nama_opd_file = ($opd_id > 0) ? $dinas_nama : 'Semua_OPD';
        $tanggal_realtime = date('d') . '_' . $bulan_indonesia[date('n')-1] . '_' . date('Y');
        $nama_file = 'laporan_apbd_' . str_replace(' ', '_', $nama_opd_file) . '_' . $tanggal_realtime . '.xlsx';
        header('Content-Disposition: attachment; filename="' . $nama_file . '"');
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;

    } catch (Exception $e) {
        // Jika terjadi error, tampilkan pesan error (di lingkungan development bisa log)
        die('Export Excel gagal: ' . $e->getMessage());
    }
}

// ===================== EXPORT PDF =====================
if ($show_table && $export === 'pdf') {
    if (ob_get_level()) ob_end_clean();

    try {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 8px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #000; padding: 2px 4px; text-align: left; }
            th { background-color: #eee; }
            .text-end { text-align: right; } .text-center { text-align: center; }
            .fw-bold { font-weight: bold; }
            .table-primary { background-color: #cce5ff; }
            .table-secondary { background-color: #e2e3e5; }
            .grand-total { background-color: #d6d8db; font-weight: bold; }
            .dev-hijau { background-color: #28a745; color: #fff; }
            .dev-kuning { background-color: #ffc107; color: #000; }
            .dev-orange { background-color: #fd7e14; color: #fff; }
            .dev-merah { background-color: #dc3545; color: #fff; }
            h3 { margin: 5px 0; text-align: center; }
            .subtitle { text-align: center; margin-bottom: 10px; }
            .ttd-wrapper { margin-top: 30px; text-align: right; }
            .ttd-box { display: inline-block; text-align: left; font-size: 9px; }
            .ttd-img { height: 40px; margin-bottom: 5px; display: block; }
            .page-break { page-break-before: always; }
        </style></head><body>';

        if (empty($all_ids)) {
            throw new Exception('Tidak ada data OPD untuk diekspor.');
        }

        $first = true;
        $overall_fisik_list = [];
        foreach ($all_ids as $oid) {
            $section = $opd_sections[$oid] ?? null;
            if (!$section) continue;
            if (!$first) $html .= '<div class="page-break"></div>';
            $first = false;

            $nama_opd = $section['nama'];
            $programs = $section['programs'];
            $prog_agg = $section['prog_agg'];
            $keg_agg = $section['keg_agg'];
            $subkeg_agg = $section['subkeg_agg'];

            $html .= '<h3>Laporan APBD</h3>';
            $html .= '<div class="subtitle">'.$bulan_indonesia[$bulan_ini-1].' '.$tahun.' | OPD: '.htmlspecialchars($nama_opd).'</div>';
            $html .= '<table><thead><tr>
                <th rowspan="2">Kode</th><th rowspan="2">Uraian</th>
                <th colspan="2">Tahun Anggaran</th>
                <th colspan="3">Anggaran s.d. '.$bulan_indonesia[$bulan_ini-1].'</th>
                <th colspan="5">Realisasi s.d. '.$bulan_indonesia[$bulan_ini-1].'</th>
                <th rowspan="2">Sisa</th><th rowspan="2">SPJ</th><th rowspan="2">Deviasi (%)</th>
            </tr><tr>
                <th>Awal</th><th>'.$pergeseran_label.'</th>
                <th>Bln Lalu</th><th>Bln Ini</th><th>s.d.</th>
                <th>Bln Lalu</th><th>Bln Ini</th><th>s.d.</th><th>Keu Total (%)</th><th>Fisik (%)</th>
            </tr></thead><tbody>';

            $fmtPersen = function($v) { return number_format($v,2,',','.'); };
            $gt_awal = $gt_pergeseran = $gt_pl = $gt_pi = $gt_ps = $gt_rl = $gt_ri = $gt_rs = $gt_sisa = $gt_spj = 0;
            $opd_fisik_list = [];
            foreach ($programs as $kode_prog => $prog_data) {
                $p = $prog_agg[$kode_prog];
                $html .= '<tr class="table-primary fw-bold">
                    <td>'.htmlspecialchars($kode_prog).'</td><td>'.htmlspecialchars(getNama($kode_prog, $nama_map)).'</td>
                    <td class="text-end">'.number_format($p['awal'],0,',','.').'</td>
                    <td class="text-end">'.number_format($p['pergeseran'],0,',','.').'</td>
                    <td class="text-end">'.number_format($p['pl'],0,',','.').'</td>
                    <td class="text-end">'.number_format($p['pi'],0,',','.').'</td>
                    <td class="text-end">'.number_format($p['ps'],0,',','.').'</td>
                    <td class="text-end">'.number_format($p['rl'],0,',','.').'</td>
                    <td class="text-end">'.number_format($p['ri'],0,',','.').'</td>
                    <td class="text-end">'.number_format($p['rs'],0,',','.').'</td>
                    <td class="text-end">'.$fmtPersen($p['keu_total']).'%</td>
                    <td class="text-end">'.$fmtPersen($p['fisik']).'%</td>
                    <td class="text-end">'.number_format($p['sisa'],0,',','.').'</td>
                    <td class="text-end">'.number_format($p['spj'],0,',','.').'</td>
                    <td class="text-end '.getDeviasiClass($p['deviasi']).'">'.$fmtPersen($p['deviasi']).'%</td></tr>';
                $gt_awal += $p['awal']; $gt_pergeseran += $p['pergeseran'];
                $gt_pl += $p['pl']; $gt_pi += $p['pi']; $gt_ps += $p['ps'];
                $gt_rl += $p['rl']; $gt_ri += $p['ri']; $gt_rs += $p['rs'];
                $gt_sisa += $p['sisa']; $gt_spj += $p['spj'];
                $opd_fisik_list[] = $p['fisik'];

                foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data) {
                    $k = $keg_agg[$kode_keg];
                    $html .= '<tr class="table-secondary fw-bold">
                        <td>'.htmlspecialchars($kode_keg).'</td><td>'.htmlspecialchars(getNama($kode_keg, $nama_map)).'</td>
                        <td class="text-end">'.number_format($k['awal'],0,',','.').'</td>
                        <td class="text-end">'.number_format($k['pergeseran'],0,',','.').'</td>
                        <td class="text-end">'.number_format($k['pl'],0,',','.').'</td>
                        <td class="text-end">'.number_format($k['pi'],0,',','.').'</td>
                        <td class="text-end">'.number_format($k['ps'],0,',','.').'</td>
                        <td class="text-end">'.number_format($k['rl'],0,',','.').'</td>
                        <td class="text-end">'.number_format($k['ri'],0,',','.').'</td>
                        <td class="text-end">'.number_format($k['rs'],0,',','.').'</td>
                        <td class="text-end">'.$fmtPersen($k['keu_total']).'%</td>
                        <td class="text-end">'.$fmtPersen($k['fisik']).'%</td>
                        <td class="text-end">'.number_format($k['sisa'],0,',','.').'</td>
                        <td class="text-end">'.number_format($k['spj'],0,',','.').'</td>
                        <td class="text-end '.getDeviasiClass($k['deviasi']).'">'.$fmtPersen($k['deviasi']).'%</td></tr>';

                    foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
                        $key = $kode_prog.'|'.$kode_keg.'|'.$kode_sub;
                        $s = $subkeg_agg[$key];
                        $html .= '<tr>
                            <td>'.htmlspecialchars($kode_sub).'</td><td>'.htmlspecialchars(getNama($kode_sub, $nama_map)).'</td>
                            <td class="text-end">'.number_format($s['awal'],0,',','.').'</td>
                            <td class="text-end">'.number_format($s['pergeseran'],0,',','.').'</td>
                            <td class="text-end">'.number_format($s['pl'],0,',','.').'</td>
                            <td class="text-end">'.number_format($s['pi'],0,',','.').'</td>
                            <td class="text-end">'.number_format($s['ps'],0,',','.').'</td>
                            <td class="text-end">'.number_format($s['rl'],0,',','.').'</td>
                            <td class="text-end">'.number_format($s['ri'],0,',','.').'</td>
                            <td class="text-end">'.number_format($s['rs'],0,',','.').'</td>
                            <td class="text-end">'.$fmtPersen($s['keu_total']).'%</td>
                            <td class="text-end">'.$fmtPersen($s['fisik']).'%</td>
                            <td class="text-end">'.number_format($s['sisa'],0,',','.').'</td>
                            <td class="text-end">'.number_format($s['spj'],0,',','.').'</td>
                            <td class="text-end '.getDeviasiClass($s['deviasi']).'">'.$fmtPersen($s['deviasi']).'%</td></tr>';
                    }
                }
            }

            $gt_fisik = count($opd_fisik_list) > 0 ? round(array_sum($opd_fisik_list) / count($opd_fisik_list), 2) : 0;
            $gt_pagu_efektif = ($gt_pergeseran > 0) ? $gt_pergeseran : $gt_awal;
            $gt_keu_total = ($gt_pagu_efektif > 0) ? round(($gt_rs / $gt_pagu_efektif) * 100, 2) : 0;
            $gt_deviasi_total = ($gt_pagu_efektif > 0) ? round(($gt_spj / $gt_pagu_efektif) * 100, 2) : 0;
            $html .= '<tr class="grand-total">
                <td colspan="2" class="text-center">TOTAL</td>
                <td class="text-end">'.number_format($gt_awal,0,',','.').'</td>
                <td class="text-end">'.number_format($gt_pergeseran,0,',','.').'</td>
                <td class="text-end">'.number_format($gt_pl,0,',','.').'</td>
                <td class="text-end">'.number_format($gt_pi,0,',','.').'</td>
                <td class="text-end">'.number_format($gt_ps,0,',','.').'</td>
                <td class="text-end">'.number_format($gt_rl,0,',','.').'</td>
                <td class="text-end">'.number_format($gt_ri,0,',','.').'</td>
                <td class="text-end">'.number_format($gt_rs,0,',','.').'</td>
                <td class="text-end">'.$fmtPersen($gt_keu_total).'%</td>
                <td class="text-end">'.$fmtPersen($gt_fisik).'%</td>
                <td class="text-end">'.number_format($gt_sisa,0,',','.').'</td>
                <td class="text-end">'.number_format($gt_spj,0,',','.').'</td>
                <td class="text-end '.getDeviasiClass($gt_deviasi_total).'">'.$fmtPersen($gt_deviasi_total).'%</td></tr>';
            $html .= '</tbody></table>';

            $overall_fisik_list = array_merge($overall_fisik_list, $opd_fisik_list);

            // TTD
            $kepala = getDataKepalaOpd($conn, $oid);
            $tgl = date('d') . ' ' . $bulan_indonesia[date('n')-1] . ' ' . date('Y');
            $html .= '<div class="ttd-wrapper"><div class="ttd-box">';
            $html .= '<div>Kendari, '.$tgl.'</div><div>KEPALA '.strtoupper(htmlspecialchars($kepala['nama_opd'])).'</div>';
            if (isValidTtdPath($kepala['ttd_path'])) {
                $full_path = __DIR__ . '/../../' . $kepala['ttd_path'];
                if (file_exists($full_path)) {
                    $img = file_get_contents($full_path);
                    if ($img !== false) {
                        $mime = function_exists('mime_content_type') ? mime_content_type($full_path) : 'image/png';
                        $html .= '<div><img class="ttd-img" src="data:'.$mime.';base64,'.base64_encode($img).'"></div>';
                    } else {
                        $html .= '<div style="margin-top:30px;">&nbsp;</div>';
                    }
                } else {
                    $html .= '<div style="margin-top:30px;">&nbsp;</div>';
                }
            } else {
                $html .= '<div style="margin-top:30px;">&nbsp;</div>';
            }
            $html .= '<div>('.htmlspecialchars($kepala['nama_kepala']).')</div>';
            $html .= '</div></div>';
        }
        $html .= '</body></html>';

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $nama_opd_file = ($opd_id > 0) ? $dinas_nama : 'Semua_OPD';
        $tanggal_realtime = date('d') . '_' . $bulan_indonesia[date('n')-1] . '_' . date('Y');
        $nama_file = 'laporan_apbd_' . str_replace(' ', '_', $nama_opd_file) . '_' . $tanggal_realtime . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nama_file . '"');
        $dompdf->stream($nama_file, ['Attachment' => 1]);
        exit;

    } catch (Exception $e) {
        die('Export PDF gagal: ' . $e->getMessage());
    }
}

// ===================== TAMPILAN HTML =====================
?>
<style>
    .table-uraian td, .table-uraian th {
        white-space: normal; word-break: break-word; vertical-align: middle !important;
    }
    .text-end { text-align: right; }
    .dev-hijau { background-color: #28a745 !important; color: #fff; }
    .dev-kuning { background-color: #ffc107 !important; color: #000; }
    .dev-orange { background-color: #fd7e14 !important; color: #fff; }
    .dev-merah { background-color: #dc3545 !important; color: #fff; }
    .section-header { background-color: #f0f0f0; font-weight: bold; }
    .opd-dropdown-container { position: relative; overflow: visible !important; }
    .opd-dropdown-menu {
        position: absolute; top: 100%; left: 0; z-index: 1060;
        display: none; min-width: 100%; max-height: 300px; overflow-y: auto;
        background: #fff; border: 1px solid #ced4da; border-radius: 0.25rem;
        box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); white-space: nowrap;
    }
    .opd-dropdown-item { padding: 0.375rem 0.75rem; cursor: pointer; }
    .opd-dropdown-item:hover { background-color: #f8f9fa; }
</style>

<div class="d-flex" style="max-width: 100vw; overflow-x: auto; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <h3 class="mb-3"><i class="bi bi-bar-chart-steps"></i> Laporan APBD</h3>
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">OPD</label>
                            <div class="opd-dropdown-container">
                                <input type="text" id="opd-search-input" class="form-control" placeholder="Cari OPD..." autocomplete="off"
                                       value="<?= $show_table ? htmlspecialchars($dinas_nama) : '' ?>">
                                <input type="hidden" name="opd_id" id="opd-id-hidden" value="<?= $opd_id ?>">
                                <div id="opd-dropdown" class="opd-dropdown-menu"></div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tahun</label>
                            <select name="tahun" class="form-select">
                                <?php for($y=date('Y')-2; $y<=date('Y')+1; $y++): ?>
                                    <option value="<?= $y ?>" <?= $tahun==$y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Bulan (s.d.)</label>
                            <select name="bulan" class="form-select">
                                <?php for($i=1;$i<=12;$i++): ?>
                                    <option value="<?= $i ?>" <?= $bulan_ini==$i ? 'selected' : '' ?>><?= $bulan_indonesia[$i-1] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary-custom w-100">Tampilkan</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!$show_table): ?>
                <div class="alert alert-info text-center">Silakan pilih OPD terlebih dahulu.</div>
            <?php elseif (empty($opd_sections)): ?>
                <div class="alert alert-warning text-center">Tidak ada data anggaran untuk OPD dan tahun yang dipilih.</div>
            <?php else: ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Laporan APBD - <?= $bulan_indonesia[$bulan_ini-1] ?> <?= $tahun ?> | OPD: <?= htmlspecialchars($dinas_nama) ?></span>
                        <div>
                            <a href="?opd_id=<?= $opd_id ?>&tahun=<?= $tahun ?>&bulan=<?= $bulan_ini ?>&export=excel" class="btn btn-sm btn-success me-1"><i class="bi bi-file-excel"></i> Excel</a>
                            <a href="?opd_id=<?= $opd_id ?>&tahun=<?= $tahun ?>&bulan=<?= $bulan_ini ?>&export=pdf" class="btn btn-sm btn-danger" target="_blank"><i class="bi bi-file-pdf"></i> PDF</a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0 table-uraian">
                                <thead class="table-light">
                                    <tr>
                                        <th rowspan="2" class="text-center align-middle">Kode Rekening</th>
                                        <th rowspan="2" class="text-center align-middle">Program / Kegiatan</th>
                                        <th colspan="2" class="text-center align-middle">Tahun Anggaran</th>
                                        <th colspan="3" class="text-center align-middle">Anggaran s.d. <?= $bulan_indonesia[$bulan_ini-1] ?></th>
                                        <th colspan="5" class="text-center align-middle">Realisasi s.d. <?= $bulan_indonesia[$bulan_ini-1] ?></th>
                                        <th rowspan="2" class="text-center align-middle">Sisa Anggaran</th>
                                        <th rowspan="2" class="text-center align-middle">Harus Di-SPJ-kan</th>
                                        <th rowspan="2" class="text-center align-middle">Deviasi (%)</th>
                                    </tr>
                                    <tr>
                                        <th class="text-center align-middle">Awal</th>
                                        <th class="text-center align-middle"><?= $pergeseran_label ? $pergeseran_label : 'Pagu' ?></th>
                                        <th class="text-center align-middle">Bln Lalu</th>
                                        <th class="text-center align-middle">Bln Ini</th>
                                        <th class="text-center align-middle">🔰 s.d.</th>
                                        <th class="text-center align-middle">Bln Lalu</th>
                                        <th class="text-center align-middle">Bln Ini</th>
                                        <th class="text-center align-middle">🔰 s.d.</th>
                                        <th class="text-center align-middle">Keu Total (%)</th>
                                        <th class="text-center align-middle">Fisik (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $overall_fisik_list = [];
                                    $gt_awal = $gt_pergeseran = $gt_pl = $gt_pi = $gt_ps = $gt_rl = $gt_ri = $gt_rs = $gt_sisa = $gt_spj = 0;
                                    $gt_vol = $gt_rvsd = 0;
                                    foreach ($all_ids as $oid):
                                        $section = $opd_sections[$oid] ?? null;
                                        if (!$section) continue;
                                        $is_dinas = ($oid == $opd_id);
                                        $label = $is_dinas ? 'DINAS INDUK' : 'UPTD';
                                    ?>
                                        <tr class="section-header">
                                            <td colspan="15"><strong><?= $label ?>: <?= htmlspecialchars($section['nama']) ?></strong></td>
                                        </tr>
                                        <?php
                                        $programs = $section['programs'];
                                        $prog_agg = $section['prog_agg'];
                                        $keg_agg = $section['keg_agg'];
                                        $subkeg_agg = $section['subkeg_agg'];

                                        foreach ($programs as $kode_prog => $prog_data):
                                            $p = $prog_agg[$kode_prog];
                                        ?>
                                            <tr class="table-primary fw-bold">
                                                <td><?= htmlspecialchars($kode_prog) ?></td>
                                                <td><?= htmlspecialchars(getNama($kode_prog, $nama_map)) ?></td>
                                                <td class="text-end"><?= number_format($p['awal'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($p['pergeseran'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($p['pl'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($p['pi'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($p['ps'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($p['rl'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($p['ri'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($p['rs'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($p['keu_total'],2,',','.') ?>%</td>
                                                <td class="text-end"><?= number_format($p['fisik'],2,',','.') ?>%</td>
                                                <td class="text-end"><?= number_format($p['sisa'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($p['spj'],0,',','.') ?></td>
                                                <td class="text-end <?= getDeviasiClass($p['deviasi']) ?>"><?= number_format($p['deviasi'],2,',','.') ?>%</td>
                                            </tr>
                                            <?php
                                            $gt_awal += $p['awal']; $gt_pergeseran += $p['pergeseran'];
                                            $gt_pl += $p['pl']; $gt_pi += $p['pi']; $gt_ps += $p['ps'];
                                            $gt_rl += $p['rl']; $gt_ri += $p['ri']; $gt_rs += $p['rs'];
                                            $gt_sisa += $p['sisa']; $gt_spj += $p['spj'];
                                            $gt_vol += $p['volume']; $gt_rvsd += $p['rvsd'];
                                            $overall_fisik_list[] = $p['fisik'];

                                            foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data):
                                                $k = $keg_agg[$kode_keg];
                                            ?>
                                                <tr class="table-secondary fw-bold">
                                                    <td><?= htmlspecialchars($kode_keg) ?></td>
                                                    <td><?= htmlspecialchars(getNama($kode_keg, $nama_map)) ?></td>
                                                    <td class="text-end"><?= number_format($k['awal'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($k['pergeseran'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($k['pl'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($k['pi'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($k['ps'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($k['rl'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($k['ri'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($k['rs'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($k['keu_total'],2,',','.') ?>%</td>
                                                    <td class="text-end"><?= number_format($k['fisik'],2,',','.') ?>%</td>
                                                    <td class="text-end"><?= number_format($k['sisa'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($k['spj'],0,',','.') ?></td>
                                                    <td class="text-end <?= getDeviasiClass($k['deviasi']) ?>"><?= number_format($k['deviasi'],2,',','.') ?>%</td>
                                                </tr>
                                                <?php foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data):
                                                    $key = $kode_prog.'|'.$kode_keg.'|'.$kode_sub;
                                                    $s = $subkeg_agg[$key];
                                                ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($kode_sub) ?></td>
                                                        <td><?= htmlspecialchars(getNama($kode_sub, $nama_map)) ?></td>
                                                        <td class="text-end"><?= number_format($s['awal'],0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($s['pergeseran'],0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($s['pl'],0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($s['pi'],0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($s['ps'],0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($s['rl'],0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($s['ri'],0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($s['rs'],0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($s['keu_total'],2,',','.') ?>%</td>
                                                        <td class="text-end"><?= number_format($s['fisik'],2,',','.') ?>%</td>
                                                        <td class="text-end"><?= number_format($s['sisa'],0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($s['spj'],0,',','.') ?></td>
                                                        <td class="text-end <?= getDeviasiClass($s['deviasi']) ?>"><?= number_format($s['deviasi'],2,',','.') ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <!-- Grand Total -->
                                    <?php
                                    $gt_fisik_total = count($overall_fisik_list) > 0 ? round(array_sum($overall_fisik_list) / count($overall_fisik_list), 2) : 0;
                                    $gt_pagu_efektif = ($gt_pergeseran > 0) ? $gt_pergeseran : $gt_awal;
                                    $gt_keu_total = ($gt_pagu_efektif > 0) ? round(($gt_rs / $gt_pagu_efektif) * 100, 2) : 0;
                                    $gt_deviasi_total = ($gt_pagu_efektif > 0) ? round(($gt_spj / $gt_pagu_efektif) * 100, 2) : 0;
                                    ?>
                                    <tr class="table-dark fw-bold">
                                        <td colspan="2" class="text-center">TOTAL KESELURUHAN</td>
                                        <td class="text-end"><?= number_format($gt_awal,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($gt_pergeseran,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($gt_pl,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($gt_pi,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($gt_ps,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($gt_rl,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($gt_ri,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($gt_rs,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($gt_keu_total,2,',','.') ?>%</td>
                                        <td class="text-end"><?= number_format($gt_fisik_total,2,',','.') ?>%</td>
                                        <td class="text-end"><?= number_format($gt_sisa,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($gt_spj,0,',','.') ?></td>
                                        <td class="text-end <?= getDeviasiClass($gt_deviasi_total) ?>"><?= number_format($gt_deviasi_total,2,',','.') ?>%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
var allOpd = <?= json_encode($opd_list_for_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('opd-search-input');
    var hidden = document.getElementById('opd-id-hidden');
    var dropdown = document.getElementById('opd-dropdown');

    function renderDropdown(filterText) {
        dropdown.innerHTML = '';
        var text = filterText.toLowerCase().trim();
        var filtered = allOpd.filter(function(opd) { return opd.nama.toLowerCase().indexOf(text) !== -1; });
        if (filtered.length === 0) {
            dropdown.innerHTML = '<div class="opd-dropdown-item text-muted">Tidak ditemukan</div>';
        } else {
            filtered.forEach(function(opd) {
                var div = document.createElement('div');
                div.className = 'opd-dropdown-item';
                div.textContent = opd.nama;
                div.dataset.id = opd.id;
                div.addEventListener('click', function() {
                    input.value = opd.nama;
                    hidden.value = opd.id;
                    dropdown.style.display = 'none';
                });
                dropdown.appendChild(div);
            });
        }
    }

    input.addEventListener('focus', function() {
        renderDropdown(input.value);
        dropdown.style.display = 'block';
        var rect = input.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.top = rect.bottom + 'px';
        dropdown.style.left = rect.left + 'px';
        dropdown.style.width = 'auto';
        dropdown.style.minWidth = rect.width + 'px';
    });
    input.addEventListener('input', function() { renderDropdown(this.value); dropdown.style.display = 'block'; });
    document.addEventListener('click', function(e) { if (!input.contains(e.target) && !dropdown.contains(e.target)) dropdown.style.display = 'none'; });
    input.addEventListener('change', function() { if (this.value === '') hidden.value = ''; });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
