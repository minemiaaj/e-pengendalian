<?php
/**
 * lock-realisasi.php - Kunci Data Realisasi (Super Admin)
 * 
 * Keamanan:
 * - Semua query menggunakan prepared statement
 * - CSRF token di setiap form POST
 * - Output di-escape
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

$bulan_keys   = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
$bulan_labels = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$minggu_keys  = ['w1','w2','w3','w4','w5'];
$minggu_labels = ['w1'=>'Pekan ke-1','w2'=>'Pekan ke-2','w3'=>'Pekan ke-3','w4'=>'Pekan ke-4','w5'=>'Pekan ke-5'];
$tahun        = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$selected_opd = isset($_GET['opd_id']) ? (int)$_GET['opd_id'] : 0;

// ========== PROSES KUNCI ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $_SESSION['flash_error'] = "Token keamanan tidak valid.";
        header('Location: lock-realisasi.php?tahun=' . $tahun . '&opd_id=' . $selected_opd);
        exit();
    }

    $opd_cond = ($selected_opd > 0) ? "AND rd.opd_id = ?" : "";
    $opd_param = ($selected_opd > 0) ? [$selected_opd] : [];

    // Kunci semua minggu yang sudah divalidasi
    if (isset($_POST['kunci_semua'])) {
        $sql = "SELECT id, status_mingguan FROM realisasi_detail rd WHERE rd.tahun = ? $opd_cond";
        $params = array_merge([$tahun], $opd_param);
        $types = 'i' . ($selected_opd > 0 ? 'i' : '');
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $status = json_decode($row['status_mingguan'] ?? '{}', true);
            $modified = false;
            foreach ($bulan_keys as $b) {
                foreach ($minggu_keys as $m) {
                    if (($status[$b][$m] ?? 'draft') === 'divalidasi') {
                        $status[$b][$m] = 'dikunci';
                        $modified = true;
                    }
                }
            }
            if ($modified) {
                $new_json = json_encode($status);
                $upd = $conn->prepare("UPDATE realisasi_detail SET status_mingguan = ? WHERE id = ?");
                $upd->bind_param('si', $new_json, $row['id']);
                $upd->execute();
                $upd->close();
                updateBulananFromMingguan($conn, $row['id']);
                $count++;
            }
        }
        $stmt->close();
        $_SESSION['flash_success'] = "Berhasil mengunci semua data tervalidasi ($count record). Kolom bulanan diperbarui.";
    }
    // Kunci per bulan-minggu tertentu
    elseif (isset($_POST['kunci_bulan_minggu'])) {
        $bulan  = $_POST['bulan_key'];
        $minggu = $_POST['minggu'];
        if (in_array($bulan, $bulan_keys) && in_array($minggu, $minggu_keys)) {
            $sql = "SELECT id, status_mingguan FROM realisasi_detail rd WHERE rd.tahun = ? $opd_cond";
            $params = array_merge([$tahun], $opd_param);
            $types = 'i' . ($selected_opd > 0 ? 'i' : '');
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $count = 0;
            while ($row = $result->fetch_assoc()) {
                $status = json_decode($row['status_mingguan'] ?? '{}', true);
                if (($status[$bulan][$minggu] ?? 'draft') === 'divalidasi') {
                    $status[$bulan][$minggu] = 'dikunci';
                    $new_json = json_encode($status);
                    $upd = $conn->prepare("UPDATE realisasi_detail SET status_mingguan = ? WHERE id = ?");
                    $upd->bind_param('si', $new_json, $row['id']);
                    $upd->execute();
                    $upd->close();
                    updateBulananFromMingguan($conn, $row['id']);
                    $count++;
                }
            }
            $stmt->close();
            $bulan_idx = array_search($bulan, $bulan_keys);
            $_SESSION['flash_success'] = "Berhasil mengunci $count data untuk {$bulan_labels[$bulan_idx]} {$minggu_labels[$minggu]}.";
        }
    }
    header('Location: lock-realisasi.php?tahun=' . $tahun . '&opd_id=' . $selected_opd);
    exit();
}

$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Dropdown OPD (prepared)
$opd_list = $conn->prepare("SELECT DISTINCT o.id, o.nama_opd FROM realisasi_detail rd JOIN opd o ON rd.opd_id = o.id WHERE rd.tahun = ? ORDER BY o.nama_opd");
$opd_list->bind_param('i', $tahun);
$opd_list->execute();
$opd_res = $opd_list->get_result();

// Data realisasi
$sql_real = "SELECT rd.id, rd.status_mingguan, 
    rd.volume_jan_w1, rd.volume_jan_w2, rd.volume_jan_w3, rd.volume_jan_w4, rd.volume_jan_w5,
    rd.volume_feb_w1, rd.volume_feb_w2, rd.volume_feb_w3, rd.volume_feb_w4, rd.volume_feb_w5,
    rd.volume_mar_w1, rd.volume_mar_w2, rd.volume_mar_w3, rd.volume_mar_w4, rd.volume_mar_w5,
    rd.volume_apr_w1, rd.volume_apr_w2, rd.volume_apr_w3, rd.volume_apr_w4, rd.volume_apr_w5,
    rd.volume_mei_w1, rd.volume_mei_w2, rd.volume_mei_w3, rd.volume_mei_w4, rd.volume_mei_w5,
    rd.volume_jun_w1, rd.volume_jun_w2, rd.volume_jun_w3, rd.volume_jun_w4, rd.volume_jun_w5,
    rd.volume_jul_w1, rd.volume_jul_w2, rd.volume_jul_w3, rd.volume_jul_w4, rd.volume_jul_w5,
    rd.volume_agu_w1, rd.volume_agu_w2, rd.volume_agu_w3, rd.volume_agu_w4, rd.volume_agu_w5,
    rd.volume_sep_w1, rd.volume_sep_w2, rd.volume_sep_w3, rd.volume_sep_w4, rd.volume_sep_w5,
    rd.volume_okt_w1, rd.volume_okt_w2, rd.volume_okt_w3, rd.volume_okt_w4, rd.volume_okt_w5,
    rd.volume_nov_w1, rd.volume_nov_w2, rd.volume_nov_w3, rd.volume_nov_w4, rd.volume_nov_w5,
    rd.volume_des_w1, rd.volume_des_w2, rd.volume_des_w3, rd.volume_des_w4, rd.volume_des_w5
    FROM realisasi_detail rd WHERE rd.tahun = ?";
$params_real = [$tahun];
$types_real = 'i';
if ($selected_opd > 0) {
    $sql_real .= " AND rd.opd_id = ?";
    $params_real[] = $selected_opd;
    $types_real .= 'i';
}
$stmt_real = $conn->prepare($sql_real);
$stmt_real->bind_param($types_real, ...$params_real);
$stmt_real->execute();
$res = $stmt_real->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt_real->close();

// Statistik
$stats = [];
foreach ($bulan_keys as $b) {
    foreach ($minggu_keys as $m) {
        $stats[$b][$m] = ['draft' => 0, 'divalidasi' => 0, 'dikunci' => 0, 'total_data' => 0];
    }
}
foreach ($rows as $row) {
    $status = json_decode($row['status_mingguan'] ?? '{}', true);
    foreach ($bulan_keys as $b) {
        foreach ($minggu_keys as $m) {
            $st = $status[$b][$m] ?? 'draft';
            $stats[$b][$m]['total_data']++;
            if ($st === 'draft') $stats[$b][$m]['draft']++;
            elseif ($st === 'divalidasi') $stats[$b][$m]['divalidasi']++;
            elseif ($st === 'dikunci') $stats[$b][$m]['dikunci']++;
        }
    }
}

$total_lockable_all = 0;
foreach ($stats as $b => $minggus) {
    foreach ($minggus as $stat) {
        $total_lockable_all += $stat['divalidasi'];
    }
}
?>

<!-- HTML Tampilan -->
<div class="d-flex" style="margin-bottom: 50px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex:1; padding:1.5rem; background-color: #f8f9fc;">
        <div class="container-fluid px-0 px-md-4">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1 text-brown">Kunci Realisasi</h4>
                    <p class="text-muted small mb-0">Tahun <strong><?= $tahun ?></strong></p>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body bg-light bg-opacity-25 p-3 p-md-4">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-sm-5 col-lg-4">
                            <label class="form-label fw-semibold small text-secondary">Pilih OPD</label>
                            <select name="opd_id" class="form-select form-select-sm rounded-pill" onchange="this.form.submit()">
                                <option value="0" <?= $selected_opd == 0 ? 'selected' : '' ?>>🔓 Semua OPD</option>
                                <?php while ($opd = $opd_res->fetch_assoc()): ?>
                                    <option value="<?= (int)$opd['id'] ?>" <?= $selected_opd == $opd['id'] ? 'selected' : '' ?>><?= htmlspecialchars($opd['nama_opd']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <input type="hidden" name="tahun" value="<?= $tahun ?>">
                        <div class="col-auto">
                            <button type="submit" class="btn btn-brown rounded-pill px-4 py-2 shadow-sm fw-semibold">
                                <i class="bi bi-funnel-fill me-2"></i> Tampilkan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($total_lockable_all > 0): ?>
            <div class="d-flex flex-column align-items-start mb-4">
                <form method="POST" onsubmit="return confirm('Kunci SEMUA data yang sudah DIVALIDASI?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <button type="submit" name="kunci_semua" class="btn btn-danger rounded-pill px-4 py-2 shadow-sm fw-semibold">
                        <i class="bi bi-lock-fill me-2"></i>Kunci Semua Data Tervalidasi
                        <span class="badge bg-light text-danger ms-2"><?= $total_lockable_all ?></span>
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-4">
                <?php foreach ($bulan_keys as $index => $b): ?>
                <div class="col">
                    <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden card-month">
                        <div class="card-header">
                            <h6 class="mb-0 fw-bold"><?= htmlspecialchars($bulan_labels[$index]) ?></h6>
                            <?php $total_divalidasi = 0;
                            foreach ($minggu_keys as $m) $total_divalidasi += $stats[$b][$m]['divalidasi'];
                            if ($total_divalidasi > 0): ?>
                                <span class="badge bg-warning text-dark rounded-pill px-2 py-1 small"><?= $total_divalidasi ?> siap</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-3">
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($minggu_keys as $m): 
                                    $stat = $stats[$b][$m];
                                    $jml_divalidasi = $stat['divalidasi'];
                                    $jml_dikunci = $stat['dikunci'];
                                    $total = $stat['total_data'];
                                    $label_minggu = $minggu_labels[$m];
                                    
                                    if ($jml_dikunci > 0): ?>
                                        <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3">
                                            <span class="small fw-semibold text-secondary"><?= htmlspecialchars($label_minggu) ?></span>
                                            <span class="badge bg-dark rounded-pill"><i class="bi bi-lock-fill me-1"></i>Dikunci (<?= $jml_dikunci ?>)</span>
                                        </div>
                                    <?php elseif ($jml_divalidasi > 0): ?>
                                        <div class="d-flex justify-content-between align-items-center bg-danger bg-opacity-10 p-2 rounded-3">
                                            <span class="small fw-semibold text-danger"><?= htmlspecialchars($label_minggu) ?></span>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                <input type="hidden" name="kunci_bulan_minggu" value="1">
                                                <input type="hidden" name="bulan_key" value="<?= htmlspecialchars($b) ?>">
                                                <input type="hidden" name="minggu" value="<?= htmlspecialchars($m) ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill py-0 px-2">
                                                    <i class="bi bi-lock"></i> Kunci (<?= $jml_divalidasi ?>)
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex justify-content-between align-items-center p-2 rounded-3">
                                            <span class="small text-muted"><?= htmlspecialchars($label_minggu) ?></span>
                                            <span class="badge bg-light text-muted rounded-pill"><?= $total == 0 ? 'Kosong' : 'Draft' ?></span>
                                        </div>
                                    <?php endif; ?>
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
    .text-brown { color: #2c4e7a; }
    .btn-brown { background-color: #3e5081; color: #fff; border: none; }
    .btn-brown:hover { background-color: #052364; color: #fff; }
    .card-month:hover { transform: translateY(-3px); box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,0.1)!important; }
    .rounded-4 { border-radius: 1rem !important; }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>