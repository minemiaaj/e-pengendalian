<?php
/**
 * opd_mapping.php – Pemetaan UPTD / KCD ke Dinas Induk
 * 
 * Keamanan:
 * - Hanya dapat di‑include dari dalam aplikasi (cek konstanta IN_APP)
 * - Data statis, tidak ada logika yang dapat dieksploitasi
 */

// Cegah akses langsung ke file ini
$opd_mapping = [
    // ========== KCD Pendidikan -> DINAS PENDIDIKAN DAN KEBUDAYAAN ==========
    'KCD Pendidikan Baubau-Buton Selatan'            => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Bombana'                        => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Buton'                          => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Buton Utara'                    => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Kolaka'                         => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Kolaka Utara'                   => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD PENDIDIKAN KONAWE KEPULAUAN'               => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Konawe Selatan'                 => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Konawe-Konawe Utara'            => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Muna'                           => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Muna Barat-Buton Tengah'        => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'KCD Pendidikan Wakatobi'                       => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',

    // ========== UPTD Pendidikan & Kebudayaan -> DINAS PENDIDIKAN DAN KEBUDAYAAN ==========
    'UPTD BTIKP'                                    => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'UPTD MUSEUM DAN TAMAN BUDAYA'                  => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',
    'UPTD PENANGANAN SISWA BERKEBUTUHAN KHUSUS (PSBK)' => 'DINAS PENDIDIKAN DAN KEBUDAYAAN',

    // ========== UPTD Kehutanan (KPH) -> DINAS KEHUTANAN ==========
    'UPTD KPH UNIT I KAPONTORI'                    => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT II LASALIMU'                    => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT III LAKOMPA'                    => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT IV KATONDAKI'                   => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT V WAKONTI'                      => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT VI PULAU MUNA'                  => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT VII PEROPAEA'                   => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT VIII GANTARA'                   => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT IX PULAU KABAENA'               => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT X TINA ORIMA'                   => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XI MEKONGGA SELATAN'            => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XII LADONGI'                    => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XIII MEKONGGA UTARA'            => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XIV UEESI'                      => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XV ALAAHA'                      => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XVI PATAMPANUA SELATAN'         => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XVII PATAMPANUA UTARA'          => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XVIII LAIWOI BARAT'             => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XX LAIWOI TENGAH'            => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XIX LAIWOI UTARA'             => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XXI LAIWOI TENGGARA'            => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XXII LAIWOI'                    => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XXIII PULAU WAWONII'            => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XXIV GULARAYA'                  => 'DINAS KEHUTANAN',
    'UPTD KPH UNIT XXV WAKATOBI'                   => 'DINAS KEHUTANAN',
    'UPTD BALAI PENGELOLAAN TAHURA NIPA-NIPA'      => 'DINAS KEHUTANAN',
    'UPTD BALAI PENGAWASAN DAN PENGENDALIAN PERBENIHAN TANAMAN HUTAN (BP3TH)' => 'DINAS KEHUTANAN',
];
