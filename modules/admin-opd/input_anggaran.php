<?php
/**
 * input_anggaran.php - Form input data anggaran manual (tambah/edit)
 * 
 * Keamanan:
 * - Semua query database menggunakan prepared statement (anti SQL injection)
 * - CSRF token di setiap form POST
 * - Validasi input ketat (tipe, rentang, format)
 * - Output escaping dengan htmlspecialchars()
 * - Penanganan error tanpa menampilkan detail mentah ke pengguna
 */

require_once __DIR__ . '/../../includes/header.php';

// ========== OTORISASI ==========
if ($_SESSION['role'] !== 'admin_opd') {
    header('Location: ../dashboard/index.php');
    exit();
}

$opd_id = (int) $_SESSION['opd_id'];
$tahun_sekarang = (int) date('Y');
$tahun = isset($_GET['tahun']) ? (int) $_GET['tahun'] : $tahun_sekarang;
if ($tahun < 2000 || $tahun > 2100) {
    $tahun = $tahun_sekarang;
}

// ========== CSRF TOKEN ==========
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ========== FUNGSI PEMBANTU ==========

/**
 * Mendapatkan versi draft yang aktif.
 * Jika sudah ada data terkunci, cari versi draft tertinggi (>0), jika tidak ada buat baru.
 */
function getActiveDraftVersion($conn, $opd_id, $tahun) {
    // Cek apakah sudah ada data terkunci
    $stmt = $conn->prepare("SELECT COUNT(*) as locked FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['locked'] == 0) {
        return 0;
    }
    // Cari versi draft tertinggi
    $stmt = $conn->prepare("SELECT MAX(versi) as max_draft FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'draft' AND versi > 0");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row['max_draft']) {
        return (int)$row['max_draft'];
    }
    // Buat versi baru = max versi keseluruhan + 1
    $stmt = $conn->prepare("SELECT MAX(versi) as max_v FROM anggaran_detail WHERE opd_id = ? AND tahun = ?");
    $stmt->bind_param('ii', $opd_id, $tahun);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['max_v'] ?? 0)) + 1;
}

// ========== AMBIL KODE PREFIX OPD ==========
$query_opd = "SELECT b.kode as kode_prefix 
              FROM opd o 
              LEFT JOIN master_hierarki b ON o.bidang_id = b.id AND b.level = 2
              WHERE o.id = ?";
$stmt = $conn->prepare($query_opd);
$stmt->bind_param('i', $opd_id);
$stmt->execute();
$res_opd = $stmt->get_result();
$opd_data = $res_opd->fetch_assoc();
$stmt->close();
$kode_prefix = $opd_data['kode_prefix'] ?? '';
$actual_prefix = $kode_prefix;
$placeholder_prefix = 'X.XX';

// ========== AJAX HANDLER (Data Hierarki & Rincian) ==========
if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    // Bersihkan output buffer
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $type = $_POST['type'] ?? '';
    $kode_parent = $_POST['kode_parent'] ?? '';

    if ($type === 'kegiatan') {
        // Level 4
        $suffix = substr($kode_parent, strlen($actual_prefix . '.'));
        $pattern1 = $placeholder_prefix . '.' . $suffix . '.%';
        $pattern2 = $actual_prefix . '.' . $suffix . '.%';
        $stmt = $conn->prepare("SELECT kode, nama FROM master_hierarki WHERE level = 4 AND (kode LIKE ? OR kode LIKE ?) ORDER BY kode");
        $stmt->bind_param('ss', $pattern1, $pattern2);
    } elseif ($type === 'subkegiatan') {
        // Level 5
        $suffix = substr($kode_parent, strlen($actual_prefix . '.'));
        $pattern1 = $placeholder_prefix . '.' . $suffix . '.%';
        $pattern2 = $actual_prefix . '.' . $suffix . '.%';
        $stmt = $conn->prepare("SELECT kode, nama FROM master_hierarki WHERE level = 5 AND (kode LIKE ? OR kode LIKE ?) ORDER BY kode");
        $stmt->bind_param('ss', $pattern1, $pattern2);
    } elseif ($type === 'rincian') {
        // Rincian belanja (kode 6 segmen), query statis aman
        $query = "SELECT id, kode, nama FROM master_belanja 
                  WHERE kode REGEXP '^[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+$'
                  ORDER BY kode";
        $res = $conn->query($query);
        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
        exit;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Tipe tidak valid']);
        exit;
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $data = [];
    while ($row = $res->fetch_assoc()) {
        // Kembalikan placeholder ke kode asli OPD
        $row['kode'] = str_replace($placeholder_prefix, $actual_prefix, $row['kode']);
        $data[] = $row;
    }
    echo json_encode($data);
    exit;
}

// ========== PESAN STATUS ==========
$success = '';
$error = '';
$warning = '';

if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'added':
            $success = 'Data berhasil ditambahkan.';
            break;
        case 'updated':
            $success = 'Data berhasil diupdate.';
            break;
        case 'updated_reset':
            $success = 'Data berhasil diupdate. Status telah dikembalikan ke DRAFT karena ada perubahan.';
            break;
        case 'pergeseran_added':
            $success = 'Data berhasil ditambahkan sebagai Pagu Pergeseran (draft).';
            break;
    }
}

// ========== PROSES EDIT: Konversi data dikunci menjadi draft jika perlu ==========
$edit_data = null;
if (isset($_GET['edit'])) {
    $id_edit = (int) $_GET['edit'];
    $stmt_edit = $conn->prepare("SELECT * FROM anggaran_detail WHERE id = ? AND opd_id = ?");
    $stmt_edit->bind_param('ii', $id_edit, $opd_id);
    $stmt_edit->execute();
    $res_edit = $stmt_edit->get_result();
    $row = $res_edit->fetch_assoc();
    $stmt_edit->close();

    if ($row) {
        if ($row['status_validasi'] === 'dikunci') {
            // Data dikunci, buat salinan draft di versi aktif (atau update jika sudah ada draft)
            $kode_program = $row['kode_program'];
            $kode_kegiatan = $row['kode_kegiatan'];
            $kode_sub_kegiatan = $row['kode_sub_kegiatan'];
            $rincian_id = $row['rincian_belanja_id'];

            $target_versi = getActiveDraftVersion($conn, $opd_id, $tahun);

            // Cek apakah sudah ada draft di versi target
            $cek_ada = $conn->prepare("SELECT id FROM anggaran_detail 
                                       WHERE opd_id = ? AND tahun = ? AND versi = ?
                                         AND kode_program = ? AND kode_kegiatan = ? 
                                         AND kode_sub_kegiatan = ? AND rincian_belanja_id = ?
                                         AND status_validasi IN ('draft','divalidasi')");
            $cek_ada->bind_param('iiisssi', $opd_id, $tahun, $target_versi, $kode_program, $kode_kegiatan, $kode_sub_kegiatan, $rincian_id);
            $cek_ada->execute();
            $res_ada = $cek_ada->get_result();
            if ($draft_ada = $res_ada->fetch_assoc()) {
                $draft_id = (int) $draft_ada['id'];
                $cek_ada->close();
                // Update draft dengan data terbaru dari yang dikunci
                $upd = $conn->prepare("UPDATE anggaran_detail SET 
                    total_volume=?, total_pagu=?,
                    volume_jan=?, volume_feb=?, volume_mar=?, volume_apr=?, volume_mei=?, volume_jun=?,
                    volume_jul=?, volume_agu=?, volume_sep=?, volume_okt=?, volume_nov=?, volume_des=?,
                    pagu_jan=?, pagu_feb=?, pagu_mar=?, pagu_apr=?, pagu_mei=?, pagu_jun=?,
                    pagu_jul=?, pagu_agu=?, pagu_sep=?, pagu_okt=?, pagu_nov=?, pagu_des=?,
                    terakhir_update = NOW()
                    WHERE id = ?");
                $upd->bind_param(
                    'ii' . str_repeat('i', 24) . 'i',
                    $row['total_volume'], $row['total_pagu'],
                    $row['volume_jan'], $row['volume_feb'], $row['volume_mar'], $row['volume_apr'], $row['volume_mei'], $row['volume_jun'],
                    $row['volume_jul'], $row['volume_agu'], $row['volume_sep'], $row['volume_okt'], $row['volume_nov'], $row['volume_des'],
                    $row['pagu_jan'], $row['pagu_feb'], $row['pagu_mar'], $row['pagu_apr'], $row['pagu_mei'], $row['pagu_jun'],
                    $row['pagu_jul'], $row['pagu_agu'], $row['pagu_sep'], $row['pagu_okt'], $row['pagu_nov'], $row['pagu_des'],
                    $draft_id
                );
                $upd->execute();
                $upd->close();
            } else {
                $cek_ada->close();
                // Buat draft baru
                $ins = $conn->prepare("INSERT INTO anggaran_detail 
                    (opd_id, tahun, versi, kode_program, kode_kegiatan, kode_sub_kegiatan, rincian_belanja_id,
                     total_volume, total_pagu,
                     volume_jan, volume_feb, volume_mar, volume_apr, volume_mei, volume_jun,
                     volume_jul, volume_agu, volume_sep, volume_okt, volume_nov, volume_des,
                     pagu_jan, pagu_feb, pagu_mar, pagu_apr, pagu_mei, pagu_jun,
                     pagu_jul, pagu_agu, pagu_sep, pagu_okt, pagu_nov, pagu_des,
                     status_validasi, terakhir_update, perubahan_setelah_validasi)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        'draft', NOW(), 0)");
                $ins->bind_param(
                    'iiisssiii' . str_repeat('i', 24),
                    $opd_id, $tahun, $target_versi,
                    $kode_program, $kode_kegiatan, $kode_sub_kegiatan, $rincian_id,
                    $row['total_volume'], $row['total_pagu'],
                    $row['volume_jan'], $row['volume_feb'], $row['volume_mar'], $row['volume_apr'], $row['volume_mei'], $row['volume_jun'],
                    $row['volume_jul'], $row['volume_agu'], $row['volume_sep'], $row['volume_okt'], $row['volume_nov'], $row['volume_des'],
                    $row['pagu_jan'], $row['pagu_feb'], $row['pagu_mar'], $row['pagu_apr'], $row['pagu_mei'], $row['pagu_jun'],
                    $row['pagu_jul'], $row['pagu_agu'], $row['pagu_sep'], $row['pagu_okt'], $row['pagu_nov'], $row['pagu_des']
                );
                $ins->execute();
                $draft_id = (int) $ins->insert_id;
                $ins->close();
            }
            // Tandai data asli bahwa sudah ada perubahan
            $stmt_mark = $conn->prepare("UPDATE anggaran_detail SET perubahan_setelah_validasi = 1 WHERE id = ?");
            $stmt_mark->bind_param('i', $row['id']);
            $stmt_mark->execute();
            $stmt_mark->close();

            // Ambil data draft untuk ditampilkan di form
            $stmt_draft = $conn->prepare("SELECT * FROM anggaran_detail WHERE id = ?");
            $stmt_draft->bind_param('i', $draft_id);
            $stmt_draft->execute();
            $edit_data = $stmt_draft->get_result()->fetch_assoc();
            $stmt_draft->close();
        } else {
            // Data masih draft/divalidasi, langsung edit
            $edit_data = $row;
        }
    }
}

// ========== PROSES SIMPAN DATA (POST) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Verifikasi CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid. Silakan muat ulang halaman.";
    } else {
        $action = $_POST['action'] ?? '';
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $kode_program = trim($_POST['kode_program'] ?? '');
        $kode_kegiatan = trim($_POST['kode_kegiatan'] ?? '');
        $kode_sub_kegiatan = trim($_POST['kode_sub_kegiatan'] ?? '');
        $rincian_belanja_id = (int) ($_POST['rincian_belanja_id'] ?? 0);

        // Total volume & pagu dari hidden input
        $total_volume = (int) ($_POST['total_volume_hidden'] ?? 0);
        $total_pagu = (int) ($_POST['total_pagu_hidden'] ?? 0);

        $bulan_keys = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
        $volume_bulan = [];
        $pagu_bulan = [];
        foreach ($bulan_keys as $b) {
            $volume_bulan[$b] = (int) ($_POST["volume_{$b}_hidden"] ?? 0);
            $pagu_bulan[$b] = (int) ($_POST["pagu_{$b}_hidden"] ?? 0);
        }

        $sum_volume = array_sum($volume_bulan);
        $sum_pagu = array_sum($pagu_bulan);
        $errors = [];

        // Validasi jumlah per bulan
        if ($sum_volume != $total_volume) {
            $errors[] = "Jumlah volume per bulan (" . number_format($sum_volume) . ") tidak sama dengan total volume (" . number_format($total_volume) . ").";
        }
        if ($sum_pagu != $total_pagu) {
            $errors[] = "Jumlah pagu per bulan (" . number_format($sum_pagu) . ") tidak sama dengan total pagu (" . number_format($total_pagu) . ").";
        }
        if (empty($rincian_belanja_id)) {
            $errors[] = "Rincian belanja harus dipilih.";
        }
        if (empty($kode_program) || empty($kode_kegiatan) || empty($kode_sub_kegiatan)) {
            $errors[] = "Program, Kegiatan, dan Sub Kegiatan harus dipilih.";
        }

        // Deteksi apakah sudah ada data dikunci (pergeseran)
        $stmt_lock = $conn->prepare("SELECT COUNT(*) as cnt FROM anggaran_detail WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'");
        $stmt_lock->bind_param('ii', $opd_id, $tahun);
        $stmt_lock->execute();
        $lock_cnt = $stmt_lock->get_result()->fetch_assoc()['cnt'];
        $stmt_lock->close();
        $is_pergeseran = ($lock_cnt > 0);

        if ($is_pergeseran) {
            $target_versi = getActiveDraftVersion($conn, $opd_id, $tahun);
        } else {
            $target_versi = 0;
        }

        // Cek duplikat hanya untuk tambah baru
        if ($action === 'tambah') {
            $cek_dup = $conn->prepare("SELECT id FROM anggaran_detail 
                                       WHERE opd_id = ? AND tahun = ? AND versi = ?
                                       AND kode_program = ? AND kode_kegiatan = ? 
                                       AND kode_sub_kegiatan = ? AND rincian_belanja_id = ?
                                       AND status_validasi != 'dikunci'");
            $cek_dup->bind_param('iiisssi', $opd_id, $tahun, $target_versi, $kode_program, $kode_kegiatan, $kode_sub_kegiatan, $rincian_belanja_id);
            $cek_dup->execute();
            $res_dup = $cek_dup->get_result();
            if ($res_dup->num_rows > 0) {
                $errors[] = "Data dengan kombinasi yang sama sudah ada pada versi ini. Silakan edit data tersebut.";
            }
            $cek_dup->close();
        }

        // Cek status sebelumnya jika edit
        $was_validated = false;
        if ($action === 'edit') {
            $stmt_st = $conn->prepare("SELECT status_validasi FROM anggaran_detail WHERE id = ? AND opd_id = ?");
            $stmt_st->bind_param('ii', $id, $opd_id);
            $stmt_st->execute();
            $row_st = $stmt_st->get_result()->fetch_assoc();
            if ($row_st && in_array($row_st['status_validasi'], ['divalidasi','dikunci'])) {
                $was_validated = true;
            }
            $stmt_st->close();
        }

        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        } else {
            // Proses simpan
            if ($action === 'tambah') {
                // Tambah data baru
                $versi = $target_versi;
                if ($is_pergeseran && $versi > 0) {
                    // Tandai semua data terkunci bahwa sudah ada perubahan
                    $stmt_mark_all = $conn->prepare("UPDATE anggaran_detail SET perubahan_setelah_validasi = 1 
                                                     WHERE opd_id = ? AND tahun = ? AND status_validasi = 'dikunci'");
                    $stmt_mark_all->bind_param('ii', $opd_id, $tahun);
                    $stmt_mark_all->execute();
                    $stmt_mark_all->close();
                }

                $sql_insert = "INSERT INTO anggaran_detail 
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
                        'draft', NULL, NULL, NOW(), 0)";
                $stmt = $conn->prepare($sql_insert);
                $stmt->bind_param(
                    'iiisssiii' . str_repeat('i', 24),
                    $opd_id, $tahun, $versi,
                    $kode_program, $kode_kegiatan, $kode_sub_kegiatan, $rincian_belanja_id,
                    $total_volume, $total_pagu,
                    $volume_bulan['jan'], $volume_bulan['feb'], $volume_bulan['mar'], $volume_bulan['apr'], $volume_bulan['mei'], $volume_bulan['jun'],
                    $volume_bulan['jul'], $volume_bulan['agu'], $volume_bulan['sep'], $volume_bulan['okt'], $volume_bulan['nov'], $volume_bulan['des'],
                    $pagu_bulan['jan'], $pagu_bulan['feb'], $pagu_bulan['mar'], $pagu_bulan['apr'], $pagu_bulan['mei'], $pagu_bulan['jun'],
                    $pagu_bulan['jul'], $pagu_bulan['agu'], $pagu_bulan['sep'], $pagu_bulan['okt'], $pagu_bulan['nov'], $pagu_bulan['des']
                );
            } else {
                // Edit data
                if ($was_validated) {
                    // Reset status ke draft
                    $sql_update = "UPDATE anggaran_detail SET 
                        kode_program=?, kode_kegiatan=?, kode_sub_kegiatan=?, rincian_belanja_id=?,
                        total_volume=?, total_pagu=?,
                        volume_jan=?, volume_feb=?, volume_mar=?, volume_apr=?, volume_mei=?, volume_jun=?,
                        volume_jul=?, volume_agu=?, volume_sep=?, volume_okt=?, volume_nov=?, volume_des=?,
                        pagu_jan=?, pagu_feb=?, pagu_mar=?, pagu_apr=?, pagu_mei=?, pagu_jun=?,
                        pagu_jul=?, pagu_agu=?, pagu_sep=?, pagu_okt=?, pagu_nov=?, pagu_des=?,
                        status_validasi = 'draft',
                        tanggal_validasi = NULL,
                        tanggal_kunci = NULL,
                        terakhir_update = NOW(),
                        perubahan_setelah_validasi = 1
                        WHERE id=? AND opd_id=?";
                    $stmt = $conn->prepare($sql_update);
                    $stmt->bind_param(
                        'sss' . str_repeat('i', 29),
                        $kode_program, $kode_kegiatan, $kode_sub_kegiatan, $rincian_belanja_id,
                        $total_volume, $total_pagu,
                        $volume_bulan['jan'], $volume_bulan['feb'], $volume_bulan['mar'], $volume_bulan['apr'], $volume_bulan['mei'], $volume_bulan['jun'],
                        $volume_bulan['jul'], $volume_bulan['agu'], $volume_bulan['sep'], $volume_bulan['okt'], $volume_bulan['nov'], $volume_bulan['des'],
                        $pagu_bulan['jan'], $pagu_bulan['feb'], $pagu_bulan['mar'], $pagu_bulan['apr'], $pagu_bulan['mei'], $pagu_bulan['jun'],
                        $pagu_bulan['jul'], $pagu_bulan['agu'], $pagu_bulan['sep'], $pagu_bulan['okt'], $pagu_bulan['nov'], $pagu_bulan['des'],
                        $id, $opd_id
                    );
                } else {
                    // Update biasa tanpa reset status
                    $sql_update = "UPDATE anggaran_detail SET 
                        kode_program=?, kode_kegiatan=?, kode_sub_kegiatan=?, rincian_belanja_id=?,
                        total_volume=?, total_pagu=?,
                        volume_jan=?, volume_feb=?, volume_mar=?, volume_apr=?, volume_mei=?, volume_jun=?,
                        volume_jul=?, volume_agu=?, volume_sep=?, volume_okt=?, volume_nov=?, volume_des=?,
                        pagu_jan=?, pagu_feb=?, pagu_mar=?, pagu_apr=?, pagu_mei=?, pagu_jun=?,
                        pagu_jul=?, pagu_agu=?, pagu_sep=?, pagu_okt=?, pagu_nov=?, pagu_des=?,
                        terakhir_update = NOW()
                        WHERE id=? AND opd_id=?";
                    $stmt = $conn->prepare($sql_update);
                    $stmt->bind_param(
                        'sss' . str_repeat('i', 29),
                        $kode_program, $kode_kegiatan, $kode_sub_kegiatan, $rincian_belanja_id,
                        $total_volume, $total_pagu,
                        $volume_bulan['jan'], $volume_bulan['feb'], $volume_bulan['mar'], $volume_bulan['apr'], $volume_bulan['mei'], $volume_bulan['jun'],
                        $volume_bulan['jul'], $volume_bulan['agu'], $volume_bulan['sep'], $volume_bulan['okt'], $volume_bulan['nov'], $volume_bulan['des'],
                        $pagu_bulan['jan'], $pagu_bulan['feb'], $pagu_bulan['mar'], $pagu_bulan['apr'], $pagu_bulan['mei'], $pagu_bulan['jun'],
                        $pagu_bulan['jul'], $pagu_bulan['agu'], $pagu_bulan['sep'], $pagu_bulan['okt'], $pagu_bulan['nov'], $pagu_bulan['des'],
                        $id, $opd_id
                    );
                }
            }

            if ($stmt->execute()) {
                // Redirect ke data_anggaran.php dengan pesan sukses
                if ($action === 'tambah' && $is_pergeseran) {
                    $msg_param = 'pergeseran_added';
                } else {
                    $msg_param = ($action === 'tambah') ? 'added' : ($was_validated ? 'updated_reset' : 'updated');
                }
                header('Location: data_anggaran.php?tahun=' . $tahun . '&msg=' . $msg_param);
                exit;
            } else {
                error_log("Gagal simpan anggaran: " . $stmt->error);
                $error = "Gagal menyimpan data. Silakan coba lagi.";
            }
            $stmt->close();
        }
    }
}

// ========== DAFTAR PROGRAM ==========
$programs = false;
if ($kode_prefix) {
    $stmt_prog = $conn->prepare("SELECT kode, nama FROM master_hierarki WHERE level = 3 AND (kode LIKE ? OR kode LIKE ?) ORDER BY kode");
    $pattern1 = $placeholder_prefix . '.%';
    $pattern2 = $actual_prefix . '.%';
    $stmt_prog->bind_param('ss', $pattern1, $pattern2);
    $stmt_prog->execute();
    $programs = $stmt_prog->get_result();
} else {
    $error_awal = "OPD ini belum memiliki data Bidang Urusan. Silakan lengkapi di menu Data OPD.";
}
?>

<!-- ==================== TAMPILAN ==================== -->
<style>
/* Style Select2 agar menyerupai form-control */
.select2-container--default .select2-selection--single {
    height: 45px !important;
    border: 1px solid #ced4da !important;
    border-radius: 15px !important;
    padding: 0.375rem 0.75rem !important;
    background-color: #fff !important;
    display: flex;
    align-items: center;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 1.5 !important;
    color: #212529 !important;
    padding-left: 0 !important;
    padding-right: 25px !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px !important;
    right: 8px !important;
}
.select2-container--default .select2-selection--single .select2-selection__placeholder {
    color: #6c757d !important;
}
.select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #86b7fe !important;
    box-shadow: 0 0 0 0.25rem rgba(13,110,253,.25) !important;
}
</style>

<div class="d-flex" style="margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex: 1; padding: 20px; max-width: 100%; overflow-x: hidden;">
        <div class="container-fluid">
            <h3><i class="bi bi-calculator-fill"></i> Input Anggaran Manual</h3>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div> <!-- $error sudah di-escape sebelumnya -->
            <?php endif; ?>
            <?php if (!empty($error_awal)): ?>
                <div class="alert alert-warning"><?= htmlspecialchars($error_awal) ?></div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    <?= $edit_data ? 'Edit Data Anggaran Tahun ' . htmlspecialchars($tahun) : 'Tambah Data Anggaran Baru Tahun ' . htmlspecialchars($tahun) ?>
                    <a href="data_anggaran.php?tahun=<?= $tahun ?>" class="btn btn-sm btn-outline-secondary float-end">
                        <i class="bi bi-arrow-left"></i> Kembali ke Data
                    </a>
                </div>
                <div class="card-body">
                    <form method="POST" id="formAnggaran">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="<?= $edit_data ? 'edit' : 'tambah' ?>">
                        <?php if ($edit_data): ?>
                            <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Program *</label>
                                <select name="kode_program" id="program" class="form-select" required <?= (!$kode_prefix) ? 'disabled' : '' ?>>
                                    <?php if ($kode_prefix && $programs && $programs->num_rows > 0): ?>
                                        <option value="">-- Pilih Program --</option>
                                        <?php while($prog = $programs->fetch_assoc()): 
                                            $prog_kode_display = str_replace($placeholder_prefix, $actual_prefix, $prog['kode']);
                                            $selected = ($edit_data && $edit_data['kode_program'] == $prog_kode_display) ? 'selected' : '';
                                        ?>
                                            <option value="<?= htmlspecialchars($prog_kode_display) ?>" <?= $selected ?>>
                                                <?= htmlspecialchars($prog_kode_display) ?> - <?= htmlspecialchars($prog['nama']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php elseif ($kode_prefix): ?>
                                        <option value="">-- Tidak ada Program untuk OPD ini --</option>
                                    <?php else: ?>
                                        <option value="">-- Bidang Urusan OPD belum diatur --</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Kegiatan *</label>
                                <select name="kode_kegiatan" id="kegiatan" class="form-select" required disabled>
                                    <option value="">-- Pilih Program dulu --</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sub Kegiatan *</label>
                                <select name="kode_sub_kegiatan" id="sub_kegiatan" class="form-select" required disabled>
                                    <option value="">-- Pilih Kegiatan dulu --</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Rincian Belanja (6 segmen) *</label>
                                <select name="rincian_belanja_id" id="rincian_belanja" class="form-select" required disabled>
                                    <option value="">-- Pilih Sub Kegiatan dulu --</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Total Volume *</label>
                                <input type="text" id="total_volume_display" class="form-control number-display" 
                                       value="<?= $edit_data ? number_format($edit_data['total_volume'], 0, ',', '.') : '' ?>" required>
                                <input type="hidden" name="total_volume_hidden" id="total_volume_hidden" 
                                       value="<?= $edit_data ? $edit_data['total_volume'] : '0' ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Total Pagu (Rp) *</label>
                                <input type="text" id="total_pagu_display" class="form-control number-display" 
                                       value="<?= $edit_data ? number_format($edit_data['total_pagu'], 0, ',', '.') : '' ?>" required>
                                <input type="hidden" name="total_pagu_hidden" id="total_pagu_hidden" 
                                       value="<?= $edit_data ? $edit_data['total_pagu'] : '0' ?>">
                            </div>
                        </div>

                        <div class="mt-4">
                            <h6>Rincian Per Bulan <small class="text-danger">* Jumlah per bulan harus sama dengan total</small></h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 30%;">Bulan</th>
                                            <th style="width: 35%;">Volume</th>
                                            <th style="width: 35%;">Pagu (Rp)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $bulan_indonesia = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                        $bulan_keys = ['jan','feb','mar','apr','mei','jun','jul','agu','sep','okt','nov','des'];
                                        for ($i = 0; $i < 12; $i++):
                                            $b = $bulan_keys[$i];
                                            $vol_val = $edit_data ? (int)$edit_data["volume_$b"] : 0;
                                            $pag_val = $edit_data ? (int)$edit_data["pagu_$b"] : 0;
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($bulan_indonesia[$i]) ?></td>
                                            <td>
                                                <input type="text" id="volume_<?= $b ?>_display" class="form-control form-control-sm bulan-volume-display number-display" value="<?= number_format($vol_val,0,',','.') ?>">
                                                <input type="hidden" name="volume_<?= $b ?>_hidden" id="volume_<?= $b ?>_hidden" value="<?= $vol_val ?>">
                                            </td>
                                            <td>
                                                <input type="text" id="pagu_<?= $b ?>_display" class="form-control form-control-sm bulan-pagu-display number-display" value="<?= number_format($pag_val,0,',','.') ?>">
                                                <input type="hidden" name="pagu_<?= $b ?>_hidden" id="pagu_<?= $b ?>_hidden" value="<?= $pag_val ?>">
                                            </td>
                                        </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-secondary">
                                            <th>Total</th>
                                            <th>
                                                <span id="total_bulan_volume">0</span>
                                                <span id="lebih_volume" class="badge bg-danger ms-1" style="display:none;">Telah Lebih!</span>
                                            </th>
                                            <th>
                                                <span id="total_bulan_pagu">0</span>
                                                <span id="lebih_pagu" class="badge bg-danger ms-1" style="display:none;">Telah Lebih!</span>
                                            </th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary-custom">Simpan Data</button>
                            <a href="input_anggaran.php?tahun=<?= $tahun ?>" class="btn btn-secondary">Batal</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Select2 & jQuery -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    var baseUrl = window.location.href.split('?')[0];
    
    // Data edit (jika ada)
    var editKegiatan = "<?= $edit_data ? htmlspecialchars($edit_data['kode_kegiatan'], ENT_QUOTES) : '' ?>";
    var editSubKegiatan = "<?= $edit_data ? htmlspecialchars($edit_data['kode_sub_kegiatan'], ENT_QUOTES) : '' ?>";
    var editRincian = "<?= $edit_data ? (int)$edit_data['rincian_belanja_id'] : '' ?>";
    
    // Inisialisasi Select2 untuk Program
    $('#program').select2({
        placeholder: "-- Pilih Program --",
        allowClear: true,
        width: '100%'
    });

    // Fungsi update hidden dan kalkulasi total bulanan
    function updateHiddenAndCalculate() {
        // Total dari input display
        var totalVol = parseInt($('#total_volume_display').val().replace(/\D/g, '')) || 0;
        var totalPag = parseInt($('#total_pagu_display').val().replace(/\D/g, '')) || 0;
        $('#total_volume_hidden').val(totalVol);
        $('#total_pagu_hidden').val(totalPag);
        
        // Volume per bulan
        var sumVol = 0;
        $('.bulan-volume-display').each(function() {
            var val = parseInt($(this).val().replace(/\D/g, '')) || 0;
            var idHidden = $(this).attr('id').replace('_display', '_hidden');
            $('#' + idHidden).val(val);
            sumVol += val;
        });
        
        // Pagu per bulan
        var sumPag = 0;
        $('.bulan-pagu-display').each(function() {
            var val = parseInt($(this).val().replace(/\D/g, '')) || 0;
            var idHidden = $(this).attr('id').replace('_display', '_hidden');
            $('#' + idHidden).val(val);
            sumPag += val;
        });
        
        $('#total_bulan_volume').text(new Intl.NumberFormat('id-ID').format(sumVol));
        $('#total_bulan_pagu').text(new Intl.NumberFormat('id-ID').format(sumPag));
        
        // Indikator kelebihan
        if (sumVol > totalVol) {
            $('#lebih_volume').show();
            $('#total_bulan_volume').css('color', 'red');
        } else {
            $('#lebih_volume').hide();
            $('#total_bulan_volume').css('color', sumVol !== totalVol ? 'red' : '');
        }
        if (sumPag > totalPag) {
            $('#lebih_pagu').show();
            $('#total_bulan_pagu').css('color', 'red');
        } else {
            $('#lebih_pagu').hide();
            $('#total_bulan_pagu').css('color', sumPag !== totalPag ? 'red' : '');
        }
    }
    
    // Format input angka (tampilkan pemisah ribuan)
    function formatDisplayInput(input) {
        input.on('input', function() {
            var val = $(this).val().replace(/\D/g, '');
            if (val !== '') {
                $(this).val(new Intl.NumberFormat('id-ID').format(parseInt(val)));
            } else {
                $(this).val('');
            }
            updateHiddenAndCalculate();
        });
    }
    
    $('.number-display').each(function() { formatDisplayInput($(this)); });
    updateHiddenAndCalculate();
    
    // Validasi sebelum submit
    $('#formAnggaran').on('submit', function(e) {
        updateHiddenAndCalculate();
        var totalVol = parseInt($('#total_volume_hidden').val()) || 0;
        var totalPag = parseInt($('#total_pagu_hidden').val()) || 0;
        var sumVol = 0, sumPag = 0;
        $('.bulan-volume-display').each(function() { sumVol += parseInt($(this).val().replace(/\D/g, '')) || 0; });
        $('.bulan-pagu-display').each(function() { sumPag += parseInt($(this).val().replace(/\D/g, '')) || 0; });
        
        var errors = [];
        if (sumVol !== totalVol) errors.push("Total volume bulanan tidak sama dengan total volume.");
        if (sumPag !== totalPag) errors.push("Total pagu bulanan tidak sama dengan total pagu.");
        if (!$('#rincian_belanja').val()) errors.push("Silakan pilih Rincian Belanja!");
        
        if (errors.length > 0) {
            alert(errors.join("\n"));
            e.preventDefault();
            return false;
        }
    });
    
    // AJAX untuk hierarki
    $('#program').on('change', function() {
        var kode_program = $(this).val();
        
        // Reset dropdown selanjutnya
        if ($('#kegiatan').hasClass("select2-hidden-accessible")) {
            $('#kegiatan').select2('destroy');
        }
        $('#kegiatan').html('<option value="">-- Memuat... --</option>').prop('disabled', true);
        $('#sub_kegiatan').html('<option value="">-- Pilih Kegiatan dulu --</option>').prop('disabled', true);
        if ($('#sub_kegiatan').hasClass("select2-hidden-accessible")) {
            $('#sub_kegiatan').select2('destroy');
        }
        $('#rincian_belanja').html('<option value="">-- Pilih Sub Kegiatan dulu --</option>').prop('disabled', true);
        if ($('#rincian_belanja').hasClass("select2-hidden-accessible")) {
            $('#rincian_belanja').select2('destroy');
        }
        
        if (kode_program) {
            $.ajax({
                url: baseUrl,
                type: 'POST',
                data: { ajax: 1, type: 'kegiatan', kode_parent: kode_program },
                dataType: 'json',
                success: function(res) {
                    var options = '<option value="">-- Pilih Kegiatan --</option>';
                    $.each(res, function(i, item) {
                        var selected = (item.kode == editKegiatan) ? ' selected' : '';
                        options += `<option value="${item.kode}"${selected}>${item.kode} - ${item.nama}</option>`;
                    });
                    $('#kegiatan').html(options).prop('disabled', false);
                    
                    $('#kegiatan').select2({
                        placeholder: "-- Pilih Kegiatan --",
                        allowClear: true,
                        width: '100%'
                    });
                    
                    if (editKegiatan && $('#kegiatan').val() == editKegiatan) {
                        $('#kegiatan').trigger('change');
                    }
                }
            });
        }
    });
    
    $('#kegiatan').on('change', function() {
        var kode_kegiatan = $(this).val();
        
        if ($('#sub_kegiatan').hasClass("select2-hidden-accessible")) {
            $('#sub_kegiatan').select2('destroy');
        }
        $('#sub_kegiatan').html('<option value="">-- Memuat... --</option>').prop('disabled', true);
        $('#rincian_belanja').html('<option value="">-- Pilih Sub Kegiatan dulu --</option>').prop('disabled', true);
        if ($('#rincian_belanja').hasClass("select2-hidden-accessible")) {
            $('#rincian_belanja').select2('destroy');
        }
        
        if (kode_kegiatan) {
            $.ajax({
                url: baseUrl,
                type: 'POST',
                data: { ajax: 1, type: 'subkegiatan', kode_parent: kode_kegiatan },
                dataType: 'json',
                success: function(res) {
                    var options = '<option value="">-- Pilih Sub Kegiatan --</option>';
                    $.each(res, function(i, item) {
                        var selected = (item.kode == editSubKegiatan) ? ' selected' : '';
                        options += `<option value="${item.kode}"${selected}>${item.kode} - ${item.nama}</option>`;
                    });
                    $('#sub_kegiatan').html(options).prop('disabled', false);
                    
                    $('#sub_kegiatan').select2({
                        placeholder: "-- Pilih Sub Kegiatan --",
                        allowClear: true,
                        width: '100%'
                    });
                    
                    if (editSubKegiatan && $('#sub_kegiatan').val() == editSubKegiatan) {
                        $('#sub_kegiatan').trigger('change');
                    }
                }
            });
        }
    });
    
    $('#sub_kegiatan').on('change', function() {
        var kode_sub = $(this).val();
        var $rincian = $('#rincian_belanja');
        
        if ($rincian.hasClass("select2-hidden-accessible")) {
            $rincian.select2('destroy');
        }
        $rincian.html('<option value="">-- Memuat rincian belanja... --</option>').prop('disabled', true);
        
        if (kode_sub) {
            $.ajax({
                url: baseUrl,
                type: 'POST',
                data: { ajax: 1, type: 'rincian' },
                dataType: 'json',
                success: function(res) {
                    var options = '<option value="">-- Pilih Rincian Belanja --</option>';
                    $.each(res, function(i, item) {
                        var selected = (item.id == editRincian) ? ' selected' : '';
                        options += `<option value="${item.id}"${selected}>${item.kode} - ${item.nama}</option>`;
                    });
                    $rincian.html(options).prop('disabled', false);
                    
                    $rincian.select2({
                        placeholder: "-- Pilih Rincian Belanja --",
                        allowClear: true,
                        width: '100%'
                    });
                    
                    if (editRincian) {
                        $rincian.val(editRincian).trigger('change');
                    }
                }
            });
        } else {
            $rincian.html('<option value="">-- Pilih Sub Kegiatan dulu --</option>').prop('disabled', true);
        }
    });
    
    // Trigger muat data edit jika ada
    <?php if ($edit_data): ?>
    if ($('#program').val()) {
        $('#program').trigger('change');
    }
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>