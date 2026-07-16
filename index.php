<?php
/**
 * index.php - Halaman depan aplikasi
 * 
 * Keamanan:
 * - Redirect ke dashboard jika sudah login
 * - Tidak ada output sebelum redirect (mencegah header already sent)
 */
require_once __DIR__ . '/config/database.php';   // Memuat BASE_URL & koneksi (jika diperlukan)
require_once __DIR__ . '/includes/functions.php'; // Memuat requireLogin()

// requireLogin() akan memeriksa session; jika belum login, redirect ke login.php
requireLogin();

// Jika sudah login, arahkan ke dashboard
header('Location: modules/dashboard/index.php');
exit();