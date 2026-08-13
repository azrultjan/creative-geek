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


// ===== SET TIMEZONE KE WITA =====
date_default_timezone_set('Asia/Makassar');

// Ambil ID admin yang sedang login (untuk audit trail)
$userId = $_SESSION['user_id'] ?? 0;

$id = $_GET['id'] ?? 0;
$is_edit = $id > 0;

$order = null;
$items = [];
if ($is_edit) {
    $stmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        die('Pesanan tidak ditemukan.');
    }

    $stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmt_items->execute([$id]);
    $items = $stmt_items->fetchAll();
}

// Ambil SEMUA pelanggan
$customers = $pdo->query("
    SELECT c.*, MAX(o.order_date) as last_order
    FROM customers c
    LEFT JOIN orders o ON c.id = o.customer_id
    GROUP BY c.id
    ORDER BY last_order DESC, c.id DESC
")->fetchAll();

// Ambil price data
$priceData = [];
$stmt = $pdo->query("SELECT category, variant, price FROM product_prices");
while ($row = $stmt->fetch()) {
    $priceData[$row['category']][$row['variant']] = (int) $row['price'];
}
if (empty($priceData['spanduk'])) $priceData['spanduk'] = ['1x1' => 45000];
if (empty($priceData['sticker']['label'])) $priceData['sticker']['label'] = ['A4' => 12000, 'A3' => 22000];
if (empty($priceData['sticker']['decal'])) $priceData['sticker']['decal'] = 1500;
if (empty($priceData['sablon'])) $priceData['sablon'] = ['A3' => 45000];

define('HARGA_SPANDUK_REGULAR', 45000);
define('HARGA_SPANDUK_REGULAR_CUSTOMER', 40000);

$designFee = 15000;
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
$stmt->execute(['design_fee']);
$row = $stmt->fetch();
if ($row) {
    $designFee = (int)$row['setting_value'];
}

$statusOptions = [
    'diterima' => 'Order Masuk & Desain',
    'finishing' => 'Pencetakan & Finishing',
    'siap_ambil' => 'Selesai'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        die('Error: Silakan pilih pelanggan yang valid.');
    }
    $stmt_check = $pdo->prepare("SELECT id, is_regular FROM customers WHERE id = ?");
    $stmt_check->execute([$customer_id]);
    $customer_data = $stmt_check->fetch();
    if (!$customer_data) {
        die('Error: Pelanggan tidak ditemukan di database.');
    }
    $is_regular = (int)$customer_data['is_regular'];

    $status = $_POST['status'] ?? 'diterima';
    $payment_status = $_POST['payment_status'] ?? 'belum';
    $payment_method = $_POST['payment_method'] ?? null;
    if ($payment_status == 'belum') $payment_method = null;
    $discount = (int)str_replace('.', '', $_POST['discount'] ?? 0);
    $notes = $_POST['notes'] ?? '';
    $design_fee = (int)($_POST['design_fee'] ?? 0);

    // progress, remaining_time, print_status
    if ($status === 'siap_ambil') {
        $progress = 100;
        $remaining_time = '00:00:00';
        $print_status = 'Done';
    } else {
        if ($is_edit) {
            $progress = $order['progress'] ?? 0;
            $remaining_time = $order['remaining_time'] ?? '00:00:00';
            $print_status = $order['print_status'] ?? 'Print';
        } else {
            $progress = 0;
            $remaining_time = '00:00:00';
            $print_status = 'Print';
        }
    }

    // Ambil array input
    $product_names = $_POST['product_name'] ?? [];
    $qtys          = $_POST['qty'] ?? [];
    $prices        = $_POST['price'] ?? [];
    $materials     = $_POST['material'] ?? [];
    $item_notes    = $_POST['item_notes'] ?? [];
    $totals        = $_POST['item_total'] ?? [];
    $categories    = $_POST['category'] ?? [];
    $lebars        = $_POST['lebar'] ?? [];
    $tinggis       = $_POST['tinggi'] ?? [];
    $sticker_types = $_POST['sticker_type'] ?? [];
    $sticker_label_sizes = $_POST['sticker_label_size'] ?? [];
    $decal_panjangs = $_POST['decal_panjang'] ?? [];
    $decal_lebars  = $_POST['decal_lebar'] ?? [];
    $sablon_sizes  = $_POST['sablon_size'] ?? [];

    $count = count($product_names);
    $qtys  = array_pad($qtys, $count, 1);
    $prices = array_pad($prices, $count, 0);
    $materials = array_pad($materials, $count, '-');
    $item_notes = array_pad($item_notes, $count, '');
    $totals = array_pad($totals, $count, 0);
    $categories = array_pad($categories, $count, 'lainnya');
    $lebars = array_pad($lebars, $count, 0);
    $tinggis = array_pad($tinggis, $count, 0);
    $sticker_types = array_pad($sticker_types, $count, 'label');
    $sticker_label_sizes = array_pad($sticker_label_sizes, $count, 'A4');
    $decal_panjangs = array_pad($decal_panjangs, $count, 0);
    $decal_lebars = array_pad($decal_lebars, $count, 0);
    $sablon_sizes = array_pad($sablon_sizes, $count, 'A3');

    $product_names = array_values($product_names);
    $qtys = array_values($qtys);
    $prices = array_values($prices);
    $materials = array_values($materials);
    $item_notes = array_values($item_notes);
    $totals = array_values($totals);
    $categories = array_values($categories);
    $lebars = array_values($lebars);
    $tinggis = array_values($tinggis);
    $sticker_types = array_values($sticker_types);
    $sticker_label_sizes = array_values($sticker_label_sizes);
    $decal_panjangs = array_values($decal_panjangs);
    $decal_lebars = array_values($decal_lebars);
    $sablon_sizes = array_values($sablon_sizes);

    foreach ($prices as &$price) {
        $price = (int)str_replace('.', '', $price);
    }
    unset($price);
    foreach ($totals as &$total) {
        $total = (int)str_replace('.', '', $total);
    }
    unset($total);

    $order_number = $is_edit ? $order['order_number'] : generateOrderNumber();

    try {
        $pdo->beginTransaction();

        if ($is_edit) {
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET customer_id=?,
                    status=?,
                    payment_status=?,
                    payment_method=?,
                    discount=?,
                    notes=?,
                    design_fee=?,
                    updated_by=?,
                    progress=?,
                    remaining_time=?,
                    print_status=?
                WHERE id=?
            ");
            $stmt->execute([
                $customer_id,
                $status,
                $payment_status,
                $payment_method,
                $discount,
                $notes,
                $design_fee,
                $userId,
                $progress,
                $remaining_time,
                $print_status,
                $id
            ]);
            $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO orders 
                    (order_number, customer_id, status, payment_status, payment_method, discount, notes, design_fee, 
                     progress, remaining_time, print_status, diterima_at, created_by, updated_by) 
                VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW(), ?, ?)
            ");
            $stmt->execute([
                $order_number,
                $customer_id,
                $status,
                $payment_status,
                $payment_method,
                $discount,
                $notes,
                $design_fee,
                $progress,
                $remaining_time,
                $print_status,
                $userId,
                $userId
            ]);
            $id = $pdo->lastInsertId();
        }

        $subtotal = 0;
        for ($i = 0; $i < count($product_names); $i++) {
            if (empty($product_names[$i])) continue;
            $qty = (int)($qtys[$i] ?? 1);
            $category = $categories[$i] ?? 'lainnya';
            $material = $materials[$i] ?? '-';
            $item_note = $item_notes[$i] ?? '';
            $total_input = isset($totals[$i]) ? (int)$totals[$i] : 0;

            if ($total_input > 0) {
                $price = ($qty > 0) ? round($total_input / $qty) : 0;
                $price = max(0, $price);
                $total = $total_input;
            } else {
                if ($category === 'spanduk') {
                    $lebar = (float) str_replace(',', '.', $lebars[$i] ?? 0);
                    $tinggi = (float) str_replace(',', '.', $tinggis[$i] ?? 0);
                    $luas = $lebar * $tinggi;
                    $hargaPerM2 = $is_regular ? HARGA_SPANDUK_REGULAR_CUSTOMER : HARGA_SPANDUK_REGULAR;
                    $price = (int) round($luas * $hargaPerM2);
                } elseif ($category === 'sticker') {
                    $type = $sticker_types[$i] ?? 'label';
                    if ($type === 'decal') {
                        $panjang = (float) str_replace(',', '.', $decal_panjangs[$i] ?? 0);
                        $lebar = (float) str_replace(',', '.', $decal_lebars[$i] ?? 0);
                        $hargaPerCm2 = $priceData['sticker']['decal'] ?? 1500;
                        $price = (int) round($panjang * $lebar * $hargaPerCm2);
                    } else {
                        $size = $sticker_label_sizes[$i] ?? 'A4';
                        $price = $priceData['sticker']['label'][$size] ?? 12000;
                    }
                } elseif ($category === 'sablon') {
                    $size = $sablon_sizes[$i] ?? 'A3';
                    $price = $priceData['sablon'][$size] ?? 45000;
                } else {
                    $price = (int)($prices[$i] ?? 0);
                }
                $price = max(0, $price);
                $total = $qty * $price;
            }

            $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_name, qty, price_per_unit, material, notes, total) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$id, $product_names[$i], $qty, $price, $material, $item_note, $total]);
            $subtotal += $total;
        }

        $grand_total = $subtotal - $discount + $design_fee;
        $pdo->prepare("UPDATE orders SET subtotal = ?, grand_total = ? WHERE id = ?")
            ->execute([$subtotal, $grand_total, $id]);

        $pdo->commit();

        // Kirim notifikasi WA
        $stmt_customer = $pdo->prepare("SELECT phone, name FROM customers WHERE id = ?");
        $stmt_customer->execute([$customer_id]);
        $customer = $stmt_customer->fetch();

        if ($customer && !empty($customer['phone'])) {
            $link = "https://cloud.creativegeek-idn.com/index.php?order=" . $order_number;
            switch ($status) {
                case 'diterima':
                    $statusText = $statusOptions[$status] ?? $status;
                    $message = "Halo " . $customer['name'] . ",\n\n";
                    $message .= "Hidup adalah proses. Dan proses orderanmu #" . $order_number . " saat ini adalah:\n\n";
                    $message .= "♦ *" . $statusText . "* ♦\n\n";
                    $message .= "Seperti sungai mengalir ke lautan,\n";
                    $message .= "Seperti angin berbisik di malam hari,\n";
                    $message .= "Pesananmu pun berjalan sesuai takdirnya.\n\n";
                    $message .= "Untuk menyaksikan langsung takdir tersebut (via e-receipt), buka portal ini:\n";
                    $message .= $link . "\n\n";
                    $message .= "Terima kasih telah menjadi bagian dari semesta Creative Geek.";
                    break;
                case 'finishing':
                    $estimatedMinutes = 30;
                    $message = "Halo " . $customer['name'] . ",\n\n";
                    $message .= "Pesanan #" . $order_number . " sudah masuk proses cetak. Estimasi selesai sekitar " . $estimatedMinutes . " menit.\n\n";
                    $message .= "Ngomong-ngomong soal estimasi, sekarang kami punya fitur andalan: progress bar yang nyambung langsung ke mesin cetak.\n";
                    $message .= "Angka yang kamu lihat itu update tiap detik—bukan perkiraan kasar, tapi hitungan real dari mesin kami.\n\n";
                    $message .= "Cek biar nggak penasaran:\n" . $link . "\n\n";
                    $message .= "Makasih sudah pesen di Creative Geek!";
                    break;
                case 'siap_ambil':
                    $message = "Halo " . $customer['name'] . "! \n\n";
                    $message .= "BREAKING NEWS: Pesanan #" . $order_number . " telah SELESAI dan siap diambil!\n";
                    $message .= "Detail e-receipt:\n" . $link . "\n\n";
                    $message .= "Alamat kami : Jl. El Tari Km.6 Kel. Maubeli, Kefamenanu, NTT.\n";
                    $message .= "Lihat di Google Maps: https://maps.app.goo.gl/WBVins3sw8qcKSc18\n\n";
                    break;
                default:
                    $message = "Halo " . $customer['name'] . ",\n\nStatus pesanan #" . $order_number . " adalah: " . $status . "\nCek di: " . $link;
                    break;
            }
            sendWhatsAppNotification($customer['phone'], $message);
        }

        header('Location: orders.php?msg=updated');
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        die('Gagal menyimpan data: ' . $e->getMessage());
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Terjadi kesalahan: ' . $e->getMessage());
    }
}

function extractSpandukName($product_name) {
    if (strpos($product_name, 'Spanduk') !== 0) return '';
    $rest = trim(substr($product_name, strlen('Spanduk')));
    if (preg_match('/(\d+\.?\d*)\s*x\s*(\d+\.?\d*)$/', $rest, $matches)) {
        $name = trim(substr($rest, 0, -strlen($matches[0])));
        return $name;
    }
    return $rest;
}

function rupiah($angka) {
    return number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?= $is_edit ? 'Edit' : 'Tambah' ?> Pesanan - Creative Geek</title>
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
        .btn-gold { background:#f5a623; color:#000; font-weight:600; padding:8px 20px; border-radius:10px; transition:all 0.2s; border:none; cursor:pointer; display:inline-block; }
        .btn-gold:hover { background:#e09515; transform:translateY(-2px); box-shadow:0 4px 20px rgba(245,166,35,0.3); }
        .btn-outline { background:transparent; border:1px solid #2a2a2a; color:#d4d4d8; padding:8px 20px; border-radius:10px; transition:all 0.2s; text-decoration:none; display:inline-block; }
        .btn-outline:hover { border-color:#f5a623; color:#f5a623; }
        input, select, textarea { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff !important; padding:8px 12px; border-radius:8px; width:100%; }
        input:focus, select:focus, textarea:focus { outline:none; border-color:#f5a623; }
        input::placeholder, textarea::placeholder { color:#666; }
        .item-row { background:#0d0d0d; border:1px solid #222; border-radius:10px; padding:12px; margin-bottom:10px; }
        .item-row .grid { gap:8px; }
        .dynamic-field { transition:all 0.3s ease; }
        .customer-search { position:relative; }
        .customer-search input { padding-right:30px; }
        .customer-search .dropdown-icon { position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#888; pointer-events:none; }
        input::-webkit-calendar-picker-indicator { opacity:0; position:absolute; right:0; width:100%; height:100%; cursor:pointer; }
        .no-item-msg { color:#aaa; text-align:center; padding:16px; background:#0d0d0d; border-radius:8px; border:1px dashed #2a2a2a; }
        .payment-method-group { transition:all 0.3s ease; }
        .payment-method-group.hidden { display:none; }
        .btn-group { display:flex; gap:10px; flex-wrap:wrap; }
        .design-fee-option { background:#0d0d0d; border:1px solid #222; border-radius:10px; padding:12px; margin-bottom:10px; }
        .design-fee-option label { margin-right:20px; cursor:pointer; }
        .design-fee-option input[type="radio"] { width:auto; margin-right:5px; }
        .rp-input { text-align:right; }
        .table-wrap { overflow-x:auto; }
        .drop-zone {
            border: 2px dashed #2a2a2a;
            border-radius: 8px;
            padding: 6px 10px;
            text-align: center;
            color: #888;
            font-size: 11px;
            transition: all 0.3s;
            cursor: pointer;
            background: #0a0a0a;
            margin-top: 4px;
        }
        .drop-zone.dragover { border-color: #f5a623; background: rgba(245,166,35,0.1); color: #f5a623; }
        .drop-zone i { margin-right: 4px; }
        .drop-zone .file-name-preview { color: #f5a623; font-weight:600; }
        @media (max-width: 768px) {
            .item-row .grid-cols-12 { grid-template-columns: 1fr 1fr !important; gap: 6px !important; }
            .item-row .col-span-2 { grid-column: span 1; }
            .item-row .col-span-9 { grid-column: span 2; }
            .item-row .col-span-4 { grid-column: span 1; }
            .item-row .col-span-1 { grid-column: span 1; }
            .item-row .col-span-4:has(.product-name-input) { grid-column: span 2; }
            .item-row .grid-cols-3.gap-2 { grid-template-columns: 1fr 1fr; }
            .card-dark { padding: 12px !important; }
            .btn-gold, .btn-outline { font-size: 13px; padding: 6px 14px; }
            .drop-zone { font-size: 10px; padding: 4px 8px; }
            .main-content { padding: 12px; }
        }
        @media (max-width: 480px) {
            .item-row .grid-cols-12 { grid-template-columns: 1fr !important; }
            .item-row .col-span-2, .item-row .col-span-9, .item-row .col-span-4, .item-row .col-span-1 { grid-column: span 1; }
            .item-row .grid-cols-3.gap-2 { grid-template-columns: 1fr; }
            .item-row .spanduk-field .grid-cols-3 { grid-template-columns: 1fr; }
            .item-row .sticker-field .grid-cols-3 { grid-template-columns: 1fr; }
            .design-fee-option .flex { flex-direction: column; gap: 8px; }
        }
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
            <h1 class="text-xl md:text-2xl font-bold text-gold">
                <i class="fas <?= $is_edit ? 'fa-edit' : 'fa-plus-circle' ?> mr-2"></i>
                <?= $is_edit ? 'Edit' : 'Tambah' ?> Pesanan
            </h1>
        </div>
        <a href="orders.php" class="btn-outline text-sm"><i class="fas fa-arrow-left mr-1"></i> Kembali</a>
    </div>

    <form method="POST" class="card-dark p-4 md:p-6" id="orderForm">
        <!-- Customer -->
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-300">
                <i class="fas fa-user mr-1"></i> Pelanggan *
            </label>
            <div class="customer-search relative">
                <input type="text" id="customer_search" list="customer_list"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white" 
                       placeholder="Ketik nama atau telepon pelanggan..." 
                       autocomplete="off" 
                       value="<?= $is_edit ? htmlspecialchars($order['customer_name'] ?? '') : '' ?>">
                <datalist id="customer_list">
                    <?php foreach ($customers as $c): 
                        $cleanPhone = preg_replace('/[^0-9]/', '', $c['phone']);
                    ?>
                        <option value="<?= htmlspecialchars($cleanPhone) ?>" data-id="<?= $c['id'] ?>">
                            <?= htmlspecialchars($c['name'] . ' - ' . $c['phone']) ?>
                        </option>
                    <?php endforeach; ?>
                </datalist>
                <input type="hidden" name="customer_id" id="customer_id" value="<?= $is_edit ? $order['customer_id'] : '' ?>">
                <span class="dropdown-icon"><i class="fas fa-chevron-down"></i></span>
            </div>
            <p class="text-xs text-gray-500 mt-1">Ketik nama atau telepon, hasil akan muncul otomatis.</p>
            <div id="customer_error" class="text-red-500 text-xs mt-1 hidden">Silakan pilih pelanggan dari daftar yang tersedia.</div>
        </div>

        <!-- Status & Payment -->
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-300"><i class="fas fa-tag mr-1"></i> Status</label>
            <select name="status">
                <?php foreach ($statusOptions as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($is_edit && $order['status'] == $key) ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-300"><i class="fas fa-credit-card mr-1"></i> Status Bayar</label>
                <select name="payment_status" id="payment_status">
                    <option value="belum" <?= ($is_edit && $order['payment_status'] == 'belum') ? 'selected' : '' ?>>Belum Bayar</option>
                    <option value="lunas" <?= ($is_edit && $order['payment_status'] == 'lunas') ? 'selected' : '' ?>>Lunas</option>
                </select>
            </div>
            <div class="payment-method-group <?= ($is_edit && $order['payment_status'] == 'belum') ? 'hidden' : '' ?>">
                <label class="block text-sm font-medium text-gray-300"><i class="fas fa-money-bill mr-1"></i> Metode Bayar</label>
                <select name="payment_method" id="payment_method" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-white">
                    <option value="Cash" <?= ($is_edit && $order['payment_method'] == 'Cash') ? 'selected' : '' ?>>Cash</option>
                    <option value="Transfer" <?= ($is_edit && $order['payment_method'] == 'Transfer') ? 'selected' : '' ?>>Transfer</option>
                </select>
            </div>
        </div>

        <!-- ITEMS -->
        <div class="mb-4">
            <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium text-gray-300"><i class="fas fa-list-ul mr-1"></i> Item Pesanan</label>
                <button type="button" id="add-item" class="text-gold text-sm hover:underline"><i class="fas fa-plus mr-1"></i> Tambah Item</button>
            </div>
            <div id="items-container">
                <?php if ($is_edit && count($items) > 0): ?>
                    <?php foreach ($items as $item): 
                        $computedPrice = $item['price_per_unit'];
                        $computedTotal = $item['total'];
                        $cat = 'lainnya';
                        if (strpos($item['product_name'], 'Spanduk') !== false) $cat = 'spanduk';
                        elseif (strpos($item['product_name'], 'Sticker') !== false) $cat = 'sticker';
                        elseif (strpos($item['product_name'], 'Sablon') !== false) $cat = 'sablon';
                        
                        $lebar = '';
                        $tinggi = '';
                        if (preg_match('/(\d+\.?\d*)\s*x\s*(\d+\.?\d*)/', $item['product_name'], $matches)) {
                            $lebar = $matches[1];
                            $tinggi = $matches[2];
                        }
                    ?>
                        <div class="item-row border border-gray-700 rounded-lg p-3 mb-3 bg-gray-900" data-is-edit="true">
                            <div class="grid grid-cols-12 gap-2 items-start">
                                <div class="col-span-2">
                                    <label class="block text-xs text-gray-400 mb-1">Kategori</label>
                                    <select name="category[]" class="product-category w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                                        <option value="spanduk" <?= strpos($item['product_name'], 'Spanduk') !== false ? 'selected' : '' ?>>Spanduk</option>
                                        <option value="sticker" <?= strpos($item['product_name'], 'Sticker') !== false ? 'selected' : '' ?>>Sticker</option>
                                        <option value="sablon" <?= strpos($item['product_name'], 'Sablon') !== false ? 'selected' : '' ?>>Sablon</option>
                                        <option value="lainnya" <?= strpos($item['product_name'], 'Spanduk') === false && strpos($item['product_name'], 'Sticker') === false && strpos($item['product_name'], 'Sablon') === false ? 'selected' : '' ?>>Lainnya</option>
                                    </select>
                                </div>

                                <div class="spanduk-field dynamic-field col-span-9 grid grid-cols-3 gap-2" style="display: <?= strpos($item['product_name'], 'Spanduk') !== false ? 'block' : 'none' ?>;">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Nama Spanduk</label>
                                        <?php
                                            $spanduk_name = '';
                                            if (strpos($item['product_name'], 'Spanduk') !== false) {
                                                $spanduk_name = extractSpandukName($item['product_name']);
                                            }
                                        ?>
                                        <input type="text" name="spanduk_name[]" class="spanduk-nama price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="Nama Spanduk" value="<?= htmlspecialchars($spanduk_name) ?>">
                                        <div class="drop-zone">
                                            <i class="fas fa-cloud-upload-alt"></i> Drop file di sini untuk ambil nama
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Lebar (m)</label>
                                        <input type="text" name="lebar[]" class="spanduk-lebar price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white angka-only" placeholder="2" value="<?= $lebar ?>">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Tinggi (m)</label>
                                        <input type="text" name="tinggi[]" class="spanduk-tinggi price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white angka-only" placeholder="1" value="<?= $tinggi ?>">
                                    </div>
                                </div>

                                <div class="sticker-field dynamic-field col-span-9 grid grid-cols-3 gap-2" style="display: <?= strpos($item['product_name'], 'Sticker') !== false ? 'block' : 'none' ?>;">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Jenis Sticker</label>
                                        <select name="sticker_type[]" class="sticker-type price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                                            <option value="label" <?= strpos($item['product_name'], 'Label') !== false ? 'selected' : '' ?>>Label</option>
                                            <option value="decal" <?= strpos($item['product_name'], 'Decal') !== false ? 'selected' : '' ?>>Decal</option>
                                        </select>
                                    </div>
                                    <div class="sticker-label-field dynamic-field col-span-2" style="display: <?= strpos($item['product_name'], 'Label') !== false ? 'block' : 'none' ?>;">
                                        <label class="block text-xs text-gray-400 mb-1">Ukuran Label</label>
                                        <select name="sticker_label_size[]" class="sticker-label-size price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                                            <option value="A4" <?= strpos($item['product_name'], 'A4') !== false ? 'selected' : '' ?>>A4</option>
                                            <option value="A3" <?= strpos($item['product_name'], 'A3') !== false ? 'selected' : '' ?>>A3</option>
                                        </select>
                                    </div>
                                    <div class="sticker-decal-field dynamic-field col-span-2 grid grid-cols-2 gap-2" style="display: <?= strpos($item['product_name'], 'Decal') !== false ? 'block' : 'none' ?>;">
                                        <div>
                                            <label class="block text-xs text-gray-400 mb-1">Panjang (cm)</label>
                                            <?php
                                                $panjang = '';
                                                $lebar_decal = '';
                                                if (preg_match('/(\d+)x(\d+)/', $item['product_name'], $matches_decal)) {
                                                    $panjang = $matches_decal[1];
                                                    $lebar_decal = $matches_decal[2];
                                                }
                                            ?>
                                            <input type="number" name="decal_panjang[]" class="decal-panjang price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="10" value="<?= $panjang ?>">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-gray-400 mb-1">Lebar (cm)</label>
                                            <input type="number" name="decal_lebar[]" class="decal-lebar price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="10" value="<?= $lebar_decal ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="sablon-field dynamic-field col-span-9" style="display: <?= strpos($item['product_name'], 'Sablon') !== false ? 'block' : 'none' ?>;">
                                    <label class="block text-xs text-gray-400 mb-1">Ukuran Sablon</label>
                                    <select name="sablon_size[]" class="sablon-size price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                                        <option value="A3" <?= strpos($item['product_name'], 'Sablon') !== false ? 'selected' : '' ?>>A3</option>
                                    </select>
                                </div>

                                <div class="manual-field dynamic-field col-span-9" style="display: <?= strpos($item['product_name'], 'Spanduk') === false && strpos($item['product_name'], 'Sticker') === false && strpos($item['product_name'], 'Sablon') === false ? 'block' : 'none' ?>;">
                                    <p class="text-xs text-gray-500 italic"><i class="fas fa-info-circle mr-1"></i> Isi nama produk & material di baris bawah</p>
                                </div>

                                <div class="col-span-1 flex justify-end items-start">
                                    <button type="button" class="remove-item text-red-500 hover:text-red-300 text-xl font-bold leading-none mt-1"><i class="fas fa-times"></i></button>
                                </div>
                            </div>

                            <div class="grid grid-cols-12 gap-2 items-end mt-2">
                                <div class="col-span-4">
                                    <label class="block text-xs text-gray-400 mb-1">Nama Produk</label>
                                    <input type="text" name="product_name[]" class="product-name-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="<?= htmlspecialchars($item['product_name']) ?>" readonly>
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-xs text-gray-400 mb-1">Qty</label>
                                    <input type="number" name="qty[]" class="qty-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="<?= $item['qty'] ?>" min="1">
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-xs text-gray-400 mb-1">Harga (Rp)</label>
                                    <input type="text" name="price[]" class="price-input rp-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="<?= rupiah($computedPrice) ?>">
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-xs text-gray-400 mb-1">Total</label>
                                    <input type="text" name="item_total[]" class="item-total rp-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="<?= rupiah($computedTotal) ?>">
                                </div>
                                <div class="col-span-2"></div>
                            </div>

                            <div class="mt-2">
                                <label class="block text-xs text-gray-400 mb-1"><i class="fas fa-box mr-1"></i> Material / Catatan</label>
                                <input type="text" name="material[]" class="material-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="Material atau catatan tambahan" value="<?= htmlspecialchars($item['material'] ?? '-') ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if ($is_edit): ?>
                        <div class="no-item-msg"><i class="fas fa-exclamation-triangle mr-1"></i> Belum ada item untuk pesanan ini. Klik <strong>+ Tambah Item</strong> untuk menambahkan.</div>
                    <?php endif; ?>
                    <div class="item-row border border-gray-700 rounded-lg p-3 mb-3 bg-gray-900" data-is-edit="false">
                        <div class="grid grid-cols-12 gap-2 items-start">
                            <div class="col-span-2">
                                <label class="block text-xs text-gray-400 mb-1">Kategori</label>
                                <select name="category[]" class="product-category w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                                    <option value="spanduk" selected>Spanduk</option>
                                    <option value="sticker">Sticker</option>
                                    <option value="sablon">Sablon</option>
                                    <option value="lainnya">Lainnya</option>
                                </select>
                            </div>

                            <div class="spanduk-field dynamic-field col-span-9 grid grid-cols-3 gap-2" style="display: block;">
                                <div>
                                    <label class="block text-xs text-gray-400 mb-1">Nama Spanduk</label>
                                    <input type="text" name="spanduk_name[]" class="spanduk-nama price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="Nama Spanduk" value="">
                                    <div class="drop-zone">
                                        <i class="fas fa-cloud-upload-alt"></i> Drop file di sini untuk ambil nama
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-400 mb-1">Lebar (m)</label>
                                    <input type="text" name="lebar[]" class="spanduk-lebar price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white angka-only" placeholder="2" value="">
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-400 mb-1">Tinggi (m)</label>
                                    <input type="text" name="tinggi[]" class="spanduk-tinggi price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white angka-only" placeholder="1" value="">
                                </div>
                            </div>

                            <div class="sticker-field dynamic-field col-span-9 grid grid-cols-3 gap-2" style="display: none;">
                                <div>
                                    <label class="block text-xs text-gray-400 mb-1">Jenis Sticker</label>
                                    <select name="sticker_type[]" class="sticker-type price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                                        <option value="label" selected>Label</option>
                                        <option value="decal">Decal</option>
                                    </select>
                                </div>
                                <div class="sticker-label-field dynamic-field col-span-2" style="display: none;">
                                    <label class="block text-xs text-gray-400 mb-1">Ukuran Label</label>
                                    <select name="sticker_label_size[]" class="sticker-label-size price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                                        <option value="A4" selected>A4</option>
                                        <option value="A3">A3</option>
                                    </select>
                                </div>
                                <div class="sticker-decal-field dynamic-field col-span-2 grid grid-cols-2 gap-2" style="display: none;">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Panjang (cm)</label>
                                        <input type="number" name="decal_panjang[]" class="decal-panjang price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="10" value="">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Lebar (cm)</label>
                                        <input type="number" name="decal_lebar[]" class="decal-lebar price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="10" value="">
                                    </div>
                                </div>
                            </div>

                            <div class="sablon-field dynamic-field col-span-9" style="display: none;">
                                <label class="block text-xs text-gray-400 mb-1">Ukuran Sablon</label>
                                <select name="sablon_size[]" class="sablon-size price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                                    <option value="A3">A3</option>
                                </select>
                            </div>

                            <div class="manual-field dynamic-field col-span-9" style="display: none;">
                                <p class="text-xs text-gray-500 italic"><i class="fas fa-info-circle mr-1"></i> Isi nama produk & material di baris bawah</p>
                            </div>

                            <div class="col-span-1 flex justify-end items-start">
                                <button type="button" class="remove-item text-red-500 hover:text-red-300 text-xl font-bold leading-none mt-1"><i class="fas fa-times"></i></button>
                            </div>
                        </div>

                        <div class="grid grid-cols-12 gap-2 items-end mt-2">
                            <div class="col-span-4">
                                <label class="block text-xs text-gray-400 mb-1">Nama Produk</label>
                                <input type="text" name="product_name[]" class="product-name-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="" readonly>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs text-gray-400 mb-1">Qty</label>
                                <input type="number" name="qty[]" class="qty-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="1" min="1">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs text-gray-400 mb-1">Harga (Rp)</label>
                                <input type="text" name="price[]" class="price-input rp-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs text-gray-400 mb-1">Total</label>
                                <input type="text" name="item_total[]" class="item-total rp-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="0">
                            </div>
                            <div class="col-span-2"></div>
                        </div>

                        <div class="mt-2">
                            <label class="block text-xs text-gray-400 mb-1"><i class="fas fa-box mr-1"></i> Material / Catatan</label>
                            <input type="text" name="material[]" class="material-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="Material atau catatan tambahan" value="-">
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- JASA DESAIN -->
        <div class="design-fee-option">
            <label class="block text-sm font-medium text-gray-300 mb-2"><i class="fas fa-paint-brush mr-1"></i> Jasa Desain</label>
            <div class="flex flex-wrap items-center gap-6">
                <label>
                    <input type="radio" name="design_fee" value="0" <?= ($is_edit && ($order['design_fee'] ?? 0) == 0) ? 'checked' : '' ?> class="design-fee-radio">
                    Tanpa Desain
                </label>
                <label>
                    <input type="radio" name="design_fee" value="<?= $designFee ?>" <?= ($is_edit && ($order['design_fee'] ?? 0) == $designFee) ? 'checked' : '' ?> class="design-fee-radio">
                    Dengan Jasa Desain (+Rp<?= rupiah($designFee) ?>)
                </label>
            </div>
            <p class="text-xs text-gray-500 mt-1">Pilih "Dengan Jasa Desain" jika customer meminta desain, akan ditambahkan biaya Rp<?= rupiah($designFee) ?> ke total.</p>
        </div>

        <!-- Total -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-300">Subtotal</label>
                <input type="text" name="subtotal" id="subtotal" value="<?= $is_edit ? rupiah($order['subtotal']) : '0' ?>" readonly class="rp-input bg-gray-800 cursor-not-allowed">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300">Diskon</label>
                <input type="text" name="discount" id="discount" value="<?= $is_edit ? rupiah($order['discount']) : '0' ?>" class="rp-input">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300">Total</label>
                <input type="text" name="grand_total" id="grand_total" value="<?= $is_edit ? rupiah($order['grand_total']) : '0' ?>" readonly class="rp-input bg-gray-800 cursor-not-allowed">
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-300"><i class="fas fa-pencil-alt mr-1"></i> Catatan Pesanan</label>
            <textarea name="notes" rows="2"><?= $is_edit ? htmlspecialchars($order['notes']) : '' ?></textarea>
        </div>

        <div class="btn-group">
            <button type="submit" class="btn-gold"><i class="fas fa-save mr-1"></i> Simpan Pesanan</button>
            <a href="orders.php" class="btn-outline"><i class="fas fa-times mr-1"></i> Batal</a>
        </div>
    </form>
</main>

<!-- Template Item -->
<div id="item-template" style="display: none;">
    <div class="item-row border border-gray-700 rounded-lg p-3 mb-3 bg-gray-900" data-is-edit="false">
        <div class="grid grid-cols-12 gap-2 items-start">
            <div class="col-span-2">
                <label class="block text-xs text-gray-400 mb-1">Kategori</label>
                <select name="category[]" class="product-category w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                    <option value="spanduk" selected>Spanduk</option>
                    <option value="sticker">Sticker</option>
                    <option value="sablon">Sablon</option>
                    <option value="lainnya">Lainnya</option>
                </select>
            </div>

            <div class="spanduk-field dynamic-field col-span-9 grid grid-cols-3 gap-2" style="display: block;">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Nama Spanduk</label>
                    <input type="text" name="spanduk_name[]" class="spanduk-nama price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="Nama Spanduk" value="">
                    <div class="drop-zone">
                        <i class="fas fa-cloud-upload-alt"></i> Drop file di sini untuk ambil nama
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Lebar (m)</label>
                    <input type="text" name="lebar[]" class="spanduk-lebar price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white angka-only" placeholder="2" value="">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Tinggi (m)</label>
                    <input type="text" name="tinggi[]" class="spanduk-tinggi price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white angka-only" placeholder="1" value="">
                </div>
            </div>

            <div class="sticker-field dynamic-field col-span-9 grid grid-cols-3 gap-2" style="display: none;">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Jenis Sticker</label>
                    <select name="sticker_type[]" class="sticker-type price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                        <option value="label" selected>Label</option>
                        <option value="decal">Decal</option>
                    </select>
                </div>
                <div class="sticker-label-field dynamic-field col-span-2" style="display: none;">
                    <label class="block text-xs text-gray-400 mb-1">Ukuran Label</label>
                    <select name="sticker_label_size[]" class="sticker-label-size price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                        <option value="A4" selected>A4</option>
                        <option value="A3">A3</option>
                    </select>
                </div>
                <div class="sticker-decal-field dynamic-field col-span-2 grid grid-cols-2 gap-2" style="display: none;">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Panjang (cm)</label>
                        <input type="number" name="decal_panjang[]" class="decal-panjang price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="10" value="">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Lebar (cm)</label>
                        <input type="number" name="decal_lebar[]" class="decal-lebar price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="10" value="">
                    </div>
                </div>
            </div>

            <div class="sablon-field dynamic-field col-span-9" style="display: none;">
                <label class="block text-xs text-gray-400 mb-1">Ukuran Sablon</label>
                <select name="sablon_size[]" class="sablon-size price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                    <option value="A3">A3</option>
                </select>
            </div>

            <div class="manual-field dynamic-field col-span-9" style="display: none;">
                <p class="text-xs text-gray-500 italic"><i class="fas fa-info-circle mr-1"></i> Isi nama produk & material di baris bawah</p>
            </div>

            <div class="col-span-1 flex justify-end items-start">
                <button type="button" class="remove-item text-red-500 hover:text-red-300 text-xl font-bold leading-none mt-1"><i class="fas fa-times"></i></button>
            </div>
        </div>

        <div class="grid grid-cols-12 gap-2 items-end mt-2">
            <div class="col-span-4">
                <label class="block text-xs text-gray-400 mb-1">Nama Produk</label>
                <input type="text" name="product_name[]" class="product-name-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="" readonly>
            </div>
            <div class="col-span-2">
                <label class="block text-xs text-gray-400 mb-1">Qty</label>
                <input type="number" name="qty[]" class="qty-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="1" min="1">
            </div>
            <div class="col-span-2">
                <label class="block text-xs text-gray-400 mb-1">Harga (Rp)</label>
                <input type="text" name="price[]" class="price-input rp-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="">
            </div>
            <div class="col-span-2">
                <label class="block text-xs text-gray-400 mb-1">Total</label>
                <input type="text" name="item_total[]" class="item-total rp-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="0">
            </div>
            <div class="col-span-2"></div>
        </div>

        <div class="mt-2">
            <label class="block text-xs text-gray-400 mb-1"><i class="fas fa-box mr-1"></i> Material / Catatan</label>
            <input type="text" name="material[]" class="material-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="Material atau catatan tambahan" value="-">
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== CUSTOMER SEARCH =====
    const searchInput = document.getElementById('customer_search');
    const hiddenId = document.getElementById('customer_id');
    const errorDiv = document.getElementById('customer_error');
    const datalist = document.getElementById('customer_list');

    // Function to find matching option based on input (substring match)
    function findMatchingOption(inputVal) {
        const options = datalist.options;
        const val = inputVal.trim().toLowerCase();
        if (val === '') return null;
        for (let i = 0; i < options.length; i++) {
            const opt = options[i];
            const text = opt.textContent.trim().toLowerCase();
            const value = opt.value.trim().toLowerCase();
            // Substring match on value or textContent
            if (value.includes(val) || text.includes(val)) {
                return opt;
            }
        }
        return null;
    }

    searchInput.addEventListener('input', function() {
        const matched = findMatchingOption(this.value);
        if (matched) {
            hiddenId.value = matched.dataset.id;
            errorDiv.classList.add('hidden');
        } else {
            hiddenId.value = '';
            if (this.value.trim() !== '') {
                errorDiv.classList.remove('hidden');
            } else {
                errorDiv.classList.add('hidden');
            }
        }
    });

    searchInput.addEventListener('blur', function() {
        const val = this.value.trim();
        if (val === '') {
            hiddenId.value = '';
            errorDiv.classList.add('hidden');
            return;
        }
        const matched = findMatchingOption(val);
        if (matched) {
            hiddenId.value = matched.dataset.id;
            errorDiv.classList.add('hidden');
        } else {
            hiddenId.value = '';
            errorDiv.classList.remove('hidden');
        }
    });

    // ===== SET AWAL UNTUK EDIT =====
    <?php if ($is_edit && $order): ?>
        const customerId = <?= $order['customer_id'] ?>;
        const customerName = "<?= htmlspecialchars($order['customer_name'] ?? '') ?>";
        if (customerName) {
            searchInput.value = customerName;
            hiddenId.value = customerId;
            errorDiv.classList.add('hidden');
        }
    <?php endif; ?>

    // ===== SUBMIT VALIDATION =====
    document.getElementById('orderForm').addEventListener('submit', function(e) {
        if (!hiddenId.value || hiddenId.value === '') {
            e.preventDefault();
            errorDiv.classList.remove('hidden');
            searchInput.focus();
            alert('Silakan pilih pelanggan yang valid dari daftar.');
        }
    });

    // ===== PAYMENT TOGGLE =====
    const paymentStatus = document.getElementById('payment_status');
    const paymentMethodGroup = document.querySelector('.payment-method-group');

    function togglePaymentMethod() {
        if (paymentStatus.value === 'belum') paymentMethodGroup.classList.add('hidden');
        else paymentMethodGroup.classList.remove('hidden');
    }
    paymentStatus.addEventListener('change', togglePaymentMethod);
    togglePaymentMethod();

    // ===== RUPIAH FORMAT =====
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('angka-only')) {
            e.target.value = e.target.value.replace(/[^0-9.,]/g, '');
        }
    });

    function formatRp(input) {
        let val = input.value.replace(/\./g, '').replace(/\D/g, '');
        if (val === '') { input.value = ''; return; }
        input.value = new Intl.NumberFormat('id-ID').format(parseInt(val));
    }

    document.querySelectorAll('.rp-input').forEach(el => {
        el.addEventListener('blur', function() { formatRp(this); });
        el.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9.]/g, '');
        });
    });

    // ===== PRICE LIST =====
    const priceList = {
        spanduk: { '1x1': <?= $priceData['spanduk']['1x1'] ?? 45000 ?> },
        sticker: {
            label: { 'A4': <?= $priceData['sticker']['label']['A4'] ?? 12000 ?>, 'A3': <?= $priceData['sticker']['label']['A3'] ?? 22000 ?> },
            decal: <?= $priceData['sticker']['decal'] ?? 1500 ?>
        },
        sablon: { 'A3': <?= $priceData['sablon']['A3'] ?? 45000 ?> }
    };
    const HARGA_REGULAR = <?= HARGA_SPANDUK_REGULAR ?>;
    const HARGA_REGULAR_CUSTOMER = <?= HARGA_SPANDUK_REGULAR_CUSTOMER ?>;

    // ===== ITEM FUNCTIONS =====
    function updateTotalOnly(row) {
        const qty = parseInt(row.querySelector('.qty-input').value) || 0;
        const price = parseInt(row.querySelector('.price-input').value.replace(/\./g, '')) || 0;
        const total = qty * price;
        row.querySelector('.item-total').value = new Intl.NumberFormat('id-ID').format(total);
        recalculateTotal();
    }

    function recalculateItem(row, triggeredBy = '') {
        const category = row.querySelector('.product-category').value;
        const qtyInput = row.querySelector('.qty-input');
        const priceInput = row.querySelector('.price-input');
        const totalInput = row.querySelector('.item-total');
        const nameInput = row.querySelector('.product-name-input');
        const namaSpandukInput = row.querySelector('.spanduk-nama');

        if (category === 'lainnya') {
            nameInput.readOnly = false;
        } else {
            nameInput.readOnly = true;
        }

        let qty = parseInt(qtyInput.value) || 0;
        let price = parseInt(priceInput.value.replace(/\./g, '')) || 0;
        let total = parseInt(totalInput.value.replace(/\./g, '')) || 0;

        if (triggeredBy === 'total') {
            if (qty > 0 && total > 0) {
                price = Math.round(total / qty);
                priceInput.value = new Intl.NumberFormat('id-ID').format(price);
            } else if (total === 0) {
                price = 0;
                priceInput.value = '0';
            }
        } else {
            if (category === 'spanduk') {
                const lebar = parseFloat(row.querySelector('.spanduk-lebar').value.replace(',', '.')) || 0;
                const tinggi = parseFloat(row.querySelector('.spanduk-tinggi').value.replace(',', '.')) || 0;
                let hargaPerM2 = HARGA_REGULAR;
                const luas = lebar * tinggi;
                const newPrice = Math.round(luas * hargaPerM2);
                if (newPrice > 0) {
                    price = newPrice;
                    priceInput.value = new Intl.NumberFormat('id-ID').format(newPrice);
                }
                const namaSpanduk = namaSpandukInput ? namaSpandukInput.value.trim() : '';
                const ukuran = (lebar && tinggi) ? lebar + 'x' + tinggi : '';
                nameInput.value = ('Spanduk' + (namaSpanduk ? ' ' + namaSpanduk : '') + (ukuran ? ' ' + ukuran : '')).trim();
            } else if (category === 'sticker') {
                const type = row.querySelector('.sticker-type').value;
                if (type === 'decal') {
                    const panjang = parseFloat(row.querySelector('.decal-panjang').value.replace(',', '.')) || 0;
                    const lebar = parseFloat(row.querySelector('.decal-lebar').value.replace(',', '.')) || 0;
                    const hargaPerCm2 = priceList.sticker.decal || 1500;
                    const luas = panjang * lebar;
                    const newPrice = Math.round(luas * hargaPerCm2);
                    if (newPrice > 0) {
                        price = newPrice;
                        priceInput.value = new Intl.NumberFormat('id-ID').format(newPrice);
                    }
                    nameInput.value = 'Sticker Decal ' + panjang + 'x' + lebar + ' cm';
                } else {
                    const size = row.querySelector('.sticker-label-size').value;
                    price = priceList.sticker.label[size] || 0;
                    priceInput.value = new Intl.NumberFormat('id-ID').format(price);
                    nameInput.value = 'Sticker Label ' + size;
                }
            } else if (category === 'sablon') {
                const size = row.querySelector('.sablon-size').value;
                price = priceList.sablon[size] || 0;
                priceInput.value = new Intl.NumberFormat('id-ID').format(price);
                nameInput.value = 'Sablon A3';
            }
        }

        if (triggeredBy !== 'total') {
            total = qty * price;
            totalInput.value = new Intl.NumberFormat('id-ID').format(total);
        }

        recalculateTotal();
    }

    function recalculateTotal() {
        let subtotal = 0;
        document.querySelectorAll('.item-total').forEach(input => {
            subtotal += parseInt(input.value.replace(/\./g, '')) || 0;
        });
        const discount = parseInt(document.getElementById('discount').value.replace(/\./g, '')) || 0;
        const designFee = parseInt(document.querySelector('input[name="design_fee"]:checked')?.value) || 0;
        const grand = subtotal - discount + designFee;
        document.getElementById('subtotal').value = new Intl.NumberFormat('id-ID').format(subtotal);
        document.getElementById('grand_total').value = new Intl.NumberFormat('id-ID').format(grand);
    }

    function toggleDynamicFields(row) {
        const category = row.querySelector('.product-category').value;
        row.querySelectorAll('.dynamic-field').forEach(el => el.style.display = 'none');
        if (category === 'spanduk') {
            row.querySelector('.spanduk-field').style.display = 'block';
        } else if (category === 'sticker') {
            const type = row.querySelector('.sticker-type').value;
            row.querySelector('.sticker-field').style.display = 'block';
            if (type === 'label') {
                row.querySelector('.sticker-label-field').style.display = 'block';
                row.querySelector('.sticker-decal-field').style.display = 'none';
            } else {
                row.querySelector('.sticker-decal-field').style.display = 'block';
                row.querySelector('.sticker-label-field').style.display = 'none';
            }
        } else if (category === 'sablon') {
            row.querySelector('.sablon-field').style.display = 'block';
        } else {
            row.querySelector('.manual-field').style.display = 'block';
        }
        const nameInput = row.querySelector('.product-name-input');
        if (category === 'lainnya') {
            nameInput.readOnly = false;
        } else {
            nameInput.readOnly = true;
        }
        recalculateItem(row);
    }

    function removeItem(row) {
        const container = document.getElementById('items-container');
        if (container.children.length > 1) {
            row.remove();
            recalculateTotal();
        } else {
            alert('Minimal harus ada 1 item pesanan.');
        }
    }

    function bindEvents(row) {
        row.querySelector('.product-category').addEventListener('change', function() {
            toggleDynamicFields(row);
        });
        row.querySelector('.sticker-type').addEventListener('change', function() {
            toggleDynamicFields(row);
        });

        const triggers = row.querySelectorAll('.price-trigger, .qty-input, .price-input, .spanduk-lebar, .spanduk-tinggi, .decal-panjang, .decal-lebar, .spanduk-nama');
        triggers.forEach(el => {
            el.addEventListener('input', function() { recalculateItem(row); });
            el.addEventListener('change', function() { recalculateItem(row); });
        });
        row.querySelector('.qty-input').addEventListener('input', function() { recalculateItem(row); });
        row.querySelector('.price-input').addEventListener('input', function() {
            recalculateItem(row);
        });

        const totalInput = row.querySelector('.item-total');
        if (totalInput) {
            totalInput.addEventListener('input', function() {
                recalculateItem(row, 'total');
            });
            totalInput.addEventListener('blur', function() {
                formatRp(this);
            });
        }

        const removeBtn = row.querySelector('.remove-item');
        if (removeBtn) {
            removeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                removeItem(row);
            });
        }

        const dropZone = row.querySelector('.drop-zone');
        if (dropZone) {
            const targetInput = row.querySelector('.spanduk-nama');
            if (targetInput) {
                const handleFile = function(file) {
                    let fileName = file.name;
                    fileName = fileName.replace(/\s*\(\d+\)$/, '');
                    while (fileName.includes('.')) {
                        fileName = fileName.replace(/\.[^.]+$/, '');
                    }
                    fileName = fileName.trim();
                    targetInput.value = fileName;
                    targetInput.dispatchEvent(new Event('input'));
                    dropZone.innerHTML = '<i class="fas fa-check-circle text-gold"></i> <span class="file-name-preview">' + fileName + '</span>';
                    setTimeout(() => {
                        dropZone.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Drop file di sini untuk ambil nama';
                    }, 3000);
                };

                dropZone.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.classList.add('dragover');
                });
                dropZone.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                });
                dropZone.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                    if (e.dataTransfer.files.length > 0) {
                        handleFile(e.dataTransfer.files[0]);
                    }
                });
                dropZone.addEventListener('click', function() {
                    const input = document.createElement('input');
                    input.type = 'file';
                    input.accept = '*/*';
                    input.onchange = function() {
                        if (this.files.length > 0) handleFile(this.files[0]);
                    };
                    input.click();
                });
            }
        }
    }

    // ===== INIT ITEMS =====
    document.querySelectorAll('.item-row').forEach(row => {
        const category = row.querySelector('.product-category').value;
        const isEdit = row.dataset.isEdit === 'true';

        row.querySelectorAll('.dynamic-field').forEach(el => el.style.display = 'none');
        if (category === 'spanduk') {
            row.querySelector('.spanduk-field').style.display = 'block';
        } else if (category === 'sticker') {
            const type = row.querySelector('.sticker-type').value;
            row.querySelector('.sticker-field').style.display = 'block';
            if (type === 'label') {
                row.querySelector('.sticker-label-field').style.display = 'block';
                row.querySelector('.sticker-decal-field').style.display = 'none';
            } else {
                row.querySelector('.sticker-decal-field').style.display = 'block';
                row.querySelector('.sticker-label-field').style.display = 'none';
            }
        } else if (category === 'sablon') {
            row.querySelector('.sablon-field').style.display = 'block';
        } else {
            row.querySelector('.manual-field').style.display = 'block';
        }

        const nameInput = row.querySelector('.product-name-input');
        if (category !== 'lainnya') {
            nameInput.readOnly = true;
        } else {
            nameInput.readOnly = false;
        }

        if (isEdit) updateTotalOnly(row);
        else recalculateItem(row);

        bindEvents(row);
    });

    // ===== ADD ITEM =====
    document.getElementById('add-item').addEventListener('click', function() {
        const container = document.getElementById('items-container');
        const template = document.getElementById('item-template').innerHTML;
        const wrapper = document.createElement('div');
        wrapper.className = 'item-row border border-gray-700 rounded-lg p-3 mb-3 bg-gray-900';
        wrapper.dataset.isEdit = 'false';
        wrapper.innerHTML = template;
        container.appendChild(wrapper);
        wrapper.querySelectorAll('.dynamic-field').forEach(el => el.style.display = 'none');
        wrapper.querySelector('.spanduk-field').style.display = 'block';
        const nameInput = wrapper.querySelector('.product-name-input');
        nameInput.readOnly = true;
        recalculateItem(wrapper);
        bindEvents(wrapper);
    });

    // ===== DISCOUNT & DESIGN FEE =====
    document.getElementById('discount').addEventListener('input', function() {
        recalculateTotal();
    });
    document.getElementById('discount').addEventListener('blur', function() {
        formatRp(this);
    });
    document.querySelectorAll('input[name="design_fee"]').forEach(el => {
        el.addEventListener('change', function() {
            recalculateTotal();
        });
    });

    recalculateTotal();
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