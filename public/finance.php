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

$start_month = $_GET['start_month'] ?? date('Y-m', strtotime('-5 months'));
$end_month = $_GET['end_month'] ?? date('Y-m');

if ($start_month > $end_month) {
    $temp = $start_month;
    $start_month = $end_month;
    $end_month = $temp;
}

$start = $start_month . '-01';
$end = date('Y-m-t', strtotime($end_month . '-01'));

$stmt = $pdo->prepare("SELECT SUM(grand_total) as total FROM orders WHERE payment_status = 'lunas' AND order_date BETWEEN ? AND ?");
$stmt->execute([$start, $end]);
$totalRevenue = $stmt->fetch()['total'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE payment_status = 'lunas' AND order_date BETWEEN ? AND ?");
$stmt->execute([$start, $end]);
$totalOrders = $stmt->fetch()['count'] ?? 0;

$avgOrder = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(grand_total) as total FROM orders WHERE payment_status = 'belum' AND order_date BETWEEN ? AND ?");
$stmt->execute([$start, $end]);
$unpaid = $stmt->fetch();
$unpaidCount = $unpaid['count'] ?? 0;
$unpaidTotal = $unpaid['total'] ?? 0;

$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(order_date, '%Y-%m') as month,
        SUM(CASE WHEN payment_status = 'lunas' THEN grand_total ELSE 0 END) as revenue,
        COUNT(CASE WHEN payment_status = 'lunas' THEN 1 END) as orders,
        SUM(CASE WHEN payment_status = 'belum' THEN grand_total ELSE 0 END) as unpaid_revenue,
        COUNT(CASE WHEN payment_status = 'belum' THEN 1 END) as unpaid_orders
    FROM orders
    WHERE order_date BETWEEN ? AND ?
    GROUP BY month
    ORDER BY month ASC
");
$stmt->execute([$start, $end]);
$monthly = $stmt->fetchAll();

$labels = [];
$revenueData = [];
$orderData = [];
$unpaidRevenueData = [];
$unpaidOrderData = [];
foreach ($monthly as $m) {
    $labels[] = date('M Y', strtotime($m['month'] . '-01'));
    $revenueData[] = (float) $m['revenue'];
    $orderData[] = (int) $m['orders'];
    $unpaidRevenueData[] = (float) $m['unpaid_revenue'];
    $unpaidOrderData[] = (int) $m['unpaid_orders'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Laporan Keuangan - Creative Geek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .stat-box { background:#0d0d0d; border:1px solid #2a2a2a; border-radius:10px; padding:16px; display:block; text-decoration:none; color:inherit; transition:all 0.2s; }
        .stat-box:hover { border-color:#f5a623; transform:translateY(-2px); }
        .stat-box .stat-label { color:#9ca3af; font-size:14px; }
        .stat-box .stat-value { font-size:28px; font-weight:bold; }
        .chart-container { position:relative; height:380px; width:100%; animation:fadeIn 0.8s ease; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        .filter-form { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
        .text-red-500 { color:#ef4444; }
        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-track { background:#0a0a0a; }
        ::-webkit-scrollbar-thumb { background:#2a2a2a; border-radius:10px; }
        ::-webkit-scrollbar-thumb:hover { background:#f5a623; }
        .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .table-wrap table { min-width:600px; width:100%; }
        @media (max-width:640px) { .stat-box .stat-value { font-size:22px; } .card-dark { padding:12px; } }
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
                <h1 class="text-xl md:text-2xl font-bold text-gold"><i class="fas fa-chart-pie mr-2"></i> Laporan Keuangan</h1>
            </div>
        </div>

        <form method="GET" class="filter-form mb-6 p-4 card-dark">
            <label class="text-gray-300 text-sm">Dari:</label>
            <input type="month" name="start_month" value="<?= $start_month ?>" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white w-auto">
            <label class="text-gray-300 text-sm">Sampai:</label>
            <input type="month" name="end_month" value="<?= $end_month ?>" class="bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white w-auto">
            <button type="submit" class="btn-gold"><i class="fas fa-filter mr-1"></i> Filter</button>
            <a href="finance.php" class="text-gray-400 text-sm hover:text-gold"><i class="fas fa-undo mr-1"></i> Reset</a>
        </form>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <a href="orders.php?payment=lunas" class="stat-box border-gold/30">
                <div class="stat-label"><i class="fas fa-check-circle text-green-400 mr-1"></i> Pendapatan (Lunas)</div>
                <div class="stat-value text-gold">Rp <?= number_format($totalRevenue, 0, ',', '.') ?></div>
                <div class="text-[10px] text-gold opacity-0 hover:opacity-100 transition">Klik untuk lihat detail</div>
            </a>
            <a href="orders.php?payment=lunas" class="stat-box">
                <div class="stat-label"><i class="fas fa-clipboard-list text-blue-400 mr-1"></i> Pesanan Lunas</div>
                <div class="stat-value text-white"><?= $totalOrders ?></div>
            </a>
            <div class="stat-box" style="cursor:default;">
                <div class="stat-label"><i class="fas fa-calculator text-yellow-400 mr-1"></i> Rata-rata per Pesanan</div>
                <div class="stat-value text-white">Rp <?= number_format($avgOrder, 0, ',', '.') ?></div>
            </div>
            <a href="orders.php?payment=belum" class="stat-box border-red-500/30">
                <div class="stat-label"><i class="fas fa-times-circle text-red-400 mr-1"></i> Pesanan Belum Lunas</div>
                <div class="stat-value text-red-500"><?= $unpaidCount ?></div>
            </a>
            <a href="orders.php?payment=belum" class="stat-box border-red-500/30">
                <div class="stat-label"><i class="fas fa-coins text-red-400 mr-1"></i> Total Piutang</div>
                <div class="stat-value text-red-500">Rp <?= number_format($unpaidTotal, 0, ',', '.') ?></div>
            </a>
        </div>

        <?php if (count($monthly) > 0): ?>
        <div class="card-dark p-4 mb-6">
            <h3 class="text-sm font-semibold text-gold mb-2"><i class="fas fa-chart-line mr-1"></i> Tren Keuangan Per Bulan</h3>
            <div class="chart-container">
                <canvas id="mainChart"></canvas>
            </div>
        </div>
        <?php else: ?>
        <div class="card-dark p-6 text-center text-gray-400"><i class="fas fa-inbox mr-2"></i> Belum ada data untuk periode yang dipilih.</div>
        <?php endif; ?>

        <div class="card-dark p-4 md:p-6">
            <div class="table-wrap">
                <table class="w-full text-sm">
                    <thead class="bg-[#0a0a0a]">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs text-gold uppercase">Bulan</th>
                            <th class="px-6 py-3 text-left text-xs text-gold uppercase">Lunas (Order)</th>
                            <th class="px-6 py-3 text-left text-xs text-gold uppercase">Lunas (Rp)</th>
                            <th class="px-6 py-3 text-left text-xs text-gold uppercase">Belum Lunas (Order)</th>
                            <th class="px-6 py-3 text-left text-xs text-gold uppercase">Belum Lunas (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($monthly) > 0): ?>
                            <?php foreach ($monthly as $m): ?>
                            <tr class="hover:bg-[#1a1a1a]">
                                <td class="px-6 py-4"><?= date('M Y', strtotime($m['month'] . '-01')) ?></td>
                                <td class="px-6 py-4"><?= $m['orders'] ?></td>
                                <td class="px-6 py-4 text-gold">Rp <?= number_format($m['revenue'], 0, ',', '.') ?></td>
                                <td class="px-6 py-4 text-red-500"><?= $m['unpaid_orders'] ?></td>
                                <td class="px-6 py-4 text-red-500">Rp <?= number_format($m['unpaid_revenue'], 0, ',', '.') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="px-6 py-4 text-center text-gray-400">Belum ada data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <?php if (count($monthly) > 0): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const labels = <?= json_encode($labels) ?>;
            const revenueData = <?= json_encode($revenueData) ?>;
            const orderData = <?= json_encode($orderData) ?>;
            const unpaidRevenueData = <?= json_encode($unpaidRevenueData) ?>;
            const unpaidOrderData = <?= json_encode($unpaidOrderData) ?>;

            const ctx = document.getElementById('mainChart').getContext('2d');
            const goldGradient = ctx.createLinearGradient(0, 0, 0, 400);
            goldGradient.addColorStop(0, 'rgba(255, 215, 0, 0.8)');
            goldGradient.addColorStop(1, 'rgba(255, 165, 0, 0.1)');

            const redGradient = ctx.createLinearGradient(0, 0, 0, 400);
            redGradient.addColorStop(0, 'rgba(255, 69, 0, 0.8)');
            redGradient.addColorStop(1, 'rgba(255, 0, 0, 0.1)');

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Pendapatan Lunas (Rp)',
                            data: revenueData,
                            type: 'line',
                            borderColor: '#FFD700',
                            backgroundColor: goldGradient,
                            borderWidth: 4,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#FFD700',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 3,
                            pointRadius: 6,
                            yAxisID: 'y',
                            order: 1,
                        },
                        {
                            label: 'Total Piutang (Rp)',
                            data: unpaidRevenueData,
                            type: 'line',
                            borderColor: '#FF4500',
                            backgroundColor: redGradient,
                            borderWidth: 3,
                            borderDash: [6, 4],
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#FF4500',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            yAxisID: 'y',
                            order: 2,
                        },
                        {
                            label: 'Jumlah Pesanan Lunas',
                            data: orderData,
                            type: 'bar',
                            backgroundColor: 'rgba(0, 191, 255, 0.6)',
                            borderColor: '#00BFFF',
                            borderWidth: 2,
                            borderRadius: 6,
                            yAxisID: 'y1',
                            order: 3,
                        },
                        {
                            label: 'Jumlah Pesanan Belum Lunas',
                            data: unpaidOrderData,
                            type: 'bar',
                            backgroundColor: 'rgba(255, 105, 180, 0.6)',
                            borderColor: '#FF69B4',
                            borderWidth: 2,
                            borderRadius: 6,
                            yAxisID: 'y1',
                            order: 4,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 1500, easing: 'easeInOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            labels: { color: '#ccc', font: { size: 12, weight: 'bold' }, boxWidth: 18, padding: 16 }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(20,20,20,0.95)',
                            borderColor: '#444',
                            borderWidth: 1,
                            titleColor: '#fff',
                            bodyColor: '#ddd',
                            cornerRadius: 10,
                            padding: 12,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    let val = context.parsed.y;
                                    if (label.includes('Rp')) {
                                        return label + ': Rp ' + val.toLocaleString('id-ID');
                                    }
                                    return label + ': ' + val + ' pesanan';
                                }
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { color: '#888', font: { size: 11, weight: 'bold' } }, grid: { color: 'rgba(255,255,255,0.03)' } },
                        y: { position: 'left', beginAtZero: true, ticks: { color: '#888', font: { size: 10 }, callback: function(value) { return 'Rp ' + value.toLocaleString('id-ID'); } }, grid: { color: 'rgba(255,255,255,0.05)' } },
                        y1: { position: 'right', beginAtZero: true, ticks: { color: '#888', font: { size: 10 }, stepSize: 1 }, grid: { drawOnChartArea: false } }
                    }
                }
            });
        });
    </script>
    <?php endif; ?>

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