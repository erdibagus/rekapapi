<?php
// =====================================================
// WHATSAPP BROADCAST API
// POST /api/whatsapp/broadcast.php
// Body: { "jenis": "gangguan_internet" | "pemeliharaan", "pesan_tambahan": "..." }
// =====================================================
date_default_timezone_set('Asia/Jakarta');
require_once '../../config/database.php';
require_once '../../config/helpers.php';

setCorsHeaders();
handlePreflight();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(false, 'Method tidak diizinkan', null, 405);
}

$body  = getRequestBody();
$jenis = $body['jenis'] ?? '';
$pesanTambahan = trim($body['pesan_tambahan'] ?? '');

if (!in_array($jenis, ['gangguan_internet', 'pemeliharaan', 'jaringan_normal'])) {
    jsonResponse(false, 'Jenis notifikasi tidak valid. Gunakan: gangguan_internet, pemeliharaan, atau jaringan_normal', null, 400);
}

$db = getDB();

// Ambil semua pelanggan aktif beserta nomor telepon
$stmt = $db->query("
    SELECT p.id, p.nama, p.telepon, pk.nama AS paket_nama
    FROM pelanggan p
    JOIN pakets pk ON pk.id = p.paket_id
    WHERE p.status = 'aktif'
    ORDER BY p.nama ASC
");
$pelangganList = $stmt->fetchAll();

if (empty($pelangganList)) {
    jsonResponse(false, 'Tidak ada pelanggan aktif', null, 404);
}

// Buat template pesan sesuai jenis
$waktuSekarang = date('d/m/Y H:i') . ' WIB';

if ($jenis === 'gangguan_internet') {
    $templatePesan =
        "⚠️ *Informasi Gangguan Jaringan*\n\n" .
        "Kepada Yth. Pelanggan *BNPWiFi*,\n\n" .
        "Kami informasikan bahwa saat ini sedang terjadi *gangguan pada sumber internet* yang berdampak pada koneksi Anda.\n\n" .
        "🔧 *Tim teknisi sedang bekerja keras* untuk memulihkan layanan secepatnya.\n\n" .
        "📅 Waktu laporan: {$waktuSekarang}\n\n";
    if (!empty($pesanTambahan)) {
        $templatePesan .= "📝 Keterangan: {$pesanTambahan}\n\n";
    }
    $templatePesan .=
        "Mohon maaf atas ketidaknyamanan yang ditimbulkan.\n\n" .
        "Terima kasih atas pengertian Anda 🙏\n" .
        "_— BNPWiFi_";
} elseif ($jenis === 'jaringan_normal') {
    $templatePesan =
        "✅ *Jaringan Kembali Normal*\n\n" .
        "Kepada Yth. Pelanggan *BNPWiFi*,\n\n" .
        "Kami informasikan bahwa layanan internet Anda telah *kembali normal* dan dapat digunakan seperti biasa.\n\n" .
        "📅 Waktu pemulihan: {$waktuSekarang}\n\n";
    if (!empty($pesanTambahan)) {
        $templatePesan .= "📝 Keterangan: {$pesanTambahan}\n\n";
    }
    $templatePesan .=
        "Terima kasih atas kesabaran dan pengertiannya 🙏\n" .
        "_— BNPWiFi_";
} else {
    // pemeliharaan
    $templatePesan =
        "🔧 *Informasi Pemeliharaan Server & Jaringan*\n\n" .
        "Kepada Yth. Pelanggan *BNPWiFi*,\n\n" .
        "Kami informasikan bahwa akan dilakukan *pemeliharaan server dan jaringan* dalam waktu dekat.\n\n" .
        "⏱️ Selama proses pemeliharaan berlangsung, layanan internet mungkin akan *terganggu sementara*.\n\n" .
        "📅 Waktu pemberitahuan: {$waktuSekarang}\n\n";
    if (!empty($pesanTambahan)) {
        $templatePesan .= "📝 Detail: {$pesanTambahan}\n\n";
    }
    $templatePesan .=
        "Kami akan berusaha meminimalkan dampak gangguan. Mohon maaf atas ketidaknyamanannya 🙏\n\n" .
        "_— BNPWiFi_";
}

// Kirim WA ke masing-masing pelanggan
$berhasil  = 0;
$gagal     = 0;
$hasil     = [];

kirimWABroadcast("628562774511", $jenis);

foreach ($pelangganList as $pel) {
    $noHp = preg_replace('/\D/', '', $pel['telepon'] ?? '');
    if (empty($noHp)) {
        $gagal++;
        $hasil[] = ['nama' => $pel['nama'], 'status' => 'skip', 'alasan' => 'No. HP kosong'];
        continue;
    }
    if (substr($noHp, 0, 1) === '0') {
        $noHp = '62' . substr($noHp, 1);
    }

    // Personalisasi pesan dengan nama pelanggan
    $pesanPersonal = str_replace(
        'Kepada Yth. Pelanggan *BNPWiFi*,',
        "Kepada Yth. *{$pel['nama']}*,",
        $templatePesan
    );

    $ok = kirimWABroadcast($noHp, $pesanPersonal);
    if ($ok) {
        $berhasil++;
        $hasil[] = ['nama' => $pel['nama'], 'status' => 'ok'];
    } else {
        $gagal++;
        $hasil[] = ['nama' => $pel['nama'], 'status' => 'gagal'];
    }

    // Jeda kecil antar pesan agar tidak dianggap spam
    usleep(500000); // 0.5 detik
}

jsonResponse(true, "Broadcast selesai: {$berhasil} berhasil, {$gagal} gagal", [
    'total'    => count($pelangganList),
    'berhasil' => $berhasil,
    'gagal'    => $gagal,
    'detail'   => $hasil,
]);

// ───── Helper: Kirim WA ────────────────────────────
function kirimWABroadcast(string $noHp, string $pesan): bool {
    $payload = json_encode(['to' => $noHp, 'message' => $pesan]);
    $url = 'https://bnp.valentine.biz.id/wabot/send-message';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $response === false || $httpCode !== 200) return false;
    $json = json_decode($response, true);
    return !empty($json['status']) && $json['status'] === true;
}
