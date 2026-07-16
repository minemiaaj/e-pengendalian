<?php
/**
 * manajemen_waktu.php - Atur batas waktu input data (Super Admin)
 * 
 * Keamanan:
 * - CSRF token di setiap POST
 * - Semua query menggunakan prepared statement
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

// Ambil pesan flash (session-based)
$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Proses hapus (via POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hapus') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $_SESSION['flash_error'] = "Token keamanan tidak valid.";
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM waktu_batas WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash_success'] = "Pengaturan berhasil dihapus.";
    }
    header('Location: manajemen_waktu.php');
    exit();
}

// Proses simpan / update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'simpan') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $_SESSION['flash_error'] = "Token keamanan tidak valid.";
    } else {
        $tahun   = (int)($_POST['tahun'] ?? 0);
        $tanggal = trim($_POST['tanggal'] ?? '');
        $jam     = trim($_POST['jam'] ?? '');
        $menit   = trim($_POST['menit'] ?? '');
        $jenis   = $_POST['jenis'] ?? 'anggaran';

        if ($tahun < 2000 || $tahun > 2100) {
            $_SESSION['flash_error'] = "Tahun tidak valid.";
        } elseif (!in_array($jenis, ['anggaran', 'realisasi'])) {
            $_SESSION['flash_error'] = "Jenis tidak valid.";
        } elseif (empty($tanggal) || $jam === '' || $menit === '') {
            $_SESSION['flash_error'] = "Tanggal, jam, dan menit harus diisi.";
        } else {
            $batas_waktu = $tanggal . ' ' . str_pad($jam, 2, '0', STR_PAD_LEFT) . ':' . str_pad($menit, 2, '0', STR_PAD_LEFT) . ':00';
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $batas_waktu);
            if (!$dt || $dt->format('Y-m-d H:i:s') !== $batas_waktu) {
                $_SESSION['flash_error'] = "Format tanggal/waktu tidak valid.";
            } else {
                // Cek apakah sudah ada untuk tahun tersebut
                $stmt = $conn->prepare("SELECT id, jenis FROM waktu_batas WHERE tahun = ?");
                $stmt->bind_param('i', $tahun);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing) {
                    $stmt = $conn->prepare("UPDATE waktu_batas SET batas_waktu = ?, jenis = ?, dieksekusi = 0, updated_at = NOW() WHERE tahun = ?");
                    $stmt->bind_param('ssi', $batas_waktu, $jenis, $tahun);
                } else {
                    $stmt = $conn->prepare("INSERT INTO waktu_batas (tahun, batas_waktu, jenis) VALUES (?, ?, ?)");
                    $stmt->bind_param('iss', $tahun, $batas_waktu, $jenis);
                }

                if ($stmt->execute()) {
                    $_SESSION['flash_success'] = "Batas waktu untuk tahun $tahun berhasil " . ($existing ? 'diperbarui' : 'ditambahkan') . ".";
                } else {
                    $_SESSION['flash_error'] = "Gagal menyimpan data: " . $conn->error;
                }
                $stmt->close();
            }
        }
    }
    header('Location: manajemen_waktu.php');
    exit();
}

// Ambil semua pengaturan
$settings = $conn->query("SELECT * FROM waktu_batas ORDER BY tahun");
?>

<div class="d-flex" style="max-width: 100vw; overflow-x: auto; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex:1; padding:20px;">
        <h3><i class="bi bi-clock-history"></i> Manajemen Waktu Batas Input</h3>
        <p class="text-muted">
            Atur batas waktu penginputan data. Saat batas waktu tercapai, sistem akan otomatis memvalidasi dan mengunci data 
            sesuai jenis yang dipilih.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">Tambah / Update Batas Waktu</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="simpan">

                    <div class="col-md-2">
                        <label class="form-label">Tahun</label>
                        <select name="tahun" class="form-select" required>
                            <?php for ($y = date('Y')-2; $y <= date('Y')+2; $y++): ?>
                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal</label>
                        <input type="date" name="tanggal" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Jam</label>
                        <input type="number" name="jam" class="form-control" min="0" max="23" placeholder="23" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Menit</label>
                        <input type="number" name="menit" class="form-control" min="0" max="59" placeholder="59" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Jenis Penguncian</label>
                        <select name="jenis" class="form-select" required>
                            <option value="anggaran">Anggaran</option>
                            <option value="realisasi">Realisasi</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary-custom">Simpan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Daftar Pengaturan Batas Waktu</div>
            <div class="card-body table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>Tahun</th>
                            <th>Batas Waktu</th>
                            <th>Jenis</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($settings && $settings->num_rows > 0): ?>
                            <?php while ($row = $settings->fetch_assoc()): ?>
                                <tr>
                                    <td><?= (int)$row['tahun'] ?></td>
                                    <td><?= htmlspecialchars(date('d-m-Y H:i', strtotime($row['batas_waktu']))) ?></td>
                                    <td>
                                        <?php if ($row['jenis'] === 'realisasi'): ?>
                                            <span class="badge bg-info">Realisasi</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">Anggaran</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['dieksekusi']): ?>
                                            <span class="badge bg-success">Telah dieksekusi</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Belum dieksekusi</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Hapus pengaturan ini?');" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center">Belum ada pengaturan.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>