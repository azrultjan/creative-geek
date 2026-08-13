<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

// Ambil parameter: bisa berupa array atau string comma-separated
$orderNumbersRaw = $input['order_numbers'] ?? ($input['order_number'] ?? null);
$estimatedMinutes = (int)($input['estimated_minutes'] ?? 15);

if (empty($orderNumbersRaw)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_numbers atau order_number wajib diisi']);
    exit;
}

// Konversi ke array
if (is_string($orderNumbersRaw)) {
    $orderNumbers = array_map('trim', explode(',', $orderNumbersRaw));
} elseif (is_array($orderNumbersRaw)) {
    $orderNumbers = array_map('trim', $orderNumbersRaw);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Format order_numbers tidak valid']);
    exit;
}

// Filter kosong
$orderNumbers = array_filter($orderNumbers, function($v) { return !empty($v); });

if (empty($orderNumbers)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tidak ada nomor order yang valid']);
    exit;
}

$results = [];
$allSuccess = true;

foreach ($orderNumbers as $orderNumber) {
    try {
        // 1. Cek order
        $stmt = $pdo->prepare("SELECT id, customer_id, status FROM orders WHERE order_number = ?");
        $stmt->execute([$orderNumber]);
        $order = $stmt->fetch();

        if (!$order) {
            $results[] = [
                'order_number' => $orderNumber,
                'success' => false,
                'message' => 'Order tidak ditemukan'
            ];
            $allSuccess = false;
            continue;
        }

        // 2. Cek status
        if (!in_array($order['status'], ['diterima', 'desain'])) {
            $results[] = [
                'order_number' => $orderNumber,
                'success' => false,
                'message' => 'Status sudah ' . $order['status']
            ];
            $allSuccess = false;
            continue;
        }

        // 3. Update order ke status finishing
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

        // 4. Kirim WA ke customer yang bersangkutan
        $stmt2 = $pdo->prepare("SELECT phone, name FROM customers WHERE id = ?");
        $stmt2->execute([$order['customer_id']]);
        $customer = $stmt2->fetch();

        if ($customer && !empty($customer['phone'])) {
            $link = "https://cloud.creativegeek-idn.com/index.php?order=" . urlencode($orderNumber);
            $msg = "Halo " . $customer['name'] . ",\n\n";
            $msg .= "Pesanan #" . $orderNumber . " sudah masuk proses cetak. Estimasi mulai proses cetak " . $estimatedMinutes . " menit.\n\n";
            $msg .= "Ngomong-ngomong soal estimasi, sekarang kami punya fitur andalan: progress bar yang nyambung langsung ke mesin cetak.\n";
            $msg .= "Angka yang kamu lihat itu update tiap detik—bukan perkiraan kasar, tapi hitungan real dari mesin kami.\n\n";
            $msg .= "Cek biar nggak penasaran:\n" . $link . "\n\n";
            $msg .= "Makasih sudah pesen di Creative Geek!";
            sendWhatsAppNotification($customer['phone'], $msg);
        }

        $results[] = [
            'order_number' => $orderNumber,
            'success' => true,
            'message' => 'Print started'
        ];

    } catch (Exception $e) {
        $results[] = [
            'order_number' => $orderNumber,
            'success' => false,
            'message' => $e->getMessage()
        ];
        $allSuccess = false;
    }
}

// Response akhir
http_response_code($allSuccess ? 200 : 207);
echo json_encode([
    'success' => $allSuccess,
    'results' => $results
]);