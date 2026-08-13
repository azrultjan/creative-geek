<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';

// Set header untuk download Excel (CSV)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Daftar_Pesanan_' . date('Ymd_His') . '.csv"');

// Buka output stream
$output = fopen('php://output', 'w');

// Tulis BOM untuk UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header kolom
fputcsv($output, [
    'No. Pesanan',
    'Tanggal',
    'Pelanggan',
    'Telepon',
    'Item (Nama, Qty, Harga)',
    'Subtotal',
    'Diskon',
    'Jasa Desain',
    'Grand Total',
    'Status'
]);

// Ambil parameter search
$search = trim($_GET['search'] ?? '');
$searchParam = '%' . $search . '%';

$sql = "SELECT o.*, c.name as customer_name, c.phone as customer_phone
        FROM orders o 
        JOIN customers c ON o.customer_id = c.id";
if ($search) {
    $sql .= " WHERE c.name LIKE :search OR c.phone LIKE :search";
}
$sql .= " ORDER BY o.id DESC";

$stmt = $pdo->prepare($sql);
if ($search) {
    $stmt->execute([':search' => $searchParam]);
} else {
    $stmt->execute();
}
$orders = $stmt->fetchAll();

// Ambil semua item
$allItems = $pdo->query("SELECT * FROM order_items ORDER BY order_id, id")->fetchAll();
$itemsByOrder = [];
foreach ($allItems as $item) {
    $itemsByOrder[$item['order_id']][] = $item;
}

$statusLabels = [
    'diterima' => 'Order Masuk',
    'desain' => 'Proses Desain',
    'cetak' => 'Proses Cetak',
    'finishing' => 'Finishing',
    'siap_ambil' => 'Siap Ambil'
];

foreach ($orders as $row) {
    $items = $itemsByOrder[$row['id']] ?? [];
    $itemStr = [];
    foreach ($items as $item) {
        $itemStr[] = $item['product_name'] . ' (' . $item['qty'] . 'x Rp' . number_format($item['price_per_unit'], 0, ',', '.') . ')';
    }
    $itemCombined = implode('; ', $itemStr);

    fputcsv($output, [
        $row['order_number'],
        date('d/m/Y H:i', strtotime($row['order_date'])),
        $row['customer_name'],
        $row['customer_phone'],
        $itemCombined,
        $row['subtotal'],
        $row['discount'],
        $row['design_fee'] ?? 0,
        $row['grand_total'],
        $statusLabels[$row['status']] ?? $row['status']
    ]);
}

fclose($output);
exit;
