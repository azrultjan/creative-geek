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

// ===== CEK AKSES MENU ORDERS =====
if (!hasMenuAccess('orders')) {
    writeLog("Akses ditolak: role=$role mencoba akses orders.php", 'WARNING');
    header('Location: ' . getFirstAllowedMenuUrl());
    exit;
}

writeLog("User $role mengakses orders.php", 'INFO');

// ===== PROSES HAPUS =====
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
        writeLog("Pesanan ID $id dihapus oleh $role", 'INFO');
        header('Location: orders.php?msg=deleted');
        exit;
    } catch (PDOException $e) {
        writeLog("Gagal hapus pesanan ID $id: " . $e->getMessage(), 'ERROR');
        die('Gagal menghapus pesanan.');
    }
}

// ===== PROSES BATCH UPDATE STATUS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_update'])) {
    if (!hasMenuAccess('order_form') && $role !== 'owner') {
        die('Akses ditolak.');
    }

    $ids = isset($_POST['order_ids']) ? array_filter($_POST['order_ids'], 'is_numeric') : [];
    $new_status = $_POST['new_status'] ?? '';

    $allowed_status = ['diterima', 'finishing', 'siap_ambil'];
    if (!in_array($new_status, $allowed_status)) {
        header('Location: orders.php?msg=error');
        exit;
    }
    if (empty($ids)) {
        header('Location: orders.php?msg=no_select');
        exit;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($new_status === 'siap_ambil') {
            $sql = "UPDATE orders 
                    SET status = ?, 
                        progress = 100, 
                        remaining_time = '00:00:00', 
                        print_status = 'Done', 
                        updated_at = NOW(), 
                        updated_by = ? 
                    WHERE id IN ($placeholders)";
            $params = array_merge([$new_status, $_SESSION['user_id']], $ids);
        } else {
            $sql = "UPDATE orders 
                    SET status = ?, 
                        updated_at = NOW(), 
                        updated_by = ? 
                    WHERE id IN ($placeholders)";
            $params = array_merge([$new_status, $_SESSION['user_id']], $ids);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();
        writeLog("Batch update status ke '$new_status' untuk $count pesanan oleh $role", 'INFO');
        header("Location: orders.php?msg=batch_updated&count=$count");
    } catch (PDOException $e) {
        writeLog("Batch update gagal: " . $e->getMessage(), 'ERROR');
        header('Location: orders.php?msg=error');
    }
    exit;
}

// ===== PROSES QUICK ACTION (single status update) =====
if (isset($_GET['quick_status']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $status = $_GET['quick_status'];
    $allowed = ['siap_ambil', 'batal'];
    if (in_array($status, $allowed) && (hasMenuAccess('order_form') || $role === 'owner')) {
        try {
            if ($status === 'siap_ambil') {
                $sql = "UPDATE orders 
                        SET status = ?, 
                            progress = 100, 
                            remaining_time = '00:00:00', 
                            print_status = 'Done', 
                            updated_at = NOW(), 
                            updated_by = ? 
                        WHERE id = ?";
                $params = [$status, $_SESSION['user_id'], $id];
            } else {
                $sql = "UPDATE orders SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?";
                $params = [$status, $_SESSION['user_id'], $id];
            }
            $pdo->prepare($sql)->execute($params);
            writeLog("Quick update status ID $id ke $status oleh $role", 'INFO');
            header('Location: orders.php?msg=quick_updated');
        } catch (PDOException $e) {
            writeLog("Quick update gagal: " . $e->getMessage(), 'ERROR');
            header('Location: orders.php?msg=error');
        }
    } else {
        header('Location: orders.php');
    }
    exit;
}

// ===== FILTER =====
$search = $_GET['search'] ?? '';
$product_search = $_GET['product'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$payment = $_GET['payment'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_preset = $_GET['date_preset'] ?? '';

if ($date_preset) {
    switch ($date_preset) {
        case 'today':
            $date_from = date('Y-m-d');
            $date_to = date('Y-m-d');
            break;
        case 'week':
            $date_from = date('Y-m-d', strtotime('-7 days'));
            $date_to = date('Y-m-d');
            break;
        case 'month':
            $date_from = date('Y-m-01');
            $date_to = date('Y-m-t');
            break;
    }
}

$where = "1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR o.order_number LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}

if (!empty($product_search)) {
    $where .= " AND o.id IN (
        SELECT DISTINCT oi.order_id 
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE p.name LIKE ?
    )";
    $params[] = "%$product_search%";
}

if (!empty($date_from)) {
    $where .= " AND DATE(o.order_date) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where .= " AND DATE(o.order_date) <= ?";
    $params[] = $date_to;
}

if (!empty($payment) && in_array($payment, ['lunas', 'belum'])) {
    $where .= " AND o.payment_status = ?";
    $params[] = $payment;
}

if (!empty($status_filter) && in_array($status_filter, ['diterima', 'finishing', 'siap_ambil'])) {
    $where .= " AND o.status = ?";
    $params[] = $status_filter;
}

// ===== PAGINATION =====
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$sql = "SELECT 
            o.*, 
            c.name as customer_name,
            u1.username as created_by_name,
            u2.username as updated_by_name
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u1 ON o.created_by = u1.id
        LEFT JOIN users u2 ON o.updated_by = u2.id
        WHERE $where 
        ORDER BY o.order_date DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== STATISTIK =====
$stats = [
    'today_orders' => 0,
    'today_revenue' => 0,
    'unpaid' => 0,
    'ready' => 0,
];
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date) = CURDATE() AND status != 'batal'");
    $stats['today_orders'] = (int)$stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE DATE(order_date) = CURDATE() AND payment_status = 'lunas' AND status != 'batal'");
    $stats['today_revenue'] = (float)$stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'belum' AND status != 'batal'");
    $stats['unpaid'] = (int)$stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'siap_ambil' AND status != 'batal'");
    $stats['ready'] = (int)$stmt->fetchColumn();
} catch (PDOException $e) { /* ignore */ }

// ===== BUILD EXPORT URL =====
$export_params = array_filter([
    'search' => $search,
    'product' => $product_search,
    'date_from' => $date_from,
    'date_to' => $date_to,
    'payment' => $payment,
    'status' => $status_filter,
    'date_preset' => $date_preset
]);
$export_url = "export_orders_excel.php?" . http_build_query($export_params);

$backUrl = getFirstAllowedMenuUrl();

// ===== FILTER MESSAGE =====
$filterParts = [];
if (!empty($payment)) {
    $filterParts[] = 'Status Bayar: <strong>' . ($payment === 'lunas' ? 'Lunas' : 'Belum Lunas') . '</strong>';
}
if (!empty($status_filter)) {
    $map = ['diterima'=>'Order Masuk & Desain', 'finishing'=>'Pencetakan & Finishing', 'siap_ambil'=>'Selesai'];
    $filterParts[] = 'Status: <strong>' . ($map[$status_filter] ?? $status_filter) . '</strong>';
}
if (!empty($date_preset)) {
    $presetLabel = ['today'=>'Hari Ini', 'week'=>'7 Hari Terakhir', 'month'=>'Bulan Ini'];
    $filterParts[] = 'Tanggal: <strong>' . ($presetLabel[$date_preset] ?? $date_preset) . '</strong>';
}
if (!empty($search)) {
    $filterParts[] = 'Pencarian: <strong>' . htmlspecialchars($search) . '</strong>';
}
if (!empty($product_search)) {
    $filterParts[] = 'Produk: <strong>' . htmlspecialchars($product_search) . '</strong>';
}
if (!empty($date_from) && !empty($date_to) && empty($date_preset)) {
    $filterParts[] = 'Tanggal: <strong>' . htmlspecialchars($date_from) . ' s/d ' . htmlspecialchars($date_to) . '</strong>';
} elseif (!empty($date_from) && empty($date_preset)) {
    $filterParts[] = 'Tanggal: <strong>dari ' . htmlspecialchars($date_from) . '</strong>';
} elseif (!empty($date_to) && empty($date_preset)) {
    $filterParts[] = 'Tanggal: <strong>sampai ' . htmlspecialchars($date_to) . '</strong>';
}
$filterMessage = implode(' | ', $filterParts);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Daftar Pesanan - Creative Geek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ===== SAMA PERSIS SEPERTI SEBELUMNYA ===== */
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
        .card-dark { background:#111; border:1px solid #1f1f1f; border-radius:12px; }
        .badge { padding:4px 12px; border-radius:20px; font-size:12px; font-weight:500; display:inline-block; }
        .badge-green { background:#1a3a2a; color:#4ade80; }
        .badge-yellow { background:#3a2a1a; color:#fbbf24; }
        .badge-red { background:#3a1a1a; color:#f87171; }
        .badge-gray { background:#1a1a1a; color:#9ca3af; }
        .btn-back { background:transparent; border:1px solid #2a2a2a; color:#ccc; padding:6px 16px; border-radius:8px; font-size:14px; text-decoration:none; transition:all 0.2s; display:inline-block; }
        .btn-back:hover { background:#1a1a1a; border-color:#f5a623; color:#f5a623; }
        .btn-gold { background:#f5a623; color:#000; padding:8px 20px; border-radius:8px; font-weight:600; transition:all 0.2s; border:none; cursor:pointer; display:inline-block; }
        .btn-gold:hover { background:#e09515; transform:translateY(-2px); box-shadow:0 4px 20px rgba(245,166,35,0.3); }
        .btn-green { background:#22c55e; color:#fff; padding:8px 20px; border-radius:8px; font-weight:600; transition:all 0.2s; border:none; cursor:pointer; display:inline-block; }
        .btn-green:hover { background:#16a34a; transform:translateY(-2px); box-shadow:0 4px 20px rgba(34,197,94,0.3); }
        .btn-sm { padding:4px 12px; font-size:12px; }
        .table-row { border-bottom:1px solid #1f1f1f; transition:background 0.15s; }
        .table-row:hover { background:#181818; }
        .search-input { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:8px 16px; border-radius:8px; width:200px; }
        .search-input:focus { outline:none; border-color:#f5a623; }
        .date-wrapper { position:relative; display:inline-block; }
        .date-wrapper input[type="date"] { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:8px 12px 8px 34px; border-radius:8px; width:160px; font-size:14px; cursor:pointer; }
        .date-wrapper input[type="date"]:focus { outline:none; border-color:#f5a623; }
        .date-wrapper input[type="date"]::-webkit-calendar-picker-indicator { opacity:0; position:absolute; right:0; width:100%; height:100%; cursor:pointer; }
        .date-wrapper .calendar-icon { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none; font-size:16px; }
        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-track { background:#0a0a0a; }
        ::-webkit-scrollbar-thumb { background:#2a2a2a; border-radius:10px; }
        ::-webkit-scrollbar-thumb:hover { background:#f5a623; }
        .modal-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.8); backdrop-filter:blur(4px); z-index:9999; justify-content:center; align-items:center; padding:20px; }
        .modal-overlay.active { display:flex; }
        .modal-content { background:#111; border:1px solid #2a2a2a; border-radius:20px; max-width:600px; width:100%; max-height:90vh; overflow-y:auto; padding:24px 20px; position:relative; animation:modalFadeIn 0.3s ease; }
        @keyframes modalFadeIn { from { opacity:0; transform:translateY(-20px) scale(0.95); } to { opacity:1; transform:translateY(0) scale(1); } }
        .modal-close { position:absolute; top:14px; right:18px; font-size:22px; color:#666; cursor:pointer; transition:color 0.2s; background:none; border:none; }
        .modal-close:hover { color:#fff; }
        .modal-item { background:#0d0d0d; border:1px solid #1f1f1f; border-radius:10px; padding:12px; margin-bottom:10px; }
        .modal-item:last-child { margin-bottom:0; }
        @media print { .sidebar, .btn-back, .btn-gold, .btn-green, .no-print, .hamburger-btn, .sidebar-overlay { display:none !important; } .card-dark { border:none; background:white; color:black; } body { background:white; color:black; } .table-row { border-bottom:1px solid #ddd; } .badge { background:#eee; color:black; } .text-gold { color:#b8860b; } .main-content { margin-left:0 !important; } }
        .filter-wrap { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .filter-wrap .search-input { width:100%; max-width:200px; }
        .filter-wrap .date-wrapper input[type="date"] { width:140px; }
        @media (max-width:640px) { .filter-wrap .search-input { max-width:100%; } .filter-wrap .date-wrapper input[type="date"] { width:120px; } .filter-wrap .btn-gold, .filter-wrap .btn-back { font-size:12px; padding:6px 12px; } .table-wrap table { min-width:600px; } .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .table-wrap table { min-width:850px; width:100%; }
        .toast-container { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; max-width:380px; width:100%; }
        .toast { background:#1a1a1a; border:1px solid #f5a623; border-radius:12px; padding:14px 18px; box-shadow:0 10px 40px rgba(0,0,0,0.5); animation:slideIn 0.5s ease, fadeOut 0.5s ease 4.5s forwards; display:flex; align-items:center; gap:12px; }
        .toast .icon { font-size:22px; }
        .toast .msg { flex:1; color:#e5e7eb; font-size:14px; }
        .toast .msg strong { color:#f5a623; }
        .toast .close-toast { background:transparent; border:none; color:#666; cursor:pointer; font-size:18px; padding:0 4px; }
        .toast .close-toast:hover { color:#fff; }
        @keyframes slideIn { from { transform:translateX(100%); opacity:0; } to { transform:translateX(0); opacity:1; } }
        @keyframes fadeOut { from { opacity:1; transform:translateX(0); } to { opacity:0; transform:translateX(50px); } }
        .filter-badge { background:rgba(245,166,35,0.15); border:1px solid #f5a623; border-radius:8px; padding:6px 12px; display:inline-block; margin-right:8px; font-size:13px; color:#f5a623; }
        .pagination { display:flex; flex-wrap:wrap; gap:5px; justify-content:center; margin-top:15px; }
        .pagination a, .pagination span { display:inline-block; padding:6px 12px; border-radius:6px; background:#0a0a0a; border:1px solid #2a2a2a; color:#ccc; text-decoration:none; font-size:14px; transition:0.2s; }
        .pagination a:hover { border-color:#f5a623; color:#f5a623; }
        .pagination .active { background:#f5a623; color:#000; border-color:#f5a623; font-weight:600; }
        select, .filter-select { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:8px 16px; border-radius:8px; font-size:14px; cursor:pointer; }
        select:focus, .filter-select:focus { outline:none; border-color:#f5a623; }
        .filter-group { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
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
                    <h1 class="text-xl md:text-2xl font-bold text-white"><i class="fas fa-clipboard-list mr-2 text-gold"></i> Daftar Pesanan</h1>
                </div>
            </div>
            <?php if (hasMenuAccess('order_form') || $role === 'owner'): ?>
                <a href="order_form.php" class="btn-gold text-sm"><i class="fas fa-plus mr-1"></i> Tambah Pesanan</a>
            <?php endif; ?>
        </div>

        <!-- STATISTIK -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5 stats-grid">
            <div class="card-dark p-4 text-center">
                <div class="text-2xl font-bold text-gold"><?= $stats['today_orders'] ?></div>
                <div class="text-xs text-gray-400"><i class="fas fa-calendar-day mr-1"></i> Pesanan Hari Ini</div>
            </div>
            <div class="card-dark p-4 text-center">
                <div class="text-2xl font-bold text-green-400">Rp<?= number_format($stats['today_revenue'], 0, ',', '.') ?></div>
                <div class="text-xs text-gray-400"><i class="fas fa-coins mr-1"></i> Pendapatan Hari Ini</div>
            </div>
            <div class="card-dark p-4 text-center">
                <div class="text-2xl font-bold text-red-400"><?= $stats['unpaid'] ?></div>
                <div class="text-xs text-gray-400"><i class="fas fa-exclamation-triangle mr-1"></i> Belum Lunas</div>
            </div>
            <div class="card-dark p-4 text-center">
                <div class="text-2xl font-bold text-blue-400"><?= $stats['ready'] ?></div>
                <div class="text-xs text-gray-400"><i class="fas fa-box-open mr-1"></i> Siap Ambil</div>
            </div>
        </div>

        <!-- Filter Aktif -->
        <?php if (!empty($filterMessage)): ?>
        <div class="mb-4 p-3 bg-gold/10 border border-gold rounded-lg flex flex-wrap items-center justify-between">
            <div>
                <span class="text-sm text-gray-300"><i class="fas fa-filter mr-1 text-gold"></i> Filter aktif: </span>
                <span class="text-sm text-white"><?= $filterMessage ?></span>
            </div>
            <a href="orders.php" class="text-xs text-gold hover:underline"><i class="fas fa-times mr-1"></i> Hapus semua filter</a>
        </div>
        <?php endif; ?>

        <!-- FORM FILTER -->
        <div class="filter-wrap no-print mb-4">
            <form method="GET" action="" class="flex flex-wrap items-center gap-3 w-full">
                <input type="text" name="search" placeholder="Cari nama / telepon / no.order" value="<?= htmlspecialchars($search) ?>" class="search-input">
                <input type="text" name="product" placeholder="Cari produk..." value="<?= htmlspecialchars($product_search) ?>" class="search-input">

                <select name="status" class="filter-select">
                    <option value="">Semua Status</option>
                    <option value="diterima" <?= $status_filter == 'diterima' ? 'selected' : '' ?>>Order Masuk & Desain</option>
                    <option value="finishing" <?= $status_filter == 'finishing' ? 'selected' : '' ?>>Pencetakan & Finishing</option>
                    <option value="siap_ambil" <?= $status_filter == 'siap_ambil' ? 'selected' : '' ?>>Selesai</option>
                </select>

                <select name="payment" class="filter-select">
                    <option value="">Semua Pembayaran</option>
                    <option value="lunas" <?= $payment == 'lunas' ? 'selected' : '' ?>>Lunas</option>
                    <option value="belum" <?= $payment == 'belum' ? 'selected' : '' ?>>Belum Lunas</option>
                </select>

                <select name="date_preset" class="filter-select" onchange="this.form.submit()">
                    <option value="">Pilih Tanggal</option>
                    <option value="today" <?= $date_preset == 'today' ? 'selected' : '' ?>>Hari Ini</option>
                    <option value="week" <?= $date_preset == 'week' ? 'selected' : '' ?>>7 Hari Terakhir</option>
                    <option value="month" <?= $date_preset == 'month' ? 'selected' : '' ?>>Bulan Ini</option>
                    <option value="custom" <?= ($date_from && $date_to && !$date_preset) ? 'selected' : '' ?>>Kustom</option>
                </select>

                <div class="date-wrapper">
                    <i class="fas fa-calendar-alt calendar-icon"></i>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" placeholder="Dari">
                </div>
                <span class="text-gray-500 text-sm">s/d</span>
                <div class="date-wrapper">
                    <i class="fas fa-calendar-alt calendar-icon"></i>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" placeholder="Sampai">
                </div>

                <button type="submit" class="btn-gold text-sm"><i class="fas fa-search mr-1"></i> Cari</button>
                <a href="orders.php" class="btn-back text-sm"><i class="fas fa-undo mr-1"></i> Reset</a>

                <div class="flex flex-wrap gap-2 ml-auto">
                    <a href="<?= $export_url ?>" class="btn-green text-sm"><i class="fas fa-file-excel mr-1"></i> Export Excel</a>
                    <button onclick="window.print()" class="btn-back text-sm"><i class="fas fa-print mr-1"></i> Print</button>
                </div>
            </form>
        </div>

        <!-- BATCH UPDATE FORM -->
        <form id="batchForm" method="POST" action="" onsubmit="return confirmBatchUpdate()">
            <input type="hidden" name="batch_update" value="1">
            <div class="flex flex-wrap items-center gap-3 mb-3 no-print">
                <span class="text-sm text-gray-400"><i class="fas fa-check-square mr-1"></i> Dengan yang dicentang:</span>
                <select name="new_status" class="bg-[#0a0a0a] border border-[#2a2a2a] text-white rounded-lg px-3 py-1.5 text-sm focus:border-gold outline-none">
                    <option value="diterima">Order Masuk & Desain</option>
                    <option value="finishing">Pencetakan & Finishing</option>
                    <option value="siap_ambil">Selesai</option>
                </select>
                <button type="submit" class="btn-gold text-sm"><i class="fas fa-sync mr-1"></i> Update Status</button>
                <button type="button" class="text-gray-500 text-sm hover:text-white" onclick="toggleAllCheckboxes()">Pilih Semua</button>
            </div>

            <!-- TABEL -->
            <div class="card-dark p-4 md:p-6">
                <div class="table-wrap">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b border-[#1f1f1f]">
                                <th class="py-2 pr-2 w-8"><input type="checkbox" id="selectAll" onchange="toggleAllCheckboxes()"></th>
                                <th class="py-2 pr-2">No. Pesanan</th>
                                <th class="py-2 pr-2">Pelanggan</th>
                                <th class="py-2 pr-2">Dibuat Oleh</th>
                                <th class="py-2 pr-2">Diedit Oleh</th>
                                <th class="py-2 pr-2">Total</th>
                                <th class="py-2 pr-2">Status</th>
                                <th class="py-2 pr-2">Tgl. Order</th>
                                <th class="py-2">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($orders) > 0): ?>
                                <?php foreach ($orders as $order):
                                    $statusMap = ['diterima'=>'Order Masuk & Desain', 'finishing'=>'Pencetakan & Finishing', 'siap_ambil'=>'Selesai'];
                                    $statusText = $statusMap[$order['status']] ?? $order['status'];
                                    $badgeClass = match($order['status']) {
                                        'siap_ambil' => 'badge-green',
                                        'diterima', 'finishing' => 'badge-yellow',
                                        'batal' => 'badge-red',
                                        default => 'badge-gray'
                                    };
                                    $orderDate = date('d/m/Y H:i', strtotime($order['order_date']));
                                    $createdBy = $order['created_by_name'] ?? '-';
                                    $updatedBy = $order['updated_by_name'] ?? '-';
                                    $canEdit = hasMenuAccess('order_form') || $role === 'owner';
                                ?>
                                <tr class="table-row">
                                    <td class="py-2 pr-2"><input type="checkbox" name="order_ids[]" value="<?= $order['id'] ?>" class="order-checkbox"></td>
                                    <td class="py-2 pr-2 font-mono text-gold text-xs md:text-sm"><?= htmlspecialchars($order['order_number']) ?></td>
                                    <td class="py-2 pr-2 text-xs md:text-sm"><?= htmlspecialchars($order['customer_name'] ?? 'Unknown') ?></td>
                                    <td class="py-2 pr-2 text-xs md:text-sm"><?= htmlspecialchars($createdBy) ?></td>
                                    <td class="py-2 pr-2 text-xs md:text-sm"><?= htmlspecialchars($updatedBy) ?></td>
                                    <td class="py-2 pr-2 text-xs md:text-sm">Rp<?= number_format($order['grand_total'], 0, ',', '.') ?></td>
                                    <td class="py-2 pr-2"><span class="badge <?= $badgeClass ?>"><?= $statusText ?></span></td>
                                    <td class="py-2 pr-2 text-xs md:text-sm"><?= $orderDate ?></td>
                                    <td class="py-2 whitespace-nowrap">
                                        <!-- View -->
                                        <button type="button" class="text-blue-400 hover:text-blue-300 mr-2 view-btn" data-id="<?= (int)$order['id'] ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($canEdit): ?>
                                            <a href="order_form.php?id=<?= $order['id'] ?>" class="text-gold hover:underline mr-2"><i class="fas fa-edit"></i></a>
                                            <a href="orders.php?delete=<?= $order['id'] ?>" onclick="return confirm('Yakin ingin menghapus pesanan ini?')" class="text-red-500 hover:underline mr-2"><i class="fas fa-trash"></i></a>
                                            <?php if ($order['status'] !== 'siap_ambil' && $order['status'] !== 'batal'): ?>
                                                <a href="orders.php?quick_status=siap_ambil&id=<?= $order['id'] ?>" class="text-green-400 hover:underline text-xs" onclick="return confirm('Tandai Siap Ambil?')">Siap</a>
                                            <?php endif; ?>
                                            <?php if ($order['status'] !== 'batal'): ?>
                                                <a href="orders.php?quick_status=batal&id=<?= $order['id'] ?>" class="text-red-400 hover:underline text-xs" onclick="return confirm('Batalkan pesanan ini?')">Batal</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <!-- ===== TOMBOL CETAK ===== -->
                                        <a href="print_receipt.php?id=<?= $order['id'] ?>&auto=1" target="_blank" class="text-blue-400 hover:text-blue-300 ml-1" title="Cetak Nota">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" class="py-6 text-center text-gray-500 text-sm"><i class="fas fa-inbox mr-2"></i> Belum ada pesanan</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination no-print">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&<?= http_build_query(array_filter(['search'=>$search,'product'=>$product_search,'date_from'=>$date_from,'date_to'=>$date_to,'payment'=>$payment,'status'=>$status_filter,'date_preset'=>$date_preset])) ?>">« Prev</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?page=<?= $i ?>&<?= http_build_query(array_filter(['search'=>$search,'product'=>$product_search,'date_from'=>$date_from,'date_to'=>$date_to,'payment'=>$payment,'status'=>$status_filter,'date_preset'=>$date_preset])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page+1 ?>&<?= http_build_query(array_filter(['search'=>$search,'product'=>$product_search,'date_from'=>$date_from,'date_to'=>$date_to,'payment'=>$payment,'status'=>$status_filter,'date_preset'=>$date_preset])) ?>">Next »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="text-xs text-gray-600 mt-3 text-center">Menampilkan <?= count($orders) ?> dari <?= $totalRows ?> pesanan</div>
    </main>

    <!-- MODAL VIEW -->
    <div class="modal-overlay" id="orderModal">
        <div class="modal-content">
            <button class="modal-close" id="modalClose"><i class="fas fa-times"></i></button>
            <div id="modalBody"></div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('orderModal');
            const modalBody = document.getElementById('modalBody');
            const closeBtn = document.getElementById('modalClose');

            function closeModal() { modal.classList.remove('active'); }
            closeBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
            document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

            // View detail (sama seperti sebelumnya)
            document.querySelectorAll('.view-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const orderId = this.dataset.id;
                    modalBody.innerHTML = '<div class="text-center py-8 text-gray-400"><i class="fas fa-spinner fa-pulse text-2xl"></i><p class="mt-2">Memuat...</p></div>';
                    modal.classList.add('active');

                    fetch(`/order_detail_ajax.php?id=${orderId}&t=${Date.now()}`)
                        .then(res => {
                            if (!res.ok) throw new Error(`HTTP ${res.status}`);
                            return res.json();
                        })
                        .then(data => {
                            if (data.success) {
                                renderModal(data.order, data.items);
                            } else {
                                modalBody.innerHTML = `<div class="text-center py-8 text-red-400"><i class="fas fa-exclamation-triangle text-2xl"></i><p class="mt-2">Error: ${data.message || 'Gagal memuat detail.'}</p></div>`;
                            }
                        })
                        .catch(err => {
                            console.error('Fetch error:', err);
                            const row = this.closest('tr');
                            const orderNumber = row.querySelector('.font-mono')?.innerText || '#' + orderId;
                            const customer = row.querySelector('td:nth-child(3)')?.innerText || '-';
                            const total = row.querySelector('td:nth-child(6)')?.innerText || 'Rp0';
                            const status = row.querySelector('.badge')?.innerText || '-';
                            modalBody.innerHTML = `
                                <div class="text-center py-4">
                                    <i class="fas fa-exclamation-circle text-yellow-400 text-2xl"></i>
                                    <p class="text-gray-400 mt-2">Gagal memuat detail lengkap. Tampilkan data dasar:</p>
                                    <div class="mt-4 text-left space-y-2">
                                        <div><span class="text-gray-400">No. Pesanan:</span> <span class="text-gold font-bold">${orderNumber}</span></div>
                                        <div><span class="text-gray-400">Pelanggan:</span> ${customer}</div>
                                        <div><span class="text-gray-400">Total:</span> ${total}</div>
                                        <div><span class="text-gray-400">Status:</span> ${status}</div>
                                    </div>
                                    <button class="mt-4 btn-back text-sm" onclick="document.getElementById('orderModal').classList.remove('active')">Tutup</button>
                                </div>
                            `;
                        });
                });
            });

            function renderModal(order, items) {
                const statusMap = { 'diterima':'Order Masuk & Desain', 'finishing':'Pencetakan & Finishing', 'siap_ambil':'Selesai' };
                const statusText = statusMap[order.status] || order.status;
                const paymentText = order.payment_status === 'lunas' ? 'LUNAS' : 'BELUM BAYAR';
                const paymentClass = order.payment_status === 'lunas' ? 'text-green-400' : 'text-red-400';
                const paymentIcon = order.payment_status === 'lunas' ? 'fa-check-circle' : 'fa-times-circle';

                let itemsHtml = '';
                if (items.length === 0) {
                    itemsHtml = '<p class="text-gray-500 text-sm">Tidak ada item.</p>';
                } else {
                    items.forEach(item => {
                        itemsHtml += `
                            <div class="modal-item flex justify-between items-center">
                                <div>
                                    <div class="font-semibold text-white">${item.product_name}</div>
                                    <div class="text-xs text-gray-400">${item.qty} pcs × Rp${Number(item.price_per_unit).toLocaleString('id-ID')}</div>
                                    ${item.material && item.material !== '-' ? `<div class="text-xs text-gray-500"><i class="fas fa-box mr-1"></i> ${item.material}</div>` : ''}
                                </div>
                                <div class="text-gold font-bold">Rp${Number(item.total).toLocaleString('id-ID')}</div>
                            </div>
                        `;
                    });
                }

                modalBody.innerHTML = `
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h2 class="text-xl font-bold text-gold">${order.order_number}</h2>
                            <p class="text-gray-400 text-sm"><i class="far fa-calendar-alt mr-1"></i> ${new Date(order.order_date).toLocaleDateString('id-ID', { day:'numeric', month:'long', year:'numeric', hour:'2-digit', minute:'2-digit' })}</p>
                        </div>
                        <span class="badge ${order.status === 'siap_ambil' ? 'badge-green' : 'badge-yellow'}">${statusText}</span>
                    </div>
                    <div class="mb-4 p-3 bg-[#0a0a0a] rounded-lg border border-[#1f1f1f]">
                        <div class="flex justify-between items-center"><span class="text-gray-400"><i class="fas fa-user mr-1"></i> Pelanggan</span><span class="font-semibold text-white">${order.customer_name || 'Unknown'}</span></div>
                        <div class="flex justify-between items-center mt-1"><span class="text-gray-400"><i class="fas fa-credit-card mr-1"></i> Status Bayar</span><span class="${paymentClass} font-semibold"><i class="fas ${paymentIcon} mr-1"></i>${paymentText}</span></div>
                        ${order.design_fee > 0 ? `<div class="flex justify-between items-center mt-1"><span class="text-gray-400"><i class="fas fa-paint-brush mr-1"></i> Jasa Desain</span><span class="text-gold">+ Rp${Number(order.design_fee).toLocaleString('id-ID')}</span></div>` : ''}
                    </div>
                    <div class="mb-4"><h4 class="text-sm font-semibold text-gray-300 mb-2"><i class="fas fa-list-ul mr-1"></i> Rincian Pesanan</h4>${itemsHtml}</div>
                    <hr class="border-[#1f1f1f] my-4">
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between text-gray-400"><span>Subtotal</span><span>Rp${Number(order.subtotal).toLocaleString('id-ID')}</span></div>
                        ${order.discount > 0 ? `<div class="flex justify-between text-gray-400"><span>Diskon</span><span class="text-red-400">- Rp${Number(order.discount).toLocaleString('id-ID')}</span></div>` : ''}
                        ${order.design_fee > 0 ? `<div class="flex justify-between text-gray-400"><span>Jasa Desain</span><span class="text-gold">+ Rp${Number(order.design_fee).toLocaleString('id-ID')}</span></div>` : ''}
                        <div class="flex justify-between text-gold font-bold text-base border-t border-[#1f1f1f] pt-2 mt-1"><span><i class="fas fa-coins mr-1"></i> Total</span><span>Rp${Number(order.grand_total).toLocaleString('id-ID')}</span></div>
                    </div>
                    ${order.notes ? `<div class="mt-4 p-3 bg-[#0a0a0a] rounded-lg text-sm text-gray-400 border border-[#1f1f1f]"><i class="fas fa-pencil-alt mr-1 text-gold"></i> <span class="text-gold">Catatan:</span> ${order.notes}</div>` : ''}
                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <a href="https://cloud.creativegeek-idn.com/index.php?order=${order.order_number}" target="_blank" class="btn-gold text-sm"><i class="fas fa-external-link-alt mr-1"></i> Lihat Nota</a>
                        <button class="btn-back text-sm" onclick="document.getElementById('orderModal').classList.remove('active')"><i class="fas fa-times mr-1"></i> Tutup</button>
                    </div>
                `;
                modal.classList.add('active');
            }

            // Toast notifications
            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'deleted'): ?>
                    showToast('Pesanan berhasil dihapus.', 'Berhasil');
                <?php elseif ($_GET['msg'] === 'batch_updated' && isset($_GET['count'])): ?>
                    showToast('<?= (int)$_GET['count'] ?> pesanan berhasil diupdate.', 'Batch Update');
                <?php elseif ($_GET['msg'] === 'no_select'): ?>
                    showToast('Tidak ada pesanan yang dipilih.', 'Perhatian');
                <?php elseif ($_GET['msg'] === 'quick_updated'): ?>
                    showToast('Status pesanan berhasil diubah.', 'Berhasil');
                <?php elseif ($_GET['msg'] === 'error'): ?>
                    showToast('Terjadi kesalahan. Silakan coba lagi.', 'Error');
                <?php endif; ?>
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

            window.toggleAllCheckboxes = function() {
                const checked = document.getElementById('selectAll').checked;
                document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = checked);
            };

            window.confirmBatchUpdate = function() {
                const checked = document.querySelectorAll('.order-checkbox:checked');
                if (checked.length === 0) {
                    alert('Pilih minimal satu pesanan.');
                    return false;
                }
                const status = document.querySelector('select[name="new_status"]').value;
                return confirm(`Ubah ${checked.length} pesanan menjadi status "${status}"?`);
            };
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
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            }
        });
    </script>
</body>
</html>