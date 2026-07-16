<?php
/**
 * program.php - Kelola Master Hierarki (Urusan, Bidang, Program, Kegiatan, Sub Kegiatan)
 *
 * Keamanan:
 * - Prepared statement untuk semua query
 * - CSRF token pada setiap form
 * - Validasi upload file
 * - Output escaping
 * - Filter, pencarian, dan halaman dipertahankan setelah aksi
 */
set_time_limit(0);
ini_set('memory_limit', '1024M');
require_once __DIR__ . '/../../includes/header.php';

if ($_SESSION['role'] !== 'super_admin') {
    header('Location: ../../index.php');
    exit();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Flash message
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type    = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// ===================== PARAMETER FILTER & PAGING =====================
$limit = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;
$filter_level = isset($_GET['filter_level']) ? (int)$_GET['filter_level'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Simpan filter ke session agar tersedia setelah POST (edit/tambah/import)
if (!empty($_GET)) {
    $_SESSION['last_filter'] = [
        'filter_level' => $filter_level,
        'search'       => $search,
        'page'         => $page
    ];
}
$last_filter = $_SESSION['last_filter'] ?? ['filter_level' => 0, 'search' => '', 'page' => 1];

// Fungsi untuk membangun query string redirect dengan parameter filter & halaman
function buildRedirectParams($overrides = []) {
    global $last_filter;
    $params = array_merge([
        'filter_level' => $last_filter['filter_level'] ?? 0,
        'search'       => $last_filter['search'] ?? '',
        'page'         => $last_filter['page'] ?? 1,
    ], $overrides);
    // Hanya sertakan parameter yang memiliki nilai
    $filtered = array_filter($params, function($v) {
        return $v !== '' && $v !== 0 && $v !== '0';
    });
    // page tidak boleh 0
    if (isset($filtered['page']) && $filtered['page'] == 1) {
        unset($filtered['page']); // default page 1 tidak perlu ditampilkan
    }
    $query = http_build_query($filtered);
    return $query ? '?' . $query : '';
}

// ===================== PROSES HAPUS SEMUA =====================
if (isset($_GET['hapus_semua']) && $_GET['hapus_semua'] == '1') {
    if ($_SESSION['role'] == 'super_admin') {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        $conn->query("DELETE FROM master_hierarki");
        $conn->query("ALTER TABLE master_hierarki AUTO_INCREMENT = 1");
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        $_SESSION['flash_message'] = "Semua data program berhasil dihapus!";
        $_SESSION['flash_type'] = "success";
    } else {
        $_SESSION['flash_message'] = "Anda tidak memiliki akses!";
        $_SESSION['flash_type'] = "danger";
    }
    header('Location: program.php' . buildRedirectParams(['page' => 1]));
    exit();
}

// ===================== PROSES HAPUS SATU DATA =====================
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        $stmt = $conn->prepare("DELETE FROM master_hierarki WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $conn->query("SET FOREIGN_KEY_CHECKS=1");

        if ($affected > 0) {
            $_SESSION['flash_message'] = "Data berhasil dihapus.";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Data tidak ditemukan.";
            $_SESSION['flash_type'] = "warning";
        }
    }
    header('Location: program.php' . buildRedirectParams());
    exit();
}

// ===================== IMPORT EXCEL =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_excel'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $_SESSION['flash_message'] = "Token keamanan tidak valid.";
        $_SESSION['flash_type'] = "danger";
        header('Location: program.php' . buildRedirectParams());
        exit();
    }

    require_once __DIR__ . '/../../vendor/autoload.php';

    $file = $_FILES['file_excel'];
    $allowed_ext = ['xlsx', 'xls'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed_mime = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel'
    ];
    if (!in_array($ext, $allowed_ext) || !in_array($mime, $allowed_mime)) {
        $_SESSION['flash_message'] = "Hanya file Excel (.xlsx, .xls) yang diizinkan.";
        $_SESSION['flash_type'] = "danger";
        header('Location: program.php' . buildRedirectParams());
        exit();
    }

    $target_dir = __DIR__ . '/../../uploads/temp/';
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0750, true);
        file_put_contents($target_dir . '.htaccess', "Deny from all\n");
    }
    $safe_filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $target_file = $target_dir . $safe_filename;

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($target_file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

            array_shift($rows);
            array_shift($rows);

            $cache = [];
            $insert_count = 0;
            $conn->begin_transaction();

            $stmtInsert = $conn->prepare("INSERT INTO master_hierarki (kode, nama, level, parent_id) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE nama = VALUES(nama), level = VALUES(level), parent_id = VALUES(parent_id)");
            $stmtFind = $conn->prepare("SELECT id FROM master_hierarki WHERE kode = ?");
            $stmtGetId = $conn->prepare("SELECT id FROM master_hierarki WHERE kode = ?");

            foreach ($rows as $row) {
                $colA = trim($row['A'] ?? '');
                $colB = trim($row['B'] ?? '');
                $colC = trim($row['C'] ?? '');
                $colD = trim($row['D'] ?? '');
                $colE = trim($row['E'] ?? '');
                $colF = trim($row['F'] ?? '');

                if ($colA === '') continue;

                $kode = '';
                $nama = '';
                $level = 0;
                $parent_kode = null;

                if (!empty($colA) && empty($colB) && empty($colC) && empty($colD) && empty($colE)) {
                    $kode = $colA;
                    $nama = !empty($colF) ? $colF : "Urusan $colA";
                    $level = 1;
                } elseif (!empty($colA) && !empty($colB) && empty($colC) && empty($colD) && empty($colE)) {
                    $kode = $colA . '.' . $colB;
                    $nama = !empty($colF) ? $colF : "Bidang $kode";
                    $level = 2;
                    $parent_kode = $colA;
                } elseif (!empty($colA) && !empty($colB) && !empty($colC) && empty($colD) && empty($colE)) {
                    $kode = $colA . '.' . $colB . '.' . $colC;
                    $nama = !empty($colF) ? $colF : "Program $kode";
                    $level = 3;
                    $parent_kode = $colA . '.' . $colB;
                } elseif (!empty($colA) && !empty($colB) && !empty($colC) && !empty($colD) && empty($colE)) {
                    $colD_fixed = str_replace(',', '.', $colD);
                    $kode = $colA . '.' . $colB . '.' . $colC . '.' . $colD_fixed;
                    $nama = !empty($colF) ? $colF : "Kegiatan $kode";
                    $level = 4;
                    $parent_kode = $colA . '.' . $colB . '.' . $colC;
                } elseif (!empty($colA) && !empty($colB) && !empty($colC) && !empty($colD) && !empty($colE)) {
                    $colD_fixed = str_replace(',', '.', $colD);
                    $kode = $colA . '.' . $colB . '.' . $colC . '.' . $colD_fixed . '.' . $colE;
                    $nama = !empty($colF) ? $colF : "Sub Kegiatan $kode";
                    $level = 5;
                    $parent_kode = $colA . '.' . $colB . '.' . $colC . '.' . $colD_fixed;
                } else {
                    continue;
                }

                $parent_id = null;
                if ($parent_kode !== null) {
                    if (isset($cache[$parent_kode])) {
                        $parent_id = $cache[$parent_kode];
                    } else {
                        $stmtFind->bind_param('s', $parent_kode);
                        $stmtFind->execute();
                        $res = $stmtFind->get_result();
                        if ($row_parent = $res->fetch_assoc()) {
                            $parent_id = (int)$row_parent['id'];
                            $cache[$parent_kode] = $parent_id;
                        }
                        $stmtFind->free_result();
                    }
                }

                $stmtInsert->bind_param('ssii', $kode, $nama, $level, $parent_id);
                $stmtInsert->execute();

                $id = $conn->insert_id;
                if (!$id) {
                    $stmtGetId->bind_param('s', $kode);
                    $stmtGetId->execute();
                    $resGet = $stmtGetId->get_result();
                    if ($rowGet = $resGet->fetch_assoc()) {
                        $id = (int)$rowGet['id'];
                    }
                    $stmtGetId->free_result();
                }
                $cache[$kode] = $id;
                $insert_count++;
            }

            $stmtInsert->close();
            $stmtFind->close();
            $stmtGetId->close();
            $conn->commit();

            $_SESSION['flash_message'] = "Data program berhasil diimpor! Total $insert_count data diproses.";
            $_SESSION['flash_type'] = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message'] = "Gagal mengimpor data: " . htmlspecialchars($e->getMessage());
            $_SESSION['flash_type'] = "danger";
        }
        @unlink($target_file);
    } else {
        $_SESSION['flash_message'] = "Gagal upload file.";
        $_SESSION['flash_type'] = "danger";
    }
    header('Location: program.php' . buildRedirectParams());
    exit();
}

// ===================== TAMBAH MANUAL =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_manual'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $_SESSION['flash_message'] = "Token keamanan tidak valid.";
        $_SESSION['flash_type'] = "danger";
        header('Location: program.php' . buildRedirectParams());
        exit();
    }

    $kode        = trim($_POST['kode'] ?? '');
    $nama        = trim($_POST['nama'] ?? '');
    $level       = (int)($_POST['level'] ?? 0);
    $parent_kode = trim($_POST['parent_kode'] ?? '');

    if (empty($kode) || empty($nama) || $level < 1 || $level > 5) {
        $_SESSION['flash_message'] = "Kode, Nama, dan Level harus diisi dengan benar.";
        $_SESSION['flash_type'] = "danger";
    } else {
        $parent_id = null;
        if (!empty($parent_kode)) {
            $stmt = $conn->prepare("SELECT id FROM master_hierarki WHERE kode = ?");
            $stmt->bind_param('s', $parent_kode);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $parent_id = (int)$row['id'];
            } else {
                $_SESSION['flash_message'] = "Parent kode tidak ditemukan!";
                $_SESSION['flash_type'] = "danger";
                header('Location: program.php' . buildRedirectParams());
                exit();
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("INSERT INTO master_hierarki (kode, nama, level, parent_id) 
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE nama = VALUES(nama), parent_id = VALUES(parent_id)");
        $stmt->bind_param('ssii', $kode, $nama, $level, $parent_id);
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = "Data berhasil ditambahkan!";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Gagal menambahkan: " . htmlspecialchars($stmt->error);
            $_SESSION['flash_type'] = "danger";
        }
        $stmt->close();
    }
    header('Location: program.php' . buildRedirectParams());
    exit();
}

// ===================== PROSES EDIT =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $_SESSION['flash_message'] = "Token keamanan tidak valid.";
        $_SESSION['flash_type'] = "danger";
        header('Location: program.php' . buildRedirectParams());
        exit();
    }

    $id          = (int)($_POST['id'] ?? 0);
    $kode        = trim($_POST['kode'] ?? '');
    $nama        = trim($_POST['nama'] ?? '');
    $level       = (int)($_POST['level'] ?? 0);
    $parent_kode = trim($_POST['parent_kode'] ?? '');

    if ($id <= 0 || empty($kode) || empty($nama) || $level < 1 || $level > 5) {
        $_SESSION['flash_message'] = "Data tidak lengkap.";
        $_SESSION['flash_type'] = "danger";
    } else {
        $parent_id = null;
        if (!empty($parent_kode)) {
            $stmt = $conn->prepare("SELECT id FROM master_hierarki WHERE kode = ?");
            $stmt->bind_param('s', $parent_kode);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $parent_id = (int)$row['id'];
            } else {
                $_SESSION['flash_message'] = "Parent kode tidak ditemukan!";
                $_SESSION['flash_type'] = "danger";
                header('Location: program.php' . buildRedirectParams());
                exit();
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE master_hierarki SET kode=?, nama=?, level=?, parent_id=? WHERE id=?");
        $stmt->bind_param('ssiii', $kode, $nama, $level, $parent_id, $id);
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = "Data berhasil diperbarui!";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Gagal update: " . htmlspecialchars($stmt->error);
            $_SESSION['flash_type'] = "danger";
        }
        $stmt->close();
    }
    header('Location: program.php' . buildRedirectParams());
    exit();
}

// ===================== AMBIL DATA UNTUK TABEL =====================
$where_clauses = [];
$params = [];
$types = '';

if ($filter_level > 0) {
    $where_clauses[] = "level = ?";
    $params[] = $filter_level;
    $types .= 'i';
}
if (!empty($search)) {
    $where_clauses[] = "(kode LIKE ? OR nama LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(' AND ', $where_clauses);
}

// Hitung total
$count_sql = "SELECT COUNT(*) as total FROM master_hierarki $where_sql";
$stmt_count = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_rows = (int)$stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_pages = ceil($total_rows / $limit);

// Ambil data
$data_sql = "SELECT * FROM master_hierarki $where_sql ORDER BY kode LIMIT ?, ?";
$types_data = $types . 'ii';
$params_data = $params;
$params_data[] = $offset;
$params_data[] = $limit;
$stmt_data = $conn->prepare($data_sql);
if (!empty($params_data)) {
    $stmt_data->bind_param($types_data, ...$params_data);
}
$stmt_data->execute();
$data = $stmt_data->get_result();

// Ambil mapping parent_id => kode untuk modal edit
$parentKodeMap = [];
$all_ids = [];
while ($row = $data->fetch_assoc()) {
    if ($row['parent_id']) $all_ids[] = (int)$row['parent_id'];
}
$data->data_seek(0); // kembalikan pointer

if (!empty($all_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
    $types_pm = str_repeat('i', count($all_ids));
    $stmt_pm = $conn->prepare("SELECT id, kode FROM master_hierarki WHERE id IN ($placeholders)");
    $stmt_pm->bind_param($types_pm, ...$all_ids);
    $stmt_pm->execute();
    $res_pm = $stmt_pm->get_result();
    while ($p = $res_pm->fetch_assoc()) {
        $parentKodeMap[(int)$p['id']] = $p['kode'];
    }
    $stmt_pm->close();
}
?>
<!-- ==================== TAMPILAN ==================== -->
<div class="d-flex" style="max-width: 100vw; overflow-x: auto; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content">
        <h3><i class="bi bi-diagram-3"></i> Program (Hierarki)</h3>

        <?php if ($flash_message): ?>
            <div class="alert alert-<?= htmlspecialchars($flash_type) ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($flash_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- IMPORT EXCEL -->
        <div class="card mb-4">
            <div class="card-header">Import Excel</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="mb-3">
                        <input type="file" name="file_excel" class="form-control" accept=".xlsx,.xls" required>
                        <small class="text-muted">
                            <ul>
                                <li>Kolom A = Urusan, B = Bidang, C = Program, D = Kegiatan (koma jadi titik), E = Sub Kegiatan, F = Uraian</li>
                                <li>Baris ke-3 mulai data</li>
                                <li><strong>Kolom A bisa berisi teks.</strong></li>
                            </ul>
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary-custom">Upload & Import</button>
                </form>
            </div>
        </div>

        <!-- TAMBAH MANUAL -->
        <div class="card mb-4">
            <div class="card-header">Tambah Manual</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="tambah_manual" value="1">
                    <div class="col-md-3">
                        <label class="form-label">Kode</label>
                        <input type="text" name="kode" class="form-control" required maxlength="100">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Nama</label>
                        <input type="text" name="nama" class="form-control" required maxlength="255">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Level</label>
                        <select name="level" class="form-select" required>
                            <option value="1">Urusan</option>
                            <option value="2">Bidang</option>
                            <option value="3">Program</option>
                            <option value="4">Kegiatan</option>
                            <option value="5">Sub Kegiatan</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Parent Kode</label>
                        <input type="text" name="parent_kode" class="form-control" placeholder="Contoh: 1.1.2">
                        <small class="text-muted">Kosongkan jika tidak ada</small>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-success w-100">Tambah</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- FILTER, PENCARIAN, DAN HAPUS SEMUA -->
        <div class="card">
            <div class="card-header">
                <div class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <form method="GET" class="d-flex">
                            <select name="filter_level" class="form-select me-2">
                                <option value="0" <?= $filter_level == 0 ? 'selected' : '' ?>>-- Semua Level --</option>
                                <option value="1" <?= $filter_level == 1 ? 'selected' : '' ?>>Urusan</option>
                                <option value="2" <?= $filter_level == 2 ? 'selected' : '' ?>>Bidang</option>
                                <option value="3" <?= $filter_level == 3 ? 'selected' : '' ?>>Program</option>
                                <option value="4" <?= $filter_level == 4 ? 'selected' : '' ?>>Kegiatan</option>
                                <option value="5" <?= $filter_level == 5 ? 'selected' : '' ?>>Sub Kegiatan</option>
                            </select>
                            <button type="submit" class="btn btn-primary-custom">Filter</button>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <form method="GET" class="d-flex">
                            <input type="hidden" name="filter_level" value="<?= $filter_level ?>">
                            <input type="text" name="search" class="form-control me-2" placeholder="Cari kode atau nama..." value="<?= htmlspecialchars($search) ?>">
                            <button type="submit" class="btn btn-primary-custom">Cari</button>
                        </form>
                    </div>
                    <div class="col-md-3">
                        <span class="badge bg-secondary">Total data: <?= number_format($total_rows) ?></span>
                    </div>
                    <div class="col-md-2 text-end">
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalHapusSemua">
                            <i class="bi bi-trash"></i> Hapus Semua
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom table-hover">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Nama</th>
                                <th>Level</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($data->num_rows == 0): ?>
                                <tr><td colspan="4" class="text-center">Tidak ada data.</td></tr>
                            <?php else: ?>
                                <?php while ($row = $data->fetch_assoc()): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($row['kode']) ?></code></td>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
                                    <td>
                                        <?php
                                        $levels = [1=>'Urusan',2=>'Bidang',3=>'Program',4=>'Kegiatan',5=>'Sub Kegiatan'];
                                        echo '<span class="badge bg-info">' . htmlspecialchars($levels[$row['level']] ?? '?') . '</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning" 
                                            onclick="editProgram(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['kode'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['nama'], ENT_QUOTES) ?>', <?= (int)$row['level'] ?>, '<?= htmlspecialchars($parentKodeMap[$row['parent_id']] ?? '', ENT_QUOTES) ?>')">
                                            Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger btn-hapus" 
                                                data-id="<?= (int)$row['id'] ?>" 
                                                data-kode="<?= htmlspecialchars($row['kode'], ENT_QUOTES) ?>"
                                                data-filter_level="<?= $filter_level ?>"
                                                data-search="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
                                            Hapus
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- PAGING -->
                <?php if ($total_pages > 1): ?>
                <nav class="p-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $page-1 ?>&filter_level=<?= $filter_level ?>&search=<?= urlencode($search) ?>">« Prev</a></li>
                        <?php else: ?>
                            <li class="page-item disabled"><span class="page-link">« Prev</span></li>
                        <?php endif; ?>
                        <?php
                        $start_page = max(1, $page - 5);
                        $end_page = min($total_pages, $start_page + 9);
                        if ($end_page - $start_page < 9) $start_page = max(1, $end_page - 9);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&filter_level=<?= $filter_level ?>&search=<?= urlencode($search) ?>"><?= $i ?></a></li>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $page+1 ?>&filter_level=<?= $filter_level ?>&search=<?= urlencode($search) ?>">Next »</a></li>
                        <?php else: ?>
                            <li class="page-item disabled"><span class="page-link">Next »</span></li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit Program -->
<div class="modal fade" id="editModalProgram" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title">Edit Data</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="update" value="1">
        <input type="hidden" name="id" id="edit-id">
        <div class="mb-3">
            <label class="form-label">Kode</label>
            <input type="text" name="kode" id="edit-kode" class="form-control" required maxlength="100">
        </div>
        <div class="mb-3">
            <label class="form-label">Nama</label>
            <textarea name="nama" id="edit-nama" class="form-control" rows="3" required maxlength="255"></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Level</label>
            <select name="level" id="edit-level" class="form-select" required>
                <option value="1">Urusan</option>
                <option value="2">Bidang</option>
                <option value="3">Program</option>
                <option value="4">Kegiatan</option>
                <option value="5">Sub Kegiatan</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Parent Kode</label>
            <input type="text" name="parent_kode" id="edit-parent" class="form-control" placeholder="Contoh: 1.1.2">
            <small class="text-muted">Kosongkan jika tidak ada</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary-custom">Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Konfirmasi Hapus Satu Data -->
<div class="modal fade" id="modalHapusSatu" tabindex="-1" aria-labelledby="modalHapusSatuLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="modalHapusSatuLabel">Konfirmasi Hapus</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Anda akan <strong>menghapus data <span id="hapus-kode"></span></strong>.</p>
        <p class="text-danger">Tindakan ini tidak dapat dibatalkan.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <a href="#" id="link-hapus-satu" class="btn btn-danger">Ya, Hapus</a>
      </div>
    </div>
  </div>
</div>

<!-- Modal Konfirmasi Hapus Semua -->
<div class="modal fade" id="modalHapusSemua" tabindex="-1" aria-labelledby="modalHapusSemuaLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="modalHapusSemuaLabel">Peringatan!</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Anda akan <strong>menghapus SEMUA data program</strong>.</p>
        <p>Tindakan ini <strong class="text-danger">tidak dapat dibatalkan</strong>.</p>
        <p>Apakah Anda yakin?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <a href="?hapus_semua=1" class="btn btn-danger">Ya, Hapus Semua</a>
      </div>
    </div>
  </div>
</div>

<script>
// Fungsi edit
function editProgram(id, kode, nama, level, parentKode) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-kode').value = kode;
    document.getElementById('edit-nama').value = nama;
    document.getElementById('edit-level').value = level;
    document.getElementById('edit-parent').value = parentKode || '';
    new bootstrap.Modal(document.getElementById('editModalProgram')).show();
}

// Event listener untuk tombol hapus
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-hapus').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var kode = this.getAttribute('data-kode');
            var filterLevel = this.getAttribute('data-filter_level') || '0';
            var search = this.getAttribute('data-search') || '';
            
            document.getElementById('hapus-kode').textContent = kode;
            
            var params = '?delete=' + id;
            if (filterLevel != '0') params += '&filter_level=' + filterLevel;
            if (search) params += '&search=' + encodeURIComponent(search);
            // Tambahkan page jika ada (bisa diambil dari URL saat ini)
            var urlParams = new URLSearchParams(window.location.search);
            var page = urlParams.get('page');
            if (page) params += '&page=' + page;
            
            document.getElementById('link-hapus-satu').href = 'program.php' + params;
            new bootstrap.Modal(document.getElementById('modalHapusSatu')).show();
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>