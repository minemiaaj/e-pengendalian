<?php
/**
 * opd-management.php - Manajemen OPD (Super Admin)
 *
 * Keamanan:
 * - Semua query database menggunakan prepared statement (anti SQL injection)
 * - CSRF token pada setiap form POST
 * - Whitelist sorting kolom (tidak ada interpolasi langsung ke SQL)
 * - Output escaping dengan htmlspecialchars()
 * - Data ke JavaScript menggunakan json_encode (hindari addslashes)
 */

require_once __DIR__ . '/../../includes/header.php';

// Hanya super_admin
if ($_SESSION['role'] !== 'super_admin') {
    header('Location: ../dashboard/index.php');
    exit();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Ambil data urusan (level 1) dan bidang (level 2) untuk dropdown
$urusan_list = $conn->query("SELECT id, kode, nama FROM master_hierarki WHERE level = 1 ORDER BY kode");
$bidang_list = $conn->query("SELECT id, kode, nama FROM master_hierarki WHERE level = 2 ORDER BY kode");

$success = '';
$error = '';

// ========== PROSES TAMBAH / EDIT ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        $action      = $_POST['action'] ?? '';
        $nama_opd    = trim($_POST['nama_opd'] ?? '');
        $nama_kepala = trim($_POST['nama_kepala'] ?? '');
        $nip_kepala  = trim($_POST['nip_kepala'] ?? '');
        $urusan_id   = !empty($_POST['urusan_id']) ? (int)$_POST['urusan_id'] : null;
        $bidang_id   = !empty($_POST['bidang_id']) ? (int)$_POST['bidang_id'] : null;

        if ($action === 'tambah') {
            $stmt = $conn->prepare("INSERT INTO opd (nama_opd, nama_kepala, nip_kepala, urusan_id, bidang_id) 
                                    VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('sssii', $nama_opd, $nama_kepala, $nip_kepala, $urusan_id, $bidang_id);
            if ($stmt->execute()) {
                $success = "OPD berhasil ditambahkan!";
            } else {
                $error = "Gagal menambahkan OPD.";
            }
            $stmt->close();
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("UPDATE opd SET nama_opd=?, nama_kepala=?, nip_kepala=?, urusan_id=?, bidang_id=? WHERE id=?");
            $stmt->bind_param('sssiii', $nama_opd, $nama_kepala, $nip_kepala, $urusan_id, $bidang_id, $id);
            if ($stmt->execute()) {
                $success = "OPD berhasil diperbarui!";
            } else {
                $error = "Gagal memperbarui OPD.";
            }
            $stmt->close();
        }
    }
}

// ========== HAPUS OPD ==========
if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];

    // Hapus data dependen: anggaran_detail, realisasi_detail, users, opd_permasalahan
    $tables = ['anggaran_detail', 'realisasi_detail', 'users', 'opd_permasalahan'];
    foreach ($tables as $table) {
        $stmt = $conn->prepare("DELETE FROM $table WHERE opd_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    // Hapus OPD
    $stmt = $conn->prepare("DELETE FROM opd WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        $success = "OPD berhasil dihapus beserta data terkait!";
    } else {
        $error = "Gagal menghapus OPD.";
    }
    $stmt->close();
}

// ========== PAGING, SORTING, SEARCH & FILTER ==========
$limit = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$sortable_columns = ['nama_opd', 'urusan_nama', 'bidang_nama'];
$order_by = 'nama_opd';
$order_dir = 'ASC';

if (isset($_GET['sort']) && in_array($_GET['sort'], $sortable_columns, true)) {
    $order_by = $_GET['sort'];
}
if (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'DESC') {
    $order_dir = 'DESC';
}

// Bangun ORDER BY dengan whitelist
if ($order_by === 'urusan_nama') {
    $order_sql = "ORDER BY u.nama $order_dir, o.nama_opd ASC";
} elseif ($order_by === 'bidang_nama') {
    $order_sql = "ORDER BY b.nama $order_dir, o.nama_opd ASC";
} else {
    $order_sql = "ORDER BY o.nama_opd $order_dir";
}

// Ambil parameter search & filter
$search = trim($_GET['search'] ?? '');
$filter_urusan = !empty($_GET['filter_urusan']) ? (int)$_GET['filter_urusan'] : 0;
$filter_bidang = !empty($_GET['filter_bidang']) ? (int)$_GET['filter_bidang'] : 0;

// Bangun WHERE clause
$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $search_param = "%$search%";
    $where[] = "(o.nama_opd LIKE ? OR o.nama_kepala LIKE ?)";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}
if ($filter_urusan > 0) {
    $where[] = "o.urusan_id = ?";
    $params[] = $filter_urusan;
    $types .= 'i';
}
if ($filter_bidang > 0) {
    $where[] = "o.bidang_id = ?";
    $params[] = $filter_bidang;
    $types .= 'i';
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// Total data (dengan filter)
$count_sql = "SELECT COUNT(*) as total FROM opd o $where_sql";
$stmt_count = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_data = $stmt_count->get_result()->fetch_assoc()['total'] ?? 0;
$stmt_count->close();
$total_pages = ceil($total_data / $limit);

// Data OPD dengan LIMIT menggunakan prepared statement
$stmt_list = $conn->prepare("SELECT o.id, o.nama_opd, o.nama_kepala, o.nip_kepala, 
                                     o.urusan_id, o.bidang_id,
                                     u.nama as urusan_nama, b.nama as bidang_nama,
                                     b.kode as bidang_kode
                              FROM opd o 
                              LEFT JOIN master_hierarki u ON o.urusan_id = u.id 
                              LEFT JOIN master_hierarki b ON o.bidang_id = b.id
                              $where_sql
                              $order_sql
                              LIMIT ?, ?");

// Gabungkan parameter: where params + limit params
$bind_params = array_merge($params, [$offset, $limit]);
$bind_types = $types . 'ii';
$stmt_list->bind_param($bind_types, ...$bind_params);
$stmt_list->execute();
$opd_list = $stmt_list->get_result();
$stmt_list->close();

// Helper format kode bidang
function format_kode_bidang($kode) {
    if (empty($kode)) return '';
    $parts = explode('.', $kode);
    $last = end($parts);
    return str_pad($last, 2, '0', STR_PAD_LEFT);
}

// Fungsi bantu untuk query string pagination
function build_query_string($params) {
    return http_build_query(array_filter($params, function($v) { return $v !== '' && $v !== 0; }));
}
?>

<style>
.main-content {
    overflow-x: auto;
    flex: 1;
    padding: 20px;
}
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.sort-link {
    color: #333;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.sort-link:hover {
    color: #007bff;
}
.sort-icon {
    font-size: 0.8rem;
}
.pagination {
    margin-top: 20px;
    justify-content: flex-end;
}
</style>

<div class="d-flex" style="max-width: 100vw; overflow-x: hidden; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <h3 class="mb-4"><i class="bi bi-building"></i> Manajemen OPD</h3>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Form Tambah OPD -->
        <div class="card mb-4">
            <div class="card-header">Tambah OPD</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="tambah">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Nama OPD</label>
                            <input type="text" name="nama_opd" class="form-control" required maxlength="200">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Nama Kepala</label>
                            <input type="text" name="nama_kepala" class="form-control" maxlength="200">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">NIP Kepala</label>
                            <input type="text" name="nip_kepala" class="form-control" maxlength="30">
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Urusan</label>
                            <select name="urusan_id" class="form-select">
                                <option value="">-- Pilih Urusan --</option>
                                <?php 
                                mysqli_data_seek($urusan_list, 0);
                                while ($u = mysqli_fetch_assoc($urusan_list)): ?>
                                    <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['kode']) ?> - <?= htmlspecialchars($u['nama']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Bidang Urusan</label>
                            <select name="bidang_id" class="form-select">
                                <option value="">-- Pilih Bidang --</option>
                                <?php 
                                mysqli_data_seek($bidang_list, 0);
                                while ($b = mysqli_fetch_assoc($bidang_list)):
                                    $kode_formatted = format_kode_bidang($b['kode']);
                                ?>
                                    <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($kode_formatted) ?> - <?= htmlspecialchars($b['nama']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary-custom w-100">Tambah OPD</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tabel OPD -->
        <div class="card">
            <div class="card-header">Daftar OPD</div>
            <div class="card-body p-0">
                <!-- Form Search & Filter -->
                <form method="GET" class="p-3 bg-light border-bottom">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Cari OPD / Kepala</label>
                            <input type="text" name="search" class="form-control" placeholder="Nama OPD atau Kepala..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Filter Urusan</label>
                            <select name="filter_urusan" class="form-select">
                                <option value="0">Semua Urusan</option>
                                <?php 
                                mysqli_data_seek($urusan_list, 0);
                                while ($u = mysqli_fetch_assoc($urusan_list)): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= ($filter_urusan == $u['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['kode']) ?> - <?= htmlspecialchars($u['nama']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Filter Bidang</label>
                            <select name="filter_bidang" class="form-select">
                                <option value="0">Semua Bidang</option>
                                <?php 
                                mysqli_data_seek($bidang_list, 0);
                                while ($b = mysqli_fetch_assoc($bidang_list)):
                                    $kode_formatted = format_kode_bidang($b['kode']);
                                ?>
                                    <option value="<?= (int)$b['id'] ?>" <?= ($filter_bidang == $b['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($kode_formatted) ?> - <?= htmlspecialchars($b['nama']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary-custom"><i class="bi bi-search"></i> Cari</button>
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-custom table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>
                                    <a href="?<?= build_query_string(['sort' => 'nama_opd', 'dir' => ($order_by=='nama_opd' && $order_dir=='ASC') ? 'DESC' : 'ASC', 'page' => $page, 'search' => $search, 'filter_urusan' => $filter_urusan, 'filter_bidang' => $filter_bidang]) ?>" class="sort-link">
                                        Nama OPD
                                        <?php if ($order_by == 'nama_opd'): ?>
                                            <span class="sort-icon"><?= ($order_dir == 'ASC') ? '▲' : '▼' ?></span>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Kepala</th>
                                <th>NIP</th>
                                <th>
                                    <a href="?<?= build_query_string(['sort' => 'urusan_nama', 'dir' => ($order_by=='urusan_nama' && $order_dir=='ASC') ? 'DESC' : 'ASC', 'page' => $page, 'search' => $search, 'filter_urusan' => $filter_urusan, 'filter_bidang' => $filter_bidang]) ?>" class="sort-link">
                                        Urusan
                                        <?php if ($order_by == 'urusan_nama'): ?>
                                            <span class="sort-icon"><?= ($order_dir == 'ASC') ? '▲' : '▼' ?></span>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?= build_query_string(['sort' => 'bidang_nama', 'dir' => ($order_by=='bidang_nama' && $order_dir=='ASC') ? 'DESC' : 'ASC', 'page' => $page, 'search' => $search, 'filter_urusan' => $filter_urusan, 'filter_bidang' => $filter_bidang]) ?>" class="sort-link">
                                        Bidang
                                        <?php if ($order_by == 'bidang_nama'): ?>
                                            <span class="sort-icon"><?= ($order_dir == 'ASC') ? '▲' : '▼' ?></span>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = $offset + 1;
                            while ($opd = $opd_list->fetch_assoc()): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= htmlspecialchars($opd['nama_opd']) ?></td>
                                <td><?= htmlspecialchars($opd['nama_kepala'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($opd['nip_kepala'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($opd['urusan_nama'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($opd['bidang_nama'] ?: '-') ?></td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-warning btn-sm" 
                                      onclick="editOpd(<?= htmlspecialchars(json_encode($opd), ENT_QUOTES, 'UTF-8') ?>)">
                                      Edit
                                    </button>
                                    <a href="?<?= build_query_string(['hapus' => $opd['id'], 'page' => $page, 'sort' => $order_by, 'dir' => $order_dir, 'search' => $search, 'filter_urusan' => $filter_urusan, 'filter_bidang' => $filter_bidang]) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus OPD ini? Data terkait akan terhapus.')">Hapus</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if ($opd_list->num_rows == 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center">Tidak ada data</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($total_pages > 1): ?>
            <div class="card-footer">
                <nav aria-label="Navigasi halaman">
                    <ul class="pagination justify-content-end mb-0">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= build_query_string(['page' => $page-1, 'sort' => $order_by, 'dir' => $order_dir, 'search' => $search, 'filter_urusan' => $filter_urusan, 'filter_bidang' => $filter_bidang]) ?>">«</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= build_query_string(['page' => $i, 'sort' => $order_by, 'dir' => $order_dir, 'search' => $search, 'filter_urusan' => $filter_urusan, 'filter_bidang' => $filter_bidang]) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= build_query_string(['page' => $page+1, 'sort' => $order_by, 'dir' => $order_dir, 'search' => $search, 'filter_urusan' => $filter_urusan, 'filter_bidang' => $filter_bidang]) ?>">»</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Edit OPD -->
<div class="modal fade" id="editModalOpd" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title">Edit OPD</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit-opd-id">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Nama OPD</label>
                <input type="text" name="nama_opd" id="edit-nama-opd" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Nama Kepala</label>
                <input type="text" name="nama_kepala" id="edit-kepala-opd" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">NIP Kepala</label>
                <input type="text" name="nip_kepala" id="edit-nip-opd" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Urusan</label>
                <select name="urusan_id" id="edit-urusan-id" class="form-select">
                    <option value="">-- Pilih Urusan --</option>
                    <?php 
                    mysqli_data_seek($urusan_list, 0);
                    while ($u = mysqli_fetch_assoc($urusan_list)): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['kode']) ?> - <?= htmlspecialchars($u['nama']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Bidang Urusan</label>
                <select name="bidang_id" id="edit-bidang-id" class="form-select">
                    <option value="">-- Pilih Bidang --</option>
                    <?php 
                    mysqli_data_seek($bidang_list, 0);
                    while ($b = mysqli_fetch_assoc($bidang_list)):
                        $kode_formatted = format_kode_bidang($b['kode']);
                    ?>
                        <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($kode_formatted) ?> - <?= htmlspecialchars($b['nama']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary-custom">Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<script>
// Data OPD untuk modal edit
function editOpd(opd) {
    document.getElementById('edit-opd-id').value = opd.id;
    document.getElementById('edit-nama-opd').value = opd.nama_opd || '';
    document.getElementById('edit-kepala-opd').value = opd.nama_kepala || '';
    document.getElementById('edit-nip-opd').value = opd.nip_kepala || '';
    document.getElementById('edit-urusan-id').value = opd.urusan_id || '';
    document.getElementById('edit-bidang-id').value = opd.bidang_id || '';
    
    new bootstrap.Modal(document.getElementById('editModalOpd')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>