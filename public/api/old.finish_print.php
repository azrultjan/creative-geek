<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$orderNumber = trim($input['order_number'] ?? '');

if (empty($orderNumber)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_number required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT o.id, o.customer_id, o.status, c.phone, c.name, o.order_number
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        WHERE o.order_number = ?
    ");
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    if ($order['status'] !== 'finishing') {
        echo json_encode(['success' => false, 'message' => 'Status bukan finishing, saat ini: ' . $order['status']]);
        exit;
    }

    $pdo->prepare("
        UPDATE orders 
        SET status = 'siap_ambil',
            progress = 100,
            remaining_time = '00:00:00',
            print_status = 'Done',
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$order['id']]);

    // ===== KIRIM WA =====
    if (!empty($order['phone'])) {
        $link = "https://cloud.creativegeek-idn.com/index.php?order=" . urlencode($orderNumber);
        $msg = "Halo " . $order['name'] . ".\n\n";
        $msg .= "Pesanan #" . $orderNumber . " sudah SELESAI dan siap diambil!\n\n";
        $msg .= "Cek detail melalui e-receipt:\n";
        $msg .= $link . "\n\n";
        $msg .= "Terima kasih telah bertransaksi di Creative Geek =)";
        sendWhatsAppNotification($order['phone'], $msg);
    }

    echo json_encode(['success' => true, 'message' => 'Print finished']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}