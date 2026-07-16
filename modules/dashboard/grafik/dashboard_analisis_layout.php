<?php
/**
 * Dashboard Analisis - Layout (HTML)
 * 
 * Keamanan:
 * - Output escaping dengan htmlspecialchars()
 * - Data ke JavaScript di-encode dengan json_encode() + flag keamanan
 * - Tidak ada interpolasi variabel langsung ke JavaScript tanpa encoding
 */

// Pastikan functions sudah di-include dan variabel sudah tersedia
if (!isset($role)) {
    require_once __DIR__ . '/dashboard_analisis_functions.php';
}
?>
<div class="d-flex" style="max-width: 100vw; overflow-x: hidden; margin-bottom: 30px;">
    <?php include __DIR__ . '/../../../includes/sidebar.php'; ?>
    
    <div class="main-content" style="flex: 1; min-width: 0; max-width: 100%; overflow-x: hidden; padding-right: 15px; box-sizing: border-box;">
        <h3 class="mb-4"><i class="bi bi-speedometer2"></i> Dashboard</h3>
        
        <?php if (in_array($role, ['super_admin', 'eksekutif'])): ?>
            <!-- CARD PERINGATAN DATA BELUM DIKUNCI (HANYA UNTUK SUPER ADMIN) -->
            <?php if ($role == 'super_admin' && isset($anggaran_belum_dikunci) && isset($realisasi_belum_dikunci) && ($anggaran_belum_dikunci > 0 || $realisasi_belum_dikunci > 0)): ?>
            <div class="card border-warning mb-4" id="unlockWarningCard">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-triangle-fill"></i> DATA BELUM DIKUNCI</span>
                    <button type="button" class="btn-close" aria-label="Close" onclick="document.getElementById('unlockWarningCard').style.display='none'"></button>
                </div>
                <div class="card-body text-dark">
                    <p class="mb-2">Terdapat data yang sudah <strong>divalidasi</strong> tetapi belum <strong>dikunci</strong>:</p>
                    <ul class="mb-3">
                        <?php if ($anggaran_belum_dikunci > 0): ?>
                        <li>Anggaran: <strong><?= (int)$anggaran_belum_dikunci ?></strong> rincian</li>
                        <?php endif; ?>
                        <?php if ($realisasi_belum_dikunci > 0): ?>
                        <li>Realisasi: <strong><?= (int)$realisasi_belum_dikunci ?></strong> data</li>
                        <?php endif; ?>
                    </ul>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($anggaran_belum_dikunci > 0): ?>
                        <a href="../super-admin/lock-anggaran.php" class="btn btn-warning btn-sm fw-semibold">
                            <i class="bi bi-lock-fill me-1"></i> Kunci Anggaran
                        </a>
                        <?php endif; ?>
                        <?php if ($realisasi_belum_dikunci > 0): ?>
                        <a href="../super-admin/lock-realisasi.php" class="btn btn-warning btn-sm fw-semibold">
                            <i class="bi bi-lock-fill me-1"></i> Kunci Realisasi
                        </a>
                        <?php endif; ?>
                    </div>
                    <p class="mt-2 mb-0 small text-muted">Data yang belum dikunci tidak akan muncul di dashboard.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Statistik cards -->
            <div class="row g-3 mb-4" style="margin-left: 0; margin-right: 0;">
                <div class="col-md-3"><div class="card-stat"><h6>Total OPD</h6><div class="value"><?= (int)$total_opd ?></div></div></div>
                <div class="col-md-3"><div class="card-stat"><h6>Total Program</h6><div class="value"><?= (int)$total_program ?></div></div></div>
                <div class="col-md-3"><div class="card-stat"><h6>Total Anggaran</h6><div class="value"><?= formatRupiah($total_anggaran) ?></div></div></div>
                <div class="col-md-3"><div class="card-stat"><h6>Realisasi</h6><div class="value"><?= formatRupiah($total_realisasi) ?></div><small><?= number_format($persen_realisasi, 2) ?>%</small></div></div>
            </div>

            <div class="row g-3">
                <!-- KOLOM KIRI -->
                <div class="col-lg-3 col-md-4">
                    <!-- 1. Analisa AI Grafik -->
                    <div class="card mb-3">
                        <div class="card-header text-white" style="background-color: #2c4e7a;">
                            <i class="bi bi-bar-chart-fill"></i> Analisa Grafik
                        </div>
                        <div class="card-body" id="aiAnalysisBody" style="max-height: 1000px; overflow-y: auto;">
                            <?php if (empty($grafik_deviasi) && $total_anggaran == 0): ?>
                                <p class="text-muted text-center">Belum ada data deviasi atau anggaran dikunci.</p>
                            <?php else: ?>
                                <div class="text-center text-muted" id="aiLoading"><i class="bi bi-hourglass-split"></i> Menganalisis data...</div>
                                <div id="aiResult" style="display: none;"></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 2. Analisis Permasalahan OPD -->
                    <div class="card mb-3">
                        <div class="card-header text-white" style="background-color: #2c4e7a;">
                            <i class="bi bi-chat-left-text-fill"></i> Analisis Permasalahan OPD
                        </div>
                        <div class="card-body" id="permasalahanAnalysisBody" style="max-height: 1000px; overflow-y: auto;">
                            <?php if (empty($permasalahan_data)): ?>
                                <p class="text-muted text-center">Belum ada data permasalahan yang diinput bulan ini.</p>
                            <?php else: ?>
                                <div class="text-center text-muted" id="permAiLoading"><i class="bi bi-hourglass-split"></i> Menganalisis permasalahan...</div>
                                <div id="permAiResult" style="display: none;"></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 3. Ringkasan Deviasi -->
                    <div class="card mb-3">
                        <div class="card-header text-white" style="background-color: #2c4e7a;">
                            <i class="bi bi-graph-down-arrow"></i> Ringkasan Deviasi
                        </div>
                        <div class="card-body">
                            <?php if (empty($grafik_deviasi)): ?>
                                <p class="text-muted">Belum ada data deviasi (tidak ada OPD dengan pagu terkunci).</p>
                            <?php else: ?>
                                <div class="mb-2">
                                    <h6>Deviasi (Target - Realisasi)</h6>
                                    <div>Rata-rata: <strong><?= number_format($rata_deviasi, 2) ?>%</strong></div>
                                    <div>Deviasi Tertinggi: <strong class="text-danger"><?= number_format($max_deviasi, 2) ?>%</strong></div>
                                    <div>Deviasi Terendah: <strong class="text-success"><?= number_format($min_deviasi, 2) ?>%</strong></div>
                                </div>
                                <hr>
                                <h6><i class="bi bi-graph-up-arrow"></i> 5 Deviasi Tertinggi</h6>
                                <?php foreach ($deviasi_top5 as $idx => $d): ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?= ($idx+1) ?>. <?= htmlspecialchars($d['nama_opd_pendek']) ?></span>
                                        <span class="badge bg-danger"><?= number_format($d['deviasi'], 2) ?>%</span>
                                    </div>
                                <?php endforeach; ?>
                                <hr>
                                <h6><i class="bi bi-graph-down-arrow"></i> 5 Deviasi Terendah</h6>
                                <?php foreach ($deviasi_bottom5 as $idx => $d): ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?= ($idx+1) ?>. <?= htmlspecialchars($d['nama_opd_pendek']) ?></span>
                                        <span class="badge bg-success"><?= number_format($d['deviasi'], 2) ?>%</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 4. Ringkasan Realisasi -->
                    <div class="card mb-3">
                        <div class="card-header text-white" style="background-color: #2c4e7a;">
                            <i class="bi bi-info-circle-fill"></i> Ringkasan Realisasi
                        </div>
                        <div class="card-body">
                            <?php if ($total_anggaran == 0): ?>
                                <p class="text-muted">Belum ada pagu anggaran dikunci.</p>
                            <?php else: ?>
                                <div class="mb-3">
                                    <h6><i class="bi bi-graph-up"></i> Realisasi Anggaran</h6>
                                    <div class="display-6"><?= number_format($persen_real_terbaru, 2) ?>%</div>
                                    <small>dari total Pagu</small>
                                    <hr>
                                    <div>Total Pagu: <strong>Rp <?= number_format($total_anggaran, 0, ',', '.') ?></strong></div>
                                    <div>Realisasi: <strong>Rp <?= number_format($total_realisasi, 0, ',', '.') ?></strong></div>
                                    <div>Sisa: <strong>Rp <?= number_format($total_anggaran - $total_realisasi, 0, ',', '.') ?></strong></div>
                                    <?php if (isset($real_bulan_lalu)): ?>
                                        <div class="mt-2 <?= $kenaikan_rp >= 0 ? 'text-success' : 'text-danger' ?>">
                                            vs bulan lalu: 
                                            <?= $kenaikan_rp >= 0 ? '▲ +' : '▼ ' ?>Rp <?= number_format(abs($kenaikan_rp), 0, ',', '.') ?>
                                            (<?= $kenaikan_persen >=0 ? '+' : '' ?><?= number_format($kenaikan_persen, 2) ?>%)
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <hr>
                                <div class="mb-3">
                                    <h6><i class="bi bi-trophy"></i> Top 5 Tertinggi</h6>
                                    <?php if (empty($top5)): ?>
                                        <p class="text-muted">Belum ada data realisasi.</p>
                                    <?php else: ?>
                                        <?php foreach ($top5 as $idx => $t): ?>
                                            <div class="d-flex justify-content-between"><span><?= ($idx+1) ?>. <?= htmlspecialchars($t['opd']) ?></span><span class="badge bg-success"><?= number_format($t['total'], 2) ?>%</span></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <hr>
                                <div>
                                    <h6><i class="bi bi-exclamation-triangle"></i> 5 Terendah</h6>
                                    <?php if (empty($bottom5)): ?>
                                        <p class="text-muted">Belum ada data realisasi.</p>
                                    <?php else: ?>
                                        <?php foreach ($bottom5 as $idx => $b): ?>
                                            <div class="d-flex justify-content-between"><span><?= ($idx+1) ?>. <?= htmlspecialchars($b['opd']) ?></span><span class="badge bg-danger"><?= number_format($b['total'], 2) ?>%</span></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 5. 5 Permasalahan Paling Sering Dipilih -->
                    <div class="card mb-3">
                        <div class="card-header text-white" style="background-color: #2c4e7a;">
                            <i class="bi bi-list-ol"></i> 5 Permasalahan Paling Sering Dipilih
                        </div>
                        <div class="card-body">
                            <?php if (empty($top5_permasalahan)): ?>
                                <p class="text-muted">Belum ada data permasalahan yang diinput bulan ini.</p>
                            <?php else: ?>
                                <?php $total = count($top5_permasalahan); $i = 1; ?>
                                <?php foreach ($top5_permasalahan as $item): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <strong><?= htmlspecialchars($item['kode']) ?> - <?= htmlspecialchars($item['deskripsi']) ?></strong>
                                            <span class="badge bg-secondary ms-2"><?= (int)$item['jumlah'] ?> OPD</span>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            OPD: 
                                            <?php 
                                            $opd_display = array_slice($item['opd_list'], 0, 5);
                                            echo implode(', ', array_map('htmlspecialchars', $opd_display));
                                            if (count($item['opd_list']) > 5) {
                                                $all_opd = implode(', ', array_map('htmlspecialchars', $item['opd_list']));
                                                echo ' <span class="text-primary" data-bs-toggle="tooltip" data-bs-html="true" title="' . htmlspecialchars($all_opd) . '">+' . (count($item['opd_list']) - 5) . ' lainnya</span>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <?php if ($i < $total): ?><hr class="my-2"><?php endif; ?>
                                    <?php $i++; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- KOLOM KANAN -->
                <div class="col-lg-9 col-md-8">
                    <!-- Grafik Realisasi vs Sisa (semua OPD) -->
                    <div class="card mb-3">
                        <div class="card-header text-white" style="background-color: #2c4e7a;">
                            <i class="bi bi-bar-chart-fill"></i> REALISASI vs SISA ANGGARAN (Semua OPD, Persen)
                        </div>
                        <div class="card-body" style="height: 450px;">
                            <?php if (empty($opd_names)): ?>
                                <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                                    Belum ada data pagu/realisasi dikunci untuk ditampilkan.
                                </div>
                            <?php else: ?>
                                <canvas id="chartRealisasiSisa" style="width:100%; height:100%;"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-7">
                            <div class="card">
                                <div class="card-header text-white" style="background-color: #2c4e7a;">
                                    <i class="bi bi-graph-up"></i> Grafik Perbandingan Target, Realisasi, dan Deviasi - Semua OPD
                                </div>
                                <div class="card-body" style="padding: 0.5rem;">
                                    <div id="horizontalChartContainer" style="width: 100%;">
                                        <canvas id="horizontalChart" style="width: 100%; height: auto; min-height: 400px;"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <!-- Donut Realisasi Periode Terbaru -->
                            <div class="card mb-3">
                                <div class="card-header text-white" style="background-color: #2c4e7a;">
                                    <i class="bi bi-pie-chart-fill"></i> REALISASI PERIODE TERBARU &amp; KENAIKAN
                                </div>
                                <div class="card-body text-center">
                                    <?php if ($total_anggaran == 0): ?>
                                        <p class="text-muted">Belum ada pagu.</p>
                                    <?php else: ?>
                                        <canvas id="chartPeningkatan" style="max-width:180px; max-height:180px; margin:0 auto;"></canvas>
                                        <p class="mt-2 mb-0"><strong><?= number_format($persen_real_terbaru, 2) ?>%</strong></p>
                                        <?php if (isset($real_bulan_lalu)): ?>
                                            <small>
                                                vs bulan lalu: 
                                                <?= $kenaikan_rp >=0 ? '▲ +' : '▼ ' ?>Rp <?= number_format(abs($kenaikan_rp), 0, ',', '.') ?>
                                                (<?= $kenaikan_persen >=0 ? '+' : '' ?><?= number_format($kenaikan_persen, 2) ?>%)
                                            </small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Total Pagu, Realisasi, Sisa (Provinsi) -->
                            <div class="card mb-3">
                                <div class="card-header text-white" style="background-color: #2c4e7a;">
                                    <i class="bi bi-cash-stack"></i> TOTAL PAGU, REALISASI, SISA (Provinsi)
                                </div>
                                <div class="card-body" style="height: 220px;">
                                    <?php if ($total_anggaran == 0): ?>
                                        <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                                            Belum ada data pagu.
                                        </div>
                                    <?php else: ?>
                                        <canvas id="chartTotalPaguRealSisa"></canvas>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Top 5 Realisasi Tertinggi -->
                            <div class="card mb-3">
                                <div class="card-header text-white" style="background-color: #2c4e7a;">
                                    <i class="bi bi-trophy-fill"></i> TOP 5 REALISASI TERTINGGI
                                </div>
                                <div class="card-body" style="height: 220px;">
                                    <?php if (empty($top5)): ?>
                                        <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                                            Belum ada data realisasi.
                                        </div>
                                    <?php else: ?>
                                        <canvas id="chartTop5"></canvas>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- 5 Realisasi Terendah -->
                            <div class="card mb-3">
                                <div class="card-header text-white" style="background-color: #2c4e7a;">
                                    <i class="bi bi-exclamation-triangle-fill"></i> 5 REALISASI TERENDAH
                                </div>
                                <div class="card-body" style="height: 220px;">
                                    <?php if (empty($bottom5)): ?>
                                        <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                                            Belum ada data realisasi.
                                        </div>
                                    <?php else: ?>
                                        <canvas id="chartBottom5"></canvas>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Top 5 Deviasi Tertinggi -->
                            <div class="card mb-3">
                                <div class="card-header text-white" style="background-color: #2c4e7a;">
                                    <i class="bi bi-graph-down-arrow"></i> TOP 5 DEVIASI TERTINGGI
                                </div>
                                <div class="card-body" style="height: 220px;">
                                    <?php if (empty($deviasi_top5)): ?>
                                        <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                                            Belum ada data deviasi.
                                        </div>
                                    <?php else: ?>
                                        <canvas id="chartDeviasiTop5"></canvas>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- 5 Deviasi Terendah -->
                            <div class="card mb-3">
                                <div class="card-header text-white" style="background-color: #2c4e7a;">
                                    <i class="bi bi-graph-up-arrow"></i> 5 DEVIASI TERENDAH
                                </div>
                                <div class="card-body" style="height: 220px;">
                                    <?php if (empty($deviasi_bottom5)): ?>
                                        <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                                            Belum ada data deviasi.
                                        </div>
                                    <?php else: ?>
                                        <canvas id="chartDeviasiBottom5"></canvas>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabel OPD -->
            <div>
                <h5 class="mt-4">Daftar OPD & Ringkasan</h5>
                <div class="table-responsive" style="max-width: 100%;">
                    <table class="table table-custom" id="opdTable" style="width: 100%;">
                        <thead>
                            <tr>
                                <th data-sort="nama_opd" style="cursor: pointer;">Nama OPD <i class="bi bi-arrow-up-down ms-1"></i></th>
                                <th data-sort="nama_kepala" style="cursor: pointer;">Kepala OPD <i class="bi bi-arrow-up-down ms-1"></i></th>
                                <th data-sort="total_anggaran" style="cursor: pointer;">Total Anggaran <i class="bi bi-arrow-up-down ms-1"></i></th>
                                <th data-sort="total_realisasi" style="cursor: pointer;">Realisasi <i class="bi bi-arrow-up-down ms-1"></i></th>
                                <th data-sort="persen" style="cursor: pointer;">% <i class="bi bi-arrow-up-down ms-1"></i></th>
                            </tr>
                        </thead>
                        <tbody id="opdTableBody"></tbody>
                    </table>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div>
                            <select id="rowsPerPageSelect" class="form-select form-select-sm" style="width: auto; display: inline-block;">
                                <option value="10">10 baris</option>
                                <option value="25">25 baris</option>
                                <option value="50">50 baris</option>
                            </select>
                            <span class="ms-2 text-muted" id="tableInfo"></span>
                        </div>
                        <div id="paginationControls"></div>
                    </div>
                </div>
            </div>

        <?php elseif ($role == 'admin_opd'): ?>
            <!-- PERINGATAN BATAS WAKTU SUDAH LEWAT -->
            <?php if ($batas_waktu_lewat && $batas_waktu_info): ?>
            <div class="card border-danger mb-4" id="batasWaktuCard">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-alarm-fill"></i> BATAS WAKTU INPUT TELAH BERAKHIR</span>
                <button type="button" class="btn-close btn-close-white" aria-label="Close" onclick="document.getElementById('batasWaktuCard').style.display='none'"></button>
            </div>
            <div class="card-body text-danger">
                <p class="mb-2">Batas waktu penginputan <strong><?= htmlspecialchars($batas_waktu_info['jenis'] === 'realisasi' ? 'realisasi' : 'anggaran') ?></strong> untuk tahun <strong><?= $tahun ?></strong> telah berakhir pada:</p>
                <p class="fw-bold mb-2"><?= htmlspecialchars(date('d-m-Y H:i', strtotime($batas_waktu_info['batas_waktu']))) ?></p>
                <p class="mb-0 small">Sistem telah mengunci data secara otomatis. Anda <strong>tidak dapat</strong> lagi menginput atau mengubah data.</p>
            </div>
            </div>
            <!-- PERINGATAN BATAS WAKTU AKTIF (HITUNG MUNDUR) -->
            <?php elseif (!$batas_waktu_lewat && $batas_waktu_info): ?>
            <div class="card border-warning mb-4" id="batasWaktuCard">
            <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                <span><i class="bi bi-hourglass-split"></i> PENGINGAT BATAS WAKTU</span>
                <button type="button" class="btn-close" aria-label="Close" onclick="document.getElementById('batasWaktuCard').style.display='none'"></button>
            </div>
            <div class="card-body text-dark">
                <p class="mb-2">Batas waktu penginputan <strong><?= htmlspecialchars($batas_waktu_info['jenis'] === 'realisasi' ? 'realisasi' : 'anggaran') ?></strong> tahun <strong><?= $tahun ?></strong>:</p>
                <p class="fw-bold mb-2"><?= htmlspecialchars(date('d-m-Y H:i', strtotime($batas_waktu_info['batas_waktu']))) ?></p>
                <p class="mb-2">Sisa waktu: <strong id="countdownTimer" style="font-size: 1.2rem;">--</strong></p>
                <p class="mb-0 small">Pastikan semua data sudah diinput sebelum waktu tersebut. Setelah melewati batas, data akan dikunci otomatis.</p>
            </div>
            </div>
            <?php endif; ?>
            <!-- PERINGATAN DEVIASI -->
            <?php if ($show_warning): ?>
            <div class="card border-danger mb-4" id="deviationWarningCard">
              <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-octagon-fill"></i> PERINGATAN DEVIASI TINGGI</span>
                <button type="button" class="btn-close btn-close-white" aria-label="Close" onclick="document.getElementById('deviationWarningCard').style.display='none'"></button>
              </div>
              <div class="card-body text-danger">
                Deviasi realisasi OPD Anda bulan ini mencapai <strong><?= number_format($current_deviasi, 2) ?>%</strong> (≥11%).
                <br>Segera lakukan:
                <ul class="mb-0 mt-2">
                  <li>Perbaiki input realisasi pada menu <a href="../admin-opd/data_realisasi.php" class="alert-link">Input Realisasi</a>.</li>
                  <li>Jelaskan penyebab deviasi melalui menu <a href="../admin-opd/permasalahan.php" class="alert-link">Permasalahan</a>.</li>
                </ul>
              </div>
            </div>
            <?php endif; ?>

            <div class="row mb-4"><div class="col-md-6 col-lg-4"><div class="card bg-light border-0 shadow-sm"><div class="card-body py-2 px-3"><h6 class="card-title mb-0"><?= htmlspecialchars($opd_info['nama_opd'] ?? '') ?></h6><small class="text-muted">Kepala OPD: <?= htmlspecialchars($opd_info['nama_kepala'] ?? '-') ?></small></div></div></div></div>
            <div class="row g-3 mb-4">
                <div class="col-md-3"><div class="card-stat"><h6>Program</h6><div class="value"><?= (int)$total_program ?></div></div></div>
                <div class="col-md-3"><div class="card-stat"><h6>Kegiatan</h6><div class="value"><?= (int)$total_kegiatan ?></div></div></div>
                <div class="col-md-3"><div class="card-stat"><h6>Sub Kegiatan</h6><div class="value"><?= (int)$total_sub_kegiatan ?></div></div></div>
                <div class="col-md-3"><div class="card-stat"><h6>Rincian Belanja</h6><div class="value"><?= (int)$total_rincian ?></div></div></div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-6"><div class="card-stat"><h6>Total Pagu Anggaran</h6><div class="value"><?= formatRupiah($total_pagu) ?></div></div></div>
                <div class="col-md-6"><div class="card-stat"><h6>Total Realisasi</h6><div class="value"><?= formatRupiah($total_realisasi) ?></div><small><?= number_format($persen_realisasi, 2) ?>%</small></div></div>
            </div>

            <!-- INFO PERMASALAHAN YANG TELAH DIPILIH -->
            <div class="card mb-3">
                <div class="card-header text-white" style="background-color: #2c4e7a;">
                    <i class="bi bi-chat-left-text-fill"></i> Permasalahan Bulan Ini
                </div>
                <div class="card-body">
                    <?php if (empty($permasalahan_opd)): ?>
                        <p class="text-muted">Belum ada permasalahan yang diinput bulan ini.</p>
                    <?php else: ?>
                        <?php foreach ($permasalahan_opd as $idx => $perm): ?>
                            <?php if ($idx > 0): ?><hr class="my-2"><?php endif; ?>
                            <div class="mb-2">
                                <strong>Deviasi:</strong> 
                                <span class="badge <?= $perm['deviasi'] >= 11 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                    <?= number_format($perm['deviasi'], 2) ?>%
                                </span>
                                <small class="text-muted ms-2"><?= date('d/m/Y H:i', strtotime($perm['created_at'])) ?></small>
                            </div>
                            <div class="mb-2">
                                <strong>Kode Permasalahan:</strong>
                                <ul class="mb-1">
                                    <?php foreach ($perm['deskripsi'] as $i => $desc): ?>
                                        <li><strong><?= htmlspecialchars($perm['kode'][$i]) ?></strong> - <?= htmlspecialchars($desc) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php if (!empty($perm['keterangan_other'])): ?>
                                <div class="mb-2">
                                    <strong>Keterangan Tambahan:</strong><br>
                                    <span class="text-muted"><?= nl2br(htmlspecialchars($perm['keterangan_other'])) ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <a href="../admin-opd/permasalahan.php" class="btn btn-sm btn-outline-secondary mt-2">
                            <i class="bi bi-pencil"></i> Edit Permasalahan
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Grafik Target, Realisasi, Deviasi OPD -->
            <div class="card mb-3">
                <div class="card-header text-white" style="background-color: #2c4e7a;">
                    <i class="bi bi-bar-chart-fill"></i> Target, Realisasi, dan Deviasi OPD
                </div>
                <div class="card-body" style="height: 200px;">
                    <?php if (empty($grafik_opd)): ?>
                        <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                            Belum ada data deviasi OPD ini.
                        </div>
                    <?php else: ?>
                        <canvas id="chartOpdDeviasi"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- REALISASI vs SISA (Semua OPD) -->
            <div class="card mb-3">
                <div class="card-header text-white" style="background-color: #2c4e7a;">
                    <i class="bi bi-bar-chart-fill"></i> REALISASI vs SISA ANGGARAN (Semua OPD, Persen)
                </div>
                <div class="card-body" style="height: 450px;">
                    <?php if (empty($opd_names_all)): ?>
                        <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                            Belum ada data pagu/realisasi dikunci untuk ditampilkan.
                        </div>
                    <?php else: ?>
                        <canvas id="chartRealisasiSisa" style="width:100%; height:100%;"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- DAFTAR PROGRAM -->
            <h5>Daftar Program</h5>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>Kode Program</th>
                            <th>Pagu</th>
                            <th>Realisasi</th>
                            <th>%</th>
                            <th>Jml Kegiatan</th>
                            <th>Jml Sub Keg</th>
                            <th>Jml Rincian</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($prog = $program_result->fetch_assoc()): 
                            $persen_prog = ($prog['pagu'] > 0) ? round(($prog['realisasi'] / $prog['pagu']) * 100, 2) : 0;
                            $uraian = getNamaFromHierarki($prog['kode_program'], 3);
                            $display_kode = htmlspecialchars($prog['kode_program']) . ' - ' . htmlspecialchars($uraian);
                        ?>
                            <tr>
                                <td><?= $display_kode ?></td>
                                <td><?= formatRupiah($prog['pagu']) ?></td>
                                <td><?= formatRupiah($prog['realisasi']) ?></td>
                                <td><?= number_format($persen_prog, 2) ?>%</td>
                                <td><?= (int)$prog['jml_kegiatan'] ?></td>
                                <td><?= (int)$prog['jml_sub_kegiatan'] ?></td>
                                <td><?= (int)$prog['jml_rincian'] ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <?php if ($program_result->num_rows == 0): ?>
                            <tr><td colspan="7" class="text-center text-muted">Belum ada program dengan data dikunci.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($role == 'kepala_opd'): ?>
            <!-- PERINGATAN DEVIASI TINGGI -->
            <?php if ($show_warning): ?>
            <div class="card border-danger mb-4" id="deviationWarningCard">
              <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-octagon-fill"></i> PERINGATAN DEVIASI TINGGI</span>
                <button type="button" class="btn-close btn-close-white" aria-label="Close" onclick="document.getElementById('deviationWarningCard').style.display='none'"></button>
              </div>
              <div class="card-body text-danger">
                Deviasi realisasi OPD Anda bulan ini mencapai <strong><?= number_format($current_deviasi, 2) ?>%</strong> (≥11%).
                Mohon koordinasikan dengan admin OPD untuk segera:
                <ul class="mb-0 mt-2">
                  <li>Memperbaiki input realisasi.</li>
                  <li>Mengisi permasalahan deviasi.</li>
                </ul>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($anggaran_draft['jml'] > 0): ?>
            <div class="card border-warning mb-4" id="draftWarningCard">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-triangle-fill"></i> DATA ANGGARAN BELUM DIVALIDASI</span>
                    <button type="button" class="btn-close" aria-label="Close" onclick="document.getElementById('draftWarningCard').style.display='none'"></button>
                </div>
                <div class="card-body text-dark">
                    Terdapat data anggaran yang masih berstatus <strong>draft</strong>:
                    <ul class="mb-0 mt-2">
                        <li>Anggaran: <strong><?= (int)$anggaran_draft['jml'] ?></strong> rincian</li>
                    </ul>
                    <div class="mt-2">
                        <a href="../kepala-opd/validasi_anggaran.php" class="btn btn-sm btn-outline-dark">Validasi Anggaran</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- HEADER & CARD ANGGARAN/REALISASI -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card bg-light border-0 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title"><?= htmlspecialchars($opd_info['nama_opd'] ?? '') ?></h5>
                            <p class="card-text">Selamat datang, <?= htmlspecialchars($_SESSION['nama_lengkap']) ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card-stat">
                        <h6>Total Pagu Anggaran</h6>
                        <div class="value"><?= formatRupiah($total_pagu) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card-stat">
                        <h6>Total Realisasi</h6>
                        <div class="value"><?= formatRupiah($total_realisasi) ?></div>
                        <small><?= number_format($persen_realisasi, 2) ?>%</small>
                    </div>
                </div>
            </div>

            <!-- INFO PERMASALAHAN YANG TELAH DIPILIH -->
            <div class="card mb-3">
                <div class="card-header text-white" style="background-color: #2c4e7a;">
                    <i class="bi bi-chat-left-text-fill"></i> Permasalahan Bulan Ini
                </div>
                <div class="card-body">
                    <?php if (empty($permasalahan_opd)): ?>
                        <p class="text-muted">Belum ada permasalahan yang diinput bulan ini.</p>
                    <?php else: ?>
                        <?php foreach ($permasalahan_opd as $idx => $perm): ?>
                            <?php if ($idx > 0): ?><hr class="my-2"><?php endif; ?>
                            <div class="mb-2">
                                <strong>Deviasi:</strong> 
                                <span class="badge <?= $perm['deviasi'] >= 11 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                    <?= number_format($perm['deviasi'], 2) ?>%
                                </span>
                                <small class="text-muted ms-2"><?= date('d/m/Y H:i', strtotime($perm['created_at'])) ?></small>
                            </div>
                            <div class="mb-2">
                                <strong>Kode Permasalahan:</strong>
                                <ul class="mb-1">
                                    <?php foreach ($perm['deskripsi'] as $i => $desc): ?>
                                        <li><strong><?= htmlspecialchars($perm['kode'][$i]) ?></strong> - <?= htmlspecialchars($desc) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php if (!empty($perm['keterangan_other'])): ?>
                                <div class="mb-2">
                                    <strong>Keterangan Tambahan:</strong><br>
                                    <span class="text-muted"><?= nl2br(htmlspecialchars($perm['keterangan_other'])) ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Grafik Target, Realisasi, Deviasi OPD -->
            <div class="card mb-3">
                <div class="card-header text-white" style="background-color: #2c4e7a;">
                    <i class="bi bi-bar-chart-fill"></i> Target, Realisasi, dan Deviasi OPD
                </div>
                <div class="card-body" style="height: 200px;">
                    <?php if (empty($grafik_opd)): ?>
                        <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                            Belum ada data deviasi OPD ini.
                        </div>
                    <?php else: ?>
                        <canvas id="chartOpdDeviasi"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- REALISASI vs SISA (Semua OPD) -->
            <div class="card mb-3">
                <div class="card-header text-white" style="background-color: #2c4e7a;">
                    <i class="bi bi-bar-chart-fill"></i> REALISASI vs SISA ANGGARAN (Semua OPD, Persen)
                </div>
                <div class="card-body" style="height: 450px;">
                    <?php if (empty($opd_names_all)): ?>
                        <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                            Belum ada data pagu/realisasi dikunci untuk ditampilkan.
                        </div>
                    <?php else: ?>
                        <canvas id="chartRealisasiSisa" style="width:100%; height:100%;"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (in_array($role, ['admin_opd', 'kepala_opd'])): ?>
<script>
    const opdGrafikData = <?= json_encode($grafik_opd ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/dashboard_analisis_script.php'; ?>