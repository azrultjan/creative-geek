<?php
$host = '172.19.0.3';
$dbname = 'creative_geek';
$username = 'root';
$password = 'root';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET CHARACTER SET utf8mb4");
    $pdo->exec("SET SESSION character_set_client = utf8mb4");
    $pdo->exec("SET SESSION character_set_connection = utf8mb4");
    $pdo->exec("SET SESSION character_set_results = utf8mb4");
    
    // ===== SET TIMEZONE KE WITA =====
    $pdo->exec("SET SESSION time_zone = '+08:00'");
    
} catch(PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}
?>