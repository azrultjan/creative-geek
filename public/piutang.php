<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../includes/functions.php';

$company = getCompanyData();

// Query per pelanggan: total pesanan, total piutang (belum), total lunas
$sql = "SELECT c.id, c.name,
               COUNT(o.id) as total_orders,
               SUM(CASE WHEN o.payment_status = 'belum' THEN o.grand_total ELSE 0 END) as total_piutang,
               SUM(CASE WHEN o.payment_status = 'lunas' THEN o.grand_total ELSE 0 END) as total_lunas
        FROM customers c
        JOIN orders o ON c.id = o.customer_id
        GROUP BY c.id, c.name
        ORDER BY total_piutang DESC, total_lunas DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$customers = $stmt->fetchAll();

// Total seluruh piutang & lunas
$totalPiutang = array_sum(array_column($customers, 'total_piutang'));
$totalLunas = array_sum(array_column($customers, 'total_lunas'));
$totalSemua = $totalPiutang + $totalLunas;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekapitulasi Piutang & Lunas - Creative Geek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #0a0a0a; color: white; }
        .card-dark { background: #151515; border: 1px solid #2a2a2a; border-radius: 12px; }
        .text-gold { color: #F5A623; }
        .bg-gold { background: #F5A623; }
        .hover-gold:hover { background: #e09515; }
        td, th { border-color: #2a2a2a; }
        .stat-box { background: #0d0d0d; border: 1px solid #2a2a2a; border-radius: 10px; padding: 20px; }
        .text-red-500 { color: #ef4444; }
        .text-green-500 { color: #22c55e; }
    </style>
</head>
<body>
    <nav class="bg-[#151515] border-b border-[#222] p-4 flex justify-between items-center">
        <span class="font-bold text-xl text-gold">CREATIVE GEEK</span>
        <div>
            <a href="dashboard.php" class="mr-4 text-gold hover:underline">Dashboard</a>
            <?php if (isOwner()): ?>
            <a href="finance.php" class="mr-4 text-gold hover:underline">Keuangan</a>
            <?php endif; ?>
            <a href="logout.php" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">Logout</a>
        </div>
    </nav>

    <div class="container mx-auto p-6">
        <h1 class="text-2xl font-bold text-gold mb-6">📊 Rekapitulasi Piutang & Lunas</h1>

        <!-- Statistik -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="stat-box border-red-500/30">
                <div class="text-gray-400 text-sm">❌ Total Piutang</div>
                <div class="text-2xl font-bold text-red-500">Rp <?= number_format($totalPiutang, 0, ',', '.') ?></div>
            </div>
            <div class="stat-box border-green-500/30">
                <div class="text-gray-400 text-sm">✅ Total Lunas</div>
                <div class="text-2xl font-bold text-green-500">Rp <?= number_format($totalLunas, 0, ',', '.') ?></div>
            </div>
            <div class="stat-box border-gold/30">
                <div class="text-gray-400 text-sm">💰 Grand Total</div>
                <div class="text-2xl font-bold text-gold">Rp <?= number_format($totalSemua, 0, ',', '.') ?></div>
            </div>
        </div>

        <!-- Tabel Rekap per Pelanggan -->
        <div class="card-dark overflow-x-auto">
            <table class="min-w-full divide-y divide-[#2a2a2a]">
                <thead class="bg-black">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Pelanggan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Total Pesanan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Total Piutang</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Total Lunas</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gold uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($customers) > 0): ?>
                        <?php foreach ($customers as $c): ?>
                        <tr class="hover:bg-[#1a1a1a] transition">
                            <td class="px-6 py-4"><?= htmlspecialchars($c['name']) ?></td>
                            <td class="px-6 py-4"><?= $c['total_orders'] ?></td>
                            <td class="px-6 py-4 text-red-500 font-bold">Rp <?= number_format($c['total_piutang'], 0, ',', '.') ?></td>
                            <td class="px-6 py-4 text-green-500 font-bold">Rp <?= number_format($c['total_lunas'], 0, ',', '.') ?></td>
                            <td class="px-6 py-4">
                                <a href="orders.php?search=<?= urlencode($c['name']) ?>&payment=belum" class="text-red-400 hover:underline mr-2">🔍 Piutang</a>
                                <a href="orders.php?search=<?= urlencode($c['name']) ?>&payment=lunas" class="text-green-400 hover:underline">✅ Lunas</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-gray-400">Belum ada data pelanggan.</td>
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
