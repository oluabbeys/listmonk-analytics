<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function isLoggedIn() {
    return !empty($_SESSION['authenticated']);
}

function requireAuthPage() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireAuthApi() {
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}
