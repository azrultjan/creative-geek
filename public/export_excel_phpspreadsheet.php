<?php
ini_set('display_errors', 0);
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../includes/functions.php';

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Color;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$payment_filter = isset($_GET['payment']) ? $_GET['payment'] : '';
$date_start = isset($_GET['date_start']) ? $_GET['date_start'] : '';
$date_end = isset($_GET['date_end']) ? $_GET['date_end'] : '';

$sql = "SELECT o.*, c.name as customer_name, oi.product_name, oi.qty, oi.price_per_unit, oi.total as item_total,
               o.id as order_id
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        JOIN order_items oi ON o.id = oi.order_id
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

$sql .= " ORDER BY c.name ASC, o.order_date ASC, oi.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

if (count($items) == 0) {
    die('Tidak ada data untuk diekspor.');
}

$totalRevenue = 0;
$totalPiutang = 0;
$uniqueOrdersLunas = [];
$uniqueOrdersBelum = [];

foreach ($items as $item) {
    if ($item['payment_status'] == 'lunas') {
        if (!in_array($item['order_id'], $uniqueOrdersLunas)) {
            $uniqueOrdersLunas[] = $item['order_id'];
            $totalRevenue += $item['grand_total'];
        }
    } else {
        if (!in_array($item['order_id'], $uniqueOrdersBelum)) {
            $uniqueOrdersBelum[] = $item['order_id'];
            $totalPiutang += $item['grand_total'];
        }
    }
}

function formatPeriode($start, $end) {
    if (empty($start) && empty($end)) {
        return 'Semua Periode';
    }
    if (empty($start)) {
        return 'Awal s.d ' . date('d F Y', strtotime($end));
    }
    if (empty($end)) {
        return date('d F Y', strtotime($start)) . ' s.d Sekarang';
    }
    return date('d F Y', strtotime($start)) . ' - ' . date('d F Y', strtotime($end));
}

$periode = 'Periode: ' . formatPeriode($date_start, $date_end);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$goldHex = 'F5A623';

// HEADER
$sheet->setCellValue('A1', 'CREATIVE GEEK');
$sheet->mergeCells('A1:L1');
$sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
$sheet->getStyle('A1')->getFont()->getColor()->setARGB('FF000000');

$sheet->setCellValue('A2', 'Jl. El Tari Km.6 Kel. Maubeli, Kefamenanu, NTT');
$sheet->mergeCells('A2:L2');
$sheet->getStyle('A2')->getFont()->setSize(10);

$sheet->setCellValue('A3', 'Telp. 0812-4628-4684 | Email: creativegeekhq@gmail.com');
$sheet->mergeCells('A3:L3');
$sheet->getStyle('A3')->getFont()->setSize(10);

// JUDUL LAPORAN
$row = 5;
$sheet->setCellValue("A$row", 'LAPORAN PESANAN (DETAIL PRODUK)');
$sheet->mergeCells("A$row:L$row");
$sheet->getStyle("A$row")->getFont()->setSize(16)->setBold(true);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row++;
$sheet->setCellValue("A$row", $periode);
$sheet->mergeCells("A$row:L$row");
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A$row")->getFont()->setSize(10)->setItalic(true);

$row += 2;

// HEADER TABEL
$headers = ['No. Pesanan', 'Pelanggan', 'Tanggal Order', 'Nama Produk', 'Qty', 'Harga Satuan', 'Total Item', 'Status', 'Pembayaran', 'Metode Bayar', 'Grand Total', 'Catatan'];
$col = 0;
foreach ($headers as $header) {
    $cell = chr(65 + $col) . $row;
    $sheet->setCellValue($cell, $header);
    $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(10);
    $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID);
    $sheet->getStyle($cell)->getFill()->getStartColor()->setARGB('FF' . $goldHex);
    $sheet->getStyle($cell)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $col++;
}

// === TULIS DATA + GROUPING ===
$row++;
$dataStartRow = $row;

$prevCustomer = null;
$prevOrderId = null;
$customerStartRow = 0;
$orderStartRow = 0;

foreach ($items as $item) {
    $currentCustomer = $item['customer_name'];
    $currentOrderId = $item['order_id'];

    if ($currentCustomer != $prevCustomer && $prevCustomer !== null) {
        if ($customerStartRow > 0) {
            $sheet->mergeCells('B' . $customerStartRow . ':B' . ($row - 1));
            $sheet->getStyle('B' . $customerStartRow . ':B' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B' . $customerStartRow . ':B' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }
        $orderStartRow = 0;
    }

    if ($currentOrderId != $prevOrderId) {
        if ($prevOrderId !== null && $orderStartRow > 0) {
            $sheet->mergeCells('A' . $orderStartRow . ':A' . ($row - 1));
            $sheet->getStyle('A' . $orderStartRow . ':A' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A' . $orderStartRow . ':A' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->mergeCells('C' . $orderStartRow . ':C' . ($row - 1));
            $sheet->getStyle('C' . $orderStartRow . ':C' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('C' . $orderStartRow . ':C' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->mergeCells('H' . $orderStartRow . ':H' . ($row - 1));
            $sheet->getStyle('H' . $orderStartRow . ':H' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('H' . $orderStartRow . ':H' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->mergeCells('I' . $orderStartRow . ':I' . ($row - 1));
            $sheet->getStyle('I' . $orderStartRow . ':I' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('I' . $orderStartRow . ':I' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->mergeCells('J' . $orderStartRow . ':J' . ($row - 1));
            $sheet->getStyle('J' . $orderStartRow . ':J' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('J' . $orderStartRow . ':J' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->mergeCells('K' . $orderStartRow . ':K' . ($row - 1));
            $sheet->getStyle('K' . $orderStartRow . ':K' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K' . $orderStartRow . ':K' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->mergeCells('L' . $orderStartRow . ':L' . ($row - 1));
            $sheet->getStyle('L' . $orderStartRow . ':L' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('L' . $orderStartRow . ':L' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }
        $orderStartRow = $row;
        $prevOrderId = $currentOrderId;
    }

    if ($currentOrderId == $prevOrderId && $row == $orderStartRow) {
        $sheet->setCellValue('A' . $row, $item['order_number']);
        $sheet->setCellValue('C' . $row, date('d-m-Y', strtotime($item['order_date'])));
        $sheet->setCellValue('H' . $row, getStatusLabel($item['status']));
        $sheet->setCellValue('I' . $row, $item['payment_status'] == 'lunas' ? 'Lunas' : 'Belum Lunas');
        $sheet->setCellValue('J' . $row, $item['payment_method'] ?: '-');
        $sheet->setCellValue('K' . $row, $item['grand_total']);
        $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('L' . $row, $item['notes'] ?: '-');
    } else {
        if ($row != $orderStartRow) {
            $sheet->setCellValue('A' . $row, '');
            $sheet->setCellValue('C' . $row, '');
            $sheet->setCellValue('H' . $row, '');
            $sheet->setCellValue('I' . $row, '');
            $sheet->setCellValue('J' . $row, '');
            $sheet->setCellValue('K' . $row, '');
            $sheet->setCellValue('L' . $row, '');
        }
    }

    if ($currentCustomer != $prevCustomer) {
        $sheet->setCellValue('B' . $row, $currentCustomer);
        $customerStartRow = $row;
        $prevCustomer = $currentCustomer;
    } else {
        $sheet->setCellValue('B' . $row, '');
    }

    $col = 3;
    $sheet->setCellValue(chr(65 + $col++) . $row, $item['product_name']);
    $sheet->setCellValue(chr(65 + $col++) . $row, $item['qty']);
    $sheet->setCellValue(chr(65 + $col++) . $row, $item['price_per_unit']);
    $sheet->getStyle(chr(65 + $col - 1) . $row)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->setCellValue(chr(65 + $col++) . $row, $item['item_total']);
    $sheet->getStyle(chr(65 + $col - 1) . $row)->getNumberFormat()->setFormatCode('#,##0');

    if ($item['payment_status'] == 'belum') {
        for ($c = 0; $c < 12; $c++) {
            $sheet->getStyle(chr(65 + $c) . $row)->getFill()->setFillType(Fill::FILL_SOLID);
            $sheet->getStyle(chr(65 + $c) . $row)->getFill()->getStartColor()->setARGB('FFFFCCCC');
        }
    }

    for ($c = 0; $c < 12; $c++) {
        $sheet->getStyle(chr(65 + $c) . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
    $row++;
}

// Merge terakhir
if ($customerStartRow > 0) {
    $sheet->mergeCells('B' . $customerStartRow . ':B' . ($row - 1));
    $sheet->getStyle('B' . $customerStartRow . ':B' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $customerStartRow . ':B' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}
if ($orderStartRow > 0) {
    $sheet->mergeCells('A' . $orderStartRow . ':A' . ($row - 1));
    $sheet->getStyle('A' . $orderStartRow . ':A' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A' . $orderStartRow . ':A' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->mergeCells('C' . $orderStartRow . ':C' . ($row - 1));
    $sheet->getStyle('C' . $orderStartRow . ':C' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('C' . $orderStartRow . ':C' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->mergeCells('H' . $orderStartRow . ':H' . ($row - 1));
    $sheet->getStyle('H' . $orderStartRow . ':H' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('H' . $orderStartRow . ':H' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->mergeCells('I' . $orderStartRow . ':I' . ($row - 1));
    $sheet->getStyle('I' . $orderStartRow . ':I' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('I' . $orderStartRow . ':I' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->mergeCells('J' . $orderStartRow . ':J' . ($row - 1));
    $sheet->getStyle('J' . $orderStartRow . ':J' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('J' . $orderStartRow . ':J' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->mergeCells('K' . $orderStartRow . ':K' . ($row - 1));
    $sheet->getStyle('K' . $orderStartRow . ':K' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('K' . $orderStartRow . ':K' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->mergeCells('L' . $orderStartRow . ':L' . ($row - 1));
    $sheet->getStyle('L' . $orderStartRow . ':L' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('L' . $orderStartRow . ':L' . ($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}

// === TOTAL BARIS (2 baris) ===
// Baris 1: Total Pendapatan (Lunas)
$row++;
$sheet->setCellValue("J$row", 'Total Pendapatan (Lunas)');
$sheet->mergeCells("J$row:K$row");
$sheet->getStyle("J$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("J$row")->getFont()->setBold(true)->setSize(11);

$sheet->setCellValue("L$row", $totalRevenue);
$sheet->getStyle("L$row")->getFont()->setBold(true)->setSize(11);
$sheet->getStyle("L$row")->getNumberFormat()->setFormatCode('#,##0');
$sheet->getStyle("J$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);

// Baris 2: Total Piutang (Belum Lunas)
$row++;
$sheet->setCellValue("J$row", 'Total Piutang (Belum Lunas)');
$sheet->mergeCells("J$row:K$row");
$sheet->getStyle("J$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle("J$row")->getFont()->setBold(true)->setSize(11);
$sheet->getStyle("J$row")->getFont()->getColor()->setARGB('FFFF0000'); // red text

$sheet->setCellValue("L$row", $totalPiutang);
$sheet->getStyle("L$row")->getFont()->setBold(true)->setSize(11);
$sheet->getStyle("L$row")->getFont()->getColor()->setARGB('FFFF0000'); // red text
$sheet->getStyle("L$row")->getNumberFormat()->setFormatCode('#,##0');
$sheet->getStyle("J$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);

// === FOOTER ===
$row += 2;
$sheet->setCellValue("A$row", 'Dicetak pada: ' . date('d-m-Y H:i:s') . ' | Creative Geek - Maubeli, Kefamenanu, NTT');
$sheet->mergeCells("A$row:L$row");
$sheet->getStyle("A$row")->getFont()->setSize(9)->setItalic(true);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// === AUTO WIDTH ===
for ($c = 0; $c < 12; $c++) {
    $sheet->getColumnDimension(chr(65 + $c))->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Laporan_Pesanan_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
