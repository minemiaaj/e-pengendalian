<?php
/**
 * rincian_belanja.php - Laporan Rincian Belanja (Super Admin)
 *
 * Keamanan:
 * - Semua query database menggunakan prepared statement (anti SQL injection)
 * - Proteksi path traversal pada file TTD
 * - Output escaping dengan htmlspecialchars()
 * - Validasi parameter input
 */

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../dashboard/tool/opd_mapping.php';


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

// Parameter
$show_table = isset($_GET['filter']);
$tahun      = isset($_GET['tahun'])  ? (int)$_GET['tahun']  : (int)date('Y');
$opd_id     = isset($_GET['opd_id']) ? (int)$_GET['opd_id'] : 0;
$bulan      = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('n');
$export     = isset($_GET['export']) && in_array($_GET['export'], ['excel','pdf',''], true) ? $_GET['export'] : '';

if ($tahun < 2000 || $tahun > 2100) $tahun = (int)date('Y');
if ($bulan < 1 || $bulan > 12) $bulan = (int)date('n');

$bulan_keys      = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
$bulan_indonesia = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

// Daftar OPD tanpa UPTD untuk dropdown
$hidden_names = array_keys($opd_mapping);
$opd_list_for_js = [];
if (!empty($hidden_names)) {
    $ph = implode(',', array_fill(0, count($hidden_names), '?'));
    $ty = str_repeat('s', count($hidden_names));
    $stmt = $conn->prepare("SELECT id, nama_opd FROM opd WHERE nama_opd NOT IN ($ph) ORDER BY nama_opd");
    $stmt->bind_param($ty, ...$hidden_names);
    $stmt->execute();
    $opd_list = $stmt->get_result();
} else {
    $opd_list = $conn->query("SELECT id, nama_opd FROM opd ORDER BY nama_opd");
}
while ($o = $opd_list->fetch_assoc()) {
    $opd_list_for_js[] = ['id' => (int)$o['id'], 'nama' => $o['nama_opd']];
}
$opd_list->data_seek(0);

// Helper
function getDataKepalaOpd($conn, $opd_id) {
    $data = ['ttd_path'=>null,'nama_kepala'=>'KEPALA OPD','nama_opd'=>'','tanggal'=>date('d F Y')];
    if ($opd_id <= 0) return $data;
    $stmt = $conn->prepare("SELECT ttd, nama_opd FROM opd WHERE id = ?");
    $stmt->bind_param('i', $opd_id);
    $stmt->execute(); $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) { $data['ttd_path'] = $row['ttd']; $data['nama_opd'] = $row['nama_opd']; }
    $stmt->close();
    $stmt = $conn->prepare("SELECT nama_lengkap FROM users WHERE opd_id = ? AND role = 'kepala_opd' LIMIT 1");
    $stmt->bind_param('i', $opd_id);
    $stmt->execute(); $res = $stmt->get_result();
    if (($row = $res->fetch_assoc()) && !empty($row['nama_lengkap'])) $data['nama_kepala'] = $row['nama_lengkap'];
    $stmt->close();
    return $data;
}
function isValidTtdPath($path) {
    return $path && preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $path) && strpos($path, '..') === false;
}
function getNama($kode, $map) { return $map[$kode] ?? '(tanpa uraian)'; }
function formatPersen($value) { return number_format($value, 2, ',', '.') . '%'; }

// ===================== PROSES DATA =====================
$sections = [];
$nama_map = [];
$programs_all = [];
$prog_totals_all = [];
$keg_totals_all = [];
$subkeg_totals_all = [];
$dinas_nama = '';
$all_ids = [];

if ($show_table) {
    if ($opd_id > 0) {
        $stmt = $conn->prepare("SELECT nama_opd FROM opd WHERE id = ?");
        $stmt->bind_param('i', $opd_id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $dinas_nama = $r ? $r['nama_opd'] : '';
        $stmt->close();

        $all_ids = [$opd_id];
        if ($dinas_nama) {
            $uptd_names = [];
            foreach ($opd_mapping as $uptd => $dinas) if ($dinas === $dinas_nama) $uptd_names[] = $uptd;
            if (!empty($uptd_names)) {
                $ph = implode(',', array_fill(0, count($uptd_names), '?'));
                $ty = str_repeat('s', count($uptd_names));
                $stmt = $conn->prepare("SELECT id FROM opd WHERE nama_opd IN ($ph)");
                $stmt->bind_param($ty, ...$uptd_names);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($u = $res->fetch_assoc()) $all_ids[] = (int)$u['id'];
                $stmt->close();
            }
        }
    }

    // Akumulasi realisasi hanya bulan yang divalidasi/dikunci
    $akum_pagu_parts = [];
    $akum_vol_parts  = [];
    for ($i = 1; $i <= $bulan; $i++) {
        $bkey = $bulan_keys[$i-1];
        $akum_pagu_parts[] = "CASE WHEN r.status_$bkey IN ('divalidasi','dikunci') THEN COALESCE(r.pagu_$bkey, 0) ELSE 0 END";
        $akum_vol_parts[]  = "CASE WHEN r.status_$bkey IN ('divalidasi','dikunci') THEN COALESCE(r.volume_$bkey, 0) ELSE 0 END";
    }
    $akum_pagu_expr = implode(' + ', $akum_pagu_parts);
    $akum_vol_expr  = implode(' + ', $akum_vol_parts);

    // Query utama dengan prepared statement
    $query = "
        SELECT a.opd_id, a.id,
               a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan,
               mb.kode AS kode_rincian, mb.nama AS nama_rincian,
               a.total_volume, a.total_pagu,
               ($akum_vol_expr) AS akum_realisasi_volume,
               ($akum_pagu_expr) AS akum_realisasi_pagu
        FROM anggaran_detail a
        JOIN master_belanja mb ON a.rincian_belanja_id = mb.id
        LEFT JOIN realisasi_detail r ON a.id = r.anggaran_detail_id
            AND r.opd_id = a.opd_id AND r.tahun = a.tahun
        WHERE ";

    $params = [];
    $types  = '';

    if ($opd_id > 0) {
        $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
        $query .= " a.opd_id IN ($placeholders) AND";
        foreach ($all_ids as $id) {
            $params[] = $id;
            $types .= 'i';
        }
    } else {
        $query .= " 1=1 AND";
    }

    $query .= " a.tahun = ? AND
          a.status_validasi = 'dikunci'
          AND a.versi = (
              SELECT MAX(versi) FROM anggaran_detail a2
              WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun
                AND a2.kode_program = a.kode_program
                AND a2.kode_kegiatan = a.kode_kegiatan
                AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan
                AND a2.rincian_belanja_id = a.rincian_belanja_id
                AND a2.status_validasi = 'dikunci'
          )
        ORDER BY a.opd_id, a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan, mb.kode";
    $params[] = $tahun;
    $types .= 'i';

    $stmt_main = $conn->prepare($query);
    $stmt_main->bind_param($types, ...$params);
    $stmt_main->execute();
    $result = $stmt_main->get_result();
    $data_per_opd = [];
    while ($row = $result->fetch_assoc()) {
        $oid = $row['opd_id'];
        $prog = $row['kode_program'];
        $keg  = $row['kode_kegiatan'];
        $sub  = $row['kode_sub_kegiatan'];
        $data_per_opd[$oid]['programs'][$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub]['rincian'][] = $row;
    }
    $stmt_main->close();

    // Kumpulkan kode untuk nama hierarki
    $all_codes = [];
    foreach ($data_per_opd as $d)
        foreach ($d['programs'] as $prog => $pdata) {
            $all_codes[] = $prog;
            foreach ($pdata['kegiatan'] as $keg => $kdata) {
                $all_codes[] = $keg;
                foreach ($kdata['sub_kegiatan'] as $sub => $sdata) $all_codes[] = $sub;
            }
        }
    $all_codes = array_unique($all_codes);
    $query_codes = [];
    foreach ($all_codes as $code) {
        $query_codes[] = $code;
        if (preg_match('/^(\d+\.\d+)\.(.+)$/', $code, $m)) $query_codes[] = 'X.XX.'.$m[2];
    }
    $query_codes = array_unique($query_codes);
    if (!empty($query_codes)) {
        $ph = implode(',', array_fill(0, count($query_codes), '?'));
        $ty = str_repeat('s', count($query_codes));
        $stmt = $conn->prepare("SELECT kode, nama FROM master_hierarki WHERE kode IN ($ph)");
        $stmt->bind_param($ty, ...$query_codes);
        $stmt->execute();
        $res = $stmt->get_result();
        $all_names = [];
        while ($nm = $res->fetch_assoc()) $all_names[$nm['kode']] = $nm['nama'];
        $stmt->close();
        foreach ($all_codes as $code) {
            if (isset($all_names[$code])) $nama_map[$code] = $all_names[$code];
            else {
                $placeholder = preg_replace('/^\d+\.\d+\./', 'X.XX.', $code);
                $nama_map[$code] = $all_names[$placeholder] ?? '(tanpa uraian)';
            }
        }
    }

    // Lanjutkan logika agregasi dan perhitungan tertimbang
    if ($opd_id > 0) {
        // Ambil nama OPD untuk setiap ID
        $opd_nama_map = [];
        $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
        $ty = str_repeat('i', count($all_ids));
        $stmt = $conn->prepare("SELECT id, nama_opd FROM opd WHERE id IN ($placeholders)");
        $stmt->bind_param($ty, ...$all_ids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $opd_nama_map[(int)$r['id']] = $r['nama_opd'];
        $stmt->close();

        foreach ($all_ids as $oid) {
            if (!isset($data_per_opd[$oid])) { $sections[$oid] = null; continue; }
            $d = $data_per_opd[$oid]; $programs = $d['programs'];
            $sub_tot = []; $keg_tot = []; $prog_tot = [];
            foreach ($programs as $prog => $pdata) {
                $prog_tot[$prog] = ['pagu'=>0,'vol'=>0,'real_pagu'=>0,'real_vol'=>0];
                foreach ($pdata['kegiatan'] as $keg => $kdata) {
                    $keg_tot[$keg] = ['pagu'=>0,'vol'=>0,'real_pagu'=>0,'real_vol'=>0];
                    foreach ($kdata['sub_kegiatan'] as $sub => $sdata) {
                        $sub_tot[$sub] = ['pagu'=>0,'vol'=>0,'real_pagu'=>0,'real_vol'=>0];
                        foreach ($sdata['rincian'] as $r) {
                            $sub_tot[$sub]['pagu'] += $r['total_pagu'];
                            $sub_tot[$sub]['vol'] += $r['total_volume'];
                            $sub_tot[$sub]['real_pagu'] += $r['akum_realisasi_pagu'];
                            $sub_tot[$sub]['real_vol'] += $r['akum_realisasi_volume'];
                        }
                        foreach (['pagu','vol','real_pagu','real_vol'] as $f) $keg_tot[$keg][$f] += $sub_tot[$sub][$f];
                    }
                    foreach (['pagu','vol','real_pagu','real_vol'] as $f) $prog_tot[$prog][$f] += $keg_tot[$keg][$f];
                }
            }

            // Perhitungan tertimbang
            $sub_tertimbang = [];
            $keg_tertimbang = [];
            $prog_tertimbang = [];
            foreach ($programs as $kode_prog => $prog_data) {
                $keg_tertimbang_list = [];
                foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data) {
                    $sub_tertimbang_list = [];
                    foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
                        $sum = 0;
                        $st = $sub_tot[$kode_sub];
                        foreach ($sub_data['rincian'] as $rincian) {
                            $tp = $rincian['total_pagu'];
                            $bobot = $st['pagu'] ? ($tp / $st['pagu']) * 100 : 0;
                            $fisik = $rincian['total_volume'] ? ($rincian['akum_realisasi_volume'] / $rincian['total_volume']) * 100 : 0;
                            $sum += $fisik * $bobot / 100;
                        }
                        $sub_tertimbang[$kode_sub] = $sum;
                        $sub_tertimbang_list[] = $sum;
                    }
                    $keg_tertimbang[$kode_keg] = count($sub_tertimbang_list) > 0 ? array_sum($sub_tertimbang_list) / count($sub_tertimbang_list) : 0;
                    $keg_tertimbang_list[] = $keg_tertimbang[$kode_keg];
                }
                $prog_tertimbang[$kode_prog] = count($keg_tertimbang_list) > 0 ? array_sum($keg_tertimbang_list) / count($keg_tertimbang_list) : 0;
            }
            $grand_tertimbang = count($prog_tertimbang) > 0 ? array_sum($prog_tertimbang) / count($prog_tertimbang) : 0;

            $sections[$oid] = [
                'nama'       => $opd_nama_map[$oid],
                'programs'   => $programs,
                'prog_totals'=> $prog_tot,
                'keg_totals' => $keg_tot,
                'sub_totals' => $sub_tot,
                'tertimbang' => [
                    'sub' => $sub_tertimbang,
                    'keg' => $keg_tertimbang,
                    'prog' => $prog_tertimbang,
                    'grand' => $grand_tertimbang
                ]
            ];
        }

        // Hitung tertimbang keseluruhan
        $all_prog_tertimbang = [];
        foreach ($all_ids as $oid) {
            if (isset($sections[$oid]['tertimbang']['prog'])) {
                foreach ($sections[$oid]['tertimbang']['prog'] as $val) {
                    $all_prog_tertimbang[] = $val;
                }
            }
        }
        $total_tertimbang_keseluruhan = count($all_prog_tertimbang) > 0 ? array_sum($all_prog_tertimbang) / count($all_prog_tertimbang) : 0;

    } else {
        // Semua OPD
        foreach ($data_per_opd as $oid => $d) {
            foreach ($d['programs'] as $prog => $pdata) {
                if (!isset($programs_all[$prog])) $programs_all[$prog] = ['kegiatan'=>[]];
                foreach ($pdata['kegiatan'] as $keg => $kdata) {
                    if (!isset($programs_all[$prog]['kegiatan'][$keg])) $programs_all[$prog]['kegiatan'][$keg] = ['sub_kegiatan'=>[]];
                    foreach ($kdata['sub_kegiatan'] as $sub => $sdata) {
                        if (!isset($programs_all[$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub])) $programs_all[$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub] = ['rincian'=>[]];
                        $programs_all[$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub]['rincian'] = array_merge(
                            $programs_all[$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub]['rincian'],
                            $sdata['rincian']
                        );
                    }
                }
            }
        }

        foreach ($programs_all as $prog => $pdata) {
            $prog_totals_all[$prog] = ['pagu'=>0,'vol'=>0,'real_pagu'=>0,'real_vol'=>0];
            foreach ($pdata['kegiatan'] as $keg => $kdata) {
                $keg_totals_all[$keg] = ['pagu'=>0,'vol'=>0,'real_pagu'=>0,'real_vol'=>0];
                foreach ($kdata['sub_kegiatan'] as $sub => $sdata) {
                    $subkeg_totals_all[$sub] = ['pagu'=>0,'vol'=>0,'real_pagu'=>0,'real_vol'=>0];
                    foreach ($sdata['rincian'] as $r) {
                        $subkeg_totals_all[$sub]['pagu'] += $r['total_pagu'];
                        $subkeg_totals_all[$sub]['vol'] += $r['total_volume'];
                        $subkeg_totals_all[$sub]['real_pagu'] += $r['akum_realisasi_pagu'];
                        $subkeg_totals_all[$sub]['real_vol'] += $r['akum_realisasi_volume'];
                    }
                    foreach (['pagu','vol','real_pagu','real_vol'] as $f) $keg_totals_all[$keg][$f] += $subkeg_totals_all[$sub][$f];
                }
                foreach (['pagu','vol','real_pagu','real_vol'] as $f) $prog_totals_all[$prog][$f] += $keg_totals_all[$keg][$f];
            }
        }

        // Tertimbang untuk semua OPD
        $all_sub_tertimbang = [];
        $all_keg_tertimbang = [];
        $all_prog_tertimbang = [];
        foreach ($programs_all as $kode_prog => $prog_data) {
            $keg_tertimbang_list = [];
            foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data) {
                $sub_tertimbang_list = [];
                foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
                    $sum = 0;
                    $st = $subkeg_totals_all[$kode_sub];
                    foreach ($sub_data['rincian'] as $rincian) {
                        $tp = $rincian['total_pagu'];
                        $bobot = $st['pagu'] ? ($tp / $st['pagu']) * 100 : 0;
                        $fisik = $rincian['total_volume'] ? ($rincian['akum_realisasi_volume'] / $rincian['total_volume']) * 100 : 0;
                        $sum += $fisik * $bobot / 100;
                    }
                    $all_sub_tertimbang[$kode_sub] = $sum;
                    $sub_tertimbang_list[] = $sum;
                }
                $all_keg_tertimbang[$kode_keg] = count($sub_tertimbang_list) > 0 ? array_sum($sub_tertimbang_list) / count($sub_tertimbang_list) : 0;
                $keg_tertimbang_list[] = $all_keg_tertimbang[$kode_keg];
            }
            $all_prog_tertimbang[$kode_prog] = count($keg_tertimbang_list) > 0 ? array_sum($keg_tertimbang_list) / count($keg_tertimbang_list) : 0;
        }
        $grand_all_tertimbang = count($all_prog_tertimbang) > 0 ? array_sum($all_prog_tertimbang) / count($all_prog_tertimbang) : 0;
    }
}

// ===================== EKSPOR =====================
if ($export && $show_table) {
    if ($opd_id > 0) {
        $multi = count($all_ids) > 1;
    } else {
        $multi = false;
    }

    if ($export === 'excel') {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        if ($opd_id > 0) {
            foreach ($all_ids as $oid) {
                $section = $sections[$oid] ?? null;
                if (!$section) continue;
                $nama_opd = $section['nama'];
                $sheetTitle = substr($nama_opd, 0, 31);
                $sheet = new Worksheet($spreadsheet, $sheetTitle);
                $spreadsheet->addSheet($sheet);

                $programs = $section['programs'];
                $prog_totals = $section['prog_totals'];
                $keg_totals = $section['keg_totals'];
                $sub_totals = $section['sub_totals'];
                $tertimbang = $section['tertimbang'];

                // Judul
                $sheet->setCellValue('A1', 'Laporan Rincian Belanja');
                $sheet->mergeCells('A1:J1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->setCellValue('A2', $bulan_indonesia[$bulan-1].' '.$tahun.' | OPD: '.$nama_opd);
                $sheet->mergeCells('A2:J2');
                $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Header
                $sheet->mergeCells('A4:A5'); $sheet->setCellValue('A4', 'Kode Rekening');
                $sheet->mergeCells('B4:B5'); $sheet->setCellValue('B4', 'Uraian');
                $sheet->mergeCells('C4:D4'); $sheet->setCellValue('C4', 'Total');
                $sheet->mergeCells('E4:E5'); $sheet->setCellValue('E4', 'Bobot');
                $sheet->mergeCells('F4:G4'); $sheet->setCellValue('F4', 'Realisasi Anggaran');
                $sheet->mergeCells('H4:J4'); $sheet->setCellValue('H4', 'Realisasi Fisik');
                $sheet->setCellValue('C5', 'Anggaran (Rp)'); $sheet->setCellValue('D5', 'Volume');
                $sheet->setCellValue('F5', 'Rp'); $sheet->setCellValue('G5', '%');
                $sheet->setCellValue('H5', 'Volume'); $sheet->setCellValue('I5', 'Fisik (%)'); $sheet->setCellValue('J5', 'Tertimbang (%)');

                $headerStyle = [
                    'font' => ['bold' => true],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEEEEE']]
                ];
                $sheet->getStyle('A4:J5')->applyFromArray($headerStyle);

                $rowNum = 6;
                $grand_pagu = $grand_vol = $grand_real_pagu = $grand_real_vol = 0;

                foreach ($programs as $kode_prog => $prog_data) {
                    $pt = $prog_totals[$kode_prog];
                    $prog_fisik = $pt['vol'] ? ($pt['real_vol'] / $pt['vol']) * 100 : 0;
                    $prog_anggaran_persen = $pt['pagu'] ? ($pt['real_pagu'] / $pt['pagu']) * 100 : 0;

                    $sheet->setCellValue('A'.$rowNum, $kode_prog);
                    $sheet->setCellValue('B'.$rowNum, getNama($kode_prog, $nama_map));
                    $sheet->setCellValue('C'.$rowNum, $pt['pagu']);
                    $sheet->setCellValue('D'.$rowNum, $pt['vol']);
                    $sheet->setCellValue('E'.$rowNum, formatPersen(100));
                    $sheet->setCellValue('F'.$rowNum, $pt['real_pagu']);
                    $sheet->setCellValue('G'.$rowNum, formatPersen($prog_anggaran_persen));
                    $sheet->setCellValue('H'.$rowNum, $pt['real_vol']);
                    $sheet->setCellValue('I'.$rowNum, formatPersen($prog_fisik));
                    $sheet->setCellValue('J'.$rowNum, formatPersen($tertimbang['prog'][$kode_prog]));
                    $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCE5FF');
                    $sheet->getStyle('A'.$rowNum.':B'.$rowNum)->getFont()->setBold(true);
                    $rowNum++;

                    foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data) {
                        $kt = $keg_totals[$kode_keg];
                        $keg_fisik = $kt['vol'] ? ($kt['real_vol'] / $kt['vol']) * 100 : 0;
                        $keg_anggaran_persen = $kt['pagu'] ? ($kt['real_pagu'] / $kt['pagu']) * 100 : 0;

                        $sheet->setCellValue('A'.$rowNum, $kode_keg);
                        $sheet->setCellValue('B'.$rowNum, getNama($kode_keg, $nama_map));
                        $sheet->setCellValue('C'.$rowNum, $kt['pagu']);
                        $sheet->setCellValue('D'.$rowNum, $kt['vol']);
                        $sheet->setCellValue('E'.$rowNum, formatPersen(100));
                        $sheet->setCellValue('F'.$rowNum, $kt['real_pagu']);
                        $sheet->setCellValue('G'.$rowNum, formatPersen($keg_anggaran_persen));
                        $sheet->setCellValue('H'.$rowNum, $kt['real_vol']);
                        $sheet->setCellValue('I'.$rowNum, formatPersen($keg_fisik));
                        $sheet->setCellValue('J'.$rowNum, formatPersen($tertimbang['keg'][$kode_keg]));
                        $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E3E5');
                        $rowNum++;

                        foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
                            $st = $sub_totals[$kode_sub];
                            $sub_fisik = $st['vol'] ? ($st['real_vol'] / $st['vol']) * 100 : 0;
                            $sub_anggaran_persen = $st['pagu'] ? ($st['real_pagu'] / $st['pagu']) * 100 : 0;

                            $sheet->setCellValue('A'.$rowNum, $kode_sub);
                            $sheet->setCellValue('B'.$rowNum, getNama($kode_sub, $nama_map));
                            $sheet->setCellValue('C'.$rowNum, $st['pagu']);
                            $sheet->setCellValue('D'.$rowNum, $st['vol']);
                            $sheet->setCellValue('E'.$rowNum, formatPersen(100));
                            $sheet->setCellValue('F'.$rowNum, $st['real_pagu']);
                            $sheet->setCellValue('G'.$rowNum, formatPersen($sub_anggaran_persen));
                            $sheet->setCellValue('H'.$rowNum, $st['real_vol']);
                            $sheet->setCellValue('I'.$rowNum, formatPersen($sub_fisik));
                            $sheet->setCellValue('J'.$rowNum, formatPersen($tertimbang['sub'][$kode_sub]));
                            $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
                            $rowNum++;

                            foreach ($sub_data['rincian'] as $rincian) {
                                $tp = $rincian['total_pagu']; $rp = $rincian['akum_realisasi_pagu'];
                                $tv = $rincian['total_volume']; $rv = $rincian['akum_realisasi_volume'];
                                $ang_persen = $tp ? ($rp / $tp) * 100 : 0;
                                $fisik = $tv ? ($rv / $tv) * 100 : 0;
                                $bobot = $st['pagu'] ? ($tp / $st['pagu']) * 100 : 0;
                                $tertimbang_val = $fisik * $bobot / 100;
                                $grand_pagu += $tp; $grand_vol += $tv; $grand_real_pagu += $rp; $grand_real_vol += $rv;

                                $sheet->setCellValue('A'.$rowNum, $rincian['kode_rincian']);
                                $sheet->setCellValue('B'.$rowNum, $rincian['nama_rincian']);
                                $sheet->setCellValue('C'.$rowNum, $tp);
                                $sheet->setCellValue('D'.$rowNum, $tv);
                                $sheet->setCellValue('E'.$rowNum, formatPersen($bobot));
                                $sheet->setCellValue('F'.$rowNum, $rp);
                                $sheet->setCellValue('G'.$rowNum, formatPersen($ang_persen));
                                $sheet->setCellValue('H'.$rowNum, $rv);
                                $sheet->setCellValue('I'.$rowNum, formatPersen($fisik));
                                $sheet->setCellValue('J'.$rowNum, formatPersen($tertimbang_val));
                                $rowNum++;
                            }
                        }
                    }
                }

                // Grand total OPD
                $grand_fisik = $grand_vol ? ($grand_real_vol / $grand_vol) * 100 : 0;
                $grand_anggaran_persen = $grand_pagu ? ($grand_real_pagu / $grand_pagu) * 100 : 0;
                $sheet->setCellValue('A'.$rowNum, 'TOTAL');
                $sheet->mergeCells('A'.$rowNum.':B'.$rowNum);
                $sheet->getStyle('A'.$rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->setCellValue('C'.$rowNum, $grand_pagu);
                $sheet->setCellValue('D'.$rowNum, $grand_vol);
                $sheet->setCellValue('E'.$rowNum, formatPersen(100));
                $sheet->setCellValue('F'.$rowNum, $grand_real_pagu);
                $sheet->setCellValue('G'.$rowNum, formatPersen($grand_anggaran_persen));
                $sheet->setCellValue('H'.$rowNum, $grand_real_vol);
                $sheet->setCellValue('I'.$rowNum, formatPersen($grand_fisik));
                $sheet->setCellValue('J'.$rowNum, formatPersen($tertimbang['grand']));
                $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6D8DB');
                $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFont()->setBold(true);

                // Format angka
                for ($r = 6; $r <= $rowNum; $r++) {
                    $sheet->getStyle('C'.$r)->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle('D'.$r)->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle('F'.$r)->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle('H'.$r)->getNumberFormat()->setFormatCode('#,##0');
                }
                $sheet->getStyle('A4:J'.$rowNum)->applyFromArray(['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]]);
                foreach(range('A','J') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

                // TTD
                $kepala = getDataKepalaOpd($conn, $oid);
                $rowTtd = $rowNum + 3;
                $tgl = date('d') . ' ' . $bulan_indonesia[date('n')-1] . ' ' . date('Y');
                $sheet->mergeCells('J'.$rowTtd.':J'.($rowTtd+4));
                $ttdText = "Kendari, {$tgl}\nKEPALA ".strtoupper($kepala['nama_opd'])."\n\n\n(".$kepala['nama_kepala'].")";
                $sheet->setCellValue('J'.$rowTtd, $ttdText);
                $sheet->getStyle('J'.$rowTtd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getStyle('J'.$rowTtd)->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
                $sheet->getStyle('J'.$rowTtd)->getAlignment()->setWrapText(true);
                if (isValidTtdPath($kepala['ttd_path'])) {
                    $fullPath = __DIR__ . '/../../' . $kepala['ttd_path'];
                    if (file_exists($fullPath)) {
                        $drawing = new Drawing();
                        $drawing->setName('TTD')->setDescription('Tanda Tangan Kepala OPD');
                        $drawing->setPath($fullPath);
                        $drawing->setHeight(40);
                        $drawing->setCoordinates('J'.($rowTtd+2));
                        $drawing->setOffsetX(5);
                        $drawing->setWorksheet($sheet);
                    }
                }
            }
        } else {
            // Semua OPD – satu sheet
            $sheet = new Worksheet($spreadsheet, 'Semua OPD');
            $spreadsheet->addSheet($sheet);
            $sheet->setCellValue('A1', 'Laporan Rincian Belanja');
            $sheet->mergeCells('A1:J1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setCellValue('A2', $bulan_indonesia[$bulan-1].' '.$tahun.' | Semua OPD');
            $sheet->mergeCells('A2:J2');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Header
            $sheet->mergeCells('A4:A5'); $sheet->setCellValue('A4', 'Kode Rekening');
            $sheet->mergeCells('B4:B5'); $sheet->setCellValue('B4', 'Uraian');
            $sheet->mergeCells('C4:D4'); $sheet->setCellValue('C4', 'Total');
            $sheet->mergeCells('E4:E5'); $sheet->setCellValue('E4', 'Bobot');
            $sheet->mergeCells('F4:G4'); $sheet->setCellValue('F4', 'Realisasi Anggaran');
            $sheet->mergeCells('H4:J4'); $sheet->setCellValue('H4', 'Realisasi Fisik');
            $sheet->setCellValue('C5', 'Anggaran (Rp)'); $sheet->setCellValue('D5', 'Volume');
            $sheet->setCellValue('F5', 'Rp'); $sheet->setCellValue('G5', '%');
            $sheet->setCellValue('H5', 'Volume'); $sheet->setCellValue('I5', 'Fisik (%)'); $sheet->setCellValue('J5', 'Tertimbang (%)');
            $headerStyle = [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEEEEE']]
            ];
            $sheet->getStyle('A4:J5')->applyFromArray($headerStyle);

            $rowNum = 6;
            $grand_pagu = $grand_vol = $grand_real_pagu = $grand_real_vol = 0;
            foreach ($programs_all as $kode_prog => $prog_data) {
                $pt = $prog_totals_all[$kode_prog];
                $prog_fisik = $pt['vol'] ? ($pt['real_vol'] / $pt['vol']) * 100 : 0;
                $prog_anggaran_persen = $pt['pagu'] ? ($pt['real_pagu'] / $pt['pagu']) * 100 : 0;

                $sheet->setCellValue('A'.$rowNum, $kode_prog);
                $sheet->setCellValue('B'.$rowNum, getNama($kode_prog, $nama_map));
                $sheet->setCellValue('C'.$rowNum, $pt['pagu']);
                $sheet->setCellValue('D'.$rowNum, $pt['vol']);
                $sheet->setCellValue('E'.$rowNum, formatPersen(100));
                $sheet->setCellValue('F'.$rowNum, $pt['real_pagu']);
                $sheet->setCellValue('G'.$rowNum, formatPersen($prog_anggaran_persen));
                $sheet->setCellValue('H'.$rowNum, $pt['real_vol']);
                $sheet->setCellValue('I'.$rowNum, formatPersen($prog_fisik));
                $sheet->setCellValue('J'.$rowNum, formatPersen($all_prog_tertimbang[$kode_prog]));
                $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCE5FF');
                $sheet->getStyle('A'.$rowNum.':B'.$rowNum)->getFont()->setBold(true);
                $rowNum++;

                foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data) {
                    $kt = $keg_totals_all[$kode_keg];
                    $keg_fisik = $kt['vol'] ? ($kt['real_vol'] / $kt['vol']) * 100 : 0;
                    $keg_anggaran_persen = $kt['pagu'] ? ($kt['real_pagu'] / $kt['pagu']) * 100 : 0;

                    $sheet->setCellValue('A'.$rowNum, $kode_keg);
                    $sheet->setCellValue('B'.$rowNum, getNama($kode_keg, $nama_map));
                    $sheet->setCellValue('C'.$rowNum, $kt['pagu']);
                    $sheet->setCellValue('D'.$rowNum, $kt['vol']);
                    $sheet->setCellValue('E'.$rowNum, formatPersen(100));
                    $sheet->setCellValue('F'.$rowNum, $kt['real_pagu']);
                    $sheet->setCellValue('G'.$rowNum, formatPersen($keg_anggaran_persen));
                    $sheet->setCellValue('H'.$rowNum, $kt['real_vol']);
                    $sheet->setCellValue('I'.$rowNum, formatPersen($keg_fisik));
                    $sheet->setCellValue('J'.$rowNum, formatPersen($all_keg_tertimbang[$kode_keg]));
                    $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E3E5');
                    $rowNum++;

                    foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
                        $st = $subkeg_totals_all[$kode_sub];
                        $sub_fisik = $st['vol'] ? ($st['real_vol'] / $st['vol']) * 100 : 0;
                        $sub_anggaran_persen = $st['pagu'] ? ($st['real_pagu'] / $st['pagu']) * 100 : 0;

                        $sheet->setCellValue('A'.$rowNum, $kode_sub);
                        $sheet->setCellValue('B'.$rowNum, getNama($kode_sub, $nama_map));
                        $sheet->setCellValue('C'.$rowNum, $st['pagu']);
                        $sheet->setCellValue('D'.$rowNum, $st['vol']);
                        $sheet->setCellValue('E'.$rowNum, formatPersen(100));
                        $sheet->setCellValue('F'.$rowNum, $st['real_pagu']);
                        $sheet->setCellValue('G'.$rowNum, formatPersen($sub_anggaran_persen));
                        $sheet->setCellValue('H'.$rowNum, $st['real_vol']);
                        $sheet->setCellValue('I'.$rowNum, formatPersen($sub_fisik));
                        $sheet->setCellValue('J'.$rowNum, formatPersen($all_sub_tertimbang[$kode_sub]));
                        $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
                        $rowNum++;

                        foreach ($sub_data['rincian'] as $rincian) {
                            $tp = $rincian['total_pagu']; $rp = $rincian['akum_realisasi_pagu'];
                            $tv = $rincian['total_volume']; $rv = $rincian['akum_realisasi_volume'];
                            $ang_persen = $tp ? ($rp / $tp) * 100 : 0;
                            $fisik = $tv ? ($rv / $tv) * 100 : 0;
                            $bobot = $st['pagu'] ? ($tp / $st['pagu']) * 100 : 0;
                            $tertimbang_val = $fisik * $bobot / 100;
                            $grand_pagu += $tp; $grand_vol += $tv; $grand_real_pagu += $rp; $grand_real_vol += $rv;

                            $sheet->setCellValue('A'.$rowNum, $rincian['kode_rincian']);
                            $sheet->setCellValue('B'.$rowNum, $rincian['nama_rincian']);
                            $sheet->setCellValue('C'.$rowNum, $tp);
                            $sheet->setCellValue('D'.$rowNum, $tv);
                            $sheet->setCellValue('E'.$rowNum, formatPersen($bobot));
                            $sheet->setCellValue('F'.$rowNum, $rp);
                            $sheet->setCellValue('G'.$rowNum, formatPersen($ang_persen));
                            $sheet->setCellValue('H'.$rowNum, $rv);
                            $sheet->setCellValue('I'.$rowNum, formatPersen($fisik));
                            $sheet->setCellValue('J'.$rowNum, formatPersen($tertimbang_val));
                            $rowNum++;
                        }
                    }
                }
            }

            $grand_fisik = $grand_vol ? ($grand_real_vol / $grand_vol) * 100 : 0;
            $grand_anggaran_persen = $grand_pagu ? ($grand_real_pagu / $grand_pagu) * 100 : 0;
            $sheet->setCellValue('A'.$rowNum, 'TOTAL');
            $sheet->mergeCells('A'.$rowNum.':B'.$rowNum);
            $sheet->getStyle('A'.$rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setCellValue('C'.$rowNum, $grand_pagu);
            $sheet->setCellValue('D'.$rowNum, $grand_vol);
            $sheet->setCellValue('E'.$rowNum, formatPersen(100));
            $sheet->setCellValue('F'.$rowNum, $grand_real_pagu);
            $sheet->setCellValue('G'.$rowNum, formatPersen($grand_anggaran_persen));
            $sheet->setCellValue('H'.$rowNum, $grand_real_vol);
            $sheet->setCellValue('I'.$rowNum, formatPersen($grand_fisik));
            $sheet->setCellValue('J'.$rowNum, formatPersen($grand_all_tertimbang));
            $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6D8DB');
            $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFont()->setBold(true);

            for ($r = 6; $r <= $rowNum; $r++) {
                $sheet->getStyle('C'.$r)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('D'.$r)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('F'.$r)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('H'.$r)->getNumberFormat()->setFormatCode('#,##0');
            }
            $sheet->getStyle('A4:J'.$rowNum)->applyFromArray(['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]]);
            foreach(range('A','J') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $nama_opd_file = ($opd_id > 0) ? $dinas_nama : 'Semua_OPD';
        $tanggal_realtime = date('d') . '_' . $bulan_indonesia[date('n')-1] . '_' . date('Y');
        $nama_file = 'rincian_belanja_' . str_replace(' ', '_', $nama_opd_file) . '_' . $tanggal_realtime . '.xlsx';
        header('Content-Disposition: attachment; filename="' . $nama_file . '"');
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    if ($export === 'pdf') {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #000; padding: 4px 6px; vertical-align: top; }
            th { background-color: #eee; font-weight: bold; }
            .text-end { text-align: right; } .text-center { text-align: center; }
            .fw-bold { font-weight: bold; }
            .table-primary { background-color: #cce5ff; }
            .table-secondary { background-color: #e2e3e5; }
            .table-light { background-color: #f8f9fa; }
            .grand-total { background-color: #d6d8db; font-weight: bold; }
            h3 { margin: 5px 0; text-align: center; }
            .subtitle { text-align: center; margin-bottom: 15px; }
            .ttd-wrapper { margin-top: 30px; text-align: right; }
            .ttd-box { display: inline-block; text-align: left; font-size: 9px; }
            .ttd-img { height: 40px; margin-bottom: 5px; display: block; }
            .page-break { page-break-before: always; }
        </style></head><body>';

        if ($opd_id > 0) {
            $first = true;
            foreach ($all_ids as $oid) {
                $section = $sections[$oid] ?? null;
                if (!$section) continue;
                $nama_opd = $section['nama'];
                if (!$first) $html .= '<div class="page-break"></div>';
                $first = false;

                $html .= '<h3>Laporan Rincian Belanja</h3>';
                $html .= '<div class="subtitle">'.$bulan_indonesia[$bulan-1].' '.$tahun.' | OPD: '.htmlspecialchars($nama_opd).'</div>';

                $programs = $section['programs'];
                $prog_totals = $section['prog_totals'];
                $keg_totals = $section['keg_totals'];
                $sub_totals = $section['sub_totals'];
                $tertimbang = $section['tertimbang'];

                $html .= '<table><thead><tr>
                    <th rowspan="2">Kode</th><th rowspan="2">Uraian</th>
                    <th colspan="2" class="text-center">Total</th>
                    <th rowspan="2" class="text-center">Bobot</th>
                    <th colspan="2" class="text-center">Realisasi Anggaran</th>
                    <th colspan="3" class="text-center">Realisasi Fisik</th>
                </tr><tr>
                    <th class="text-end">Anggaran (Rp)</th><th class="text-end">Volume</th>
                    <th class="text-end">Rp</th><th class="text-center">%</th>
                    <th class="text-end">Volume</th><th class="text-center">Fisik (%)</th><th class="text-center">Tertimbang (%)</th>
                </tr></thead><tbody>';

                $grand_pagu = $grand_vol = $grand_real_pagu = $grand_real_vol = 0;

                foreach ($programs as $kode_prog => $prog_data) {
                    $pt = $prog_totals[$kode_prog];
                    $prog_fisik = $pt['vol'] ? ($pt['real_vol'] / $pt['vol']) * 100 : 0;
                    $prog_anggaran_persen = $pt['pagu'] ? ($pt['real_pagu'] / $pt['pagu']) * 100 : 0;

                    $html .= '<tr class="table-primary fw-bold"><td>'.htmlspecialchars($kode_prog).'</td><td>'.htmlspecialchars(getNama($kode_prog, $nama_map)).'</td>
                        <td class="text-end">'.number_format($pt['pagu'],0,',','.').'</td><td class="text-end">'.number_format($pt['vol']).'</td>
                        <td class="text-center">'.formatPersen(100).'</td><td class="text-end">'.number_format($pt['real_pagu'],0,',','.').'</td>
                        <td class="text-center">'.formatPersen($prog_anggaran_persen).'</td><td class="text-end">'.number_format($pt['real_vol']).'</td>
                        <td class="text-center">'.formatPersen($prog_fisik).'</td><td class="text-center">'.formatPersen($tertimbang['prog'][$kode_prog]).'</td></tr>';

                    foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data) {
                        $kt = $keg_totals[$kode_keg];
                        $keg_fisik = $kt['vol'] ? ($kt['real_vol'] / $kt['vol']) * 100 : 0;
                        $keg_anggaran_persen = $kt['pagu'] ? ($kt['real_pagu'] / $kt['pagu']) * 100 : 0;

                        $html .= '<tr class="table-secondary"><td>'.htmlspecialchars($kode_keg).'</td><td>'.htmlspecialchars(getNama($kode_keg, $nama_map)).'</td>
                            <td class="text-end">'.number_format($kt['pagu'],0,',','.').'</td><td class="text-end">'.number_format($kt['vol']).'</td>
                            <td class="text-center">'.formatPersen(100).'</td><td class="text-end">'.number_format($kt['real_pagu'],0,',','.').'</td>
                            <td class="text-center">'.formatPersen($keg_anggaran_persen).'</td><td class="text-end">'.number_format($kt['real_vol']).'</td>
                            <td class="text-center">'.formatPersen($keg_fisik).'</td><td class="text-center">'.formatPersen($tertimbang['keg'][$kode_keg]).'</td></tr>';

                        foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
                            $st = $sub_totals[$kode_sub];
                            $sub_fisik = $st['vol'] ? ($st['real_vol'] / $st['vol']) * 100 : 0;
                            $sub_anggaran_persen = $st['pagu'] ? ($st['real_pagu'] / $st['pagu']) * 100 : 0;

                            $html .= '<tr class="table-light"><td>'.htmlspecialchars($kode_sub).'</td><td>'.htmlspecialchars(getNama($kode_sub, $nama_map)).'</td>
                                <td class="text-end">'.number_format($st['pagu'],0,',','.').'</td><td class="text-end">'.number_format($st['vol']).'</td>
                                <td class="text-center">'.formatPersen(100).'</td><td class="text-end">'.number_format($st['real_pagu'],0,',','.').'</td>
                                <td class="text-center">'.formatPersen($sub_anggaran_persen).'</td><td class="text-end">'.number_format($st['real_vol']).'</td>
                                <td class="text-center">'.formatPersen($sub_fisik).'</td><td class="text-center">'.formatPersen($tertimbang['sub'][$kode_sub]).'</td></tr>';

                            foreach ($sub_data['rincian'] as $rincian) {
                                $tp = $rincian['total_pagu']; $rp = $rincian['akum_realisasi_pagu'];
                                $tv = $rincian['total_volume']; $rv = $rincian['akum_realisasi_volume'];
                                $ang_persen = $tp ? ($rp / $tp) * 100 : 0;
                                $fisik = $tv ? ($rv / $tv) * 100 : 0;
                                $bobot = $st['pagu'] ? ($tp / $st['pagu']) * 100 : 0;
                                $tertimbang_val = $fisik * $bobot / 100;
                                $grand_pagu += $tp; $grand_vol += $tv; $grand_real_pagu += $rp; $grand_real_vol += $rv;

                                $html .= '<tr><td>'.htmlspecialchars($rincian['kode_rincian']).'</td><td>'.htmlspecialchars($rincian['nama_rincian']).'</td>
                                    <td class="text-end">'.number_format($tp,0,',','.').'</td><td class="text-end">'.number_format($tv).'</td>
                                    <td class="text-center">'.formatPersen($bobot).'</td><td class="text-end">'.number_format($rp,0,',','.').'</td>
                                    <td class="text-center">'.formatPersen($ang_persen).'</td><td class="text-end">'.number_format($rv).'</td>
                                    <td class="text-center">'.formatPersen($fisik).'</td><td class="text-center">'.formatPersen($tertimbang_val).'</td></tr>';
                            }
                        }
                    }
                }

                $grand_fisik = $grand_vol ? ($grand_real_vol / $grand_vol) * 100 : 0;
                $grand_anggaran_persen = $grand_pagu ? ($grand_real_pagu / $grand_pagu) * 100 : 0;
                $html .= '<tr class="grand-total"><td colspan="2" class="text-center"><strong>TOTAL</strong></td>
                    <td class="text-end"><strong>'.number_format($grand_pagu,0,',','.').'</strong></td>
                    <td class="text-end"><strong>'.number_format($grand_vol).'</strong></td>
                    <td class="text-center"><strong>'.formatPersen(100).'</strong></td>
                    <td class="text-end"><strong>'.number_format($grand_real_pagu,0,',','.').'</strong></td>
                    <td class="text-center"><strong>'.formatPersen($grand_anggaran_persen).'</strong></td>
                    <td class="text-end"><strong>'.number_format($grand_real_vol).'</strong></td>
                    <td class="text-center"><strong>'.formatPersen($grand_fisik).'</strong></td>
                    <td class="text-center"><strong>'.formatPersen($tertimbang['grand']).'</strong></td></tr>';
                $html .= '</tbody></table>';

                // TTD
                $kepala = getDataKepalaOpd($conn, $oid);
                $tgl = date('d') . ' ' . $bulan_indonesia[date('n')-1] . ' ' . date('Y');
                $html .= '<div class="ttd-wrapper"><div class="ttd-box">';
                $html .= '<div>Kendari, '.$tgl.'</div><div>KEPALA '.strtoupper(htmlspecialchars($kepala['nama_opd'])).'</div>';
                if (isValidTtdPath($kepala['ttd_path'])) {
                    $fullPath = __DIR__ . '/../../' . $kepala['ttd_path'];
                    if (file_exists($fullPath)) {
                        $img = file_get_contents($fullPath);
                        $mime = mime_content_type($fullPath);
                        $html .= '<div><img class="ttd-img" src="data:'.$mime.';base64,'.base64_encode($img).'"></div>';
                    } else {
                        $html .= '<div style="margin-top:30px;">&nbsp;</div>';
                    }
                } else {
                    $html .= '<div style="margin-top:30px;">&nbsp;</div>';
                }
                $html .= '<div>('.htmlspecialchars($kepala['nama_kepala']).')</div>';
                $html .= '</div></div>';
            }
        } else {
            // Semua OPD tanpa TTD
            $html .= '<h3>Laporan Rincian Belanja</h3>';
            $html .= '<div class="subtitle">'.$bulan_indonesia[$bulan-1].' '.$tahun.' | Semua OPD</div>';
            $html .= '<table><thead><tr>
                <th rowspan="2">Kode</th><th rowspan="2">Uraian</th>
                <th colspan="2" class="text-center">Total</th>
                <th rowspan="2" class="text-center">Bobot</th>
                <th colspan="2" class="text-center">Realisasi Anggaran</th>
                <th colspan="3" class="text-center">Realisasi Fisik</th>
            </tr><tr>
                <th class="text-end">Anggaran (Rp)</th><th class="text-end">Volume</th>
                <th class="text-end">Rp</th><th class="text-center">%</th>
                <th class="text-end">Volume</th><th class="text-center">Fisik (%)</th><th class="text-center">Tertimbang (%)</th>
            </tr></thead><tbody>';

            $grand_pagu = $grand_vol = $grand_real_pagu = $grand_real_vol = 0;
            foreach ($programs_all as $kode_prog => $prog_data) {
                $pt = $prog_totals_all[$kode_prog];
                $prog_fisik = $pt['vol'] ? ($pt['real_vol'] / $pt['vol']) * 100 : 0;
                $prog_anggaran_persen = $pt['pagu'] ? ($pt['real_pagu'] / $pt['pagu']) * 100 : 0;

                $html .= '<tr class="table-primary fw-bold"><td>'.htmlspecialchars($kode_prog).'</td><td>'.htmlspecialchars(getNama($kode_prog, $nama_map)).'</td>
                    <td class="text-end">'.number_format($pt['pagu'],0,',','.').'</td><td class="text-end">'.number_format($pt['vol']).'</td>
                    <td class="text-center">'.formatPersen(100).'</td><td class="text-end">'.number_format($pt['real_pagu'],0,',','.').'</td>
                    <td class="text-center">'.formatPersen($prog_anggaran_persen).'</td><td class="text-end">'.number_format($pt['real_vol']).'</td>
                    <td class="text-center">'.formatPersen($prog_fisik).'</td><td class="text-center">'.formatPersen($all_prog_tertimbang[$kode_prog]).'</td></tr>';

                foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data) {
                    $kt = $keg_totals_all[$kode_keg];
                    $keg_fisik = $kt['vol'] ? ($kt['real_vol'] / $kt['vol']) * 100 : 0;
                    $keg_anggaran_persen = $kt['pagu'] ? ($kt['real_pagu'] / $kt['pagu']) * 100 : 0;

                    $html .= '<tr class="table-secondary"><td>'.htmlspecialchars($kode_keg).'</td><td>'.htmlspecialchars(getNama($kode_keg, $nama_map)).'</td>
                        <td class="text-end">'.number_format($kt['pagu'],0,',','.').'</td><td class="text-end">'.number_format($kt['vol']).'</td>
                        <td class="text-center">'.formatPersen(100).'</td><td class="text-end">'.number_format($kt['real_pagu'],0,',','.').'</td>
                        <td class="text-center">'.formatPersen($keg_anggaran_persen).'</td><td class="text-end">'.number_format($kt['real_vol']).'</td>
                        <td class="text-center">'.formatPersen($keg_fisik).'</td><td class="text-center">'.formatPersen($all_keg_tertimbang[$kode_keg]).'</td></tr>';

                    foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
                        $st = $subkeg_totals_all[$kode_sub];
                        $sub_fisik = $st['vol'] ? ($st['real_vol'] / $st['vol']) * 100 : 0;
                        $sub_anggaran_persen = $st['pagu'] ? ($st['real_pagu'] / $st['pagu']) * 100 : 0;

                        $html .= '<tr class="table-light"><td>'.htmlspecialchars($kode_sub).'</td><td>'.htmlspecialchars(getNama($kode_sub, $nama_map)).'</td>
                            <td class="text-end">'.number_format($st['pagu'],0,',','.').'</td><td class="text-end">'.number_format($st['vol']).'</td>
                            <td class="text-center">'.formatPersen(100).'</td><td class="text-end">'.number_format($st['real_pagu'],0,',','.').'</td>
                            <td class="text-center">'.formatPersen($sub_anggaran_persen).'</td><td class="text-end">'.number_format($st['real_vol']).'</td>
                            <td class="text-center">'.formatPersen($sub_fisik).'</td><td class="text-center">'.formatPersen($all_sub_tertimbang[$kode_sub]).'</td></tr>';

                        foreach ($sub_data['rincian'] as $rincian) {
                            $tp = $rincian['total_pagu']; $rp = $rincian['akum_realisasi_pagu'];
                            $tv = $rincian['total_volume']; $rv = $rincian['akum_realisasi_volume'];
                            $ang_persen = $tp ? ($rp / $tp) * 100 : 0;
                            $fisik = $tv ? ($rv / $tv) * 100 : 0;
                            $bobot = $st['pagu'] ? ($tp / $st['pagu']) * 100 : 0;
                            $tertimbang_val = $fisik * $bobot / 100;
                            $grand_pagu += $tp; $grand_vol += $tv; $grand_real_pagu += $rp; $grand_real_vol += $rv;

                            $html .= '<tr><td>'.htmlspecialchars($rincian['kode_rincian']).'</td><td>'.htmlspecialchars($rincian['nama_rincian']).'</td>
                                <td class="text-end">'.number_format($tp,0,',','.').'</td><td class="text-end">'.number_format($tv).'</td>
                                <td class="text-center">'.formatPersen($bobot).'</td><td class="text-end">'.number_format($rp,0,',','.').'</td>
                                <td class="text-center">'.formatPersen($ang_persen).'</td><td class="text-end">'.number_format($rv).'</td>
                                <td class="text-center">'.formatPersen($fisik).'</td><td class="text-center">'.formatPersen($tertimbang_val).'</td></tr>';
                        }
                    }
                }
            }

            $grand_fisik = $grand_vol ? ($grand_real_vol / $grand_vol) * 100 : 0;
            $grand_anggaran_persen = $grand_pagu ? ($grand_real_pagu / $grand_pagu) * 100 : 0;
            $html .= '<tr class="grand-total"><td colspan="2" class="text-center"><strong>TOTAL</strong></td>
                <td class="text-end"><strong>'.number_format($grand_pagu,0,',','.').'</strong></td>
                <td class="text-end"><strong>'.number_format($grand_vol).'</strong></td>
                <td class="text-center"><strong>'.formatPersen(100).'</strong></td>
                <td class="text-end"><strong>'.number_format($grand_real_pagu,0,',','.').'</strong></td>
                <td class="text-center"><strong>'.formatPersen($grand_anggaran_persen).'</strong></td>
                <td class="text-end"><strong>'.number_format($grand_real_vol).'</strong></td>
                <td class="text-center"><strong>'.formatPersen($grand_fisik).'</strong></td>
                <td class="text-center"><strong>'.formatPersen($grand_all_tertimbang).'</strong></td></tr>';
            $html .= '</tbody></table>';
        }

        $html .= '</body></html>';

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        ob_end_clean();
        $nama_opd_file = ($opd_id > 0) ? $dinas_nama : 'Semua_OPD';
        $tanggal_realtime = date('d') . '_' . $bulan_indonesia[date('n')-1] . '_' . date('Y');
        $nama_file = 'rincian_belanja_' . str_replace(' ', '_', $nama_opd_file) . '_' . $tanggal_realtime . '.pdf';
        $dompdf->stream($nama_file, ['Attachment' => 1]);
        exit;
    }
}
// ===================== TAMPILAN HTML =====================
?>

<style>
    .table-uraian td, .table-uraian th { white-space: normal; word-break: break-word; vertical-align: middle !important; }
    .text-end { text-align: right; }
    .text-center { text-align: center; }
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
    .uraian-cell { max-width: 300px; word-wrap: break-word; white-space: normal; }
    .nowrap-cell { white-space: nowrap; }
</style>

<div class="d-flex" style="max-width: 100vw; overflow-x: auto; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <h3><i class="bi bi-receipt"></i> Laporan Rincian Belanja</h3>
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <input type="hidden" name="filter" value="1">
                        <div class="col-md-3">
                            <label class="form-label">OPD</label>
                            <div class="opd-dropdown-container">
                                <input type="text" id="opd-search-input" class="form-control" placeholder="Cari OPD..." autocomplete="off"
                                       value="<?= ($show_table && $opd_id>0) ? htmlspecialchars($dinas_nama) : '' ?>">
                                <input type="hidden" name="opd_id" id="opd-id-hidden" value="<?= $opd_id ?>">
                                <div id="opd-dropdown" class="opd-dropdown-menu"></div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label>Tahun</label>
                            <select name="tahun" class="form-select">
                                <?php for($y=date('Y')-2; $y<=date('Y')+1; $y++): ?>
                                    <option value="<?= $y ?>" <?= $tahun==$y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Bulan</label>
                            <select name="bulan" class="form-select">
                                <?php for($i=1;$i<=12;$i++): ?>
                                    <option value="<?= $i ?>" <?= $bulan==$i ? 'selected' : '' ?>><?= $bulan_indonesia[$i-1] ?></option>
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
                <div class="alert alert-info text-center">Silakan pilih OPD dan periode, lalu klik <strong>Tampilkan</strong>.</div>
            <?php else: ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Laporan Rincian Belanja - <?= $bulan_indonesia[$bulan-1] ?> <?= $tahun ?> <?= ($opd_id>0) ? ' | OPD: '.htmlspecialchars($dinas_nama) : ' | Semua OPD' ?></span>
                        <div>
                            <a href="?filter=1&opd_id=<?= $opd_id ?>&tahun=<?= $tahun ?>&bulan=<?= $bulan ?>&export=excel" class="btn btn-sm btn-success me-1"><i class="bi bi-file-excel"></i> Excel</a>
                            <a href="?filter=1&opd_id=<?= $opd_id ?>&tahun=<?= $tahun ?>&bulan=<?= $bulan ?>&export=pdf" class="btn btn-sm btn-danger" target="_blank"><i class="bi bi-file-pdf"></i> PDF</a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0 table-uraian">
                                <thead class="table-light">
                                    <tr>
                                        <th rowspan="2" class="text-center align-middle" style="width:10%">Kode Rekening</th>
                                        <th rowspan="2" class="text-center align-middle" style="width:30%">Uraian</th>
                                        <th colspan="2" class="text-center align-middle">Total</th>
                                        <th rowspan="2" class="text-center align-middle" style="width:7%">Bobot</th>
                                        <th colspan="2" class="text-center align-middle">Realisasi Anggaran</th>
                                        <th colspan="3" class="text-center align-middle">Realisasi Fisik</th>
                                    </tr>
                                    <tr>
                                        <th class="text-center align-middle">Anggaran (Rp)</th>
                                        <th class="text-center align-middle">Volume</th>
                                        <th class="text-center align-middle">Rp</th>
                                        <th class="text-center align-middle">%</th>
                                        <th class="text-center align-middle">Volume</th>
                                        <th class="text-center align-middle">Fisik (%)</th>
                                        <th class="text-center align-middle">Tertimbang (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($opd_id > 0): ?>
                                    <?php
                                    $grand_pagu = $grand_vol = $grand_real_pagu = $grand_real_vol = 0;
                                    foreach ($all_ids as $oid):
                                        $section = $sections[$oid] ?? null;
                                        if (!$section) continue;
                                        $is_dinas = ($oid == $opd_id);
                                        $label = $is_dinas ? 'DINAS INDUK' : 'UPTD';
                                    ?>
                                        <tr class="section-header">
                                            <td colspan="10"><strong><?= $label ?>: <?= htmlspecialchars($section['nama']) ?></strong></td>
                                        </tr>
                                        <?php
                                        $programs = $section['programs'];
                                        $prog_totals = $section['prog_totals'];
                                        $keg_totals = $section['keg_totals'];
                                        $sub_totals = $section['sub_totals'];
                                        $tertimbang = $section['tertimbang'];

                                        foreach ($programs as $kode_prog => $prog_data):
                                            $pt = $prog_totals[$kode_prog];
                                            $prog_fisik = $pt['vol'] ? ($pt['real_vol'] / $pt['vol']) * 100 : 0;
                                            $prog_anggaran_persen = $pt['pagu'] ? ($pt['real_pagu'] / $pt['pagu']) * 100 : 0;
                                    ?>
                                        <tr class="table-primary fw-bold">
                                            <td class="nowrap-cell"><?= htmlspecialchars($kode_prog) ?></td>
                                            <td class="uraian-cell"><?= htmlspecialchars(getNama($kode_prog, $nama_map)) ?></td>
                                            <td class="text-end"><?= number_format($pt['pagu'],0,',','.') ?></td>
                                            <td class="text-end"><?= number_format($pt['vol']) ?></td>
                                            <td class="text-center"><?= formatPersen(100) ?></td>
                                            <td class="text-end"><?= number_format($pt['real_pagu'],0,',','.') ?></td>
                                            <td class="text-center"><?= formatPersen($prog_anggaran_persen) ?></td>
                                            <td class="text-end"><?= number_format($pt['real_vol']) ?></td>
                                            <td class="text-center"><?= formatPersen($prog_fisik) ?></td>
                                            <td class="text-center"><?= formatPersen($tertimbang['prog'][$kode_prog]) ?></td>
                                        </tr>
                                        <?php foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data):
                                            $kt = $keg_totals[$kode_keg];
                                            $keg_fisik = $kt['vol'] ? ($kt['real_vol'] / $kt['vol']) * 100 : 0;
                                            $keg_anggaran_persen = $kt['pagu'] ? ($kt['real_pagu'] / $kt['pagu']) * 100 : 0;
                                        ?>
                                            <tr class="table-info">
                                                <td class="nowrap-cell"><?= htmlspecialchars($kode_keg) ?></td>
                                                <td class="uraian-cell"><?= htmlspecialchars(getNama($kode_keg, $nama_map)) ?></td>
                                                <td class="text-end"><?= number_format($kt['pagu'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($kt['vol']) ?></td>
                                                <td class="text-center"><?= formatPersen(100) ?></td>
                                                <td class="text-end"><?= number_format($kt['real_pagu'],0,',','.') ?></td>
                                                <td class="text-center"><?= formatPersen($keg_anggaran_persen) ?></td>
                                                <td class="text-end"><?= number_format($kt['real_vol']) ?></td>
                                                <td class="text-center"><?= formatPersen($keg_fisik) ?></td>
                                                <td class="text-center"><?= formatPersen($tertimbang['keg'][$kode_keg]) ?></td>
                                            </tr>
                                            <?php foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data):
                                                $st = $sub_totals[$kode_sub];
                                                $sub_fisik = $st['vol'] ? ($st['real_vol'] / $st['vol']) * 100 : 0;
                                                $sub_anggaran_persen = $st['pagu'] ? ($st['real_pagu'] / $st['pagu']) * 100 : 0;
                                            ?>
                                                <tr class="table-warning">
                                                    <td class="nowrap-cell"><?= htmlspecialchars($kode_sub) ?></td>
                                                    <td class="uraian-cell"><?= htmlspecialchars(getNama($kode_sub, $nama_map)) ?></td>
                                                    <td class="text-end"><?= number_format($st['pagu'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($st['vol']) ?></td>
                                                    <td class="text-center"><?= formatPersen(100) ?></td>
                                                    <td class="text-end"><?= number_format($st['real_pagu'],0,',','.') ?></td>
                                                    <td class="text-center"><?= formatPersen($sub_anggaran_persen) ?></td>
                                                    <td class="text-end"><?= number_format($st['real_vol']) ?></td>
                                                    <td class="text-center"><?= formatPersen($sub_fisik) ?></td>
                                                    <td class="text-center"><?= formatPersen($tertimbang['sub'][$kode_sub]) ?></td>
                                                </tr>
                                                <?php foreach ($sub_data['rincian'] as $rincian):
                                                    $tp = $rincian['total_pagu']; $rp = $rincian['akum_realisasi_pagu'];
                                                    $tv = $rincian['total_volume']; $rv = $rincian['akum_realisasi_volume'];
                                                    $ang_persen = $tp ? ($rp / $tp) * 100 : 0;
                                                    $fisik = $tv ? ($rv / $tv) * 100 : 0;
                                                    $bobot = $st['pagu'] ? ($tp / $st['pagu']) * 100 : 0;
                                                    $tertimbang_val = $fisik * $bobot / 100;
                                                    $grand_pagu += $tp; $grand_vol += $tv; $grand_real_pagu += $rp; $grand_real_vol += $rv;
                                                ?>
                                                    <tr>
                                                        <td class="nowrap-cell"><?= htmlspecialchars($rincian['kode_rincian']) ?></td>
                                                        <td class="uraian-cell"><?= htmlspecialchars($rincian['nama_rincian']) ?></td>
                                                        <td class="text-end"><?= number_format($tp,0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($tv) ?></td>
                                                        <td class="text-center"><?= formatPersen($bobot) ?></td>
                                                        <td class="text-end"><?= number_format($rp,0,',','.') ?></td>
                                                        <td class="text-center"><?= formatPersen($ang_persen) ?></td>
                                                        <td class="text-end"><?= number_format($rv) ?></td>
                                                        <td class="text-center"><?= formatPersen($fisik) ?></td>
                                                        <td class="text-center"><?= formatPersen($tertimbang_val) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                    <tr class="table-dark fw-bold">
                                        <td colspan="2" class="text-center">TOTAL KESELURUHAN</td>
                                        <td class="text-end"><?= number_format($grand_pagu,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($grand_vol) ?></td>
                                        <td class="text-center"><?= formatPersen(100) ?></td>
                                        <td class="text-end"><?= number_format($grand_real_pagu,0,',','.') ?></td>
                                        <td class="text-center"><?= formatPersen($grand_pagu ? ($grand_real_pagu/$grand_pagu)*100 : 0) ?></td>
                                        <td class="text-end"><?= number_format($grand_real_vol) ?></td>
                                        <td class="text-center"><?= formatPersen($grand_vol ? ($grand_real_vol/$grand_vol)*100 : 0) ?></td>
                                        <td class="text-center"><?= formatPersen($total_tertimbang_keseluruhan) ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php
                                    $grand_pagu = $grand_vol = $grand_real_pagu = $grand_real_vol = 0;
                                    foreach ($programs_all as $kode_prog => $prog_data):
                                        $pt = $prog_totals_all[$kode_prog];
                                        $prog_fisik = $pt['vol'] ? ($pt['real_vol'] / $pt['vol']) * 100 : 0;
                                    ?>
                                        <tr class="table-primary fw-bold">
                                            <td class="nowrap-cell"><?= htmlspecialchars($kode_prog) ?></td>
                                            <td class="uraian-cell"><?= htmlspecialchars(getNama($kode_prog, $nama_map)) ?></td>
                                            <td class="text-end"><?= number_format($pt['pagu'],0,',','.') ?></td>
                                            <td class="text-end"><?= number_format($pt['vol']) ?></td>
                                            <td class="text-center"><?= formatPersen(100) ?></td>
                                            <td class="text-end"><?= number_format($pt['real_pagu'],0,',','.') ?></td>
                                            <td class="text-center"><?= formatPersen($pt['pagu'] ? ($pt['real_pagu']/$pt['pagu'])*100 : 0) ?></td>
                                            <td class="text-end"><?= number_format($pt['real_vol']) ?></td>
                                            <td class="text-center"><?= formatPersen($prog_fisik) ?></td>
                                            <td class="text-center"><?= formatPersen($all_prog_tertimbang[$kode_prog]) ?></td>
                                        </tr>
                                        <?php foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data):
                                            $kt = $keg_totals_all[$kode_keg];
                                            $keg_fisik = $kt['vol'] ? ($kt['real_vol'] / $kt['vol']) * 100 : 0;
                                        ?>
                                            <tr class="table-info">
                                                <td class="nowrap-cell"><?= htmlspecialchars($kode_keg) ?></td>
                                                <td class="uraian-cell"><?= htmlspecialchars(getNama($kode_keg, $nama_map)) ?></td>
                                                <td class="text-end"><?= number_format($kt['pagu'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($kt['vol']) ?></td>
                                                <td class="text-center"><?= formatPersen(100) ?></td>
                                                <td class="text-end"><?= number_format($kt['real_pagu'],0,',','.') ?></td>
                                                <td class="text-center"><?= formatPersen($kt['pagu'] ? ($kt['real_pagu']/$kt['pagu'])*100 : 0) ?></td>
                                                <td class="text-end"><?= number_format($kt['real_vol']) ?></td>
                                                <td class="text-center"><?= formatPersen($keg_fisik) ?></td>
                                                <td class="text-center"><?= formatPersen($all_keg_tertimbang[$kode_keg]) ?></td>
                                            </tr>
                                            <?php foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data):
                                                $st = $subkeg_totals_all[$kode_sub];
                                                $sub_fisik = $st['vol'] ? ($st['real_vol'] / $st['vol']) * 100 : 0;
                                            ?>
                                                <tr class="table-warning">
                                                    <td class="nowrap-cell"><?= htmlspecialchars($kode_sub) ?></td>
                                                    <td class="uraian-cell"><?= htmlspecialchars(getNama($kode_sub, $nama_map)) ?></td>
                                                    <td class="text-end"><?= number_format($st['pagu'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($st['vol']) ?></td>
                                                    <td class="text-center"><?= formatPersen(100) ?></td>
                                                    <td class="text-end"><?= number_format($st['real_pagu'],0,',','.') ?></td>
                                                    <td class="text-center"><?= formatPersen($st['pagu'] ? ($st['real_pagu']/$st['pagu'])*100 : 0) ?></td>
                                                    <td class="text-end"><?= number_format($st['real_vol']) ?></td>
                                                    <td class="text-center"><?= formatPersen($sub_fisik) ?></td>
                                                    <td class="text-center"><?= formatPersen($all_sub_tertimbang[$kode_sub]) ?></td>
                                                </tr>
                                                <?php foreach ($sub_data['rincian'] as $rincian):
                                                    $tp = $rincian['total_pagu']; $rp = $rincian['akum_realisasi_pagu'];
                                                    $tv = $rincian['total_volume']; $rv = $rincian['akum_realisasi_volume'];
                                                    $ang_persen = $tp ? ($rp / $tp) * 100 : 0;
                                                    $fisik = $tv ? ($rv / $tv) * 100 : 0;
                                                    $bobot = $st['pagu'] ? ($tp / $st['pagu']) * 100 : 0;
                                                    $tertimbang_val = $fisik * $bobot / 100;
                                                    $grand_pagu += $tp; $grand_vol += $tv; $grand_real_pagu += $rp; $grand_real_vol += $rv;
                                                ?>
                                                    <tr>
                                                        <td class="nowrap-cell"><?= htmlspecialchars($rincian['kode_rincian']) ?></td>
                                                        <td class="uraian-cell"><?= htmlspecialchars($rincian['nama_rincian']) ?></td>
                                                        <td class="text-end"><?= number_format($tp,0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($tv) ?></td>
                                                        <td class="text-center"><?= formatPersen($bobot) ?></td>
                                                        <td class="text-end"><?= number_format($rp,0,',','.') ?></td>
                                                        <td class="text-center"><?= formatPersen($ang_persen) ?></td>
                                                        <td class="text-end"><?= number_format($rv) ?></td>
                                                        <td class="text-center"><?= formatPersen($fisik) ?></td>
                                                        <td class="text-center"><?= formatPersen($tertimbang_val) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <tr class="table-dark fw-bold">
                                        <td colspan="2" class="text-center">TOTAL</td>
                                        <td class="text-end"><?= number_format($grand_pagu,0,',','.') ?></td>
                                        <td class="text-end"><?= number_format($grand_vol) ?></td>
                                        <td class="text-center"><?= formatPersen(100) ?></td>
                                        <td class="text-end"><?= number_format($grand_real_pagu,0,',','.') ?></td>
                                        <td class="text-center"><?= formatPersen($grand_pagu ? ($grand_real_pagu/$grand_pagu)*100 : 0) ?></td>
                                        <td class="text-end"><?= number_format($grand_real_vol) ?></td>
                                        <td class="text-center"><?= formatPersen($grand_vol ? ($grand_real_vol/$grand_vol)*100 : 0) ?></td>
                                        <td class="text-center"><?= formatPersen($grand_all_tertimbang) ?></td>
                                    </tr>
                                <?php endif; ?>
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