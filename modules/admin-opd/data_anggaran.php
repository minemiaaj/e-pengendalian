<?php
/**
 * data_anggaran.php - Menampilkan data anggaran per OPD, versi, tahun.
 * Keamanan: prepared statement, validasi input, output escaping.
 */
require_once __DIR__ . '/../../includes/header.php';

// Hanya admin_opd yang bisa akses
if ($_SESSION['role'] !== 'admin_opd') {
    header('Location: ../dashboard/index.php');
    exit();
}

$opd_id = (int) $_SESSION['opd_id'];
$tahun  = isset($_GET['tahun']) ? (int) $_GET['tahun'] : (int) date('Y');
$versi  = isset($_GET['versi']) ? (int) $_GET['versi'] : 0;

// Ambil kode bidang urusan OPD (prepared)
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

$error_awal = '';
if (empty($kode_prefix)) {
    $error_awal = "OPD ini belum memiliki data Bidang Urusan. Silakan lengkapi di menu Data OPD.";
}

// Ambil daftar versi (prepared)
$versi_list = [];
$stmt_vlist = $conn->prepare("SELECT DISTINCT versi FROM anggaran_detail 
                              WHERE opd_id = ? AND tahun = ? 
                              ORDER BY versi");
$stmt_vlist->bind_param('ii', $opd_id, $tahun);
$stmt_vlist->execute();
$res_versi = $stmt_vlist->get_result();
while ($v = $res_versi->fetch_assoc()) {
    $versi_list[] = (int) $v['versi'];
}
$stmt_vlist->close();

// Cek versi terkunci maksimum
$max_locked = null;
$stmt_max = $conn->prepare("SELECT MAX(versi) AS max_v FROM anggaran_detail 
                            WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'");
$stmt_max->bind_param('ii', $opd_id, $tahun);
$stmt_max->execute();
$res_max = $stmt_max->get_result();
$row_max = $res_max->fetch_assoc();
$max_locked = $row_max['max_v'] ?? null;
$stmt_max->close();
$ada_versi_terkunci = !is_null($max_locked);

// Tentukan versi yang ditampilkan
if ($versi == 0) {
    if ($ada_versi_terkunci) {
        $versi = (int) $max_locked;
    } else {
        $versi = !empty($versi_list) ? max($versi_list) : 0;
    }
} else {
    if (!in_array($versi, $versi_list, true)) {
        $versi = $ada_versi_terkunci ? (int) $max_locked : (!empty($versi_list) ? max($versi_list) : 0);
    }
}

// ========== PROSES HAPUS (hanya prepared statements) ==========
$success = '';
$error   = '';
if (isset($_GET['delete'])) {
    $id_delete = (int) $_GET['delete'];
    $stmt_del = $conn->prepare("DELETE FROM anggaran_detail WHERE id = ? AND opd_id = ?");
    $stmt_del->bind_param('ii', $id_delete, $opd_id);
    $stmt_del->execute();
    $stmt_del->close();
    header('Location: data_anggaran.php?tahun=' . $tahun . '&versi=' . $versi . '&msg=deleted');
    exit;
}
if (isset($_GET['delete_all'])) {
    $stmt_all = $conn->prepare("DELETE FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND versi = ?");
    $stmt_all->bind_param('iii', $opd_id, $tahun, $versi);
    $stmt_all->execute();
    $stmt_all->close();
    header('Location: data_anggaran.php?tahun=' . $tahun . '&msg=deleted_all');
    exit;
}
if (isset($_GET['delete_program'])) {
    $kode_prog = $_GET['delete_program'];
    // Validasi format kode: angka dan titik, cegah karakter aneh
    if (!preg_match('/^[0-9.]+$/', $kode_prog)) {
        $error = 'Kode program tidak valid.';
    } else {
        $stmt = $conn->prepare("DELETE FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND versi = ? AND kode_program = ?");
        $stmt->bind_param('iiis', $opd_id, $tahun, $versi, $kode_prog);
        $stmt->execute();
        $stmt->close();
        header('Location: data_anggaran.php?tahun=' . $tahun . '&versi=' . $versi . '&msg=deleted');
        exit;
    }
}
if (isset($_GET['delete_kegiatan'])) {
    $kode_keg = $_GET['delete_kegiatan'];
    if (!preg_match('/^[0-9.]+$/', $kode_keg)) {
        $error = 'Kode kegiatan tidak valid.';
    } else {
        $stmt = $conn->prepare("DELETE FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND versi = ? AND kode_kegiatan = ?");
        $stmt->bind_param('iiis', $opd_id, $tahun, $versi, $kode_keg);
        $stmt->execute();
        $stmt->close();
        header('Location: data_anggaran.php?tahun=' . $tahun . '&versi=' . $versi . '&msg=deleted');
        exit;
    }
}
if (isset($_GET['delete_sub_kegiatan'])) {
    $kode_sub = $_GET['delete_sub_kegiatan'];
    if (!preg_match('/^[0-9.]+$/', $kode_sub)) {
        $error = 'Kode sub kegiatan tidak valid.';
    } else {
        $stmt = $conn->prepare("DELETE FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND versi = ? AND kode_sub_kegiatan = ?");
        $stmt->bind_param('iiis', $opd_id, $tahun, $versi, $kode_sub);
        $stmt->execute();
        $stmt->close();
        header('Location: data_anggaran.php?tahun=' . $tahun . '&versi=' . $versi . '&msg=deleted');
        exit;
    }
}

// Pesan sukses
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'deleted': $success = 'Data berhasil dihapus.'; break;
        case 'added':   $success = 'Data berhasil ditambahkan.'; break;
        case 'updated': $success = 'Data berhasil diupdate.'; break;
        case 'updated_reset': $success = 'Data berhasil diupdate. Status dikembalikan ke DRAFT.'; break;
        case 'deleted_all': $success = 'Semua data pada halaman ini berhasil dihapus.'; break;
    }
}

// Query data sesuai versi (prepared)
$query = "
    SELECT ad.id, ad.kode_program, ad.kode_kegiatan, ad.kode_sub_kegiatan,
           ad.rincian_belanja_id, ad.total_volume, ad.total_pagu,
           ad.status_validasi, ad.perubahan_setelah_validasi, ad.versi,
           mb.kode AS kode_rincian, mb.nama AS nama_rincian
    FROM anggaran_detail ad
    JOIN master_belanja mb ON ad.rincian_belanja_id = mb.id
    WHERE ad.opd_id = ? AND ad.tahun = ? AND ad.versi = ?
    ORDER BY ad.kode_program, ad.kode_kegiatan, ad.kode_sub_kegiatan, mb.kode
";
$stmt = $conn->prepare($query);
$stmt->bind_param('iii', $opd_id, $tahun, $versi);
$stmt->execute();
$result = $stmt->get_result();

$programs = [];
while ($row = $result->fetch_assoc()) {
    $prog = $row['kode_program'];
    $keg  = $row['kode_kegiatan'];
    $sub  = $row['kode_sub_kegiatan'];
    $programs[$prog]['kegiatan'][$keg]['sub_kegiatan'][$sub]['rincian'][] = $row;
}

// Ambil nama dari master_hierarki dengan prepared statement & mapping prefix
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

function getNama($kode, $nama_map) {
    return isset($nama_map[$kode]) ? $nama_map[$kode] : '(tanpa uraian)';
}
function angkaRomawi($n) {
    $n = (int)$n;
    if ($n <= 0) return '';
    $romans = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
    return isset($romans[$n]) ? $romans[$n] : (string)$n;
}
?>

<style>
    .table-uraian td, .table-uraian th { white-space: normal; word-break: break-word; }
    .uraian-cell { max-width: 300px; word-wrap: break-word; white-space: normal; }
    .nowrap-cell { white-space: nowrap; }
    .action-cell { width: 45px; text-align: center; }
</style>

<div class="d-flex" style="margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex: 1; padding: 20px;">
        <div class="container-fluid">
            <h3><i class="bi bi-table"></i> Data Anggaran</h3>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (!empty($error_awal)): ?>
                <div class="alert alert-warning"><?= htmlspecialchars($error_awal) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                    <span>Data Anggaran Tahun <?= $tahun ?></span>
                    <div class="d-flex align-items-center flex-wrap">
                        <label class="me-2">Versi:</label>
                        <select class="form-select form-select-sm" style="width:auto;" onchange="location = this.value;">
                            <?php foreach ($versi_list as $v): 
                                if ($v == 0) {
                                    $label = 'Pagu Awal (Draft)';
                                } else {
                                    $label = ($v == 1) ? 'Pagu Awal' : 'Pagu Pergeseran ' . angkaRomawi($v-1);
                                }
                                $selected = ($v == $versi) ? 'selected' : '';
                            ?>
                                <option value="?tahun=<?= $tahun ?>&versi=<?= $v ?>" <?= $selected ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a href="?tahun=<?= $tahun-1 ?>&versi=<?= $versi ?>" class="btn btn-sm btn-outline-secondary ms-2">← Sebelumnya</a>
                        <a href="?tahun=<?= $tahun+1 ?>&versi=<?= $versi ?>" class="btn btn-sm btn-outline-secondary">Berikutnya →</a>

                        <?php if (!empty($programs)): ?>
                            <a href="?tahun=<?= $tahun ?>&versi=<?= $versi ?>&delete_all=1" 
                               class="btn btn-sm btn-danger ms-3" 
                               onclick="return confirm('Yakin hapus SEMUA data di halaman ini?')">
                               <i class="bi bi-trash"></i> Hapus Semua
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0 table-sm table-uraian">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 10%;">Kode Rekening</th>
                                    <th style="width: 40%;">Uraian</th>
                                    <th style="width: 15%;">Total Pagu (Rp)</th>
                                    <th style="width: 10%;">Volume</th>
                                    <th style="width: 10%;">Status</th>
                                    <th style="width: 8%;">Keterangan</th>
                                    <th style="width: 7%;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($programs)): ?>
                                    <tr><td colspan="7" class="text-center">
                                        <?= !empty($error_awal) ? htmlspecialchars($error_awal) : 'Belum ada data anggaran.' ?>
                                    </td></tr>
                                <?php else: 
                                    $grand_total_pagu = 0;
                                    $grand_total_vol = 0;
                                    $has_rincian = false;

                                    foreach ($programs as $kode_prog => $prog_data): 
                                ?>
                                    <tr class="table-primary fw-bold">
                                        <td class="nowrap-cell"><?= htmlspecialchars($kode_prog) ?></td>
                                        <td class="uraian-cell" colspan="5"><?= htmlspecialchars(getNama($kode_prog, $nama_map)) ?></td>
                                        <td class="action-cell">
                                            <a href="?tahun=<?= $tahun ?>&versi=<?= $versi ?>&delete_program=<?= urlencode($kode_prog) ?>" 
                                               class="btn btn-sm btn-danger" 
                                               onclick="return confirm('Hapus seluruh program <?= htmlspecialchars($kode_prog) ?>?')">
                                               <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php foreach ($prog_data['kegiatan'] as $kode_keg => $keg_data): ?>
                                        <!-- Warna untuk KEGIATAN diubah menjadi table-info (biru muda) -->
                                        <tr class="table-info">
                                            <td class="nowrap-cell"><?= htmlspecialchars($kode_keg) ?></td>
                                            <td class="uraian-cell" colspan="5"><?= htmlspecialchars(getNama($kode_keg, $nama_map)) ?></td>
                                            <td class="action-cell">
                                                <a href="?tahun=<?= $tahun ?>&versi=<?= $versi ?>&delete_kegiatan=<?= urlencode($kode_keg) ?>" 
                                                   class="btn btn-sm btn-danger" 
                                                   onclick="return confirm('Hapus seluruh kegiatan <?= htmlspecialchars($kode_keg) ?>?')">
                                                   <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php foreach ($keg_data['sub_kegiatan'] as $kode_sub => $sub_data): ?>
                                            <!-- Warna untuk SUB KEGIATAN diubah menjadi table-warning (kuning muda) -->
                                            <tr class="table-warning">
                                                <td class="nowrap-cell"><?= htmlspecialchars($kode_sub) ?></td>
                                                <td class="uraian-cell" colspan="5"><?= htmlspecialchars(getNama($kode_sub, $nama_map)) ?></td>
                                                <td class="action-cell">
                                                    <a href="?tahun=<?= $tahun ?>&versi=<?= $versi ?>&delete_sub_kegiatan=<?= urlencode($kode_sub) ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       onclick="return confirm('Hapus seluruh sub‑kegiatan <?= htmlspecialchars($kode_sub) ?>?')">
                                                       <i class="bi bi-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php foreach ($sub_data['rincian'] as $rincian): 
                                                $has_rincian = true;
                                                $target_pag = $rincian['total_pagu'];
                                                $target_vol = $rincian['total_volume'];
                                                $grand_total_pagu += $target_pag;
                                                $grand_total_vol += $target_vol;

                                                $status_badge = '';
                                                if ($rincian['status_validasi'] == 'draft') $status_badge = '<span class="badge bg-secondary">Draft</span>';
                                                elseif ($rincian['status_validasi'] == 'divalidasi') $status_badge = '<span class="badge bg-success">Divalidasi</span>';
                                                elseif ($rincian['status_validasi'] == 'dikunci') $status_badge = '<span class="badge bg-danger">Dikunci</span>';
                                                $keterangan = ($rincian['status_validasi'] == 'dikunci' && $rincian['perubahan_setelah_validasi'] == 1) ? 'Pagu Pergeseran' : '';
                                            ?>
                                                <tr>
                                                    <td class="nowrap-cell"><?= htmlspecialchars($rincian['kode_rincian']) ?></td>
                                                    <td class="uraian-cell"><?= htmlspecialchars($rincian['nama_rincian']) ?></td>
                                                    <td class="text-end"><?= number_format($target_pag, 0, ',', '.') ?></td>
                                                    <td class="text-end"><?= number_format($target_vol, 0, ',', '.') ?></td>
                                                    <td><?= $status_badge ?></td>
                                                    <td><?= htmlspecialchars($keterangan) ?></td>
                                                    <td class="text-nowrap action-cell">
                                                        <a href="input_anggaran.php?edit=<?= $rincian['id'] ?>&tahun=<?= $tahun ?>" class="btn btn-sm btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                                                        <a href="data_anggaran.php?delete=<?= $rincian['id'] ?>&tahun=<?= $tahun ?>&versi=<?= $versi ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin hapus data ini?')" title="Hapus"><i class="bi bi-trash"></i></a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>

                                <?php if ($has_rincian): ?>
                                    <tr class="table-dark fw-bold">
                                        <td colspan="2" class="text-center">TOTAL</td>
                                        <td class="text-end"><?= number_format($grand_total_pagu, 0, ',', '.') ?></td>
                                        <td class="text-end"><?= number_format($grand_total_vol) ?></td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>