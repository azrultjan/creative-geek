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

if ($role !== 'owner' && !hasMenuAccess('dashboard')) {
    header('Location: ' . getFirstAllowedMenuUrl());
    exit;
}

// ===== KONFIGURASI WHATSAPP API (sama dengan whatsapp.php) =====
$waSessionId = 'dd00d7f2-69cf-4160-aac1-df2ae16e5ec2';
$waApiKey = 'owa_k1_9adc820273a8ada22e94d24f4073d69d34cbab82932d63cdb6eb7dc703ad914c';

// ===== FUNGSI CEK STATUS SISTEM =====
function checkDatabase() {
    global $pdo;
    try {
        $pdo->query("SELECT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function checkWhatsApp($sessionId, $apiKey) {
    $url = 'http://host.docker.internal:2785/api/sessions/' . $sessionId;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return false;
    }
    
    $data = json_decode($response, true);
    if (!$data) return false;
    
    $status = $data['status'] ?? '';
    return in_array($status, ['ready', 'connected']);
}

function checkDomain() {
    $host = $_SERVER['HTTP_HOST'];
    if (in_array($host, ['localhost', '127.0.0.1', '::1'])) return true;
    $ip = gethostbyname($host);
    if ($ip === $host) return false;
    $port = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 443 : 80;
    $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    if ($fp) { fclose($fp); return true; }
    return false;
}

$dbStatus    = checkDatabase();
$waStatus    = checkWhatsApp($waSessionId, $waApiKey);
$domainStatus = checkDomain();

// ===== STATISTIK =====
$stmt = $pdo->query("SELECT COUNT(*) as total FROM orders");
$totalOrders = $stmt->fetch()['total'] ?? 0;

$stmt = $pdo->query("SELECT COUNT(*) as total FROM customers");
$totalCustomers = $stmt->fetch()['total'] ?? 0;

$stmt = $pdo->query("SELECT SUM(grand_total) as today FROM orders WHERE payment_status = 'lunas' AND DATE(order_date) = CURDATE()");
$todayRevenue = $stmt->fetch()['today'] ?? 0;
if ($todayRevenue == 0) {
    $stmt = $pdo->query("SELECT SUM(grand_total) as today FROM orders WHERE payment_status = 'lunas' AND DATE(order_date) = DATE(NOW())");
    $todayRevenue = $stmt->fetch()['today'] ?? 0;
}

$stmt = $pdo->query("SELECT SUM(grand_total) as total FROM orders WHERE payment_status = 'lunas'");
$totalRevenue = $stmt->fetch()['total'] ?? 0;

$stmt = $pdo->query("SELECT COUNT(*) as active FROM orders WHERE status NOT IN ('siap_ambil', 'batal')");
$activeOrders = $stmt->fetch()['active'] ?? 0;

$stmt = $pdo->query("SELECT COUNT(*) as count, SUM(grand_total) as total FROM orders WHERE payment_status = 'belum' AND DATE(order_date) = CURDATE()");
$unpaidToday = $stmt->fetch();
$unpaidTodayCount = $unpaidToday['count'] ?? 0;
$unpaidTodayTotal = $unpaidToday['total'] ?? 0;

// ===== DATA NOTIFIKASI =====
$activeOrdersCount = 0;
$unpaidOrders = [];

// Pesanan aktif (belum selesai)
$stmt = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE status NOT IN ('siap_ambil', 'batal')");
$activeOrdersCount = $stmt->fetch()['count'] ?? 0;

// Pesanan belum bayar dengan selisih hari
$stmt = $pdo->query("
    SELECT id, order_number, customer_id, grand_total, order_date, 
           DATEDIFF(NOW(), order_date) as days_diff
    FROM orders 
    WHERE payment_status = 'belum' 
    ORDER BY order_date ASC
");
$unpaidOrders = $stmt->fetchAll();

// ===== DATA GRAFIK =====
$months = [];
$monthFull = [];
$revenues = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $label = date('M', strtotime("-$i months"));
    $months[] = $label;
    $monthFull[] = $month;
    $stmt = $pdo->prepare("SELECT SUM(grand_total) as total FROM orders WHERE payment_status = 'lunas' AND DATE_FORMAT(order_date, '%Y-%m') = ?");
    $stmt->execute([$month]);
    $row = $stmt->fetch();
    $revenues[] = (float)($row['total'] ?? 0);
}

// ===== WIDGET YANG DIIZINKAN =====
$allowedWidgets = [];
if ($role === 'owner') {
    $allowedWidgets = ['total_orders', 'total_customers', 'today_revenue', 'total_revenue', 'active_orders', 'unpaid_today', 'recent_activities', 'whatsapp_chat', 'order_list'];
} else {
    $stmt = $pdo->prepare("SELECT widget_key FROM dashboard_widgets WHERE role = ? AND is_visible = 1");
    $stmt->execute([$role]);
    while ($row = $stmt->fetch()) {
        $allowedWidgets[] = $row['widget_key'];
    }
}

// ===== ORDER LIST =====
$orderStatusFilter = $_GET['order_status'] ?? '';
$orderList = [];
if (in_array('order_list', $allowedWidgets)) {
    try {
        $sql = "SELECT o.*, c.name as customer_name 
                FROM orders o 
                LEFT JOIN customers c ON o.customer_id = c.id 
                WHERE 1=1";
        $params = [];
        if (!empty($orderStatusFilter)) {
            $sql .= " AND o.status = ?";
            $params[] = $orderStatusFilter;
        }
        $sql .= " ORDER BY o.id DESC LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orderList = $stmt->fetchAll();
        if ($orderList === false) $orderList = [];
    } catch (PDOException $e) {
        $orderList = [];
    }
}

// ===== ACTIVITY TIMELINE =====
$activities = [];
$stmt = $pdo->query("
    SELECT 
        'order' as type,
        o.id,
        o.order_number,
        o.status,
        o.grand_total,
        c.name as customer_name,
        o.order_date as event_time
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    ORDER BY o.order_date DESC
    LIMIT 20
");
while ($row = $stmt->fetch()) {
    $activities[] = $row;
}

$stmt = $pdo->query("
    SELECT 
        'payment' as type,
        o.id,
        o.order_number,
        o.payment_status,
        o.grand_total,
        c.name as customer_name,
        o.order_date as event_time
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.payment_status = 'lunas'
    ORDER BY o.order_date DESC
    LIMIT 20
");
while ($row = $stmt->fetch()) {
    $activities[] = $row;
}

$stmt = $pdo->query("
    SELECT 
        'customer' as type,
        c.id,
        c.name as customer_name,
        COALESCE(c.created_at, c.last_reply_at) as event_time
    FROM customers c
    WHERE c.created_at IS NOT NULL OR c.last_reply_at IS NOT NULL
    ORDER BY c.id DESC
    LIMIT 20
");
while ($row = $stmt->fetch()) {
    $activities[] = $row;
}

usort($activities, function($a, $b) {
    $ta = strtotime($a['event_time'] ?? 'now');
    $tb = strtotime($b['event_time'] ?? 'now');
    return $tb - $ta;
});
$activities = array_slice($activities, 0, 15);

// ===== TIMELINE BELUM BAYAR =====
$unpaidActivities = [];
$stmt = $pdo->query("
    SELECT 
        o.id,
        o.order_number,
        o.grand_total,
        c.name as customer_name,
        o.order_date as event_time
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.payment_status = 'belum'
    ORDER BY o.order_date DESC
    LIMIT 10
");
while ($row = $stmt->fetch()) {
    $unpaidActivities[] = $row;
}

$statuses = ['diterima', 'desain', 'cetak', 'finishing', 'siap_ambil', 'batal'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Dashboard - Creative Geek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #0a0a0a; color: #e5e7eb; }
        .sidebar { background: #0d0d0d; border-right: 1px solid #1f1f1f; position:fixed; top:0; left:0; height:100%; width:250px; z-index:50; transform:translateX(-100%); transition:transform 0.3s ease; overflow-y:auto; padding:20px 0; }
        .sidebar.open { transform:translateX(0); }
        .sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:40; }
        .sidebar-overlay.active { display:block; }
        .sidebar-link { display:flex; align-items:center; gap:14px; padding:10px 20px; border-radius:10px; color:#9ca3af; transition:all 0.2s; text-decoration:none; margin:2px 12px; font-size:14px; }
        .sidebar-link i { width:20px; font-size:16px; text-align:center; }
        .sidebar-link:hover { background:#1a1a1a; color:#f5a623; }
        .sidebar-link.active { background:#1a1a1a; color:#f5a623; font-weight:600; border-right:2px solid #f5a623; }

        .stat-card {
            background: #111111;
            border: 1px solid #1f1f1f;
            border-radius: 16px;
            padding: 16px;
            transition: all 0.3s;
            display: block;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }
        .stat-card:hover {
            border-color: #f5a623;
            transform: translateY(-4px);
            box-shadow: 0 10px 40px rgba(245, 166, 35, 0.08);
        }
        .stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-icon.orange { background: rgba(245, 166, 35, 0.15); color: #f5a623; }
        .stat-icon.blue { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
        .stat-icon.green { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
        .stat-icon.purple { background: rgba(168, 85, 247, 0.15); color: #a78bfa; }
        .stat-icon.red { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .stat-icon.gold { background: rgba(245, 166, 35, 0.15); color: #f5a623; }
        .stat-number { font-size: 24px; font-weight: 700; letter-spacing: -0.5px; }
        .stat-label { color: #9ca3af; font-size: 13px; margin-top: 2px; }
        .stat-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .text-red-500 { color: #ef4444; }

        .card-dark { background: #111111; border: 1px solid #1f1f1f; border-radius: 16px; padding: 16px; }
        .btn-gold { background: #f5a623; color: #000; font-weight: 600; padding: 8px 16px; border-radius: 10px; transition: all 0.2s; text-align: center; display: inline-block; }
        .btn-gold:hover { background: #e09515; transform: translateY(-2px); box-shadow: 0 4px 20px rgba(245, 166, 35, 0.3); }
        .btn-outline { background: transparent; border: 1px solid #2a2a2a; color: #d4d4d8; padding: 8px 16px; border-radius: 10px; transition: all 0.2s; text-align: center; display: inline-block; }
        .btn-outline:hover { border-color: #f5a623; color: #f5a623; }

        .side-timeline-section {
            display: flex;
            align-items: flex-start;
            gap: 18px;
            padding: 12px 0;
            border-bottom: 1px solid #1a1a1a;
            transition: background 0.2s;
            padding-left: 4px;
        }
        .side-timeline-section:last-child { border-bottom: none; }
        .side-timeline-section:hover { background: #141414; border-radius: 8px; }
        .side-timeline-icon {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 18px;
            color: #fff;
            position: relative;
            margin-top: 2px;
        }
        .side-timeline-icon::after {
            content: '';
            position: absolute;
            left: 50%;
            bottom: -18px;
            width: 2px;
            height: 16px;
            background: #2a2a2a;
            transform: translateX(-50%);
        }
        .side-timeline-section:last-child .side-timeline-icon::after { display: none; }
        .timeline-content { flex:1; min-width:0; padding-top:2px; }
        .timeline-content h6 { font-weight:600; font-size:15px; margin-bottom:2px; color:#e5e7eb; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .timeline-content .meta { font-size:13px; color:#9ca3af; display:flex; flex-wrap:wrap; align-items:center; gap:4px 12px; }
        .timeline-content .meta .time { color:#6b7280; font-size:12px; }
        .timeline-content .meta .badge-timeline { font-size:10px; padding:2px 10px; border-radius:20px; font-weight:500; }
        .timeline-content .meta .link-detail { color:#f5a623; font-size:12px; text-decoration:none; }
        .timeline-content .meta .link-detail:hover { text-decoration:underline; }
        .side-timeline-icon.bg-gold { background:rgba(245,166,35,0.2); color:#f5a623; }
        .side-timeline-icon.bg-green { background:rgba(74,222,128,0.15); color:#4ade80; }
        .side-timeline-icon.bg-blue { background:rgba(96,165,250,0.15); color:#60a5fa; }
        .side-timeline-icon.bg-red { background:rgba(239,68,68,0.15); color:#ef4444; }
        .scrollbar-thin::-webkit-scrollbar { width:4px; }
        .scrollbar-thin::-webkit-scrollbar-track { background:#1a1a1a; border-radius:10px; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background:#f5a623; border-radius:10px; }

        .unpaid-item {
            display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid #1a1a1a;
        }
        .unpaid-item:last-child { border-bottom:none; }
        .unpaid-item .dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .unpaid-item .info { flex:1; min-width:0; }
        .unpaid-item .info .order-number { font-weight:600; font-size:14px; color:#e5e7eb; }
        .unpaid-item .info .customer { font-size:13px; color:#9ca3af; }
        .unpaid-item .info .amount { font-size:13px; color:#ef4444; font-weight:500; }
        .unpaid-item .info .time { font-size:11px; color:#6b7280; }
        .unpaid-item .info .detail-link { font-size:11px; color:#f5a623; text-decoration:none; }
        .unpaid-item .info .detail-link:hover { text-decoration:underline; }

        .stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        @media (min-width: 640px) { .stat-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; } }
        @media (min-width: 1024px) { .stat-grid { grid-template-columns: repeat(5, 1fr); gap: 16px; } }

        .quick-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .quick-actions a, .quick-actions button { flex: 1 1 auto; min-width: 120px; }
        @media (min-width: 640px) { .quick-actions a, .quick-actions button { flex: 1; } }

        .header-title h1 { font-size: 1.25rem; }
        @media (min-width: 640px) { .header-title h1 { font-size: 1.5rem; } }
        #clock { font-size: 13px; }
        @media (min-width: 640px) { #clock { font-size: 14px; } }

        /* --- Indikator status kecil di header --- */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            padding: 4px 12px;
            border-radius: 20px;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            color: #d4d4d8;
            white-space: nowrap;
            transition: border-color 0.2s;
        }
        .status-indicator:hover {
            border-color: #f5a623;
        }
        .status-indicator .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-indicator .dot.green { background: #4ade80; }
        .status-indicator .dot.red { background: #ef4444; }
        .status-indicator .label {
            font-weight: 500;
            font-size: 11px;
        }
        a.status-indicator {
            text-decoration: none;
        }
        a.status-indicator:hover {
            border-color: #25D366;
            background: #1a2a1a;
        }
        @media (max-width: 640px) {
            .status-indicator { font-size: 9px; padding: 2px 8px; gap: 4px; }
            .status-indicator .dot { width: 6px; height: 6px; }
        }

        .chat-widget { max-height: 260px; overflow-y: auto; }
        .chat-item { padding: 8px 12px; border-bottom: 1px solid #1f1f1f; cursor: pointer; transition: background 0.2s; }
        .chat-item:hover { background: #1a1a1a; }
        .chat-item .unread-badge { background: #f5a623; color: #000; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 10px; margin-left: 8px; }

        /* ===== TOAST DI KANAN BAWAH (UKURAN KECIL) ===== */
        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-width: 320px;
            width: 100%;
            pointer-events: none; /* biar klik tembus ke bawah kecuali toast-nya sendiri */
        }
        .toast {
            background: #1a1a1a;
            border: 1px solid #f5a623;
            border-radius: 8px;
            padding: 10px 14px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.6);
            animation: slideInRight 0.4s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            pointer-events: auto;
            transition: opacity 0.3s ease, transform 0.3s ease;
            cursor: default;
            font-size: 13px;
        }
        .toast .icon {
            font-size: 18px;
            flex-shrink: 0;
        }
        .toast .msg {
            flex: 1;
            color: #e5e7eb;
            font-size: 13px;
            line-height: 1.4;
        }
        .toast .msg strong {
            color: #f5a623;
            font-weight: 600;
        }
        .toast .close-toast {
            background: transparent;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 16px;
            padding: 0 4px;
            flex-shrink: 0;
        }
        .toast .close-toast:hover { color: #fff; }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #2a2a2a; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #f5a623; }
        .border-gold { border-color: #f5a623; }
        .bg-gold\/10 { background: rgba(245, 166, 35, 0.1); }
        .gold { color: #f5a623; }
        .text-gold { color: #f5a623; }

        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-block; }
        .status-diterima { background: #3b82f6; color: white; }
        .status-desain { background: #8b5cf6; color: white; }
        .status-cetak { background: #f59e0b; color: black; }
        .status-finishing { background: #ec4899; color: white; }
        .status-siap_ambil { background: #10b981; color: white; }
        .status-batal { background: #6b7280; color: white; }
        .filter-select { background: #0a0a0a; border: 1px solid #2a2a2a; color: #fff; padding: 6px 12px; border-radius: 8px; font-size: 13px; }
        .filter-select:focus { outline: none; border-color: #F5A623; }
        .apexcharts-bar-area { cursor: pointer; }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <?php include __DIR__ . '/sidebar.php'; ?>
    </aside>

    <main class="main-content p-4 md:p-6" id="mainContent">
        <!-- HEADER dengan indikator status berlabel dan link WhatsApp -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div class="flex items-center gap-3 header-title">
                <button class="hamburger-btn text-gray-400 hover:text-gold text-2xl" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-white">Dashboard</h1>
                    <p class="text-gray-400 text-xs md:text-sm">Selamat datang, <?= htmlspecialchars($_SESSION['username'] ?? 'admin') ?> 👋</p>
                </div>
            </div>
            <div class="flex items-center gap-2 md:gap-3 flex-wrap">
                <!-- Indikator Database (tanpa link) -->
                <span class="status-indicator" title="Database">
                    <span class="dot <?= $dbStatus ? 'green' : 'red' ?>"></span>
                    <span class="label"><?= $dbStatus ? 'Database Terhubung' : 'Database Gagal' ?></span>
                </span>

                <!-- Indikator WhatsApp (dengan link ke OpenWA) -->
                <a href="http://localhost:2785/" target="_blank" class="status-indicator" title="WhatsApp">
                    <span class="dot <?= $waStatus ? 'green' : 'red' ?>"></span>
                    <span class="label"><?= $waStatus ? 'WhatsApp Terhubung' : 'WhatsApp Terputus' ?></span>
                </a>

                <!-- Indikator Domain (tanpa link) -->
                <span class="status-indicator" title="Domain">
                    <span class="dot <?= $domainStatus ? 'green' : 'red' ?>"></span>
                    <span class="label"><?= $domainStatus ? 'Domain Terhubung' : 'Domain Gagal' ?></span>
                </span>

                <!-- Jam -->
                <span class="text-xs md:text-sm text-gray-400" id="clock"><i class="far fa-clock mr-1"></i> <?= date('H:i:s') ?></span>
                <!-- Tombol Refresh -->
                <button class="btn-outline text-xs md:text-sm px-3 py-1.5" onclick="window.location.reload()"><i class="fas fa-sync-alt mr-1"></i> Refresh</button>
            </div>
        </div>

        <!-- STAT CARDS -->
        <?php if (empty($allowedWidgets) && $role !== 'owner'): ?>
            <div class="card-dark p-4 mb-6 text-center text-gray-400">
                <i class="fas fa-info-circle mr-2"></i> Belum ada widget yang diizinkan untuk role Anda. Hubungi owner untuk pengaturan.
            </div>
        <?php endif; ?>
        <div class="stat-grid mb-6">
            <?php if (in_array('total_orders', $allowedWidgets)): ?>
            <a href="orders.php" class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon orange"><i class="fas fa-box"></i></div>
                    <div><div class="stat-number text-gold"><?= $totalOrders ?></div><div class="stat-label">Total Pesanan</div></div>
                </div>
            </a>
            <?php endif; ?>
            <?php if (in_array('total_customers', $allowedWidgets)): ?>
            <a href="customers.php" class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                    <div><div class="stat-number text-blue-400"><?= $totalCustomers ?></div><div class="stat-label">Total Pelanggan</div></div>
                </div>
            </a>
            <?php endif; ?>
            <?php if (in_array('today_revenue', $allowedWidgets)): ?>
            <a href="orders.php?payment=lunas&date=today" class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon green"><i class="fas fa-coins"></i></div>
                    <div><div class="stat-number text-green-400">Rp<?= number_format($todayRevenue, 0, ',', '.') ?></div><div class="stat-label">Pendapatan Hari Ini</div><div class="stat-sub"><?= date('d M Y') ?></div></div>
                </div>
            </a>
            <?php endif; ?>
            <?php if (in_array('total_revenue', $allowedWidgets) && $role === 'owner'): ?>
            <a href="orders.php?payment=lunas" class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon gold"><i class="fas fa-wallet"></i></div>
                    <div><div class="stat-number text-gold">Rp<?= number_format($totalRevenue, 0, ',', '.') ?></div><div class="stat-label">Total Pendapatan</div><div class="stat-sub">(Semua waktu)</div></div>
                </div>
            </a>
            <?php endif; ?>
            <?php if (in_array('active_orders', $allowedWidgets)): ?>
            <a href="orders.php?status=active" class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon purple"><i class="fas fa-bolt"></i></div>
                    <div><div class="stat-number text-purple-400"><?= $activeOrders ?></div><div class="stat-label">Pesanan Aktif</div><div class="stat-sub">(Belum selesai)</div></div>
                </div>
            </a>
            <?php endif; ?>
            <?php if (in_array('unpaid_today', $allowedWidgets)): ?>
            <a href="orders.php?payment=belum&date=today" class="stat-card">
                <div class="flex items-center gap-3">
                    <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
                    <div><div class="stat-number text-red-500"><?= $unpaidTodayCount ?></div><div class="stat-label">Belum Bayar Hari Ini</div><div class="stat-sub">Rp <?= number_format($unpaidTodayTotal, 0, ',', '.') ?></div></div>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <!-- CHART + QUICK ACTIONS + WHATSAPP CHAT -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 md:gap-6 mb-6">
            <?php if ($role === 'owner'): ?>
                <div class="lg:col-span-2 card-dark">
                    <h3 class="text-sm font-semibold text-gray-300 mb-4"><i class="fas fa-chart-line mr-2"></i> Pendapatan 6 Bulan Terakhir <span class="text-xs text-gray-500 font-normal">(klik batang untuk detail)</span></h3>
                    <div id="revenueChart" style="height: 240px;"></div>
                </div>
            <?php elseif (in_array('whatsapp_chat', $allowedWidgets)): ?>
                <div class="lg:col-span-2 card-dark">
                    <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
                        <h3 class="text-sm font-semibold text-gray-300"><i class="fab fa-whatsapp mr-2 text-green-400"></i> Chat WhatsApp Terbaru <span id="chatBadge" class="bg-red-500 text-white text-xs px-2 py-0.5 rounded-full ml-2" style="display:none;">0</span></h3>
                        <a href="whatsapp.php" class="text-xs text-gold hover:underline" onclick="markWhatsAppOpened()">Lihat semua <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                    <div class="chat-widget" id="chatWidget"><div class="text-center text-gray-500 py-4 text-sm"><i class="fas fa-spinner fa-pulse mr-2"></i> Memuat chat...</div></div>
                </div>
            <?php endif; ?>

            <div class="card-dark flex flex-col gap-3">
                <h3 class="text-sm font-semibold text-gray-300"><i class="fas fa-bolt mr-2 text-gold"></i> Aksi Cepat</h3>
                <div class="quick-actions">
                    <?php if (hasMenuAccess('order_form') || $role === 'owner'): ?>
                        <a href="order_form.php" class="btn-gold text-sm w-full"><i class="fas fa-plus mr-1"></i> Tambah Pesanan</a>
                    <?php endif; ?>
                    <?php if (hasMenuAccess('orders') || $role === 'owner'): ?>
                        <a href="orders.php" class="btn-outline text-sm w-full"><i class="fas fa-list mr-1"></i> Lihat Pesanan</a>
                    <?php endif; ?>
                    <?php if (hasMenuAccess('customers') || $role === 'owner'): ?>
                        <a href="customers.php" class="btn-outline text-sm w-full"><i class="fas fa-user-friends mr-1"></i> Kelola Pelanggan</a>
                    <?php endif; ?>
                    <?php if ($role === 'owner'): ?>
                        <a href="users.php" class="btn-outline text-sm w-full"><i class="fas fa-user-cog mr-1"></i> Kelola User</a>
                        <a href="finance.php" class="btn-outline text-sm w-full"><i class="fas fa-wallet mr-1"></i> Keuangan</a>
                    <?php else: ?>
                        <?php if (hasMenuAccess('whatsapp') || $role === 'owner'): ?>
                            <a href="whatsapp.php" class="btn-outline text-sm w-full" onclick="markWhatsAppOpened()"><i class="fab fa-whatsapp mr-1"></i> Buka WhatsApp</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($role === 'owner'): ?>
                        <!-- === TOMBOL TEST NOTIFIKASI (DEBUG) - HANYA UNTUK OWNER === -->
                        <button onclick="showRandomNotification()" class="btn-outline text-sm w-full" style="border-color:#f5a623; color:#f5a623;">
                            <i class="fas fa-bell mr-1"></i> Test Notifikasi
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TIMELINE + BELUM BAYAR -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 md:gap-6">
            <?php if (in_array('recent_activities', $allowedWidgets)): ?>
            <div class="lg:col-span-2 card-dark p-4">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">
                    <i class="fas fa-clock mr-2 text-gold"></i> Aktivitas Terbaru
                </h3>
                <div class="max-h-[420px] overflow-y-auto pr-2 scrollbar-thin space-y-0">
                    <?php if (count($activities) > 0): ?>
                        <?php foreach ($activities as $act): 
                            $displayTime = date('d M Y, H:i', strtotime($act['event_time']));
                            $isToday = date('Y-m-d') == date('Y-m-d', strtotime($act['event_time']));
                            $timeLabel = $isToday ? 'Hari ini, ' . date('H:i', strtotime($act['event_time'])) : $displayTime;

                            if ($act['type'] == 'payment') {
                                $icon = 'fa-credit-card'; $bgClass = 'bg-green'; $title = 'Pembayaran Lunas'; $badgeText = 'Lunas'; $badgeColor = 'bg-green-900/40 text-green-300';
                                $detail = 'Rp ' . number_format($act['grand_total'], 0, ',', '.'); $link = 'order_form.php?id=' . $act['id'];
                            } elseif ($act['type'] == 'customer') {
                                $icon = 'fa-user-plus'; $bgClass = 'bg-blue'; $title = 'Pelanggan Baru'; $badgeText = 'Baru'; $badgeColor = 'bg-blue-900/40 text-blue-300';
                                $detail = $act['customer_name']; $link = 'customers.php';
                            } else {
                                $icon = 'fa-box'; $bgClass = 'bg-gold'; $statusMap = ['diterima'=>'Order Masuk','desain'=>'Proses Desain','cetak'=>'Proses Cetak','finishing'=>'Finishing','siap_ambil'=>'Siap Ambil'];
                                $title = $statusMap[$act['status']] ?? $act['status']; $badgeText = $title; $badgeColor = 'bg-gold/20 text-gold';
                                $detail = $act['customer_name'] . ' - Rp ' . number_format($act['grand_total'], 0, ',', '.'); $link = 'order_form.php?id=' . $act['id'];
                            }
                        ?>
                            <div class="side-timeline-section">
                                <div class="side-timeline-icon <?= $bgClass ?>"><i class="fas <?= $icon ?>"></i></div>
                                <div class="timeline-content">
                                    <h6><?= htmlspecialchars($title) ?></h6>
                                    <div class="meta">
                                        <span><?= htmlspecialchars($detail) ?></span>
                                        <span class="badge-timeline <?= $badgeColor ?>"><?= $badgeText ?></span>
                                        <span class="time"><i class="far fa-clock mr-1"></i><?= $timeLabel ?></span>
                                        <?php if ($act['type'] != 'customer'): ?>
                                            <a href="<?= $link ?>" class="link-detail"><i class="fas fa-external-link-alt text-xs mr-1"></i>Detail</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-gray-500 py-4 text-sm"><i class="fas fa-inbox mr-2"></i> Belum ada aktivitas.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card-dark p-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-gray-300">
                        <i class="fas fa-exclamation-triangle mr-2 text-red-400"></i> Belum Bayar
                    </h3>
                    <?php if (count($unpaidActivities) > 0): ?>
                        <span class="text-xs bg-red-500/20 text-red-400 px-2 py-0.5 rounded-full"><?= count($unpaidActivities) ?></span>
                    <?php endif; ?>
                </div>
                <div class="max-h-[420px] overflow-y-auto pr-1 space-y-0">
                    <?php if (count($unpaidActivities) > 0): ?>
                        <?php foreach ($unpaidActivities as $item): 
                            $time = date('d M Y, H:i', strtotime($item['event_time']));
                        ?>
                            <div class="unpaid-item">
                                <div class="dot bg-red-500"></div>
                                <div class="info">
                                    <div class="order-number"><?= htmlspecialchars($item['order_number']) ?></div>
                                    <div class="customer"><?= htmlspecialchars($item['customer_name'] ?? 'Unknown') ?></div>
                                    <div class="flex items-center gap-2 flex-wrap mt-0.5">
                                        <span class="amount">Rp <?= number_format($item['grand_total'], 0, ',', '.') ?></span>
                                        <span class="time"><i class="far fa-clock mr-1"></i><?= $time ?></span>
                                        <a href="order_form.php?id=<?= $item['id'] ?>" class="detail-link">Detail</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-gray-500 py-4 text-sm">
                            <i class="fas fa-check-circle mr-2 text-green-400"></i> Tidak ada pesanan belum bayar.
                        </div>
                    <?php endif; ?>
                    <div class="mt-2 pt-2 border-t border-[#1f1f1f] flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs text-gray-400">
                            Hari ini: 
                            <span class="text-red-400 font-semibold"><?= $unpaidTodayCount ?></span> pesanan 
                            (total <span class="text-red-400">Rp <?= number_format($unpaidTodayTotal, 0, ',', '.') ?></span>)
                        </span>
                        <a href="orders.php?payment=belum" class="text-xs text-gold hover:underline whitespace-nowrap">
                            Lihat semua belum bayar <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ORDER LIST -->
        <?php if (in_array('order_list', $allowedWidgets)): ?>
        <div class="card-dark p-4 mt-6">
            <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                <h3 class="text-sm font-semibold text-gray-300">
                    <i class="fas fa-list mr-2 text-gold"></i> Daftar Pesanan Terbaru
                </h3>
                <a href="orders.php" class="text-xs text-gold hover:underline">Lihat semua <i class="fas fa-arrow-right ml-1"></i></a>
            </div>
            <form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
                <label class="text-sm text-gray-300">Filter Status:</label>
                <select name="order_status" class="filter-select">
                    <option value="">Semua Status</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= $s ?>" <?= $orderStatusFilter == $s ? 'selected' : '' ?>><?= getStatusLabel($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="bg-gold text-black px-4 py-1.5 rounded font-bold text-sm hover:bg-[#e09515] transition">Filter</button>
                <?php if (!empty($orderStatusFilter)): ?>
                    <a href="?<?= http_build_query(array_diff_key($_GET, ['order_status'=>''])) ?>" class="text-gray-400 text-sm hover:text-gold">Reset</a>
                <?php endif; ?>
            </form>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-[#2a2a2a] text-sm">
                    <thead class="bg-black">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gold uppercase">No. Pesanan</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gold uppercase">Pelanggan</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gold uppercase">Tanggal</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gold uppercase">Status</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gold uppercase">Total</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gold uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#2a2a2a]">
                        <?php if (!empty($orderList)): ?>
                            <?php foreach ($orderList as $order): ?>
                            <tr class="hover:bg-[#1a1a1a] transition">
                                <td class="px-4 py-3 text-gold font-mono"><?= htmlspecialchars($order['order_number']) ?></td>
                                <td class="px-4 py-3"><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td class="px-4 py-3 text-gray-400"><?= date('d M Y', strtotime($order['order_date'])) ?></td>
                                <td class="px-4 py-3">
                                    <span class="status-badge status-<?= $order['status'] ?>"><?= getStatusLabel($order['status']) ?></span>
                                </td>
                                <td class="px-4 py-3 text-gold"><?= number_format($order['grand_total'], 0, ',', '.') ?></td>
                                <td class="px-4 py-3">
                                    <a href="order_form.php?id=<?= $order['id'] ?>" class="text-blue-400 hover:text-blue-300 text-sm">Detail</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-gray-400">Tidak ada pesanan.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </main>

    <div class="toast-container" id="toastContainer"></div>

    <!-- SCRIPTS -->
    <?php if ($role !== 'owner' && in_array('whatsapp_chat', $allowedWidgets)): ?>
    <script>
        const SEEN_KEY = 'seenChatMessages';
        function getSeenMessages() {
            try { return JSON.parse(sessionStorage.getItem(SEEN_KEY) || '{}'); } catch (e) { return {}; }
        }
        function setSeenMessages(seen) {
            sessionStorage.setItem(SEEN_KEY, JSON.stringify(seen));
        }
        // Fungsi showToast untuk chat (akan di-override oleh yang baru)
        function showToast(message, title = '💬 Pesan Baru') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerHTML = `
                <span class="icon"><i class="fas fa-comment-dots text-gold"></i></span>
                <div class="msg"><strong>${title}</strong><br>${message}</div>
                <button class="close-toast" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(toast);
            setTimeout(() => { if (toast.parentElement) toast.remove(); }, 5000);
        }
        function markWhatsAppOpened() {
            sessionStorage.setItem('navigatingToWhatsApp', 'true');
            window.location.href = 'whatsapp.php';
        }
        async function loadChatWidget() {
            try {
                const res = await fetch('whatsapp_ajax.php?action=get_chats&limit=5&_=' + Date.now());
                const data = await res.json();
                const container = document.getElementById('chatWidget');
                const badge = document.getElementById('chatBadge');
                if (data.success && data.data && data.data.length > 0) {
                    const seen = getSeenMessages();
                    let totalUnread = 0;
                    const navigating = sessionStorage.getItem('navigatingToWhatsApp');
                    if (navigating === 'true') {
                        data.data.forEach(chat => { if (chat.lastMessage && chat.lastMessage.id) seen[chat.id] = chat.lastMessage.id; });
                        setSeenMessages(seen);
                        sessionStorage.removeItem('navigatingToWhatsApp');
                        totalUnread = 0;
                    } else {
                        data.data.forEach(chat => {
                            if (!chat.lastMessage || !chat.lastMessage.id) return;
                            const msgId = chat.lastMessage.id;
                            const seenId = seen[chat.id];
                            if (!seenId || seenId !== msgId) totalUnread++;
                        });
                        if (totalUnread > 0) {
                            data.data.forEach(chat => {
                                if (!chat.lastMessage) return;
                                const msgId = chat.lastMessage.id;
                                const seenId = seen[chat.id];
                                if (!seenId || seenId !== msgId) {
                                    const name = chat.name || chat.pushName || chat.id || 'Unknown';
                                    const lastMsg = chat.lastMessage?.text || 'Pesan baru';
                                    showToast(`Dari ${name}: ${lastMsg}`, 'Chat Baru');
                                }
                            });
                            try {
                                const audio = new Audio('data:audio/wav;base64,UklGRlQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoAAACBhYqLh4eGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoY=');
                                audio.play().catch(() => {});
                            } catch(e) {}
                        }
                    }
                    if (totalUnread > 0) {
                        badge.style.display = 'inline';
                        badge.textContent = totalUnread;
                    } else {
                        badge.style.display = 'none';
                    }
                    let html = '';
                    data.data.slice(0, 5).forEach(chat => {
                        const name = chat.name || chat.pushName || chat.id || 'Unknown';
                        const lastMsg = chat.lastMessage?.text || 'Tidak ada pesan';
                        const msgId = chat.lastMessage?.id || '';
                        const seenId = seen[chat.id];
                        const isNew = msgId && (!seenId || seenId !== msgId);
                        html += `<div class="chat-item" onclick="markWhatsAppOpened()">
                            <div class="flex justify-between">
                                <span class="font-semibold text-white text-sm"><i class="fas fa-user-circle mr-2 text-gray-500"></i>${name}</span>
                                ${isNew ? `<span class="unread-badge"><i class="fas fa-circle text-[8px]"></i></span>` : ''}
                            </div>
                            <div class="text-xs text-gray-400 truncate">${lastMsg}</div>
                        </div>`;
                    });
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<div class="text-center text-gray-500 py-4 text-sm"><i class="fas fa-inbox mr-2"></i> Belum ada chat</div>';
                    badge.style.display = 'none';
                }
            } catch (e) {
                console.error('❌ Load chat error:', e);
                document.getElementById('chatWidget').innerHTML = '<div class="text-center text-gray-500 py-4 text-sm"><i class="fas fa-exclamation-triangle mr-2"></i> Gagal memuat chat</div>';
            }
        }
        loadChatWidget();
        setInterval(loadChatWidget, 10000);
        document.addEventListener('visibilitychange', function() { if (!document.hidden) loadChatWidget(); });
        window.addEventListener('pageshow', function(event) { if (event.persisted) { sessionStorage.removeItem('navigatingToWhatsApp'); loadChatWidget(); } });
        if ('Notification' in window && Notification.permission === 'default') { Notification.requestPermission(); }
    </script>
    <?php endif; ?>

    <?php if ($role === 'owner'): ?>
    <script>
        const months = <?= json_encode($months) ?>;
        const monthFull = <?= json_encode($monthFull) ?>;
        const revenues = <?= json_encode($revenues) ?>;

        const options = {
            series: [{ name: 'Pendapatan', data: revenues }],
            chart: {
                type: 'bar',
                height: 240,
                toolbar: { show: false },
                background: 'transparent',
                events: {
                    dataPointSelection: function(event, chartContext, config) {
                        const month = monthFull[config.dataPointIndex];
                        if (month) {
                            window.location.href = 'orders.php?month=' + month;
                        }
                    }
                }
            },
            plotOptions: {
                bar: { borderRadius: 6, columnWidth: '50%' }
            },
            dataLabels: { enabled: false },
            colors: ['#f5a623'],
            grid: { borderColor: '#1f1f1f', strokeDashArray: 4 },
            xaxis: {
                categories: months,
                labels: { style: { colors: '#9ca3af', fontSize: '11px' } }
            },
            yaxis: {
                labels: {
                    style: { colors: '#9ca3af', fontSize: '11px' },
                    formatter: v => 'Rp'+v.toLocaleString('id-ID')
                }
            },
            tooltip: {
                y: { formatter: v => 'Rp'+v.toLocaleString('id-ID') }
            }
        };

        new ApexCharts(document.querySelector("#revenueChart"), options).render();
    </script>
    <?php endif; ?>

    <!-- ===== SKRIP NOTIFIKASI PINTAR (30 MENIT, KANAN BAWAH, UKURAN KECIL) ===== -->
    <script>
        // Data notifikasi dari server
        const notificationData = {
            activeOrders: <?= $activeOrdersCount ?>,
            unpaid: <?= json_encode($unpaidOrders) ?>
        };

        // Fungsi utama untuk menampilkan toast dengan hover pause
        function showToast(message, title = 'Info', borderColor = '#f5a623', icon = 'fa-bell', duration = 5000) {
            const container = document.getElementById('toastContainer');
            if (!container) return;

            // Hapus toast yang sudah ada (agar tidak menumpuk)
            const existingToasts = container.querySelectorAll('.toast');
            existingToasts.forEach(el => el.remove());

            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.style.borderColor = borderColor;
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';

            toast.innerHTML = `
                <span class="icon" style="color:${borderColor};"><i class="fas ${icon}"></i></span>
                <div class="msg"><strong>${title}</strong><br>${message}</div>
                <button class="close-toast" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;

            container.appendChild(toast);

            // Timer untuk auto-hide
            let hideTimeout = setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateX(20px)';
                    setTimeout(() => { if (toast.parentElement) toast.remove(); }, 300);
                }
            }, duration);

            // Hover = pause (clear timeout)
            toast.addEventListener('mouseenter', () => {
                clearTimeout(hideTimeout);
            });

            // Leave = resume (set timeout lagi)
            toast.addEventListener('mouseleave', () => {
                hideTimeout = setTimeout(() => {
                    if (toast.parentElement) {
                        toast.style.opacity = '0';
                        toast.style.transform = 'translateX(20px)';
                        setTimeout(() => { if (toast.parentElement) toast.remove(); }, 300);
                    }
                }, duration);
            });

            // Tombol close langsung hapus
            toast.querySelector('.close-toast').addEventListener('click', () => {
                clearTimeout(hideTimeout);
                if (toast.parentElement) toast.remove();
            });
        }

        // Fungsi untuk memilih notifikasi secara acak (hanya satu)
        function showRandomNotification() {
            const messages = [];

            // 1. Notifikasi pesanan aktif (belum selesai) - warna kuning
            if (notificationData.activeOrders > 0) {
                const count = notificationData.activeOrders;
                const msg = `${count} pesanan masih dalam pengerjaan. Segera update status!`;
                messages.push({
                    message: msg,
                    title: '⚠️ Pekerjaan Tertunda',
                    borderColor: '#fbbf24',
                    icon: 'fa-clock'
                });
            }

            // 2. Notifikasi belum bayar per kategori
            const unpaid = notificationData.unpaid;
            unpaid.forEach(order => {
                const days = parseInt(order.days_diff) || 0;
                let color, statusText;
                if (days > 7) {
                    color = '#ef4444';
                    statusText = '> 1 minggu';
                } else if (days > 3) {
                    color = '#fbbf24';
                    statusText = '> 3 hari';
                } else if (days > 1) {
                    color = '#4ade80';
                    statusText = '> 1 hari';
                } else {
                    return;
                }
                const customerName = order.customer_name || 'Pelanggan';
                const orderNum = order.order_number;
                const total = Number(order.grand_total).toLocaleString('id-ID');
                const msg = `${orderNum} (${customerName}) belum bayar (${statusText}). Total Rp ${total}. Follow up!`;
                messages.push({
                    message: msg,
                    title: '💸 Tagihan Belum Bayar',
                    borderColor: color,
                    icon: 'fa-exclamation-circle'
                });
            });

            if (messages.length === 0) {
                showToast('Semua aman! Tidak ada pekerjaan tertunda atau tagihan overdue.', '✅ Semua Terkendali', '#4ade80', 'fa-check-circle', 4000);
                return;
            }

            const randomIndex = Math.floor(Math.random() * messages.length);
            const selected = messages[randomIndex];
            showToast(selected.message, selected.title, selected.borderColor, selected.icon, 5000);
        }

        // Tampilkan pertama kali setelah 5 detik, kemudian setiap 30 menit
        setTimeout(() => {
            showRandomNotification();
        }, 5000);

        setInterval(() => {
            showRandomNotification();
        }, 30 * 60 * 1000);

        // Ekspos fungsi ke global agar bisa dipanggil dari tombol Test
        window.showRandomNotification = showRandomNotification;
    </script>

    <script>
        function updateClock() {
            document.getElementById('clock').innerHTML = '<i class="far fa-clock mr-1"></i> ' + new Date().toTimeString().slice(0,8);
        }
        setInterval(updateClock, 1000);

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
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
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            }
        });
    </script>
</body>
</html>