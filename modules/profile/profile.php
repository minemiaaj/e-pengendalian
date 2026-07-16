<?php
/**
 * profile.php – Halaman Edit Profil Pengguna
 * 
 * Fitur:
 * - Tampilkan informasi akun (username, role, OPD, status)
 * - Ubah nama lengkap
 * - Ubah password (opsional, dengan konfirmasi)
 * - Upload foto profil (max 2MB, format JPG/PNG/GIF)
 * - Hapus foto lama saat upload baru
 * 
 * Keamanan:
 * - CSRF token pada form POST
 * - Prepared statement untuk update database
 * - Password di-hash dengan bcrypt
 * - Validasi input (nama tidak kosong, password minimal 6 karakter)
 * - Validasi file upload (MIME type, ekstensi, ukuran)
 * - Nama file diacak untuk keamanan
 * - Path traversal dicegah
 * - Output escaping dengan htmlspecialchars()
 */

require_once __DIR__ . '/../../includes/header.php';

// ========== AMBIL DATA USER SAAT INI ==========
$user_id = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, nama_lengkap, role, opd_id, status, foto FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: ../dashboard/index.php');
    exit();
}

// ========== CSRF TOKEN ==========
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ========== PROSES UPDATE PROFIL ==========
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid. Silakan muat ulang halaman.";
    } else {
        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $password     = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (empty($nama_lengkap)) {
            $error = "Nama lengkap tidak boleh kosong.";
        } elseif (!empty($password) && strlen($password) < 6) {
            $error = "Password minimal 6 karakter.";
        } elseif ($password !== $password_confirm) {
            $error = "Konfirmasi password tidak cocok.";
        } else {
            // ---------- Proses upload foto ----------
            $foto_path = null;
            $upload_dir = ROOT_DIR . '/uploads/foto_profil/';

            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0750, true)) {
                    $error = "Gagal membuat direktori upload.";
                }
                // .htaccess modern (Apache 2.4+)
                file_put_contents($upload_dir . '.htaccess',
                    "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phps|cgi|pl|py|sh|exe)$\">\n    Require all denied\n</FilesMatch>\n<FilesMatch \"\\.(jpg|jpeg|png|gif)$\">\n    Require all granted\n</FilesMatch>\n");
            }

            if (!$error && isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['foto'];
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
                $max_size = 2 * 1024 * 1024;

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
                    $new_filename = 'foto_' . bin2hex(random_bytes(16)) . '.' . $ext;
                    $destination = $upload_dir . $new_filename;
                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        $foto_path = 'uploads/foto_profil/' . $new_filename;
                    } else {
                        $error = "Gagal menyimpan file foto.";
                    }
                }
            } elseif (!$error && isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
                $error = "Terjadi kesalahan saat upload file (kode: " . $_FILES['foto']['error'] . ").";
            }

            // ---------- Simpan ke database ----------
            if (!$error) {
                if (!empty($password) && $foto_path) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET nama_lengkap = ?, password = ?, foto = ? WHERE id = ?";
                    $stmt_upd = $conn->prepare($sql);
                    $stmt_upd->bind_param('sssi', $nama_lengkap, $hashed, $foto_path, $user_id);
                } elseif (!empty($password)) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET nama_lengkap = ?, password = ? WHERE id = ?";
                    $stmt_upd = $conn->prepare($sql);
                    $stmt_upd->bind_param('ssi', $nama_lengkap, $hashed, $user_id);
                } elseif ($foto_path) {
                    $sql = "UPDATE users SET nama_lengkap = ?, foto = ? WHERE id = ?";
                    $stmt_upd = $conn->prepare($sql);
                    $stmt_upd->bind_param('ssi', $nama_lengkap, $foto_path, $user_id);
                } else {
                    $sql = "UPDATE users SET nama_lengkap = ? WHERE id = ?";
                    $stmt_upd = $conn->prepare($sql);
                    $stmt_upd->bind_param('si', $nama_lengkap, $user_id);
                }

                if ($stmt_upd->execute()) {
                    // Hapus foto lama
                    if ($foto_path && !empty($user['foto'])) {
                        $old_file = ROOT_DIR . '/' . $user['foto'];
                        if (file_exists($old_file)) {
                            @unlink($old_file);
                        }
                    }

                    $_SESSION['nama_lengkap'] = $nama_lengkap;
                    if ($foto_path) {
                        $_SESSION['foto'] = $foto_path;
                    }

                    $success = "Profil berhasil diperbarui!";
                    $user['nama_lengkap'] = $nama_lengkap;
                    if ($foto_path) {
                        $user['foto'] = $foto_path;
                    }
                } else {
                    error_log("Gagal update profil user ID $user_id: " . $stmt_upd->error);
                    $error = "Gagal menyimpan perubahan. Silakan coba lagi.";
                }
                $stmt_upd->close();
            }
        }
    }
}

// ========== AMBIL NAMA OPD ==========
$nama_opd = '';
if (!empty($user['opd_id'])) {
    $stmt_opd = $conn->prepare("SELECT nama_opd FROM opd WHERE id = ?");
    $stmt_opd->bind_param('i', $user['opd_id']);
    $stmt_opd->execute();
    $res = $stmt_opd->get_result();
    if ($row = $res->fetch_assoc()) {
        $nama_opd = $row['nama_opd'];
    }
    $stmt_opd->close();
}

/**
 * Helper: menghasilkan URL absolut untuk aset (foto, dll.)
 */
function asset_url($path) {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

// --- DEBUG: bisa dihapus setelah berhasil ---
// echo '<!-- DEBUG BASE_URL: ' . BASE_URL . ' -->';
// echo '<!-- DEBUG ROOT_DIR: ' . ROOT_DIR . ' -->';
// echo '<!-- DEBUG foto_path_rel: ' . ($user['foto'] ?? 'kosong') . ' -->';
// --- akhir debug ---
?>

<div class="d-flex" style="max-width: 100vw; overflow-x: hidden; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex: 1; padding: 20px;">
        <div class="container-fluid">
            <h3 class="mb-4"><i class="bi bi-person-circle"></i> Edit Profil Saya</h3>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Kartu Informasi Akun -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <i class="bi bi-info-circle"></i> Informasi Akun
                </div>
                <div class="card-body">
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-2"><strong>Foto</strong></div>
                        <div class="col-md-10">
                            <?php 
                            $foto_path_rel = $user['foto'] ?? '';
                            $foto_abs_path = !empty($foto_path_rel) ? ROOT_DIR . '/' . $foto_path_rel : null;
                            if ($foto_abs_path && file_exists($foto_abs_path)): 
                            ?>
                                <img src="<?= asset_url($foto_path_rel) ?>" 
                                    style="width:100px; height:100px; object-fit:cover; border-radius:50%; border:2px solid #dee2e6;"
                                    alt="Foto Profil">
                            <?php else: ?>
                                <i class="bi bi-person-circle" style="font-size:4rem; color:#6c757d;"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-2"><strong>Username</strong></div>
                        <div class="col-md-10"><?= htmlspecialchars($user['username']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-2"><strong>Role</strong></div>
                        <div class="col-md-10"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role']))) ?></div>
                    </div>
                    <?php if ($nama_opd): ?>
                    <div class="row mb-2">
                        <div class="col-md-2"><strong>OPD</strong></div>
                        <div class="col-md-10"><?= htmlspecialchars($nama_opd) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="row mb-2">
                        <div class="col-md-2"><strong>Status</strong></div>
                        <div class="col-md-10">
                            <span class="badge <?= $user['status'] == 'aktif' ? 'bg-success' : 'bg-danger' ?>">
                                <?= htmlspecialchars(ucfirst($user['status'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Ubah Data -->
            <div class="card">
                <div class="card-header bg-light">
                    <i class="bi bi-pencil-square"></i> Ubah Data
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                        <div class="mb-3">
                            <label for="nama_lengkap" class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nama_lengkap" name="nama_lengkap" 
                                   value="<?= htmlspecialchars($user['nama_lengkap']) ?>" required maxlength="200">
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password Baru <span class="text-muted">(kosongkan jika tidak diubah)</span></label>
                            <input type="password" class="form-control" id="password" name="password" minlength="6">
                            <div class="form-text">Minimal 6 karakter.</div>
                        </div>

                        <div class="mb-3">
                            <label for="password_confirm" class="form-label">Konfirmasi Password Baru</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm">
                        </div>

                        <div class="mb-3">
                            <label for="foto" class="form-label">Foto Profil <span class="text-muted">(max 2MB, format JPG/PNG/GIF)</span></label>
                            <input type="file" class="form-control" id="foto" name="foto" accept="image/jpeg,image/png,image/gif">
                        </div>

                        <button type="submit" name="update_profile" class="btn btn-primary-custom">
                            <i class="bi bi-save"></i> Simpan Perubahan
                        </button>
                        <a href="../dashboard/index.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Batal
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
