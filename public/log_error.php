<?php
/*function writeLog($message, $level = 'ERROR') {
    $logFile = __DIR__ . '/logs/error.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
    $logEntry = "[$timestamp] [$level] [IP: $ip] [URI: $uri] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
$logFile = __DIR__ . '/logs/error.log';
if (!file_exists($logFile)) { touch($logFile); chmod($logFile, 0666); }*/


function writeLog($message, $level = 'ERROR') {
    // Logging dimatikan untuk mengurangi beban CPU
    return;
}