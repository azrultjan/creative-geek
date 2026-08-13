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

$role = $_SESSION['role'] ?? '';

if (!hasMenuAccess('customers')) {
    writeLog("Akses ditolak: role=$role mencoba customers.php", 'WARNING');
    header('Location: ' . getFirstAllowedMenuUrl());
    exit;
}

writeLog("User $role mengakses customers.php", 'INFO');

function safe($str) { return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8'); }

$editData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $name_locked = isset($_POST['name_locked']) ? 1 : 0;
    $is_regular = isset($_POST['is_regular']) ? 1 : 0; // <-- TAMBAHAN

    if ($name !== '') {
        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE customers SET name = ?, phone = ?, name_locked = ?, is_regular = ? WHERE id = ?")
                    ->execute([$name, $phone, $name_locked, $is_regular, $id]);
                writeLog("Customer ID $id diupdate oleh $role", 'INFO');
            } else {
                $pdo->prepare("INSERT INTO customers (name, phone, name_locked, is_regular) VALUES (?, ?, ?, ?)")
                    ->execute([$name, $phone, $name_locked, $is_regular]);
                writeLog("Customer baru ditambahkan oleh $role: $name", 'INFO');
            }
            header("Location: customers.php");
            exit;
        } catch (PDOException $e) {
            writeLog("Gagal simpan customer: " . $e->getMessage(), 'ERROR');
            die('Gagal menyimpan data.');
        }
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
        writeLog("Customer ID $id dihapus oleh $role", 'INFO');
        header("Location: customers.php");
        exit;
    } catch (PDOException $e) {
        writeLog("Gagal hapus customer ID $id: " . $e->getMessage(), 'ERROR');
        die('Gagal menghapus.');
    }
}

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch();
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sql = "SELECT id, name, phone, chat_id, last_reply_at, name_locked, is_regular FROM customers"; // <-- tambah is_regular
$params = [];
if (!empty($search)) {
    $sql .= " WHERE name LIKE ? OR phone LIKE ?";
    $like = "%$search%";
    $params = [$like, $like];
}
$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$backUrl = getFirstAllowedMenuUrl();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Manajemen Pelanggan - Creative Geek</title>
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
        .btn-back { background:transparent; border:1px solid #2a2a2a; color:#ccc; padding:6px 16px; border-radius:8px; font-size:14px; text-decoration:none; transition:all 0.2s; display:inline-block; }
        .btn-back:hover { background:#1a1a1a; border-color:#f5a623; color:#f5a623; }
        .table-row { border-bottom:1px solid #1f1f1f; transition:background 0.15s; }
        .table-row:hover { background:#181818; }
        input, select, textarea { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:8px 12px; border-radius:8px; width:100%; }
        input:focus { border-color:#f5a623; outline:none; }
        .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .table-wrap table { min-width:700px; width:100%; }
        @media (max-width:640px) { .card-dark { padding:12px; } }
        .checkbox-custom { width:auto; margin-right:8px; }
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
                <div class="flex items-center gap-3">
                    <a href="<?= $backUrl ?>" class="btn-back text-sm"><i class="fas fa-arrow-left mr-1"></i> Kembali</a>
                    <h1 class="text-xl md:text-2xl font-bold text-white"><i class="fas fa-users mr-2 text-gold"></i> Manajemen Pelanggan</h1>
                </div>
            </div>
        </div>

        <div class="card-dark mb-6">
            <h2 class="text-lg font-semibold text-gold mb-4"><?= $editData ? '<i class="fas fa-edit mr-1"></i> Edit Pelanggan' : '<i class="fas fa-plus mr-1"></i> Tambah Pelanggan' ?></h2>
            <form method="POST">
                <input type="hidden" name="id" value="<?= safe($editData['id'] ?? 0) ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-300">Nama *</label><input type="text" name="name" required value="<?= safe($editData['name'] ?? '') ?>"></div>
                    <div><label class="block text-sm font-medium text-gray-300">Telepon</label><input type="text" name="phone" value="<?= safe($editData['phone'] ?? '') ?>"></div>
                </div>
                <div class="mt-3 flex items-center">
                    <input type="checkbox" name="name_locked" id="name_locked" class="checkbox-custom" value="1" <?= ($editData && $editData['name_locked'] == 1) ? 'checked' : '' ?>>
                    <label for="name_locked" class="text-sm text-gray-300">Kunci nama agar tidak diubah otomatis</label>
                </div>
                <!-- ===== TAMBAHAN CHECKBOX PELANGGAN TETAP ===== -->
                <div class="mt-3 flex items-center">
                    <input type="checkbox" name="is_regular" id="is_regular" class="checkbox-custom" value="1" <?= ($editData && $editData['is_regular'] == 1) ? 'checked' : '' ?>>
                    <label for="is_regular" class="text-sm text-gray-300">Pelanggan Tetap (harga spanduk Rp 40.000/m²)</label>
                </div>
                <!-- ========================================== -->
                <div class="mt-4">
                    <button type="submit" class="btn-gold"><?= $editData ? 'Update' : 'Simpan' ?></button>
                    <?php if ($editData): ?>
                        <a href="customers.php" class="btn-outline ml-2"><i class="fas fa-times mr-1"></i> Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card-dark mb-6">
            <h2 class="text-lg font-semibold text-gold mb-4"><i class="fas fa-search mr-1"></i> Cari Pelanggan</h2>
            <form method="GET" class="flex flex-wrap gap-2">
                <input type="text" name="search" placeholder="Cari nama atau telepon..." value="<?= safe($search) ?>" class="flex-1 min-w-[200px]">
                <button type="submit" class="btn-gold"><i class="fas fa-search mr-1"></i> Cari</button>
                <?php if (!empty($search)): ?>
                    <a href="customers.php" class="btn-outline"><i class="fas fa-undo mr-1"></i> Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card-dark">
            <div class="table-wrap">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-[#1f1f1f]">
                            <th class="pb-2 pr-2">ID</th>
                            <th class="pb-2 pr-2">Nama</th>
                            <th class="pb-2 pr-2">Telepon</th>
                            <th class="pb-2 pr-2">Chat ID</th>
                            <th class="pb-2 pr-2">Last Reply</th>
                            <th class="pb-2 pr-2">Locked</th>
                            <th class="pb-2 pr-2">Status</th> <!-- TAMBAHAN KOLOM -->
                            <th class="pb-2">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($customers) > 0): ?>
                            <?php foreach ($customers as $row): ?>
                            <tr class="table-row">
                                <td class="py-2 pr-2"><?= safe($row['id']) ?></td>
                                <td class="py-2 pr-2"><?= safe($row['name']) ?></td>
                                <td class="py-2 pr-2"><?= safe($row['phone']) ?></td>
                                <td class="py-2 pr-2 text-xs" style="word-break:break-all;"><?= safe($row['chat_id']) ?></td>
                                <td class="py-2 pr-2"><?= safe($row['last_reply_at']) ?></td>
                                <td class="py-2 pr-2"><?= $row['name_locked'] ? '<i class="fas fa-lock text-gold"></i>' : '<i class="fas fa-lock-open text-gray-500"></i>' ?></td>
                                <td class="py-2 pr-2"><?= $row['is_regular'] ? '<span class="text-gold"><i class="fas fa-check-circle"></i> Tetap</span>' : 'Biasa' ?></td>
                                <td class="py-2 whitespace-nowrap">
                                    <a href="customers.php?edit=<?= safe($row['id']) ?>" class="text-gold hover:underline mr-2"><i class="fas fa-edit"></i></a>
                                    <a href="customers.php?delete=<?= safe($row['id']) ?>" onclick="return confirm('Yakin hapus?')" class="text-red-500 hover:underline"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="py-4 text-center text-gray-500"><i class="fas fa-inbox mr-2"></i> Tidak ada data ditemukan.</td></tr>
                        <?php endif; ?>
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