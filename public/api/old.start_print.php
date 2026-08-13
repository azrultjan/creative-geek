<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$orderNumber = trim($input['order_number'] ?? '');
$estimatedMinutes = (int)($input['estimated_minutes'] ?? 15);

if (empty($orderNumber)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_number required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, customer_id, status FROM orders WHERE order_number = ?");
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    if (!in_array($order['status'], ['diterima', 'desain'])) {
        echo json_encode(['success' => false, 'message' => 'Status sudah ' . $order['status']]);
        exit;
    }

    $startedAt = date('Y-m-d H:i:s');
    $estimatedFinishAt = date('Y-m-d H:i:s', strtotime("+$estimatedMinutes minutes"));
    $remainingTime = sprintf('%02d:%02d:00', floor($estimatedMinutes/60), $estimatedMinutes%60);

    $pdo->prepare("
        UPDATE orders 
        SET status = 'finishing',
            started_at = ?,
            estimated_minutes = ?,
            estimated_finish_at = ?,
            progress = 0,
            remaining_time = ?,
            print_status = 'Print',
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$startedAt, $estimatedMinutes, $estimatedFinishAt, $remainingTime, $order['id']]);

    // ===== KIRIM WA =====
    $stmt2 = $pdo->prepare("SELECT phone, name FROM customers WHERE id = ?");
    $stmt2->execute([$order['customer_id']]);
    $customer = $stmt2->fetch();

    if ($customer && !empty($customer['phone'])) {
        $link = "https://cloud.creativegeek-idn.com/index.php?order=" . urlencode($orderNumber);
      if ($customer && !empty($customer['phone'])) {
    $link = "https://cloud.creativegeek-idn.com/index.php?order=" . urlencode($orderNumber);
		$msg = "Halo " . $customer['name'] . ",\n\n";
		$msg .= "Pesanan #" . $orderNumber . " sudah masuk proses cetak. Estimasi selesai sekitar " . $estimatedMinutes . " menit.\n\n";
		$msg .= "Ngomong-ngomong soal estimasi, sekarang kami punya fitur andalan: progress bar yang nyambung langsung ke mesin cetak.\n";
		$msg .= "Angka yang kamu lihat itu update tiap detik—bukan perkiraan kasar, tapi hitungan real dari mesin kami.\n\n";
		$msg .= "Cek biar nggak penasaran:\n" . $link . "\n\n";
		$msg .= "Makasih sudah pesen di Creative Geek!";
    sendWhatsAppNotification($customer['phone'], $msg);
}
        sendWhatsAppNotification($customer['phone'], $msg);
    }

    echo json_encode(['success' => true, 'message' => 'Print started']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}