<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';

$id = $_GET['id'] ?? 0;
if (!$id) {
    die('Invoice ID tidak ditemukan.');
}

// Ambil data order
$stmt = $pdo->prepare("
    SELECT o.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ?
");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    die('Pesanan tidak ditemukan.');
}

// Ambil item
$stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt_items->execute([$id]);
$items = $stmt_items->fetchAll();

$statusLabels = [
    'diterima' => 'Order Masuk',
    'desain' => 'Proses Desain',
    'cetak' => 'Proses Cetak',
    'finishing' => 'Finishing',
    'siap_ambil' => 'Siap Ambil'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?= htmlspecialchars($order['order_number']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f8f9fa; padding: 40px 20px; }
        .invoice-wrapper { max-width: 900px; margin: 0 auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.08); overflow: hidden; }
        .invoice-header { background: #0a0a0a; padding: 40px 50px 30px; }
        .invoice-body { padding: 40px 50px; }
        .invoice-footer { background: #f8f9fa; padding: 30px 50px; border-top: 2px solid #f5a623; }
        .text-gold { color: #f5a623; }
        .border-gold { border-color: #f5a623; }
        .bg-gold { background: #f5a623; }
        .table-invoice { width: 100%; border-collapse: collapse; }
        .table-invoice th { text-align: left; padding: 12px 10px; border-bottom: 2px solid #e5e7eb; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; font-weight: 600; }
        .table-invoice td { padding: 12px 10px; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .table-invoice tr:last-child td { border-bottom: none; }
        .table-invoice .total-row td { font-weight: 700; font-size: 16px; border-top: 2px solid #e5e7eb; padding-top: 16px; }
        .badge-status { padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-paid { background: #d1fae5; color: #065f46; }
        .badge-unpaid { background: #fee2e2; color: #991b1b; }
        .badge-processing { background: #fef3c7; color: #92400e; }
        .divider { height: 2px; background: linear-gradient(to right, #f5a623, transparent); margin: 24px 0; }
        @media print {
            body { padding: 0; background: white; }
            .invoice-wrapper { box-shadow: none; border-radius: 0; }
            .no-print { display: none !important; }
            .invoice-header { background: #0a0a0a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-gold { background: #f5a623 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .badge-paid, .badge-unpaid, .badge-processing { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .invoice-footer { background: #f8f9fa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<div class="invoice-wrapper">
    <!-- HEADER -->
    <div class="invoice-header">
        <div class="flex justify-between items-start">
            <div>
                <div class="text-3xl font-bold text-gold tracking-tight">CREATIVE GEEK</div>
                <div class="text-gray-400 text-sm mt-1">Jl. El Tari Km.6, Maubeli, Kefamenanu</div>
                <div class="text-gray-400 text-sm">NTT 85613 • Telp. 0812-4628-4684</div>
                <div class="text-gray-400 text-sm">Email: creativegeekhq@gmail.com</div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500 uppercase tracking-wider">Invoice</div>
                <div class="text-2xl font-bold text-white mt-1">#<?= htmlspecialchars($order['order_number']) ?></div>
                <div class="text-gray-400 text-sm mt-1"><?= date('d F Y', strtotime($order['order_date'])) ?></div>
            </div>
        </div>
    </div>

    <!-- BODY -->
    <div class="invoice-body">
        <!-- Info Customer & Status -->
        <div class="flex flex-wrap justify-between gap-6 mb-6">
            <div>
                <div class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Tagihan Untuk</div>
                <div class="text-lg font-bold text-gray-800 mt-1"><?= htmlspecialchars($order['customer_name']) ?></div>
                <?php if ($order['customer_address']): ?>
                    <div class="text-sm text-gray-500"><?= htmlspecialchars($order['customer_address']) ?></div>
                <?php endif; ?>
                <?php if ($order['customer_phone']): ?>
                    <div class="text-sm text-gray-500">📞 <?= htmlspecialchars($order['customer_phone']) ?></div>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Status</div>
                <div class="mt-1">
                    <?php if ($order['payment_status'] === 'lunas'): ?>
                        <span class="badge-status badge-paid">✅ LUNAS</span>
                    <?php else: ?>
                        <span class="badge-status badge-unpaid">❌ BELUM BAYAR</span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 text-xs text-gray-400">
                    Status Pengerjaan: 
                    <span class="font-medium text-gray-700"><?= $statusLabels[$order['status']] ?? $order['status'] ?></span>
                </div>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Tabel Item -->
        <table class="table-invoice">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th>Nama Kegiatan</th>
                    <th style="width:120px;">Volume</th>
                    <th style="width:130px;">Harga Satuan</th>
                    <th style="width:130px; text-align:right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($items as $item): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td>
                        <div class="font-medium text-gray-800"><?= htmlspecialchars($item['product_name']) ?></div>
                        <?php if ($item['material'] && $item['material'] !== '-'): ?>
                            <div class="text-xs text-gray-400">📦 <?= htmlspecialchars($item['material']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $item['qty'] ?> pcs</td>
                    <td>Rp <?= number_format($item['price_per_unit'], 0, ',', '.') ?></td>
                    <td style="text-align:right; font-weight:600;">Rp <?= number_format($item['total'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($items)): ?>
                <tr><td colspan="5" class="text-center text-gray-400 py-4">Tidak ada item.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Summary -->
        <div class="flex justify-end mt-6">
            <div class="w-64">
                <div class="flex justify-between py-2 text-sm">
                    <span class="text-gray-500">Subtotal</span>
                    <span class="font-medium">Rp <?= number_format($order['subtotal'], 0, ',', '.') ?></span>
                </div>
                <?php if ($order['discount'] > 0): ?>
                <div class="flex justify-between py-2 text-sm">
                    <span class="text-gray-500">Diskon</span>
                    <span class="font-medium text-red-500">- Rp <?= number_format($order['discount'], 0, ',', '.') ?></span>
                </div>
                <?php endif; ?>
                <?php if ($order['design_fee'] > 0): ?>
                <div class="flex justify-between py-2 text-sm">
                    <span class="text-gray-500">Jasa Desain</span>
                    <span class="font-medium text-gold">+ Rp <?= number_format($order['design_fee'], 0, ',', '.') ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between py-3 text-lg font-bold border-t-2 border-gray-200 mt-2">
                    <span>Total</span>
                    <span class="text-gold">Rp <?= number_format($order['grand_total'], 0, ',', '.') ?></span>
                </div>
            </div>
        </div>

        <?php if ($order['notes']): ?>
        <div class="mt-4 p-4 bg-gray-50 rounded-lg text-sm text-gray-600 border border-gray-100">
            <span class="font-semibold text-gray-700">📝 Catatan:</span> <?= htmlspecialchars($order['notes']) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- FOOTER -->
    <div class="invoice-footer">
        <div class="flex flex-wrap justify-between gap-4">
            <div>
                <div class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Syarat & Ketentuan</div>
                <div class="text-sm text-gray-600 mt-2 max-w-md leading-relaxed">
                    Terima kasih atas kepercayaan Anda telah menggunakan jasa dan/atau melakukan pemesanan di CREATIVE GEEK.
                </div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Metode Pembayaran</div>
                <div class="text-sm font-medium text-gray-800 mt-1">Bank BRI</div>
                <div class="text-sm text-gray-600">466801051176539 a/n Azrul Tjan</div>
                <div class="text-xs text-gray-400 mt-2">Invoice ini aktif selama 1 minggu</div>
            </div>
        </div>
        <div class="flex justify-between items-center mt-4 pt-4 border-t border-gray-200">
            <div class="text-xs text-gray-400">
                Konfirmasi pembayaran melalui WA: <span class="font-medium text-gray-600">0812-4628-4684</span>
            </div>
            <div class="text-sm font-semibold text-gold">CREATIVE GEEK</div>
        </div>
    </div>
</div>

<!-- Tombol Aksi -->
<div class="max-w-[900px] mx-auto mt-6 flex justify-end gap-3 no-print">
    <button onclick="window.print()" class="px-5 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition text-sm font-medium">
        🖨️ Cetak Invoice
    </button>
    <a href="orders.php" class="px-5 py-2 border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
        ⬅ Kembali
    </a>
</div>

</body>
</html>
