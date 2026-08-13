<?php
session_start();
if ($_SESSION['role'] !== 'owner') {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/menus.php';

// ============ PASTIKAN TABEL DASHBOARD_WIDGETS ADA ============
$pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_widgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(20) NOT NULL,
    widget_key VARCHAR(50) NOT NULL,
    is_visible TINYINT(1) DEFAULT 1,
    UNIQUE KEY (role, widget_key)
)");

// ============ PROSES SIMPAN ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    // 1. Simpan menu_access
    if (isset($_POST['menu'])) {
        $pdo->query("DELETE FROM menu_access WHERE role IN ('admin','operator')");
        $roles = ['admin', 'operator'];
        foreach ($roles as $role) {
            if (!empty($_POST['menu'][$role]) && is_array($_POST['menu'][$role])) {
                foreach ($_POST['menu'][$role] as $menuKey) {
                    $stmt = $pdo->prepare("INSERT INTO menu_access (role, menu_key, is_visible) VALUES (?, ?, 1)");
                    $stmt->execute([$role, $menuKey]);
                }
            }
        }
    }

    // 2. Simpan dashboard_widgets
    if (isset($_POST['widget'])) {
        $pdo->query("DELETE FROM dashboard_widgets WHERE role IN ('admin','operator')");
        $roles = ['admin', 'operator'];
        foreach ($roles as $role) {
            if (!empty($_POST['widget'][$role]) && is_array($_POST['widget'][$role])) {
                foreach ($_POST['widget'][$role] as $widgetKey) {
                    $stmt = $pdo->prepare("INSERT INTO dashboard_widgets (role, widget_key, is_visible) VALUES (?, ?, 1)");
                    $stmt->execute([$role, $widgetKey]);
                }
            }
        }
    }

    $_SESSION['success'] = 'Pengaturan berhasil diperbarui!';
    header('Location: menu_settings.php');
    exit;
}

// ============ AMBIL DATA EXISTING ============
// Menu
$existingMenus = [];
$stmt = $pdo->query("SELECT role, menu_key FROM menu_access WHERE is_visible = 1");
while ($row = $stmt->fetch()) {
    $existingMenus[$row['role']][] = $row['menu_key'];
}

// Widget
$existingWidgets = [];
$stmt = $pdo->query("SELECT role, widget_key FROM dashboard_widgets WHERE is_visible = 1");
while ($row = $stmt->fetch()) {
    $existingWidgets[$row['role']][] = $row['widget_key'];
}

// Daftar widget yang tersedia (TAMBAHKAN recent_activities & whatsapp_chat)
$allWidgets = [
    ['key' => 'total_orders', 'label' => 'Total Pesanan', 'icon' => 'fa-box'],
    ['key' => 'total_customers', 'label' => 'Total Pelanggan', 'icon' => 'fa-users'],
    ['key' => 'today_revenue', 'label' => 'Pendapatan Hari Ini', 'icon' => 'fa-coins'],
    ['key' => 'total_revenue', 'label' => 'Total Pendapatan', 'icon' => 'fa-wallet'],
    ['key' => 'active_orders', 'label' => 'Pesanan Aktif', 'icon' => 'fa-bolt'],
    ['key' => 'unpaid_today', 'label' => 'Belum Bayar Hari Ini', 'icon' => 'fa-exclamation-triangle'],
    // ===== WIDGET BARU =====
    ['key' => 'recent_activities', 'label' => 'Aktivitas Terbaru', 'icon' => 'fa-clock'],
    ['key' => 'whatsapp_chat', 'label' => 'Chat WhatsApp Terbaru', 'icon' => 'fa-comment-dots'],
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Pengaturan - Owner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        .card-dark { background:#111; border:1px solid #1f1f1f; border-radius:12px; padding:20px; }
        .btn-gold { background:#f5a623; color:#000; font-weight:600; padding:8px 20px; border-radius:10px; transition:all 0.2s; border:none; cursor:pointer; display:inline-block; }
        .btn-gold:hover { background:#e09515; transform:translateY(-2px); }
        .btn-outline { background:transparent; border:1px solid #2a2a2a; color:#d4d4d8; padding:8px 20px; border-radius:10px; transition:all 0.2s; text-decoration:none; display:inline-block; }
        .btn-outline:hover { border-color:#f5a623; color:#f5a623; }
        .table-row { border-bottom:1px solid #1f1f1f; transition:background 0.15s; }
        .table-row:hover { background:#181818; }
        input[type="checkbox"] { accent-color: #f5a623; width:18px; height:18px; cursor:pointer; }
        .table-wrap { overflow-x:auto; }
        .tab-button { padding:8px 16px; border-radius:8px 8px 0 0; background:#1a1a1a; border:1px solid #2a2a2a; border-bottom:none; cursor:pointer; color:#9ca3af; }
        .tab-button.active { background:#111; color:#f5a623; border-color:#f5a623; }
        .tab-content { display:none; }
        .tab-content.active { display:block; }
        .section-title { font-size:18px; font-weight:600; color:#f5a623; margin-bottom:16px; }
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
            <h1 class="text-xl md:text-2xl font-bold text-white">
                <i class="fas fa-cog mr-2 text-gold"></i> Pengaturan
            </h1>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-900/30 border border-green-700 text-green-400 px-4 py-2 rounded mb-4">
                <?= $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="flex gap-1 mb-0">
            <button class="tab-button active" data-tab="menu">Atur Menu</button>
            <button class="tab-button" data-tab="dashboard">Atur Dashboard</button>
        </div>

        <form method="POST">
            <!-- TAB 1: Atur Menu -->
            <div class="card-dark tab-content active" id="tab-menu">
                <p class="text-gray-400 text-sm mb-4">Centang menu yang ingin ditampilkan untuk masing-masing role. (Owner otomatis melihat semua).</p>
                <div class="table-wrap">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[#1f1f1f] text-left text-gray-400">
                                <th class="pb-2">Menu</th>
                                <th class="pb-2 text-center">Admin</th>
                                <th class="pb-2 text-center">Operator</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allMenus as $menu): ?>
                            <tr class="border-b border-[#1a1a1a]">
                                <td class="py-2 flex items-center gap-2">
                                    <i class="fas <?= $menu['icon'] ?> text-gold w-5"></i>
                                    <?= $menu['label'] ?>
                                    <span class="text-[10px] text-gray-500">(<?= $menu['key'] ?>)</span>
                                </td>
                                <td class="py-2 text-center">
                                    <input type="checkbox" name="menu[admin][]" value="<?= $menu['key'] ?>" 
                                        <?= (isset($existingMenus['admin']) && in_array($menu['key'], $existingMenus['admin'])) ? 'checked' : '' ?>
                                        class="accent-gold">
                                </td>
                                <td class="py-2 text-center">
                                    <input type="checkbox" name="menu[operator][]" value="<?= $menu['key'] ?>" 
                                        <?= (isset($existingMenus['operator']) && in_array($menu['key'], $existingMenus['operator'])) ? 'checked' : '' ?>
                                        class="accent-gold">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: Atur Dashboard Widgets -->
            <div class="card-dark tab-content" id="tab-dashboard">
                <p class="text-gray-400 text-sm mb-4">Centang widget yang ingin ditampilkan di dashboard untuk masing-masing role. (Owner otomatis melihat semua).</p>
                <div class="table-wrap">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[#1f1f1f] text-left text-gray-400">
                                <th class="pb-2">Widget</th>
                                <th class="pb-2 text-center">Admin</th>
                                <th class="pb-2 text-center">Operator</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allWidgets as $widget): ?>
                            <tr class="border-b border-[#1a1a1a]">
                                <td class="py-2 flex items-center gap-2">
                                    <i class="fas <?= $widget['icon'] ?> text-gold w-5"></i>
                                    <?= $widget['label'] ?>
                                    <span class="text-[10px] text-gray-500">(<?= $widget['key'] ?>)</span>
                                </td>
                                <td class="py-2 text-center">
                                    <input type="checkbox" name="widget[admin][]" value="<?= $widget['key'] ?>" 
                                        <?= (isset($existingWidgets['admin']) && in_array($widget['key'], $existingWidgets['admin'])) ? 'checked' : '' ?>
                                        class="accent-gold">
                                </td>
                                <td class="py-2 text-center">
                                    <input type="checkbox" name="widget[operator][]" value="<?= $widget['key'] ?>" 
                                        <?= (isset($existingWidgets['operator']) && in_array($widget['key'], $existingWidgets['operator'])) ? 'checked' : '' ?>
                                        class="accent-gold">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <button type="submit" name="save" class="btn-gold px-6 py-2 rounded-lg">
                    <i class="fas fa-save mr-1"></i> Simpan Pengaturan
                </button>
                <a href="dashboard.php" class="btn-outline px-6 py-2 rounded-lg">Kembali ke Dashboard</a>
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

        // Tab switching
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                document.getElementById('tab-' + this.dataset.tab).classList.add('active');
            });
        });
    </script>
</body>
</html>