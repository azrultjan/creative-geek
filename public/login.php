<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start(); // <-- WAJIB

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/menus.php';

$company = getCompanyData();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        // SEMUA ROLE LANGSUNG KE DASHBOARD (unified)
        header('Location: dashboard.php');
        exit;
    } else {
        $error = "Username atau password salah!";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Creative Geek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #0a0a0a; }
        .card-dark { background: #151515; border: 1px solid #2a2a2a; border-radius: 16px; }
        .text-gold { color: #F5A623; }
        .bg-gold { background: #F5A623; }
        .border-gold { border-color: #F5A623; }
        .hover-gold:hover { background: #e09515; }
        input {
            background: #0a0a0a;
            border: 1px solid #2a2a2a;
            color: #ffffff !important;
            padding: 10px 14px;
            border-radius: 8px;
            width: 100%;
        }
        input:focus { outline: none; border-color: #F5A623; }
        input::placeholder { color: #666; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="card-dark p-8 w-full max-w-md shadow-2xl">
        <div class="text-center">
            <div class="bg-gold text-black font-bold text-3xl px-4 py-2 inline-block rounded-lg">CREATIVE GEEK</div>
            <h2 class="text-3xl font-bold text-gold mt-3">Login</h2>
            <p class="text-gray-400 text-sm">Manajemen Pesanan Cetak</p>
            <p class="text-gray-500 text-xs mt-1"><?= $company['address'] ?></p>
        </div>
        <?php if (isset($error)): ?>
            <div class="bg-red-900/50 text-red-300 p-3 rounded mt-4 border border-red-700"><?= $error ?></div>
        <?php endif; ?>
        <form method="POST" class="mt-6">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300">Username</label>
                <input type="text" name="username" placeholder="Masukkan username" required>
            </div>
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-300">Password</label>
                <input type="password" name="password" placeholder="Masukkan password" required>
            </div>
            <button type="submit" class="w-full bg-gold text-black py-2 rounded-lg font-bold hover:bg-[#e09515] transition">LOGIN</button>
        </form>
        <div class="text-center text-xs text-gray-500 mt-4 border-t border-[#222] pt-3">
            🕐 <?= $company['hours'] ?> • 📞 <?= $company['phone'] ?>
        </div>
    </div>
</body>
</html>