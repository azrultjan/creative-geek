<?php
// ======================================================
// ===== AUTO-FIX: Hapus .php & Ganti CDN Tailwind =====
// ======================================================
ob_start(function($buffer) {
    // Hanya proses untuk HTML (bukan AJAX/JSON)
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        
        // ===== 1. GANTI CDN TAILWIND DENGAN CSS LOKAL =====
        $buffer = str_replace(
            '<script src="https://cdn.tailwindcss.com"></script>',
            '<link href="/css/style.css" rel="stylesheet">',
            $buffer
        );
        
        // ===== 2. Ganti href="file.php" atau href="file.php?..." menjadi href="file" =====
        $pattern = '/href="([^"]+)\.php(\?[^"]*)?"/';
        $buffer = preg_replace($pattern, 'href="$1$2"', $buffer);
        
        // ===== 3. Ganti action="file.php" atau action="file.php?..." menjadi action="file" =====
        $pattern = '/action="([^"]+)\.php(\?[^"]*)?"/';
        $buffer = preg_replace($pattern, 'action="$1$2"', $buffer);
        
        // ===== 4. Ganti src="file.php" jika ada (jarang) =====
        $pattern = '/src="([^"]+)\.php(\?[^"]*)?"/';
        $buffer = preg_replace($pattern, 'src="$1$2"', $buffer);
    }
    return $buffer;
});
// ======================================================

// ======================================================
// ===== FUNGSI-FUNGSI ASLI (TIDAK BERUBAH) =====
// ======================================================

function generateOrderNumber() {
    return 'CG' . date('dmy') . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

// ======================================================
// ===== FUNGSI KIRIM WHATSAPP (DIPERBAIKI) =====
// ======================================================
function sendWhatsAppNotification($phone, $message) {
    $sessionId = 'dd00d7f2-69cf-4160-aac1-df2ae16e5ec2';
    $apiKey = 'owa_k1_9adc820273a8ada22e94d24f4073d69d34cbab82932d63cdb6eb7dc703ad914c';

    // Format nomor HP
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 1) === '0') {
        $phone = '62' . substr($phone, 1);
    }
    if (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }

    $chatId = $phone . '@c.us';
    
    // ===== PERBAIKAN: Gunakan IP container openwa-api =====
   $url = 'http://host.docker.internal:2785/api/sessions/' . $sessionId . '/messages/send-text';

    $data = ['chatId' => $chatId, 'text' => $message];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // ===== LOGGING DETAIL =====
    $logMsg = "WA send: phone=$phone, chatId=$chatId, httpCode=$httpCode, response=$response, error=$error";
    writeLog($logMsg, 'DEBUG');
    error_log($logMsg);

    if ($httpCode >= 200 && $httpCode < 300) {
    return true;
}
return false;
}

// ======================================================
// ===== FUNGSI LAINNYA (TIDAK BERUBAH) =====
// ======================================================

function hasMenuAccess($menuKey, $role = null) {
    global $pdo;
    if ($role === null) $role = $_SESSION['role'] ?? 'guest';
    if ($role === 'owner') return true;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM menu_access WHERE role = ? AND menu_key = ? AND is_visible = 1");
    $stmt->execute([$role, $menuKey]);
    return $stmt->fetchColumn() > 0;
}

function getFirstAllowedMenuUrl() {
    global $allMenus, $pdo;
    $role = $_SESSION['role'] ?? 'guest';
    if ($role === 'owner') return 'dashboard';
    foreach ($allMenus as $menu) {
        if (hasMenuAccess($menu['key'], $role)) {
            return $menu['url'];
        }
    }
    return 'logout';
}

function getStatusLabel($status) {
    $labels = [
        'diterima'  => 'Order Masuk',
        'desain'    => 'Proses Desain',
        'cetak'     => 'Proses Cetak',
        'finishing' => 'Finishing',
        'siap_ambil'=> 'Siap Ambil'
    ];
    return $labels[$status] ?? $status;
}

function getStatuses() {
    return ['diterima', 'desain', 'cetak', 'finishing', 'siap_ambil'];
}

function getCompanyData() {
    global $pdo;
    $settings = [];
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function writeLog($message, $level = 'INFO') {
    $logFile = __DIR__ . '/../logs/app.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $time = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$time] [$level] $message" . PHP_EOL, FILE_APPEND);
}

function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url);
    exit;
}

// ======================================================
// ===== PASTIKAN BUFFER DIKIRIM DI AKHIR REQUEST =====
// ======================================================
register_shutdown_function(function() {
    if (ob_get_level()) {
        ob_end_flush();
    }
});