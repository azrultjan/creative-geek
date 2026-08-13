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

// Set header CSV dengan UTF-8 BOM
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="laporan_pesanan_' . date('Ymd') . '.csv"');

$output = fopen('php://output', 'w');

// BOM untuk UTF-8 (agar Excel baca)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header dengan separator tab
fputcsv($output, [
    'No. Pesanan',
    'Pelanggan',
    'Tanggal Order',
    'Status',
    'Pembayaran',
    'Metode Bayar',
    'Subtotal',
    'Diskon',
    'Grand Total',
    'Catatan'
], "\t");

// Data per baris
foreach ($orders as $order) {
    fputcsv($output, [
        $order['order_number'],
        $order['customer_name'],
        date('d-m-Y', strtotime($order['order_date'])),
        getStatusLabel($order['status']),
        $order['payment_status'] == 'lunas' ? 'Lunas' : 'Belum Lunas',
        $order['payment_method'] ?: '-',
        'Rp ' . number_format($order['subtotal'], 0, ',', '.'),
        'Rp ' . number_format($order['discount'], 0, ',', '.'),
        'Rp ' . number_format($order['grand_total'], 0, ',', '.'),
        $order['notes'] ?: '-'
    ], "\t");
}

fclose($output);
exit;
