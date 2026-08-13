<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$search = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$searchParam = '%' . $search . '%';
$conditions = [];
$params = [];

if ($search) {
    $conditions[] = "(c.name LIKE :search OR c.phone LIKE :search)";
    $params[':search'] = $searchParam;
}
if ($date_from && $date_to) {
    $conditions[] = "DATE(o.order_date) BETWEEN :date_from AND :date_to";
    $params[':date_from'] = $date_from;
    $params[':date_to'] = $date_to;
} elseif ($date_from) {
    $conditions[] = "DATE(o.order_date) >= :date_from";
    $params[':date_from'] = $date_from;
} elseif ($date_to) {
    $conditions[] = "DATE(o.order_date) <= :date_to";
    $params[':date_to'] = $date_to;
}

$sql = "SELECT o.*, c.name as customer_name, c.phone as customer_phone
        FROM orders o 
        JOIN customers c ON o.customer_id = c.id";
if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY o.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

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
$paymentLabels = [
    'belum' => 'Belum Lunas',
    'lunas' => 'Lunas'
];

// Total pendapatan & piutang
$totalLunas = 0;
$totalPiutang = 0;
foreach ($orders as $order) {
    if ($order['payment_status'] === 'lunas') {
        $totalLunas += $order['grand_total'];
    } else {
        $totalPiutang += $order['grand_total'];
    }
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Laporan Pesanan');

$row = 1;
$sheet->mergeCells('A1:L1');
$sheet->setCellValue('A1', 'CREATIVE GEEK');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$row = 2;
$sheet->mergeCells('A2:L2');
$sheet->setCellValue('A2', 'Jl. El Tari Km.6 Kel. Maubeli, Kefamenanu, NTT');
$sheet->getStyle('A2')->getFont()->setSize(10);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$row = 3;
$sheet->mergeCells('A3:L3');
$sheet->setCellValue('A3', 'Telp. 0812-4628-4684 | Email: creativegeekhq@gmail.com');
$sheet->getStyle('A3')->getFont()->setSize(10);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$row = 4;
$sheet->mergeCells('A4:L4');
$sheet->setCellValue('A4', '');
$row = 5;
$sheet->mergeCells('A5:L5');
$sheet->setCellValue('A5', 'LAPORAN PESANAN (DETAIL PRODUK)');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 6;
$sheet->mergeCells('A6:L6');
$periode = 'Semua Periode';
if ($date_from && $date_to) {
    $periode = date('d-m-Y', strtotime($date_from)) . ' s/d ' . date('d-m-Y', strtotime($date_to));
} elseif ($date_from) {
    $periode = 'Mulai ' . date('d-m-Y', strtotime($date_from));
} elseif ($date_to) {
    $periode = 'Sampai ' . date('d-m-Y', strtotime($date_to));
}
$sheet->setCellValue('A6', 'Periode: ' . $periode);
$sheet->getStyle('A6')->getFont()->setSize(10);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 7;
$headers = ['No. Pesanan', 'Pelanggan', 'Tanggal Order', 'Nama Produk', 'Qty', 'Harga Satuan', 'Total Item', 'Status', 'Pembayaran', 'Metode Bayar', 'Grand Total', 'Catatan'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $row, $header);
    $col++;
}
$sheet->getStyle('A7:L7')->getFont()->setBold(true)->setSize(10);
$sheet->getStyle('A7:L7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF5A623');
$sheet->getStyle('A7:L7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A7:L7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

$row = 8;
foreach ($orders as $order) {
    $items = $itemsByOrder[$order['id']] ?? [];
    $firstItem = true;
    foreach ($items as $item) {
        $sheet->setCellValue('A' . $row, $order['order_number']);
        $sheet->setCellValue('B' . $row, $order['customer_name']);
        $sheet->setCellValue('C' . $row, date('d-m-Y', strtotime($order['order_date'])));
        $sheet->setCellValue('D' . $row, $item['product_name']);
        $sheet->setCellValue('E' . $row, $item['qty']);
        $sheet->setCellValue('F' . $row, $item['price_per_unit']);
        $sheet->setCellValue('G' . $row, $item['total']);
        if ($firstItem) {
            $sheet->setCellValue('H' . $row, $statusLabels[$order['status']] ?? $order['status']);
            $sheet->setCellValue('I' . $row, $paymentLabels[$order['payment_status']] ?? $order['payment_status']);
            $sheet->setCellValue('J' . $row, $order['payment_method'] ?? '-');
            $sheet->setCellValue('K' . $row, $order['grand_total']);
            $sheet->setCellValue('L' . $row, $order['notes'] ?? '');
            $firstItem = false;
        } else {
            $sheet->setCellValue('H' . $row, '');
            $sheet->setCellValue('I' . $row, '');
            $sheet->setCellValue('J' . $row, '');
            $sheet->setCellValue('K' . $row, '');
            $sheet->setCellValue('L' . $row, '');
        }
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $row++;
    }
}

$row++;
$row++;
$sheet->setCellValue('A' . $row, 'Total Pendapatan (Lunas)');
$sheet->mergeCells('A' . $row . ':J' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->setCellValue('K' . $row, $totalLunas);
$sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
$sheet->getStyle('K' . $row)->getFont()->setBold(true);

$row++;
$sheet->setCellValue('A' . $row, 'Total Piutang (Belum Lunas)');
$sheet->mergeCells('A' . $row . ':J' . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->setCellValue('K' . $row, $totalPiutang);
$sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
$sheet->getStyle('K' . $row)->getFont()->setBold(true);

$row += 2;
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->setCellValue('A' . $row, 'Dicetak pada: ' . date('d-m-Y H:i:s') . ' | Creative Geek - Maubeli, Kefamenanu, NTT');
$sheet->getStyle('A' . $row)->getFont()->setSize(9)->setItalic(true);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$dataStart = 7;
$dataEnd = $row - 1;
$sheet->getStyle('A' . $dataStart . ':L' . ($row - 2))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A' . $dataStart . ':L' . ($row - 2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

$sheet->getColumnDimension('A')->setWidth(16);
$sheet->getColumnDimension('B')->setWidth(20);
$sheet->getColumnDimension('C')->setWidth(14);
$sheet->getColumnDimension('D')->setWidth(30);
$sheet->getColumnDimension('E')->setWidth(8);
$sheet->getColumnDimension('F')->setWidth(14);
$sheet->getColumnDimension('G')->setWidth(14);
$sheet->getColumnDimension('H')->setWidth(16);
$sheet->getColumnDimension('I')->setWidth(14);
$sheet->getColumnDimension('J')->setWidth(14);
$sheet->getColumnDimension('K')->setWidth(16);
$sheet->getColumnDimension('L')->setWidth(20);

$writer = new Xlsx($spreadsheet);
$filename = 'Laporan_Pesanan_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;
