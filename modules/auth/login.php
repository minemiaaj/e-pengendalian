<?php
/**
 * login.php - Halaman Login E-Pengendalian
 * 
 * Keamanan:
 * - Mendukung password_hash (bcrypt) dan fallback MD5 untuk kompatibilitas
 * - Otomatis upgrade password MD5 ke bcrypt saat login sukses
 * - Prepared statement untuk mencegah SQL injection
 * - Session security (HttpOnly, Secure, SameSite)
 * - CSRF token
 * - Brute-force protection
 * - Pesan error generik
 * - Simpan path foto ke session
 */

// ========== KONFIGURASI SESSION AMAN ==========
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict'
    ];
    session_set_cookie_params($cookieParams);
    session_start();
}

// ========== LOAD DATABASE ==========
require_once __DIR__ . '/../../config/database.php';

// ========== CSRF TOKEN ==========
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ========== BRUTE‑FORCE PROTECTION ==========
$max_attempts = 5;
$lockout_time = 900; // 15 menit

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['first_attempt_time'] = time();
}

$blocked = false;
$remaining_time = 0;
if ($_SESSION['login_attempts'] >= $max_attempts) {
    $elapsed = time() - $_SESSION['first_attempt_time'];
    if ($elapsed < $lockout_time) {
        $blocked = true;
        $remaining_time = $lockout_time - $elapsed;
    } else {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }
}

// ========== JIKA SUDAH LOGIN, ARAHKAN KE DASHBOARD ==========
if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard/index.php');
    exit();
}

$error = '';

// ========== PROSES LOGIN ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifikasi CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } elseif ($blocked) {
        $minutes = ceil($remaining_time / 60);
        $error = "Terlalu banyak percobaan. Silakan coba lagi dalam {$minutes} menit.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username dan password harus diisi.';
        } else {
            // Catat percobaan
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] == 1) {
                $_SESSION['first_attempt_time'] = time();
            }

            // Prepared statement - ambil foto untuk session
            $stmt = $conn->prepare("SELECT id, username, password, nama_lengkap, role, opd_id, status, foto 
                                    FROM users WHERE username = ? AND status = 'aktif' LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $authenticated = false;
                $needs_rehash = false;

                // Cek dengan password_verify (bcrypt)
                if (password_verify($password, $row['password'])) {
                    $authenticated = true;
                    if (password_needs_rehash($row['password'], PASSWORD_DEFAULT)) {
                        $needs_rehash = true;
                    }
                }
                // Fallback ke MD5 untuk password lama
                elseif (md5($password) === $row['password']) {
                    $authenticated = true;
                    $needs_rehash = true;
                }

                if ($authenticated) {
                    // Upgrade password ke bcrypt jika perlu
                    if ($needs_rehash) {
                        $new_hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt_upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt_upd->bind_param('si', $new_hash, $row['id']);
                        $stmt_upd->execute();
                        $stmt_upd->close();
                    }

                    // Login berhasil - simpan SEMUA data ke session
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id']       = $row['id'];
                    $_SESSION['username']      = $row['username'];
                    $_SESSION['nama_lengkap']  = $row['nama_lengkap'];
                    $_SESSION['role']          = $row['role'];
                    $_SESSION['opd_id']        = $row['opd_id'];
                    $_SESSION['foto']          = $row['foto'];   // <-- TAMBAHKAN INI
                    
                    // Reset percobaan
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['first_attempt_time'] = 0;
                    
                    header('Location: ../dashboard/index.php');
                    exit();
                } else {
                    $error = 'Username atau password salah.';
                }
            } else {
                $error = 'Username atau password salah.';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
    <title>Login - E-Pengendalian Sultra</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #0d2137 0%, #1a3a5c 100%);
            display: flex; align-items: center; justify-content: center; padding: 1rem;
        }
        .login-card {
            background: white; border-radius: 2rem; padding: 2rem 1.8rem;
            max-width: 440px; width: 100%;
            box-shadow: 0 20px 35px rgba(0,0,0,0.2); transition: transform 0.3s;
            border-top: 6px solid #c9a84c;
        }
        .login-card:hover { transform: translateY(-5px); }
        .logo-container { text-align: center; margin-bottom: 1.5rem; }
        .logo-login { width: 85px; height: 85px; object-fit: contain; margin-bottom: 0.5rem; }
        .login-card h4 { color: #1a3a5c; font-weight: 700; font-size: 1.6rem; margin-bottom: 0.25rem; }
        .login-card .subtitle { color: #6c757d; font-size: 0.85rem; margin-bottom: 1.8rem; }
        .form-control {
            border-radius: 50px; padding: 0.75rem 1.2rem; border: 1px solid #ced4da; transition: all 0.2s;
        }
        .form-control:focus { border-color: #c9a84c; box-shadow: 0 0 0 3px rgba(201,168,76,0.25); }
        .btn-login {
            background: #1a3a5c; color: white; border-radius: 50px; padding: 0.75rem;
            font-weight: 600; width: 100%; border: none; transition: all 0.3s;
        }
        .btn-login:hover { background: #c9a84c; color: #0d2137; transform: scale(1.02); }
        .alert { border-radius: 50px; font-size: 0.85rem; }
        @media (max-width: 480px) {
            .login-card { padding: 1.5rem 1.2rem; }
            .logo-login { width: 70px; height: 70px; }
            .login-card h4 { font-size: 1.4rem; }
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="logo-container">
        <img src="../../assets/images/logo.png" alt="Logo" class="logo-login">
        <h4>E-PENGENDALIAN</h4>
        <div class="subtitle">Provinsi Sulawesi Tenggara</div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger text-center mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <div class="mb-3">
            <div class="input-group">
                <span class="input-group-text bg-transparent border-end-0"><i class="bi bi-person"></i></span>
                <input type="text" name="username" class="form-control border-start-0" placeholder="Username" required autofocus autocomplete="username">
            </div>
        </div>
        <div class="mb-4">
            <div class="input-group">
                <span class="input-group-text bg-transparent border-end-0"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" class="form-control border-start-0" placeholder="Password" required autocomplete="current-password">
            </div>
        </div>
        <button type="submit" class="btn btn-login">
            <i class="bi bi-box-arrow-in-right"></i> MASUK
        </button>
    </form>
    <div class="text-center mt-4 small text-muted">
        Sistem Pengendalian Program Pemerintah
    </div>
</div>
</body>
</html>
