<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Hanya operator yang bisa akses
if ($_SESSION['role'] !== 'operator') {
    die('Akses ditolak. Halaman ini khusus untuk operator.');
}

$company = getCompanyData();

// Ambil parameter filter status
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Query pesanan
$sql = "SELECT o.*, c.name as customer_name 
        FROM orders o 
        JOIN customers c ON o.customer_id = c.id 
        WHERE 1=1";
$params = [];

if (!empty($status_filter)) {
    $sql .= " AND o.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY o.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$statuses = getStatuses();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Operator - Creative Geek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #0a0a0a; color: white; }
        .card-dark { background: #151515; border: 1px solid #2a2a2a; border-radius: 12px; }
        .text-gold { color: #F5A623; }
        .bg-gold { background: #F5A623; }
        .hover-gold:hover { background: #e09515; }
        td, th { border-color: #2a2a2a; }
        .filter-select { background: #0a0a0a; border: 1px solid #2a2a2a; color: #fff; padding: 8px 14px; border-radius: 8px; }
        .filter-select:focus { outline: none; border-color: #F5A623; }
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .status-diterima { background: #3b82f6; color: white; }
        .status-desain { background: #8b5cf6; color: white; }
        .status-cetak { background: #f59e0b; color: black; }
        .status-finishing { background: #ec4899; color: white; }
        .status-siap_ambil { background: #10b981; color: white; }
    </style>
</head>
<body>
    <nav class="bg-[#151515] border-b border-[#222] p-4 flex justify-between items-center">
        <span class="font-bold text-xl text-gold">CREATIVE GEEK</span>
        <div>
            <span class="mr-4 text-sm text-gray-300">👤 <?= $_SESSION['username'] ?> (<?= $_SESSION['role'] ?>)</span>
            <a href="logout.php" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">Logout</a>
        </div>
    </nav>

    <div class="container mx-auto p-6">
        <h1 class="text-2xl font-bold text-gold mb-6">⚙️ Dashboard Operator</h1>

        <!-- Filter Status -->
        <div class="card-dark p-4 mb-4">
            <form method="GET" class="flex flex-wrap items-center gap-3">
                <label class="text-sm text-gray-300">Filter Status:</label>
                <select name="status" class="filter-select">
                    <option value="">Semua Status</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= $s ?>" <?= $status_filter == $s ? 'selected' : '' ?>><?= getStatusLabel($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="bg-gold text-black px-4 py-2 rounded font-bold hover:bg-[#e09515] transition">Filter</button>
                <a href="dashboard_operator.php" class="text-gray-400 text-sm hover:text-gold">Reset</a>
            </form>
        </div>

        <!-- Tabel Pesanan -->
        <div class="overflow-x-auto card-dark">
            <table class="min-w-full divide-y divide-[#2a2a2a]">
                <thead class="bg-black">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">No. Pesanan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Pelanggan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Tanggal</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Total</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#2a2a2a]">
                    <?php if (count($orders) > 0): ?>
                        <?php foreach ($orders as $order): ?>
                        <tr class="hover:bg-[#1a1a1a] transition">
                            <td class="px-6 py-4 text-gold font-mono text-sm"><?= $order['order_number'] ?></td>
                            <td class="px-6 py-4"><?= htmlspecialchars($order['customer_name']) ?></td>
                            <td class="px-6 py-4 text-gray-400"><?= date('d M Y', strtotime($order['order_date'])) ?></td>
                            <td class="px-6 py-4">
                                <?php
                                $status_class = 'status-' . $order['status'];
                                ?>
                                <span class="status-badge <?= $status_class ?>"><?= getStatusLabel($order['status']) ?></span>
                            </td>
                            <td class="px-6 py-4 text-gold"><?= formatRupiah($order['grand_total']) ?></td>
                            <td class="px-6 py-4">
                                <a href="status_update.php?id=<?= $order['id'] ?>" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700 transition">Update Status</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-400">Tidak ada pesanan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-6 text-xs text-gray-500 border-t border-[#222] pt-4">
            <?= $company['address'] ?> • 🕐 <?= $company['hours'] ?> • 📞 <?= $company['phone'] ?>
        </div>
    </div>
</body>
</html>
