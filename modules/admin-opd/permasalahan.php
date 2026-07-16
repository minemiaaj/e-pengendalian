<?php
/**
 * permasalahan.php - Halaman Input Permasalahan Deviasi OPD
 * Role: admin_opd
 * 
 * Keamanan:
 * - Struktur header/session diseragamkan (include header.php sekali)
 * - Semua query database menggunakan prepared statement
 * - CSRF token pada form POST
 * - Output escaping dengan htmlspecialchars()
 * - Validasi input sebelum penyimpanan
 */
require_once __DIR__ . '/../../includes/header.php';

// Hanya admin_opd
if ($_SESSION['role'] != 'admin_opd') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$opd_id = (int) $_SESSION['opd_id'];
$tahun  = (int) date('Y');
$bulan_ini = (int) date('n');

// Dapatkan deviasi OPD dari dashboard functions (fungsi sudah ada)
require_once __DIR__ . '/../dashboard/grafik/dashboard_analisis_functions.php';
$deviasi_list = getDeviasiPerOPD($conn, $tahun, $bulan_ini);
$current_deviasi = 0;
foreach ($deviasi_list as $d) {
    if ($d['opd_id'] == $opd_id) {
        $current_deviasi = $d['deviasi'];
        break;
    }
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_msg = '';
$error_msg   = '';

// Kategori masalah (statis)
$kategori_masalah = [
    'Administrasi' => [
        ['kode' => 'ADM-01', 'deskripsi' => 'Kendala penerbitan Surat Perintah Membayar (SPM) atau SP2D.'],
        ['kode' => 'ADM-02', 'deskripsi' => 'Kesalahan penginputan kode rekening belanja pada aplikasi (SIPD).'],
        ['kode' => 'ADM-03', 'deskripsi' => 'Proses verifikasi dokumen yang memakan waktu lama di tingkat internal Perangkat Daerah.'],
        ['kode' => 'ADM-04', 'deskripsi' => 'Adanya mutasi/pergantian pejabat pengelola keuangan di tengah tahun anggaran.'],
        ['kode' => 'ADM-05', 'deskripsi' => 'Kesalahan administratif dalam dokumen tender yang menyebabkan lelang gagal/ulang.'],
        ['kode' => 'ADM-06', 'deskripsi' => 'Keterlambatan proses penyusunan/revisi dokumen DPA/RKA.'],
        ['kode' => 'ADM-07', 'deskripsi' => 'Keterlambatan penyerahan dokumen pertanggungjawaban (SPJ) dari pelaksana kegiatan.'],
        ['kode' => 'ADM-08', 'deskripsi' => 'Ketidaklengkapan berkas persyaratan pencairan dana dari pihak ketiga/rekanan.'],
    ],
    'Regulasi' => [
        ['kode' => 'REG-01', 'deskripsi' => 'Perubahan Petunjuk Teknis (Juknis) dari Kementerian/Pusat saat tahun berjalan.'],
        ['kode' => 'REG-02', 'deskripsi' => 'Keterlambatan penetapan Perkada terkait Standar Harga Satuan atau Biaya Masukan.'],
        ['kode' => 'REG-03', 'deskripsi' => 'Tumpang tindih aturan antara regulasi sektoral dengan aturan pengelolaan keuangan.'],
        ['kode' => 'REG-04', 'deskripsi' => 'Kebijakan Refocusing atau pergeseran anggaran secara mendadak (darurat).'],
        ['kode' => 'REG-05', 'deskripsi' => 'Pembatasan aturan baru terkait belanja operasional atau perjalanan dinas.'],
        ['kode' => 'REG-06', 'deskripsi' => 'Adanya instruksi moratorium atau penundaan kegiatan tertentu dari pimpinan.'],
        ['kode' => 'REG-07', 'deskripsi' => 'Keterlambatan pengesahan APBD Perubahan.'],
        ['kode' => 'REG-08', 'deskripsi' => 'Belum terbitnya SK Penunjukan Pejabat Pengelola Keuangan atau Pengelola Barang/Jasa.'],
    ],
    'Teknis' => [
        ['kode' => 'TEK-01', 'deskripsi' => 'Gangguan akses sistem aplikasi (Server Down/Error) pada jam sibuk.'],
        ['kode' => 'TEK-02', 'deskripsi' => 'Kondisi cuaca ekstrem (hujan/banjir) yang menghambat progres fisik di lapangan.'],
        ['kode' => 'TEK-03', 'deskripsi' => 'Keterbatasan sinyal internet untuk penginputan laporan di wilayah terpencil.'],
        ['kode' => 'TEK-04', 'deskripsi' => 'Kenaikan harga material/bahan baku yang signifikan (melebihi nilai kontrak).'],
        ['kode' => 'TEK-05', 'deskripsi' => 'Kinerja rekanan/pihak ketiga yang rendah (kurang modal/alat/pekerja).'],
        ['kode' => 'TEK-06', 'deskripsi' => 'Sulitnya akses transportasi menuju lokasi pekerjaan (geografis berat).'],
        ['kode' => 'TEK-07', 'deskripsi' => 'Kerusakan perangkat keras (Komputer/Laptop/Server) kantor pengelola.'],
        ['kode' => 'TEK-08', 'deskripsi' => 'Kelangkaan material tertentu di pasar yang dibutuhkan untuk spesifikasi teknis.'],
    ]
];

// Inisialisasi form
$selected_kodes   = [];
$keterangan_other = '';

// Proses penyimpanan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error_msg = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $kode_arr = $_POST['kode'] ?? [];
        $keterangan_other_post = trim($_POST['keterangan_other'] ?? '');

        // Validasi: setidaknya satu checkbox atau keterangan diisi
        if (empty($kode_arr) && $keterangan_other_post === '') {
            $error_msg = 'Anda harus memilih setidaknya satu permasalahan atau mengisi keterangan lainnya.';
            $selected_kodes = $kode_arr;
            $keterangan_other = $keterangan_other_post;
        } else {
            $kode_json = json_encode($kode_arr);

            // Hapus data lama (prepared)
            $stmt_del = $conn->prepare("DELETE FROM opd_permasalahan WHERE opd_id = ? AND tahun = ? AND bulan = ?");
            $stmt_del->bind_param("iii", $opd_id, $tahun, $bulan_ini);
            $stmt_del->execute();
            $stmt_del->close();

            // Insert baru (prepared)
            $stmt_ins = $conn->prepare("INSERT INTO opd_permasalahan (opd_id, tahun, bulan, deviasi, kode_permasalahan, keterangan_other) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->bind_param("iiidss", $opd_id, $tahun, $bulan_ini, $current_deviasi, $kode_json, $keterangan_other_post);

            if ($stmt_ins->execute()) {
                $success_msg = 'Data permasalahan berhasil disimpan.';
                // Tetap bersih setelah simpan
            } else {
                error_log("Gagal simpan permasalahan: " . $stmt_ins->error);
                $error_msg = 'Gagal menyimpan data. Silakan coba lagi.';
                $selected_kodes = $kode_arr;
                $keterangan_other = $keterangan_other_post;
            }
            $stmt_ins->close();
        }
    }
}

// Ambil nama OPD (prepared)
$nama_opd = '';
$stmt_opd = $conn->prepare("SELECT nama_opd FROM opd WHERE id = ?");
$stmt_opd->bind_param('i', $opd_id);
$stmt_opd->execute();
$res_opd = $stmt_opd->get_result();
if ($row = $res_opd->fetch_assoc()) {
    $nama_opd = $row['nama_opd'];
}
$stmt_opd->close();
?>
<div class="d-flex" style="max-width: 100vw; overflow-x: hidden; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main-content" style="flex: 1; min-width: 0; padding: 20px;">
        <h3 class="mb-4"><i class="bi bi-exclamation-triangle"></i> Input Permasalahan Deviasi</h3>

        <!-- Info OPD & Deviasi -->
        <div class="card card-modern mb-4 bg-light">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h5 class="card-title mb-1"><?= htmlspecialchars($nama_opd) ?></h5>
                        <p class="card-text text-muted">Deviasi Bulan <strong><?= date('F') ?></strong>: 
                            <span class="badge bg-info fs-6"><?= number_format($current_deviasi, 2) ?>%</span>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <i class="bi bi-building" style="font-size: 2.5rem; color: #0d6efd;"></i>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= htmlspecialchars($success_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($error_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <div class="card card-modern">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Pilih Permasalahan yang Terjadi</h5>
                </div>
                <div class="card-body">
                    <div class="accordion" id="accordionPermasalahan">
                        <?php $i = 0; foreach ($kategori_masalah as $kategori => $items): ?>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="heading<?= $i ?>">
                                    <button class="accordion-button <?= $i === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $i ?>" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="collapse<?= $i ?>">
                                        <i class="bi bi-folder2-open me-2"></i> <?= htmlspecialchars($kategori) ?> 
                                        <span class="badge bg-secondary ms-2"><?= count($items) ?> masalah</span>
                                    </button>
                                </h2>
                                <div id="collapse<?= $i ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" aria-labelledby="heading<?= $i ?>" data-bs-parent="#accordionPermasalahan">
                                    <div class="accordion-body">
                                        <div class="row">
                                            <?php foreach ($items as $item): ?>
                                                <div class="col-md-6 mb-2">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                               name="kode[]" value="<?= htmlspecialchars($item['kode']) ?>"
                                                               id="<?= htmlspecialchars($item['kode']) ?>"
                                                               <?= in_array($item['kode'], $selected_kodes) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="<?= htmlspecialchars($item['kode']) ?>">
                                                            <?= htmlspecialchars($item['deskripsi']) ?>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php $i++; endforeach; ?>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="mb-3">
                        <label for="keterangan_other" class="form-label fw-bold">
                            <i class="bi bi-pencil-square me-1"></i> Keterangan Tambahan (Lainnya)
                        </label>
                        <textarea class="form-control" id="keterangan_other" name="keterangan_other" rows="3"
                                  placeholder="Jelaskan permasalahan lain jika tidak ada dalam daftar..."><?= htmlspecialchars($keterangan_other) ?></textarea>
                    </div>
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary px-4 py-2">
                            <i class="bi bi-save me-1"></i> Simpan Permasalahan
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>