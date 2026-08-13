<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../includes/functions.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$payment_filter = isset($_GET['payment']) ? $_GET['payment'] : '';
$date_start = isset($_GET['date_start']) ? $_GET['date_start'] : '';
$date_end = isset($_GET['date_end']) ? $_GET['date_end'] : '';

$sql = "SELECT o.*, c.name as customer_name 
        FROM orders o 
        JOIN customers c ON o.customer_id = c.id 
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (o.order_number LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $statuses = array_map('trim', explode(',', $status_filter));
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $sql .= " AND o.status IN ($placeholders)";
    $params = array_merge($params, $statuses);
}

if (!empty($payment_filter)) {
    $sql .= " AND o.payment_status = ?";
    $params[] = $payment_filter;
}

if (!empty($date_start)) {
    $sql .= " AND DATE(o.order_date) >= ?";
    $params[] = $date_start;
}
if (!empty($date_end)) {
    $sql .= " AND DATE(o.order_date) <= ?";
    $params[] = $date_end;
}

$sql .= " ORDER BY o.order_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

if (count($orders) == 0) {
    die('Tidak ada data untuk diekspor.');
}

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="laporan_pesanan_' . date('Ymd') . '.xls"');
header('Cache-Control: max-age=0');

$totalData = count($orders);
$totalRevenue = array_sum(array_column($orders, 'grand_total'));
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" 
      xmlns:x="urn:schemas-microsoft-com:office:excel" 
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
    <x:ExcelWorkbook>
        <x:ExcelWorksheets>
            <x:ExcelWorksheet>
                <x:Name>Laporan Pesanan</x:Name>
                <x:WorksheetOptions>
                    <x:DisplayGridlines/>
                </x:WorksheetOptions>
            </x:ExcelWorksheet>
        </x:ExcelWorksheets>
    </x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
    /* Reset & Font */
    body { font-family: "Calibri", Arial, sans-serif; font-size: 11px; color: #000; }
    table { border-collapse: collapse; width: 100%; }
    th {
        background-color: #F5A623;
        color: #000;
        font-weight: bold;
        font-size: 11px;
        border: 1px solid #000;
        padding: 6px 8px;
        text-align: center;
        vertical-align: middle;
    }
    td {
        border: 1px solid #333;
        padding: 5px 8px;
        font-size: 11px;
        vertical-align: middle;
        white-space: nowrap;
    }
    .text-left { text-align: left; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .status-badge { font-weight: bold; }
    .lunas { color: #2e7d32; font-weight: bold; }
    .belum { color: #c62828; font-weight: bold; }
    .total-row td { border-top: 2px solid #000; font-weight: bold; font-size: 12px; padding-top: 8px; }
    .total-label { text-align: right; padding-right: 20px; }
    .footer { margin-top: 15px; font-size: 10px; color: #888; border-top: 1px solid #ccc; padding-top: 8px; text-align: center; }
    .col-order { width: 12%; }
    .col-customer { width: 15%; }
    .col-date { width: 10%; }
    .col-status { width: 9%; }
    .col-payment { width: 9%; }
    .col-method { width: 9%; }
    .col-subtotal { width: 10%; }
    .col-discount { width: 8%; }
    .col-grandtotal { width: 10%; }
    .col-notes { width: 18%; white-space: normal; word-wrap: break-word; }
</style>
</head>
<body>
<table style="border: none; width: 100%; margin-bottom: 6px;">
    <tr>
        <td style="border: none; width: 60%;">
            <div style="font-size: 22px; font-weight: bold; color: #F5A623;">CREATIVE GEEK</div>
            <div style="font-size: 12px; color: #555;">Jl. El Tari Km.6 Kel. Maubeli, Kefamenanu, NTT</div>
            <div style="font-size: 12px; color: #555;">Telp. 0812-4628-4684 | Email: creativegeekhq@gmail.com</div>
        </td>
        <td style="border: none; width: 40%; text-align: right;">
            <div style="font-size: 16px; font-weight: bold;">LAPORAN PESANAN</div>
            <div style="font-size: 12px; color: #666;">Periode: <?= $date_start ? date('d-m-Y', strtotime($date_start)) : 'Awal' ?> s.d <?= $date_end ? date('d-m-Y', strtotime($date_end)) : 'Sekarang' ?></div>
            <div style="font-size: 12px; color: #666;">Tanggal Cetak: <?= date('d-m-Y H:i:s') ?></div>
        </td>
    </tr>
</table>

<table>
<thead>
    <tr>
        <th class="col-order">No. Pesanan</th>
        <th class="col-customer">Pelanggan</th>
        <th class="col-date">Tanggal Order</th>
        <th class="col-status">Status</th>
        <th class="col-payment">Pembayaran</th>
        <th class="col-method">Metode Bayar</th>
        <th class="col-subtotal">Subtotal</th>
        <th class="col-discount">Diskon</th>
        <th class="col-grandtotal">Grand Total</th>
        <th class="col-notes">Catatan</th>
    </tr>
</thead>
<tbody>
<?php foreach ($orders as $order):
    $statusLabel = getStatusLabel($order['status']);
    $paymentLabel = $order['payment_status'] == 'lunas' ? 'Lunas' : 'Belum Lunas';
    $paymentClass = $order['payment_status'] == 'lunas' ? 'lunas' : 'belum';
?>
    <tr>
        <td class="text-center"><?= $order['order_number'] ?></td>
        <td class="text-left"><?= htmlspecialchars($order['customer_name']) ?></td>
        <td class="text-center"><?= date('d-m-Y', strtotime($order['order_date'])) ?></td>
        <td class="text-center"><span class="status-badge"><?= $statusLabel ?></span></td>
        <td class="text-center"><span class="<?= $paymentClass ?>"><?= $paymentLabel ?></span></td>
        <td class="text-center"><?= $order['payment_method'] ?: '-' ?></td>
        <td class="text-right"><?= number_format($order['subtotal'], 0, ',', '.') ?></td>
        <td class="text-right"><?= number_format($order['discount'], 0, ',', '.') ?></td>
        <td class="text-right" style="font-weight:bold;"><?= number_format($order['grand_total'], 0, ',', '.') ?></td>
        <td class="text-left"><?= $order['notes'] ?: '-' ?></td>
    </tr>
<?php endforeach; ?>
    <tr class="total-row">
        <td colspan="8" class="total-label">TOTAL (<?= $totalData ?> Pesanan)</td>
        <td class="text-right"><?= number_format($totalRevenue, 0, ',', '.') ?></td>
        <td></td>
    </tr>
</tbody>
</table>

<div class="footer">
    Creative Geek &bull; Jl. El Tari Km.6 Maubeli, Kefamenanu &bull; Telp. 0812-4628-4684
</div>
</body>
</html>
<?php
exit;
