<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Asia/Makassar');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$logDir = '/var/www/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

// ===== Lock file =====
$lockFile = '/tmp/cron_send_ready.lock';
if (file_exists($lockFile)) {
    file_put_contents($logDir . '/cron_exec.log', date('Y-m-d H:i:s') . " - Lock exists, skipping\n", FILE_APPEND);
    exit;
}
file_put_contents($lockFile, getmypid());

file_put_contents($logDir . '/cron_exec.log', date('Y-m-d H:i:s') . " - Cron executed\n", FILE_APPEND);

try {
    // ===== Ambil order yang siap, belum notified, dan finish_time <= NOW =====
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.operator_id,
               c.name AS customer_name, 
               c.phone,
               u.username AS operator_name,
               COALESCE(o.retry_count, 0) AS retry_count
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u ON o.operator_id = u.id
        WHERE o.status = 'siap_ambil'
          AND o.notified = 0
          AND o.finish_time <= NOW()
          AND (o.retry_count IS NULL OR o.retry_count < 3)
    ");
    $stmt->execute();
    $orders = $stmt->fetchAll();

    if (empty($orders)) {
        file_put_contents($logDir . '/cron_exec.log', date('Y-m-d H:i:s') . " - No orders\n", FILE_APPEND);
    } else {
        file_put_contents($logDir . '/cron_exec.log', date('Y-m-d H:i:s') . " - Found " . count($orders) . " order(s)\n", FILE_APPEND);
    }

    foreach ($orders as $order) {
        $retry = (int)$order['retry_count'] + 1;
        $phone = $order['phone'] ?? '';

        if (empty($phone)) {
            // Tidak ada nomor, langsung tandai selesai
            $pdo->prepare("UPDATE orders SET notified = 1, retry_count = ? WHERE id = ?")->execute([$retry, $order['id']]);
            continue;
        }

        $link = "https://cloud.creativegeek-idn.com/index.php?order=" . urlencode($order['order_number']);
        $operator = $order['operator_name'] ?? 'Tim Creative Geek';

        $msg = "Halo " . $order['customer_name'] . "! \n\n";
        $msg .= "BREAKING NEWS: Pesanan #" . $order['order_number'] . " telah SELESAI dan siap diambil!\n";
        $msg .= "Detail e-receipt:\n" . $link . "\n\n";
        $msg .= "Alamat kami : Jl. El Tari Km.6 Kel. Maubeli, Kefamenanu, NTT.\n";
        $msg .= "Lihat di Google Maps: https://maps.app.goo.gl/WBVins3sw8qcKSc18\n\n";

        try {
            $sent = sendWhatsAppNotification($phone, $msg);

            if ($sent) {
                // Sukses → notified = 1, retry_count direset 0
                $pdo->prepare("UPDATE orders SET notified = 1, retry_count = 0 WHERE id = ?")->execute([$order['id']]);
                writeLog("WA sukses & notified=1 untuk #{$order['order_number']}");
                file_put_contents($logDir . '/cron_exec.log', date('Y-m-d H:i:s') . " - #{$order['order_number']} WA sent\n", FILE_APPEND);
            } else {
                // Gagal → tambah retry_count
                $pdo->prepare("UPDATE orders SET retry_count = ? WHERE id = ?")->execute([$retry, $order['id']]);
                writeLog("WA gagal #{$order['order_number']}, retry {$retry}/3", 'ERROR');
                file_put_contents($logDir . '/cron_exec.log', date('Y-m-d H:i:s') . " - #{$order['order_number']} WA failed (retry {$retry}/3)\n", FILE_APPEND);

                // Jika sudah 3 kali gagal, tandai notified=1 agar berhenti
                if ($retry >= 3) {
                    $pdo->prepare("UPDATE orders SET notified = 1 WHERE id = ?")->execute([$order['id']]);
                    writeLog("STOP: #{$order['order_number']} gagal 3x, diberhentikan", 'WARNING');
                    file_put_contents($logDir . '/cron_exec.log', date('Y-m-d H:i:s') . " - #{$order['order_number']} STOP after 3 failures\n", FILE_APPEND);
                }
            }
        } catch (Exception $e) {
            writeLog("Exception #{$order['order_number']}: " . $e->getMessage(), 'ERROR');
            file_put_contents($logDir . '/cron_exec.log', date('Y-m-d H:i:s') . " - #{$order['order_number']} Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            // Increment retry
            $pdo->prepare("UPDATE orders SET retry_count = ? WHERE id = ?")->execute([$retry, $order['id']]);
        }
    }
} catch (Exception $e) {
    file_put_contents($logDir . '/cron_exec.log', date('Y-m-d H:i:s') . " - FATAL: " . $e->getMessage() . "\n", FILE_APPEND);
} finally {
    if (file_exists($lockFile)) unlink($lockFile);
}