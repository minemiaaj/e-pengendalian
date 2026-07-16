<?php
/**
 * rincian_belanja_opd.php - Laporan Rincian Belanja per OPD
 * 
 * Keamanan:
 * - Semua query database menggunakan prepared statement (anti SQL injection)
 * - Validasi parameter GET (tahun, bulan, export)
 * - Proteksi path traversal pada file TTD
 * - Output escaping dengan htmlspecialchars()
 * - Header ekspor yang aman
 */

// ---- DEBUGGING: tampilkan semua error (hapus/komentari untuk production) ----
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../includes/header.php';

// ========== AUTOLOAD ==========
require_once __DIR__ . '/../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Dompdf\Dompdf;
use Dompdf\Options;

// ========== OTORISASI ==========
if (!in_array($_SESSION['role'], ['admin_opd', 'kepala_opd'])) {
    header('Location: ../dashboard/index.php');
    exit();
}

$opd_id = (int) $_SESSION['opd_id'];
$tahun  = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');
$bulan  = isset($_GET['bulan']) ? (int) $_GET['bulan'] : (int) date('n');
$allowed_exports = ['excel', 'pdf', ''];
$export = isset($_GET['export']) && in_array($_GET['export'], $allowed_exports, true) ? $_GET['export'] : '';

// Validasi rentang tahun & bulan
if ($tahun < 2000 || $tahun > 2100) $tahun = (int) date('Y');
if ($bulan < 1 || $bulan > 12) $bulan = (int) date('n');

$bulan_keys      = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
$bulan_indonesia = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

// ========== HELPER FUNCTIONS ==========

/**
 * Ambil data kepala OPD (nama, ttd path) dengan prepared statement.
 */
function getDataKepalaOpd($conn, $opd_id) {
    $data = [
        'ttd_path'    => null,
        'nama_kepala' => 'KEPALA OPD',
        'nama_opd'    => ''
    ];
    if ($opd_id <= 0) return $data;

    // Ambil nama OPD dan path TTD dari tabel opd
    $stmt = $conn->prepare("SELECT ttd, nama_opd FROM opd WHERE id = ?");
    $stmt->bind_param('i', $opd_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $data['ttd_path'] = $row['ttd'];
        $data['nama_opd'] = $row['nama_opd'];
    }
    $stmt->close();

    // Ambil nama kepala OPD dari users
    $stmt = $conn->prepare("SELECT nama_lengkap FROM users WHERE opd_id = ? AND role = 'kepala_opd' LIMIT 1");
    $stmt->bind_param('i', $opd_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if (($row = $res->fetch_assoc()) && !empty($row['nama_lengkap'])) {
        $data['nama_kepala'] = $row['nama_lengkap'];
    }
    $stmt->close();

    return $data;
}

// ========== BANGUN QUERY UTAMA DENGAN PREPARED STATEMENT ==========
// Ekspresi akumulasi realisasi berdasarkan status (hanya bulan <= bulan ini)
$akum_pagu_parts = [];
$akum_vol_parts  = [];
for ($i = 1; $i <= $bulan; $i++) {
    $bkey = $bulan_keys[$i - 1];
    $akum_pagu_parts[] = "CASE WHEN r.status_$bkey IN ('divalidasi','dikunci') THEN COALESCE(r.pagu_$bkey, 0) ELSE 0 END";
    $akum_vol_parts[]  = "CASE WHEN r.status_$bkey IN ('divalidasi','dikunci') THEN COALESCE(r.volume_$bkey, 0) ELSE 0 END";
}
$akum_pagu_expr = implode(' + ', $akum_pagu_parts);
$akum_vol_expr  = implode(' + ', $akum_vol_parts);

$query = "
    SELECT 
        a.kode_program,
        a.kode_kegiatan,
        a.kode_sub_kegiatan,
        mb.kode AS kode_rincian,
        mb.nama AS nama_rincian,
        a.total_volume,
        a.total_pagu,
        ($akum_vol_expr) AS akum_realisasi_volume,
        ($akum_pagu_expr) AS akum_realisasi_pagu
    FROM anggaran_detail a
    JOIN master_belanja mb ON a.rincian_belanja_id = mb.id
    LEFT JOIN realisasi_detail r 
        ON a.id = r.anggaran_detail_id 
        AND r.opd_id = a.opd_id 
        AND r.tahun = a.tahun
    WHERE a.opd_id = ? 
      AND a.tahun = ? 
      AND a.status_validasi = 'dikunci'
      AND a.versi = (
          SELECT MAX(versi) 
          FROM anggaran_detail a2 
          WHERE a2.opd_id = a.opd_id 
            AND a2.tahun = a.tahun 
            AND a2.kode_program = a.kode_program 
            AND a2.kode_kegiatan = a.kode_kegiatan 
            AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan 
            AND a2.rincian_belanja_id = a.rincian_belanja_id
            AND a2.status_validasi = 'dikunci'
      )
    ORDER BY a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan, mb.kode
";
$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $opd_id, $tahun);
$stmt->execute();
$result = $stmt->get_result();

// Susun data hierarki
$programs = [];
$all_codes = [];
while ($row = $result->fetch_assoc()) {
    $prog = $row['kode_program'];
    $keg  = $row['kode_kegiatan'];
    $sub  = $row['kode_sub_kegiatan'];
    $programs[$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub]['rincian'][] = $row;
    $all_codes[] = $prog;
    $all_codes[] = $keg;
    $all_codes[] = $sub;
}
$stmt->close();
$all_codes = array_unique($all_codes);

// ========== AMBIL NAMA HIERARKI DARI master_hierarki (PREPARED) ==========
$nama_map = [];
if (!empty($all_codes)) {
    // Bangun kode dengan fallback placeholder X.XX
    $query_codes = [];
    foreach ($all_codes as $code) {
        $query_codes[] = $code;
        if (preg_match('/^(\d+\.\d+)\.(.+)$/', $code, $m)) {
            $query_codes[] = 'X.XX.' . $m[2];
        }
    }
    $query_codes = array_unique($query_codes);
    $placeholders = implode(',', array_fill(0, count($query_codes), '?'));
    $types = str_repeat('s', count($query_codes));
    
    $stmt_nama = $conn->prepare("SELECT kode, nama FROM master_hierarki WHERE kode IN ($placeholders)");
    $stmt_nama->bind_param($types, ...$query_codes);
    $stmt_nama->execute();
    $res_nama = $stmt_nama->get_result();
    $all_names = [];
    while ($nm = $res_nama->fetch_assoc()) {
        $all_names[$nm['kode']] = $nm['nama'];
    }
    $stmt_nama->close();
    
    // Mapping kode asli ke nama
    foreach ($all_codes as $code) {
        if (isset($all_names[$code])) {
            $nama_map[$code] = $all_names[$code];
        } else {
            $placeholder = preg_replace('/^\d+\.\d+\./', 'X.XX.', $code);
            $nama_map[$code] = $all_names[$placeholder] ?? '(tanpa uraian)';
        }
    }
}

// ========== AGREGASI PER SUB/KEG/PROG (sama seperti aslinya) ==========
$subkeg_totals = [];
$keg_totals    = [];
$prog_totals   = [];
foreach ($programs as $prog => $pdata) {
    $prog_totals[$prog] = ['pagu' => 0, 'vol' => 0, 'real_pagu' => 0, 'real_vol' => 0];
    foreach ($pdata['kegiatan'] as $keg => $kdata) {
        $keg_totals[$keg] = ['pagu' => 0, 'vol' => 0, 'real_pagu' => 0, 'real_vol' => 0];
        foreach ($kdata['sub_kegiatan'] as $sub => $sdata) {
            $subkeg_totals[$sub] = ['pagu' => 0, 'vol' => 0, 'real_pagu' => 0, 'real_vol' => 0];
            foreach ($sdata['rincian'] as $rincian) {
                $subkeg_totals[$sub]['pagu']      += $rincian['total_pagu'];
                $subkeg_totals[$sub]['vol']       += $rincian['total_volume'];
                $subkeg_totals[$sub]['real_pagu'] += $rincian['akum_realisasi_pagu'];
                $subkeg_totals[$sub]['real_vol']  += $rincian['akum_realisasi_volume'];
            }
            $keg_totals[$keg]['pagu']      += $subkeg_totals[$sub]['pagu'];
            $keg_totals[$keg]['vol']       += $subkeg_totals[$sub]['vol'];
            $keg_totals[$keg]['real_pagu'] += $subkeg_totals[$sub]['real_pagu'];
            $keg_totals[$keg]['real_vol']  += $subkeg_totals[$sub]['real_vol'];
        }
        $prog_totals[$prog]['pagu']      += $keg_totals[$keg]['pagu'];
        $prog_totals[$prog]['vol']       += $keg_totals[$keg]['vol'];
        $prog_totals[$prog]['real_pagu'] += $keg_totals[$keg]['real_pagu'];
        $prog_totals[$prog]['real_vol']  += $keg_totals[$keg]['real_vol'];
    }
}

// ========== PERHITUNGAN TERTIMBANG ==========
$sub_tertimbang = [];
$keg_tertimbang = [];
$prog_tertimbang = [];

foreach ($programs as $kode_prog => $prog_data) {
    $keg_tertimbang_list = [];
    foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data) {
        $sub_tertimbang_list = [];
        foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
            $sum = 0;
            $st  = $subkeg_totals[$kode_sub];
            foreach ($sub_data['rincian'] as $rincian) {
                $target_pag = $rincian['total_pagu'];
                $bobot = $st['pagu'] ? ($target_pag / $st['pagu']) * 100 : 0;
                $fisik = $rincian['total_volume'] ? ($rincian['akum_realisasi_volume'] / $rincian['total_volume']) * 100 : 0;
                $sum += $fisik * $bobot / 100;
            }
            $sub_tertimbang[$kode_sub] = $sum;
            $sub_tertimbang_list[] = $sum;
        }
        $keg_tertimbang[$kode_keg] = count($sub_tertimbang_list) > 0
            ? array_sum($sub_tertimbang_list) / count($sub_tertimbang_list)
            : 0;
        $keg_tertimbang_list[] = $keg_tertimbang[$kode_keg];
    }
    $prog_tertimbang[$kode_prog] = count($keg_tertimbang_list) > 0
        ? array_sum($keg_tertimbang_list) / count($keg_tertimbang_list)
        : 0;
}
$grand_tertimbang = count($prog_tertimbang) > 0
    ? array_sum($prog_tertimbang) / count($prog_tertimbang)
    : 0;

// ========== FUNGSI TAMPILAN ==========
function getNama($kode, $map) {
    return $map[$kode] ?? '(tanpa uraian)';
}
function formatPersen($value) {
    return number_format($value, 2, ',', '.') . '%';
}

// ========== AMBIL NAMA OPD UNTUK TAMPILAN & EKSPOR ==========
$nama_opd = '';
$stmt_opd = $conn->prepare("SELECT nama_opd FROM opd WHERE id = ?");
$stmt_opd->bind_param('i', $opd_id);
$stmt_opd->execute();
$res_opd = $stmt_opd->get_result();
if ($r = $res_opd->fetch_assoc()) {
    $nama_opd = $r['nama_opd'];
}
$stmt_opd->close();

// ========== EKSPOR EXCEL ==========
if ($export === 'excel' && !empty($programs)) {
    // Bersihkan output buffer
    if (ob_get_length()) ob_end_clean();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Rincian Belanja');

    // Judul
    $sheet->setCellValue('A1', 'Laporan Rincian Belanja');
    $sheet->mergeCells('A1:J1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->setCellValue('A2', $bulan_indonesia[$bulan-1].' '.$tahun.' | OPD: '.$nama_opd.' (Akumulasi s.d. '.$bulan_indonesia[$bulan-1].')');
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
        $sheet->setCellValue('J'.$rowNum, formatPersen($prog_tertimbang[$kode_prog]));
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
            $sheet->setCellValue('J'.$rowNum, formatPersen($keg_tertimbang[$kode_keg]));
            $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E3E5');
            $rowNum++;

            foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data) {
                $st = $subkeg_totals[$kode_sub];
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
                $sheet->setCellValue('J'.$rowNum, formatPersen($sub_tertimbang[$kode_sub]));
                $sheet->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
                $rowNum++;

                foreach ($sub_data['rincian'] as $rincian) {
                    $target_pag = $rincian['total_pagu'];
                    $real_pag   = $rincian['akum_realisasi_pagu'];
                    $target_vol = $rincian['total_volume'];
                    $real_vol   = $rincian['akum_realisasi_volume'];
                    $anggaran_persen = $target_pag ? ($real_pag / $target_pag) * 100 : 0;
                    $fisik = $target_vol ? ($real_vol / $target_vol) * 100 : 0;
                    $bobot = $st['pagu'] ? ($target_pag / $st['pagu']) * 100 : 0;
                    $tertimbang = $fisik * $bobot / 100;

                    $grand_pagu      += $target_pag;
                    $grand_vol       += $target_vol;
                    $grand_real_pagu += $real_pag;
                    $grand_real_vol  += $real_vol;

                    $sheet->setCellValue('A'.$rowNum, $rincian['kode_rincian']);
                    $sheet->setCellValue('B'.$rowNum, $rincian['nama_rincian']);
                    $sheet->setCellValue('C'.$rowNum, $target_pag);
                    $sheet->setCellValue('D'.$rowNum, $target_vol);
                    $sheet->setCellValue('E'.$rowNum, formatPersen($bobot));
                    $sheet->setCellValue('F'.$rowNum, $real_pag);
                    $sheet->setCellValue('G'.$rowNum, formatPersen($anggaran_persen));
                    $sheet->setCellValue('H'.$rowNum, $real_vol);
                    $sheet->setCellValue('I'.$rowNum, formatPersen($fisik));
                    $sheet->setCellValue('J'.$rowNum, formatPersen($tertimbang));
                    $rowNum++;
                }
            }
        }
    }

    // Total Grand
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
    $sheet->setCellValue('J'.$rowNum, formatPersen($grand_tertimbang));
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
    foreach (range('A','J') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

    // TTD Kepala OPD (proteksi path traversal)
    $kepalaData = getDataKepalaOpd($conn, $opd_id);
    $rowTtd = $rowNum + 3;
    $tgl_sekarang = date('d') . ' ' . $bulan_indonesia[date('n')-1] . ' ' . date('Y');
    $sheet->mergeCells('J'.$rowTtd.':J'.($rowTtd+4));
    $ttdText = "Kendari, {$tgl_sekarang}\nKEPALA " . strtoupper($kepalaData['nama_opd']) . "\n\n\n(" . $kepalaData['nama_kepala'] . ")";
    $sheet->setCellValue('J'.$rowTtd, $ttdText);
    $sheet->getStyle('J'.$rowTtd)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('J'.$rowTtd)->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
    $sheet->getStyle('J'.$rowTtd)->getAlignment()->setWrapText(true);

    // Sisipkan TTD jika path valid
    $ttd_path = $kepalaData['ttd_path'];
    if ($ttd_path && preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $ttd_path) && strpos($ttd_path, '..') === false) {
        $full_path = __DIR__ . '/../../' . $ttd_path;
        if (file_exists($full_path)) {
            $drawing = new Drawing();
            $drawing->setName('TTD');
            $drawing->setDescription('Tanda Tangan Kepala OPD');
            $drawing->setPath($full_path);
            $drawing->setHeight(40);
            $drawing->setCoordinates('J' . ($rowTtd + 2));
            $drawing->setOffsetX(5);
            $drawing->setWorksheet($sheet);
        }
    }

    // Output Excel
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $tanggal_realtime = date('d') . '_' . $bulan_indonesia[date('n')-1] . '_' . date('Y');
    $nama_file = 'rincian_belanja_' . str_replace(' ', '_', $nama_opd) . '_' . $tanggal_realtime . '.xlsx';
    header('Content-Disposition: attachment; filename="' . $nama_file . '"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ========== EKSPOR PDF ==========
if ($export === 'pdf' && !empty($programs)) {
    if (ob_get_length()) ob_end_clean();

    $kepalaData = getDataKepalaOpd($conn, $opd_id);
    $tgl_sekarang = date('d') . ' ' . $bulan_indonesia[date('n')-1] . ' ' . date('Y');

    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #000; padding: 4px 6px; vertical-align: top; }
            th { background-color: #eee; font-weight: bold; vertical-align: middle; }
            .text-end { text-align: right; }
            .text-center { text-align: center; }
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
        </style>
    </head>
    <body>
        <h3>Laporan Rincian Belanja</h3>
        <div class="subtitle">' . $bulan_indonesia[$bulan-1] . ' ' . $tahun . ' | OPD: ' . htmlspecialchars($nama_opd) . ' (Akumulasi s.d. ' . $bulan_indonesia[$bulan-1] . ')</div>
        <table>
            <thead>
                <tr>
                    <th rowspan="2">Kode</th>
                    <th rowspan="2">Uraian</th>
                    <th colspan="2" class="text-center">Total</th>
                    <th rowspan="2" class="text-center">Bobot</th>
                    <th colspan="2" class="text-center">Realisasi Anggaran</th>
                    <th colspan="3" class="text-center">Realisasi Fisik</th>
                </tr>
                <tr>
                    <th class="text-end">Anggaran (Rp)</th>
                    <th class="text-end">Volume</th>
                    <th class="text-end">Rp</th>
                    <th class="text-center">%</th>
                    <th class="text-end">Volume</th>
                    <th class="text-center">Fisik (%)</th>
                    <th class="text-center">Tertimbang (%)</th>
                </tr>
            </thead>
            <tbody>';

    $grand_pagu = $grand_vol = $grand_real_pagu = $grand_real_vol = 0;

    foreach ($programs as $kode_prog => $prog_data):
        $pt = $prog_totals[$kode_prog];
        $prog_fisik = $pt['vol'] ? ($pt['real_vol'] / $pt['vol']) * 100 : 0;
        $prog_anggaran_persen = $pt['pagu'] ? ($pt['real_pagu'] / $pt['pagu']) * 100 : 0;

        $html .= '<tr class="table-primary fw-bold">
            <td>' . htmlspecialchars($kode_prog) . '</td>
            <td>' . htmlspecialchars(getNama($kode_prog, $nama_map)) . '</td>
            <td class="text-end">' . number_format($pt['pagu'], 0, ',', '.') . '</td>
            <td class="text-end">' . number_format($pt['vol']) . '</td>
            <td class="text-center">' . formatPersen(100) . '</td>
            <td class="text-end">' . number_format($pt['real_pagu'], 0, ',', '.') . '</td>
            <td class="text-center">' . formatPersen($prog_anggaran_persen) . '</td>
            <td class="text-end">' . number_format($pt['real_vol']) . '</td>
            <td class="text-center">' . formatPersen($prog_fisik) . '</td>
            <td class="text-center">' . formatPersen($prog_tertimbang[$kode_prog]) . '</td>
        </tr>';

        foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data):
            $kt = $keg_totals[$kode_keg];
            $keg_fisik = $kt['vol'] ? ($kt['real_vol'] / $kt['vol']) * 100 : 0;
            $keg_anggaran_persen = $kt['pagu'] ? ($kt['real_pagu'] / $kt['pagu']) * 100 : 0;

            $html .= '<tr class="table-secondary">
                <td>' . htmlspecialchars($kode_keg) . '</td>
                <td>' . htmlspecialchars(getNama($kode_keg, $nama_map)) . '</td>
                <td class="text-end">' . number_format($kt['pagu'], 0, ',', '.') . '</td>
                <td class="text-end">' . number_format($kt['vol']) . '</td>
                <td class="text-center">' . formatPersen(100) . '</td>
                <td class="text-end">' . number_format($kt['real_pagu'], 0, ',', '.') . '</td>
                <td class="text-center">' . formatPersen($keg_anggaran_persen) . '</td>
                <td class="text-end">' . number_format($kt['real_vol']) . '</td>
                <td class="text-center">' . formatPersen($keg_fisik) . '</td>
                <td class="text-center">' . formatPersen($keg_tertimbang[$kode_keg]) . '</td>
            </tr>';

            foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data):
                $st = $subkeg_totals[$kode_sub];
                $sub_fisik = $st['vol'] ? ($st['real_vol'] / $st['vol']) * 100 : 0;
                $sub_anggaran_persen = $st['pagu'] ? ($st['real_pagu'] / $st['pagu']) * 100 : 0;

                $html .= '<tr class="table-light">
                    <td>' . htmlspecialchars($kode_sub) . '</td>
                    <td>' . htmlspecialchars(getNama($kode_sub, $nama_map)) . '</td>
                    <td class="text-end">' . number_format($st['pagu'], 0, ',', '.') . '</td>
                    <td class="text-end">' . number_format($st['vol']) . '</td>
                    <td class="text-center">' . formatPersen(100) . '</td>
                    <td class="text-end">' . number_format($st['real_pagu'], 0, ',', '.') . '</td>
                    <td class="text-center">' . formatPersen($sub_anggaran_persen) . '</td>
                    <td class="text-end">' . number_format($st['real_vol']) . '</td>
                    <td class="text-center">' . formatPersen($sub_fisik) . '</td>
                    <td class="text-center">' . formatPersen($sub_tertimbang[$kode_sub]) . '</td>
                </tr>';

                foreach ($sub_data['rincian'] as $rincian):
                    $target_pag = $rincian['total_pagu'];
                    $real_pag   = $rincian['akum_realisasi_pagu'];
                    $target_vol = $rincian['total_volume'];
                    $real_vol   = $rincian['akum_realisasi_volume'];
                    $anggaran_persen = $target_pag ? ($real_pag / $target_pag) * 100 : 0;
                    $fisik = $target_vol ? ($real_vol / $target_vol) * 100 : 0;
                    $bobot = $st['pagu'] ? ($target_pag / $st['pagu']) * 100 : 0;
                    $tertimbang = $fisik * $bobot / 100;

                    $grand_pagu      += $target_pag;
                    $grand_vol       += $target_vol;
                    $grand_real_pagu += $real_pag;
                    $grand_real_vol  += $real_vol;

                    $html .= '<tr>
                        <td>' . htmlspecialchars($rincian['kode_rincian']) . '</td>
                        <td>' . htmlspecialchars($rincian['nama_rincian']) . '</td>
                        <td class="text-end">' . number_format($target_pag, 0, ',', '.') . '</td>
                        <td class="text-end">' . number_format($target_vol) . '</td>
                        <td class="text-center">' . formatPersen($bobot) . '</td>
                        <td class="text-end">' . number_format($real_pag, 0, ',', '.') . '</td>
                        <td class="text-center">' . formatPersen($anggaran_persen) . '</td>
                        <td class="text-end">' . number_format($real_vol) . '</td>
                        <td class="text-center">' . formatPersen($fisik) . '</td>
                        <td class="text-center">' . formatPersen($tertimbang) . '</td>
                    </tr>';
                endforeach;
            endforeach;
        endforeach;
    endforeach;

    if ($grand_pagu > 0 || $grand_vol > 0) {
        $grand_fisik = $grand_vol ? ($grand_real_vol / $grand_vol) * 100 : 0;
        $grand_anggaran_persen = $grand_pagu ? ($grand_real_pagu / $grand_pagu) * 100 : 0;
        $html .= '<tr class="grand-total">
            <td colspan="2" class="text-center"><strong>TOTAL</strong></td>
            <td class="text-end"><strong>' . number_format($grand_pagu, 0, ',', '.') . '</strong></td>
            <td class="text-end"><strong>' . number_format($grand_vol) . '</strong></td>
            <td class="text-center"><strong>' . formatPersen(100) . '</strong></td>
            <td class="text-end"><strong>' . number_format($grand_real_pagu, 0, ',', '.') . '</strong></td>
            <td class="text-center"><strong>' . formatPersen($grand_anggaran_persen) . '</strong></td>
            <td class="text-end"><strong>' . number_format($grand_real_vol) . '</strong></td>
            <td class="text-center"><strong>' . formatPersen($grand_fisik) . '</strong></td>
            <td class="text-center"><strong>' . formatPersen($grand_tertimbang) . '</strong></td>
        </tr>';
    }

    $html .= '</tbody></table>';

    // TTD
    $html .= '<div class="ttd-wrapper"><div class="ttd-box">';
    $html .= '<div>Kendari, ' . $tgl_sekarang . '</div>';
    $html .= '<div>KEPALA ' . strtoupper(htmlspecialchars($kepalaData['nama_opd'])) . '</div>';

    $ttd_path = $kepalaData['ttd_path'];
    if ($ttd_path && preg_match('/^[a-zA-Z0-9_\-\/\.]+$/', $ttd_path) && strpos($ttd_path, '..') === false) {
        $full_path = __DIR__ . '/../../' . $ttd_path;
        if (file_exists($full_path)) {
            $image_data = file_get_contents($full_path);
            $base64 = base64_encode($image_data);
            $mime = mime_content_type($full_path);
            $html .= '<div><img class="ttd-img" src="data:' . $mime . ';base64,' . $base64 . '"></div>';
        } else {
            $html .= '<div style="margin-top: 30px;">&nbsp;</div>';
        }
    } else {
        $html .= '<div style="margin-top: 30px;">&nbsp;</div>';
    }

    $html .= '<div>(' . htmlspecialchars($kepalaData['nama_kepala']) . ')</div>';
    $html .= '</div></div>';
    $html .= '</body></html>';

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $tanggal_realtime = date('d') . '_' . $bulan_indonesia[date('n')-1] . '_' . date('Y');
    $nama_file = 'rincian_belanja_' . str_replace(' ', '_', $nama_opd) . '_' . $tanggal_realtime . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nama_file . '"');
    $dompdf->stream($nama_file, ['Attachment' => 1]);
    exit;
}
?>

<!-- ==================== TAMPILAN HTML ==================== -->
<style>
    .table-uraian td, .table-uraian th {
        white-space: normal; word-break: break-word; vertical-align: middle !important;
    }
    .nowrap-cell { white-space: nowrap; }
    .uraian-cell { max-width: 300px; word-wrap: break-word; white-space: normal; }
</style>

<div class="d-flex" style="max-width: 100vw; overflow-x: auto; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <h3 class="mb-3"><i class="bi bi-receipt"></i> Rincian Belanja - <?= htmlspecialchars($nama_opd) ?></h3>
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Tahun</label>
                            <select name="tahun" class="form-select">
                                <?php for($y=date('Y')-2; $y<=date('Y')+1; $y++): ?>
                                    <option value="<?= $y ?>" <?= $tahun==$y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Bulan (s.d.)</label>
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

            <?php if (empty($programs)): ?>
                <div class="alert alert-warning text-center">Tidak ada data rincian belanja untuk periode ini.</div>
            <?php else: ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Akumulasi s.d. <?= $bulan_indonesia[$bulan-1] ?> <?= $tahun ?></span>
                        <div>
                            <a href="?tahun=<?= $tahun ?>&bulan=<?= $bulan ?>&export=excel" class="btn btn-sm btn-success me-1"><i class="bi bi-file-excel"></i> Excel</a>
                            <a href="?tahun=<?= $tahun ?>&bulan=<?= $bulan ?>&export=pdf" class="btn btn-sm btn-danger" target="_blank"><i class="bi bi-file-pdf"></i> PDF</a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0 table-uraian">
                                <thead class="table-light">
                                    <tr>
                                        <th rowspan="2">Kode</th>
                                        <th rowspan="2">Uraian</th>
                                        <th colspan="2" class="text-center">Total</th>
                                        <th rowspan="2" class="text-center">Bobot</th>
                                        <th colspan="2" class="text-center">Realisasi Anggaran</th>
                                        <th colspan="3" class="text-center">Realisasi Fisik</th>
                                    </tr>
                                    <tr>
                                        <th class="text-end">Anggaran (Rp)</th>
                                        <th class="text-end">Volume</th>
                                        <th class="text-end">Rp</th>
                                        <th class="text-center">%</th>
                                        <th class="text-end">Volume</th>
                                        <th class="text-center">Fisik (%)</th>
                                        <th class="text-center">Tertimbang (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $grand_pagu = $grand_vol = $grand_real_pagu = $grand_real_vol = 0;
                                    foreach ($programs as $kode_prog => $prog_data):
                                        $pt = $prog_totals[$kode_prog];
                                        $prog_fisik = $pt['vol'] ? ($pt['real_vol'] / $pt['vol']) * 100 : 0;
                                        $prog_anggaran_persen = $pt['pagu'] ? ($pt['real_pagu'] / $pt['pagu']) * 100 : 0;
                                    ?>
                                        <tr class="table-primary fw-bold">
                                            <td><?= htmlspecialchars($kode_prog) ?></td>
                                            <td><?= htmlspecialchars(getNama($kode_prog, $nama_map)) ?></td>
                                            <td class="text-end"><?= number_format($pt['pagu'],0,',','.') ?></td>
                                            <td class="text-end"><?= number_format($pt['vol']) ?></td>
                                            <td class="text-center"><?= formatPersen(100) ?></td>
                                            <td class="text-end"><?= number_format($pt['real_pagu'],0,',','.') ?></td>
                                            <td class="text-center"><?= formatPersen($prog_anggaran_persen) ?></td>
                                            <td class="text-end"><?= number_format($pt['real_vol']) ?></td>
                                            <td class="text-center"><?= formatPersen($prog_fisik) ?></td>
                                            <td class="text-center"><?= formatPersen($prog_tertimbang[$kode_prog]) ?></td>
                                        </tr>
                                        <?php foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data):
                                            $kt = $keg_totals[$kode_keg];
                                            $keg_fisik = $kt['vol'] ? ($kt['real_vol'] / $kt['vol']) * 100 : 0;
                                            $keg_anggaran_persen = $kt['pagu'] ? ($kt['real_pagu'] / $kt['pagu']) * 100 : 0;
                                        ?>
                                            <tr class="table-info">
                                                <td><?= htmlspecialchars($kode_keg) ?></td>
                                                <td><?= htmlspecialchars(getNama($kode_keg, $nama_map)) ?></td>
                                                <td class="text-end"><?= number_format($kt['pagu'],0,',','.') ?></td>
                                                <td class="text-end"><?= number_format($kt['vol']) ?></td>
                                                <td class="text-center"><?= formatPersen(100) ?></td>
                                                <td class="text-end"><?= number_format($kt['real_pagu'],0,',','.') ?></td>
                                                <td class="text-center"><?= formatPersen($keg_anggaran_persen) ?></td>
                                                <td class="text-end"><?= number_format($kt['real_vol']) ?></td>
                                                <td class="text-center"><?= formatPersen($keg_fisik) ?></td>
                                                <td class="text-center"><?= formatPersen($keg_tertimbang[$kode_keg]) ?></td>
                                            </tr>
                                            <?php foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data):
                                                $st = $subkeg_totals[$kode_sub];
                                                $sub_fisik = $st['vol'] ? ($st['real_vol'] / $st['vol']) * 100 : 0;
                                                $sub_anggaran_persen = $st['pagu'] ? ($st['real_pagu'] / $st['pagu']) * 100 : 0;
                                            ?>
                                                <tr class="table-warning">
                                                    <td><?= htmlspecialchars($kode_sub) ?></td>
                                                    <td><?= htmlspecialchars(getNama($kode_sub, $nama_map)) ?></td>
                                                    <td class="text-end"><?= number_format($st['pagu'],0,',','.') ?></td>
                                                    <td class="text-end"><?= number_format($st['vol']) ?></td>
                                                    <td class="text-center"><?= formatPersen(100) ?></td>
                                                    <td class="text-end"><?= number_format($st['real_pagu'],0,',','.') ?></td>
                                                    <td class="text-center"><?= formatPersen($sub_anggaran_persen) ?></td>
                                                    <td class="text-end"><?= number_format($st['real_vol']) ?></td>
                                                    <td class="text-center"><?= formatPersen($sub_fisik) ?></td>
                                                    <td class="text-center"><?= formatPersen($sub_tertimbang[$kode_sub]) ?></td>
                                                </tr>
                                                <?php foreach ($sub_data['rincian'] as $rincian):
                                                    $target_pag = $rincian['total_pagu'];
                                                    $real_pag   = $rincian['akum_realisasi_pagu'];
                                                    $target_vol = $rincian['total_volume'];
                                                    $real_vol   = $rincian['akum_realisasi_volume'];
                                                    $anggaran_persen = $target_pag ? ($real_pag / $target_pag) * 100 : 0;
                                                    $fisik     = $target_vol ? ($real_vol / $target_vol) * 100 : 0;
                                                    $bobot     = $st['pagu'] ? ($target_pag / $st['pagu']) * 100 : 0;
                                                    $tertimbang = $fisik * $bobot / 100;

                                                    $grand_pagu      += $target_pag;
                                                    $grand_vol       += $target_vol;
                                                    $grand_real_pagu += $real_pag;
                                                    $grand_real_vol  += $real_vol;
                                                ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($rincian['kode_rincian']) ?></td>
                                                        <td><?= htmlspecialchars($rincian['nama_rincian']) ?></td>
                                                        <td class="text-end"><?= number_format($target_pag,0,',','.') ?></td>
                                                        <td class="text-end"><?= number_format($target_vol) ?></td>
                                                        <td class="text-center"><?= formatPersen($bobot) ?></td>
                                                        <td class="text-end"><?= number_format($real_pag,0,',','.') ?></td>
                                                        <td class="text-center"><?= formatPersen($anggaran_persen) ?></td>
                                                        <td class="text-end"><?= number_format($real_vol) ?></td>
                                                        <td class="text-center"><?= formatPersen($fisik) ?></td>
                                                        <td class="text-center"><?= formatPersen($tertimbang) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>

                                    <?php if ($grand_pagu > 0): 
                                        $grand_fisik = $grand_vol ? ($grand_real_vol / $grand_vol) * 100 : 0;
                                        $grand_anggaran_persen = $grand_pagu ? ($grand_real_pagu / $grand_pagu) * 100 : 0;
                                    ?>
                                        <tr class="table-dark fw-bold">
                                            <td colspan="2" class="text-center">TOTAL</td>
                                            <td class="text-end"><?= number_format($grand_pagu,0,',','.') ?></td>
                                            <td class="text-end"><?= number_format($grand_vol) ?></td>
                                            <td class="text-center"><?= formatPersen(100) ?></td>
                                            <td class="text-end"><?= number_format($grand_real_pagu,0,',','.') ?></td>
                                            <td class="text-center"><?= formatPersen($grand_anggaran_persen) ?></td>
                                            <td class="text-end"><?= number_format($grand_real_vol) ?></td>
                                            <td class="text-center"><?= formatPersen($grand_fisik) ?></td>
                                            <td class="text-center"><?= formatPersen($grand_tertimbang) ?></td>
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
<?php include __DIR__ . '/../../includes/footer.php'; ?>