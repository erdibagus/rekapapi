<?php
// =====================================================
// Database Configuration - RekapBNP WiFi API
// =====================================================

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'kumonpur_rekapwifi');
define('DB_USER', 'kumonpur_bagus');
define('DB_PASS', 'gagaso123!');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Koneksi database gagal: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}
