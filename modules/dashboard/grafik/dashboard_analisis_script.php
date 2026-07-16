<?php
/**
 * Dashboard Analisis - Script (Chart.js, AI, Tabel, Countdown)
 * 
 * Keamanan:
 * - Semua data dari PHP telah di-encode dengan json_encode + flag di layout.
 * - Output ke HTML menggunakan escapeHtml() untuk mencegah XSS.
 * - Tidak ada evaluasi kode dinamis (eval, new Function).
 * - Endpoint AI dipanggil dengan POST dan header yang sesuai.
 */

// Pastikan Chart.js dan marked sudah dimuat sebelumnya (oleh footer/layout)
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<style>
    #aiResult, #aiResult *, #permAiResult, #permAiResult * {
        max-width: 100% !important;
        overflow-wrap: anywhere !important;
        word-break: break-word !important;
        white-space: normal !important;
    }
    #aiResult pre, #aiResult code, #permAiResult pre, #permAiResult code {
        white-space: pre-wrap !important;
        word-break: break-all !important;
        overflow-x: auto !important;
        max-width: 100% !important;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ========== Inisialisasi tooltip Bootstrap ==========
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // ========== Fungsi bantuan ==========
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatRupiahJs(angka) {
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(angka);
    }

    // ========== COUNTDOWN TIMER (jika ada) ==========
    if (typeof countdownTarget !== 'undefined' && countdownTarget) {
        function updateCountdown() {
            const now = Math.floor(Date.now() / 1000);
            const diff = countdownTarget - now;
            const timerEl = document.getElementById('countdownTimer');
            const labelEl = document.getElementById('countdownLabel');
            if (!timerEl) return;

            if (diff <= 0) {
                timerEl.textContent = 'WAKTU HABIS';
                labelEl.textContent = '⏰';
                // Reload halaman setelah 3 detik agar sistem otomatis mengunci
                setTimeout(() => location.reload(), 3000);
                return;
            }

            const days = Math.floor(diff / 86400);
            const hours = Math.floor((diff % 86400) / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;

            let display = '';
            if (days > 0) display += days + ' hari ';
            display += String(hours).padStart(2,'0') + ':' + 
                       String(minutes).padStart(2,'0') + ':' + 
                       String(seconds).padStart(2,'0');
            timerEl.textContent = display;
            labelEl.textContent = '⏳ ' + (days > 0 ? days + ' hari ' : '') + 'tersisa';
        }
        updateCountdown();
        setInterval(updateCountdown, 1000);
    }

    // ========== Data dari server (sudah di-encode aman) ==========
    <?php if (in_array($role, ['super_admin', 'eksekutif'])): ?>
    // Data untuk Super Admin / Eksekutif
    const chartData       = <?= json_encode($grafik_deviasi ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const opdNames        = <?= json_encode($opd_names ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const realPercent     = <?= json_encode($real_persen ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const sisaPercent     = <?= json_encode($sisa_persen ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const topLabels       = <?= json_encode(array_column($top5 ?? [], 'opd'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const topValues       = <?= json_encode(array_column($top5 ?? [], 'total'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const bottomLabels    = <?= json_encode(array_column($bottom5 ?? [], 'opd'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const bottomValues    = <?= json_encode(array_column($bottom5 ?? [], 'total'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const devTop5Labels   = <?= json_encode(array_column($deviasi_top5 ?? [], 'nama_opd_pendek'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const devTop5Values   = <?= json_encode(array_column($deviasi_top5 ?? [], 'deviasi'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const devBottom5Labels= <?= json_encode(array_column($deviasi_bottom5 ?? [], 'nama_opd_pendek'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const devBottom5Values= <?= json_encode(array_column($deviasi_bottom5 ?? [], 'deviasi'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const aiData          = <?= json_encode($ai_data ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const permasalahanData= <?= json_encode($permasalahan_data ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const allOpdRows      = <?= json_encode($all_opd_rows ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // --- Grafik Horizontal (Target, Realisasi, Deviasi per OPD) ---
    const hCanvas = document.getElementById('horizontalChart');
    if (hCanvas) {
        let labels = [];
        let targetData = [];
        let realisasiData = [];
        let deviasiData = [];

        if (chartData.length > 0) {
            labels = chartData.map(d => d.nama_opd_pendek);
            targetData = chartData.map(d => d.target_persen);
            realisasiData = chartData.map(d => d.realisasi_persen);
            deviasiData = chartData.map(d => d.deviasi);
        } else if (opdNames.length > 0) {
            // fallback jika hanya data realisasi vs sisa (tidak ada deviasi)
            labels = opdNames;
            const zeroArr = new Array(opdNames.length).fill(0);
            targetData = zeroArr;
            realisasiData = zeroArr;
            deviasiData = zeroArr;
        }

        if (labels.length > 0) {
            const calcHeight = Math.max(400, labels.length * 35 + 100);
            hCanvas.parentElement.style.height = calcHeight + 'px';
            new Chart(hCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Target (%)', data: targetData, backgroundColor: '#1a3a5c', borderRadius: 6 },
                        { label: 'Realisasi (%)', data: realisasiData, backgroundColor: '#c9a84c', borderRadius: 6 },
                        { label: 'Deviasi (%)', data: deviasiData, backgroundColor: '#e74c3c', borderRadius: 6 }
                    ]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': ' + ctx.raw.toFixed(2) + '%' } },
                        legend: { position: 'top', labels: { font: { size: 11 } } }
                    },
                    scales: {
                        x: { title: { display: true, text: 'Persentase (%)' }, beginAtZero: true, max: 100,
                             ticks: { callback: val => val + '%' } },
                        y: { ticks: { autoSkip: false, font: { size: 10 } } }
                    }
                }
            });
        }
    }

    // --- Realisasi vs Sisa per OPD ---
    const ctxRS = document.getElementById('chartRealisasiSisa')?.getContext('2d');
    if (ctxRS && opdNames.length) {
        new Chart(ctxRS, {
            type: 'bar',
            data: {
                labels: opdNames,
                datasets: [
                    { label: 'Realisasi (%)', data: realPercent, backgroundColor: 'rgba(40,167,69,0.8)', borderRadius: 8 },
                    { label: 'Sisa (%)', data: sisaPercent, backgroundColor: 'rgba(220,53,69,0.8)', borderRadius: 8 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: { x: { ticks: { autoSkip: true, maxRotation: 45 } }, y: { beginAtZero: true, max: 100 } }
            }
        });
    }

    // --- Donut Realisasi vs Sisa (provinsi) ---
    const ctxDonut = document.getElementById('chartPeningkatan')?.getContext('2d');
    if (ctxDonut) {
        new Chart(ctxDonut, {
            type: 'doughnut',
            data: {
                labels: ['Realisasi', 'Sisa'],
                datasets: [{
                    data: [<?= $persen_real_terbaru ?>, <?= $persen_sisa_terbaru ?>],
                    backgroundColor: ['rgba(40,167,69,0.8)', 'rgba(255,193,7,0.8)']
                }]
            },
            options: { responsive: true, cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } } }
        });
    }

    // --- Total Pagu, Realisasi, Sisa ---
    const ctxTotal = document.getElementById('chartTotalPaguRealSisa')?.getContext('2d');
    if (ctxTotal) {
        new Chart(ctxTotal, {
            type: 'bar',
            data: {
                labels: ['Provinsi'],
                datasets: [
                    { label: 'Pagu', data: [<?= $total_anggaran ?>], backgroundColor: 'rgba(0,51,102,0.8)' },
                    { label: 'Realisasi', data: [<?= $total_realisasi ?>], backgroundColor: 'rgba(40,167,69,0.8)' },
                    { label: 'Sisa', data: [<?= $total_anggaran - $total_realisasi ?>], backgroundColor: 'rgba(255,193,7,0.8)' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { tooltip: { callbacks: { label: (ctx) => 'Rp ' + new Intl.NumberFormat('id-ID').format(ctx.raw) } } },
                scales: { y: { ticks: { callback: val => new Intl.NumberFormat('id-ID').format(val) } } }
            }
        });
    }

    // --- Top 5 Realisasi ---
    const ctxTop = document.getElementById('chartTop5')?.getContext('2d');
    if (ctxTop && topLabels.length) {
        new Chart(ctxTop, {
            type: 'bar',
            data: { labels: topLabels, datasets: [{ label: 'Realisasi (%)', data: topValues, backgroundColor: 'rgba(255,193,7,0.9)', borderRadius: 8 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true, max: 100 } } }
        });
    }

    // --- Bottom 5 Realisasi ---
    const ctxBot = document.getElementById('chartBottom5')?.getContext('2d');
    if (ctxBot && bottomLabels.length) {
        new Chart(ctxBot, {
            type: 'bar',
            data: { labels: bottomLabels, datasets: [{ label: 'Realisasi (%)', data: bottomValues, backgroundColor: 'rgba(220,53,69,0.8)', borderRadius: 8 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true, max: 100 } } }
        });
    }

    // --- Top 5 Deviasi ---
    const ctxDevTop = document.getElementById('chartDeviasiTop5')?.getContext('2d');
    if (ctxDevTop && devTop5Labels.length) {
        new Chart(ctxDevTop, {
            type: 'bar',
            data: { labels: devTop5Labels, datasets: [{ label: 'Deviasi (%)', data: devTop5Values, backgroundColor: 'rgba(255,193,7,0.9)', borderRadius: 8 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { title: { display: true, text: 'Deviasi (%)' } } } }
        });
    }

    // --- Bottom 5 Deviasi ---
    const ctxDevBot = document.getElementById('chartDeviasiBottom5')?.getContext('2d');
    if (ctxDevBot && devBottom5Labels.length) {
        new Chart(ctxDevBot, {
            type: 'bar',
            data: { labels: devBottom5Labels, datasets: [{ label: 'Deviasi (%)', data: devBottom5Values, backgroundColor: 'rgba(220,53,69,0.8)', borderRadius: 8 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { title: { display: true, text: 'Deviasi (%)' } } } }
        });
    }

    // --- Tabel OPD dengan Sort & Pagination ---
    let currentPage = 1;
    let rowsPerPage = 10;
    let sortColumn = 'nama_opd';
    let sortDirection = 'asc';

    function sortData(data, column, direction) {
        return data.slice().sort((a, b) => {
            let valA = a[column], valB = b[column];
            if (typeof valA === 'string') valA = valA.toLowerCase();
            if (typeof valB === 'string') valB = valB.toLowerCase();
            if (valA < valB) return direction === 'asc' ? -1 : 1;
            if (valA > valB) return direction === 'asc' ? 1 : -1;
            return 0;
        });
    }

    let sortedData = sortData([...allOpdRows], sortColumn, sortDirection);

    function renderOpdTable() {
        const tbody = document.getElementById('opdTableBody');
        const tableInfo = document.getElementById('tableInfo');
        if (!tbody) return;
        const start = (currentPage - 1) * rowsPerPage;
        const pageData = sortedData.slice(start, start + rowsPerPage);
        tbody.innerHTML = pageData.map(row => `
            <tr>
                <td>${escapeHtml(row.nama_opd)}</td>
                <td>${escapeHtml(row.nama_kepala)}</td>
                <td>${formatRupiahJs(row.total_anggaran)}</td>
                <td>${formatRupiahJs(row.total_realisasi)}</td>
                <td>${row.persen}%</td>
            </tr>`).join('');
        const totalItems = sortedData.length;
        const end = Math.min(start + rowsPerPage, totalItems);
        if (tableInfo) tableInfo.textContent = `Menampilkan ${start+1}-${end} dari ${totalItems}`;
        renderPagination();
    }

    function renderPagination() {
        const container = document.getElementById('paginationControls');
        if (!container) return;
        const totalPages = Math.ceil(sortedData.length / rowsPerPage);
        if (totalPages <= 1) { container.innerHTML = ''; return; }
        let html = '<ul class="pagination pagination-sm mb-0">';
        html += `<li class="page-item ${currentPage===1?'disabled':''}"><a class="page-link" href="#" data-page="${currentPage-1}">«</a></li>`;
        for (let i=1; i<=totalPages; i++) {
            html += `<li class="page-item ${i===currentPage?'active':''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }
        html += `<li class="page-item ${currentPage===totalPages?'disabled':''}"><a class="page-link" href="#" data-page="${currentPage+1}">»</a></li>`;
        html += '</ul>';
        container.innerHTML = html;
        container.querySelectorAll('.page-link').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                const p = parseInt(link.dataset.page);
                if (p >= 1 && p <= totalPages) { currentPage = p; renderOpdTable(); }
            });
        });
    }

    document.getElementById('rowsPerPageSelect')?.addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        renderOpdTable();
    });

    document.querySelectorAll('#opdTable th[data-sort]').forEach(th => {
        th.addEventListener('click', function() {
            const column = this.dataset.sort;
            if (sortColumn === column) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortColumn = column;
                sortDirection = 'asc';
            }
            document.querySelectorAll('#opdTable th i').forEach(icon => icon.className = 'bi bi-arrow-up-down ms-1');
            this.querySelector('i').className = sortDirection === 'asc' ? 'bi bi-sort-up ms-1' : 'bi bi-sort-down ms-1';
            sortedData = sortData([...allOpdRows], sortColumn, sortDirection);
            currentPage = 1;
            renderOpdTable();
        });
    });

    renderOpdTable();

    // --- AI Analysis untuk Grafik ---
    if (Object.keys(aiData).length > 0 && chartData.length > 0) {
        fetch('tool/ajax_groq.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                tab: 'dashboard_grafik',
                data: {
                    ...aiData,
                    opd_teratas: chartData.map(item => ({
                        nama: item.nama_opd_pendek,
                        target_persen: item.target_persen,
                        realisasi_persen: item.realisasi_persen,
                        deviasi: item.deviasi
                    }))
                }
            })
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('aiLoading').style.display = 'none';
            const aiRes = document.getElementById('aiResult');
            if (data.success && data.analysis) {
                aiRes.innerHTML = '<div class="ai-text">' + marked.parse(data.analysis) + '</div>';
                aiRes.style.display = 'block';
            } else {
                aiRes.innerHTML = '<div class="alert alert-warning">Gagal: ' + escapeHtml(data.error || 'Unknown') + '</div>';
                aiRes.style.display = 'block';
            }
        })
        .catch(err => {
            document.getElementById('aiLoading').style.display = 'none';
            document.getElementById('aiResult').innerHTML = '<div class="alert alert-danger">Error koneksi AI.</div>';
            document.getElementById('aiResult').style.display = 'block';
        });
    } else {
        const aiLoading = document.getElementById('aiLoading');
        if (aiLoading) aiLoading.innerHTML = '<p class="text-muted">Tidak ada data untuk dianalisis.</p>';
    }

    // --- AI Analysis untuk Permasalahan ---
    if (permasalahanData.length > 0) {
        fetch('tool/ajax_groq.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                tab: 'permasalahan_analysis',
                data: { permasalahan: permasalahanData }
            })
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('permAiLoading').style.display = 'none';
            const permRes = document.getElementById('permAiResult');
            if (data.success && data.analysis) {
                permRes.innerHTML = '<div class="ai-text">' + marked.parse(data.analysis) + '</div>';
                permRes.style.display = 'block';
            } else {
                permRes.innerHTML = '<div class="alert alert-warning">Gagal analisis: ' + escapeHtml(data.error || 'Unknown') + '</div>';
                permRes.style.display = 'block';
            }
        })
        .catch(err => {
            document.getElementById('permAiLoading').style.display = 'none';
            document.getElementById('permAiResult').innerHTML = '<div class="alert alert-danger">Error koneksi AI.</div>';
            document.getElementById('permAiResult').style.display = 'block';
        });
    } else {
        const permLoading = document.getElementById('permAiLoading');
        if (permLoading) permLoading.innerHTML = '<p class="text-muted">Belum ada data permasalahan yang diinput OPD bulan ini.</p>';
    }

    <?php endif; // super_admin / eksekutif ?>

    // ============ ADMIN OPD / KEPALA OPD ============
    <?php if (in_array($role, ['admin_opd', 'kepala_opd'])): ?>
    const opdNamesAll    = <?= json_encode($opd_names_all ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const realPercentAll = <?= json_encode($real_persen_all ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const sisaPercentAll = <?= json_encode($sisa_persen_all ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const markedOpdName  = <?= json_encode($marked_opd_name_pendek ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Grafik Realisasi vs Sisa semua OPD (dengan highlight OPD sendiri)
    if (opdNamesAll.length > 0) {
        const bgReal = opdNamesAll.map(name => name === markedOpdName ? 'rgba(201, 168, 76, 0.9)' : 'rgba(40, 167, 69, 0.8)');
        const bgSisa = opdNamesAll.map(name => name === markedOpdName ? 'rgba(255, 193, 7, 0.9)' : 'rgba(220, 53, 69, 0.8)');

        const ctxRvs = document.getElementById('chartRealisasiSisa')?.getContext('2d');
        if (ctxRvs) {
            new Chart(ctxRvs, {
                type: 'bar',
                data: {
                    labels: opdNamesAll,
                    datasets: [
                        { label: 'Realisasi (%)', data: realPercentAll, backgroundColor: bgReal, borderRadius: 8 },
                        { label: 'Sisa (%)', data: sisaPercentAll, backgroundColor: bgSisa, borderRadius: 8 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + ctx.raw.toFixed(2) + '%' } } },
                    scales: { x: { ticks: { autoSkip: true, maxRotation: 45 } }, y: { beginAtZero: true, max: 100 } }
                }
            });
        }
    }

    // Grafik Deviasi OPD (Target, Realisasi, Deviasi)
    if (typeof opdGrafikData !== 'undefined' && opdGrafikData.length > 0) {
        const ctxOpdDev = document.getElementById('chartOpdDeviasi')?.getContext('2d');
        if (ctxOpdDev) {
            const item = opdGrafikData[0];
            new Chart(ctxOpdDev, {
                type: 'bar',
                data: {
                    labels: [item.nama_opd_pendek],
                    datasets: [
                        { label: 'Target (%)', data: [item.target_persen], backgroundColor: '#1a3a5c', borderRadius: 6 },
                        { label: 'Realisasi (%)', data: [item.realisasi_persen], backgroundColor: '#c9a84c', borderRadius: 6 },
                        { label: 'Deviasi (%)', data: [item.deviasi], backgroundColor: '#e74c3c', borderRadius: 6 }
                    ]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { font: { size: 10 } } } },
                    scales: { x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } }
                }
            });
        }
    }
    <?php endif; ?>

    <?php if (in_array($role, ['admin_opd', 'kepala_opd']) && !$batas_waktu_lewat && $batas_waktu_info): ?>
    (function() {
        // Ambil timestamp target dari PHP
        const targetTime = new Date("<?= date('Y-m-d\TH:i:s', strtotime($batas_waktu_info['batas_waktu'])) ?>").getTime();

        const countdownElement = document.getElementById('countdownTimer');
        if (!countdownElement) return;

        function updateCountdown() {
            const now = new Date().getTime();
            const distance = targetTime - now;

            if (distance <= 0) {
                countdownElement.innerHTML = '<span class="text-danger fw-bold">Batas waktu sudah habis! Halaman akan dimuat ulang.</span>';
                clearInterval(timerInterval);
                // Muat ulang halaman setelah 3 detik agar status terbaru tampil
                setTimeout(function() { location.reload(); }, 3000);
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            let display = '';
            if (days > 0) display += days + ' hari ';
            if (hours > 0) display += hours + ' jam ';
            if (minutes > 0) display += minutes + ' menit ';
            display += seconds + ' detik';

            countdownElement.textContent = display;
        }

        // Jalankan segera dan ulangi setiap detik
        updateCountdown();
        const timerInterval = setInterval(updateCountdown, 1000);
    })();
    <?php endif; ?>
});
</script>