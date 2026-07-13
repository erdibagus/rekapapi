<?php
// =====================================================
// CORS & JSON Helper - RekapBNP WiFi API
// =====================================================

function setCorsHeaders(): void {
    $allowed_origins = [
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
        'https://rekapapi.valentine.biz.id',
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed_origins, true)) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        // Fallback: izinkan semua saat development lokal
        header("Access-Control-Allow-Origin: *");
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json; charset=utf-8');
}

function handlePreflight(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function jsonResponse(bool $success, string $message, $data = null, int $code = 200): void {
    http_response_code($code);
    $resp = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $resp['data'] = $data;
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

function getRequestBody(): array {
    $body = file_get_contents('php://input');
    return json_decode($body, true) ?? [];
}

function requireMethod(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        jsonResponse(false, 'Method tidak diizinkan', null, 405);
    }
}
