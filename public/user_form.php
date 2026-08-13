<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/menus.php';

$id = $_GET['id'] ?? 0;
$is_edit = $id > 0;
$user = null;
if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) die('User tidak ditemukan.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $role = $_POST['role'];
    $password = $_POST['password'] ?? '';

    if ($is_edit) {
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET username=?, password=?, role=? WHERE id=?")->execute([$username, $hash, $role, $id]);
        } else {
            $pdo->prepare("UPDATE users SET username=?, role=? WHERE id=?")->execute([$username, $role, $id]);
        }
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?,?,?)")->execute([$username, $hash, $role]);
    }
    header('Location: users.php?msg=updated');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?= $is_edit ? 'Edit' : 'Tambah' ?> User - Creative Geek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #0a0a0a; color: #e5e7eb; min-height: 100vh; }
        .sidebar { background: #0d0d0d; border-right: 1px solid #1f1f1f; position:fixed; top:0; left:0; height:100%; width:250px; z-index:50; transform:translateX(-100%); transition:transform 0.3s ease; overflow-y:auto; padding:20px 0; }
        .sidebar.open { transform:translateX(0); }
        .sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:40; }
        .sidebar-overlay.active { display:block; }
        .sidebar-link { display:flex; align-items:center; gap:14px; padding:10px 20px; border-radius:10px; color:#9ca3af; transition:all 0.2s; text-decoration:none; margin:2px 12px; font-size:14px; }
        .sidebar-link i { width:20px; font-size:16px; text-align:center; }
        .sidebar-link:hover { background:#1a1a1a; color:#f5a623; }
        .sidebar-link.active { background:#1a1a1a; color:#f5a623; font-weight:600; border-right:2px solid #f5a623; }
        .main-content { margin-left:0; transition:margin-left 0.3s ease; }
        @media (min-width:768px) {
            .sidebar { transform:translateX(0); width:230px; }
            .sidebar-overlay { display:none !important; }
            .main-content { margin-left:230px; }
            .hamburger-btn { display:none !important; }
        }
        .text-gold { color:#f5a623; }
        .card-dark { background:#151515; border:1px solid #2a2a2a; border-radius:12px; padding:24px; }
        .btn-gold { background:#f5a623; color:#000; font-weight:600; padding:8px 20px; border-radius:10px; transition:all 0.2s; border:none; cursor:pointer; display:inline-block; }
        .btn-gold:hover { background:#e09515; transform:translateY(-2px); box-shadow:0 4px 20px rgba(245,166,35,0.3); }
        .btn-outline { background:transparent; border:1px solid #2a2a2a; color:#d4d4d8; padding:8px 20px; border-radius:10px; transition:all 0.2s; text-decoration:none; display:inline-block; }
        .btn-outline:hover { border-color:#f5a623; color:#f5a623; }
        input, select { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:8px 12px; border-radius:8px; width:100%; }
        input:focus, select:focus { outline:none; border-color:#f5a623; }
        @media (max-width:640px) { .card-dark { padding:16px; } }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <?php include __DIR__ . '/sidebar.php'; ?>
    </aside>

    <main class="main-content p-4 md:p-6 bg-[#0a0a0a]" id="mainContent">
        <div class="flex items-center gap-3 mb-5">
            <button class="hamburger-btn text-gray-400 hover:text-gold text-2xl" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-xl md:text-2xl font-bold text-gold"><i class="fas <?= $is_edit ? 'fa-edit' : 'fa-user-plus' ?> mr-2"></i> <?= $is_edit ? 'Edit' : 'Tambah' ?> User</h1>
        </div>

        <form method="POST" class="card-dark max-w-2xl">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300"><i class="fas fa-user mr-1"></i> Username</label>
                <input type="text" name="username" required value="<?= $is_edit ? htmlspecialchars($user['username']) : '' ?>">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300"><i class="fas fa-key mr-1"></i> Role</label>
                <select name="role">
                    <option value="admin" <?= ($is_edit && $user['role'] == 'admin') ? 'selected' : '' ?>>Admin</option>
                    <option value="operator" <?= ($is_edit && $user['role'] == 'operator') ? 'selected' : '' ?>>Operator</option>
                    <option value="owner" <?= ($is_edit && $user['role'] == 'owner') ? 'selected' : '' ?>>Owner</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300"><i class="fas fa-lock mr-1"></i> Password <?= $is_edit ? '(kosongkan jika tidak diubah)' : '' ?></label>
                <input type="password" name="password" <?= $is_edit ? '' : 'required' ?>>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-gold"><i class="fas fa-save mr-1"></i> Simpan</button>
                <a href="users.php" class="btn-outline"><i class="fas fa-times mr-1"></i> Batal</a>
            </div>
        </form>
    </main>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                }
            }
        });
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768) {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebarOverlay').classList.remove('active');
            }
        });
    </script>
</body>
</html>