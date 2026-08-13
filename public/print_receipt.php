<?php
// ============================================================
// print_receipt.php - Nota Thermal 58mm (HITAM PEKAT)
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$order_id = isset($_GET['id']) ? $_GET['id'] : null;
$order_number = isset($_GET['order']) ? $_GET['order'] : null;

if (empty($order_id) && empty($order_number)) {
    die('ID atau nomor order tidak ditemukan.');
}

require_once __DIR__ . '/config/database.php';

if (!isset($pdo)) {
    die('Koneksi database gagal.');
}

function getProductTable($pdo) {
    $tables = ['products', 'product', 'barang', 'produk'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) return $table;
        } catch (PDOException $e) { continue; }
    }
    return null;
}
$productTable = getProductTable($pdo);

$defaultSettings = [
    'store_name'      => 'CREATIVE GEEK',
    'store_phone'     => '0812-4628-4684',
    'store_address'   => 'Jl. El Tari Km.6 Kel. Maubeli, Kefamenanu, NTT',
    'store_whatsapp'  => '6281246284684',
    'store_hours'     => '08:00 - 22:00 WITA',
    'store_footer'    => '2026 Creative Geek',
    'store_tagline'   => 'Unleash Your Limit.',
    'store_website'   => 'www.creativegeek-idn.com',
    'store_policy'    => 'Spanduk yang telah cetak wajib di ambil'
];

$settings = $defaultSettings;
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) { /* fallback */ }

$order = null;
try {
    if (!empty($order_id) && is_numeric($order_id)) {
        $stmt = $pdo->prepare("
            SELECT o.*, c.name as customer_name, c.phone as customer_phone,
                   u.username as created_by_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN users u ON o.created_by = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([(int)$order_id]);
    } else {
        $search = !empty($order_number) ? $order_number : $order_id;
        $stmt = $pdo->prepare("
            SELECT o.*, c.name as customer_name, c.phone as customer_phone,
                   u.username as created_by_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN users u ON o.created_by = u.id
            WHERE o.order_number = ?
        ");
        $stmt->execute([$search]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error query order: ' . $e->getMessage());
}

if (!$order) {
    die('Pesanan tidak ditemukan.');
}

$items = [];
try {
    if ($productTable) {
        $sql = "SELECT oi.*, p.name as product_name, p.material
                FROM order_items oi
                JOIN `$productTable` p ON oi.product_id = p.id
                WHERE oi.order_id = ?";
    } else {
        $sql = "SELECT oi.*, oi.product_name, oi.material
                FROM order_items oi
                WHERE oi.order_id = ?";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$order['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        if (empty($item['product_name'])) $item['product_name'] = 'Produk #' . $item['product_id'];
        if (!isset($item['material'])) $item['material'] = '-';
    }
    unset($item);
} catch (PDOException $e) {
    $items = [];
}

$statusMap = [
    'diterima'   => 'Diproses',
    'finishing'  => 'Finishing',
    'siap_ambil' => 'Selesai',
    'batal'      => 'Batal'
];
$statusText = $statusMap[$order['status']] ?? $order['status'];
$paymentLabel = $order['payment_status'] === 'lunas' ? 'LUNAS' : 'BELUM LUNAS';
$orderDate = date('d/m/Y H:i', strtotime($order['order_date']));

$storeName     = $settings['store_name'] ?? 'CREATIVE GEEK';
$storePhone    = $settings['store_phone'] ?? '0812-4628-4684';
$storeAddress  = $settings['store_address'] ?? 'Jl. El Tari Km.6 Kel. Maubeli, Kefamenanu, NTT';
$storeWhatsapp = $settings['store_whatsapp'] ?? '6281246284684';
$storeHours    = $settings['store_hours'] ?? '08:00 - 22:00 WITA';
$storeFooter   = $settings['store_footer'] ?? '2026 Creative Geek';
$storeTagline  = $settings['store_tagline'] ?? 'Unleash Your Limit.';
$storeWebsite  = $settings['store_website'] ?? 'www.creativegeek-idn.com';
$storePolicy   = $settings['store_policy'] ?? 'Spanduk yang telah cetak wajib di ambil';

$whatsappDisplay = preg_replace('/^62/', '0', $storeWhatsapp);
if (strlen($whatsappDisplay) > 0 && $whatsappDisplay[0] !== '0') {
    $whatsappDisplay = '0' . $whatsappDisplay;
}

$kasir = $order['created_by_name'] ?? '-';
if ($kasir !== '-') $kasir = ucwords(strtolower($kasir));

// QR Code dengan ukuran lebih besar (80x80) agar tidak blur
$qrContent = 'https://' . $_SERVER['HTTP_HOST'] . '/track.php?order=' . urlencode($order['order_number']);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($qrContent);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nota - <?= htmlspecialchars($order['order_number']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            background: #f0f0f0;
            display: flex;
            justify-content: center;
            padding: 10px;
            font-family: 'Courier New', monospace;
        }

        .receipt {
            width: 58mm;
            background: #fff;
            padding: 3px 4mm;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            border-radius: 2px;
            font-size: 12px;
            font-weight: normal;
            line-height: 1.3;
            color: #000;
        }

        .receipt .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 2px;
            margin-bottom: 2px;
        }
        .receipt .store-name {
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 0.5px;
        }
        .receipt .store-tagline {
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 0.3px;
        }
        .receipt .store-info,
        .receipt .store-hours,
        .receipt .order-date {
            font-size: 8px;
            font-weight: bold;
            margin-top: 1px;
        }
        .receipt .order-date {
            margin-top: 1px;
        }

        .receipt .divider {
            border: none;
            border-top: 1px dashed #000;
            margin: 2px 0;
        }

        .receipt .item-table {
            width: 100%;
            font-size: 11px;
            border-collapse: collapse;
            font-weight: normal;
        }
        .receipt .item-table th {
            font-size: 9px;
            text-transform: uppercase;
            border-bottom: 1px solid #ddd;
            padding: 1px 0;
            text-align: left;
            font-weight: bold;
        }
        .receipt .item-table td {
            padding: 0px 0;
            vertical-align: top;
        }
        .receipt .item-table .col-qty {
            width: 15%;
            text-align: center;
        }
        .receipt .item-table .col-name {
            width: 50%;
        }
        .receipt .item-table .col-price {
            width: 35%;
            text-align: right;
        }
        .receipt .item-table .item-material {
            font-size: 8px;
            font-weight: normal;
        }
        .receipt .item-table .item-total td {
            border-top: 1px solid #ddd;
            padding-top: 2px;
            font-weight: bold;
        }
        .receipt .item-table .grand-total td {
            border-top: 2px solid #000;
            padding-top: 2px;
            font-weight: bold;
            font-size: 14px;
        }

        .receipt .qr-container {
            text-align: center;
            margin: 2px 0;
        }
        .receipt .qr-container img {
            width: 80px;   /* diperbesar agar tidak blur */
            height: 80px;
            display: inline-block;
        }
        .receipt .qr-label {
            font-size: 8px;
            font-weight: normal;
            margin-top: 1px;
        }

        .receipt .footer {
            text-align: center;
            border-top: 1px dashed #000;
            padding-top: 2px;
            margin-top: 2px;
        }
        .receipt .thankyou {
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 0.5px;
        }
        .receipt .footer-policy {
            font-size: 9px;
            font-weight: normal;
            margin: 1px 0;
        }
        .receipt .footer-copyright,
        .receipt .website {
            font-size: 9px;
            font-weight: normal;
            line-height: 1.2;
        }
        .receipt .footer-copyright {
            margin-top: 1px;
        }
        .receipt .website {
            margin-top: 1px;
        }
        .receipt .timestamp {
            font-size: 8px;
            font-weight: normal;
            margin-top: 1px;
        }

        .print-btn {
            position: fixed; bottom: 30px; right: 30px;
            background: #1a1a1a; color: #fff; border: none;
            padding: 14px 28px; border-radius: 50px;
            font-size: 16px; font-weight: 600; cursor: pointer;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            transition: all .3s; z-index: 999;
            font-family: 'Inter', sans-serif;
        }
        .print-btn:hover { background: #b8860b; transform: translateY(-2px); }
        .print-btn i { margin-right: 8px; }

        @page {
            size: 58mm auto;
            margin: 0;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
                margin: 0;
            }
            .receipt {
                width: 58mm;
                padding: 3px 4mm;
                box-shadow: none;
                border-radius: 0;
                margin: 0 auto;
                font-size: 12px;
            }
            .print-btn,
            .no-print {
                display: none !important;
            }
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
        }

        @media (max-width: 400px) {
            .receipt {
                width: 100%;
                border-radius: 0;
            }
            .print-btn {
                bottom: 15px;
                right: 15px;
                padding: 12px 20px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>

<div class="receipt" id="receipt">

    <div class="header">
        <div class="store-name"><?= htmlspecialchars($storeName) ?></div>
        <div class="store-tagline"><?= htmlspecialchars($storeTagline) ?></div>
        <div class="store-info"><?= nl2br(htmlspecialchars($storeAddress)) ?></div>
        <div class="store-info">Telp: <?= htmlspecialchars($storePhone) ?> | WA: <?= htmlspecialchars($whatsappDisplay) ?></div>
        <div class="store-hours">Jam: <?= htmlspecialchars($storeHours) ?></div>
        <div class="order-date"><?= $orderDate ?></div>
    </div>

    <hr class="divider">

    <table class="item-table">
        <thead>
            <tr>
                <th class="col-qty">Qty</th>
                <th class="col-name">Item</th>
                <th class="col-price">Harga</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="3" style="text-align:center; padding:3px 0;">Tidak ada item</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item):
                    $itemTotal = $item['qty'] * $item['price_per_unit'];
                ?>
                <tr>
                    <td class="col-qty"><?= (int)$item['qty'] ?>×</td>
                    <td class="col-name">
                        <?= htmlspecialchars($item['product_name']) ?>
                        <?php if (!empty($item['material']) && $item['material'] !== '-'): ?>
                        <div class="item-material"><?= htmlspecialchars($item['material']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="col-price">Rp<?= number_format($itemTotal, 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>

            <tr class="item-total">
                <td colspan="2" style="text-align:right; font-weight:bold;">Subtotal</td>
                <td class="col-price">Rp<?= number_format($order['subtotal'] ?? 0, 0, ',', '.') ?></td>
            </tr>
            <?php if (($order['discount'] ?? 0) > 0): ?>
            <tr>
                <td colspan="2" style="text-align:right; font-weight:bold;">Diskon</td>
                <td class="col-price">-Rp<?= number_format($order['discount'], 0, ',', '.') ?></td>
            </tr>
            <?php endif; ?>
            <?php if (($order['design_fee'] ?? 0) > 0): ?>
            <tr>
                <td colspan="2" style="text-align:right; font-weight:bold;">Jasa Desain</td>
                <td class="col-price">+Rp<?= number_format($order['design_fee'], 0, ',', '.') ?></td>
            </tr>
            <?php endif; ?>
            <tr class="grand-total">
                <td colspan="2" style="text-align:right; font-size:14px;">TOTAL&nbsp;&nbsp;&nbsp;</td>
                <td class="col-price" style="font-size:14px;">Rp<?= number_format($order['grand_total'] ?? 0, 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>

    <hr class="divider">

    <?php if (!empty($order['notes'])): ?>
    <div style="margin:2px 0; font-size:10px;">
        <span style="font-weight:bold;">Catatan:</span> <?= htmlspecialchars($order['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- QR Code dengan ukuran 80x80 agar tidak blur -->
    <div class="qr-container">
        <img src="<?= $qrUrl ?>" alt="QR">
        <div class="qr-label">Scan untuk cek status</div>
    </div>

    <div class="footer">
        <div class="thankyou">TERIMA KASIH</div>
        <div class="footer-policy"><?= htmlspecialchars($storePolicy) ?></div>
        <div class="footer-copyright"><?= htmlspecialchars($storeFooter) ?></div>
        <div class="website"><?= htmlspecialchars($storeWebsite) ?></div>
        <div class="timestamp"><?= date('d/m/Y H:i:s') ?> | #<?= $order['id'] ?></div>
    </div>

</div>

<button class="print-btn no-print" onclick="printReceipt()">
    <i class="fas fa-print"></i> Cetak Nota
</button>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<script>
    function printReceipt() { window.print(); }
    <?php if (isset($_GET['auto']) && $_GET['auto'] == 1): ?>
    window.onload = function() { setTimeout(function() { window.print(); }, 500); };
    <?php endif; ?>
</script>

</body>
</html>