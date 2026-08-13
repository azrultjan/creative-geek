<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isOwner() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'owner';
}

function isOperator() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'operator';
}

function isDesainer() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'desainer';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin() && !isOwner()) {
        die('Akses ditolak.');
    }
}

function requireOwner() {
    requireLogin();
    if (!isOwner()) {
        die('Akses ditolak. Hanya owner yang bisa mengakses.');
    }
}

function requireOperator() {
    requireLogin();
    if (!isOperator()) {
        die('Akses ditolak. Hanya operator yang bisa mengakses.');
    }
}
?>
