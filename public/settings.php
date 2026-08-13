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

// Cek role, hanya owner yang boleh akses
if ($_SESSION['role'] !== 'owner') {
    header("Location: dashboard.php");
    exit;
}

// Ambil semua setting dari database
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Default values jika belum ada di database
$defaults = [
    'company_name' => 'Creative Geek',
    'address' => 'Jl. Contoh No. 123, Kota',
    'phone' => '08123456789',
    'hours' => '08:00 - 17:00 WITA',
    'design_fee' => '15000',
    'whatsapp_number' => '6281246284684',
    'footer_text' => '© 2026 Creative Geek - All rights reserved'
];

// Gabungkan dengan default
foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Proses update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowedKeys = array_keys($defaults);
    foreach ($allowedKeys as $key) {
        if (isset($_POST[$key])) {
            $value = trim($_POST[$key]);
            // Khusus design_fee, bersihkan format rupiah
            if ($key === 'design_fee') {
                $value = (int)str_replace('.', '', $value);
            }
            // Update atau insert
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
            $settings[$key] = $value;
        }
    }
    header("Location: settings.php?msg=updated");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Pengaturan - Creative Geek</title>
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
        .btn-outline { background:transparent; border:1px solid #2a2a2a; color:#d4d4d8; padding:8px 20px; border-radius:10px; transition:all 0.2s; text-decoration:none; display:inline-block; }
        .btn-outline:hover { border-color:#f5a623; color:#f5a623; }
        input, textarea { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:8px 12px; border-radius:8px; width:100%; }
        input:focus, textarea:focus { outline:none; border-color:#f5a623; }
        .form-label { display:block; font-size:14px; font-weight:500; color:#9ca3af; margin-bottom:4px; }
        .toast-container { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; max-width:380px; width:100%; }
        .toast { background:#1a1a1a; border:1px solid #f5a623; border-radius:12px; padding:14px 18px; box-shadow:0 10px 40px rgba(0,0,0,0.5); animation:slideIn 0.5s ease, fadeOut 0.5s ease 4.5s forwards; display:flex; align-items:center; gap:12px; }
        .toast .icon { font-size:22px; }
        .toast .msg { flex:1; color:#e5e7eb; font-size:14px; }
        .toast .msg strong { color:#f5a623; }
        .toast .close-toast { background:transparent; border:none; color:#666; cursor:pointer; font-size:18px; padding:0 4px; }
        .toast .close-toast:hover { color:#fff; }
        @keyframes slideIn { from { transform:translateX(100%); opacity:0; } to { transform:translateX(0); opacity:1; } }
        @keyframes fadeOut { from { opacity:1; transform:translateX(0); } to { opacity:0; transform:translateX(50px); } }
        @media (max-width:640px) { .card-dark { padding:12px; } }
        .rp-input { text-align:right; }
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
                <h1 class="text-xl md:text-2xl font-bold text-gold"><i class="fas fa-cog mr-2"></i> Pengaturan</h1>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
            <div class="bg-green-600 text-white p-3 rounded mb-4"><i class="fas fa-check-circle mr-1"></i> Pengaturan berhasil disimpan.</div>
        <?php endif; ?>

        <form method="POST" class="card-dark">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label"><i class="fas fa-building mr-1"></i> Nama Perusahaan</label>
                    <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name']) ?>">
                </div>
                <div>
                    <label class="form-label"><i class="fas fa-phone mr-1"></i> Nomor Telepon</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($settings['phone']) ?>">
                </div>
                <div class="md:col-span-2">
                    <label class="form-label"><i class="fas fa-map-marker-alt mr-1"></i> Alamat</label>
                    <textarea name="address" rows="2"><?= htmlspecialchars($settings['address']) ?></textarea>
                </div>
                <div>
                    <label class="form-label"><i class="far fa-clock mr-1"></i> Jam Operasional</label>
                    <input type="text" name="hours" value="<?= htmlspecialchars($settings['hours']) ?>" placeholder="Contoh: 08:00 - 17:00 WITA">
                </div>
                <div>
                    <label class="form-label"><i class="fab fa-whatsapp mr-1"></i> Nomor WhatsApp</label>
                    <input type="text" name="whatsapp_number" value="<?= htmlspecialchars($settings['whatsapp_number']) ?>" placeholder="62812xxxx">
                </div>
                <div>
                    <label class="form-label"><i class="fas fa-paint-brush mr-1"></i> Biaya Desain (Rp)</label>
                    <input type="text" name="design_fee" class="rp-input" value="<?= number_format((int)$settings['design_fee'], 0, ',', '.') ?>" oninput="formatPrice(this)">
                </div>
                <div>
                    <label class="form-label"><i class="fas fa-copyright mr-1"></i> Footer Text</label>
                    <input type="text" name="footer_text" value="<?= htmlspecialchars($settings['footer_text']) ?>">
                </div>
            </div>

            <div class="mt-4 flex gap-3">
                <button type="submit" class="btn-gold"><i class="fas fa-save mr-1"></i> Simpan Pengaturan</button>
                <a href="dashboard.php" class="btn-outline"><i class="fas fa-times mr-1"></i> Batal</a>
            </div>
        </form>

        <div class="mt-4 text-xs text-gray-500">
            <i class="fas fa-info-circle mr-1"></i> Pengaturan akan digunakan di seluruh sistem (dashboard, nota, dll.)
        </div>
    </main>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        function formatPrice(input) {
            let val = input.value.replace(/\D/g, '');
            if (val === '') { input.value = ''; return; }
            input.value = new Intl.NumberFormat('id-ID').format(parseInt(val));
        }

        document.querySelector('form').addEventListener('submit', function(e) {
            document.querySelectorAll('.rp-input').forEach(input => {
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