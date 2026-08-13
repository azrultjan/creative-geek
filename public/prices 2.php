<?php
session_start();
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';

// Cek role, hanya owner yang boleh akses
if ($_SESSION['role'] !== 'owner') {
    header("Location: dashboard.php");
    exit;
}

$products = [];
$stmt = $pdo->query("SELECT id, category, variant, price FROM product_prices ORDER BY category, variant");
while ($row = $stmt->fetch()) {
    $products[] = $row;
}

$designFee = 15000;
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
$stmt->execute(['design_fee']);
$row = $stmt->fetch();
if ($row) $designFee = (int)$row['setting_value'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['prices']) && is_array($_POST['prices'])) {
        foreach ($_POST['prices'] as $id => $price) {
            $price = (int)str_replace('.', '', $price);
            if ($price > 0) {
                $stmt = $pdo->prepare("UPDATE product_prices SET price = ? WHERE id = ?");
                $stmt->execute([$price, $id]);
            }
        }
    }
    if (isset($_POST['design_fee'])) {
        $newDesignFee = (int)str_replace('.', '', $_POST['design_fee']);
        if ($newDesignFee >= 0) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('design_fee', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$newDesignFee, $newDesignFee]);
            $designFee = $newDesignFee;
        }
    }
    header("Location: prices.php?msg=updated");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Manajemen Harga - Creative Geek</title>
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
        .btn-gold { background:#f5a623; color:#000; font-weight:600; padding:8px 20px; border-radius:10px; transition:all 0.2s; border:none; cursor:pointer; display:inline-block; }
        .btn-gold:hover { background:#e09515; transform:translateY(-2px); box-shadow:0 4px 20px rgba(245,166,35,0.3); }
        input[type="text"] { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:6px 10px; border-radius:8px; width:150px; text-align:right; }
        input:focus { outline:none; border-color:#f5a623; }
        .price-input { font-size:14px; }
        .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .table-wrap table { min-width:600px; width:100%; }
        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-track { background:#0a0a0a; }
        ::-webkit-scrollbar-thumb { background:#2a2a2a; border-radius:10px; }
        ::-webkit-scrollbar-thumb:hover { background:#f5a623; }
        .toast-container { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; max-width:380px; width:100%; }
        .toast { background:#1a1a1a; border:1px solid #f5a623; border-radius:12px; padding:14px 18px; box-shadow:0 10px 40px rgba(0,0,0,0.5); animation:slideIn 0.5s ease, fadeOut 0.5s ease 4.5s forwards; display:flex; align-items:center; gap:12px; }
        .toast .icon { font-size:22px; }
        .toast .msg { flex:1; color:#e5e7eb; font-size:14px; }
        .toast .msg strong { color:#f5a623; }
        .toast .close-toast { background:transparent; border:none; color:#666; cursor:pointer; font-size:18px; padding:0 4px; }
        .toast .close-toast:hover { color:#fff; }
        @keyframes slideIn { from { transform:translateX(100%); opacity:0; } to { transform:translateX(0); opacity:1; } }
        @keyframes fadeOut { from { opacity:1; transform:translateX(0); } to { opacity:0; transform:translateX(50px); } }
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
                <h1 class="text-xl md:text-2xl font-bold text-gold"><i class="fas fa-money-bill-wave mr-2"></i> Manajemen Harga</h1>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
            <div class="bg-green-600 text-white p-3 rounded mb-4"><i class="fas fa-check-circle mr-1"></i> Harga berhasil diperbarui.</div>
        <?php endif; ?>

        <div class="card-dark p-4 mb-4">
            <p class="text-sm text-gray-400">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>Spanduk</strong> per m² • <strong>Sablon A3</strong> per lembar • <strong>Sticker Label</strong> per lembar • <strong>Sticker Decal</strong> per cm²
            </p>
        </div>

        <form method="POST" class="card-dark">
            <div class="table-wrap">
                <table class="w-full text-sm">
                    <thead class="bg-black">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs text-gold uppercase">Kategori</th>
                            <th class="px-4 py-3 text-left text-xs text-gold uppercase">Variant</th>
                            <th class="px-4 py-3 text-left text-xs text-gold uppercase">Harga (Rp)</th>
                            <th class="px-4 py-3 text-left text-xs text-gold uppercase">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                        <tr class="hover:bg-[#1a1a1a]">
                            <td class="px-4 py-3 capitalize"><?= htmlspecialchars($p['category'] ?? '') ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($p['variant'] ?? '') ?></td>
                            <td class="px-4 py-3">
                                <input type="text" name="prices[<?= $p['id'] ?>]" class="price-input" value="<?= number_format($p['price'], 0, ',', '.') ?>" oninput="formatPrice(this)">
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-400">
                                <?php
                                if ($p['category'] == 'spanduk') echo 'Per m²';
                                elseif ($p['category'] == 'sablon') echo 'Per lembar';
                                elseif ($p['category'] == 'sticker' && strpos($p['variant'] ?? '', 'decal') !== false) echo 'Per cm²';
                                elseif ($p['category'] == 'sticker') echo 'Per lembar';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="hover:bg-[#1a1a1a] border-t border-[#1f1f1f]">
                            <td class="px-4 py-3 font-semibold text-gold"><i class="fas fa-paint-brush mr-1"></i> Jasa Desain</td>
                            <td class="px-4 py-3">-</td>
                            <td class="px-4 py-3">
                                <input type="text" name="design_fee" class="price-input" value="<?= number_format($designFee, 0, ',', '.') ?>" oninput="formatPrice(this)">
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-400">Tambahan biaya desain per order</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn-gold"><i class="fas fa-save mr-1"></i> Simpan Semua Harga</button>
            </div>
        </form>
    </main>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        function formatPrice(input) {
            let val = input.value.replace(/\D/g, '');
            if (val === '') { input.value = ''; return; }
            input.value = new Intl.NumberFormat('id-ID').format(parseInt(val));
        }

        document.querySelector('form').addEventListener('submit', function(e) {
            document.querySelectorAll('.price-input').forEach(input => {
                input.value = input.value.replace(/\./g, '');
            });
        });

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