<?php
// =====================================================
// PAYMENTS API
// GET  /api/payments/?bulan=&tahun=  → list rekap bulan
// GET  /api/payments/?pelanggan_id=  → history 6 bulan
// PUT  /api/payments/?id=            → tandai lunas / batalkan
// PUT  /api/payments/?id=&resend_wa=1 → kirim ulang WA
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

// ───── PUT (Tandai Lunas / Batalkan / Resend WA) ───
if ($method === 'PUT') {
    if (!$id) jsonResponse(false, 'ID payment diperlukan', null, 400);

    // ── Resend WA ──────────────────────────────────
    if (isset($_GET['resend_wa']) && $_GET['resend_wa'] == 1) {
        $row = $db->query("
            SELECT py.*, pl.nama AS pelanggan_nama, pl.telepon,
                   pk.nama AS paket_nama
            FROM payments py
            JOIN pelanggan pl ON pl.id = py.pelanggan_id
            JOIN pakets pk ON pk.id = pl.paket_id
            WHERE py.id = $id AND py.lunas = 1
        ")->fetch();

        if (!$row) {
            jsonResponse(false, 'Payment tidak ditemukan atau belum lunas', null, 404);
        }

        $waSent = sendWhatsAppNotification($row);
        $upd = $db->prepare("UPDATE payments SET wa_sent = ? WHERE id = ?");
        $upd->execute([$waSent ? 1 : 0, $id]);

        $row['wa_sent'] = $waSent ? 1 : 0;
        jsonResponse(true, $waSent ? 'Notifikasi WA berhasil dikirim ulang' : 'Gagal kirim WA', $row);
    }

    // ── Tandai Lunas / Batalkan ────────────────────
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
            UPDATE payments SET lunas = 1, tgl_bayar = ?, keterangan = ?, wa_sent = 0 WHERE id = ?
        ");
        $stmt->execute([$tgl_bayar, $keterangan, $id]);
    } else {
        $stmt = $db->prepare("
            UPDATE payments SET lunas = 0, tgl_bayar = NULL, keterangan = NULL, wa_sent = 0 WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    if ($stmt->rowCount() === 0) {
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

    // Kirim WA dari backend saat tandai lunas
    if ($lunas) {
        $waSent = sendWhatsAppNotification($row);
        $upd = $db->prepare("UPDATE payments SET wa_sent = ? WHERE id = ?");
        $upd->execute([$waSent ? 1 : 0, $id]);
        $row['wa_sent'] = $waSent ? 1 : 0;
    }

    jsonResponse(
        true,
        $lunas ? 'Pembayaran berhasil ditandai lunas' : 'Pembayaran berhasil dibatalkan',
        $row
    );
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

// ───── Helper: Generate Payments ───────────────────
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
        INSERT IGNORE INTO payments (pelanggan_id, bulan, tahun, nominal, lunas, wa_sent)
        VALUES (?, ?, ?, ?, 0, 0)
    ");
    foreach ($customers as $c) {
        $ins->execute([$c['id'], $bulan, $tahun, $c['harga']]);
        $count += $ins->rowCount();
    }
    return $count;
}

// ───── Helper: Kirim WA dari Backend ───────────────
function sendWhatsAppNotification(array $row): bool {
    $noHp = preg_replace('/\D/', '', $row['telepon'] ?? '');
    if (empty($noHp)) return false;
    if (substr($noHp, 0, 1) === '0') {
        $noHp = '62' . substr($noHp, 1);
    }

    $namaBulan = getNamaBulanPhp((int)$row['bulan']);
    $nominal   = 'Rp ' . number_format((int)$row['nominal'], 0, ',', '.');
    $tglBayar  = !empty($row['tgl_bayar'])
        ? date('d F Y', strtotime($row['tgl_bayar']))
        : '-';

    $pesan =
        "✅ *Konfirmasi Pembayaran WiFi*\n\n" .
        "Halo, *{$row['pelanggan_nama']}*!\n\n" .
        "Pembayaran WiFi Anda telah kami terima.\n" .
        "📦 Paket   : {$row['paket_nama']}\n" .
        "📅 Periode : {$namaBulan} {$row['tahun']}\n" .
        "🗓️ Tgl Bayar: {$tglBayar}\n" .
        "💰 Nominal : {$nominal}\n\n" .
        "Terima kasih telah membayar tepat waktu! 🙏\n" .
        "_— BNPWiFi_";

    $payload = json_encode(['to' => $noHp, 'message' => $pesan]);

    $ch = curl_init('https://bnp.valentine.biz.id/wabot/send-message');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || $response === false) return false;

    $json = json_decode($response, true);
    // API WA mengembalikan: {"status": true, "message": "Pesan berhasil dikirim."}
    return isset($json['status']) && $json['status'] === true;
}

// ───── Helper: Nama Bulan (PHP) ────────────────────
function getNamaBulanPhp(int $bulan): string {
    $nama = [
        1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',
        5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',
        9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
    ];
    return $nama[$bulan] ?? '';
}
