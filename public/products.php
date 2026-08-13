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

// ===== TAMBAHKAN INI =====
if ($_SESSION['role'] !== 'owner') {
    header("Location: dashboard.php");
    exit;
}
// ========================

function safe($str) { return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8'); }

$editData = null;

// Proses simpan (tambah/edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $category = trim($_POST['category'] ?? '');
    $variant = trim($_POST['variant'] ?? '');
    $price = (int) str_replace('.', '', $_POST['price'] ?? 0);

    if ($category !== '' && $variant !== '' && $price > 0) {
        if ($id > 0) {
            $pdo->prepare("UPDATE product_prices SET category = ?, variant = ?, price = ? WHERE id = ?")
                ->execute([$category, $variant, $price, $id]);
        } else {
            $pdo->prepare("INSERT INTO product_prices (category, variant, price) VALUES (?, ?, ?)")
                ->execute([$category, $variant, $price]);
        }
        header("Location: products.php?msg=updated");
        exit;
    }
}

// Proses hapus
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM product_prices WHERE id = ?")->execute([$id]);
    header("Location: products.php?msg=deleted");
    exit;
}

// Proses edit (ambil data)
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM product_prices WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch();
}

// Ambil semua data produk
$products = $pdo->query("SELECT * FROM product_prices ORDER BY category, variant")->fetchAll();

// Group by category untuk tampilan lebih rapi
$grouped = [];
foreach ($products as $p) {
    $grouped[$p['category']][] = $p;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Manajemen Produk - Creative Geek</title>
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
        .card-dark { background:#111; border:1px solid #1f1f1f; border-radius:12px; padding:20px; }
        .btn-gold { background:#f5a623; color:#000; font-weight:600; padding:8px 20px; border-radius:10px; transition:all 0.2s; border:none; cursor:pointer; display:inline-block; }
        .btn-gold:hover { background:#e09515; transform:translateY(-2px); }
        .btn-outline { background:transparent; border:1px solid #2a2a2a; color:#d4d4d8; padding:8px 20px; border-radius:10px; transition:all 0.2s; text-decoration:none; display:inline-block; }
        .btn-outline:hover { border-color:#f5a623; color:#f5a623; }
        .btn-red { background:#dc2626; color:#fff; font-weight:600; padding:4px 12px; border-radius:6px; transition:all 0.2s; border:none; cursor:pointer; display:inline-block; }
        .btn-red:hover { background:#b91c1c; }
        .table-row { border-bottom:1px solid #1f1f1f; transition:background 0.15s; }
        .table-row:hover { background:#181818; }
        input, select { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:8px 12px; border-radius:8px; width:100%; }
        input:focus, select:focus { border-color:#f5a623; outline:none; }
        .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .table-wrap table { min-width:700px; width:100%; }
        @media (max-width:640px) { .card-dark { padding:12px; } }
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
                <h1 class="text-xl md:text-2xl font-bold text-white"><i class="fas fa-box mr-2 text-gold"></i> Manajemen Produk</h1>
            </div>
        </div>

        <!-- Form Tambah/Edit -->
        <div class="card-dark mb-6">
            <h2 class="text-lg font-semibold text-gold mb-4"><?= $editData ? '<i class="fas fa-edit mr-1"></i> Edit Produk' : '<i class="fas fa-plus mr-1"></i> Tambah Produk' ?></h2>
            <form method="POST">
                <input type="hidden" name="id" value="<?= safe($editData['id'] ?? 0) ?>">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300">Kategori</label>
                        <select name="category" required>
                            <option value="">Pilih Kategori</option>
                            <option value="spanduk" <?= ($editData && $editData['category'] == 'spanduk') ? 'selected' : '' ?>>Spanduk</option>
                            <option value="sticker" <?= ($editData && $editData['category'] == 'sticker') ? 'selected' : '' ?>>Sticker</option>
                            <option value="sablon" <?= ($editData && $editData['category'] == 'sablon') ? 'selected' : '' ?>>Sablon</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300">Varian</label>
                        <input type="text" name="variant" required placeholder="Contoh: 1x1, A4, A3" value="<?= safe($editData['variant'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-300">Harga (Rp)</label>
                        <input type="text" name="price" required class="rp-input" placeholder="45000" value="<?= $editData ? number_format($editData['price'], 0, ',', '.') : '' ?>">
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn-gold"><?= $editData ? 'Update' : 'Simpan' ?></button>
                    <?php if ($editData): ?>
                        <a href="products.php" class="btn-outline ml-2"><i class="fas fa-times mr-1"></i> Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Daftar Produk -->
        <div class="card-dark">
            <div class="table-wrap">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-[#1f1f1f]">
                            <th class="pb-2 pr-2">ID</th>
                            <th class="pb-2 pr-2">Kategori</th>
                            <th class="pb-2 pr-2">Varian</th>
                            <th class="pb-2 pr-2">Harga</th>
                            <th class="pb-2">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($products) > 0): ?>
                            <?php foreach ($products as $row): ?>
                            <tr class="table-row">
                                <td class="py-2 pr-2"><?= safe($row['id']) ?></td>
                                <td class="py-2 pr-2 capitalize"><?= safe($row['category']) ?></td>
                                <td class="py-2 pr-2"><?= safe($row['variant']) ?></td>
                                <td class="py-2 pr-2 text-gold font-semibold">Rp <?= number_format($row['price'], 0, ',', '.') ?></td>
                                <td class="py-2 whitespace-nowrap">
                                    <a href="products.php?edit=<?= safe($row['id']) ?>" class="text-gold hover:underline mr-2"><i class="fas fa-edit"></i></a>
                                    <a href="products.php?delete=<?= safe($row['id']) ?>" onclick="return confirm('Yakin hapus produk ini?')" class="text-red-500 hover:underline"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="py-4 text-center text-gray-500"><i class="fas fa-inbox mr-2"></i> Belum ada data produk.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Toast -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        // Format Rupiah
        document.querySelectorAll('.rp-input').forEach(el => {
            el.addEventListener('blur', function() {
                let val = this.value.replace(/\./g, '').replace(/\D/g, '');
                if (val === '') { this.value = ''; return; }
                this.value = new Intl.NumberFormat('id-ID').format(parseInt(val));
            });
            el.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9.]/g, '');
            });
        });

        // Toast notification
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
            showToast('Produk berhasil disimpan.', 'Berhasil');
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
            showToast('Produk berhasil dihapus.', 'Berhasil');
        <?php endif; ?>

        function showToast(message, title = 'Info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerHTML = `
                <span class="icon"><i class="fas fa-info-circle text-gold"></i></span>
                <div class="msg"><strong>${title}</strong><br>${message}</div>
                <button class="close-toast" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(toast);
            setTimeout(() => { if (toast.parentElement) toast.remove(); }, 5000);
        }

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