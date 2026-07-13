<?php
// =====================================================
// PAKETS API - CRUD Paket WiFi
// GET    /api/pakets/     → list semua paket
// POST   /api/pakets/     → tambah paket baru
// PUT    /api/pakets/?id= → update paket
// DELETE /api/pakets/?id= → hapus paket
// =====================================================

require_once '../../config/database.php';
require_once '../../config/helpers.php';

setCorsHeaders();
handlePreflight();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// ───── GET ─────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $db->query("SELECT * FROM pakets ORDER BY harga ASC");
    $data = $stmt->fetchAll();
    jsonResponse(true, 'OK', $data);
}

// ───── POST (Tambah) ────────────────────────────────
if ($method === 'POST') {
    $body  = getRequestBody();
    $nama  = trim($body['nama'] ?? '');
    $harga = (int)($body['harga'] ?? 0);

    if (!$nama || $harga <= 0) {
        jsonResponse(false, 'Nama dan harga wajib diisi', null, 400);
    }

    $stmt = $db->prepare("INSERT INTO pakets (nama, harga) VALUES (?, ?)");
    $stmt->execute([$nama, $harga]);
    $newId = $db->lastInsertId();

    $row = $db->query("SELECT * FROM pakets WHERE id = $newId")->fetch();
    jsonResponse(true, 'Paket berhasil ditambahkan', $row, 201);
}

// ───── PUT (Update) ────────────────────────────────
if ($method === 'PUT') {
    if (!$id) jsonResponse(false, 'ID paket diperlukan', null, 400);

    $body  = getRequestBody();
    $nama  = trim($body['nama'] ?? '');
    $harga = (int)($body['harga'] ?? 0);

    if (!$nama || $harga <= 0) {
        jsonResponse(false, 'Nama dan harga wajib diisi', null, 400);
    }

    $stmt = $db->prepare("UPDATE pakets SET nama = ?, harga = ? WHERE id = ?");
    $stmt->execute([$nama, $harga, $id]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(false, 'Paket tidak ditemukan', null, 404);
    }

    $row = $db->query("SELECT * FROM pakets WHERE id = $id")->fetch();
    jsonResponse(true, 'Paket berhasil diperbarui', $row);
}

// ───── DELETE ───────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) jsonResponse(false, 'ID paket diperlukan', null, 400);

    // Cek apakah paket masih digunakan
    $check = $db->prepare("SELECT COUNT(*) FROM pelanggan WHERE paket_id = ?");
    $check->execute([$id]);
    $count = $check->fetchColumn();

    if ($count > 0) {
        jsonResponse(false, "Paket tidak bisa dihapus, masih digunakan oleh $count pelanggan", null, 409);
    }

    $stmt = $db->prepare("DELETE FROM pakets WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(false, 'Paket tidak ditemukan', null, 404);
    }

    jsonResponse(true, 'Paket berhasil dihapus');
}

jsonResponse(false, 'Method tidak diizinkan', null, 405);
