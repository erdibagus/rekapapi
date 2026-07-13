<?php
// =====================================================
// PAYMENTS API
// GET  /api/payments/?bulan=&tahun=  → list rekap bulan
// GET  /api/payments/?pelanggan_id=  → history 6 bulan
// PUT  /api/payments/?id=            → tandai lunas / batalkan
// POST /api/payments/generate        → generate bulan baru
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
    // History per pelanggan (6 bulan)
    if (isset($_GET['pelanggan_id'])) {
        $pelId = (int)$_GET['pelanggan_id'];
        $stmt = $db->prepare("
            SELECT py.*, pl.nama AS pelanggan_nama, pk.nama AS paket_nama
            FROM payments py
            JOIN pelanggan pl ON pl.id = py.pelanggan_id
            JOIN pakets pk ON pk.id = pl.paket_id
            WHERE py.pelanggan_id = ?
            ORDER BY py.tahun DESC, py.bulan DESC
            LIMIT 12
        ");
        $stmt->execute([$pelId]);
        jsonResponse(true, 'OK', $stmt->fetchAll());
    }

    // Rekap per bulan (default bulan ini)
    $bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('n');
    $tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

    // Auto-generate payments jika belum ada untuk bulan yang diminta
    $countCheck = $db->prepare(
        "SELECT COUNT(*) FROM payments WHERE bulan = ? AND tahun = ?"
    );
    $countCheck->execute([$bulan, $tahun]);
    if ($countCheck->fetchColumn() == 0) {
        generatePaymentsForMonth($db, $bulan, $tahun);
    }

    $stmt = $db->prepare("
        SELECT py.*,
               pl.nama AS pelanggan_nama, pl.telepon, pl.alamat,
               pk.nama AS paket_nama, pk.harga AS paket_harga
        FROM payments py
        JOIN pelanggan pl ON pl.id = py.pelanggan_id
        JOIN pakets pk ON pk.id = pl.paket_id
        WHERE py.bulan = ? AND py.tahun = ?
        ORDER BY pl.nama ASC
    ");
    $stmt->execute([$bulan, $tahun]);
    jsonResponse(true, 'OK', $stmt->fetchAll());
}

// ───── PUT (Tandai Lunas / Batalkan) ───────────────
if ($method === 'PUT') {
    if (!$id) jsonResponse(false, 'ID payment diperlukan', null, 400);

    $body       = getRequestBody();
    $lunas      = isset($body['lunas']) ? (bool)$body['lunas'] : null;
    $keterangan = $body['keterangan'] ?? null;

    if ($lunas === null) {
        jsonResponse(false, 'Field lunas diperlukan', null, 400);
    }

    if ($lunas) {
        $tgl_bayar  = $body['tgl_bayar'] ?? date('Y-m-d');
        $keterangan = $keterangan ?? 'Pembayaran via tunai';
        $stmt = $db->prepare("
            UPDATE payments SET lunas = 1, tgl_bayar = ?, keterangan = ? WHERE id = ?
        ");
        $stmt->execute([$tgl_bayar, $keterangan, $id]);
    } else {
        $stmt = $db->prepare("
            UPDATE payments SET lunas = 0, tgl_bayar = NULL, keterangan = NULL WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    if ($stmt->rowCount() === 0) {
        // Cek apakah record ada
        $check = $db->prepare("SELECT id FROM payments WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) jsonResponse(false, 'Payment tidak ditemukan', null, 404);
    }

    $row = $db->query("
        SELECT py.*, pl.nama AS pelanggan_nama, pl.telepon,
               pk.nama AS paket_nama
        FROM payments py
        JOIN pelanggan pl ON pl.id = py.pelanggan_id
        JOIN pakets pk ON pk.id = pl.paket_id
        WHERE py.id = $id
    ")->fetch();

    jsonResponse(true, $lunas ? 'Pembayaran berhasil ditandai lunas' : 'Pembayaran berhasil dibatalkan', $row);
}

// ───── POST (Generate) ─────────────────────────────
if ($method === 'POST') {
    $body  = getRequestBody();
    $bulan = isset($body['bulan']) ? (int)$body['bulan'] : (int)date('n');
    $tahun = isset($body['tahun']) ? (int)$body['tahun'] : (int)date('Y');

    $generated = generatePaymentsForMonth($db, $bulan, $tahun);
    jsonResponse(true, "$generated record payment berhasil di-generate", ['generated' => $generated]);
}

jsonResponse(false, 'Method tidak diizinkan', null, 405);

// ───── Helper ──────────────────────────────────────
function generatePaymentsForMonth(PDO $db, int $bulan, int $tahun): int {
    $stmt = $db->query("
        SELECT p.id, pk.harga
        FROM pelanggan p
        JOIN pakets pk ON pk.id = p.paket_id
        WHERE p.status = 'aktif'
    ");
    $customers = $stmt->fetchAll();

    $count = 0;
    $ins = $db->prepare("
        INSERT IGNORE INTO payments (pelanggan_id, bulan, tahun, nominal, lunas)
        VALUES (?, ?, ?, ?, 0)
    ");
    foreach ($customers as $c) {
        $ins->execute([$c['id'], $bulan, $tahun, $c['harga']]);
        $count += $ins->rowCount();
    }
    return $count;
}
