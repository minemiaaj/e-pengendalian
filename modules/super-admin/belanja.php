<?php
/**
 * belanja.php - Kelola Master Belanja (Kode Rekening)
 * 
 * Keamanan:
 * - Prepared statement untuk semua query dinamis
 * - CSRF token pada setiap form
 * - Validasi file upload
 * - Output escaping
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

// ===================== PROSES HAPUS SEMUA =====================
if (isset($_GET['hapus_semua']) && $_GET['hapus_semua'] == '1') {
    if ($_SESSION['role'] == 'super_admin') {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        $conn->query("DELETE FROM master_belanja");
        $conn->query("ALTER TABLE master_belanja AUTO_INCREMENT = 1");
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        $_SESSION['flash_message'] = "Semua data belanja berhasil dihapus!";
        $_SESSION['flash_type'] = "success";
    } else {
        $_SESSION['flash_message'] = "Anda tidak memiliki akses!";
        $_SESSION['flash_type'] = "danger";
    }
    header('Location: belanja.php');
    exit();
}

// ===================== PROSES HAPUS SATU DATA =====================
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        // Nonaktifkan foreign key checks agar bisa menghapus meskipun ada data terkait
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        
        // Hapus master belanja
        $stmt = $conn->prepare("DELETE FROM master_belanja WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        // Aktifkan kembali foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS=1");

        if ($affected > 0) {
            // Bersihkan data terkait yang mungkin tersisa (opsional, agar tidak ada data yatim)
            $conn->query("DELETE FROM realisasi_detail WHERE rincian_belanja_id = $id");
            $conn->query("DELETE FROM anggaran_detail WHERE rincian_belanja_id = $id");

            $_SESSION['flash_message'] = "Data berhasil dihapus.";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Data tidak ditemukan atau sudah dihapus.";
            $_SESSION['flash_type'] = "warning";
        }
    } else {
        $_SESSION['flash_message'] = "ID tidak valid.";
        $_SESSION['flash_type'] = "danger";
    }
    header('Location: belanja.php');
    exit();
}

// ===================== PROSES IMPORT EXCEL =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_excel'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $_SESSION['flash_message'] = "Token keamanan tidak valid.";
        $_SESSION['flash_type'] = "danger";
        header('Location: belanja.php');
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
        header('Location: belanja.php');
        exit();
    }

    $target_dir = "../../uploads/temp/";
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

            array_shift($rows); // hapus header

            $insert_data = [];
            $kode_set = [];
            foreach ($rows as $row) {
                $kode = trim($row['A'] ?? '');
                $nama = trim($row['B'] ?? '');
                $keterangan = trim($row['D'] ?? '');

                if (empty($kode) || empty($nama)) continue;
                if (strtoupper($keterangan) !== 'YA') continue;
                if (in_array($kode, $kode_set)) continue;
                $kode_set[] = $kode;
                $insert_data[] = ['kode' => $kode, 'nama' => $nama];
            }

            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO master_belanja (kode, nama) 
                                    VALUES (?, ?) 
                                    ON DUPLICATE KEY UPDATE nama = VALUES(nama)");
            foreach ($insert_data as $data) {
                $stmt->bind_param("ss", $data['kode'], $data['nama']);
                $stmt->execute();
            }
            $stmt->close();
            $conn->commit();
            $_SESSION['flash_message'] = "Data belanja berhasil diimpor! Total " . count($insert_data) . " baris.";
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
    header('Location: belanja.php');
    exit();
}

// ===================== PROSES TAMBAH MANUAL =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah_manual'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $_SESSION['flash_message'] = "Token keamanan tidak valid.";
        $_SESSION['flash_type'] = "danger";
        header('Location: belanja.php');
        exit();
    }

    $kode = trim($_POST['kode'] ?? '');
    $nama = trim($_POST['nama'] ?? '');

    if (empty($kode) || empty($nama)) {
        $_SESSION['flash_message'] = "Kode dan Nama harus diisi.";
        $_SESSION['flash_type'] = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO master_belanja (kode, nama) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE nama = VALUES(nama)");
        $stmt->bind_param("ss", $kode, $nama);
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = "Data berhasil ditambahkan!";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Gagal menambahkan.";
            $_SESSION['flash_type'] = "danger";
        }
        $stmt->close();
    }
    header('Location: belanja.php');
    exit();
}

// ===================== PROSES EDIT (melalui POST) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $_SESSION['flash_message'] = "Token keamanan tidak valid.";
        $_SESSION['flash_type'] = "danger";
        header('Location: belanja.php');
        exit();
    }

    $id = (int)($_POST['id'] ?? 0);
    $kode = trim($_POST['kode'] ?? '');
    $nama = trim($_POST['nama'] ?? '');

    if ($id <= 0 || empty($kode) || empty($nama)) {
        $_SESSION['flash_message'] = "Data tidak lengkap.";
        $_SESSION['flash_type'] = "danger";
    } else {
        $stmt_update = $conn->prepare("UPDATE master_belanja SET kode = ?, nama = ? WHERE id = ?");
        $stmt_update->bind_param('ssi', $kode, $nama, $id);
        if ($stmt_update->execute()) {
            $_SESSION['flash_message'] = "Data berhasil diperbarui!";
            $_SESSION['flash_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Gagal update data.";
            $_SESSION['flash_type'] = "danger";
        }
        $stmt_update->close();
    }
    header('Location: belanja.php');
    exit();
}

// ===================== PAGING & PENCARIAN =====================
$limit = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if (!empty($search)) {
    $search_param = "%{$search}%";
    $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM master_belanja WHERE kode LIKE ? OR nama LIKE ?");
    $stmt_count->bind_param("ss", $search_param, $search_param);
    $stmt_count->execute();
    $total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
} else {
    $total_rows = $conn->query("SELECT COUNT(*) as total FROM master_belanja")->fetch_assoc()['total'];
}
$total_pages = ceil($total_rows / $limit);

if (!empty($search)) {
    $stmt_data = $conn->prepare("SELECT * FROM master_belanja WHERE kode LIKE ? OR nama LIKE ? ORDER BY kode LIMIT ?, ?");
    $stmt_data->bind_param("ssii", $search_param, $search_param, $offset, $limit);
    $stmt_data->execute();
    $data = $stmt_data->get_result();
} else {
    $stmt_data = $conn->prepare("SELECT * FROM master_belanja ORDER BY kode LIMIT ?, ?");
    $stmt_data->bind_param("ii", $offset, $limit);
    $stmt_data->execute();
    $data = $stmt_data->get_result();
}
?>

<div class="d-flex" style="max-width: 100vw; overflow-x: auto; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content">
        <h3><i class="bi bi-cash-stack"></i> Belanja (Kode Rekening Belanja)</h3>

        <?php if ($flash_message): ?>
            <div class="alert alert-<?= htmlspecialchars($flash_type) ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($flash_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- IMPORT EXCEL -->
        <div class="card mb-4">
            <div class="card-header">Import Excel Belanja</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="mb-3">
                        <input type="file" name="file_excel" class="form-control" accept=".xlsx,.xls" required>
                        <small class="text-muted">
                            Format: Kolom A = Kode Rekening, Kolom B = Uraian, Kolom D = "Ya" (hanya baris dengan "Ya" yang akan diimpor).<br>
                            Baris pertama dianggap header.
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
                    <div class="col-md-4">
                        <label class="form-label">Kode Rekening</label>
                        <input type="text" name="kode" class="form-control" required maxlength="50">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Nama Rekening</label>
                        <input type="text" name="nama" class="form-control" required maxlength="255">
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
                    <div class="col-md-6">
                        <form method="GET" class="d-flex">
                            <input type="text" name="search" class="form-control me-2" placeholder="Cari kode atau nama..." value="<?= htmlspecialchars($search) ?>">
                            <button type="submit" class="btn btn-primary-custom">Cari</button>
                        </form>
                    </div>
                    <div class="col-md-4">
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
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($data->num_rows == 0): ?>
                                <tr><td colspan="3" class="text-center">Tidak ada data. Silakan import atau tambah manual.</td></tr>
                            <?php else: ?>
                                <?php while ($row = $data->fetch_assoc()): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($row['kode']) ?></code></td>
                                    <td><?= htmlspecialchars($row['nama']) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning" 
                                            onclick="editBelanja(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['kode'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['nama'], ENT_QUOTES) ?>')">
                                            Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger btn-hapus" 
                                                data-id="<?= (int)$row['id'] ?>" 
                                                data-kode="<?= htmlspecialchars($row['kode'], ENT_QUOTES) ?>">
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
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>">« Prev</a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled"><span class="page-link">« Prev</span></li>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 5);
                        $end_page = min($total_pages, $start_page + 9);
                        if ($end_page - $start_page < 9) $start_page = max(1, $end_page - 9);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>">Next »</a>
                            </li>
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

<!-- Modal Edit Belanja -->
<div class="modal fade" id="editModalBelanja" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title">Edit Data Belanja</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="update" value="1">
        <input type="hidden" name="id" id="edit-id">
        <div class="mb-3">
            <label class="form-label">Kode Rekening</label>
            <input type="text" name="kode" id="edit-kode" class="form-control" required maxlength="50">
        </div>
        <div class="mb-3">
            <label class="form-label">Nama Rekening</label>
            <textarea name="nama" id="edit-nama" class="form-control" rows="3" required maxlength="255"></textarea>
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
        <p>Data terkait di anggaran dan realisasi juga akan dihapus.</p>
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
        <p>Anda akan <strong>menghapus SEMUA data belanja</strong>.</p>
        <p>Tindakan ini <strong class="text-danger">tidak dapat dibatalkan</strong>.</p>
        <p>Apakah Anda yakin ingin melanjutkan?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <a href="?hapus_semua=1" class="btn btn-danger">Ya, Hapus Semua</a>
      </div>
    </div>
  </div>
</div>

<script>
// Event listener untuk tombol hapus
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-hapus').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var kode = this.getAttribute('data-kode');
            document.getElementById('hapus-kode').textContent = kode;
            document.getElementById('link-hapus-satu').href = 'belanja.php?delete=' + id;
            new bootstrap.Modal(document.getElementById('modalHapusSatu')).show();
        });
    });
});

// Fungsi edit
function editBelanja(id, kode, nama) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-kode').value = kode;
    document.getElementById('edit-nama').value = nama;
    new bootstrap.Modal(document.getElementById('editModalBelanja')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>