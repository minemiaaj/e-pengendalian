<?php
/**
 * validasi_anggaran.php - Validasi Anggaran oleh Kepala OPD
 * 
 * Keamanan:
 * - Semua query menggunakan prepared statement
 * - CSRF token pada form
 * - Input divalidasi dan di-casting
 * - Output di-escape
 */
require_once __DIR__ . '/../../includes/header.php';

if ($_SESSION['role'] !== 'kepala_opd') {
    header('Location: ../dashboard/index.php');
    exit();
}

$opd_id = (int) $_SESSION['opd_id'];
$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
if ($tahun < 2000 || $tahun > 2100) $tahun = (int)date('Y');

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Flash message
$success = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Validasi semua draft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validasi_semua_anggaran'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        $stmt = $conn->prepare("UPDATE anggaran_detail 
                                SET status_validasi = 'divalidasi', 
                                    tanggal_validasi = NOW(),
                                    perubahan_setelah_validasi = 0
                                WHERE opd_id = ? AND tahun = ? AND status_validasi = 'draft'");
        $stmt->bind_param('ii', $opd_id, $tahun);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $_SESSION['flash_success'] = "Validasi anggaran berhasil. $affected data telah divalidasi.";
        header('Location: validasi_anggaran.php?tahun='.$tahun);
        exit;
    }
}

// Validasi ulang data yang berubah
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validasi_ulang_anggaran'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        $stmt = $conn->prepare("UPDATE anggaran_detail 
                                SET status_validasi = 'divalidasi', 
                                    tanggal_validasi = NOW(),
                                    perubahan_setelah_validasi = 0
                                WHERE opd_id = ? AND tahun = ? 
                                  AND status_validasi = 'draft' 
                                  AND perubahan_setelah_validasi = 1");
        $stmt->bind_param('ii', $opd_id, $tahun);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $_SESSION['flash_success'] = "Validasi ulang berhasil. $affected data telah divalidasi kembali.";
        header('Location: validasi_anggaran.php?tahun='.$tahun);
        exit;
    }
}

// Ambil data anggaran versi terbaru (prepared)
$query = "
    SELECT 
        a.id,
        a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan,
        mb.kode AS kode_rincian, mb.nama AS nama_rincian,
        a.total_pagu,
        a.status_validasi,
        a.perubahan_setelah_validasi
    FROM anggaran_detail a
    JOIN master_belanja mb ON a.rincian_belanja_id = mb.id
    WHERE a.opd_id = ? AND a.tahun = ?
      AND a.versi = (
          SELECT MAX(versi) 
          FROM anggaran_detail a2 
          WHERE a2.opd_id = a.opd_id AND a2.tahun = a.tahun 
            AND a2.kode_program = a.kode_program 
            AND a2.kode_kegiatan = a.kode_kegiatan 
            AND a2.kode_sub_kegiatan = a.kode_sub_kegiatan 
            AND a2.rincian_belanja_id = a.rincian_belanja_id
      )
    ORDER BY a.kode_program, a.kode_kegiatan, a.kode_sub_kegiatan, mb.kode
";
$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $opd_id, $tahun);
$stmt->execute();
$anggaran_result = $stmt->get_result();

// Bangun hierarki
$programs = [];
$count_draft = 0;
$count_berubah = 0;
while ($row = $anggaran_result->fetch_assoc()) {
    $prog = $row['kode_program'];
    $keg  = $row['kode_kegiatan'];
    $sub  = $row['kode_sub_kegiatan'];
    $programs[$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub]['rincian'][] = $row;

    if ($row['status_validasi'] === 'draft') {
        $count_draft++;
        if ($row['perubahan_setelah_validasi'] == 1) {
            $count_berubah++;
        }
    }
}
$stmt->close();

// Nama hierarki (prepared – sudah aman dari functions sebelumnya)
$all_codes = [];
foreach ($programs as $prog => $pdata) {
    $all_codes[] = $prog;
    foreach ($pdata['kegiatan'] as $keg => $kdata) {
        $all_codes[] = $keg;
        foreach ($kdata['sub_kegiatan'] as $sub => $sdata) {
            $all_codes[] = $sub;
        }
    }
}
$all_codes = array_unique($all_codes);
$nama_map = [];
if (!empty($all_codes)) {
    $query_codes = [];
    foreach ($all_codes as $code) {
        $query_codes[] = $code;
        if (preg_match('/^(\d+\.\d+)\.(.+)$/', $code, $matches)) {
            $query_codes[] = 'X.XX.' . $matches[2];
        }
    }
    $query_codes = array_unique($query_codes);
    $placeholders = implode(',', array_fill(0, count($query_codes), '?'));
    $types = str_repeat('s', count($query_codes));
    $stmt = $conn->prepare("SELECT kode, nama FROM master_hierarki WHERE kode IN ($placeholders)");
    $stmt->bind_param($types, ...$query_codes);
    $stmt->execute();
    $res = $stmt->get_result();
    $all_names = [];
    while ($nm = $res->fetch_assoc()) {
        $all_names[$nm['kode']] = $nm['nama'];
    }
    $stmt->close();

    foreach ($all_codes as $code) {
        if (isset($all_names[$code])) {
            $nama_map[$code] = $all_names[$code];
        } else {
            $placeholder = preg_replace('/^\d+\.\d+\./', 'X.XX.', $code);
            $nama_map[$code] = $all_names[$placeholder] ?? '(tanpa uraian)';
        }
    }
}

function getNama($kode, $map) {
    return $map[$kode] ?? '(tanpa uraian)';
}
?>

<div class="d-flex" style="margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex:1; padding:20px;">
        <div class="container-fluid">
            <h3><i class="bi bi-check-circle"></i> Validasi Anggaran</h3>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5>Status Validasi Anggaran Tahun <?= $tahun ?></h5>
                <div>
                    <?php if ($count_draft > 0): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="validasi_semua_anggaran" value="1">
                            <button type="submit" class="btn btn-success btn-sm me-1" 
                                    onclick="return confirm('Validasi SEMUA data anggaran yang masih draft?')">
                                ✔ Validasi Semua Draft (<?= $count_draft ?> data)
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($count_berubah > 0): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="validasi_ulang_anggaran" value="1">
                            <button type="submit" class="btn btn-warning btn-sm" 
                                    onclick="return confirm('Validasi ulang semua data yang berubah setelah validasi?')">
                                ↻ Validasi Ulang Data Berubah (<?= $count_berubah ?> data)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($programs)): ?>
                <div class="alert alert-info">Belum ada data anggaran untuk ditampilkan.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-validasi">
                    <thead class="table-light">
                        <tr>
                            <th style="width:15%">Kode Rekening</th>
                            <th style="width:45%">Uraian</th>
                            <th style="width:20%" class="text-end">Total Pagu (Rp)</th>
                            <th style="width:20%">Status Validasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programs as $kode_prog => $prog_data): ?>
                            <tr class="table-primary fw-bold">
                                <td><?= htmlspecialchars($kode_prog) ?></td>
                                <td colspan="3"><?= htmlspecialchars(getNama($kode_prog, $nama_map)) ?></td>
                            </tr>
                            <?php foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data): ?>
                                <tr class="table-secondary">
                                    <td><?= htmlspecialchars($kode_keg) ?></td>
                                    <td colspan="3"><?= htmlspecialchars(getNama($kode_keg, $nama_map)) ?></td>
                                </tr>
                                <?php foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data): ?>
                                    <tr class="table-light">
                                        <td><?= htmlspecialchars($kode_sub) ?></td>
                                        <td colspan="3"><?= htmlspecialchars(getNama($kode_sub, $nama_map)) ?></td>
                                    </tr>
                                    <?php foreach ($sub_data['rincian'] as $rincian): 
                                        $status = $rincian['status_validasi'];
                                        $berubah = $rincian['perubahan_setelah_validasi'];
                                        if ($status === 'draft') {
                                            $badge = '<span class="badge bg-warning text-dark">Belum Divalidasi</span>';
                                            if ($berubah == 1) {
                                                $badge .= ' <span class="badge bg-danger">Berubah</span>';
                                            }
                                        } elseif ($status === 'divalidasi' && $berubah == 1) {
                                            $badge = '<span class="badge bg-danger">Ada Perubahan</span>';
                                        } else {
                                            $badge = '<span class="badge bg-success">Divalidasi</span>';
                                        }
                                    ?>
                                        <tr>
                                            <td><?= htmlspecialchars($rincian['kode_rincian']) ?></td>
                                            <td><?= htmlspecialchars($rincian['nama_rincian']) ?></td>
                                            <td class="text-end"><?= number_format($rincian['total_pagu'], 0, ',', '.') ?></td>
                                            <td><?= $badge ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>