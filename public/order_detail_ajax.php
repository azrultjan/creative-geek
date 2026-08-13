<?php
// ===== AKTIFKAN ERROR REPORTING =====
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ===== PASTIKAN SESSION SUDAH DIMULAI =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== INCLUDE DENGAN PATH ABSOLUT =====
require_once '/var/www/config/database.php';

// ===== SET HEADER JSON =====
header('Content-Type: application/json');

// ===== CEK PARAMETER ID =====
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ID tidak valid'
    ]);
    exit;
}

$id = (int)$_GET['id'];

try {
    // ===== PASTIKAN KONEKSI DATABASE ADA =====
    if (!isset($pdo)) {
        throw new Exception('Koneksi database tidak tersedia.');
    }

    // ===== AMBIL DATA ORDER + CUSTOMER =====
    $stmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Order tidak ditemukan'
        ]);
        exit;
    }

    // ===== AMBIL ITEM PESANAN =====
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===== KONVERSI ANGKA KE FLOAT =====
    foreach ($items as &$item) {
        $item['price_per_unit'] = (float)$item['price_per_unit'];
        $item['total'] = (float)$item['total'];
    }
    $order['subtotal'] = (float)$order['subtotal'];
    $order['discount'] = (float)$order['discount'];
    $order['design_fee'] = (float)($order['design_fee'] ?? 0);
    $order['grand_total'] = (float)$order['grand_total'];

    // ===== KIRIM RESPONSE SUKSES =====
    echo json_encode([
        'success' => true,
        'order' => $order,
        'items' => $items
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}