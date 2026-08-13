<nav class="bg-[#151515] border-b border-[#222] p-4 flex justify-between items-center">
    <span class="font-bold text-xl text-gold">CREATIVE GEEK</span>
    <div>
        <a href="dashboard.php" class="mr-4 text-gold hover:underline <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'nav-active' : '' ?>">Dashboard</a>
        <a href="orders.php" class="mr-4 text-gold hover:underline <?= basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'nav-active' : '' ?>">Pesanan</a>
        <a href="customers.php" class="mr-4 text-gold hover:underline <?= basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'nav-active' : '' ?>">Pelanggan</a>
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'owner'): ?>
        <a href="prices.php" class="mr-4 text-gold hover:underline <?= basename($_SERVER['PHP_SELF']) == 'prices.php' ? 'nav-active' : '' ?>">Harga</a>
        <?php endif; ?>
        <a href="logout.php" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">Logout</a>
    </div>
</nav>
<style>
    .nav-active { 
        color: #F5A623 !important; 
        font-weight: bold; 
        border-bottom: 2px solid #F5A623; 
        padding-bottom: 2px;
    }
</style>
