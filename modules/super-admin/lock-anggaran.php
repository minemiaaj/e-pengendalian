<?php
/**
 * lock-anggaran.php - Kunci Data Anggaran (Super Admin)
 * 
 * Keamanan:
 * - Semua query menggunakan prepared statement
 * - CSRF token di setiap form POST
 * - Output escaping
 */
require_once __DIR__ . '/../../includes/header.php';

if ($_SESSION['role'] !== 'super_admin') {
    header('Location: ../dashboard/index.php');
    exit();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success = '';
$error = '';

// ========== KUNCI SEMUA DATA (SEMUA OPD) ==========
if (isset($_POST['kunci_semua_data'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        $query_pasangan = $conn->query("SELECT DISTINCT opd_id, tahun FROM anggaran_detail WHERE status_validasi = 'divalidasi'");
        if (!$query_pasangan) {
            $error = "Gagal membaca data.";
        } else {
            $updated_pairs = [];
            $conn->begin_transaction();
            try {
                while ($pair = $query_pasangan->fetch_assoc()) {
                    $oid = (int)$pair['opd_id'];
                    $thn = (int)$pair['tahun'];
                    kunciAnggaranOpd($conn, $oid, $thn);
                    $updated_pairs[] = ['opd_id' => $oid, 'tahun' => $thn];
                }
                $conn->commit();

                // Sinkronisasi realisasi
                foreach ($updated_pairs as $p) {
                    syncAnggaranToRealisasi($conn, $p['opd_id'], $p['tahun']);
                }
                $success = "✅ Semua data divalidasi telah dikunci.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "⚠️ Gagal mengunci semua data: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// ========== KUNCI PER OPD & TAHUN ==========
if (isset($_POST['kunci_anggaran'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        $opd_id = (int)($_POST['opd_id'] ?? 0);
        $tahun  = (int)($_POST['tahun'] ?? 0);

        if ($opd_id <= 0 || $tahun < 2000) {
            $error = "Parameter tidak valid.";
        } else {
            $conn->begin_transaction();
            try {
                kunciAnggaranOpd($conn, $opd_id, $tahun);
                syncAnggaranToRealisasi($conn, $opd_id, $tahun);
                $conn->commit();
                $success = "✅ Berhasil mengunci data untuk OPD terpilih.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "⚠️ Gagal mengunci data: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// ========== RINGKASAN DATA OPD ==========
$opd_list = $conn->query("
    SELECT o.id, o.nama_opd, a.tahun,
           SUM(CASE WHEN a.status_validasi = 'divalidasi' AND a.versi = 0 THEN 1 ELSE 0 END) as jml_siap_kunci_baru,
           SUM(CASE WHEN a.status_validasi = 'divalidasi' AND a.versi > 0 THEN 1 ELSE 0 END) as jml_siap_kunci_perubahan,
           SUM(CASE WHEN a.status_validasi = 'dikunci' THEN 1 ELSE 0 END) as jml_dikunci,
           MAX(CASE WHEN a.status_validasi = 'dikunci' THEN a.versi END) as versi_terkunci,
           MAX(CASE WHEN a.status_validasi = 'divalidasi' AND a.versi = 0 THEN a.tanggal_validasi END) as tgl_validasi,
           MAX(CASE WHEN a.status_validasi = 'dikunci' THEN a.tanggal_kunci END) as tgl_kunci
    FROM opd o
    JOIN anggaran_detail a ON o.id = a.opd_id
    WHERE a.status_validasi IN ('divalidasi','dikunci')
    GROUP BY o.id, o.nama_opd, a.tahun
    ORDER BY o.nama_opd, a.tahun
");

$total_siap_kunci = $conn->query("SELECT COUNT(*) as total FROM anggaran_detail WHERE status_validasi = 'divalidasi'")->fetch_assoc()['total'] ?? 0;
$total_terkunci_all = $conn->query("SELECT COUNT(*) as total FROM anggaran_detail WHERE status_validasi = 'dikunci'")->fetch_assoc()['total'] ?? 0;
?>

<div class="d-flex" style="margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex:1; padding:20px;">
        <h3><i class="bi bi-lock-fill"></i> Kunci Data Anggaran</h3>
        <p class="text-muted">
            Data yang sudah dikunci <strong>tidak dapat diubah</strong> kembali. 
            Setelah dikunci, hanya <strong>kombinasi baru</strong> yang akan muncul di tabel Realisasi (volume & pagu = 0).
        </p>

        <?php if ($success): ?>
            <div class="alert alert-info"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="mb-3">
            <?php if ($total_siap_kunci > 0): ?>
                <form method="POST" onsubmit="return confirm('Kunci SEMUA data yang sudah divalidasi?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <button type="submit" name="kunci_semua_data" class="btn btn-danger">
                        🔒 Kunci Semua Data (<?= $total_siap_kunci ?> siap)
                    </button>
                </form>
            <?php else: ?>
                <span class="text-muted">Tidak ada data siap dikunci.</span>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">Daftar OPD & Status Penguncian</div>
            <div class="card-body table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>OPD</th><th>Tahun</th>
                            <th>Siap Kunci (Baru)</th><th>Siap Kunci (Perubahan)</th>
                            <th>Dikunci</th><th>Versi</th>
                            <th>Tgl Validasi</th><th>Tgl Kunci</th><th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $opd_list->fetch_assoc()):
                            $siap = $row['jml_siap_kunci_baru'] + $row['jml_siap_kunci_perubahan'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['nama_opd']) ?></td>
                            <td><?= (int)$row['tahun'] ?></td>
                            <td class="text-center"><?= (int)$row['jml_siap_kunci_baru'] ?></td>
                            <td class="text-center"><?= (int)$row['jml_siap_kunci_perubahan'] ?></td>
                            <td class="text-center"><?= (int)$row['jml_dikunci'] ?></td>
                            <td class="text-center"><?= $row['versi_terkunci'] ? 'Versi ' . (int)$row['versi_terkunci'] : '-' ?></td>
                            <td><?= $row['tgl_validasi'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($row['tgl_validasi']))) : '-' ?></td>
                            <td><?= $row['tgl_kunci'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($row['tgl_kunci']))) : '-' ?></td>
                            <td>
                                <?php if ($siap > 0): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="opd_id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="tahun" value="<?= (int)$row['tahun'] ?>">
                                        <button type="submit" name="kunci_anggaran" class="btn btn-sm btn-danger"
                                                onclick="return confirm('Kunci data yang sudah divalidasi?')">
                                            🔒 Kunci (<?= $siap ?>)
                                        </button>
                                    </form>
                                <?php elseif ($row['jml_dikunci'] > 0): ?>
                                    <span class="badge bg-secondary">Terkunci</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>