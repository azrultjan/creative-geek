<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$orderNumber = trim($input['order_number'] ?? '');
$remaining = trim($input['remaining'] ?? '');
$printStatus = trim($input['print_status'] ?? '');
$progress = isset($input['progress']) ? (int)$input['progress'] : null; // <-- DITAMBAHKAN

if (empty($orderNumber)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_number required']);
    exit;
}

try {
    // 1. Cek status order saat ini
    $stmtCheck = $pdo->prepare("SELECT status FROM orders WHERE order_number = ?");
    $stmtCheck->execute([$orderNumber]);
    $currentStatus = $stmtCheck->fetchColumn();

    // 2. Jika sudah selesai, tolak semua perubahan
    if ($currentStatus === 'siap_ambil') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Order sudah selesai (siap_ambil), tidak boleh diubah lagi.'
        ]);
        exit;
    }

    // 3. Bangun query UPDATE secara dinamis
    $sql = "UPDATE orders 
            SET remaining_time = ?,
                print_status = ?,
                updated_at = NOW()";
    $params = [$remaining, $printStatus];

    // Jika progress dikirim, tambahkan ke query
    if ($progress !== null) {
        $sql .= ", progress = ?";
        $params[] = $progress;
    }

    $sql .= " WHERE order_number = ?";
    $params[] = $orderNumber;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>