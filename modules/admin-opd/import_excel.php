<?php
/**
 * import_excel.php - Import data anggaran dari file Excel (RAK Belanja)
 * Mendukung upload MULTIPLE file sekaligus.
 *
 * Keamanan:
 * - Prepared statement untuk semua query database
 * - Validasi ketat file upload: MIME type, ekstensi, nama file acak
 * - CSRF token
 * - Transaksi database
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/header.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ========== OTORISASI ==========
if ($_SESSION['role'] !== 'admin_opd') {
    header('Location: ../dashboard/index.php');
    exit();
}

$opd_id = (int) $_SESSION['opd_id'];
$tahun_sekarang = (int) date('Y');

// Ambil & validasi tahun
$tahun = isset($_POST['tahun']) ? (int) $_POST['tahun'] : (isset($_GET['tahun']) ? (int) $_GET['tahun'] : $tahun_sekarang);
if ($tahun < 2000 || $tahun > 2100) {
    $tahun = $tahun_sekarang;
}

// ========== CSRF TOKEN ==========
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success = '';
$error   = '';

// ========== FUNGSI PEMBANTU ==========

function getNumericValue($cell) {
    if (is_numeric($cell)) {
        return (int) $cell;
    }
    $str = trim((string) $cell);
    if ($str === '') {
        return 0;
    }
    $clean = str_replace('.', '', $str);
    $clean = str_replace(',', '.', $clean);
    $clean = preg_replace('/[^0-9\.\-]/', '', $clean);
    if ($clean === '' || $clean === '-' || $clean === '.') {
        return 0;
    }
    if (strpos($clean, '.') !== false) {
        return (int) round((float) $clean);
    }
    return (int) $clean;
}

function getActiveDraftVersion($conn, $opd_id, $tahun) {
    $stmt = $conn->prepare("SELECT COUNT(*) as locked FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['locked'] == 0) {
        return 0;
    }
    $stmt = $conn->prepare("SELECT MAX(versi) as max_draft FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'draft' AND versi > 0");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row['max_draft']) {
        return (int)$row['max_draft'];
    }
    $stmt = $conn->prepare("SELECT MAX(versi) as max_v FROM anggaran_detail WHERE opd_id = ? AND tahun = ?");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['max_v'] ?? 0)) + 1;
}

// ===================== PROSES UPLOAD & EKSTRAKSI (MULTIPLE) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    // Verifikasi CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid. Silakan muat ulang halaman.";
    } else {
        // Siapkan direktori upload
        $upload_dir = __DIR__ . '/../../uploads/temp/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0750, true)) {
                $error = "Gagal membuat direktori upload.";
            }
            file_put_contents($upload_dir . '.htaccess', "Deny from all\n");
        }

        if (!$error) {
            $files = $_FILES['excel_file'];
            // Jika hanya satu file, kita ubah menjadi array agar seragam
            if (!is_array($files['tmp_name'])) {
                $files = [
                    'tmp_name' => [$files['tmp_name']],
                    'name'     => [$files['name']],
                    'type'     => [$files['type']],
                    'error'    => [$files['error']],
                    'size'     => [$files['size']]
                ];
            }

            $all_items = [];      // kumpulan item dari semua file
            $file_errors = [];    // error per file

            // Batasi maksimal 10 file
            $max_files = 10;
            if (count($files['tmp_name']) > $max_files) {
                $error = "Maksimal $max_files file yang dapat diupload sekaligus.";
            }

            if (!$error) {
                foreach ($files['tmp_name'] as $idx => $tmp_path) {
                    if ($files['error'][$idx] !== UPLOAD_ERR_OK) {
                        $file_errors[] = "File " . htmlspecialchars($files['name'][$idx]) . " gagal diupload (error code: " . $files['error'][$idx] . ")";
                        continue;
                    }

                    // Validasi MIME & ekstensi
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime  = finfo_file($finfo, $tmp_path);
                    finfo_close($finfo);
                    $ext = strtolower(pathinfo($files['name'][$idx], PATHINFO_EXTENSION));
                    $allowed_mime = [
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel'
                    ];
                    $allowed_ext = ['xlsx', 'xls'];
                    if (!in_array($mime, $allowed_mime, true) || !in_array($ext, $allowed_ext, true)) {
                        $file_errors[] = "File " . htmlspecialchars($files['name'][$idx]) . " bukan Excel (tipe: $mime)";
                        continue;
                    }

                    // Pindahkan ke folder sementara dengan nama acak
                    $safe_filename = bin2hex(random_bytes(16)) . '.' . $ext;
                    $safe_path = $upload_dir . $safe_filename;
                    if (!move_uploaded_file($tmp_path, $safe_path)) {
                        $file_errors[] = "Gagal memindahkan file " . htmlspecialchars($files['name'][$idx]);
                        continue;
                    }

                    // Proses file Excel
                    try {
                        $spreadsheet = IOFactory::load($safe_path);
                        $worksheet   = $spreadsheet->getActiveSheet();
                        $rows        = $worksheet->toArray(null, true, false, false);

                        if (empty($rows)) {
                            throw new Exception("File kosong atau tidak memiliki data.");
                        }

                        // ----- EKSTRAK KODE PROGRAM / KEGIATAN / SUB KEGIATAN -----
                        $program_kode = $kegiatan_kode = $sub_kegiatan_kode = '';
                        foreach ($rows as $row) {
                            $label = trim((string) ($row[0] ?? ''));
                            if (stripos($label, 'Program') !== false && !$program_kode) {
                                $value = trim((string) ($row[1] ?? ''));
                                if (preg_match('/^[\d\.]+/', $value, $matches)) {
                                    $program_kode = $matches[0];
                                }
                            }
                            if (stripos($label, 'Kegiatan') !== false && !$kegiatan_kode) {
                                $value = trim((string) ($row[1] ?? ''));
                                if (preg_match('/^[\d\.]+/', $value, $matches)) {
                                    $kegiatan_kode = $matches[0];
                                }
                            }
                            if (stripos($label, 'Sub Kegiatan') !== false && !$sub_kegiatan_kode) {
                                $value = trim((string) ($row[1] ?? ''));
                                if (preg_match('/^[\d\.]+/', $value, $matches)) {
                                    $sub_kegiatan_kode = $matches[0];
                                }
                            }
                            if ($program_kode && $kegiatan_kode && $sub_kegiatan_kode) break;
                        }

                        if (!$program_kode || !$kegiatan_kode || !$sub_kegiatan_kode) {
                            throw new Exception("Tidak ditemukan kode Program / Kegiatan / Sub Kegiatan.");
                        }

                        // ----- CARI BARIS HEADER BULAN -----
                        $header_row_idx = -1;
                        $bulan_cols = [];
                        $target_bulan = ['Januari','Februari','Maret','April','Mei','Juni',
                                         'Juli','Agustus','September','Oktober','November','Desember'];
                        foreach ($rows as $idx => $row) {
                            foreach ($row as $col_idx => $cell) {
                                if (in_array(trim((string) $cell), $target_bulan, true)) {
                                    $header_row_idx = $idx;
                                    break 2;
                                }
                            }
                        }
                        if ($header_row_idx === -1) {
                            throw new Exception("Baris header bulan (Januari-Desember) tidak ditemukan.");
                        }

                        $header_row = $rows[$header_row_idx];
                        foreach ($target_bulan as $bulan) {
                            $col = array_search($bulan, $header_row);
                            if ($col === false) {
                                throw new Exception("Kolom untuk bulan $bulan tidak ditemukan.");
                            }
                            $bulan_cols[$bulan] = $col;
                        }

                        // ----- CARI KOLOM TOTAL RAK -----
                        $total_col = 3;
                        for ($i = $header_row_idx - 1; $i >= 0; $i--) {
                            $row = $rows[$i];
                            foreach ($row as $idx => $cell) {
                                if (trim((string) $cell) === 'Total RAK') {
                                    $total_col = $idx;
                                    break 2;
                                }
                            }
                        }

                        // ----- PROSES BARIS RINCIAN -----
                        $bulan_keys = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
                        $bulan_map = array_combine($target_bulan, $bulan_keys);

                        for ($i = $header_row_idx + 1; $i < count($rows); $i++) {
                            $row = $rows[$i];
                            $kode_rekening = trim((string) ($row[0] ?? ''));
                            if (!preg_match('/^\d+\.\d+\.\d+\.\d+\.\d+\.\d+$/', $kode_rekening)) {
                                continue;
                            }

                            $uraian = trim((string) ($row[1] ?? ''));
                            $total_pagu = getNumericValue($row[$total_col] ?? 0);

                            $monthly_pagu = [];
                            foreach ($target_bulan as $bulan) {
                                $monthly_pagu[$bulan_map[$bulan]] = getNumericValue($row[$bulan_cols[$bulan]] ?? 0);
                            }
                            if ($total_pagu === 0) {
                                $total_pagu = array_sum($monthly_pagu);
                            }

                            // Cek di master_belanja
                            $stmt = $conn->prepare("SELECT id FROM master_belanja WHERE kode = ?");
                            $stmt->bind_param('s', $kode_rekening);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $rincian = $res->fetch_assoc();
                            $stmt->close();

                            if ($rincian) {
                                $all_items[] = [
                                    'program_kode'       => $program_kode,
                                    'kegiatan_kode'      => $kegiatan_kode,
                                    'sub_kegiatan_kode'  => $sub_kegiatan_kode,
                                    'kode_rekening'      => $kode_rekening,
                                    'uraian'             => $uraian,
                                    'rincian_id'         => (int) $rincian['id'],
                                    'total_pagu'         => $total_pagu,
                                    'pagu_per_bulan'     => $monthly_pagu
                                ];
                            } else {
                                throw new Exception("Kode rekening \"$kode_rekening\" tidak ditemukan di master_belanja.");
                            }
                        }

                        // Hapus file sementara
                        if (file_exists($safe_path)) {
                            unlink($safe_path);
                        }

                    } catch (Exception $e) {
                        $file_errors[] = "File " . htmlspecialchars($files['name'][$idx]) . ": " . htmlspecialchars($e->getMessage());
                        // Hapus file sementara jika ada
                        if (file_exists($safe_path)) {
                            unlink($safe_path);
                        }
                    }
                }

                // Setelah semua file diproses
                if (!empty($file_errors)) {
                    $error = "Terdapat kesalahan pada beberapa file:<br>" . implode('<br>', $file_errors);
                } elseif (empty($all_items)) {
                    $error = "Tidak ada data valid yang ditemukan dari semua file.";
                } else {
                    // Simpan ke session untuk preview
                    $_SESSION['import_preview'] = [
                        'tahun' => $tahun,
                        'items' => $all_items
                    ];
                    header("Location: import_excel.php?preview=1&tahun=" . $tahun);
                    exit();
                }
            }
        }
    }
}

// ===================== KONFIRMASI IMPORT =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && isset($_SESSION['import_preview'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        $preview = $_SESSION['import_preview'];
        $tahun   = (int) $preview['tahun'];
        $items   = $preview['items'];

        $conn->begin_transaction();
        try {
            $target_versi = getActiveDraftVersion($conn, $opd_id, $tahun);
            $is_pergeseran = ($target_versi > 0);

            if ($is_pergeseran) {
                $stmt_mark = $conn->prepare("UPDATE anggaran_detail SET perubahan_setelah_validasi = 1 
                                             WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'");
                $stmt_mark->bind_param('ii', $opd_id, $tahun);
                $stmt_mark->execute();
                $stmt_mark->close();
            }

            $inserted = 0;
            $updated  = 0;
            $bulan_singkat = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];

            foreach ($items as $item) {
                $rincian_id = (int) $item['rincian_id'];
                $total_pagu = (int) $item['total_pagu'];
                $pagu = $item['pagu_per_bulan'];
                $program_kode = $item['program_kode'];
                $kegiatan_kode = $item['kegiatan_kode'];
                $sub_kegiatan_kode = $item['sub_kegiatan_kode'];

                $volume = [];
                $total_volume = 0;
                foreach ($bulan_singkat as $bln) {
                    $vol = (isset($pagu[$bln]) && $pagu[$bln] > 0) ? 1 : 0;
                    $volume[$bln] = $vol;
                    $total_volume += $vol;
                }

                // Cek data existing di versi target
                $check = $conn->prepare("SELECT id FROM anggaran_detail 
                                         WHERE opd_id = ? AND tahun = ? AND versi = ? 
                                         AND kode_program = ? AND kode_kegiatan = ? 
                                         AND kode_sub_kegiatan = ? AND rincian_belanja_id = ?
                                         AND status_validasi != 'dikunci'");
                $check->bind_param('iiisssi', $opd_id, $tahun, $target_versi, $program_kode, $kegiatan_kode, $sub_kegiatan_kode, $rincian_id);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                $check->close();

                if ($exists) {
                    $update = $conn->prepare("UPDATE anggaran_detail SET 
                        total_pagu = ?, 
                        pagu_jan = ?, pagu_feb = ?, pagu_mar = ?, pagu_apr = ?, pagu_mei = ?, pagu_jun = ?,
                        pagu_jul = ?, pagu_agu = ?, pagu_sep = ?, pagu_okt = ?, pagu_nov = ?, pagu_des = ?,
                        total_volume = ?, 
                        volume_jan = ?, volume_feb = ?, volume_mar = ?, volume_apr = ?, volume_mei = ?, volume_jun = ?,
                        volume_jul = ?, volume_agu = ?, volume_sep = ?, volume_okt = ?, volume_nov = ?, volume_des = ?,
                        terakhir_update = NOW(), status_validasi = 'draft'
                        WHERE id = ?");
                    $update->bind_param(
                        'i' . str_repeat('i', 12) . 'i' . str_repeat('i', 12) . 'i',
                        $total_pagu,
                        $pagu['jan'], $pagu['feb'], $pagu['mar'], $pagu['apr'], $pagu['mei'], $pagu['jun'],
                        $pagu['jul'], $pagu['agu'], $pagu['sep'], $pagu['okt'], $pagu['nov'], $pagu['des'],
                        $total_volume,
                        $volume['jan'], $volume['feb'], $volume['mar'], $volume['apr'], $volume['mei'], $volume['jun'],
                        $volume['jul'], $volume['agu'], $volume['sep'], $volume['okt'], $volume['nov'], $volume['des'],
                        $exists['id']
                    );
                    $update->execute();
                    $update->close();
                    $updated++;
                } else {
                    $insert = $conn->prepare("INSERT INTO anggaran_detail 
                        (opd_id, tahun, versi, kode_program, kode_kegiatan, kode_sub_kegiatan, rincian_belanja_id,
                         total_volume, total_pagu,
                         volume_jan, volume_feb, volume_mar, volume_apr, volume_mei, volume_jun,
                         volume_jul, volume_agu, volume_sep, volume_okt, volume_nov, volume_des,
                         pagu_jan, pagu_feb, pagu_mar, pagu_apr, pagu_mei, pagu_jun,
                         pagu_jul, pagu_agu, pagu_sep, pagu_okt, pagu_nov, pagu_des,
                         status_validasi, tanggal_validasi, tanggal_kunci, terakhir_update, perubahan_setelah_validasi) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                                'draft', NULL, NULL, NOW(), 0)");
                    $insert->bind_param(
                        'iiisssiii' . str_repeat('i', 24),
                        $opd_id, $tahun, $target_versi,
                        $program_kode, $kegiatan_kode, $sub_kegiatan_kode, $rincian_id,
                        $total_volume, $total_pagu,
                        $volume['jan'], $volume['feb'], $volume['mar'], $volume['apr'], $volume['mei'], $volume['jun'],
                        $volume['jul'], $volume['agu'], $volume['sep'], $volume['okt'], $volume['nov'], $volume['des'],
                        $pagu['jan'], $pagu['feb'], $pagu['mar'], $pagu['apr'], $pagu['mei'], $pagu['jun'],
                        $pagu['jul'], $pagu['agu'], $pagu['sep'], $pagu['okt'], $pagu['nov'], $pagu['des']
                    );
                    $insert->execute();
                    $insert->close();
                    $inserted++;
                }
            }

            $conn->commit();
            unset($_SESSION['import_preview']);
            $success = "Import selesai: $inserted data baru, $updated data diperbarui.";
            header("Location: import_excel.php?tahun=" . $tahun . "&success=1");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Gagal menyimpan data: " . htmlspecialchars($e->getMessage());
        }
    }
}

// ===================== TAMPILAN =====================
$show_preview = (isset($_GET['preview']) && isset($_SESSION['import_preview']));
if ($show_preview) {
    $preview = $_SESSION['import_preview'];
    $tahun   = $preview['tahun'];
    $items   = $preview['items'];
}
if (isset($_GET['success'])) {
    $success = "Import berhasil! Silakan cek data anggaran.";
}
?>

<div class="d-flex" style="margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex: 1; padding: 20px;">
        <div class="container-fluid">
            <h3><i class="bi bi-file-excel-fill"></i> Import Data Anggaran dari Excel (Multiple File)</h3>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php elseif (!empty($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <?php if (!$show_preview): ?>
                <!-- Form Upload -->
                <div class="card">
                    <div class="card-header">Upload File Excel (RAK Belanja) – maksimal 10 file</div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <div class="mb-3">
                                <label class="form-label">Tahun Anggaran</label>
                                <input type="number" name="tahun" class="form-control" 
                                       value="<?= htmlspecialchars($tahun) ?>" 
                                       min="2000" max="2100" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Pilih File Excel (.xlsx, .xls) – bisa pilih beberapa</label>
                                <input type="file" name="excel_file[]" class="form-control" 
                                       accept=".xlsx,.xls" multiple required>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-upload"></i> Proses &amp; Preview
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Preview & Konfirmasi -->
                <div class="card">
                    <div class="card-header bg-warning">
                        <strong>Preview Data Import (<?= count($items) ?> item dari semua file)</strong>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Program</th>
                                        <th>Kegiatan</th>
                                        <th>Sub Kegiatan</th>
                                        <th>Kode Rekening</th>
                                        <th>Uraian</th>
                                        <th class="text-end">Total Pagu</th>
                                        <th>Pagu per Bulan (ringkasan)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): 
                                        $bulan_label = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                                        $bulan_display = [];
                                        $idx = 0;
                                        foreach (['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'] as $key) {
                                            $val = isset($item['pagu_per_bulan'][$key]) ? (int)$item['pagu_per_bulan'][$key] : 0;
                                            $bulan_display[] = $bulan_label[$idx] . ': ' . number_format($val, 0, ',', '.');
                                            $idx++;
                                        }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['program_kode']) ?></td>
                                        <td><?= htmlspecialchars($item['kegiatan_kode']) ?></td>
                                        <td><?= htmlspecialchars($item['sub_kegiatan_kode']) ?></td>
                                        <td><?= htmlspecialchars($item['kode_rekening']) ?></td>
                                        <td><?= htmlspecialchars(substr($item['uraian'], 0, 60)) ?></td>
                                        <td class="text-end"><?= number_format($item['total_pagu'], 0, ',', '.') ?></td>
                                        <td style="font-size:0.75rem;"><?= htmlspecialchars(implode(' | ', $bulan_display)) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <form method="POST" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="confirm_import" value="1">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> Konfirmasi Import (<?= count($items) ?> item)
                            </button>
                            <a href="import_excel.php" class="btn btn-secondary">Batal</a>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>