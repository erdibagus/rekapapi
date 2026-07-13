<?php
// =====================================================
// PELANGGAN API - CRUD Pelanggan
// GET    /api/pelanggan/     → list semua (join paket)
// POST   /api/pelanggan/     → tambah pelanggan
// PUT    /api/pelanggan/?id= → update pelanggan
// DELETE /api/pelanggan/?id= → hapus pelanggan
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
    if ($id) {
        // Detail satu pelanggan
        $stmt = $db->prepare("
            SELECT p.*, pk.nama AS paket_nama, pk.harga AS paket_harga
            FROM pelanggan p
            LEFT JOIN pakets pk ON pk.id = p.paket_id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(false, 'Pelanggan tidak ditemukan', null, 404);
        jsonResponse(true, 'OK', $row);
    }

    // Semua pelanggan
    $stmt = $db->query("
        SELECT p.*, pk.nama AS paket_nama, pk.harga AS paket_harga
        FROM pelanggan p
        LEFT JOIN pakets pk ON pk.id = p.paket_id
        ORDER BY p.nama ASC
    ");
    jsonResponse(true, 'OK', $stmt->fetchAll());
}

// ───── POST (Tambah) ────────────────────────────────
if ($method === 'POST') {
    $body        = getRequestBody();
    $nama        = trim($body['nama'] ?? '');
    $alamat      = trim($body['alamat'] ?? '');
    $telepon     = trim($body['telepon'] ?? '');
    $paket_id    = (int)($body['paket_id'] ?? 0);
    $jatuh_tempo = (int)($body['jatuh_tempo'] ?? 1);
    $status      = $body['status'] ?? 'aktif';
    $bergabung   = $body['bergabung'] ?? date('Y-m-d');

    if (!$nama || !$alamat || !$telepon || !$paket_id) {
        jsonResponse(false, 'Nama, alamat, telepon, dan paket wajib diisi', null, 400);
    }
    if (!in_array($status, ['aktif', 'nonaktif'])) $status = 'aktif';
    if ($jatuh_tempo < 1 || $jatuh_tempo > 31) $jatuh_tempo = 1;

    $stmt = $db->prepare("
        INSERT INTO pelanggan (nama, alamat, telepon, paket_id, jatuh_tempo, status, bergabung)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$nama, $alamat, $telepon, $paket_id, $jatuh_tempo, $status, $bergabung]);
    $newId = $db->lastInsertId();

    // Generate payment bulan ini untuk pelanggan baru
    generateMonthlyPayment($db, $newId, $paket_id);

    $row = fetchPelangganById($db, $newId);
    jsonResponse(true, 'Pelanggan berhasil ditambahkan', $row, 201);
}

// ───── PUT (Update) ─────────────────────────────────
if ($method === 'PUT') {
    if (!$id) jsonResponse(false, 'ID pelanggan diperlukan', null, 400);

    $body        = getRequestBody();
    $nama        = trim($body['nama'] ?? '');
    $alamat      = trim($body['alamat'] ?? '');
    $telepon     = trim($body['telepon'] ?? '');
    $paket_id    = (int)($body['paket_id'] ?? 0);
    $jatuh_tempo = (int)($body['jatuh_tempo'] ?? 1);
    $status      = $body['status'] ?? 'aktif';
    $bergabung   = $body['bergabung'] ?? date('Y-m-d');

    if (!$nama || !$alamat || !$telepon || !$paket_id) {
        jsonResponse(false, 'Nama, alamat, telepon, dan paket wajib diisi', null, 400);
    }
    if (!in_array($status, ['aktif', 'nonaktif'])) $status = 'aktif';
    if ($jatuh_tempo < 1 || $jatuh_tempo > 31) $jatuh_tempo = 1;

    $stmt = $db->prepare("
        UPDATE pelanggan
        SET nama = ?, alamat = ?, telepon = ?, paket_id = ?,
            jatuh_tempo = ?, status = ?, bergabung = ?
        WHERE id = ?
    ");
    $stmt->execute([$nama, $alamat, $telepon, $paket_id, $jatuh_tempo, $status, $bergabung, $id]);

    if ($stmt->rowCount() === 0) {
        // Bisa jadi tidak ada perubahan, cek apakah record ada
        $check = $db->prepare("SELECT id FROM pelanggan WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) jsonResponse(false, 'Pelanggan tidak ditemukan', null, 404);
    }

    $row = fetchPelangganById($db, $id);
    jsonResponse(true, 'Pelanggan berhasil diperbarui', $row);
}

// ───── DELETE ───────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) jsonResponse(false, 'ID pelanggan diperlukan', null, 400);

    // Payments akan terhapus otomatis (CASCADE)
    $stmt = $db->prepare("DELETE FROM pelanggan WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(false, 'Pelanggan tidak ditemukan', null, 404);
    }

    jsonResponse(true, 'Pelanggan berhasil dihapus');
}

jsonResponse(false, 'Method tidak diizinkan', null, 405);

// ───── Helper functions ────────────────────────────
function fetchPelangganById(PDO $db, int $id): ?array {
    $stmt = $db->prepare("
        SELECT p.*, pk.nama AS paket_nama, pk.harga AS paket_harga
        FROM pelanggan p
        LEFT JOIN pakets pk ON pk.id = p.paket_id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function generateMonthlyPayment(PDO $db, int $pelangganId, int $paketId): void {
    $bulan = (int)date('n');
    $tahun = (int)date('Y');

    $stmt = $db->prepare("SELECT harga FROM pakets WHERE id = ?");
    $stmt->execute([$paketId]);
    $paket = $stmt->fetch();
    if (!$paket) return;

    $nominal = (int)$paket['harga'];

    $stmt = $db->prepare("
        INSERT IGNORE INTO payments (pelanggan_id, bulan, tahun, nominal, lunas)
        VALUES (?, ?, ?, ?, 0)
    ");
    $stmt->execute([$pelangganId, $bulan, $tahun, $nominal]);
}
