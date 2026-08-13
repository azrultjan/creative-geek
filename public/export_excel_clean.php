<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Ambil filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$payment_filter = isset($_GET['payment']) ? $_GET['payment'] : '';
$date_start = isset($_GET['date_start']) ? $_GET['date_start'] : '';
$date_end = isset($_GET['date_end']) ? $_GET['date_end'] : '';

// Bangun query
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

// Hitung total
$totalRevenue = array_sum(array_column($orders, 'grand_total'));

// Header untuk Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="Laporan_Pesanan_' . date('Ymd') . '.xls"');
header('Cache-Control: max-age=0');

// Mulai HTML
echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" 
      xmlns:x="urn:schemas-microsoft-com:office:excel" 
      xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta charset="UTF-8">';
echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Laporan</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
echo '<style>
    /* Reset */
    body { font-family: "Calibri", Arial, sans-serif; font-size: 11pt; color: #000; }
    .header-title { font-size: 24pt; font-weight: bold; color: #F5A623; font-family: Arial; }
    .header-sub { font-size: 10pt; color: #555; }
    .header-date { font-size: 10pt; color: #666; }
    
    /* Tabel */
    table { border-collapse: collapse; width: 100%; margin-top: 12px; }
    th { 
        background-color: #F5A623; 
        color: #000; 
        font-weight: bold; 
        font-size: 10pt;
        border: 1px solid #000; 
        padding: 6px 6px; 
        text-align: center;
        white-space: nowrap;
    }
    td { 
        border: 1px solid #333; 
        padding: 5px 6px; 
        font-size: 10pt;
        vertical-align: middle;
    }
    .text-left { text-align: left; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    
    /* Status & Payment */
    .lunas { color: #2e7d32; font-weight: bold; }
    .belum { color: #c62828; font-weight: bold; }
    .status-badge { font-weight: bold; }
    
    /* Total row */
    .total-row td { 
        border-top: 2px solid #000; 
        font-weight: bold; 
        font-size: 11pt;
        padding-top: 8px;
    }
    .total-label { text-align: right; padding-right: 20px; }
    
    /* Footer */
    .footer { margin-top: 15px; font-size: 9pt; color: #888; border-top: 1px solid #ccc; padding-top: 8px; text-align: center; }
    
    /* Lebar kolom fix (pakai width dalam pt) */
    .col-order { width: 120pt; }
    .col-customer { width: 130pt; }
    .col-date { width: 100pt; }
    .col-status { width: 80pt; }
    .col-payment { width: 90pt; }
    .col-method { width: 80pt; }
    .col-subtotal { width: 90pt; }
    .col-discount { width: 80pt; }
    .col-grandtotal { width: 100pt; }
    .col-notes { width: 120pt; }
</style>';
echo '</head><body>';

// ========== HEADER ==========
echo '<table style="border: none; margin-bottom: 6px;">';
echo '<tr>';
echo '<td style="border: none; width: 60%;">';
echo '<div class="header-title">CREATIVE GEEK</div>';
echo '<div class="header-sub">Jl. El Tari Km.6 Kel. Maubeli, Kec. Kota Kefamenanu, Kab. Timor Tengah Utara</div>';
echo '<div class="header-sub">Telp. 0812-4628-4684 | Email: creativegeekhq@gmail.com</div>';
echo '</td>';
echo '<td style="border: none; width: 40%; text-align: right;">';
echo '<div style="font-size: 18pt; font-weight: bold;">LAPORAN PESANAN</div>';
echo '<div class="header-date">Periode: ' . ($date_start ? date('d-m-Y', strtotime($date_start)) : 'Awal') . ' s.d ' . ($date_end ? date('d-m-Y', strtotime($date_end)) : 'Sekarang') . '</div>';
echo '<div class="header-date">Dicetak: ' . date('d-m-Y H:i:s') . '</div>';
echo '</td>';
echo '</tr>';
echo '</table>';

// ========== TABEL ==========
echo '<table>';
echo '<thead>';
echo '<tr>';
echo '<th class="col-order">No. Pesanan</th>';
echo '<th class="col-customer">Pelanggan</th>';
echo '<th class="col-date">Tanggal Order</th>';
echo '<th class="col-status">Status</th>';
echo '<th class="col-payment">Pembayaran</th>';
echo '<th class="col-method">Metode Bayar</th>';
echo '<th class="col-subtotal">Subtotal</th>';
echo '<th class="col-discount">Diskon</th>';
echo '<th class="col-grandtotal">Grand Total</th>';
echo '<th class="col-notes">Catatan</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

foreach ($orders as $order) {
    $statusLabel = getStatusLabel($order['status']);
    $paymentLabel = $order['payment_status'] == 'lunas' ? 'Lunas' : 'Belum Lunas';
    $paymentClass = $order['payment_status'] == 'lunas' ? 'lunas' : 'belum';
    $dateFormatted = date('d F Y', strtotime($order['order_date']));
    echo '<tr>';
    echo '<td class="text-center">' . $order['order_number'] . '</td>';
    echo '<td class="text-left">' . htmlspecialchars($order['customer_name']) . '</td>';
    echo '<td class="text-center">' . $dateFormatted . '</td>';
    echo '<td class="text-center"><span class="status-badge">' . $statusLabel . '</span></td>';
    echo '<td class="text-center"><span class="' . $paymentClass . '">' . $paymentLabel . '</span></td>';
    echo '<td class="text-center">' . ($order['payment_method'] ?: '-') . '</td>';
    echo '<td class="text-right">Rp ' . number_format($order['subtotal'], 0, ',', '.') . '</td>';
    echo '<td class="text-right">Rp ' . number_format($order['discount'], 0, ',', '.') . '</td>';
    echo '<td class="text-right" style="font-weight:bold;">Rp ' . number_format($order['grand_total'], 0, ',', '.') . '</td>';
    echo '<td class="text-left">' . ($order['notes'] ?: '-') . '</td>';
    echo '</tr>';
}

// ========== TOTAL ==========
echo '<tr class="total-row">';
echo '<td colspan="8" class="total-label">TOTAL (' . count($orders) . ' Pesanan)</td>';
echo '<td class="text-right">Rp ' . number_format($totalRevenue, 0, ',', '.') . '</td>';
echo '<td></td>';
echo '</tr>';

echo '</tbody></table>';

// ========== FOOTER ==========
echo '<div class="footer">';
echo 'Creative Geek &bull; Jl. El Tari Km.6 Maubeli, Kefamenanu &bull; Telp. 0812-4628-4684 &bull; Email: creativegeekhq@gmail.com';
echo '</div>';

echo '</body></html>';
exit;
