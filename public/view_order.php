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

$id = $_GET['id'] ?? 0;
if (!$id) {
    die('ID pesanan tidak valid.');
}

// Ambil data utama pesanan
$stmt = $pdo->prepare("
    SELECT 
        o.*, 
        c.name as customer_name,
        u1.username as created_by_name,
        u2.username as updated_by_name
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    LEFT JOIN users u1 ON o.created_by = u1.id
    LEFT JOIN users u2 ON o.updated_by = u2.id
    WHERE o.id = ?
");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    die('Pesanan tidak ditemukan.');
}

// Ambil item-item pesanan
$stmtItems = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Pesanan #<?= htmlspecialchars($order['order_number'], ENT_QUOTES) ?></title>
    <!-- CSS -->
    <style>
        .detail-container { max-width: 800px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 8px; }
        .info-row { display: flex; padding: 6px 0; border-bottom: 1px solid #eee; }
        .label { font-weight: bold; width: 180px; }
        .value { flex: 1; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <div class="detail-container">
        <h2>Detail Pesanan</h2>
        <div class="info-row">
            <span class="label">No. Pesanan</span>
            <span class="value"><?= htmlspecialchars($order['order_number'], ENT_QUOTES) ?></span>
        </div>
        <div class="info-row">
            <span class="label">Pelanggan</span>
            <span class="value"><?= htmlspecialchars($order['customer_name'], ENT_QUOTES) ?></span>
        </div>
        <div class="info-row">
            <span class="label">Status</span>
            <span class="value"><?= htmlspecialchars($order['status'], ENT_QUOTES) ?></span>
        </div>
        <div class="info-row">
            <span class="label">Status Pembayaran</span>
            <span class="value"><?= htmlspecialchars($order['payment_status'], ENT_QUOTES) ?></span>
        </div>
        <?php if ($order['payment_method']): ?>
        <div class="info-row">
            <span class="label">Metode Bayar</span>
            <span class="value"><?= htmlspecialchars($order['payment_method'], ENT_QUOTES) ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="label">Subtotal</span>
            <span class="value">Rp <?= number_format($order['subtotal'], 0, ',', '.') ?></span>
        </div>
        <div class="info-row">
            <span class="label">Diskon</span>
            <span class="value">Rp <?= number_format($order['discount'], 0, ',', '.') ?></span>
        </div>
        <div class="info-row">
            <span class="label">Biaya Desain</span>
            <span class="value">Rp <?= number_format($order['design_fee'], 0, ',', '.') ?></span>
        </div>
        <div class="info-row">
            <span class="label">Total</span>
            <span class="value"><strong>Rp <?= number_format($order['grand_total'], 0, ',', '.') ?></strong></span>
        </div>
        <div class="info-row">
            <span class="label">Dibuat Oleh</span>
            <span class="value">
                <?= htmlspecialchars($order['created_by_name'] ?? '-', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="info-row">
            <span class="label">Terakhir Diedit Oleh</span>
            <span class="value">
                <?= htmlspecialchars($order['updated_by_name'] ?? '-', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="info-row">
            <span class="label">Catatan</span>
            <span class="value"><?= nl2br(htmlspecialchars($order['notes'] ?? '', ENT_QUOTES)) ?></span>
        </div>

        <h3>Item Pesanan</h3>
        <?php if (count($items) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama Produk</th>
                    <th>Qty</th>
                    <th>Harga Satuan</th>
                    <th>Total</th>
                    <th>Material</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($items as $item): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($item['product_name'], ENT_QUOTES) ?></td>
                    <td><?= $item['qty'] ?></td>
                    <td>Rp <?= number_format($item['price_per_unit'], 0, ',', '.') ?></td>
                    <td>Rp <?= number_format($item['total'], 0, ',', '.') ?></td>
                    <td><?= htmlspecialchars($item['material'] ?? '-', ENT_QUOTES) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p><em>Tidak ada item.</em></p>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <a href="orders.php">Kembali ke Daftar</a>
            <a href="order_form.php?id=<?= $id ?>">Edit Pesanan</a>
        </div>
    </div>
</body>
</html>