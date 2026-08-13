<?php
// /includes/functions.php

function generateOrderNumber() {
    $date = date('dmy');
    $random = str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    return 'CG' . $date . '/' . $random;
}

function sendWhatsAppNotification($phone, $message) {
    $sessionId = 'dd00d7f2-69cf-4160-aac1-df2ae16e5ec2';
    $apiKey = 'owa_k1_9adc820273a8ada22e94d24f4073d69d34cbab82932d63cdb6eb7dc703ad914c';

    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 1) === '0') {
        $phone = '62' . substr($phone, 1);
    }
    if (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }

    $chatId = $phone . '@c.us';
    $url = 'http://host.docker.internal:2785/api/sessions/' . $sessionId . '/messages/send-text';

    $data = ['chatId' => $chatId, 'text' => $message];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
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

    if ($httpCode !== 200) {
        error_log("WhatsApp send failed: HTTP $httpCode, response: $response, error: $error");
        return false;
    }
    return true;
}

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
    if ($role === 'owner') return 'dashboard.php';
    foreach ($allMenus as $menu) {
        if (hasMenuAccess($menu['key'], $role)) {
            return $menu['url'];
        }
    }
    return 'logout.php';
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