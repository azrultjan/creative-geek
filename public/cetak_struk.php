<?php
// ============================================================
// FILE: cetak_struk_escpos.php - CETAK THERMAL KE GEZHI_micro_printer
// MENGGUNAKAN mike42/escpos-php - MAC + CUPS
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Autoload Composer
require_once __DIR__ . '/vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\CapabilityProfile;

// ===== AMBIL PARAMETER =====
$order_id = isset($_GET['id']) ? $_GET['id'] : null;
$order_number = isset($_GET['order']) ? $_GET['order'] : null;

if (empty($order_id) && empty($order_number)) {
    die('ID atau nomor order tidak ditemukan. Gunakan ?id=123 atau ?order=INV-xxx');
}

// ===== KONEKSI DATABASE =====
require_once __DIR__ . '/config/database.php';
if (!isset($pdo)) {
    die('Koneksi database gagal.');
}

// ============================================================
// DETEKSI TABEL PRODUK
// ============================================================
function getProductTable($pdo) {
    $tables = ['products', 'product', 'barang', 'produk'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) return $table;
        } catch (PDOException $e) { continue; }
    }
    return null;
}
$productTable = getProductTable($pdo);

// ============================================================
// AMBIL SETTING TOKO
// ============================================================
$defaultSettings = [
    'store_name'      => 'CREATIVE GEEK',
    'store_phone'     => '0812-4628-4684',
    'store_address'   => 'Jl. El Tari Km.6 Kel. Maubeli, Kefamenanu, NTT',
    'store_whatsapp'  => '6281246284684',
    'store_hours'     => '08:00 - 22:00 WITA',
    'store_footer'    => '2026 Creative Geek - All rights reserved',
    'store_tagline'   => 'Digital Printing & Design',
    'store_website'   => 'www.creativegeek-idn.com'
];
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) { /* fallback */ }
$settings = array_merge($defaultSettings, $settings);

// ============================================================
// AMBIL DATA ORDER
// ============================================================
try {
    if (!empty($order_id) && is_numeric($order_id)) {
        $stmt = $pdo->prepare("
            SELECT o.*, c.name as customer_name, c.phone as customer_phone,
                   u.username as created_by_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN users u ON o.created_by = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([(int)$order_id]);
    } else {
        $search = !empty($order_number) ? $order_number : $order_id;
        $stmt = $pdo->prepare("
            SELECT o.*, c.name as customer_name, c.phone as customer_phone,
                   u.username as created_by_name
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN users u ON o.created_by = u.id
            WHERE o.order_number = ?
        ");
        $stmt->execute([$search]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error: ' . $e->getMessage());
}

if (!$order) {
    die('Pesanan tidak ditemukan.');
}

// ============================================================
// AMBIL ITEM ORDER
// ============================================================
try {
    if ($productTable) {
        $sql = "SELECT oi.*, p.name as product_name, p.material
                FROM order_items oi
                JOIN `$productTable` p ON oi.product_id = p.id
                WHERE oi.order_id = ?";
    } else {
        $sql = "SELECT oi.*, oi.product_name, oi.material
                FROM order_items oi
                WHERE oi.order_id = ?";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$order['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        if (empty($item['product_name'])) $item['product_name'] = 'Produk #' . $item['product_id'];
        if (!isset($item['material'])) $item['material'] = '-';
    }
    unset($item);
} catch (PDOException $e) {
    die('Error item: ' . $e->getMessage());
}

// ============================================================
// FORMAT DATA
// ============================================================
$statusMap = [
    'diterima'   => 'Diproses',
    'finishing'  => 'Finishing',
    'siap_ambil' => 'Selesai',
    'batal'      => 'Batal'
];
$statusText = $statusMap[$order['status']] ?? $order['status'];
$paymentLabel = $order['payment_status'] === 'lunas' ? 'LUNAS' : 'BELUM LUNAS';
$orderDate = date('d/m/Y H:i', strtotime($order['order_date']));
$kasir = $order['created_by_name'] ?? '-';
if ($kasir !== '-') $kasir = ucwords(strtolower($kasir));

// Data toko
$storeName     = $settings['store_name'];
$storePhone    = $settings['store_phone'];
$storeAddress  = $settings['store_address'];
$storeWhatsapp = $settings['store_whatsapp'];
$storeHours    = $settings['store_hours'];
$storeFooter   = $settings['store_footer'];
$storeTagline  = $settings['store_tagline'];
$storeWebsite  = $settings['store_website'];
$whatsappDisplay = preg_replace('/^62/', '0', $storeWhatsapp);
if (strlen($whatsappDisplay) > 0 && $whatsappDisplay[0] !== '0') {
    $whatsappDisplay = '0' . $whatsappDisplay;
}

// ============================================================
// KONEKSI KE PRINTER (PASTIKAN NAMA PRINTER SESUAI)
// ============================================================
// Dari output lpstat, printer thermal adalah 'GEZHI_micro_printer'
$printerName = 'GEZHI_micro_printer'; // <-- GANTI jika berbeda

try {
    // Gunakan WindowsPrintConnector untuk CUPS di Mac (sama seperti SMB)
    $connector = new WindowsPrintConnector($printerName);
    $profile = CapabilityProfile::load("default");
    $printer = new Printer($connector, $profile);
} catch (Exception $e) {
    echo "❌ Gagal koneksi ke printer '$printerName': " . $e->getMessage() . "\n";
    echo "Coba periksa apakah printer aktif dan sudah di-share.\n";
    exit(1);
}

// ============================================================
// CETAK STRUK
// ============================================================
try {
    // --- HEADER TOKO ---
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setTextSize(2, 2); // besar
    $printer->text($storeName . "\n");
    $printer->setTextSize(1, 1);
    $printer->text($storeTagline . "\n");
    $printer->text($storeAddress . "\n");
    $printer->text("Telp: $storePhone | WA: $whatsappDisplay\n");
    $printer->text("Jam: $storeHours\n");
    $printer->text("Tanggal: $orderDate\n");
    $printer->text(str_repeat("-", 32) . "\n");

    // --- INFO ORDER ---
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->setEmphasis(true);
    $printer->text("No. Order: " . $order['order_number'] . "\n");
    $printer->setEmphasis(false);
    $printer->text("Pelanggan: " . ($order['customer_name'] ?? '-') . "\n");
    if (!empty($order['customer_phone'])) {
        $printer->text("Telepon: " . $order['customer_phone'] . "\n");
    }
    $printer->text("Kasir: $kasir\n");
    $printer->text("Status: $statusText\n");
    $printer->text("Pembayaran: $paymentLabel\n");
    $printer->text(str_repeat("-", 32) . "\n");

    // --- ITEM ---
    $printer->setEmphasis(true);
    $printer->text(str_pad("Item", 20) . str_pad("Qty", 5) . "Harga\n");
    $printer->setEmphasis(false);
    foreach ($items as $item) {
        $nama = substr($item['product_name'], 0, 18);
        if (!empty($item['material']) && $item['material'] !== '-') {
            $nama .= " (" . $item['material'] . ")";
        }
        $qty = (int)$item['qty'];
        $total = $qty * $item['price_per_unit'];
        $line = str_pad($nama, 20) . str_pad($qty, 5) . "Rp" . number_format($total, 0, ',', '.');
        $printer->text($line . "\n");
    }

    $printer->text(str_repeat("-", 32) . "\n");

    // --- TOTAL ---
    $printer->setEmphasis(true);
    $printer->text(str_pad("Subtotal", 25) . "Rp" . number_format($order['subtotal'], 0, ',', '.') . "\n");
    if ($order['discount'] > 0) {
        $printer->text(str_pad("Diskon", 25) . "-Rp" . number_format($order['discount'], 0, ',', '.') . "\n");
    }
    if ($order['design_fee'] > 0) {
        $printer->text(str_pad("Jasa Desain", 25) . "+Rp" . number_format($order['design_fee'], 0, ',', '.') . "\n");
    }
    $printer->setTextSize(2, 2);
    $printer->text(str_pad("TOTAL", 25) . "Rp" . number_format($order['grand_total'], 0, ',', '.') . "\n");
    $printer->setTextSize(1, 1);
    $printer->setEmphasis(false);

    // --- CATATAN ---
    if (!empty($order['notes'])) {
        $printer->text(str_repeat("-", 32) . "\n");
        $printer->text("Catatan: " . $order['notes'] . "\n");
    }

    // --- QR CODE (opsional) ---
    // Untuk QR code, library bisa generate, tapi kita skip

    // --- FOOTER ---
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text(str_repeat("-", 32) . "\n");
    $printer->setEmphasis(true);
    $printer->text("★ TERIMA KASIH ★\n");
    $printer->setEmphasis(false);
    $printer->text($storeFooter . "\n");
    $printer->text("✦ " . $storeWebsite . " ✦\n");
    $printer->text(date('d/m/Y H:i:s') . " | #" . $order['id'] . "\n");
    $printer->text("\n\n");

    // Potong kertas
    $printer->cut();

    // Buka laci kasir (jika ada)
    // $printer->pulse();

    $printer->close();

    echo "✅ Struk berhasil dicetak ke printer '$printerName'!\n";

} catch (Exception $e) {
    echo "❌ Gagal mencetak: " . $e->getMessage() . "\n";
    echo "Coba periksa apakah printer '$printerName' aktif dan terhubung.\n";
}