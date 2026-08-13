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

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    if ($id == $_SESSION['user_id']) {
        die('Anda tidak bisa menghapus akun sendiri.');
    }
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    header('Location: users.php?msg=deleted');
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Manajemen User - Creative Geek</title>
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
        .card-dark { background:#111; border:1px solid #1f1f1f; border-radius:16px; padding:20px; }
        .btn-gold { background:#f5a623; color:#000; font-weight:600; padding:8px 20px; border-radius:10px; transition:all 0.2s; display:inline-block; }
        .btn-gold:hover { background:#e09515; transform:translateY(-2px); box-shadow:0 4px 20px rgba(245,166,35,0.3); }
        .btn-outline { background:transparent; border:1px solid #2a2a2a; color:#d4d4d8; padding:8px 20px; border-radius:10px; transition:all 0.2s; text-decoration:none; display:inline-block; }
        .btn-outline:hover { border-color:#f5a623; color:#f5a623; }
        .table-row { border-bottom:1px solid #1f1f1f; transition:background 0.15s; }
        .table-row:hover { background:#181818; }
        .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .table-wrap table { min-width:600px; width:100%; }
        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-track { background:#0a0a0a; }
        ::-webkit-scrollbar-thumb { background:#2a2a2a; border-radius:10px; }
        ::-webkit-scrollbar-thumb:hover { background:#f5a623; }
        @media (max-width:640px) { .card-dark { padding:12px; } }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <?php include __DIR__ . '/sidebar.php'; ?>
    </aside>

    <main class="main-content p-4 md:p-6 bg-[#0a0a0a]" id="mainContent">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <div class="flex items-center gap-3">
                <button class="hamburger-btn text-gray-400 hover:text-gold text-2xl" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="text-xl md:text-2xl font-bold text-gold"><i class="fas fa-users-cog mr-2"></i> Manajemen User</h1>
            </div>
            <a href="user_form.php" class="btn-gold"><i class="fas fa-plus mr-1"></i> Tambah User</a>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
            <div class="bg-green-900/50 text-green-300 p-3 rounded mb-4"><i class="fas fa-check-circle mr-1"></i> User berhasil dihapus!</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'updated'): ?>
            <div class="bg-blue-900/50 text-blue-300 p-3 rounded mb-4"><i class="fas fa-check-circle mr-1"></i> User berhasil disimpan!</div>
        <?php endif; ?>

        <div class="card-dark">
            <div class="table-wrap">
                <table class="w-full text-sm">
                    <thead class="bg-[#0a0a0a]">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs text-gold uppercase">ID</th>
                            <th class="px-6 py-3 text-left text-xs text-gold uppercase">Username</th>
                            <th class="px-6 py-3 text-left text-xs text-gold uppercase">Role</th>
                            <th class="px-6 py-3 text-left text-xs text-gold uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr class="hover:bg-[#1a1a1a]">
                            <td class="px-6 py-4"><?= $user['id'] ?></td>
                            <td class="px-6 py-4"><?= htmlspecialchars($user['username']) ?></td>
                            <td class="px-6 py-4">
                                <span class="bg-gold text-black px-2 py-1 rounded text-xs font-bold" style="background:#f5a623; color:#000;"><?= $user['role'] ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a href="user_form.php?id=<?= $user['id'] ?>" class="text-gold hover:underline mr-2"><i class="fas fa-edit"></i></a>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <a href="users.php?delete=<?= $user['id'] ?>" class="text-red-500 hover:underline" onclick="return confirm('Yakin hapus user ini?')"><i class="fas fa-trash"></i></a>
                                <?php else: ?>
                                <span class="text-gray-500 text-sm">(Anda)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
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