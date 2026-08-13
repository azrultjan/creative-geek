<?php
// Ambil role dari session
$role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? 'User';
// Tentukan halaman aktif berdasarkan nama file
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar fixed top-0 left-0 h-full w-[250px] flex flex-col p-4 z-50" style="background: #0d0d0d; border-right: 1px solid #1f1f1f;">
    <div class="flex items-center gap-3 mb-8 px-2">
        <span class="text-2xl font-bold text-gold" style="color: #f5a623;">CG</span>
        <span class="text-white font-bold text-lg tracking-tight">Creative Geek</span>
    </div>
    <nav class="flex-1 space-y-1">
        <a href="dashboard.php" class="sidebar-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
            <span>📊</span> Dashboard
        </a>
        <a href="orders.php" class="sidebar-link <?= $current_page == 'orders.php' ? 'active' : '' ?>">
            <span>📋</span> Pesanan
        </a>
        <a href="customers.php" class="sidebar-link <?= $current_page == 'customers.php' ? 'active' : '' ?>">
            <span>👤</span> Pelanggan
        </a>
        <?php if ($role === 'owner'): ?>
        <a href="prices.php" class="sidebar-link <?= $current_page == 'prices.php' ? 'active' : '' ?>">
            <span>💰</span> Harga
        </a>
        <?php endif; ?>
        <a href="logout.php" class="sidebar-link mt-8 text-red-400 hover:text-red-300">
            <span>🚪</span> Logout
        </a>
    </nav>
    <div class="border-t border-[#1f1f1f] pt-4 mt-4 text-xs text-gray-500">
        <div>👋 <?= htmlspecialchars($username) ?></div>
        <div class="mt-1 gold" style="color: #f5a623;"><?= date('d F Y H:i') ?> WITA</div>
    </div>
</aside>

<style>
    .sidebar-link { 
        display: flex; 
        align-items: center; 
        gap: 12px; 
        padding: 10px 16px; 
        border-radius: 10px; 
        color: #9ca3af; 
        transition: all 0.2s; 
        text-decoration: none;
    }
    .sidebar-link:hover { background: #1a1a1a; color: #f5a623; }
    .sidebar-link.active { background: #1a1a1a; color: #f5a623; font-weight: 600; border-right: 2px solid #f5a623; }
    .gold { color: #f5a623; }
</style>
