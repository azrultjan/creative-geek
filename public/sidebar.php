<!-- sidebar.php -->
<?php
// Pastikan session sudah dimulai
$role = $_SESSION['role'] ?? 'guest';
$username = $_SESSION['username'] ?? 'User';

// Include database dan menu config
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/menus.php';

// Fungsi untuk mendapatkan menu yang boleh tampil (tanpa paksaan dashboard)
function getVisibleMenus($role, $allMenus, $pdo) {
    if ($role === 'owner') {
        return $allMenus;
    }
    $allowedKeys = [];
    $stmt = $pdo->prepare("SELECT menu_key FROM menu_access WHERE role = ? AND is_visible = 1");
    $stmt->execute([$role]);
    while ($row = $stmt->fetch()) {
        $allowedKeys[] = $row['menu_key'];
    }
    $visible = [];
    foreach ($allMenus as $menu) {
        if (in_array($menu['key'], $allowedKeys)) {
            $visible[] = $menu;
        }
    }
    return $visible;
}

$menus = getVisibleMenus($role, $allMenus, $pdo);
?>
<div class="flex flex-col h-full px-3">
    <!-- Brand -->
    <div class="flex items-center gap-3 px-2 py-4 mb-4 border-b border-[#1f1f1f]">
        <i class="fas fa-cube text-2xl text-gold"></i>
        <span class="text-lg font-bold text-white">Creative Geek</span>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 space-y-1">
        <?php foreach ($menus as $menu): ?>
            <a href="<?= $menu['url'] ?? '#' ?>" class="sidebar-link <?= (basename($_SERVER['PHP_SELF']) == ($menu['url'] ?? '')) ? 'active' : '' ?>">
                <i class="fas <?= $menu['icon'] ?? 'fa-link' ?>"></i> <?= $menu['label'] ?? 'Menu' ?>
            </a>
        <?php endforeach; ?>
        <?php if (empty($menus)): ?>
            <div class="text-xs text-gray-600 px-4 py-2 text-center">(Tidak ada menu)</div>
        <?php endif; ?>
    </nav>

    <!-- Logout -->
    <div class="border-t border-[#1f1f1f] pt-4 mt-2">
        <a href="logout" class="sidebar-link text-red-400 hover:text-red-300 hover:bg-red-900/20">
            <i class="fas fa-right-from-bracket"></i> Logout
        </a>
        <div class="text-xs text-gray-600 px-4 py-2">
            <i class="fas fa-user-circle mr-1"></i> <?= htmlspecialchars($username) ?>
            <span class="block text-[10px] text-gray-700"><?= htmlspecialchars($role) ?></span>
        </div>
    </div>
</div>