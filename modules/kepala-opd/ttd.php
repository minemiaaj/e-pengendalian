<?php
/**
 * ttd.php - Upload Tanda Tangan Kepala OPD
 * 
 * Keamanan:
 * - Validasi MIME type sebenarnya (finfo) + ekstensi
 * - Nama file diacak untuk mencegah path traversal
 * - CSRF token pada form
 * - Proteksi direktori upload (.htaccess)
 * - Prepared statement untuk semua query
 * - Output escaping
 */
require_once __DIR__ . '/../../includes/header.php';

if ($_SESSION['role'] !== 'kepala_opd') {
    header('Location: ../dashboard/index.php');
    exit();
}

$opd_id = (int) $_SESSION['opd_id'];
$message = '';
$error = '';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Proses upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ttd_image'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        $file = $_FILES['ttd_image'];
        $allowed_ext = ['jpg', 'jpeg', 'png'];
        $max_size = 2 * 1024 * 1024; // 2MB

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "Gagal upload file.";
        } elseif ($file['size'] > $max_size) {
            $error = "Ukuran file maksimal 2MB.";
        } else {
            // Validasi MIME type sebenarnya
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowed_mime = ['image/jpeg', 'image/png'];
            if (!in_array($mime, $allowed_mime)) {
                $error = "Hanya file gambar JPG/PNG yang diperbolehkan.";
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext)) {
                    $error = "Ekstensi file tidak valid.";
                } else {
                    // Direktori upload yang aman
                    $upload_dir = __DIR__ . '/../../uploads/ttd/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0750, true);
                        // Proteksi: tolak eksekusi PHP di folder ini
                        file_put_contents($upload_dir . '.htaccess', "Deny from all\n");
                    }

                    // Hapus file TTD lama dengan ekstensi apa pun
                    $old_files = glob($upload_dir . "ttd_{$opd_id}.*");
                    foreach ($old_files as $old_file) {
                        @unlink($old_file);
                    }

                    // Nama file acak untuk keamanan (hindari tebakan)
                    $new_filename = 'ttd_' . $opd_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $destination = $upload_dir . $new_filename;
                    if (move_uploaded_file($file['tmp_name'], $destination)) {
                        $path_db = 'uploads/ttd/' . $new_filename;
                        $stmt = $conn->prepare("UPDATE opd SET ttd = ? WHERE id = ?");
                        $stmt->bind_param('si', $path_db, $opd_id);
                        $stmt->execute();
                        $message = "Tanda tangan berhasil diupload.";
                    } else {
                        $error = "Gagal menyimpan file.";
                    }
                }
            }
        }
    }
}

// Proses hapus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ttd'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Token keamanan tidak valid.";
    } else {
        // Ambil path saat ini
        $stmt = $conn->prepare("SELECT ttd FROM opd WHERE id = ?");
        $stmt->bind_param('i', $opd_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row && $row['ttd']) {
            $full_path = __DIR__ . '/../../' . $row['ttd'];
            // Validasi path (tidak mengandung ..)
            if (strpos($row['ttd'], '..') === false && file_exists($full_path)) {
                @unlink($full_path);
            }
        }

        // Kosongkan kolom
        $stmt = $conn->prepare("UPDATE opd SET ttd = NULL WHERE id = ?");
        $stmt->bind_param('i', $opd_id);
        $stmt->execute();
        $message = "Tanda tangan berhasil dihapus.";
    }
}

// Ambil data OPD
$stmt = $conn->prepare("SELECT ttd, nama_opd FROM opd WHERE id = ?");
$stmt->bind_param('i', $opd_id);
$stmt->execute();
$opd_data = $stmt->get_result()->fetch_assoc();
$current_ttd = $opd_data['ttd'] ?? null;
$nama_opd = $opd_data['nama_opd'];
$stmt->close();
?>

<div class="d-flex" style="margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <h3><i class="bi bi-pen"></i> Tanda Tangan Kepala OPD</h3>
            <p class="text-muted">Upload tanda tangan Anda (format JPG/PNG). Tanda tangan akan muncul pada laporan Rincian Belanja dan APBD.</p>

            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">Upload Tanda Tangan Baru</div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <div class="mb-3">
                                    <label class="form-label">File Gambar (JPG/PNG, maks 2MB)</label>
                                    <input type="file" name="ttd_image" class="form-control" accept="image/jpeg,image/png" required>
                                </div>
                                <button type="submit" class="btn btn-primary-custom">Upload</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">Tanda Tangan Saat Ini</div>
                        <div class="card-body text-center">
                            <?php if ($current_ttd): 
                                $full_path = __DIR__ . '/../../' . $current_ttd;
                                // Validasi path
                                if (strpos($current_ttd, '..') === false && file_exists($full_path)):
                                    $timestamp = filemtime($full_path);
                            ?>
                                <img src="<?= BASE_URL . '/' . htmlspecialchars($current_ttd) ?>?v=<?= $timestamp ?>"
                                     alt="Tanda Tangan"
                                     style="max-width: 200px; border: 1px solid #ddd; padding: 5px;">
                                <div class="mt-3">
                                    <form method="POST" onsubmit="return confirm('Hapus tanda tangan?')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <button type="submit" name="delete_ttd" class="btn btn-danger">Hapus Tanda Tangan</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">Tanda tangan tidak ditemukan.</p>
                            <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted">Belum ada tanda tangan yang diupload.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>