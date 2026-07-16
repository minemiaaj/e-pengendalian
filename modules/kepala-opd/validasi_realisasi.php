<?php
/**
 * validasi_realisasi.php - Validasi Realisasi Mingguan oleh Kepala OPD
 * 
 * Keamanan:
 * - Semua query menggunakan prepared statement
 * - Tidak ada interpolasi variabel langsung ke SQL
 * - CSRF token pada form
 * - Output di-escape
 */
require_once __DIR__ . '/../../includes/header.php';
if ($_SESSION['role'] !== 'kepala_opd') { 
    header('Location: ../dashboard/index.php'); 
    exit(); 
}

$opd_id = (int) $_SESSION['opd_id'];
$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$bulan_keys = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
$bulan_labels = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$minggu_keys = ['w1','w2','w3','w4','w5'];
$minggu_labels = ['w1'=>'Pekan ke-1', 'w2'=>'Pekan ke-2', 'w3'=>'Pekan ke-3', 'w4'=>'Pekan ke-4', 'w5'=>'Pekan ke-5'];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Flash message
$success = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Proses validasi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validasi_bulan_minggu'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        $bulan = $_POST['bulan_key'];
        $minggu = $_POST['minggu'];
        if (in_array($bulan, $bulan_keys) && in_array($minggu, $minggu_keys)) {
            // Cek apakah sudah dikunci oleh Super Admin
            $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM realisasi_detail WHERE opd_id = ? AND tahun = ? AND JSON_EXTRACT(status_mingguan, ?) = 'dikunci'");
            $path = '$."' . $bulan . '"."' . $minggu . '"';
            $stmt->bind_param('iis', $opd_id, $tahun, $path);
            $stmt->execute();
            $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
            if ($cnt > 0) {
                $_SESSION['flash_error'] = "Periode ini sudah dikunci oleh Super Admin, tidak dapat divalidasi ulang.";
            } else {
                // Ambil semua record realisasi untuk OPD
                $stmt = $conn->prepare("SELECT id, status_mingguan FROM realisasi_detail WHERE opd_id = ? AND tahun = ?");
                $stmt->bind_param('ii', $opd_id, $tahun);
                $stmt->execute();
                $result = $stmt->get_result();
                $count = 0;
                while ($row = $result->fetch_assoc()) {
                    $status = json_decode($row['status_mingguan'] ?? '{}', true);
                    $st = $status[$bulan][$minggu] ?? 'draft';
                    if ($st == 'draft' || $st == 'divalidasi') {
                        unset($status[$bulan][$minggu.'_changed']);
                        $status[$bulan][$minggu] = 'divalidasi';
                        $new_json = json_encode($status);
                        // Update dengan prepared statement
                        $upd = $conn->prepare("UPDATE realisasi_detail SET status_mingguan = ? WHERE id = ?");
                        $upd->bind_param('si', $new_json, $row['id']);
                        $upd->execute();
                        $upd->close();
                        $count++;
                    }
                }
                $stmt->close();
                $_SESSION['flash_success'] = "Berhasil memvalidasi $count data. Flag perubahan (jika ada) telah direset.";
            }
        }
    }
    header('Location: validasi_realisasi.php?tahun='.$tahun);
    exit;
}

// Ambil data realisasi
$stmt = $conn->prepare("SELECT id, status_mingguan, 
    volume_jan_w1, volume_jan_w2, volume_jan_w3, volume_jan_w4, volume_jan_w5,
    volume_feb_w1, volume_feb_w2, volume_feb_w3, volume_feb_w4, volume_feb_w5,
    volume_mar_w1, volume_mar_w2, volume_mar_w3, volume_mar_w4, volume_mar_w5,
    volume_apr_w1, volume_apr_w2, volume_apr_w3, volume_apr_w4, volume_apr_w5,
    volume_mei_w1, volume_mei_w2, volume_mei_w3, volume_mei_w4, volume_mei_w5,
    volume_jun_w1, volume_jun_w2, volume_jun_w3, volume_jun_w4, volume_jun_w5,
    volume_jul_w1, volume_jul_w2, volume_jul_w3, volume_jul_w4, volume_jul_w5,
    volume_agu_w1, volume_agu_w2, volume_agu_w3, volume_agu_w4, volume_agu_w5,
    volume_sep_w1, volume_sep_w2, volume_sep_w3, volume_sep_w4, volume_sep_w5,
    volume_okt_w1, volume_okt_w2, volume_okt_w3, volume_okt_w4, volume_okt_w5,
    volume_nov_w1, volume_nov_w2, volume_nov_w3, volume_nov_w4, volume_nov_w5,
    volume_des_w1, volume_des_w2, volume_des_w3, volume_des_w4, volume_des_w5
    FROM realisasi_detail WHERE opd_id = ? AND tahun = ?");
$stmt->bind_param('ii', $opd_id, $tahun);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

// Hitung statistik
$stats = [];
foreach ($bulan_keys as $b) {
    foreach ($minggu_keys as $m) {
        $stats[$b][$m] = ['draft' => 0, 'divalidasi' => 0, 'dikunci' => 0, 'changed' => 0, 'total_data' => 0];
    }
}
foreach ($rows as $row) {
    $status = json_decode($row['status_mingguan'] ?? '{}', true);
    foreach ($bulan_keys as $b) {
        foreach ($minggu_keys as $m) {
            $st = $status[$b][$m] ?? 'draft';
            $changed = $status[$b][$m.'_changed'] ?? false;
            $stats[$b][$m]['total_data']++;
            if ($st == 'draft') $stats[$b][$m]['draft']++;
            elseif ($st == 'divalidasi') {
                $stats[$b][$m]['divalidasi']++;
                if ($changed) $stats[$b][$m]['changed']++;
            } elseif ($st == 'dikunci') $stats[$b][$m]['dikunci']++;
        }
    }
}
?>

<div class="d-flex" style="margin-bottom: 50px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex:1; padding:1.5rem;">
        <div class="container-fluid px-0 px-md-3">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>Validasi Realisasi</h4>
                    <p class="text-muted small mb-0">Tahun <strong><?= htmlspecialchars($tahun) ?></strong> – Klik Validasi untuk mengunci minggu. Data yang sudah divalidasi tidak dapat diubah Admin OPD, kecuali jika belum ada data.</p>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-3">
                <?php foreach ($bulan_keys as $index => $b): ?>
                <div class="col">
                    <div class="card border-0 shadow-sm h-100 rounded-3 hover-card">
                        <div class="card-header bg-white border-bottom py-2 px-3 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-semibold text-dark"><?= htmlspecialchars($bulan_labels[$index]) ?></h6>
                            <span class="badge bg-light text-secondary"><?= htmlspecialchars($tahun) ?></span>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($minggu_keys as $m): 
                                    $stat = $stats[$b][$m];
                                    $jml_draft = $stat['draft'];
                                    $jml_divalidasi = $stat['divalidasi'];
                                    $jml_dikunci = $stat['dikunci'];
                                    $jml_changed = $stat['changed'];
                                    $total = $stat['total_data'];
                                ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <small class="text-muted"><?= htmlspecialchars($minggu_labels[$m]) ?></small>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($jml_dikunci > 0): ?>
                                            <span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>Dikunci</span>
                                        <?php elseif ($jml_divalidasi > 0 && $jml_draft == 0): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Tervalidasi</span>
                                            <?php if ($jml_changed > 0): ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle-fill me-1"></i>Berubah</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                <input type="hidden" name="validasi_bulan_minggu" value="1">
                                                <input type="hidden" name="bulan_key" value="<?= htmlspecialchars($b) ?>">
                                                <input type="hidden" name="minggu" value="<?= htmlspecialchars($m) ?>">
                                                <button type="submit" class="btn btn-outline-success btn-sm rounded-pill">
                                                    <i class="bi bi-check-circle"></i> Validasi
                                                    <?= ($jml_draft > 0 ? " ($jml_draft)" : (($total == 0) ? ' (0)' : '')) ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
.hover-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.hover-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.08) !important;
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>