<?php
// Webhook WhatsApp dengan mode BELAJAR (learning mode)
// Selama mode belajar aktif, bot tidak membalas, hanya menyimpan chat
// Setelah mode belajar dimatikan, bot akan mulai membalas dengan pengetahuan dari chat yang terkumpul

require_once '../../config/database.php';

// ===== KONFIGURASI =====
// Ubah ke false untuk mengaktifkan balasan (setelah selesai belajar)
$LEARNING_MODE = true; // true = hanya simpan chat, tidak balas

// ===== FUNGSI LOGGING =====
function logWebhook($msg) {
    file_put_contents(__DIR__ . '/webhook.log', date('Y-m-d H:i:s') . ' - ' . $msg . PHP_EOL, FILE_APPEND);
}

// ===== FUNGSI SIMPAN CHAT KE DATABASE =====
function saveChat($customerId, $sender, $message) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO chat_history (customer_id, sender, message) VALUES (?, ?, ?)");
        $stmt->execute([$customerId, $sender, $message]);
        return true;
    } catch (PDOException $e) {
        logWebhook("Gagal simpan chat: " . $e->getMessage());
        return false;
    }
}

// ===== FUNGSI AMBIL RIWAYAT CHAT (UNTUK KONTEKS) =====
function getChatHistory($customerId, $limit = 10) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT sender, message, created_at 
            FROM chat_history 
            WHERE customer_id = ? 
            ORDER BY created_at ASC 
            LIMIT ?
        ");
        $stmt->execute([$customerId, $limit]);
        $rows = $stmt->fetchAll();
        
        $history = '';
        foreach ($rows as $row) {
            $sender = $row['sender'] === 'customer' ? 'Customer' : 'Saya';
            $history .= "$sender: " . $row['message'] . "\n";
        }
        return $history;
    } catch (PDOException $e) {
        logWebhook("Gagal ambil riwayat chat: " . $e->getMessage());
        return '';
    }
}

// ===== FUNGSI PANGGIL DEEPSEEK UNTUK CHAT BIASA (GAYA NTT) =====
function callDeepSeekChat($message, $customerName, $chatHistory = '') {
    $apiKey = 'sk-eb3a67992a4b4711b3d262e300e31cbd'; // GANTI SETELAH TESTING!
    $url = 'https://api.deepseek.com/v1/chat/completions';

    $systemPrompt = "Kamu adalah asisten WA toko percetakan 'Creative Geek'.\n" .
                    "Kamu orang NTT asli, gaya bicaramu santai, ramah, dan sopan.\n" .
                    "ATURAN PENTING:\n" .
                    "1. JANGAN PAKAI kata 'gue', 'elo', 'lu', 'ehe', 'hehe', 'hihi', 'Om/Tante'.\n" .
                    "2. Panggil customer dengan 'kak' atau 'adek' (pakai nama kalau tahu).\n" .
                    "3. Jawab singkat, to the point, pakai bahasa sehari-hari yang natural.\n" .
                    "4. Kalau customer nanya harga atau mau pesan, langsung tanya detail (ukuran, warna, bahan).\n" .
                    "5. Jangan sok akrab berlebihan, jaga sopan santun.\n" .
                    "6. Hindari kalimat formal kayak 'semoga harimu menyenangkan' atau 'terima kasih telah menghubungi'.\n" .
                    "7. Gunakan kalimat seperti: 'maaf kak, tadi saya ada pegang mesin. ada yang bisa saya bantu?'\n" .
                    "Customer bernama: " . $customerName;

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt]
    ];

    // Tambahkan riwayat chat sebagai konteks
    if (!empty($chatHistory)) {
        $messages[] = ['role' => 'assistant', 'content' => "Riwayat chat sebelumnya:\n" . $chatHistory];
    }

    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = [
        'model' => 'deepseek-chat',
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 500
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        logWebhook("DeepSeek Chat error: HTTP $httpCode");
        return null;
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

// ===== FUNGSI PANGGIL DEEPSEEK UNTUK PARSE ORDER =====
function callDeepSeekOrderParser($message) {
    $apiKey = 'sk-6939241164b447ec957c4a190b5bd993';
    $url = 'https://api.deepseek.com/v1/chat/completions';

    $systemPrompt = "Anda adalah asisten yang mengubah pesan order percetakan menjadi data JSON terstruktur.\n" .
                    "Ekstrak: product_name (spanduk/sticker/sablon/baliho/lainnya), size (contoh: 2x1 meter), color, material, qty (angka).\n" .
                    "Jika tidak ada, isi dengan null.\n" .
                    "Output hanya JSON, tanpa penjelasan lain.\n" .
                    "Contoh: {\"product_name\":\"Spanduk\",\"size\":\"2x1 meter\",\"color\":\"merah\",\"material\":\"flexy\",\"qty\":1}";

    $payload = [
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Pesan: $message"]
        ],
        'temperature' => 0.3,
        'max_tokens' => 200,
        'response_format' => ['type' => 'json_object']
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        logWebhook("DeepSeek Parser error: HTTP $httpCode");
        return null;
    }

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    return json_decode($content, true);
}

// ===== FUNGSI CEK APAKAH PESAN MENGANDUNG ORDER =====
function isOrderMessage($message) {
    $keywords = ['spanduk', 'sticker', 'sablon', 'baliho', 'cetak', 'pesan', 'order', 'mau', 'ukuran', 'meter', 'x', 'label', 'decal', 'harga'];
    $message = strtolower($message);
    foreach ($keywords as $keyword) {
        if (strpos($message, $keyword) !== false) return true;
    }
    return false;
}

// ===== FUNGSI BUAT DRAFT ORDER =====
function createDraftOrder($customerId, $parsed, $customerName) {
    global $pdo;

    $productName = $parsed['product_name'] ?? 'Produk';
    $size = $parsed['size'] ?? '';
    $color = $parsed['color'] ?? '';
    $material = $parsed['material'] ?? '-';
    $qty = (int)($parsed['qty'] ?? 1);

    // Estimasi harga sederhana (bisa dikembangkan)
    $price = 0;
    if (stripos($productName, 'spanduk') !== false || stripos($productName, 'baliho') !== false) {
        preg_match('/(\d+\.?\d*)\s*x\s*(\d+\.?\d*)/', $size, $m);
        if ($m) {
            $luas = (float)$m[1] * (float)$m[2];
            $price = round($luas * 45000);
        } else {
            $price = 45000;
        }
    } elseif (stripos($productName, 'sticker') !== false) {
        $price = 12000;
    } elseif (stripos($productName, 'sablon') !== false) {
        $price = 45000;
    } else {
        $price = 50000;
    }
    $total = $price * $qty;

    // Generate order number
    $orderNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

    // Insert order
    $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_id, status, payment_status, subtotal, grand_total, notes, is_draft, diterima_at, created_by) VALUES (?, ?, 'draft', 'belum', ?, ?, ?, 1, NOW(), 0)");
    $stmt->execute([$orderNumber, $customerId, $total, $total, "Draft dari WA: $productName $size $color"]);
    $orderId = $pdo->lastInsertId();

    // Insert item
    $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_name, qty, price_per_unit, material, notes, total) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$orderId, $productName . ' ' . $size, $qty, $price, $material, $color, $total]);

    logWebhook("Draft order created: $orderNumber (ID: $orderId)");

    return ['order_id' => $orderId, 'order_number' => $orderNumber, 'total' => $total];
}

// ===== FUNGSI KIRIM LINK KONFIRMASI =====
function sendConfirmationLink($chatId, $orderNumber, $orderId, $orderTotal, $sessionId) {
    $link = "https://domainanda.com/confirm_order.php?id=$orderId"; // GANTI DOMAIN
    $message = "Halo kak! Saya sudah buatkan draft pesanan Anda:\n" .
               "📦 Order: $orderNumber\n" .
               "💰 Total: Rp " . number_format($orderTotal, 0, ',', '.') . "\n\n" .
               "Klik link berikut untuk lihat detail dan konfirmasi:\n$link\n\n" .
               "Atau kalau mau revisi, tinggal balas chat ini.";

    // Kirim via OpenWA
    $api_key = 'owa_k1_9adc820273a8ada22e94d24f4073d69d34cbab82932d63cdb6eb7dc703ad914c';
    $url = "http://host.docker.internal:2785/api/sessions/{$sessionId}/messages/send-text";
    $data = ['chatId' => $chatId, 'text' => $message];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $api_key]);
    $response = curl_exec($ch);
    curl_close($ch);
    logWebhook("Confirmation link sent for order $orderNumber");
}

// ===== MULAI WEBHOOK =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
logWebhook("Raw: " . $input);

$event = $data['event'] ?? '';
$payload = $data['data'] ?? [];
$sessionId = $data['sessionId'] ?? 'default';

if ($event !== 'message.received') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}

$from = $payload['from'] ?? '';
$body = $payload['body'] ?? '';
$contact = $payload['contact'] ?? [];
$pushName = $contact['pushName'] ?? '';
$name = $contact['name'] ?? '';
$chatId = $payload['chatId'] ?? $from;

// ===== VALIDASI CHAT_ID =====
$validDomains = ["@c.us", "@lid", "@g.us", "@newsletter"];
$isValidChat = false;
foreach ($validDomains as $domain) {
    if (strpos($chatId, $domain) !== false) {
        $isValidChat = true;
        break;
    }
}
if (!$isValidChat) {
    logWebhook("ChatId tidak valid: $chatId, diabaikan");
    http_response_code(200);
    echo json_encode(["status" => "invalid_chatid"]);
    exit;
}

$idempotencyKey = $data['idempotencyKey'] ?? '';
logWebhook("From: $from, PushName: $pushName, Name: $name, ChatId: $chatId");

// ===== FILTER =====
if (isset($payload['fromMe']) && $payload['fromMe'] === true) {
    logWebhook("Pesan dari bot sendiri, diabaikan");
    http_response_code(200);
    echo json_encode(['status' => 'ignored_self']);
    exit;
}

if (strpos($chatId, '@g.us') !== false) {
    logWebhook("Pesan dari grup, diabaikan");
    http_response_code(200);
    echo json_encode(['status' => 'ignored_group']);
    exit;
}

// ===== PROSES NOMOR TELEPON =====
function getContactInfo($sessionId, $chatId) {
    $api_key = 'owa_k1_9adc820273a8ada22e94d24f4073d69d34cbab82932d63cdb6eb7dc703ad914c';
    $url = "http://host.docker.internal:2785/api/sessions/{$sessionId}/contacts/{$chatId}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $api_key]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpcode == 200) return json_decode($response, true);
    return null;
}

$senderPhone = null;
if (strpos($chatId, '@lid') !== false) {
    $contactInfo = getContactInfo($sessionId, $chatId);
    if ($contactInfo && isset($contactInfo['id'])) {
        $senderPhone = preg_replace('/@.*/', '', $contactInfo['id']);
    }
}
if (empty($senderPhone)) {
    $cleanFrom = preg_replace('/@.*/', '', $from);
    if (strpos($from, '@lid') === false && strpos($from, '@g.us') === false) {
        $senderPhone = $cleanFrom;
    }
}
if (empty($senderPhone)) {
    $cleanChatId = preg_replace('/@.*/', '', $chatId);
    if (strpos($chatId, '@lid') === false && strpos($chatId, '@g.us') === false) {
        $senderPhone = $cleanChatId;
    }
}
if (empty($senderPhone)) {
    $senderPhone = 'unknown';
}
logWebhook("SenderPhone: $senderPhone");

// ===== CARI / BUAT CUSTOMER =====
$customer = null;
$stmt = $pdo->prepare("SELECT id, name, phone, chat_id, last_reply_at, name_locked FROM customers WHERE chat_id = ?");
$stmt->execute([$chatId]);
$customer = $stmt->fetch();

if (!$customer) {
    $stmt = $pdo->prepare("SELECT id, name, phone, chat_id, last_reply_at, name_locked FROM customers WHERE phone = ?");
    $stmt->execute([$senderPhone]);
    $customer = $stmt->fetch();
}

if ($customer) {
    logWebhook("Customer found: " . $customer['name']);
    $needUpdate = false;
    if ($customer['chat_id'] != $chatId) {
        $customer['chat_id'] = $chatId;
        $needUpdate = true;
    }
    $isValidPhone = preg_match('/^[0-9]{10,}$/', $senderPhone);
    if ($isValidPhone && $customer['phone'] != $senderPhone) {
        $customer['phone'] = $senderPhone;
        $needUpdate = true;
    }
    if ($customer['name_locked'] == 0 && !empty($pushName) && $customer['name'] != $pushName) {
        $cleanName = preg_replace('/[^\p{L}\p{N}\s]/u', '', $pushName);
        $cleanName = trim($cleanName);
        if (!empty($cleanName)) {
            $customer['name'] = $cleanName;
            $needUpdate = true;
        }
    }
    if ($needUpdate) {
        $stmt = $pdo->prepare("UPDATE customers SET phone = ?, chat_id = ?, name = ? WHERE id = ?");
        $stmt->execute([$customer['phone'], $customer['chat_id'], $customer['name'], $customer['id']]);
        logWebhook("Updated customer");
    }
} else {
    $newName = !empty($pushName) ? $pushName : $name;
    $newName = preg_replace('/[^\p{L}\p{N}\s]/u', '', $newName);
    $newName = trim($newName);
    if (empty($newName)) $newName = 'Customer';
    try {
        $stmt = $pdo->prepare("INSERT INTO customers (name, phone, chat_id, name_locked) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone), chat_id = VALUES(chat_id)");
        $stmt->execute([$newName, $senderPhone, $chatId]);
        $stmt = $pdo->prepare("SELECT id, name, phone, chat_id, last_reply_at, name_locked FROM customers WHERE chat_id = ? OR phone = ?");
        $stmt->execute([$chatId, $senderPhone]);
        $customer = $stmt->fetch();
        logWebhook("New customer created: " . $customer['name']);
    } catch (PDOException $e) {
        logWebhook("Insert error: " . $e->getMessage());
    }
}

// ===== SIMPAN PESAN CUSTOMER KE DATABASE (SELALU) =====
if ($customer) {
    saveChat($customer['id'], 'customer', $body);
}

// ===== CEK MODE BELAJAR =====
global $LEARNING_MODE;
if ($LEARNING_MODE) {
    // Mode belajar: hanya simpan chat, TIDAK ADA BALASAN
    logWebhook("MODE BELAJAR AKTIF - Pesan disimpan, tidak dibalas.");
    http_response_code(200);
    echo json_encode(['status' => 'learned']);
    exit;
}

// ===== JIKA MODE BELAJAR MATI, PROSES SEPERTI BIASA =====
// ===== COOLDOWN (2 MENIT) =====
$cooldownFile = __DIR__ . '/.reply_cooldown.json';
$cooldown = json_decode(file_exists($cooldownFile) ? file_get_contents($cooldownFile) : '[]', true);
$now = time();
foreach ($cooldown as $key => $time) {
    if ($now - $time > 120) unset($cooldown[$key]);
}
$cooldownActive = isset($cooldown[$chatId]);
if (!$cooldownActive) {
    $cooldown[$chatId] = $now;
    file_put_contents($cooldownFile, json_encode($cooldown));
}

// ===== IDEMPOTENCY =====
$cacheFile = __DIR__ . '/.idempotency_cache';
if (!file_exists($cacheFile)) file_put_contents($cacheFile, '');
$cache = json_decode(file_get_contents($cacheFile), true) ?: [];
$now = time();
foreach ($cache as $key => $time) {
    if ($now - $time > 60) unset($cache[$key]);
}
if (!empty($idempotencyKey) && isset($cache[$idempotencyKey])) {
    logWebhook("Duplicate idempotencyKey, skipping");
    http_response_code(200);
    echo json_encode(['status' => 'duplicate_ignored']);
    exit;
}
$cache[$idempotencyKey] = $now;
file_put_contents($cacheFile, json_encode($cache));

// ===== COOLDOWN DATABASE (2 MENIT) =====
$sendReply = true;
$nowDateTime = new DateTime('now', new DateTimeZone('Asia/Makassar'));
$nowStr = $nowDateTime->format('Y-m-d H:i:s');

if ($customer && !empty($customer['last_reply_at'])) {
    $lastReply = new DateTime($customer['last_reply_at']);
    $diffSeconds = $nowDateTime->getTimestamp() - $lastReply->getTimestamp();
    if ($diffSeconds < 120) {
        $sendReply = false;
        logWebhook("Cooldown DB: $diffSeconds sec, skip");
    } else {
        $sendReply = true;
    }
} else {
    $sendReply = true;
}

// ===== PROSES PESAN (HANYA JIKA MODE BELAJAR MATI) =====
$reply = '';
$orderCreated = false;

if ($sendReply && $customer && !$cooldownActive) {
    $customerName = $customer['name'] ?? 'Customer';
    $message = $body ?? '';

    // Ambil riwayat chat sebagai konteks
    $chatHistory = getChatHistory($customer['id'], 10);

    // CEK APAKAH PESAN MENGANDUNG ORDER
    if (isOrderMessage($message)) {
        logWebhook("Order detected, parsing...");
        $parsed = callDeepSeekOrderParser($message);
        if ($parsed && !empty($parsed['product_name'])) {
            // Buat draft order
            $orderResult = createDraftOrder($customer['id'], $parsed, $customerName);
            if ($orderResult) {
                $orderCreated = true;
                // Kirim link konfirmasi
                sendConfirmationLink($chatId, $orderResult['order_number'], $orderResult['order_id'], $orderResult['total'], $sessionId);
                $reply = "Baik kak, saya sudah buatkan draft pesanan Anda dengan nomor " . $orderResult['order_number'] . ". Cek link yang saya kirim ya.";
                logWebhook("Order created and link sent");
            } else {
                $reply = "Maaf kak, saya gagal membuat draft order. Coba tulis ulang dengan detail lengkap (ukuran, warna, bahan).";
            }
        } else {
            // Gagal parsing, tanyakan detail
            $reply = "Maaf kak, saya kurang paham detail pesanannya. Bisa sebutkan produk, ukuran, warna, dan bahan? Contoh: Spanduk 2x1 meter, warna merah, bahan flexy.";
            logWebhook("Order parsing failed, asking details");
        }
    } else {
        // BUKAN ORDER → chat biasa
        logWebhook("Normal chat, calling AI");
        $aiReply = callDeepSeekChat($message, $customerName, $chatHistory);
        $reply = $aiReply ?: "Halo kak! Ada yang bisa saya bantu?";
    }

    // Update last_reply_at
    if ($customer) {
        $stmt = $pdo->prepare("UPDATE customers SET last_reply_at = ? WHERE id = ?");
        $stmt->execute([$nowStr, $customer['id']]);
    }
} else {
    logWebhook("Skip reply (cooldown or no customer)");
}

// ===== KIRIM BALASAN =====
if (!empty($reply) && !$orderCreated) {
    $api_key = 'owa_k1_9adc820273a8ada22e94d24f4073d69d34cbab82932d63cdb6eb7dc703ad914c';
    $url = "http://host.docker.internal:2785/api/sessions/{$sessionId}/messages/send-text";
    $data = ['chatId' => $chatId, 'text' => $reply];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $api_key]);
    curl_exec($ch);
    curl_close($ch);
    logWebhook("Reply sent: $reply");
    
    // Simpan balasan bot ke database
    if ($customer) {
        saveChat($customer['id'], 'bot', $reply);
    }
} elseif ($orderCreated) {
    logWebhook("Order created, link already sent, no additional reply.");
    // Simpan balasan bot (link) ke database
    if ($customer && !empty($reply)) {
        saveChat($customer['id'], 'bot', $reply);
    }
} else {
    logWebhook("No reply sent.");
}

http_response_code(200);
echo json_encode(['status' => 'ok']);