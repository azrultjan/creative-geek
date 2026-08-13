<?php
// Webhook dengan cooldown 5 menit - Daily greeting + update name
require_once '../../config/database.php';

function logWebhook($msg) {
    file_put_contents(__DIR__ . '/webhook.log', date('Y-m-d H:i:s') . ' - ' . $msg . PHP_EOL, FILE_APPEND);
}


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
    $validDomains = ["@c.us", "@lid", "@g.us"];
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

logWebhook("From: $from, PushName: $pushName, Name: $name, ChatId: $chatId, Idempotency: $idempotencyKey");

// ===== FILTER DARI BOT SENDIRI =====
if (isset($payload['fromMe']) && $payload['fromMe'] === true) {
    logWebhook("Pesan dari bot sendiri, diabaikan");
    http_response_code(200);
    echo json_encode(['status' => 'ignored_self']);
    exit;
}

// ===== FILTER GRUP =====
if (strpos($chatId, '@g.us') !== false) {
    logWebhook("Pesan dari grup, diabaikan");
    http_response_code(200);
    echo json_encode(['status' => 'ignored_group']);
    exit;
}

// ===== COOLDOWN 5 MENIT (FILE JSON) – HANYA UNTUK BALASAN =====
$cooldownFile = __DIR__ . '/.reply_cooldown.json';
$cooldown = json_decode(file_exists($cooldownFile) ? file_get_contents($cooldownFile) : '[]', true);
$now = time();
foreach ($cooldown as $key => $time) {
    if ($now - $time > 300) unset($cooldown[$key]);
}
$cooldownActive = isset($cooldown[$chatId]);
if ($cooldownActive) {
    logWebhook("Cooldown aktif untuk chatId $chatId, balasan akan dilewati (tapi customer tetap diproses)");
} else {
    $cooldown[$chatId] = $now;
    file_put_contents($cooldownFile, json_encode($cooldown));
}

// ===== IDEMPOTENCY CACHE =====
$cacheFile = __DIR__ . '/.idempotency_cache';
if (!file_exists($cacheFile)) file_put_contents($cacheFile, '');
$cache = json_decode(file_get_contents($cacheFile), true) ?: [];
$now = time();
foreach ($cache as $key => $time) {
    if ($now - $time > 60) unset($cache[$key]);
}
if (!empty($idempotencyKey) && isset($cache[$idempotencyKey])) {
    logWebhook("Duplicate idempotencyKey, skipping reply");
    http_response_code(200);
    echo json_encode(['status' => 'duplicate_ignored']);
    exit;
}
$cache[$idempotencyKey] = $now;
file_put_contents($cacheFile, json_encode($cache));

// ===== PROSES NOMOR TELEPON =====
$senderPhone = null;
if (strpos($chatId, '@lid') !== false) {
    $contactInfo = getContactInfo($sessionId, $chatId);
    if ($contactInfo && isset($contactInfo['id'])) {
        $senderPhone = preg_replace('/@.*/', '', $contactInfo['id']);
        logWebhook("Phone dari getContactInfo (LID): $senderPhone");
    }
}
if (empty($senderPhone)) {
    $cleanFrom = preg_replace('/@.*/', '', $from);
    if (strpos($from, '@lid') === false && strpos($from, '@g.us') === false) {
        $senderPhone = $cleanFrom;
        logWebhook("Phone dari from (bukan LID/grup): $senderPhone");
    } else {
        logWebhook("from adalah LID/grup, ignore");
    }
}
if (empty($senderPhone)) {
    $cleanChatId = preg_replace('/@.*/', '', $chatId);
    if (strpos($chatId, '@lid') === false && strpos($chatId, '@g.us') === false) {
        $senderPhone = $cleanChatId;
        logWebhook("Fallback ke clean chatId: $senderPhone");
    }
}
if (empty($senderPhone)) {
    $senderPhone = 'unknown';
    logWebhook("SenderPhone tidak ditemukan, gunakan 'unknown'");
}
logWebhook("SenderPhone (nomor bersih): $senderPhone");

// ===== CARI / BUAT CUSTOMER =====
// Ambil juga kolom name_locked
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
    logWebhook("Customer found: " . $customer['name'] . " (phone: " . $customer['phone'] . ", chat_id: " . $customer['chat_id'] . ", locked: " . $customer['name_locked'] . ")");
    $needUpdate = false;

    if ($customer['chat_id'] != $chatId) {
        $customer['chat_id'] = $chatId;
        $needUpdate = true;
        logWebhook("Chat_id akan diupdate ke: $chatId");
    }

    $isValidPhone = preg_match('/^[0-9]{10,}$/', $senderPhone);
    if ($isValidPhone && $customer['phone'] != $senderPhone) {
        $customer['phone'] = $senderPhone;
        $needUpdate = true;
        logWebhook("Akan update phone ke: $senderPhone (valid)");
    } elseif (!$isValidPhone && $customer['phone'] != $senderPhone) {
        logWebhook("Phone tidak valid ($senderPhone), tidak diupdate");
    }

    // ===== UPDATE NAME HANYA JIKA TIDAK LOCKED =====
    if ($customer['name_locked'] == 0) {
        if (!empty($pushName) && $customer['name'] != $pushName) {
            $cleanName = preg_replace('/[^\p{L}\p{N}\s]/u', '', $pushName);
            $cleanName = trim($cleanName);
            if (!empty($cleanName)) {
                $customer['name'] = $cleanName;
                $needUpdate = true;
                logWebhook("Akan update name ke: $cleanName (name_locked=0)");
            } else {
                logWebhook("PushName hanya emoji, tidak diupdate");
            }
        }
    } else {
        logWebhook("Name locked, skip update name");
    }

    if ($needUpdate) {
        $stmt = $pdo->prepare("UPDATE customers SET phone = ?, chat_id = ?, name = ? WHERE id = ?");
        $stmt->execute([$customer['phone'], $customer['chat_id'], $customer['name'], $customer['id']]);
        logWebhook("Updated customer: " . $customer['name']);
    }
} else {
    $newName = !empty($pushName) ? $pushName : $name;
    $newName = preg_replace('/[^\p{L}\p{N}\s]/u', '', $newName);
    $newName = trim($newName);
    if (empty($newName)) $newName = 'Customer';

    logWebhook("Attempting INSERT: name=$newName, phone=$senderPhone, chat_id=$chatId, name_locked=0");
    try {
        // Insert dengan name_locked = 0 (default)
        $stmt = $pdo->prepare("INSERT INTO customers (name, phone, chat_id, name_locked) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone), chat_id = VALUES(chat_id), name_locked = VALUES(name_locked)");
        $result = $stmt->execute([$newName, $senderPhone, $chatId]);
        if ($result) {
            $customerId = $pdo->lastInsertId();
            if ($customerId) logWebhook("New customer created with ID: $customerId");
            $stmt = $pdo->prepare("SELECT id, name, phone, chat_id, last_reply_at, name_locked FROM customers WHERE chat_id = ? OR phone = ?");
            $stmt->execute([$chatId, $senderPhone]);
            $customer = $stmt->fetch();
            if ($customer) logWebhook("Customer fetched after insert: " . $customer['name']);
        }
    } catch (PDOException $e) {
        logWebhook("PDO Exception saat insert: " . $e->getMessage());
        $stmt = $pdo->prepare("SELECT id, name, phone, chat_id, last_reply_at, name_locked FROM customers WHERE chat_id = ? OR phone = ?");
        $stmt->execute([$chatId, $senderPhone]);
        $customer = $stmt->fetch();
        if ($customer) logWebhook("Customer ditemukan setelah exception: " . $customer['name']);
    }
}

// ===== COOLDOWN DATABASE (5 MENIT) =====
$sendReply = true;
$nowDateTime = new DateTime('now', new DateTimeZone('Asia/Makassar'));
$nowStr = $nowDateTime->format('Y-m-d H:i:s');

if ($customer && !empty($customer['last_reply_at'])) {
    $lastReply = new DateTime($customer['last_reply_at']);
    $diffSeconds = $nowDateTime->getTimestamp() - $lastReply->getTimestamp();
    if ($diffSeconds < 300) {
        $sendReply = false;
        logWebhook("Last reply was $diffSeconds seconds ago (< 5 min), skipping reply");
    } else {
        $sendReply = true;
        logWebhook("Last reply was $diffSeconds seconds ago, sending reply");
    }
} else {
    $sendReply = true;
    logWebhook("First time replying, sending full");
}

// ===== DAILY LIMIT (sekali sehari) =====
$reply = '';
$targetChat = $chatId;

if ($sendReply && $customer) {
    $today = date('Y-m-d');
    $lastDate = !empty($customer['last_reply_at']) ? date('Y-m-d', strtotime($customer['last_reply_at'])) : null;
    if ($lastDate == $today) {
        $sendReply = false;
        logWebhook("Already replied today, skip auto-reply.");
    } else {
        $reply = "Halo " . $customer['name'] . "! 👋\nTerima kasih sudah menghubungi Creative Geek. Ada yang bisa kami bantu ?";
        logWebhook("Daily greeting sent (first time today).");
    }
} elseif ($sendReply && !$customer) {
    $reply = "Halo! 👋 Terima kasih sudah menghubungi Creative Geek. Ada yang bisa kami bantu ?";
    logWebhook("New customer, greeting sent.");
}

if ($cooldownActive) {
    $sendReply = false;
    logWebhook("Cooldown aktif, balasan dibatalkan.");
}

if (!empty($reply) && $customer) {
    $stmt = $pdo->prepare("UPDATE customers SET last_reply_at = ? WHERE id = ?");
    $stmt->execute([$nowStr, $customer['id']]);
    logWebhook("Updated last_reply_at for customer " . $customer['name']);
} elseif ($customer && $sendReply === false) {
    logWebhook("No reply sent (daily limit, cooldown, or duplicate)");
} else {
    logWebhook("No reply sent (other reason)");
}

logWebhook("Reply: " . $reply);
logWebhook("Target chat: " . $targetChat);

if (!empty($reply)) {
    function replyWhatsApp($chatId, $message, $sessionId) {
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
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        logWebhook("Reply response ($httpcode): " . $response);
        return $httpcode == 200;
    }
    replyWhatsApp($targetChat, $reply, $sessionId);
} else {
    logWebhook("No reply sent (cooldown, duplicate, or daily limit)");
}

http_response_code(200);
echo json_encode(['status' => 'ok']);