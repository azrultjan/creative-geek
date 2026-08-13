<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/menus.php';

$sessionId = 'dd00d7f2-69cf-4160-aac1-df2ae16e5ec2';
$apiKey = 'owa_k1_9adc820273a8ada22e94d24f4073d69d34cbab82932d63cdb6eb7dc703ad914c';
$apiUrl = 'http://host.docker.internal:2785/api';

function callOpenWA($endpoint, $method = 'GET', $data = null) {
    global $apiKey, $apiUrl;
    $url = $apiUrl . $endpoint;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

// ===== GET CHATS (DIPERBAIKI) =====
if ($action === 'get_chats') {
    $result = callOpenWA("/sessions/{$sessionId}/chats");
    if ($result['code'] == 200 && is_array($result['data'])) {
        // Filter chat yang valid
        $chats = array_filter($result['data'], function($chat) {
            if (($chat['kind'] ?? '') === 'status') return false;
            if (empty($chat['id'])) return false;
            return true;
        });
        
        // Urutkan berdasarkan timestamp terbaru
        usort($chats, function($a, $b) {
            $tsA = $a['lastMessage']['timestamp'] ?? $a['timestamp'] ?? 0;
            $tsB = $b['lastMessage']['timestamp'] ?? $b['timestamp'] ?? 0;
            return $tsB - $tsA;
        });
        
        // Batasi maksimal 50 chat
        $chats = array_slice($chats, 0, 50);
        
        // Ambil pesan terakhir untuk setiap chat
        $enhancedChats = [];
        foreach ($chats as $chat) {
            $chatId = $chat['id'];
            // Ambil 1 pesan terakhir
            $msgResult = callOpenWA("/sessions/{$sessionId}/messages?chatId=" . urlencode($chatId) . "&limit=1");
            $lastMsg = null;
            if ($msgResult['code'] == 200 && isset($msgResult['data']['messages']) && is_array($msgResult['data']['messages']) && count($msgResult['data']['messages']) > 0) {
                $lastMsg = $msgResult['data']['messages'][0];
            }
            // Fallback ke lastMessage dari chat jika tidak ada pesan
            if (!$lastMsg && isset($chat['lastMessage'])) {
                $lastMsg = $chat['lastMessage'];
            }
            // Gabungkan
            $chat['lastMessage'] = $lastMsg;
            $enhancedChats[] = $chat;
        }
        
        echo json_encode(['success' => true, 'data' => $enhancedChats]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Gagal mengambil daftar chat',
            'code' => $result['code'],
            'detail' => $result['raw']
        ]);
    }
    exit;
}

// ===== GET MESSAGES =====
if ($action === 'get_messages') {
    $chatId = $_GET['chatId'] ?? '';
    if (!$chatId) {
        echo json_encode(['success' => false, 'error' => 'chatId required']);
        exit;
    }
    $limit = $_GET['limit'] ?? 50;
    $encoded = urlencode($chatId);
    
    $result = callOpenWA("/sessions/{$sessionId}/messages?chatId={$encoded}&limit={$limit}");
    
    if ($result['code'] == 200 && isset($result['data']['messages']) && is_array($result['data']['messages'])) {
        $messages = array_reverse($result['data']['messages']);
        echo json_encode(['success' => true, 'data' => $messages]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Gagal mengambil pesan',
            'code' => $result['code'],
            'detail' => $result['raw']
        ]);
    }
    exit;
}

// ===== SEND TEXT =====
if ($action === 'send') {
    $chatId = $_POST['chatId'] ?? '';
    $text = $_POST['text'] ?? '';
    if (!$chatId || !$text) {
        echo json_encode(['success' => false, 'error' => 'chatId dan text required']);
        exit;
    }
    $result = callOpenWA("/sessions/{$sessionId}/messages/send-text", 'POST', [
        'chatId' => $chatId,
        'text' => $text
    ]);
    if ($result['code'] == 200 || $result['code'] == 201) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['data']['message'] ?? 'Gagal kirim',
            'code' => $result['code'],
            'detail' => $result['raw']
        ]);
    }
    exit;
}

// ===== SEND MEDIA (Image/GIF) =====
if ($action === 'send_media') {
    $chatId = $_POST['chatId'] ?? '';
    $type = $_POST['type'] ?? 'image';
    
    if (!$chatId) {
        echo json_encode(['success' => false, 'error' => 'chatId required']);
        exit;
    }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'File upload error']);
        exit;
    }
    
    $file = $_FILES['file'];
    $filePath = $file['tmp_name'];
    $fileName = $file['name'];
    $mimeType = mime_content_type($filePath);
    $fileContent = base64_encode(file_get_contents($filePath));
    
    $data = [
        'chatId' => $chatId,
        'media' => $fileContent,
        'fileName' => $fileName,
        'mimeType' => $mimeType
    ];
    
    $endpoint = ($type === 'gif') ? '/sessions/' . $sessionId . '/messages/send-gif' : '/sessions/' . $sessionId . '/messages/send-image';
    $result = callOpenWA($endpoint, 'POST', $data);
    
    if ($result['code'] == 200 || $result['code'] == 201) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['data']['message'] ?? 'Gagal kirim media',
            'code' => $result['code'],
            'detail' => $result['raw']
        ]);
    }
    exit;
}

// ===== SEND FILE =====
if ($action === 'send_file') {
    $chatId = $_POST['chatId'] ?? '';
    
    if (!$chatId) {
        echo json_encode(['success' => false, 'error' => 'chatId required']);
        exit;
    }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'File upload error']);
        exit;
    }
    
    $file = $_FILES['file'];
    $filePath = $file['tmp_name'];
    $fileName = $file['name'];
    $mimeType = mime_content_type($filePath);
    $fileContent = base64_encode(file_get_contents($filePath));
    
    $data = [
        'chatId' => $chatId,
        'media' => $fileContent,
        'fileName' => $fileName,
        'mimeType' => $mimeType
    ];
    
    $result = callOpenWA("/sessions/{$sessionId}/messages/send-file", 'POST', $data);
    
    if ($result['code'] == 200 || $result['code'] == 201) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['data']['message'] ?? 'Gagal kirim file',
            'code' => $result['code'],
            'detail' => $result['raw']
        ]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);