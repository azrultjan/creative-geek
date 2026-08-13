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
    // Cek order
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

    // ===== 1. Ambil satu operator secara RANDOM =====
    $operatorStmt = $pdo->prepare("SELECT id, username FROM users WHERE role = 'operator' ORDER BY RAND() LIMIT 1");
    $operatorStmt->execute();
    $operator = $operatorStmt->fetch();

    $operatorId = $operator ? $operator['id'] : null;
    $operatorName = $operator ? $operator['username'] : 'Tim Creative Geek';

    // ===== 2. Update order =====
    $finishTime = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $pdo->prepare("
        UPDATE orders 
        SET status = 'siap_ambil',
            progress = 100,
            remaining_time = '00:00:00',
            print_status = 'Done',
            updated_at = NOW(),
            finish_time = ?,
            notified = 0,
            operator_id = ?
        WHERE id = ?
    ")->execute([$finishTime, $operatorId, $order['id']]);

    // ===== 3. Response sukses =====
    echo json_encode([
        'success' => true,
        'message' => "Print selesai. Dikerjakan oleh: $operatorName. WA akan dikirim 10 menit lagi."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}