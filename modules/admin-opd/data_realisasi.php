<?php
/**
 * data_realisasi.php - Menampilkan data realisasi per bulan.
 * Keamanan: prepared statement, validasi input, output escaping.
 */
require_once __DIR__ . '/../../includes/header.php';

if ($_SESSION['role'] !== 'admin_opd') {
    header('Location: ../dashboard/index.php');
    exit();
}

$opd_id = (int) $_SESSION['opd_id'];
$tahun  = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');
$selected_bulan = isset($_GET['bulan']) ? (int) $_GET['bulan'] : null;
if ($selected_bulan !== null && ($selected_bulan < 1 || $selected_bulan > 12)) {
    $selected_bulan = null;
}

$bulan_keys = ['jan', 'feb', 'mar', 'apr', 'mei', 'jun', 'jul', 'agu', 'sep', 'okt', 'nov', 'des'];
$bulan_indonesia = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Ambil kode bidang urusan OPD
$query_opd = "SELECT b.kode as kode_prefix 
              FROM opd o 
              LEFT JOIN master_hierarki b ON o.bidang_id = b.id AND b.level = 2
              WHERE o.id = ?";
$stmt_opd = $conn->prepare($query_opd);
$stmt_opd->bind_param('i', $opd_id);
$stmt_opd->execute();
$res_opd = $stmt_opd->get_result();
$opd_data = $res_opd->fetch_assoc();
$stmt_opd->close();
$kode_prefix = $opd_data['kode_prefix'] ?? '';
$actual_prefix = $kode_prefix;
$placeholder_prefix = 'X.XX';

$success = '';
$error = '';

$programs = [];
$show_table = false;
$nama_map = [];

if ($selected_bulan !== null) {
    $bulan_key = $bulan_keys[$selected_bulan - 1];
    $show_table = true;

    // ---- Bangun ekspresi kumulatif target dan realisasi sampai bulan terpilih ----
    // Kumulatif target volume & pagu
    $cum_target_vol_parts = [];
    $cum_target_pag_parts = [];
    for ($m = 1; $m <= $selected_bulan; $m++) {
        $key = $bulan_keys[$m - 1];
        $cum_target_vol_parts[] = "COALESCE(target.volume_$key, 0)";
        $cum_target_pag_parts[] = "COALESCE(target.pagu_$key, 0)";
    }
    $cum_target_vol = implode(' + ', $cum_target_vol_parts);
    $cum_target_pag = implode(' + ', $cum_target_pag_parts);

    // Kumulatif realisasi volume & pagu (semua pekan, bulan 1 s/d terpilih)
    $cum_real_vol_parts = [];
    $cum_real_pag_parts = [];
    for ($m = 1; $m <= $selected_bulan; $m++) {
        $key = $bulan_keys[$m - 1];
        for ($w = 1; $w <= 5; $w++) {
            $cum_real_vol_parts[] = "COALESCE(rd.volume_{$key}_w$w, 0)";
            $cum_real_pag_parts[] = "COALESCE(rd.pagu_{$key}_w$w, 0)";
        }
    }
    $cum_real_vol = implode(' + ', $cum_real_vol_parts);
    $cum_real_pag = implode(' + ', $cum_real_pag_parts);

    $query = "
        SELECT 
            target.id AS anggaran_id,
            target.kode_program,
            target.kode_kegiatan,
            target.kode_sub_kegiatan,
            mb.kode AS kode_rincian,
            mb.nama AS nama_rincian,
            target.volume_{$bulan_key} AS target_vol_bulan_ini,
            target.pagu_{$bulan_key}   AS target_pag_bulan_ini,
            ($cum_target_vol)          AS target_vol_sd_bulan_ini,
            ($cum_target_pag)          AS target_pag_sd_bulan_ini,
            ($cum_real_vol)            AS realisasi_vol,
            ($cum_real_pag)            AS realisasi_pag,
            rd.id                      AS realisasi_id,
            COALESCE(rd.status_{$bulan_key}, 'draft') AS status_bulan,
            rd.status_mingguan
        FROM (
            SELECT 
                ad.*,
                ROW_NUMBER() OVER (
                    PARTITION BY ad.kode_program, ad.kode_kegiatan, ad.kode_sub_kegiatan, ad.rincian_belanja_id 
                    ORDER BY ad.versi DESC
                ) AS rn
            FROM anggaran_detail ad
            WHERE ad.opd_id = ? AND ad.tahun = ? AND ad.status_validasi = 'dikunci'
        ) target
        JOIN master_belanja mb ON target.rincian_belanja_id = mb.id
        LEFT JOIN realisasi_detail rd 
            ON target.id = rd.anggaran_detail_id 
            AND rd.opd_id = target.opd_id 
            AND rd.tahun = target.tahun
        WHERE target.rn = 1
        ORDER BY target.kode_program, target.kode_kegiatan, target.kode_sub_kegiatan, mb.kode
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $prog = $row['kode_program'];
        $keg  = $row['kode_kegiatan'];
        $sub  = $row['kode_sub_kegiatan'];
        $programs[$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub]['rincian'][] = $row;
    }
    $stmt->close();

    // Ambil nama hierarki (sama seperti sebelumnya)
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

    if (!empty($all_codes)) {
        $query_codes = [];
        foreach ($all_codes as $code) {
            $query_codes[] = $code;
            if (strpos($code, $actual_prefix . '.') === 0) {
                $query_codes[] = $placeholder_prefix . substr($code, strlen($actual_prefix));
            }
            if (strpos($code, $placeholder_prefix . '.') === 0) {
                $query_codes[] = $actual_prefix . substr($code, strlen($placeholder_prefix));
            }
        }
        $query_codes = array_unique($query_codes);
        if (!empty($query_codes)) {
            $placeholders = implode(',', array_fill(0, count($query_codes), '?'));
            $types = str_repeat('s', count($query_codes));
            $stmt_nama = $conn->prepare("SELECT kode, nama FROM master_hierarki WHERE kode IN ($placeholders)");
            $stmt_nama->bind_param($types, ...$query_codes);
            $stmt_nama->execute();
            $res_nama = $stmt_nama->get_result();
            while ($nm = $res_nama->fetch_assoc()) {
                $kode_db = $nm['kode'];
                if (strpos($kode_db, $placeholder_prefix . '.') === 0) {
                    $kode_asli = $actual_prefix . substr($kode_db, strlen($placeholder_prefix));
                } else {
                    $kode_asli = $kode_db;
                }
                $nama_map[$kode_asli] = $nm['nama'];
            }
            $stmt_nama->close();
        }
    }
}

function getNama($kode, $nama_map) {
    return isset($nama_map[$kode]) ? $nama_map[$kode] : '(tanpa uraian)';
}
?>

<style>
    .table-uraian td, .table-uraian th { white-space: normal; word-break: break-word; }
    .uraian-cell { max-width: 300px; word-wrap: break-word; white-space: normal; }
    .nowrap-cell { white-space: nowrap; }
</style>

<div class="d-flex" style="margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <h3><i class="bi bi-table"></i> Data Realisasi</h3>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Tahun</label>
                            <select name="tahun" class="form-select">
                                <?php for ($y = date('Y')-2; $y <= date('Y')+1; $y++): ?>
                                    <option value="<?= $y ?>" <?= $tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Bulan</label>
                            <select name="bulan" class="form-select">
                                <option value="">-- Pilih Bulan --</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= $selected_bulan == $i ? 'selected' : '' ?>><?= $bulan_indonesia[$i-1] ?></option>
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
                <div class="alert alert-info text-center">
                    Silakan pilih bulan terlebih dahulu untuk melihat data realisasi.
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-header">
                        Data Realisasi Bulan <?= $bulan_indonesia[$selected_bulan-1] ?> Tahun <?= $tahun ?>
                        <small class="text-muted">(hanya menampilkan versi anggaran yang sudah dikunci)</small>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0 table-sm table-uraian">
                                <thead class="table-light">
                                    <tr>
                                        <th rowspan="2" style="width: 8%;">Kode Rekening</th>
                                        <th rowspan="2" style="width: 35%;">Uraian</th>
                                        <th colspan="4" class="text-center" style="width: 24%;">Volume</th>
                                        <th colspan="4" class="text-center" style="width: 24%;">Anggaran (Rp)</th>
                                        <th rowspan="2" style="width: 8%;">Aksi</th>
                                    </tr>
                                    <tr>
                                        <th>Target Bulan Ini</th>
                                        <th>Target s.d Bulan Ini</th>
                                        <th>Realisasi (Vol)</th>
                                        <th>Realisasi (%)</th>
                                        <th>Target Bulan Ini</th>
                                        <th>Target s.d Bulan Ini</th>
                                        <th>Realisasi (Rp)</th>
                                        <th>Realisasi (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($programs)): ?>
                                        <tr><td colspan="11" class="text-center">
                                            Tidak ada data anggaran yang sudah dikunci untuk tahun <?= $tahun ?>.
                                        </td></tr>
                                    <?php else: 
                                        $grand_t_vol_bulan = 0;
                                        $grand_t_vol_sd    = 0;
                                        $grand_r_vol       = 0;
                                        $grand_t_pag_bulan = 0;
                                        $grand_t_pag_sd    = 0;
                                        $grand_r_pag       = 0;
                                        $has_rincian = false;

                                        foreach ($programs as $kode_prog => $prog_data): 
                                    ?>
                                        <tr class="table-primary fw-bold">
                                            <td class="nowrap-cell"><?= htmlspecialchars($kode_prog) ?></td>
                                            <td class="uraian-cell" colspan="10"><?= htmlspecialchars(getNama($kode_prog, $nama_map)) ?></td>
                                        </tr>
                                        <?php foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data): ?>
                                            <tr class="table-warning">
                                                <td class="nowrap-cell"><?= htmlspecialchars($kode_keg) ?></td>
                                                <td class="uraian-cell" colspan="10"><?= htmlspecialchars(getNama($kode_keg, $nama_map)) ?></td>
                                            </tr>
                                            <?php foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data): ?>
                                                <tr class="table-info">
                                                    <td class="nowrap-cell"><?= htmlspecialchars($kode_sub) ?></td>
                                                    <td class="uraian-cell" colspan="10"><?= htmlspecialchars(getNama($kode_sub, $nama_map)) ?></td>
                                                </tr>
                                                <?php foreach ($sub_data['rincian'] as $rincian): 
                                                    $has_rincian = true;
                                                    $target_vol_bulan  = $rincian['target_vol_bulan_ini'];
                                                    $target_vol_sd     = $rincian['target_vol_sd_bulan_ini'];
                                                    $real_vol          = $rincian['realisasi_vol'];
                                                    $persen_vol        = ($target_vol_sd != 0) ? round(($real_vol / $target_vol_sd) * 100, 2) : 0;

                                                    $target_pag_bulan  = $rincian['target_pag_bulan_ini'];
                                                    $target_pag_sd     = $rincian['target_pag_sd_bulan_ini'];
                                                    $real_pag          = $rincian['realisasi_pag'];
                                                    $persen_pag        = ($target_pag_sd != 0) ? round(($real_pag / $target_pag_sd) * 100, 2) : 0;

                                                    $grand_t_vol_bulan += $target_vol_bulan;
                                                    $grand_t_vol_sd    += $target_vol_sd;
                                                    $grand_r_vol       += $real_vol;
                                                    $grand_t_pag_bulan += $target_pag_bulan;
                                                    $grand_t_pag_sd    += $target_pag_sd;
                                                    $grand_r_pag       += $real_pag;

                                                    // Status kunci mingguan (hanya bulan terpilih)
                                                    $status_mingguan = json_decode($rincian['status_mingguan'] ?? '{}', true);
                                                    $all_locked = true;
                                                    for ($w = 1; $w <= 5; $w++) {
                                                        $key = "w{$w}";
                                                        if (($status_mingguan[$bulan_key][$key] ?? 'draft') !== 'dikunci') {
                                                            $all_locked = false;
                                                            break;
                                                        }
                                                    }
                                                    $is_locked = $all_locked;
                                                ?>
                                                    <tr>
                                                        <td class="nowrap-cell"><?= htmlspecialchars($rincian['kode_rincian']) ?></td>
                                                        <td class="uraian-cell"><?= htmlspecialchars($rincian['nama_rincian']) ?></td>
                                                        <!-- Volume -->
                                                        <td class="text-end"><?= number_format($target_vol_bulan, 2, ',', '.') ?></td>
                                                        <td class="text-end"><?= number_format($target_vol_sd, 2, ',', '.') ?></td>
                                                        <td class="text-end"><?= number_format($real_vol, 2, ',', '.') ?></td>
                                                        <td class="text-center nowrap-cell"><?= $persen_vol ?>%</td>
                                                        <!-- Anggaran -->
                                                        <td class="text-end"><?= number_format($target_pag_bulan, 0, ',', '.') ?></td>
                                                        <td class="text-end"><?= number_format($target_pag_sd, 0, ',', '.') ?></td>
                                                        <td class="text-end"><?= number_format($real_pag, 0, ',', '.') ?></td>
                                                        <td class="text-center nowrap-cell"><?= $persen_pag ?>%</td>
                                                        <!-- Aksi -->
                                                        <td class="nowrap-cell text-center">
                                                            <?php if ($is_locked): ?>
                                                                <button class="btn btn-sm btn-secondary" disabled title="Semua pekan di bulan ini sudah dikunci">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <a href="input_realisasi.php?anggaran_id=<?= $rincian['anggaran_id'] ?>&tahun=<?= $tahun ?>&bulan=<?= $selected_bulan ?>" class="btn btn-sm btn-warning" title="Edit">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>

                                    <?php if ($has_rincian): 
                                        $grand_persen_vol = ($grand_t_vol_sd != 0) ? round(($grand_r_vol / $grand_t_vol_sd) * 100, 2) : 0;
                                        $grand_persen_pag = ($grand_t_pag_sd != 0) ? round(($grand_r_pag / $grand_t_pag_sd) * 100, 2) : 0;
                                    ?>
                                        <tr class="table-dark fw-bold">
                                            <td colspan="2" class="text-center">TOTAL</td>
                                            <td class="text-end"><?= number_format($grand_t_vol_bulan, 2, ',', '.') ?></td>
                                            <td class="text-end"><?= number_format($grand_t_vol_sd, 2, ',', '.') ?></td>
                                            <td class="text-end"><?= number_format($grand_r_vol, 2, ',', '.') ?></td>
                                            <td class="text-center nowrap-cell"><?= $grand_persen_vol ?>%</td>
                                            <td class="text-end"><?= number_format($grand_t_pag_bulan, 0, ',', '.') ?></td>
                                            <td class="text-end"><?= number_format($grand_t_pag_sd, 0, ',', '.') ?></td>
                                            <td class="text-end"><?= number_format($grand_r_pag, 0, ',', '.') ?></td>
                                            <td class="text-center nowrap-cell"><?= $grand_persen_pag ?>%</td>
                                            <td></td>
                                        </tr>
                                    <?php endif; ?>
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