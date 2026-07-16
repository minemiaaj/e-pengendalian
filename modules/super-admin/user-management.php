<?php
/**
 * user-management.php - Manajemen Akun Pengguna (Super Admin)
 * 
 * Keamanan:
 * - Prepared statement untuk semua query
 * - Password hashing dengan bcrypt
 * - CSRF token
 * - Upload foto dengan validasi ketat (MIME, ukuran, ekstensi)
 * - Nama file diacak untuk keamanan
 * - Hapus file foto lama saat update/hapus user
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

// Ambil daftar OPD untuk dropdown
$opd_list_raw = $conn->query("SELECT id, nama_opd FROM opd ORDER BY nama_opd");

$success = '';
$error = '';

// ========== PROSES TAMBAH / EDIT ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        $action   = $_POST['action'] ?? '';
        $username = trim($_POST['username'] ?? '');
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $role     = $_POST['role'] ?? '';
        $status   = $_POST['status'] ?? '';
        $opd_id   = (in_array($role, ['kepala_opd','admin_opd']) && !empty($_POST['opd_id'])) ? (int)$_POST['opd_id'] : null;

        // ===== Upload Foto =====
        $foto_path = null;
        $upload_dir = __DIR__ . '/../../uploads/foto_profil/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0750, true);
            file_put_contents($upload_dir . '.htaccess', "Deny from all\n");
        }

        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['foto'];
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            $max_size = 2 * 1024 * 1024; // 2MB

            // Validasi MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowed_mime = ['image/jpeg', 'image/png', 'image/gif'];

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext) || !in_array($mime, $allowed_mime)) {
                $error = "Hanya file gambar JPG, PNG, atau GIF yang diperbolehkan.";
            } elseif ($file['size'] > $max_size) {
                $error = "Ukuran file maksimal 2MB.";
            } else {
                // Nama file acak
                $new_filename = 'foto_' . bin2hex(random_bytes(16)) . '.' . $ext;
                $destination = $upload_dir . $new_filename;
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $foto_path = 'uploads/foto_profil/' . $new_filename;
                } else {
                    $error = "Gagal menyimpan file foto.";
                }
            }
        }

        // Jika ada error dari upload, hentikan proses
        if ($error) {
            // tidak lanjut
        } elseif ($action === 'tambah') {
            if (empty($username) || empty($_POST['password']) || empty($nama) || empty($role)) {
                $error = "Username, Password, Nama Lengkap, dan Role harus diisi.";
            } else {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, nama_lengkap, role, opd_id, status, foto) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssiss', $username, $password, $nama, $role, $opd_id, $status, $foto_path);
                if ($stmt->execute()) {
                    $success = "Pengguna berhasil ditambahkan!";
                } else {
                    $error = "Gagal menambahkan pengguna.";
                }
                $stmt->close();
            }
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);

            // Ambil foto lama untuk dihapus nanti
            $stmt_old = $conn->prepare("SELECT foto FROM users WHERE id = ?");
            $stmt_old->bind_param('i', $id);
            $stmt_old->execute();
            $old_foto = $stmt_old->get_result()->fetch_assoc()['foto'] ?? null;
            $stmt_old->close();

            $sql = "UPDATE users SET username=?, nama_lengkap=?, role=?, opd_id=?, status=?";
            $params = [$username, $nama, $role, $opd_id, $status];
            $types = 'sssis';

            // Jika password diisi
            if (!empty($_POST['password'])) {
                $sql .= ", password=?";
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $params[] = $password;
                $types .= 's';
            }

            // Jika ada foto baru
            if ($foto_path) {
                $sql .= ", foto=?";
                $params[] = $foto_path;
                $types .= 's';
            }

            $sql .= " WHERE id=?";
            $params[] = $id;
            $types .= 'i';

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                // Hapus foto lama jika ada
                if ($foto_path && $old_foto && file_exists(__DIR__ . '/../../' . $old_foto)) {
                    @unlink(__DIR__ . '/../../' . $old_foto);
                }
                $success = "Pengguna berhasil diperbarui!";
            } else {
                $error = "Gagal memperbarui pengguna.";
            }
            $stmt->close();
        }
    }
}

// ========== HAPUS ==========
if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    // Ambil foto untuk dihapus
    $stmt_foto = $conn->prepare("SELECT foto FROM users WHERE id = ?");
    $stmt_foto->bind_param('i', $id);
    $stmt_foto->execute();
    $foto = $stmt_foto->get_result()->fetch_assoc()['foto'] ?? null;
    $stmt_foto->close();

    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        if ($foto && file_exists(__DIR__ . '/../../' . $foto)) {
            @unlink(__DIR__ . '/../../' . $foto);
        }
        $success = "Pengguna berhasil dihapus!";
    } else {
        $error = "Gagal menghapus pengguna.";
    }
    $stmt->close();
}

// ========== PAGING, SORTING, SEARCH & FILTER ==========
$limit = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$sortable_columns = ['username', 'nama_lengkap', 'role', 'nama_opd', 'status'];
$order_by = 'username';
$order_dir = 'ASC';
if (isset($_GET['sort']) && in_array($_GET['sort'], $sortable_columns, true)) {
    $order_by = $_GET['sort'];
}
if (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'DESC') {
    $order_dir = 'DESC';
}

if ($order_by === 'nama_opd') {
    $order_sql = "ORDER BY o.nama_opd $order_dir, u.username ASC";
} else {
    $order_sql = "ORDER BY u.$order_by $order_dir";
}

// Ambil parameter search & filter
$search = trim($_GET['search'] ?? '');
$filter_role = $_GET['filter_role'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$filter_opd = !empty($_GET['filter_opd']) ? (int)$_GET['filter_opd'] : 0;

// Validasi whitelist
$allowed_roles = ['super_admin', 'eksekutif', 'kepala_opd', 'admin_opd'];
if (!in_array($filter_role, $allowed_roles, true)) {
    $filter_role = '';
}
$allowed_status = ['aktif', 'nonaktif'];
if (!in_array($filter_status, $allowed_status, true)) {
    $filter_status = '';
}

// Bangun WHERE clause
$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $search_param = "%$search%";
    $where[] = "(u.username LIKE ? OR u.nama_lengkap LIKE ?)";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}
if ($filter_role !== '') {
    $where[] = "u.role = ?";
    $params[] = $filter_role;
    $types .= 's';
}
if ($filter_status !== '') {
    $where[] = "u.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_opd > 0) {
    $where[] = "u.opd_id = ?";
    $params[] = $filter_opd;
    $types .= 'i';
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// Total data (dengan filter)
$count_sql = "SELECT COUNT(*) as total FROM users u $where_sql";
$stmt_count = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_data = $stmt_count->get_result()->fetch_assoc()['total'] ?? 0;
$stmt_count->close();
$total_pages = ceil($total_data / $limit);

// Data pengguna
$stmt_users = $conn->prepare("SELECT u.*, o.nama_opd 
                              FROM users u 
                              LEFT JOIN opd o ON u.opd_id = o.id 
                              $where_sql 
                              $order_sql 
                              LIMIT ?, ?");

$bind_params = array_merge($params, [$offset, $limit]);
$bind_types = $types . 'ii';
$stmt_users->bind_param($bind_types, ...$bind_params);
$stmt_users->execute();
$users = $stmt_users->get_result();
$stmt_users->close();

// Helper untuk query string
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
.foto-thumb {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 50%;
    border: 1px solid #ddd;
}
</style>

<div class="d-flex" style="max-width: 100vw; overflow-x: hidden; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main-content">
        <h3 class="mb-4"><i class="bi bi-people-fill"></i> Manajemen Akun Pengguna</h3>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Form Tambah Pengguna -->
        <div class="card mb-4">
            <div class="card-header">Tambah Pengguna</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="tambah">
                    <div class="col-md-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required maxlength="50">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" class="form-control" required maxlength="200">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" id="role-select-add" onchange="toggleOpd(this, 'opd-group-add')" required>
                            <option value="">-- Pilih --</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="eksekutif">Eksekutif</option>
                            <option value="kepala_opd">Kepala OPD</option>
                            <option value="admin_opd">Admin OPD</option>
                        </select>
                    </div>
                    <div class="col-md-2" id="opd-group-add" style="display:none;">
                        <label class="form-label">OPD</label>
                        <select name="opd_id" class="form-select">
                            <option value="">-- Pilih OPD --</option>
                            <?php
                            mysqli_data_seek($opd_list_raw, 0);
                            while ($opd = mysqli_fetch_assoc($opd_list_raw)):
                            ?>
                                <option value="<?= (int)$opd['id'] ?>"><?= htmlspecialchars($opd['nama_opd']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="aktif">Aktif</option>
                            <option value="nonaktif">Nonaktif</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Foto Profil</label>
                        <input type="file" name="foto" class="form-control" accept="image/jpeg,image/png,image/gif">
                        <small class="text-muted">Max 2MB (JPG, PNG, GIF)</small>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary-custom">Tambah Pengguna</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabel Pengguna -->
        <div class="card">
            <div class="card-header">Daftar Pengguna</div>
            <div class="card-body p-0">
                <!-- Form Search & Filter -->
                <form method="GET" class="p-3 bg-light border-bottom">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Cari Username / Nama</label>
                            <input type="text" name="search" class="form-control" placeholder="Username atau Nama..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Filter Role</label>
                            <select name="filter_role" class="form-select">
                                <option value="">Semua Role</option>
                                <?php foreach ($allowed_roles as $r): ?>
                                    <option value="<?= $r ?>" <?= ($filter_role === $r) ? 'selected' : '' ?>>
                                        <?= ucfirst(str_replace('_', ' ', $r)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Filter Status</label>
                            <select name="filter_status" class="form-select">
                                <option value="">Semua Status</option>
                                <option value="aktif" <?= ($filter_status === 'aktif') ? 'selected' : '' ?>>Aktif</option>
                                <option value="nonaktif" <?= ($filter_status === 'nonaktif') ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Filter OPD</label>
                            <select name="filter_opd" class="form-select">
                                <option value="0">Semua OPD</option>
                                <?php
                                mysqli_data_seek($opd_list_raw, 0);
                                while ($opd = mysqli_fetch_assoc($opd_list_raw)):
                                ?>
                                    <option value="<?= (int)$opd['id'] ?>" <?= ($filter_opd == $opd['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opd['nama_opd']) ?>
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
                                <th>Foto</th>
                                <th>
                                    <a href="?<?= build_query_string(['sort' => 'username', 'dir' => ($order_by=='username' && $order_dir=='ASC') ? 'DESC' : 'ASC', 'page' => $page, 'search' => $search, 'filter_role' => $filter_role, 'filter_status' => $filter_status, 'filter_opd' => $filter_opd]) ?>" class="sort-link">
                                        Username
                                        <?php if ($order_by == 'username'): ?>
                                            <span class="sort-icon"><?= ($order_dir == 'ASC') ? '▲' : '▼' ?></span>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?= build_query_string(['sort' => 'nama_lengkap', 'dir' => ($order_by=='nama_lengkap' && $order_dir=='ASC') ? 'DESC' : 'ASC', 'page' => $page, 'search' => $search, 'filter_role' => $filter_role, 'filter_status' => $filter_status, 'filter_opd' => $filter_opd]) ?>" class="sort-link">
                                        Nama
                                        <?php if ($order_by == 'nama_lengkap'): ?>
                                            <span class="sort-icon"><?= ($order_dir == 'ASC') ? '▲' : '▼' ?></span>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?= build_query_string(['sort' => 'role', 'dir' => ($order_by=='role' && $order_dir=='ASC') ? 'DESC' : 'ASC', 'page' => $page, 'search' => $search, 'filter_role' => $filter_role, 'filter_status' => $filter_status, 'filter_opd' => $filter_opd]) ?>" class="sort-link">
                                        Role
                                        <?php if ($order_by == 'role'): ?>
                                            <span class="sort-icon"><?= ($order_dir == 'ASC') ? '▲' : '▼' ?></span>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?= build_query_string(['sort' => 'nama_opd', 'dir' => ($order_by=='nama_opd' && $order_dir=='ASC') ? 'DESC' : 'ASC', 'page' => $page, 'search' => $search, 'filter_role' => $filter_role, 'filter_status' => $filter_status, 'filter_opd' => $filter_opd]) ?>" class="sort-link">
                                        OPD
                                        <?php if ($order_by == 'nama_opd'): ?>
                                            <span class="sort-icon"><?= ($order_dir == 'ASC') ? '▲' : '▼' ?></span>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?= build_query_string(['sort' => 'status', 'dir' => ($order_by=='status' && $order_dir=='ASC') ? 'DESC' : 'ASC', 'page' => $page, 'search' => $search, 'filter_role' => $filter_role, 'filter_status' => $filter_status, 'filter_opd' => $filter_opd]) ?>" class="sort-link">
                                        Status
                                        <?php if ($order_by == 'status'): ?>
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
                            while ($user = $users->fetch_assoc()): 
                            ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td>
                                    <?php if (!empty($user['foto']) && file_exists(__DIR__ . '/../../' . $user['foto'])): ?>
                                        <img src="<?= BASE_URL . '/' . htmlspecialchars($user['foto']) ?>" class="foto-thumb" alt="Foto">
                                    <?php else: ?>
                                        <i class="bi bi-person-circle" style="font-size:2rem; color:#6c757d;"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($user['username']) ?></td>
                                <td><?= htmlspecialchars($user['nama_lengkap']) ?></td>
                                <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role']))) ?></td>
                                <td><?= htmlspecialchars($user['nama_opd'] ?: '-') ?></td>
                                <td>
                                    <span class="badge <?= $user['status'] == 'aktif' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= htmlspecialchars($user['status']) ?>
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <button class="btn btn-warning btn-sm" onclick="editUser(<?= htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8') ?>)">Edit</button>
                                    <a href="?<?= build_query_string(['hapus' => $user['id'], 'page' => $page, 'sort' => $order_by, 'dir' => $order_dir, 'search' => $search, 'filter_role' => $filter_role, 'filter_status' => $filter_status, 'filter_opd' => $filter_opd]) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Yakin hapus pengguna ini?')">Hapus</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if ($users->num_rows == 0): ?>
                            <tr>
                                <td colspan="8" class="text-center">Tidak ada data</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="card-footer">
                <nav aria-label="Navigasi halaman">
                    <ul class="pagination justify-content-end mb-0">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= build_query_string(['page' => $page-1, 'sort' => $order_by, 'dir' => $order_dir, 'search' => $search, 'filter_role' => $filter_role, 'filter_status' => $filter_status, 'filter_opd' => $filter_opd]) ?>">«</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= build_query_string(['page' => $i, 'sort' => $order_by, 'dir' => $order_dir, 'search' => $search, 'filter_role' => $filter_role, 'filter_status' => $filter_status, 'filter_opd' => $filter_opd]) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= build_query_string(['page' => $page+1, 'sort' => $order_by, 'dir' => $order_dir, 'search' => $search, 'filter_role' => $filter_role, 'filter_status' => $filter_status, 'filter_opd' => $filter_opd]) ?>">»</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Edit User -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" enctype="multipart/form-data" class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title">Edit Pengguna</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-3">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit-id">
        <div class="col-6">
            <label class="form-label">Username</label>
            <input type="text" name="username" id="edit-username" class="form-control" required maxlength="50">
        </div>
        <div class="col-6">
            <label class="form-label">Password (kosongkan jika tidak diubah)</label>
            <input type="password" name="password" class="form-control" placeholder="Biarkan kosong jika tidak diubah">
        </div>
        <div class="col-6">
            <label class="form-label">Nama Lengkap</label>
            <input type="text" name="nama_lengkap" id="edit-nama" class="form-control" required maxlength="200">
        </div>
        <div class="col-6">
            <label class="form-label">Role</label>
            <select name="role" id="edit-role" class="form-select" onchange="toggleOpd(this, 'edit-opd-group')" required>
                <option value="super_admin">Super Admin</option>
                <option value="eksekutif">Eksekutif</option>
                <option value="kepala_opd">Kepala OPD</option>
                <option value="admin_opd">Admin OPD</option>
            </select>
        </div>
        <div class="col-6" id="edit-opd-group" style="display:none;">
            <label class="form-label">OPD</label>
            <select name="opd_id" id="edit-opd" class="form-select">
                <option value="">-- Pilih OPD --</option>
                <?php
                mysqli_data_seek($opd_list_raw, 0);
                while ($opd = mysqli_fetch_assoc($opd_list_raw)):
                ?>
                    <option value="<?= (int)$opd['id'] ?>"><?= htmlspecialchars($opd['nama_opd']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label">Status</label>
            <select name="status" id="edit-status" class="form-select">
                <option value="aktif">Aktif</option>
                <option value="nonaktif">Nonaktif</option>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Foto Profil</label>
            <input type="file" name="foto" class="form-control" accept="image/jpeg,image/png,image/gif">
            <small class="text-muted">Max 2MB (JPG, PNG, GIF). Kosongkan jika tidak ingin mengubah.</small>
            <div id="edit-foto-preview" class="mt-2"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary-custom">Simpan</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleOpd(select, groupId) {
    const group = document.getElementById(groupId);
    const role = select.value;
    if (role === 'kepala_opd' || role === 'admin_opd') {
        group.style.display = 'block';
    } else {
        group.style.display = 'none';
    }
}

function editUser(user) {
    document.getElementById('edit-id').value = user.id;
    document.getElementById('edit-username').value = user.username;
    document.getElementById('edit-nama').value = user.nama_lengkap;
    document.getElementById('edit-role').value = user.role;
    document.getElementById('edit-status').value = user.status;

    const roleSelect = document.getElementById('edit-role');
    toggleOpd(roleSelect, 'edit-opd-group');

    const opdSelect = document.getElementById('edit-opd');
    if (user.opd_id && (user.role === 'kepala_opd' || user.role === 'admin_opd')) {
        opdSelect.value = user.opd_id;
    } else {
        opdSelect.value = '';
    }

    // Preview foto
    const preview = document.getElementById('edit-foto-preview');
    if (user.foto) {
        preview.innerHTML = `<img src="<?= BASE_URL ?>/` + user.foto + `" class="foto-thumb" style="width:80px;height:80px;">`;
    } else {
        preview.innerHTML = '<i class="bi bi-person-circle" style="font-size:3rem;color:#6c757d;"></i>';
    }

    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>