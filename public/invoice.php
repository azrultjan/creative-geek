<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../includes/functions.php';

$id = $_GET['id'] ?? 0;
if (!$id) die('ID pesanan tidak valid.');

$stmt = $pdo->prepare("SELECT o.*, c.name as customer_name, c.address, c.phone, c.email FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) die('Pesanan tidak ditemukan.');

$stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt_items->execute([$id]);
$items = $stmt_items->fetchAll();

$company = getCompanyData();

// Format nomor invoice: INV-YYYYMMDD-XXX
$invoiceNumber = 'INV-' . date('Ymd', strtotime($order['order_date'])) . '-' . str_pad($order['id'], 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - Creative Geek</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f4f4; font-family: Arial, Helvetica, sans-serif; padding: 20px; }
        .invoice-box {
            max-width: 900px;
            margin: auto;
            padding: 30px 35px;
            background: #ffffff;
            border: 1px solid #ddd;
            box-shadow: 0 0 15px rgba(0,0,0,0.08);
        }
        .invoice-box table { width: 100%; border-collapse: collapse; }
        .invoice-box table td { padding: 6px 4px; vertical-align: top; }
        .invoice-box .header-title {
            font-size: 32px;
            font-weight: bold;
            color: #F5A623;
            letter-spacing: 1px;
        }
        .invoice-box .company-details {
            font-size: 13px;
            color: #555;
            line-height: 1.5;
            margin-top: 4px;
        }
        .invoice-box .invoice-number {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        .invoice-box .invoice-date {
            font-size: 13px;
            color: #888;
        }
        .invoice-box .section-title {
            font-weight: bold;
            font-size: 15px;
            margin-bottom: 4px;
        }
        .invoice-box .customer-details {
            font-size: 14px;
            color: #333;
            line-height: 1.6;
        }
        .invoice-box .payment-status {
            font-weight: bold;
            font-size: 15px;
        }
        .invoice-box .payment-status.lunas { color: #2e7d32; }
        .invoice-box .payment-status.belum { color: #c62828; }

        /* Tabel item */
        .invoice-box .item-table {
            margin-top: 20px;
            border-collapse: collapse;
            width: 100%;
        }
        .invoice-box .item-table th {
            background: #f0f0f0;
            font-weight: bold;
            font-size: 13px;
            text-transform: uppercase;
            padding: 8px 6px;
            border-bottom: 2px solid #ccc;
            text-align: left;
        }
        .invoice-box .item-table td {
            padding: 8px 6px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        .invoice-box .item-table .text-center { text-align: center; }
        .invoice-box .item-table .text-right { text-align: right; }
        .invoice-box .item-table .total-row td {
            border-top: 2px solid #000;
            font-weight: bold;
            font-size: 16px;
            padding-top: 10px;
        }
        .invoice-box .item-table .total-label {
            text-align: right;
            padding-right: 20px;
        }

        /* Syarat & Ketentuan */
        .invoice-box .terms {
            margin-top: 25px;
            padding-top: 18px;
            border-top: 1px solid #ddd;
            font-size: 13px;
            color: #555;
            line-height: 1.6;
        }
        .invoice-box .terms strong {
            color: #333;
        }
        .invoice-box .payment-info {
            background: #fafafa;
            padding: 12px 16px;
            border-radius: 4px;
            margin-top: 10px;
            border-left: 3px solid #F5A623;
        }
        .invoice-box .payment-info .bank-detail {
            font-size: 14px;
            font-weight: bold;
        }
        .invoice-box .footer-note {
            margin-top: 12px;
            font-size: 12px;
            color: #aaa;
            text-align: center;
        }

        /* Print style */
        @media print {
            body { background: #fff; padding: 0; }
            .invoice-box { border: none; box-shadow: none; max-width: 100%; padding: 20px 25px; }
            .no-print { display: none !important; }
            .invoice-box .item-table th { background: #e0e0e0; }
        }

        .btn-print {
            background: #F5A623;
            color: #000;
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            margin-top: 15px;
            transition: background 0.2s;
        }
        .btn-print:hover { background: #e09515; }
        .btn-back {
            margin-left: 12px;
            color: #555;
            text-decoration: none;
            font-size: 14px;
        }
        .btn-back:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="invoice-box">
    <!-- Tombol aksi (tidak muncul di print) -->
    <div class="no-print" style="text-align: right; margin-bottom: 12px;">
        <button onclick="window.print()" class="btn-print">🖨️ Print / PDF</button>
        <a href="orders.php" class="btn-back">← Kembali ke Daftar</a>
    </div>

    <!-- HEADER -->
    <table>
        <tr>
            <td style="width: 60%;">
                <div class="header-title">CREATIVE GEEK</div>
                <div class="company-details">
                    <?= $company['address'] ?><br>
                    Telp: <?= $company['phone'] ?> &nbsp;|&nbsp; Email: creativegeekhq@gmail.com
                </div>
            </td>
            <td style="width: 40%; text-align: right;">
                <div class="invoice-number">INVOICE</div>
                <div style="font-size: 15px; font-weight: bold; color: #333;"><?= $invoiceNumber ?></div>
                <div class="invoice-date">Tanggal: <?= date('d-m-Y', strtotime($order['order_date'])) ?></div>
            </td>
        </tr>
    </table>

    <!-- TAGIHAN UNTUK & STATUS -->
    <table style="margin-top: 20px;">
        <tr>
            <td style="width: 55%;">
                <div class="section-title">Tagihan Untuk</div>
                <div class="customer-details">
                    <strong><?= htmlspecialchars($order['customer_name']) ?></strong><br>
                    <?= htmlspecialchars($order['address']) ?><br>
                    <?php if ($order['phone']) echo 'Telp: ' . htmlspecialchars($order['phone']) . '<br>'; ?>
                    <?php if ($order['email']) echo 'Email: ' . htmlspecialchars($order['email']); ?>
                </div>
            </td>
            <td style="width: 45%; text-align: right;">
                <div class="section-title">Status Pembayaran</div>
                <div class="payment-status <?= $order['payment_status'] == 'lunas' ? 'lunas' : 'belum' ?>">
                    <?= $order['payment_status'] == 'lunas' ? '✅ LUNAS' : '❌ BELUM LUNAS' ?>
                </div>
                <div style="font-size: 13px; color: #888; margin-top: 4px;">
                    Metode: <?= $order['payment_method'] ?: '-' ?>
                </div>
            </td>
        </tr>
    </table>

    <!-- TABEL ITEM -->
    <table class="item-table">
        <thead>
            <tr>
                <th style="width: 40%;">NO KEGIATAN</th>
                <th style="width: 15%; text-align: center;">VOLUME</th>
                <th style="width: 15%; text-align: center;">JUMLAH BARANG</th>
                <th style="width: 15%; text-align: right;">HARGA SATUAN</th>
                <th style="width: 15%; text-align: right;">JUMLAH</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['product_name']) ?></td>
                <td class="text-center"><?= $item['qty'] ?></td>
                <td class="text-center"><?= $item['qty'] ?></td>
                <td class="text-right">Rp <?= number_format($item['price_per_unit'], 0, ',', '.') ?></td>
                <td class="text-right">Rp <?= number_format($item['total'], 0, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="4" class="total-label">JUMLAH</td>
                <td class="text-right">Rp <?= number_format($order['grand_total'], 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>

    <!-- SYARAT & KETENTUAN + METODE PEMBAYARAN -->
    <div class="terms">
        <strong>Syarat & Ketentuan</strong>
        <p>Terima kasih atas kepercayaan Anda telah menggunakan jasa dan/atau melakukan pemesanan di CREATIVE GEEK</p>

        <div class="payment-info">
            <strong>Metode Pembayaran</strong><br>
            <span class="bank-detail">Bank BRI</span><br>
            466801051176539 a.n Azrul Tjan<br>
            <span style="font-size: 13px; color: #555;">
                Invoice ini aktif selama satu minggu. Mohon melakukan pembayaran dengan segera untuk menghindari interupsi terhadap layanan Anda.
            </span><br>
            <span style="font-size: 13px; color: #555;">
                Setelah melakukan pembayaran, silahkan melakukan konfirmasi melalui WhatsApp ke nomor <?= $company['phone'] ?> disertai dengan bukti transfer.
            </span>
        </div>

        <div class="footer-note">
            Dicetak pada: <?= date('d-m-Y H:i:s') ?>
        </div>
    </div>
</div>
</body>
</html>
