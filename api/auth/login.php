<?php
// =====================================================
// AUTH - Login API
// POST /api/auth/login.php
// Body: { "username": "...", "password": "..." }
// =====================================================

require_once '../../config/database.php';
require_once '../../config/helpers.php';

setCorsHeaders();
handlePreflight();
requireMethod('POST');

$body = getRequestBody();
$username = trim($body['username'] ?? '');
$password  = trim($body['password'] ?? '');

if (!$username || !$password) {
    jsonResponse(false, 'Username dan password wajib diisi', null, 400);
}

try {
    $db  = getDB();
    $sql = "SELECT id, username, password, name, role, avatar FROM users WHERE username = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        jsonResponse(false, 'Username atau password salah', null, 401);
    }

    // Hapus password dari response
    unset($user['password']);

    jsonResponse(true, 'Login berhasil', $user);
} catch (PDOException $e) {
    jsonResponse(false, 'Server error: ' . $e->getMessage(), null, 500);
}
