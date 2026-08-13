<?php
session_start();

// Cek login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/menus.php';

$role = $_SESSION['role'] ?? 'guest';

// Hanya izinkan owner, admin, operator
if (!in_array($role, ['owner', 'admin', 'operator'])) {
    header('Location: login.php');
    exit;
}

// Cek akses menu (jika bukan owner)
if ($role !== 'owner' && !hasMenuAccess('operator_dashboard')) {
    header('Location: dashboard.php');
    exit;
}

$company = getCompanyData();

// Proses update status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && isset($_POST['new_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = $_POST['new_status'];
    // Hanya 3 status yang diizinkan (sesuai order_form)
    $valid_statuses = ['diterima', 'finishing', 'siap_ambil'];
    if (in_array($new_status, $valid_statuses)) {
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $order_id]);

        // ===== KIRIM NOTIFIKASI WA KE PELANGGAN =====
        $stmt_customer = $pdo->prepare("
            SELECT o.order_number, c.phone, c.name 
            FROM orders o 
            JOIN customers c ON o.customer_id = c.id 
            WHERE o.id = ?
        ");
        $stmt_customer->execute([$order_id]);
        $orderData = $stmt_customer->fetch();

        if ($orderData && !empty($orderData['phone'])) {
            $statusLabels = [
                'diterima'   => 'Order Masuk & Desain',
                'finishing'  => 'Pencetakan & Finishing',
                'siap_ambil' => 'Selesai'
            ];
            $statusText = $statusLabels[$new_status] ?? $new_status;
            $message = "Halo " . $orderData['name'] . ".\n\n";
            $message .= "Status pesanan Anda #" . $orderData['order_number'] . " telah diperbarui menjadi: *" . $statusText . "*.\n\n";
            $message .= "Cek detail melalui e-receipt:\n";
            $message .= "https://cloud.creativegeek-idn.com/index.php?order=" . $orderData['order_number'] . "\n\n";
            $message .= "Terima kasih telah bertransaksi di Creative Geek =)";
            sendWhatsAppNotification($orderData['phone'], $message);
        }

        header('Location: operator_dashboard.php?msg=updated');
        exit;
    }
}

// Tampilkan pesanan dengan status 'diterima' dan 'finishing' (belum selesai)
$statuses_for_operator = ['diterima', 'finishing'];
$placeholders = implode(',', array_fill(0, count($statuses_for_operator), '?'));
$stmt = $pdo->prepare("
    SELECT o.*, c.name as customer_name 
    FROM orders o 
    JOIN customers c ON o.customer_id = c.id 
    WHERE o.status IN ($placeholders) 
    ORDER BY o.order_date DESC
");
$stmt->execute($statuses_for_operator);
$orders = $stmt->fetchAll();

// Status yang tersedia untuk dropdown operator (hanya 3 status)
$all_statuses = ['diterima', 'finishing', 'siap_ambil'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Update Status Pesanan - Creative Geek</title>
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
        .card-dark { background:#151515; border:1px solid #2a2a2a; border-radius:12px; }
        .btn-update { background:#f5a623; color:#000; padding:4px 12px; border-radius:6px; font-weight:bold; border:none; cursor:pointer; }
        .btn-update:hover { background:#e09515; }
        .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .table-wrap table { min-width:700px; width:100%; }
        select { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:4px 8px; border-radius:6px; }
        select:focus { outline:none; border-color:#f5a623; }
        @media (max-width:640px) { .card-dark { padding:12px; } }
        .badge-status { padding:4px 12px; border-radius:20px; font-size:12px; font-weight:bold; display:inline-block; }
        .badge-diterima { background:#3b82f6; color:#fff; }
        .badge-finishing { background:#ec4899; color:#fff; }
        .badge-siap_ambil { background:#10b981; color:#fff; }
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
                <h1 class="text-xl md:text-2xl font-bold text-gold"><i class="fas fa-sync-alt mr-2"></i> Update Status Pesanan</h1>
            </div>
            <span class="text-sm text-gray-400"><i class="fas fa-user mr-1"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
        </div>

        <p class="text-gray-400 text-sm mb-4"><i class="fas fa-info-circle mr-1"></i> Ubah status pesanan yang sedang dalam proses produksi.</p>

        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'updated'): ?>
            <div class="bg-green-900/50 text-green-300 p-3 rounded mb-4 border border-green-700"><i class="fas fa-check-circle mr-1"></i> Status berhasil diupdate! Notifikasi WA telah dikirim ke pelanggan.</div>
        <?php endif; ?>

        <div class="card-dark p-4 md:p-6">
            <div class="table-wrap">
                <table class="w-full text-sm">
                    <thead class="bg-black">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs text-gold uppercase">No. Pesanan</th>
                            <th class="px-4 py-3 text-left text-xs text-gold uppercase">Pelanggan</th>
                            <th class="px-4 py-3 text-left text-xs text-gold uppercase">Tanggal</th>
                            <th class="px-4 py-3 text-left text-xs text-gold uppercase">Status Saat Ini</th>
                            <th class="px-4 py-3 text-left text-xs text-gold uppercase">Update Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#2a2a2a]">
                        <?php if (count($orders) > 0): ?>
                            <?php foreach ($orders as $order): ?>
                            <tr class="hover:bg-[#1a1a1a] transition">
                                <td class="px-4 py-3 text-gold font-mono text-sm"><?= htmlspecialchars($order['order_number']) ?></td>
                                <td class="px-4 py-3"><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td class="px-4 py-3 text-gray-400"><?= date('d M Y', strtotime($order['order_date'])) ?></td>
                                <td class="px-4 py-3">
                                    <span class="badge-status badge-<?= $order['status'] ?>"><?= getStatusLabel($order['status']) ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" class="flex items-center gap-2 flex-wrap">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <select name="new_status" class="w-40">
                                            <?php foreach ($all_statuses as $s): ?>
                                                <option value="<?= $s ?>" <?= $s == $order['status'] ? 'selected' : '' ?>><?= getStatusLabel($s) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-update"><i class="fas fa-sync-alt mr-1"></i> Update</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-400"><i class="fas fa-inbox mr-2"></i> Tidak ada pesanan dalam proses produksi saat ini.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 text-xs text-gray-500 border-t border-[#222] pt-4">
            <?= $company['address'] ?? '' ?> • <i class="far fa-clock mr-1"></i> <?= $company['hours'] ?? '' ?> • <i class="fas fa-phone mr-1"></i> <?= $company['phone'] ?? '' ?>
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