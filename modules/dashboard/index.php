<?php
/**
 * Dashboard - Halaman Utama
 * 
 * Keamanan:
 * - Proteksi akses langsung (pastikan IN_APP terdefinisi)
 * - Semua dependensi telah memiliki session dan validasi role
 */
require_once __DIR__ . '/../../includes/header.php';          // session, role, BASE_URL
require_once __DIR__ . '/grafik/dashboard_analisis_functions.php'; // logika & query (sudah aman)
require_once __DIR__ . '/grafik/dashboard_analisis_layout.php';   // tampilan (output di-escape)
require_once __DIR__ . '/../../includes/footer.php';
