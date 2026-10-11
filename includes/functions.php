<?php
/**
 * includes/functions.php
 * =====================================================================
 * Fungsi bisnis umum: nomor order, WhatsApp, status, settings, log.
 * Semua kredensial WhatsApp diambil dari config/services.php.
 * =====================================================================
 */

/**
 * Generate nomor order unik — FORMAT ASLI: CG + ddmmyy + 4 digit (tanpa slash).
 * Contoh: CG0608261353
 */
function generateOrderNumber(): string
{
    $date = date('dmy');
    $random = str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    return 'CG' . $date . $random;
}

/**
 * Normalisasi nomor HP Indonesia ke format 62...
 */
function normalizePhone($phone): string
{
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);
    if (substr($phone, 0, 1) === '0') {
        $phone = '62' . substr($phone, 1);
    }
    if (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }
    return $phone;
}

/**
 * Apakah pesan yang SAMA baru saja benar-benar terkirim ke nomor ini?
 * Dipakai sebagai pengaman sebelum MENGIRIM ULANG: mencegah pelanggan menerima
 * pesan dobel ketika gateway membalas error padahal pesannya sudah masuk.
 *
 * @return bool true = sudah terkirim (jangan kirim ulang).
 */
function wa_sent_recently(string $phone, string $message, int $windowSec = 180): bool
{
    try {
        // Hanya berlaku utk pesan teks biasa (bukan media) & pesan yang cukup
        // khas — supaya teks pendek generik ("Halo") tidak salah dianggap sama.
        if ($message === '' || mb_strlen($message) < 30) return false;

        $cfg = whatsapp_config();
        if (empty($cfg['session_id'])) return false;

        $chatId = normalizePhone($phone) . '@c.us';
        // Timeout pendek: ini pengecekan cepat, TIDAK boleh menahan permintaan
        // operator terlalu lama (dipanggil di tengah alur simpan pesanan).
        $res = callOpenWA('/api/sessions/' . rawurlencode((string)$cfg['session_id'])
            . '/messages?chatId=' . rawurlencode($chatId) . '&limit=10', 'GET', null, 5);
        if ($res['code'] < 200 || $res['code'] >= 300) return false;

        $rows = $res['data']['messages'] ?? null;
        if (!is_array($rows)) return false;

        $now = time();
        foreach ($rows as $m) {
            if ((string)($m['direction'] ?? '') !== 'outgoing') continue;
            if ((string)($m['body'] ?? '') !== $message) continue;
            $ts = wa_msg_ts($m['timestamp'] ?? 0);
            // timestamp bisa kosong di sebagian engine → anggap baru saja.
            if ($ts > 0 && ($now - $ts) > $windowSec) continue;
            return true; // sudah terkirim baru-baru ini
        }
    } catch (Throwable $e) {
        // Gagal memverifikasi → jangan mengubah perilaku (biarkan alur normal).
        return false;
    }
    return false;
}

/**
 * Kirim notifikasi WhatsApp (OpenWA/WAHA) — konfigurasi terpusat.
 *
 * @param string      $phone    Nomor HP (dalam format apa pun).
 * @param string      $message  Isi pesan.
 * @param string|null $media    (opsional) URL media yang dilampirkan.
 * @param bool        $fastMode true = dipakai di alur INTERAKTIF (operator
 *                              sedang menunggu di layar): anggaran waktu ketat
 *                              & tidak menunggu lama. false = alur background
 *                              (cron / notifikasi internal): boleh lebih longgar.
 * @return bool true jika berhasil (HTTP 2xx).
 */
function sendWhatsAppNotification(string $phone, string $message, ?string $media = null, bool $fastMode = false): bool
{
    $cfg = whatsapp_config();
    if (empty($cfg['api_url']) || empty($cfg['api_key']) || empty($cfg['session_id'])) {
        writeLog('WhatsApp tidak terkonfigurasi (cek config/services.php / .env)', 'ERROR');
        return false;
    }

    $phone = normalizePhone($phone);
    if ($phone === '') {
        writeLog('WhatsApp: nomor HP kosong setelah normalisasi', 'ERROR');
        return false;
    }

    $chatId = $phone . '@c.us';
    $url = rtrim($cfg['api_url'], '/')
        . '/api/sessions/' . rawurlencode($cfg['session_id']) . '/messages/send-text';

    $data = ['chatId' => $chatId, 'text' => $message];
    if (!empty($media)) {
        $data['media'] = $media;
    }
    // JSON_UNESCAPED_UNICODE: kirim emoji/teks non-ASCII sebagai UTF-8 asli
    // (bukan \uXXXX) supaya tidak rusak jadi mojibake (mis. ⚠️ jadi "âš ï¸").

    // Fungsi kirim sekali (dipakai retry di bawah).
    $attempt = static function () use ($url, $data, $cfg, $fastMode) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Timeout 35 dtk: OpenWA kadang lambat return HTTP response meski pesan
        // SUDAH terkirim (terbukti: log OpenWA "Message sent" tp PHP timeout 10s).
        // Timeout 10s terlalu agresif → false negative (pesan terkirim tapi dianggap
        // GAGAL). Naikkan supaya response sempat kembali.
        // Mode cepat (operator menunggu): dibatasi 8 dtk supaya SATU percobaan
        // tidak pernah menahan permintaan lebih lama dari anggaran total.
        curl_setopt($ch, CURLOPT_TIMEOUT, $fastMode ? 8 : 35);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $fastMode ? 3 : 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // Paksa IPv4: di Docker macOS, host.docker.internal punya entri IPv6 yang
        // tidak punya route → curl bisa hang/timeout. IPv4 selalu tersedia.
        if (defined('CURLOPT_IPRESOLVE')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $cfg['api_key'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [$httpCode, $response, $error];
    };

    // =====================================================================
    // RETRY + ANGARAN WAKTU + DETEKSI KEGAGALAN PERMANEN
    // (FIX 2026-09-15 — dipulihkan 2026-09-24; sempat hilang saat 8 file inti
    //  dikembalikan ke versi lama pada 2026-09-22)
    // =====================================================================
    // MASALAH NYATA: dulu SETIAP kegagalan di-retry 2x jeda 3 dtk = ~6 dtk.
    // Saat sesi WA mati, gateway membalas CEPAT HTTP 400
    // "Session '...' is not active. Start the session first." → tetap di-retry
    // → total ~6 dtk. Ditambah panggilan AI (~1-3 dtk), permintaan simpan
    // pesanan melewati batas 4 dtk Cloudflare Worker
    // (tools/maintenance/worker.js: TIMEOUT_MS = 4000) → Worker menyajikan
    // halaman "Main TENIS" PADAHAL PESANAN SUDAH TERSIMPAN.
    // Operator mengira gagal → menekan Simpan lagi → ORDER DUPLIKAT.
    // (Terbukti: CG1409262828/CG1409260366, CG1409266646/CG1409262669.)
    //
    // Solusi: kegagalan PERMANEN tidak di-retry sama sekali; retry hanya untuk
    // gangguan sesaat, dengan anggaran waktu yang tegas supaya fungsi ini tidak
    // pernah menahan permintaan terlalu lama.
    $budgetSec = $fastMode ? 10 : 45;   // batas total waktu untuk SEMUA percobaan
    $startedAt = microtime(true);

    /* Kegagalan PERMANEN: mengulang tidak akan mengubah hasil, hanya membuang
       waktu. Dikenali dari kode 400/401/403 + isi pesan yang khas. */
    $isPermanent = static function (int $code, $body): bool {
        if (!in_array($code, [400, 401, 403], true)) return false;
        $b = strtolower((string)$body);
        foreach (['is not active', 'not connected', 'start the session',
                  'unauthorized', 'invalid api key'] as $needle) {
            if (strpos($b, $needle) !== false) return true;
        }
        return false;
    };

    /* =====================================================================
     * FIX 2026-09-24 — JANGAN ULANGI KIRIMAN YANG HASILNYA TIDAK DIKETAHUI
     * (penyebab "notifikasi terkirim dobel-dobel")
     * =====================================================================
     * BUKTI NYATA (direproduksi):
     * Gateway OpenWA terkadang LAMBAT membalas, padahal pesannya SUDAH masuk
     * & diteruskan. PHP lalu kehabisan waktu (http=0), menganggapnya gagal,
     * dan MENGIRIM ULANG → pelanggan menerima pesan yang SAMA 2x.
     * Uji ulang: 1 panggilan kirim → gateway menerima 2 pesan identik.
     * Di data OpenWA terbukti pasangan pesan dengan waMessageId BERBEDA
     * (bukan 1 pesan tercatat 2x) tapi isi & nomor order sama, dengan jeda
     * 2-6 dtk (= pola jeda retry 1,5 dtk + waktu proses).
     * Log PHP hanya mencatat 1x karena hanya percobaan TERAKHIR yang dilaporkan.
     *
     * ATURAN BARU:
     * - http = 0 (timeout / koneksi putus / tanpa respons) → hasil TIDAK
     *   DIKETAHUI. Pesan mungkin sudah terkirim. JANGAN diulang.
     * - HTTP 5xx → server gateway bermasalah, hasil juga tidak pasti → JANGAN diulang.
     * - HTTP 4xx dgn pesan tegas (mis. "Bad Request" sesaat) → gateway
     *   MENOLAK, jadi pesan PASTI belum masuk → aman diulang.
     * Prinsip: lebih baik pesan TIDAK terkirim daripada terkirim DUA KALI.
     * ===================================================================== */
    $isUnknownOutcome = static function (int $code): bool {
        return $code === 0 || $code >= 500;
    };

    $result = $attempt();
    $httpCode = $result[0];
    $response = $result[1];
    $error    = $result[2];
    for ($i = 0; $i < 2 && ($httpCode < 200 || $httpCode >= 300); $i++) {
        if ($isPermanent($httpCode, $response)) {
            writeLog("WhatsApp TIDAK terkirim (masalah permanen) ke $phone: "
                . substr((string)$response, 0, 200)
                . " — Sesi WhatsApp kemungkinan perlu di-scan ulang.", 'ERROR');
            return false;
        }
        // Hasil tidak diketahui → pesan mungkin SUDAH terkirim ke pelanggan.
        // Mengulang di sini persis yang membuat pesan terkirim dobel.
        if ($isUnknownOutcome($httpCode)) {
            writeLog("WhatsApp: respons tidak pasti (http=$httpCode"
                . ($error !== '' ? " err=$error" : '')
                . ") ke $phone — pesan MUNGKIN sudah terkirim; "
                . "kirim ulang DIBATALKAN supaya pelanggan tidak menerima dobel.", 'WARN');
            return false;
        }
        // PENGAMAN TERAKHIR sebelum mengulang: tanya langsung ke gateway apakah
        // pesan ini SUDAH tercatat terkirim. Gateway kadang MEMBALAS ERROR
        // padahal pesannya sudah masuk & diteruskan (terbukti di data: pesan
        // identik muncul 2x dengan waMessageId berbeda, jeda 2-6 dtk, sementara
        // log PHP hanya mencatat 1x). Kalau sudah terkirim → jangan ulangi.
        if ($i === 0 && wa_sent_recently($phone, $message)) {
            writeLog("WhatsApp: pesan ke $phone ternyata SUDAH terkirim "
                . "(dikonfirmasi dari riwayat gateway) walau respons http=$httpCode "
                . "— kirim ulang dibatalkan.", 'WARN');
            return true;
        }
        // Sisa anggaran tidak cukup untuk satu percobaan lagi → berhenti.
        if ((microtime(true) - $startedAt) + 1.5 > $budgetSec) {
            writeLog("WhatsApp: anggaran waktu {$budgetSec}s habis, retry dihentikan (phone=$phone http=$httpCode).", 'WARN');
            break;
        }
        usleep(1500000); // 1,5 dtk (dulu 3 dtk — terlalu lama utk alur interaktif)
        $result = $attempt();
        $httpCode = $result[0];
        $response = $result[1];
        $error    = $result[2];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        writeLog("WhatsApp gagal: phone=$phone http=$httpCode resp=" . substr((string)$response, 0, 300) . " err=$error", 'ERROR');
        return false;
    }

    // =====================================================================
    // VERIFIKASI LEBIH KETAT (2026-09-22)
    // =====================================================================
    // MASALAH NYATA: dulu fungsi ini `return true` HANYA karena HTTP 2xx.
    // Padahal gateway bisa membalas 200/201 dengan body yang TIDAK berisi
    // messageId — artinya tidak ada pesan yang benar-benar masuk antrian
    // WhatsApp. Untuk fitur seperti reminder nota ke PELANGGAN, "true palsu"
    // berbahaya: halaman bilang "Terkirim", admin mengira pelanggan sudah
    // dihubungi, padahal pesan tidak pernah keluar — dan pelanggan tetap
    // tidak membayar.
    //
    // Sekarang: kalau respons BISA diparse sebagai JSON, WAJIB ada messageId.
    // Kalau body bukan JSON (mis. gateway lama membalas teks polos), tetap
    // dianggap sukses — supaya tidak false-negative.
    // =====================================================================
    $body = trim((string)$response);
    if ($body !== '' && ($body[0] === '{' || $body[0] === '[')) {
        $j = json_decode($body, true);
        if (is_array($j)) {
            $mid = $j['messageId'] ?? ($j['id'] ?? ($j['data']['messageId'] ?? null));
            if (empty($mid)) {
                writeLog("WhatsApp mencurigakan: http=$httpCode tapi TIDAK ada messageId — "
                    . "phone=$phone resp=" . substr($body, 0, 300), 'ERROR');
                return false;
            }
        }
    }

    return true;
}

/**
 * Kirim SALINAN INTERNAL (owner/karyawan) saat ada event penting.
 * Mengirim ke NOMOR + GRUP yang dikonfigurasi di Pengaturan → Notifikasi
 * Monitor RYPC UV (`monitor_notif_phones` / `monitor_notif_groups`).
 *
 * PENTING LINK: pesan internal BOLEH lebih detail (termasuk harga), dan
 * link e-receipt yang dipakai TETAP `notaUrl()` (cloud) — JANGAN pernah
 * mengganti ke IP LAN (192.168.11.x) pada pesan apa pun yang bisa diteruskan
 * pelanggan.
 *
 * @param string $message Isi pesan internal.
 * @return int Jumlah tujuan yang berhasil dikirimi.
 */
function sendInternalNotice(string $message): int
{
    $sent = 0;
    if (trim($message) === '') return 0;

    // Nomor tujuan — default ke Nomor WhatsApp toko.
    $phonesRaw = (string)getSetting('monitor_notif_phones', (string)getSetting('whatsapp_number', ''));
    $phones = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $phonesRaw) ?: [])));
    foreach ($phones as $ph) {
        if (sendWhatsAppNotification($ph, $message)) $sent++;
    }

    // Grup tujuan (@g.us) — default ke ai_groups.
    $groupsRaw = (string)getSetting('monitor_notif_groups', (string)getSetting('ai_groups', ''));
    $groups = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $groupsRaw) ?: []), static fn($g) => strpos($g, '@g.us') !== false));
    foreach ($groups as $gid) {
        if (sendWhatsAppToChat($gid, $message)) $sent++;
    }

    return $sent;
}

/**
 * Kirim pesan WA ke ADMIN produksi (default: user role 'admin' dari tabel users,
 * bisa di-override via setting `notif_admin_phones` comma-separated).
 * Dipakai notif order WA baru / pending kerja ke dion & pace.
 * @return int jumlah tujuan berhasil.
 */
function sendToAdmins(string $message): int
{
    $sent = 0;
    if (trim($message) === '') return 0;

    $raw = (string)getSetting('notif_admin_phones', '');
    $mode = (string)getSetting('notif_admin_mode', '');
    if ($mode === 'none') return 0;

    $phones = [];
    if ($mode === 'selected' || ($mode === '' && $raw !== '')) {
        // Setting eksplisit (comma-separated) menang.
        $phones = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: [])));
    } elseif ($mode === 'auto' || $mode === '') {
        // Default: semua user role 'admin' yang punya nomor.
        try {
            $st = db()->query("SELECT phone FROM users WHERE role='admin' AND phone IS NOT NULL AND phone != ''");
            while ($r = $st->fetch()) {
                $p = normalizePhone((string)$r['phone']);
                if ($p !== '' && !in_array($p, $phones, true)) $phones[] = $p;
            }
        } catch (Throwable $e) { /* abaikan */ }
    }
    foreach ($phones as $ph) {
        if (sendWhatsAppNotification($ph, $message)) $sent++;
    }
    return $sent;
}

/**
 * Kirim pesan WA ke OWNER (default: user role 'owner', bisa di-override via
 * setting `notif_owner_phone`). Dipakai report penjualan harian.
 * @return int 1 jika berhasil.
 */
function sendToOwner(string $message): int
{
    if (trim($message) === '') return 0;
    $phone = trim((string)getSetting('notif_owner_phone', ''));
    if ($phone === '') {
        try {
            $st = db()->query("SELECT phone FROM users WHERE role='owner' AND phone IS NOT NULL AND phone != '' ORDER BY id LIMIT 1");
            $phone = (string)$st->fetchColumn();
        } catch (Throwable $e) { /* abaikan */ }
    }
    $phone = normalizePhone($phone);
    if ($phone === '') return 0;
    return sendWhatsAppNotification($phone, $message) ? 1 : 0;
}

/**
 * Potong pesan panjang menjadi beberapa bagian aman utk WhatsApp.
 * Batas WhatsApp ~4096 karakter; kita pakai 3500 biar aman (ada overhead
 * encoding). Pecah di batas baris (newline) bila memungkinkan.
 * @return string[] daftar bagian pesan (masing-masing <= 3500 char).
 */
function wa_split_long_message(string $message): array
{
    $max = 3500;
    if (mb_strlen($message) <= $max) {
        return [$message];
    }
    $parts = [];
    $buf = '';
    foreach (preg_split('/(\r\n|\r|\n)/', $message) as $i => $line) {
        // Baris super panjang (tanpa newline) → potong paksa.
        while (mb_strlen($line) > $max) {
            $chunk = mb_substr($line, 0, $max);
            $line = mb_substr($line, $max);
            if ($buf !== '') { $parts[] = $buf; $buf = ''; }
            $parts[] = $chunk;
        }
        $candidate = ($buf === '' ? '' : $buf . "\n") . $line;
        if ($i > 0 && mb_strlen($candidate) > $max) {
            if ($buf !== '') { $parts[] = $buf; $buf = ''; }
            $candidate = $line;
        }
        $buf = $candidate;
    }
    if ($buf !== '') {
        $parts[] = $buf;
    }
    // Gabung bagian yang sangat pendek supaya tidak terlalu banyak pesan kecil.
    $merged = [];
    foreach ($parts as $p) {
        if ($merged && mb_strlen($merged[count($merged) - 1]) + mb_strlen($p) + 1 <= $max) {
            $merged[count($merged) - 1] .= "\n" . $p;
        } else {
            $merged[] = $p;
        }
    }
    return $merged;
}

/**
 * Kirim pesan WA panjang (bisa > 4096 char) ke ADMIN — otomatis dipecah
 * menjadi beberapa pesan aman. Dipakai rekap pending/belum bayar yang panjang.
 * @return int jumlah tujuan × bagian yang berhasil.
 */
function sendToAdminsLong(string $message): int
{
    $parts = wa_split_long_message($message);
    $sent = 0;
    foreach ($parts as $part) {
        $sent += sendToAdmins($part);
    }
    return $sent;
}

/**
 * Kirim pesan WA panjang (bisa > 4096 char) ke OWNER — otomatis dipecah.
 * @return int jumlah bagian yang berhasil.
 */
function sendToOwnerLong(string $message): int
{
    $parts = wa_split_long_message($message);
    $sent = 0;
    foreach ($parts as $part) {
        $sent += sendToOwner($part);
    }
    return $sent;
}

/**
 * Cek status koneksi WhatsApp (dipakai dashboard & whatsapp page).
 * =====================================================================
 * PERBAIKAN 12 Okt 2026 — dua masalah yang saling memperburuk:
 *
 * 1. TIDAK ADA CACHE. Fungsi ini dipanggil di SETIAP buka halaman (dan dua
 *    kali berturut-turut di Pengaturan). Padahal OpenWA membatasi
 *    **100 permintaan per 60 detik** (RATE_LIMIT_MEDIUM_LIMIT). Dashboard
 *    saja sudah memanggil tiap 5 detik.
 *
 * 2. HTTP 429 DIBACA SEBAGAI "TIDAK TERHUBUNG". Jadi saat server WA sedang
 *    membatasi permintaan, dashboard justru menampilkan "WA terputus" dan
 *    memunculkan tombol Hubungkan → pengguna menekan → permintaan QR
 *    bertambah → makin kena 429 → **lingkaran setan**, dan QR tidak pernah
 *    muncul. (Gejala yang dilaporkan pemilik: "QR ga tersedia terus".)
 *
 * Sekarang: hasil disimpan 10 detik, dan bila kena 429 dipakai KEADAAN
 * TERAKHIR YANG DIKETAHUI (tidak menuduh WA putus hanya karena kita
 * dibatasi) sambil menandai `throttled`.
 * =====================================================================
 */
function checkWhatsAppStatus(): array
{
    // Satu permintaan per request PHP walau dipanggil berkali-kali
    static $memo = null;
    if ($memo !== null) return $memo;

    $cfg = whatsapp_config();
    if (empty($cfg['api_url']) || empty($cfg['session_id'])) {
        return $memo = ['connected' => false, 'message' => 'Belum dikonfigurasi'];
    }

    $cacheFile = __DIR__ . '/../logs/wa_status_cache.json';
    $ttl       = 10;   // detik — cukup untuk menahan polling tanpa terasa basi
    $c         = wa_cache_read($cacheFile);
    $umur      = isset($c['ts']) ? (time() - (int)$c['ts']) : PHP_INT_MAX;

    if (isset($c['connected']) && $umur < $ttl) {
        return $memo = [
            'connected' => (bool)$c['connected'],
            'message'   => (string)($c['message'] ?? ''),
            'cached'    => true,
            'ts'        => (int)$c['ts'],
        ];
    }

    $url = rtrim($cfg['api_url'], '/') . '/api/sessions/' . rawurlencode($cfg['session_id']);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ' . $cfg['api_key'],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    /* DIBATASI (429) → jangan menuduh WA putus. Pakai keadaan terakhir yang
     * diketahui (maksimal 10 menit) supaya tampilan tidak berkedip-kedip dan
     * tombol Hubungkan tidak muncul tanpa sebab. */
    if ($httpCode === 429) {
        if (isset($c['connected']) && $umur < 600) {
            return $memo = [
                'connected' => (bool)$c['connected'],
                'message'   => (string)($c['message'] ?? ''),
                'cached'    => true,
                'throttled' => true,
                'ts'        => (int)$c['ts'],
            ];
        }
        return $memo = ['connected' => false, 'message' => 'Server WA sibuk (429)', 'throttled' => true];
    }

    if ($httpCode >= 200 && $httpCode < 300 && $response !== false) {
        $data = json_decode((string)$response, true);
        $state = strtolower((string)($data['state'] ?? ($data['status'] ?? '')));
        // OpenWA memakai 'ready' untuk session aktif/terhubung
        $connected = in_array($state, ['work', 'online', 'connected', 'running', 'ready'], true);
        $hasil = ['connected' => $connected, 'message' => $state ?: 'Terhubung'];
        wa_cache_write($cacheFile, $hasil);   // wa_cache_write menuliskan 'ts' sendiri
        return $memo = $hasil;
    }

    /* Respons gagal lain — simpan juga supaya tidak dihajar berulang kali,
     * TAPI jangan lebih dari 10 detik (keadaan bisa cepat berubah). */
    $gagal = ['connected' => false, 'message' => 'Tidak terhubung'];
    wa_cache_write($cacheFile, $gagal);
    return $memo = $gagal;
}

/**
 * Panggil API OpenWA/WAHA — konfigurasi terpusat (config/services.php).
 *
 * @param string      $endpoint Contoh: "/api/sessions/{id}/chats"
 * @param string      $method   GET atau POST
 * @param array|null  $data     Body (dijadikan JSON) untuk POST.
 * @return array ['code' => int, 'data' => ?array, 'raw' => string]
 */
function callOpenWA(string $endpoint, string $method = 'GET', ?array $data = null, int $timeoutSec = 120): array
{
    // Respons bisa besar (media base64 dari history includeMedia=true, file
    // desain sampai puluhan MB) — pastikan memory & timeout cukup di SEMUA jalur
    // yang memakai helper ini (bukan hanya whatsapp_media.php).
    @ini_set('memory_limit', '768M');

    $cfg = whatsapp_config();
    if (empty($cfg['api_url']) || empty($cfg['api_key'])) {
        return ['code' => 0, 'data' => null, 'raw' => 'WhatsApp tidak terkonfigurasi'];
    }

    $url = rtrim($cfg['api_url'], '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // $timeoutSec bisa diperkecil pemanggil utk pengecekan cepat (mis. verifikasi
    // "pesan sudah terkirim?" sebelum kirim ulang) — jangan tahan permintaan 2 menit.
    curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeoutSec));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ' . $cfg['api_key'],
        'Content-Type: application/json',
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } elseif (in_array(strtoupper($method), ['PUT', 'PATCH', 'DELETE'], true)) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['code' => 0, 'data' => null, 'raw' => $err];
    }
    return [
        'code' => $httpCode,
        'data' => json_decode((string)$response, true),
        'raw'  => (string)$response,
    ];
}

/**
 * Ambil pesan terbaru sebuah chat dari OpenWA (kompatibel Baileys & webjs).
 *
 * Engine Baileys TIDAK mendukung endpoint `/messages/{chatId}/history` (501).
 * Dipakai endpoint store `GET /api/sessions/{sid}/messages?chatId=&limit=`
 * yang tersedia di kedua engine; fallback ke /history bila store gagal (webjs).
 *
 * @return array daftar pesan ternormalisasi:
 *   {id, fromMe(bool), body, text, timestamp(unix int), timestamp_str,
 *    type, media, direction, author}
 */
function wa_wa_messages(string $chatId, int $limit = 40): array
{
    $cfg = whatsapp_config();
    if (empty($cfg['api_url']) || empty($cfg['session_id']) || $chatId === '') {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $sid = (string)$cfg['session_id'];

    // 1) Endpoint STORE (Baileys & webjs) — pesan tersimpan di DB OpenWA.
    $res = callOpenWA('/api/sessions/' . rawurlencode($sid)
        . '/messages?chatId=' . rawurlencode($chatId) . '&limit=' . $limit);
    if ($res['code'] >= 200 && $res['code'] < 300 && isset($res['data']['messages']) && is_array($res['data']['messages'])) {
        // Store Baileys menyimpan DUPLIKAT (id kanonik + id ber-prefiks "true_/false_<jid>_").
        // Dedupe by id; baris ber-data media diprioritaskan.
        $byKey = [];
        foreach ($res['data']['messages'] as $m) {
            $waId = (string)($m['waMessageId'] ?? $m['id'] ?? '');
            $key = preg_replace('/^(?:true|false)_[^_]+@[a-z0-9.]+_/i', '', $waId);
            if ($key === '') $key = $waId;
            $byKey[$key][] = $m;
        }
        $out = [];
        foreach ($byKey as $key => $rows) {
            $pick = $rows[0];
            $media = null;
            foreach ($rows as $r) {
                $md = $r['metadata']['media'] ?? null;
                if (is_array($md) && !empty($md['data'])) { $pick = $r; $media = $md; break; }
            }
            if ($media === null && is_array($rows[0]['metadata']['media'] ?? null)) {
                $media = $rows[0]['metadata']['media'];
            }
            $ts = (int)($pick['timestamp'] ?? 0);
            $body = (string)($pick['body'] ?? '');
            $out[] = [
                'id'             => $key,
                'chatId'         => $chatId,
                'fromMe'         => (string)($pick['direction'] ?? '') === 'outgoing',
                'body'           => $body,
                'text'           => $body,
                'timestamp'      => $ts,
                'timestamp_str'  => $ts > 0 ? date('Y-m-d H:i:s', $ts) : '',
                'type'           => (string)($pick['type'] ?? ''),
                'media'          => $media,
                'fileName'       => (string)($media['filename'] ?? ''),
                'direction'      => (string)($pick['direction'] ?? ''),
                'from'           => (string)($pick['from'] ?? ''),
                'author'         => (string)($pick['author'] ?? ''),
            ];
        }
        // PENTING: urutkan KRONOLOGIS (timestamp naik) — store tidak dijamin urut,
        // dan pemakai (wa_ai_chat_context, ai_reply) mengambil "N pesan TERAKHIR".
        usort($out, function ($a, $b) {
            $ta = (int)($a['timestamp'] ?? 0);
            $tb = (int)($b['timestamp'] ?? 0);
            if ($ta === $tb) return 0;
            return $ta < $tb ? -1 : 1;
        });
        return $out;
    }

    // 2) Fallback: engine lama whatsapp-web.js — endpoint /history.
    $res2 = callOpenWA('/api/sessions/' . rawurlencode($sid)
        . '/messages/' . rawurlencode($chatId) . '/history?limit=' . $limit . '&includeMedia=false');
    if ($res2['code'] >= 200 && $res2['code'] < 300 && is_array($res2['data'])) {
        return $res2['data'];
    }
    return [];
}

/** Parse timestamp pesan (unix int atau string tanggal) → unix int (0 bila tak dikenal). */
function wa_msg_ts($v): int
{
    $v = trim((string)$v);
    if ($v === '') return 0;
    if (is_numeric($v)) return (int)$v;
    $t = strtotime($v);
    return $t === false ? 0 : $t;
}

/**
 * Ambil peta kontak WhatsApp dari OpenWA: id (@c.us/@lid) & number → [name, pushName].
 *
 * /contacts di-paging (limit 1000 + offset) — OpenWA bisa punya ribuan kontak dan
 * kontak yang dicari sering berada di halaman berikutnya, jadi JANGAN hanya ambil
 * limit=1000 (nama akan hilang). Hasil di-cache singkat (90 dtk) supaya halaman
 * chat tidak membanjiri API OpenWA.
 *
 * @return array{byId: array<string,array{name:string,pushName:string}>, byNumber: array<string,array{name:string,pushName:string}>}
 */
function wa_contact_map(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $cacheFile = __DIR__ . '/../logs/wa_contact_map.json';
    $now = time();
    if (is_file($cacheFile) && ($now - (int)@filemtime($cacheFile)) < 90) {
        $data = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($data) && isset($data['byId'], $data['byNumber'])) {
            $cached = $data;
            return $cached;
        }
    }

    $cfg = whatsapp_config();
    $sid = (string)($cfg['session_id'] ?? '');
    $byId = [];
    $byNumber = [];
    if ($sid !== '') {
        $offset = 0;
        while ($offset <= 5000) {
            $cl = callOpenWA('/api/sessions/' . rawurlencode($sid)
                . '/contacts?limit=1000&offset=' . $offset);
            if ($cl['code'] != 200 || !is_array($cl['data']) || count($cl['data']) === 0) {
                break;
            }
            foreach ($cl['data'] as $c) {
                $cid = (string)($c['id'] ?? '');
                $nm  = trim((string)($c['name'] ?? ''));
                $pn  = trim((string)($c['pushName'] ?? ''));
                $num = preg_replace('/[^0-9]/', '', (string)($c['number'] ?? ''));
                $entry = ['name' => $nm, 'pushName' => $pn];
                if ($cid !== '') {
                    if (!isset($byId[$cid])) {
                        $byId[$cid] = $entry;
                    } else {
                        if (empty($byId[$cid]['name']) && $nm !== '') $byId[$cid]['name'] = $nm;
                        if (empty($byId[$cid]['pushName']) && $pn !== '') $byId[$cid]['pushName'] = $pn;
                    }
                }
                if ($num !== '') {
                    if (!isset($byNumber[$num])) {
                        $byNumber[$num] = $entry;
                    } else {
                        if (empty($byNumber[$num]['name']) && $nm !== '') $byNumber[$num]['name'] = $nm;
                        if (empty($byNumber[$num]['pushName']) && $pn !== '') $byNumber[$num]['pushName'] = $pn;
                    }
                }
            }
            $offset += 1000;
        }
    }

    $cached = ['byId' => $byId, 'byNumber' => $byNumber];
    if (is_dir(dirname($cacheFile))) {
        @file_put_contents($cacheFile, json_encode($cached), LOCK_EX);
    }
    return $cached;
}

/**
 * Baca isi cache JSON (file di logs/) — SELALU kembalikan data penuh (tidak
 * membuang isi saat basi; pemanggil memutuskan per-entry mana yang perlu refresh).
 */
function wa_cache_read(string $file): array
{
    $d = [];
    if (is_file($file)) {
        $j = json_decode((string)@file_get_contents($file), true);
        if (is_array($j)) $d = $j;
    }
    return $d;
}

/** Tulis cache JSON (file di logs/) dengan penanda waktu now. */
function wa_cache_write(string $file, array $data): void
{
    $data['ts'] = time();
    if (is_dir(dirname($file))) {
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
}

/**
 * Ambil preview (pesan terakhir) sebuah chat tanpa membanjiri OpenWA.
 *
 * 1) Cache lokal per-entry 60 dtk (logs/wa_previews.json).
 * 2) Store `/messages?chatId=&limit=1` (Baileys terisi).
 * 3) Fallback `/history?limit=1` (engine whatsapp-web.js — store kosong).
 *
 * @return array{msg:string, ts:int, ok:bool, cached:bool}
 */
function wa_last_message(string $chatId, string $sessionId): array
{
    $chatId = trim($chatId);
    if ($chatId === '') return ['msg' => '', 'ts' => 0, 'ok' => false, 'cached' => false];

    $cacheFile = __DIR__ . '/../logs/wa_previews.json';
    $cache = wa_cache_read($cacheFile);
    $map = $cache['map'] ?? [];
    $now = time();

    // Entry fresh (< 60 dtk) — pakai tanpa memanggil OpenWA.
    if (isset($map[$chatId]) && is_array($map[$chatId]) && ($now - (int)($map[$chatId]['at'] ?? 0)) < 60) {
        return $map[$chatId] + ['cached' => true];
    }

    $best = null;
    $res = callOpenWA('/api/sessions/' . rawurlencode($sessionId)
        . '/messages?chatId=' . rawurlencode($chatId) . '&limit=1');
    if ($res['code'] >= 200 && $res['code'] < 300 && is_array($res['data']['messages'] ?? null)) {
        foreach ($res['data']['messages'] as $m) {
            if ($best === null || (int)($m['timestamp'] ?? 0) > (int)($best['timestamp'] ?? 0)) $best = $m;
        }
    }
    if ($best === null) {
        $hist = callOpenWA('/api/sessions/' . rawurlencode($sessionId)
            . '/messages/' . rawurlencode($chatId) . '/history?limit=1&includeMedia=false');
        if ($hist['code'] >= 200 && $hist['code'] < 300 && is_array($hist['data'])) {
            foreach ($hist['data'] as $m) {
                if ($best === null || (int)($m['timestamp'] ?? 0) > (int)($best['timestamp'] ?? 0)) $best = $m;
            }
        }
    }

    $out = ['msg' => '', 'ts' => 0, 'ok' => false, 'cached' => false];
    if ($best !== null) {
        $body = (string)($best['body'] ?? '');
        $type = (string)($best['type'] ?? '');
        if ($type === 'image' || $type === 'gif') {
            $body = 'Gambar';
        } elseif ($type === 'video') {
            $body = 'Video';
        } elseif ($type === 'audio') {
            $body = 'Audio';
        } elseif ($type === 'document' || $type === 'ptt') {
            $fn = (string)($best['metadata']['media']['filename'] ?? '');
            $body = $fn !== '' ? 'Dokumen: ' . $fn : 'Dokumen';
        }
        $out = ['msg' => $body, 'ts' => (int)($best['timestamp'] ?? 0), 'ok' => true, 'cached' => false];
    }

    $out['at'] = $now;
    $map[$chatId] = $out;
    $cache['map'] = $map;
    wa_cache_write($cacheFile, $cache);
    return $out;
}

/**
 * Simpan upload foto pelanggan (dari form) ke storage lokal.
 * Menggunakan $_FILES['photo']. @return path web (mis. /uploads/customers/x.jpg)
 * atau '' jika tidak ada upload / gagal.
 */
function wa_save_customer_photo_upload(): string
{
    if (empty($_FILES['photo']) || empty($_FILES['photo']['name'])) {
        return '';
    }
    if (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }
    $tmp = (string)($_FILES['photo']['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return '';
    }
    if ((int)($_FILES['photo']['size'] ?? 0) > 2 * 1024 * 1024) {
        return '';
    }
    if (@getimagesizefromstring((string)@file_get_contents($tmp)) === false) {
        return '';
    }

    $dir = BASE_PATH . '/html/uploads/customers';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        return '';
    }

    $name = 'c_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $path = $dir . '/' . $name;

    // Konversi ke JPEG agar ringan & seragam (png/webp/gif pun bisa dibaca).
    $img = @imagecreatefromstring((string)file_get_contents($tmp));
    if ($img !== false) {
        $ok = @imagejpeg($img, $path, 85);
        @imagedestroy($img);
        if (!$ok) return '';
    } else {
        if (@move_uploaded_file($tmp, $path) === false) return '';
    }
    return '/uploads/customers/' . $name;
}

/**
 * Unduh URL gambar profil → simpan ke storage lokal.
 * @return string path lokal (mis. /uploads/customers/xxx.jpg) atau '' jika gagal.
 */
function wa_save_photo_from_url(string $url): string
{
    if ($url === '') return '';
    $webRoot = BASE_PATH . '/html';
    $dir = $webRoot . '/uploads/customers';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) return '';

    $name = 'c_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $path = $dir . '/' . $name;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $data = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($data === false || $http !== 200 || strlen($data) < 100) return '';
    if (@getimagesizefromstring($data) === false) return ''; // pastikan benar-benar gambar
    if (@file_put_contents($path, $data) === false) return '';

    return '/uploads/customers/' . $name;
}

/**
 * Ambil & simpan foto profil WhatsApp pelanggan ke storage lokal
 * (URL pps.whatsapp.net bisa kadaluarsa, jadi diunduh & disimpan).
 * @return string path lokal (mis. /uploads/customers/xxx.jpg) atau '' jika gagal/tidak ada.
 */
function wa_fetch_customer_photo(string $chatId): string
{
    if ($chatId === '') return '';
    $cfg = whatsapp_config();
    if (empty($cfg['api_url']) || empty($cfg['session_id'])) return '';
    $sessionId = (string)$cfg['session_id'];

    $res = callOpenWA('/api/sessions/' . rawurlencode($sessionId) . '/contacts/' . rawurlencode($chatId) . '/profile-picture');
    if ($res['code'] != 200) return '';
    $url = (string)($res['data']['url'] ?? '');
    if ($url === '') return '';

    return wa_save_photo_from_url($url);
}

/**
 * Ambil foto profil WhatsApp untuk BANYAK chat sekaligus (endpoint batch).
 * Endpoint punya batas 50 id & deadline 8 detik per id → cepat & tidak menggantung.
 * @param string[] $chatIds
 * @return array<string,string> map chatId => path lokal ('' bila tidak ada).
 */
function wa_fetch_customer_photos_bulk(array $chatIds): array
{
    $out = [];
    if (empty($chatIds)) return $out;
    $cfg = whatsapp_config();
    if (empty($cfg['api_url']) || empty($cfg['session_id'])) return $out;
    $sessionId = (string)$cfg['session_id'];
    $chunks = array_chunk(array_values(array_unique($chatIds)), 50);
    foreach ($chunks as $chunk) {
        $ids = implode(',', array_map('rawurlencode', $chunk));
        $res = callOpenWA('/api/sessions/' . rawurlencode($sessionId) . '/contacts/profile-pictures?ids=' . $ids);
        if ($res['code'] != 200) continue;
        $pictures = (array)($res['data']['pictures'] ?? []);
        foreach ($pictures as $chatId => $url) {
            $chatId = (string)$chatId;
            $url = (string)$url;
            if ($chatId === '' || $url === '') {
                $out[$chatId] = '';
                continue;
            }
            $out[$chatId] = wa_save_photo_from_url($url);
        }
    }
    return $out;
}

/**
 * Ambil & simpan foto profil WhatsApp dari NOMOR HP (user/karyawan) ke
 * storage lokal uploads/users/. Nomor dinormalisasi ke chat id @c.us.
 * @return string path lokal (mis. /uploads/users/xxx.jpg) atau '' bila gagal.
 */
function wa_fetch_user_photo(string $phone): string
{
    $phone = normalizePhone($phone);
    if ($phone === '') return '';
    $chatId = $phone . '@c.us';

    $cfg = whatsapp_config();
    if (empty($cfg['api_url']) || empty($cfg['session_id'])) return '';
    $sessionId = (string)$cfg['session_id'];

    $res = callOpenWA('/api/sessions/' . rawurlencode($sessionId) . '/contacts/' . rawurlencode($chatId) . '/profile-picture');
    if ($res['code'] != 200) return '';
    $url = (string)($res['data']['url'] ?? '');
    if ($url === '') return '';

    // Unduh & simpan ke folder users (mirip wa_save_photo_from_url tapi folder users/)
    $webRoot = BASE_PATH . '/html';
    $dir = $webRoot . '/uploads/users';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) return '';

    $name = 'u_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $path = $dir . '/' . $name;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $data = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($data === false || $http !== 200 || strlen($data) < 100) return '';
    if (@getimagesizefromstring($data) === false) return '';
    if (@file_put_contents($path, $data) === false) return '';

    return '/uploads/users/' . $name;
}

/**
 * Label status order (Indonesia).
 */
function getStatusLabel(string $status): string
{
    $labels = [
        'diterima'   => 'Order Masuk',
        'desain'     => 'Proses Desain',
        'cetak'      => 'Proses Cetak',
        'finishing'  => 'Finishing',
        'siap_ambil' => 'Siap Ambil',
        'batal'      => 'Dibatalkan',
        'draft'      => 'Draft',
        'masuk'      => 'Order Masuk & Desain',
        'proses'     => 'Pencetakan & Finishing',
    ];
    return $labels[$status] ?? $status;
}

/**
 * Daftar status order (urutan alur produksi).
 */
function getStatuses(): array
{
    return ['diterima', 'desain', 'cetak', 'finishing', 'siap_ambil'];
}

/**
 * Class CSS badge untuk tiap status (dipakai di seluruh UI).
 */
function getStatusBadgeClass(string $status): string
{    $map = [
        'diterima'   => 'badge badge-info',
        'desain'     => 'badge badge-warning',
        'cetak'      => 'badge badge-primary',
        'finishing'  => 'badge badge-secondary',
        'siap_ambil' => 'badge badge-success',
        'batal'      => 'badge badge-danger',
        'draft'      => 'badge badge-muted',
    ];
    return $map[$status] ?? 'badge badge-muted';
}

/**
 * Ambil semua data pengaturan toko dari tabel settings.
 */
function getCompanyData(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $settings = [];
    try {
        $stmt = db()->query("SELECT setting_key, setting_value FROM settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        $settings = [];
    }
    return $settings;
}

/**
 * Ambil satu nilai pengaturan.
 */
function getSetting(string $key, $default = ''): string
{
    $data = getCompanyData();
    return $data[$key] ?? $default;
}

/**
 * Simpan satu nilai pengaturan (UPSERT).
 */
function setSetting(string $key, string $value): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$key, $value]);
    } catch (Throwable $e) {
        // abaikan — pengaturan opsional tidak boleh mematikan halaman
    }
}

/* =====================================================================
 * STOR (SETORAN) — TANGGAL MULAI PENCATATAN
 * ---------------------------------------------------------------------
 * Fitur "Stor" baru dipasang 12 Okt 2026, jadi TIDAK ADA catatan setoran
 * untuk hari-hari sebelumnya. Kalau semua riwayat ikut dihitung, kartu
 * menampilkan "belum disetor" puluhan juta rupiah (tumpukan Juni–Oktober)
 * padahal menurut owner/operator uang bulan-bulan itu SUDAH beres disetor
 * di luar sistem.
 *
 * ATURAN: hari SEBELUM tanggal ini dianggap SUDAH disetor → tidak ikut
 * dihitung. Bisa diubah lewat settings `stor_start_date` (format Y-m-d).
 * ===================================================================== */
if (!defined('STOR_START_DEFAULT')) {
    define('STOR_START_DEFAULT', '2026-10-12');
}

/**
 * Tanggal mulai pencatatan stor (Y-m-d). Selalu valid — kalau setting kosong
 * atau salah format, jatuh ke STOR_START_DEFAULT.
 */
function stor_start_date(): string
{
    $v = trim((string)getSetting('stor_start_date', STOR_START_DEFAULT));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : STOR_START_DEFAULT;
}

/* =====================================================================
 * JAM MULAI HARI KERJA (untuk rekap Stor)
 * ---------------------------------------------------------------------
 * Bengkel ini sering bekerja LEWAT TENGAH MALAM. Kalau "hari" dipotong di
 * jam 00:00, order jam 23:30 dan order jam 00:30 jatuh di hari BERBEDA,
 * padahal itu satu shift yang sama → rekap setoran harian jadi terbelah dan
 * operator bingung ("kok hari ini kosong padahal saya baru selesai kerja").
 *
 * ATURAN: hari kerja = 06:00 s/d 05:59 besoknya. Artinya:
 *   11 Okt 23:30  → hari kerja 11 Okt
 *   12 Okt 00:30  → hari kerja 11 Okt   (masih shift yang sama)
 *   12 Okt 07:00  → hari kerja 12 Okt
 *
 * Penerapan SENGAJA dibatasi pada bagian STOR saja (kartu Stor dashboard,
 * seksi Stor di Keuangan, tabel Stor Per Hari) supaya tidak mengubah kartu
 * KPI lain yang sudah lama dipakai. Ubah lewat settings `workday_start_hour`.
 * ===================================================================== */
if (!defined('WORKDAY_START_DEFAULT')) {
    define('WORKDAY_START_DEFAULT', 6);
}

/** Jam mulai hari kerja (0–23). */
function workday_start_hour(): int
{
    $v = (int)getSetting('workday_start_hour', (string)WORKDAY_START_DEFAULT);
    return ($v >= 0 && $v <= 23) ? $v : WORKDAY_START_DEFAULT;
}

/** Tanggal "hari kerja" SEKARANG (Y-m-d). */
function workday_today(): string
{
    $h = workday_start_hour();
    $ts = time();
    if ($h > 0 && (int)date('G') < $h) {
        $ts -= 86400;   // sebelum jam mulai = masih hari kerja kemarin
    }
    return date('Y-m-d', $ts);
}

/**
 * Ekspresi SQL untuk mengubah kolom DATETIME → tanggal hari kerja.
 * Contoh: workday_sql_date('order_date') → "DATE(DATE_SUB(order_date, INTERVAL 6 HOUR))"
 */
function workday_sql_date(string $column): string
{
    $h = workday_start_hour();
    if ($h <= 0) {
        return "DATE($column)";
    }
    return "DATE(DATE_SUB($column, INTERVAL $h HOUR))";
}

/**
 * Rentang DATETIME untuk satu hari kerja (untuk perbandingan kolom datetime).
 * Contoh hari kerja 2026-10-12, jam mulai 6 →
 *   ['2026-10-12 06:00:00', '2026-10-13 06:00:00']
 */
function workday_range(string $workdayYmd): array
{
    $h = workday_start_hour();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workdayYmd)) {
        $workdayYmd = workday_today();
    }
    $start = $workdayYmd . ' ' . sprintf('%02d:00:00', $h);
    $end   = date('Y-m-d H:i:s', strtotime($start . ' +1 day'));
    return [$start, $end];
}

/**
 * Daftar ID printer yang DISEMBUNYIKAN dari halaman Printer (cg_monitor).
 *
 * Kenapa perlu: agent PC (CG_PC_AGENT_V2) melaporkan SEMUA printer Windows yang
 * terpasang di PC-nya (mis. "EPSON L120 Series", "Canon LBP6030/6040") tiap
 * beberapa detik. Akibatnya kartu printer yang alatnya mati / tidak dipakai
 * tetap tampil "Online" (Idle) selamanya → halaman monitor jadi ramai & bikin
 * salah paham ("kok printer ini on padahal off?").
 * Yang disembunyikan tetap tersimpan di DB; hanya tidak ditampilkan.
 */
function cg_hidden_printer_ids(): array
{
    $ids = json_decode((string)getSetting('monitor_hidden_printers', '[]'), true);
    if (!is_array($ids)) return [];
    $out = [];
    foreach ($ids as $v) {
        $v = (int)$v;
        if ($v > 0) $out[] = $v;
    }
    return array_values(array_unique($out));
}

/** Simpan daftar ID printer yang disembunyikan. */
function cg_hidden_printer_save(array $ids): array
{
    $out = [];
    foreach ($ids as $v) {
        $v = (int)$v;
        if ($v > 0) $out[] = $v;
    }
    $out = array_values(array_unique($out));
    setSetting('monitor_hidden_printers', json_encode($out));
    return $out;
}

/**
 * Bersihkan printer "mati lama" dari daftar (biar monitor tidak romol).
 *
 * Aturan (diminta user 2026-10-11):
 *   - Printer yang tidak heartbeat > $days hari  → dianggap sisa/alat mati.
 *   - Punya riwayat (printer_jobs / dtf_sheet_log) → JANGAN dihapus, cukup
 *     disembunyikan + status offline (supaya statistik & riwayat tetap utuh).
 *   - Tidak punya riwayat → dihapus permanen.
 *   - Printer BARU tidak masalah: kalau alatnya hidup & agent jalan, barisnya
 *     dibuat lagi otomatis oleh heartbeat (deteksi ulang).
 *
 * @return array ['dihapus'=>int, 'disembunyikan'=>int, 'nama'=>string[]]
 */
function cg_cleanup_stale_printers(int $days = 2): array
{
    $res = ['dihapus' => 0, 'disembunyikan' => 0, 'nama' => []];
    try {
        if ($days < 1) $days = 1;
        $q = db()->prepare(
            "SELECT id, name, last_heartbeat, updated_at
               FROM printers
              WHERE (last_heartbeat IS NULL AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY))
                 OR (last_heartbeat IS NOT NULL AND last_heartbeat < DATE_SUB(NOW(), INTERVAL ? DAY))"
        );
        $q->execute([$days, $days]);
        $rows = $q->fetchAll();
        if (!$rows) return $res;

        $hidden = cg_hidden_printer_ids();
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            // Jangan sentuh yg sudah disembunyikan (biar tidak berulang).
            if (in_array($id, $hidden, true)) continue;

            $chk = db()->prepare(
                "SELECT (SELECT COUNT(*) FROM printer_jobs   WHERE printer_id = ?) AS n1,
                        (SELECT COUNT(*) FROM dtf_sheet_log  WHERE printer_id = ?) AS n2"
            );
            $chk->execute([$id, $id]);
            $c = $chk->fetch() ?: ['n1' => 0, 'n2' => 0];
            $hasHistory = ((int)$c['n1'] > 0) || ((int)$c['n2'] > 0);

            if ($hasHistory) {
                db()->prepare("UPDATE printers SET status='offline', print_status='Idle',
                                      current_order='', progress=0, remaining_time='00:00:00'
                                WHERE id=?")->execute([$id]);
                $hidden[] = $id;
                $res['disembunyikan']++;
                $res['nama'][] = (string)$r['name'] . ' (disembunyikan)';
            } else {
                db()->prepare("DELETE FROM printers WHERE id=?")->execute([$id]);
                $res['dihapus']++;
                $res['nama'][] = (string)$r['name'] . ' (dihapus)';
            }
        }
        cg_hidden_printer_save($hidden);
    } catch (Throwable $e) {
        // abaikan — pembersihan tidak boleh mematikan halaman monitor
    }
    return $res;
}

/**
 * Nama perusahaan/brand — dari Pengaturan (company_name), fallback ke APP_NAME.
 * Dipakai di navbar, judul halaman, footer, & halaman login.
 */
function companyName(): string
{
    $name = trim(getSetting('company_name', ''));
    if ($name !== '') return $name;
    return defined('APP_NAME') ? (string)APP_NAME : 'Creative Geek';
}

/**
 * Kill switch MASTER AI — per-PENGGUNA.
 * - Global (ai_master_enabled di settings) = master: owner bisa matikan SEMUA AI
 *   untuk semua orang bila diset 0.
 * - Per-user (ai_master_enabled di user_preferences) = tiap pengguna bisa
 *   mematikan AI untuk dirinya sendiri (default 1 / ikut global).
 * Semua pintu AI (chat CGAI, reply order, status AI, desain) wajib cek ini dulu.
 */
function wa_ai_master_enabled(): bool
{
    // Master global — bila owner mematikan, semua AI mati untuk semua user.
    $globalOn = getSetting('ai_master_enabled', '1') === '1';
    if (!$globalOn) {
        return false;
    }
    // Per-user — tiap pengguna punya kill switch sendiri.
    return userPref('ai_master_enabled', '1') === '1';
}

/* =====================================================================
 * THINKING MODE DeepSeek — MATI secara default (PENTING, jangan diubah
 * tanpa membaca penjelasan ini!)
 * ---------------------------------------------------------------------
 * MASALAH YANG DIPERBAIKI (2026-09-22):
 *   Model DeepSeek (deepseek-flash) MENYALAKAN thinking mode secara
 *   default ("Thinking mode is enabled by default, with the default effort
 *   being high" — docs resmi). Artinya model menulis rantai-pemikiran
 *   PANJANG dulu sebelum menjawab, dan token itu IKUT dihitung.
 *
 *   Dengan max_tokens yang dipakai aplikasi ini (60-700 sesuai kebutuhan
 *   fitur), jatah token HABIS dipakai berpikir → model tidak pernah sempat
 *   menulis jawaban → `content` KOSONG dan `finish_reason = "length"`.
 *
 *   Akibat nyata di produksi: log `AI chat: response kosong setelah 3x
 *   retry` muncul 23x — pelanggan TIDAK DIBALAS sama sekali (bot seolah
 *   bisu), padahal HTTP 200 / tidak ada error.
 *
 *   Efek samping lain: docs menyatakan thinking mode TIDAK mendukung
 *   `temperature` ("will have no effect"). Artinya SEMUA penyetelan
 *   temperature per-fitur di aplikasi ini (0.1 deteksi produk, 0.55 chat,
 *   1.15 kata banner) SIA-SIA selama thinking aktif. Terbukti dari uji:
 *   temperature 0.0 vs 2.0 menghasilkan variasi jawaban yang sama persis.
 *
 * KEPUTUSAN: thinking mode DIMATIKAN default. Untuk tugas aplikasi ini
 * (balas CS, deteksi produk, rangkum order, kata harian) berpikir panjang
 * tidak diperlukan, dan mematikannya membuat:
 *   - AI tidak lagi bisu      (masalah utama)
 *   - 6,6x lebih hemat token  (60 vs 1.459 token per balasan)
 *   - 6,5x lebih cepat        (1,6s vs 10,6s)
 *   - temperature BERFUNGSI kembali → variasi jawaban hidup lagi
 *
 * Bila di masa depan butuh penalaran panjang (mis. hitung harga rumit),
 * bisa dinyalakan lewat Pengaturan → AI → "Thinking Mode". Kalau dinyalakan,
 * NAIKKAN juga `max_tokens` (butuh ~1.500 token sekali jalan), kalau tidak
 * jawaban akan kosong lagi.
 * ===================================================================== */

/** Apakah thinking mode AI dinyalakan? default '0' = MATI. */
function wa_ai_thinking_enabled(): bool
{
    return getSetting('ai_thinking_enabled', '0') === '1';
}

/**
 * Terapkan konfigurasi thinking ke payload AI (KHUSUS DeepSeek).
 * Pemakaian: $payload = wa_ai_apply_thinking($payload);
 * ATAU     : $payload = wa_ai_apply_thinking($payload, $someBaseUrl);
 *
 * PENTING — parameter `thinking` hanya dikenal DeepSeek. Provider lain
 * (mis. OpenAI) akan MENOLAK permintaan dengan:
 *   HTTP 400 "Unrecognized request argument supplied: thinking"
 * Karena itu base URL yang dipakai ikut diperiksa: kalau bukan DeepSeek,
 * fungsi ini TIDAK menambahkan apa pun.
 *
 * (BUG 2026-09-22: sebelumnya parameter ini ditambahkan tanpa cek provider →
 * fitur "enhance prompt gambar" yang memakai OpenAI gpt-4o-mini jadi GAGAL
 * total / hasil kosong selama beberapa jam.)
 *
 * @param array       $payload Payload request (model/messages/max_tokens/...).
 * @param string|null $baseUrl Base URL provider yang akan dipanggil.
 *                             null = pakai setting ai_base_url (DeepSeek utama).
 */
function wa_ai_apply_thinking(array $payload, ?string $baseUrl = null): array
{
    if (wa_ai_thinking_enabled()) {
        return $payload; // thinking aktif (perilaku bawaan DeepSeek)
    }
    // Hanya DeepSeek yang mendukung parameter `thinking`.
    $base = $baseUrl;
    if ($base === null) {
        $base = (string)getSetting('ai_base_url', 'https://api.deepseek.com');
    }
    if (stripos($base, 'deepseek') === false) {
        return $payload; // provider lain — jangan kirim parameter DeepSeek
    }
    // Matikan thinking → model langsung menjawab, token tidak terbuang.
    $payload['thinking'] = ['type' => 'disabled'];
    return $payload;
}

/**
 * Upload gambar tervalidasi ke public/uploads/<subdir>.
 * Kembalikan path relatif (/uploads/<subdir>/<file>) atau '' bila gagal.
 */
function uploadSettingImage(array $file, string $subdir = 'brand', int $maxBytes = 2097152): string
{
    if (!isset($file['error'])) return '';
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return '';
    if ($file['error'] !== UPLOAD_ERR_OK) return '';
    if ((int)($file['size'] ?? 0) > $maxBytes) return '';

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return '';

    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF  => 'gif',
    ];
    $ext = $allowed[$info[2]] ?? '';
    if ($ext === '') return '';

    /* Lokasi folder uploads: pakai DOCUMENT_ROOT (kompatibel Docker & lokal).
       Fallback ke <project>/public/uploads bila tidak tersedia (mis. CLI). */
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($docRoot !== '' && is_dir($docRoot)) {
        $dir = $docRoot . '/uploads/' . $subdir;
    } else {
        $dir = __DIR__ . '/../public/uploads/' . $subdir;
    }
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return '';
    return '/uploads/' . $subdir . '/' . $name;
}

/** Logo brand (default logo putih). */
function brandLogo(): string
{
    $logo = getSetting('brand_logo', '');
    return $logo !== '' ? $logo : '/logo-white.png';
}

/** Icon/favicon brand (default favicon putih). */
function brandIcon(): string
{
    $icon = getSetting('brand_icon', '');
    return $icon !== '' ? $icon : '/favicon.png';
}

/** Gambar latar banner dashboard (kosong bila belum diatur). */
function brandBanner(): string
{
    return getSetting('banner_image', '');
}

/* =====================================================================
 * KATA MOTIVASI HARIAN BANNER DASHBOARD (fun, ganti tiap hari)
 * ---------------------------------------------------------------------
 * Teks di bawah nama pada banner dashboard (dulu "banner_subtitle" statis)
 * diganti KATA-KATA MOTIVASI SELENGEAN (receh, genit, tapi nyemangatin)
 * yang berbeda setiap hari:
 *   - Dihasilkan AI (setting ai_*) sekali sehari oleh cron
 *     public/cron/banner_pantun_harian.php, atau lewat tombol di Pengaturan.
 *   - Kalau AI mati/kredit habis → dipakai daftar CADANGAN bawaan
 *     (tetap beda tiap hari, ditentukan tanggal), jadi banner tidak
 *     pernah kosong.
 *
 * Catatan: nama fungsi & setting tetap memakai "pantun" (banner_pantun_*)
 * supaya tidak perlu migrasi DB — isinya kini kata motivasi receh, bukan
 * pantun 4 baris.
 *
 * Setting terkait (settings):
 *   banner_pantun_enabled = 1/0   → mode kata harian AKTIF
 *   banner_pantun_date    = Y-m-d → tanggal kalimat yang tersimpan
 *   banner_pantun_text    = 1-3 baris kalimat (dipisah \n)
 *   banner_pantun_total   = jumlah kalimat yang pernah dibuat AI
 *   banner_pantun_recent  = JSON 6 kalimat terakhir (anti-ulang)
 * ===================================================================== */

/** Apakah mode KATA HARIAN di banner dashboard aktif? (default: aktif) */
function cg_pantun_enabled(): bool
{
    return getSetting('banner_pantun_enabled', '1') === '1';
}

/** Tanggal hari ini (WITA) — format Y-m-d. */
function cg_pantun_date_today(): string
{
    return (new DateTime('now', new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Makassar')))->format('Y-m-d');
}

/**
 * Frasa KLISE ala pidato/ceramah yang bikin banner terasa kaku & tua.
 * Kalau balasan AI mengandung ini → dianggap tidak selengean, pakai cadangan.
 */
function cg_pantun_cliche_words(): array
{
    return [
        'jangan menyerah', 'jangan pernah menyerah', 'pantang menyerah',
        'tetap semangat', 'semangat terus', 'bangkitlah', 'marilah kita',
        'jadilah pribadi', 'hendaknya', 'kerja keras', 'kerja cerdas',
        'giat bekerja', 'tingkatkan produktivitas', 'produktif', 'produktivitas',
        'bersyukurlah', 'attitude', 'mindset', 'mental juara',
        'semoga sukses', 'semoga berkah', 'penuh berkah', 'sukses selalu',
        'kunci kesuksesan', 'masa depan gemilang', 'raih impianmu',
    ];
}

/**
 * TEMA TERLARANG — kata yang TIDAK boleh muncul sama sekali.
 * Alasan (2026-09-22): AI selalu balik ke tema GAJI/Gajian walau diminta
 * variasi ("ujung-ujungnya gajian terus"). Jadi tema gaji dilarang total,
 * bukan cuma dihindari, supaya kalimatnya benar-benar variatif.
 */
function cg_pantun_banned_words(): array
{
    return [
        'gaji', 'gajian', 'gajianmu', 'upah', 'honor', 'dibayar', 'dibayarin',
        'bayaran', 'uang bulanan', 'tunjangan', 'thr', 'bonus gaji',
    ];
}

/**
 * Tema ALTERNATIF yang didorong ke AI, biar tidak stuck di satu tema.
 * Dipilih beberapa secara acak tiap generate supaya variasinya beda-beda.
 */
function cg_pantun_theme_hints(): array
{
    return [
        'tinta yang selalu habis di saat paling tidak tepat',
        'printer yang berdengung seperti ikut ngobrol',
        'kertas macet & cara menyikapinya dengan sabar',
        'pelanggan minta revisi "kecil aja" tapi ubah semuanya',
        'file desain dikirim lewat screenshot (bukan file asli)',
        'penggaris, cutter, dan alat kerja yang sering hilang',
        'mesin yang ngadat tiba-tiba lalu sembuh sendiri',
        'order masuk bertubi-tubi jam 4 sore',
        'kucing/tikus/hewan sekitar yang bikin hari jadi ramai',
        'aroma tinta & lem yang khas toko cetak',
        'makna "sebentar lagi ya Kak" dari pelanggan',
        'hujan deras saat spanduk harus dijemur',
        'revisi ke-9 yang isinya cuma "geser sedikit ke kiri"',
        'desain "simpel aja" yang ternyata bikin mikir semalam',
        'semangat pagi (tanpa) kopi',
        'belajar hal baru dari kesalahan cetak',
        'kerja tim yang saling bantu saat mesin rewel',
        'proses cetak yang rapi = kebanggaan kecil sendiri',
        'printer yang akhirnya berhenti bunyi & itu terasa mewah',
        'melihat hasil cetak pelanggan puas = bonus batin',
    ];
}

/**
 * Daftar CADANGAN (dipakai bila AI mati/gagal) — kata motivasi SELENGEAN:
 * santai, receh, kadang nyindir diri sendiri, khas anak muda kerja.
 * PENTING: TIDAK ADA yang bertema gaji/upah (tema itu dilarang total).
 */
function cg_pantun_fallback_pool(): array
{
    return [
        "Deadline bukan musuh, dia cuma pengingat kalau kita masih dibutuhkan.",
        "Revisi itu tanda sayang. Kalau revisi ke-10, berarti cinta bertepuk sebelah tangan.",
        "Tinta boleh habis, tapi jangan sampai kalah sama printer yang ngadat.",
        "Buka laptop, buka rezeki. Buka TikTok, ya... tutup lagi, kerja dulu.",
        "Yang penting bukan cepat, yang penting hasilnya bikin pelanggan balik lagi.",
        "Mesin ngadat itu biasa. Yang luar biasa itu kamu, tetap senyum.",
        "Kerja hari ini = cerita seru buat nanti.",
        "Kertas macet? Tarik napas dulu, printer juga butuh waktu.",
        "Kalau pelanggan bilang 'simpel aja', siapkan tiga revisi.",
        "Kamu bukan robot, tapi hasil kerjamu ajaib.",
        "Jangan bandingkan harga dengan tetangga, bandingkan senyummu.",
        "Bekerja tanpa ngopi, ibarat cetak tanpa tinta.",
        "Belum sempurna, tapi kita sudah di depan printer. Lumayan.",
        "Cetak dulu, bangga nanti.",
        "Rezeki itu kayak tinta. Habis, tinggal beli lagi, isi lagi, lanjut.",
        "Orang rajin itu bukan yang tidak pernah ngantuk — tapi yang ngantuk, tetap kerja.",
        "Hari ini berat? Syukurlah, tanda kita masih hidup dan masih dibutuhkan.",
        "Kalau capek, duduk dulu. Kalau masih capek, duduk yang lebih nyaman.",
        "Printer boleh berdengung, asal jangan lebih keras dari suara kita ngobrol.",
        "Pesan hari ini: geser dikit ke kiri itu bukan revisi, itu seni.",
    ];
}

/** Kata cadangan yang dipakai hari ini (beda tiap hari, walau AI mati). */
function cg_pantun_fallback(): string
{
    $pool = cg_pantun_fallback_pool();
    if (empty($pool)) return '';
    // Kombinasi tanggal + tahun → pilihan berbeda tiap hari (tanpa simpanan).
    $seed = (int)date('z') + (int)date('Y');
    return $pool[$seed % count($pool)];
}

/**
 * Kata harian yang DITAMPILKAN hari ini:
 *  - kalimat AI yang tersimpan & tanggalnya hari ini, atau
 *  - cadangan (bila belum ada / AI mati).
 */
function cg_pantun_current(): string
{
    $saved = trim((string)getSetting('banner_pantun_text', ''));
    $date  = (string)getSetting('banner_pantun_date', '');
    if ($saved !== '' && $date === cg_pantun_date_today()) {
        return $saved;
    }
    return cg_pantun_fallback();
}

/**
 * Rapikan hasil mentah AI menjadi maksimal 2 baris kalimat bersih:
 * buang bullet/nomor, tanda kutip, label ("Kalimat:", "Kata motivasi:"),
 * baris kosong, dan kalimat PENUTUP ala chatbot ("Semoga membantu!").
 */
function cg_pantun_normalize(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    // Kalimat penutup/instruksi ala chatbot — bukan bagian kalimat motivasi.
    // PENTING: hanya dibuang bila barisnya PENDEK (< 45 karakter). Kalau kata
    // seperti "jangan lupa" muncul di kalimat panjang ("...yang penting jangan
    // lupa gaji."), itu kalimat sah dan harus dipertahankan.
    $penutup = '/(semoga (membantu|bermanfaat|menginspirasi|terinspirasi|harimu|hari anda)|'
        . 'selamat (bekerja|berkarya|beraktivitas)|salam (sukses|sehat)|'
        . 'terima kasih|thank you|jangan lupa (istirahat|makan|sholat|berdoa|senyum)|'
        . 'ini dia|berikut (adalah|ini)|mari kita (mulai|kerja)|'
        . 'tetap semangat ya|semangat ya|gitu aja|sekian)/iu';

    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        // Buang penanda markdown/list di awal baris: "- ", "1. ", "* "
        $line = (string)preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line);
        // Buang label di awal: "Pantun:", "Kalimat:", "Kata motivasi:", "Quotes:", "Baris 1:"
        $line = (string)preg_replace('/^\s*(pantun|kalimat|kata\s*motivasi|motivasi|quotes?|jawaban|baris\s*\d+)\s*[:\-]\s*/iu', '', $line);
        // Buang tanda kutip/backtick pembungkus
        $line = trim($line, " \t\"'`“”‘’");
        $line = (string)preg_replace('/\s+/u', ' ', $line);
        if ($line === '') continue;
        // Lewati baris penutup ala chatbot — hanya bila barisnya pendek.
        if (mb_strlen($line) < 45 && preg_match($penutup, $line)) continue;
        $out[] = $line;
    }
    return implode("\n", array_slice($out, 0, 2));
}

/** Simpan pantun sebagai pantun HARI INI. */
function cg_pantun_save(string $text): void
{
    $text = trim($text);
    if ($text === '') return;
    $stmt = db()->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute(['banner_pantun_text', $text]);
    $stmt->execute(['banner_pantun_date', cg_pantun_date_today()]);
    $total = (int)getSetting('banner_pantun_total', '0');
    $stmt->execute(['banner_pantun_total', (string)($total + 1)]);

    // Simpan riwayat 6 pantun terakhir supaya AI tidak mengulang sampiran
    // (mis. "ikan tongkol" / "pasar Bikomi" dipakai tiap hari).
    $recent = cg_pantun_recent();
    array_unshift($recent, $text);
    $stmt->execute(['banner_pantun_recent', (string)json_encode(array_slice($recent, 0, 6), JSON_UNESCAPED_UNICODE)]);
}

/** Riwayat pantun terakhir (terbaru di depan) — untuk mencegah pengulangan. */
function cg_pantun_recent(): array
{
    $raw = (string)getSetting('banner_pantun_recent', '');
    if ($raw === '') return [];
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $t) {
        $t = trim((string)$t);
        if ($t !== '') $out[] = $t;
    }
    return array_slice($out, 0, 6);
}

/**
 * Buat pantun BARU lewat AI lalu simpan sebagai pantun hari ini.
 *
 * @param bool $force true = buat walau pantun hari ini sudah ada (tombol manual).
 * @return array{ok:bool,text:string,message:string}
 */
function cg_pantun_generate(bool $force = false): array
{
    $today  = cg_pantun_date_today();
    $saved  = trim((string)getSetting('banner_pantun_text', ''));
    $savedD = (string)getSetting('banner_pantun_date', '');

    if (!$force && $saved !== '' && $savedD === $today) {
        return ['ok' => true, 'text' => $saved, 'message' => 'Pantun hari ini sudah ada (tidak dibuat ulang).'];
    }

    // AI mati / belum dikonfigurasi → pakai pantun cadangan (TIDAK disimpan,
    // supaya cron bisa mencoba lagi saat AI sudah aktif).
    if (!wa_ai_master_enabled() || (string)getSetting('ai_api_key', '') === '') {
        return [
            'ok'      => false,
            'text'    => cg_pantun_fallback(),
            'message' => 'AI belum aktif / API key kosong — banner memakai pantun cadangan.',
        ];
    }

    $tz   = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Makassar');
    $now  = new DateTime('now', $tz);
    $hari = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
             'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'][$now->format('l')] ?? '';
    $tgl  = $now->format('d/m/Y');
    $prev = $saved !== '' ? $saved : '(belum ada)';

    $system = "Kamu admin toko yang jago bikin caption receh: santai, selengean, sedikit genit, "
        . "tapi tetap sopan dan tidak menyinggung siapa pun. Gaya bahasa anak muda kerja di grup WhatsApp. "
        . "Balas LANGSUNG kalimatnya saja — tanpa pembuka, tanpa penjelasan, tanpa tanda kutip, tanpa emoji.";

    /* Tema acak (3 buah) — supaya hasilnya tidak stuck di satu tema saja.
       Sebelumnya AI selalu balik ke tema gaji walau diminta variasi. */
    $pool = cg_pantun_theme_hints();
    shuffle($pool);
    $temaAcak = implode("\n   - ", array_slice($pool, 0, 3));

    $user = "Tulis 1 (satu) KATA-KATA MOTIVASI SELENGEAN untuk ditempel di banner dashboard "
        . "toko percetakan Creative Geek di Kefamenanu, NTT (melayani spanduk, sticker, undangan, kartu nama). "
        . "Yang baca: karyawan toko sendiri, jadi harus bikin mereka senyum-senyum sambil kerja.\n\n"
        . "Hari ini: {$hari}, {$tgl}.\n\n"
        . "Aturan:\n"
        . "1. Cukup 1 baris, MAKSIMAL 2 baris. Total maksimal 90 karakter.\n"
        . "2. Gaya: receh, selengean, genit, suka nyindir diri sendiri — bukan ceramah, bukan nasihat.\n"
        . "3. Boleh pakai 'kamu', 'kita', 'ngopi', 'deadline'. Emoji TIDAK boleh.\n"
        . "4. AMBIL TEMA dari salah satu daftar ini (pilih yang paling lucu buat hari ini):\n"
        . "   - {$temaAcak}\n"
        . "5. Harus tetap NYEMANGATIN — bikin ketawa, bukan bikin sedih atau mengeluh panjang.\n"
        . "6. DILARANG TOTAL membahas UANG/UPAH: kata 'gaji', 'gajian', 'upah', 'honor', 'dibayar', "
        . "'THR', 'tunjangan', 'bonus' TIDAK BOLEH muncul sama sekali. Tema gaji sudah terlalu sering "
        . "dipakai dan membosankan — cari sudut pandang lain (mesin, tinta, kertas, pelanggan, ngopi, tim kerja, "
        . "alat kerja, hujan, hewan sekitar, belajar dari kesalahan).\n"
        . "7. DILARANG KERAS pakai frasa klise ala pidato: 'jangan menyerah', 'pantang menyerah', "
        . "'tetap semangat', 'semangat terus', 'marilah kita', 'jangan malas', 'kerja keras', "
        . "'giat bekerja', 'tingkatkan produktivitas', 'semoga sukses', 'semoga berkah', 'sukses selalu', "
        . "'kunci kesuksesan', 'masa depan gemilang', 'raih impianmu', 'bersyukurlah'.\n"
        . "8. Jangan menyinggung fisik, agama, suku, atau SARA. Jangan sebut AI/robot/teknologi.\n"
        . "9. JANGAN mengulang kalimat ini:\n{$prev}\n\n"
        . "Contoh gaya yang DIMINTA (perhatikan: TIDAK ADA yang soal gaji):\n"
        . "Deadline bukan musuh, dia cuma pengingat kalau kita masih dibutuhkan.\n"
        . "Pesan hari ini: geser dikit ke kiri itu bukan revisi, itu seni.\n"
        . "Revisi itu tanda sayang. Kalau revisi ke-10, berarti cinta bertepuk sebelah tangan.\n"
        . "Mesin ngadat itu biasa. Yang luar biasa itu kamu, tetap senyum.\n\n"
        . "Balas hanya kalimatnya.";

    $res  = wa_ai_complete($system, $user, 200, defined('WA_AI_TEMP_BANNER') ? WA_AI_TEMP_BANNER : 1.3);
    $text = cg_pantun_normalize($res);

    // Validasi: minimal 1 baris, tidak mengandung kata terlarang / klise / tema uang.
    $lineCount = $text === '' ? 0 : (substr_count($text, "\n") + 1);
    $bad = preg_match('/\b(ai|chatgpt|openai|robot|deepseek)\b/iu', $text) === 1;
    $klise = '';
    $banned = '';
    $lowText = mb_strtolower($text);
    foreach (cg_pantun_cliche_words() as $w) {
        if (mb_strpos($lowText, $w) !== false) { $klise = $w; break; }
    }
    // Tema gaji/uang → tolak (AI sering balik ke tema ini walau dilarang).
    foreach (cg_pantun_banned_words() as $w) {
        if (preg_match('/(?<![\p{L}])' . preg_quote($w, '/') . '(?![\p{L}])/iu', $lowText)) { $banned = $w; break; }
    }
    if ($lineCount < 1 || $bad || $klise !== '' || $banned !== '') {
        $why = $bad ? ' (mengandung kata terlarang)'
            : ($banned !== '' ? " (tema uang: \"{$banned}\" — dilarang)" : ($klise !== '' ? " (klise: \"{$klise}\" — terlalu kaku)" : ''));
        return [
            'ok'      => false,
            'text'    => cg_pantun_fallback(),
            'message' => 'Balasan AI tidak valid' . $why . ' — memakai cadangan.',
        ];
    }

    cg_pantun_save($text);
    writeLog('Kata harian banner dibuat AI (' . $lineCount . ' baris)', 'INFO');
    return ['ok' => true, 'text' => $text, 'message' => 'Kata motivasi baru berhasil dibuat.'];
}

/**
 * Tulis log aplikasi ke logs/app.log (di luar docroot).
 */
function writeLog(string $message, string $level = 'INFO'): void
{
    $logFile = BASE_PATH . '/logs/app.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $time = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$time] [$level] $message" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Deteksi request AJAX.
 */
function isAjaxRequest(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * AGREGASI MULTI-JOB PER ORDER (progress gabungan utk pelanggan).
 * =====================================================================
 * Satu order bisa dicetak dalam BANYAK job spooler (mis. 10 undangan =
 * 10 gambar/10 job). Tanpa agregasi, progress per-job jalan sendiri lalu
 * reset → pelanggan tidak bisa pantau "udah selesai berapa".
 *
 * Fungsi ini menjumlahkan total_pages & printed_pages dari SEMUA job
 * (printing + selesai) utk order tertentu, DIBATASI ke SESI CETAK TERAKHIR.
 *
 * SESI CETAK = kumpulan job yang berjarak < GAP (5 menit) satu sama lain.
 * Job dengan gap > 5 menit dianggap sesi BARU. Agregasi hanya memakai sesi
 * terakhir. Ini mencegah:
 *  - test berulang / cetak ulang di lain hari menumpuk target
 *  - order lama yang di-print lagi tidak ikut target lama
 *
 * @param string $orderNumber Nomor order (CGxxxxxxxxxx)
 * @return array{total_pages:int, printed_pages:int, has_data:bool}
 */
function getOrderPrintAggregation(string $orderNumber): array
{
    $res = ['total_pages' => 0, 'printed_pages' => 0, 'has_data' => false];
    if ($orderNumber === '') {
        return $res;
    }
    try {
        $st = db()->prepare("
            SELECT total_pages, printed_pages, start_at
            FROM printer_jobs
            WHERE order_number = ? AND status IN ('printing','selesai')
              AND total_pages IS NOT NULL AND total_pages > 0
            ORDER BY start_at ASC, id ASC
        ");
        $st->execute([$orderNumber]);
        $rows = $st->fetchAll();
        if (!$rows) {
            return $res;
        }

        // Kelompokkan ke dalam sesi cetak (gap > 5 menit = sesi baru)
        $GAP = 300; // 5 menit
        $sessions = [];
        $cur = [];
        $prevTs = null;
        foreach ($rows as $r) {
            $ts = strtotime((string)$r['start_at']);
            if ($prevTs !== null && ($ts - $prevTs) > $GAP) {
                $sessions[] = $cur;
                $cur = [];
            }
            $cur[] = $r;
            $prevTs = $ts;
        }
        if ($cur) {
            $sessions[] = $cur;
        }

        // Ambil sesi TERAKHIR
        $last = $sessions[count($sessions) - 1];
        $t = 0; $p = 0;
        foreach ($last as $j) {
            $t += (int)$j['total_pages'];
            $p += (int)($j['printed_pages'] ?? 0);
        }
        // Amankan: printed tidak boleh > total (data spooler bisa aneh)
        if ($p > $t) { $p = $t; }
        $res = ['total_pages' => $t, 'printed_pages' => $p, 'has_data' => $t > 0];
    } catch (Throwable $e) {
        // abaikan — gagal agregasi tidak fatal
    }
    return $res;
}

/**
 * Jumlah LEMBAR FILM DTF yang sudah SELESAI dicetak utk sebuah order.
 *
 * Sumber: tabel `dtf_sheet_log` — catatan PERMANEN per lembar yang dikirim
 * agent DTF (`dtf_sheet_events`). Tabel ini tahan terhadap:
 *   - file log Digital Factory yang ter-rotate / terhapus
 *   - agent restart / mati
 *   - printer yang sudah melepas order (`printers.current_order` kosong)
 *
 * KENAPA PERLU (FIX 2026-09-11, keluhan user "kenapa nota ga baca processnya"):
 * Nota sebelumnya HANYA membaca tabel `printers` (`current_order` /
 * `extra_info`). Begitu mesin selesai / melepas order, `current_order`
 * dikosongkan → nota kehilangan rujukan → progress & info "N dari M lembar
 * film" HILANG, padahal riwayat cetaknya ADA di `dtf_sheet_log`. Konsep awal
 * (film selesai → lanjut press baju) jadi tidak pernah tampil.
 *
 * @return int jumlah lembar selesai (0 kalau belum ada)
 */
function getDtfSheetsDone(string $orderNumber): int
{
    if ($orderNumber === '') {
        return 0;
    }
    try {
        $st = db()->prepare("SELECT COUNT(DISTINCT sheet_no) FROM dtf_sheet_log
            WHERE order_number = ? AND status = 'selesai'");
        $st->execute([$orderNumber]);
        return max(0, (int)$st->fetchColumn());
    } catch (Throwable $e) {
        // Tabel mungkin belum ada (deploy lama) — jangan fatal.
        return 0;
    }
}

/**
 * SATU SUMBER KEBENARAN untuk status & progress cetak sebuah order.
 *
 * ============================================================================
 * KENAPA FUNGSI INI ADA (baca sebelum mengubah!)
 * ============================================================================
 * Nota (`public/index.php`) punya DUA jalur yang menyajikan data yang sama:
 *   1. RENDER AWAL  → variabel `$pcsRender`, `$progress`, `$printStatus`
 *   2. POLLING AJAX → variabel `$pcsInfo`,   `$printerProgress`, `$printStatusAjax`
 *
 * Logika yang sama DULU ditulis DUA KALI di kedua jalur. Akibatnya setiap
 * perbaikan harus diterapkan 2x, dan kalau satu terlewat hasilnya jadi TIDAK
 * KONSISTEN: tampilan awal benar, 5 detik kemudian (setelah polling) berubah
 * salah — atau sebaliknya. Itu penyebab keluhan berulang "1 rusak 1 benar".
 *
 * SEKARANG: semua perhitungan status/progress/lembar DITULIS SEKALI di sini.
 * Kedua jalur HANYA memanggil fungsi ini. Kalau ada bug, cukup perbaiki 1 tempat
 * → dijamin kedua jalur ikut benar.
 *
 * ATURAN YANG DIPAKAI (jujur, berbasis BUKTI — bukan asumsi):
 *   - Lembar film selesai = `dtf_sheet_log` (catatan PERMANEN per lembar).
 *     Tahan agent mati / log DF ter-rotate / order sudah dilepas printer.
 *   - Target lembar = ceil(total baju / orders.dtf_per_lembar).
 *     Kalau operator tidak mengisi → 1 lembar (realita produksi order kecil).
 *   - Status "sedang cetak" HANYA kalau ada bukti: printer mengerjakan order ini
 *     DAN statusnya aktif. Kalau tidak ada bukti → 'Idle'/'Pause'/'' (tidak
 *     mengaku mencetak). Kolom `orders.print_status` TIDAK dipercaya karena
 *     sering basi (sisa 'Print' dari cetakan lama).
 *
 * @param string     $orderNumber  nomor order (mis. CG1109267731)
 * @param array|null $order        baris orders (butuh is_dtf, dtf_per_lembar, print_status)
 * @param array|null $printerRow   baris printer yang mencantumkan order ini (boleh null)
 * @return array{
 *   is_dtf:int, dtf:array, sheets_done:int, sheets_target:int,
 *   progress:int, print_status:string, mesin_aktif:bool, unit_label:string
 * }
 */
function resolveOrderPrintState(string $orderNumber, $order = null, $printerRow = null): array
{
    // ========================================================================
    // PENTING — JANGAN kembalikan type-hint `?array` di parameter 2 & 3!
    // ========================================================================
    // PDO `fetch()` mengembalikan **false** (bukan null) kalau tidak ada baris.
    // PHP memeriksa tipe argumen SEBELUM isi fungsi berjalan, jadi kalau
    // parameter diberi tipe `?array` dan pemanggil mengirim `false` (atau
    // `null` secara implisit), PHP langsung melempar TypeError -> SELURUH
    // halaman nota error 500. Normalisasi is_array() DI DALAM fungsi TIDAK
    // akan pernah tercapai.
    //
    // Terbukti saat uji menyeluruh 2026-09-12: resolveOrderPrintState($on,
    // false, false) bikin halaman error. Karena itu parameter dibuat longgar
    // (tanpa type-hint) lalu dinormalkan di sini.
    // ========================================================================
    if (!is_array($printerRow)) {
        $printerRow = null;
    }
    if (!is_array($order)) {
        $order = null;
    }
    $out = [
        'is_dtf'        => 0,
        'dtf'           => [
            'dtf'                 => 0,
            'dtf_sheets_printed'  => 0,
            'dtf_sheets_target'   => 0,
            'dtf_qty_baju'        => 0,
            'dtf_baju_per_lembar' => 0,
            'dtf_baju_printed'    => 0,
            'unit_label'          => 'pcs',
        ],
        'sheets_done'   => 0,
        'sheets_target' => 0,
        'progress'      => 0,
        'print_status'  => '',
        'mesin_aktif'   => false,
        'unit_label'    => 'pcs',
    ];
    if ($orderNumber === '') {
        return $out;
    }

    // ---- 1) Deteksi apakah order ini DTF/sablon -----------------------------
    // Penanda (urutan prioritas): orders.is_dtf → dtf_per_lembar → nama item.
    // CATATAN: `press_menit_per_baju` SENGAJA TIDAK dipakai — dulu kolom itu
    // bikin order baliho/spanduk salah terdeteksi DTF.
    $perLembar = (int)($order['dtf_per_lembar'] ?? 0);
    $isDtf = !empty($order['is_dtf']) || $perLembar > 0;

    // Qty baju dari item sablon/kaos (dipakai utk target & estimasi baju).
    $qtyBaju = 0;
    try {
        $stItems = db()->prepare("SELECT product_name, qty FROM order_items
            WHERE order_id = (SELECT id FROM orders WHERE order_number = ? LIMIT 1)");
        $stItems->execute([$orderNumber]);
        foreach ($stItems->fetchAll() as $it) {
            $pn = mb_strtolower((string)($it['product_name'] ?? ''));
            if (strpos($pn, 'kaos') !== false || strpos($pn, 'baju') !== false
                || strpos($pn, 'sablon') !== false || strpos($pn, 'shirt') !== false) {
                $qtyBaju += (float)($it['qty'] ?? 0);
                $isDtf = true;
            }
        }
        $qtyBaju = (int)round($qtyBaju);
    } catch (Throwable $e) {
        // abaikan — deteksi lewat is_dtf/dt_per_lembar tetap jalan
    }

    if (!$isDtf) {
        // Non-DTF (spanduk/baliho/undangan): status cetak juga harus jujur.
        // Prinsip sama dgn DTF: "sedang cetak" hanya kalau mesin benar-benar
        // aktif. Kalau printer mencantumkan order ini tapi statusnya tidak
        // aktif & sudah ada progress → pekerjaan BERHENTI di tengah → 'Pause'
        // (jujur "Ditunda"), bukan tetap 'Print' selamanya.
        $out['mesin_aktif'] = _printerSedangMengerjakan($printerRow);
        if ($out['mesin_aktif']) {
            $out['print_status'] = (string)($printerRow['print_status'] ?? '');
        } elseif (!empty($printerRow) && (int)($printerRow['progress'] ?? 0) > 0) {
            $out['print_status'] = 'Pause';
        }
        return $out;
    }

    // ---- 2) Lembar selesai dari catatan PERMANEN ---------------------------
    $done = getDtfSheetsDone($orderNumber);
    // Lembar selesai bisa juga dikirim printer (extra_info) — ambil yg TERBESAR
    // supaya tidak ada nilai yang mundur.
    $exP = [];
    if (!empty($printerRow['extra_info'])) {
        $exP = json_decode((string)$printerRow['extra_info'], true) ?: [];
    }
    $donePrinter = (int)($exP['dtf_sheets_printed'] ?? 0);
    if ($donePrinter > $done) {
        $done = $donePrinter;
    }

    // ---- 3) Target lembar ---------------------------------------------------
    $target = (int)($exP['dtf_sheets_target'] ?? 0);
    if ($target <= 0 && $perLembar > 0 && $qtyBaju > 0) {
        $target = (int)ceil($qtyBaju / $perLembar);
    }
    if ($target <= 0) {
        // Operator tidak mengisi "isi baju per lembar":
        // default 1 lembar (realita: order kecil dicetak 1 gang-sheet).
        $target = max(1, $done);
    }
    // Target tidak boleh lebih kecil dari lembar yang sudah jadi.
    if ($target < $done) {
        $target = $done;
    }

    // ---- 4) Baju per lembar & estimasi baju selesai ------------------------
    $bajuPerLembar = $perLembar > 0 ? $perLembar : ($qtyBaju > 0 ? $qtyBaju : 1);

    // ---- 5) Status cetak JUJUR --------------------------------------------
    // TANDA FILM SELESAI DARI ORDER (`orders.press_wa_at`):
    // Kolom ini diisi server saat SEMUA lembar dianggap tuntas & notif "wajib
    // ===== TANDA FILM SELESAI DARI ORDER (`orders.press_wa_at`) =====
    // Kolom ini diisi server saat SEMUA lembar dianggap tuntas & notif "wajib
    // press" terkirim. Penanda PERSISTEN yang tidak bergantung printer/agent.
    //
    // PERBAIKAN 2026-09-12 (keluhan user: "sekarang lagi cetak tapi ga kebaca,
    // progress 0 terus"). MASALAH: penanda ini DIPERCAYA TANPA SYARAT, padahal
    // bisa TERISI KELIRU. Kejadian nyata order CG1109267733 (target 5 lembar):
    // lembar ke-5 GAGAL cetak (USB write error -> "Job Processing aborted"),
    // tapi versi lama agent menghitungnya SELESAI -> done=5 -> server kirim
    // notif "film selesai" & isi `press_wa_at`. Sejak itu nota menganggap film
    // tuntas (5/5, 100%, Idle) padahal mesin MASIH harus mencetak lembar ke-5.
    //
    // SYARAT BARU: `press_wa_at` hanya dipercaya bila TIDAK ADA lembar GAGAL
    // menurut `dtf_sheet_log` (catatan permanen). Kalau ada lembar gagal, film
    // BELUM tuntas -> jangan paksa `done = target`.
    $filmSelesaiByOrder = false;
    if (!empty($order['press_wa_at'])
        && $order['press_wa_at'] !== '0000-00-00 00:00:00') {
        $filmSelesaiByOrder = true;
        // Cek jejak kegagalan: kalau ada lembar 'gagal', penanda tuntas
        // tidak sah (film masih harus dicetak ulang).
        try {
            $qGagal = db()->prepare("SELECT COUNT(*) FROM dtf_sheet_log
                WHERE order_number = ? AND status = 'gagal'");
            $qGagal->execute([$orderNumber]);
            if ((int)$qGagal->fetchColumn() > 0) {
                $filmSelesaiByOrder = false;
            }
        } catch (Throwable $e) {
            // tabel tak ada -> biarkan penanda apa adanya (perilaku lama)
        }
    }

    $out['mesin_aktif'] = _printerSedangMengerjakan($printerRow);
    if ($out['mesin_aktif']) {
        // Printer benar-benar mengerjakan order ini → pakai statusnya.
        $out['print_status'] = (string)($printerRow['print_status'] ?? '');
    } elseif ($filmSelesaiByOrder || ($target > 0 && $done >= $target)) {
        // Film tuntas → mesin tidak mencetak lagi. 'Idle' = masuk tahap press.
        $out['print_status'] = 'Idle';
        if ($filmSelesaiByOrder && $done < $target) {
            // Selaraskan hitungan: film sudah selesai menurut order, jadi
            // tampilkan lembar selesai = target (jangan nyangkut di 4/5).
            $done = $target;
        }
    } elseif ($done > 0) {
        // Masih ada sisa lembar tapi mesin tidak aktif → jujur "Ditunda".
        $out['print_status'] = 'Pause';
    } else {
        // Belum ada lembar sama sekali → belum pernah dicetak. JANGAN mengaku.
        $out['print_status'] = '';
    }

    // ---- 6) Progress dari lembar (jujur) ----------------------------------
    $prog = 0;
    if ($target > 0) {
        $prog = (int)round($done * 100 / $target);
        if ($done < $target) {
            $prog = min(99, $prog);   // belum tuntas → jangan 100%
        }
        $prog = max(0, min(100, $prog));
    }
    // Kalau printer melaporkan progress lebih tinggi (mis. sedang cetak lembar
    // aktif dgn frac), hormati — jangan turunkan.
    $progPrinter = (int)($printerRow['progress'] ?? 0);
    if ($printerRow && $progPrinter > $prog && $out['mesin_aktif']) {
        $prog = min(100, $progPrinter);
    }

    $out['is_dtf']        = 1;
    $out['sheets_done']   = $done;
    $out['sheets_target'] = $target;
    $out['progress']      = $prog;
    $out['unit_label']    = 'lembar film';
    $out['dtf'] = [
        'dtf'                 => 1,
        'dtf_sheets_printed'  => $done,
        'dtf_sheets_target'   => $target,
        'dtf_qty_baju'        => $qtyBaju,
        'dtf_baju_per_lembar' => $bajuPerLembar,
        'dtf_baju_printed'    => min($qtyBaju > 0 ? $qtyBaju : $done, $done * $bajuPerLembar),
        'unit_label'          => 'lembar film',
    ];
    return $out;
}

/**
 * Apakah printer ini SEDANG mengerjakan order (status aktif)?
 * Dipakai sbg bukti sebelum mengklaim "sedang cetak".
 */
function _printerSedangMengerjakan($printerRow): bool
{
    // CATATAN: parameter SENGAJA tanpa type-hint `?array` — PDO fetch() bisa
    // mengembalikan `false`, dan PHP memeriksa tipe SEBELUM isi fungsi jalan
    // (TypeError -> halaman 500). Lihat penjelasan di resolveOrderPrintState().
    if (!is_array($printerRow) || empty($printerRow)) {
        return false;
    }
    $ps = mb_strtolower(trim((string)($printerRow['print_status'] ?? '')));
    return in_array($ps, ['print', 'printing', 'pause', 'mencetak', 'cetak', 'running'], true);
}

/* =====================================================================
 * AUTO CACHE-BUSTER + AUTO PURGE CLOUDFLARE
 * - Semua aset (CSS/JS) diberi ?v= berdasarkan filemtime → browser &
 *   Cloudflare selalu ambil versi terbaru saat file berubah.
 * - Saat aset/kode berubah, purge cache Cloudflare otomatis (bila API
 *   token terisi) supaya HTML terbaru langsung tampil tanpa purge manual.
 * ===================================================================== */

/**
 * URL absolut ke file aset (public/ di-root web).
 * Contoh: assetUrl('/assets/img/logo.png') → https://domain/assets/img/logo.png
 */
function assetUrl(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return siteUrl() . $path;
}

/**
 * Versi aset gabungan: filemtime terbaru dari app.css + app.js (+ style.css).
 * Dipakai sbg query string cache-buster: ?v=ASSET_VER.
 */
function assetVer(): string
{
    static $ver = null;
    if ($ver !== null) {
        return $ver;
    }
    // Lokasi aset: di Docker, ./assets di-mount ke /var/www/html/assets.
    // BASE_PATH = /var/www. Coba beberapa lokasi agar kompatibel lokal & container.
    $paths = [
        BASE_PATH . '/html/assets/css/app.css',
        BASE_PATH . '/html/assets/js/app.js',
        BASE_PATH . '/../assets/css/app.css',
        BASE_PATH . '/../assets/js/app.js',
        BASE_PATH . '/../public/assets/css/app.css',
        BASE_PATH . '/../public/assets/js/app.js',
    ];
    $mtime = 0;
    foreach ($paths as $p) {
        $t = @filemtime($p);
        if ($t !== false && $t > $mtime) {
            $mtime = $t;
        }
    }
    // Fallback: hash dari APP_VERSION bila tidak ada file (mis. CLI)
    $ver = $mtime > 0 ? (string)$mtime : md5((string)APP_VERSION);
    return $ver;
}

/**
 * Fingerprint kode saat ini: filemtime terbaru dari file penting.
 * Dipakai untuk mendeteksi "apakah ada update" → pemicu auto-purge.
 */
function codeFingerprint(): string
{
    static $fp = null;
    if ($fp !== null) {
        return $fp;
    }
    $files = [
        BASE_PATH . '/html/assets/css/app.css',
        BASE_PATH . '/html/assets/js/app.js',
        BASE_PATH . '/config/app.php',
        BASE_PATH . '/config/security.php',
        BASE_PATH . '/config/menus.php',
        BASE_PATH . '/includes/functions.php',
    ];
    $buf = '';
    foreach ($files as $p) {
        $buf .= (string)@filemtime($p);
    }
    $fp = md5($buf . APP_VERSION);
    return $fp;
}

/**
 * Auto-purge Cloudflare bila kode berubah (dipanggil sekali per request,
 * aman: cek cepat di memori + DB). Kembalikan true bila purge dijalankan.
 */
function autoPurgeCloudflareIfChanged(): bool
{
    // Hanya owner yang memicu (kontrol). Untuk semua request cukup cek fingerprint.
    if (($_SESSION['role'] ?? '') !== 'owner') {
        return false;
    }
    $cfg = cloudflareConfig();
    if ($cfg['token'] === '' || $cfg['zone_id'] === '') {
        return false; // token belum diisi → tidak bisa purge
    }
    $fp = codeFingerprint();
    try {
        $st = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = 'cf_last_fingerprint'");
        $st->execute();
        $last = (string)$st->fetchColumn();
        if ($last === $fp) {
            return false; // tidak ada perubahan
        }
        // Ada update → purge cache zone + simpan fingerprint baru
        $res = callCloudflare('POST', 'zones/' . rawurlencode($cfg['zone_id']) . '/purge_cache', ['purge_everything' => true]);
        if ($res['success']) {
            db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('cf_last_fingerprint', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$fp]);
            writeLog("Auto-purge Cloudflare: kode berubah (fingerprint baru) oleh {$_SESSION['username']}", 'INFO');
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

/**
 * Cek status online/offline domain (APP_URL).
 * Hasil di-cache di session selama 60 detik agar tidak memperlambat halaman.
 * @return array ['online' => bool, 'code' => int|string]
 */
function domainStatus(): array
{
    $cacheKey = 'cg_domain_status';
    $now = time();
    if (isset($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])
        && ($_SESSION[$cacheKey]['t'] ?? 0) > $now - 60) {
        return $_SESSION[$cacheKey]['d'];
    }

    $url = siteUrl() !== '' ? siteUrl() : 'http://localhost';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $online = $err === '' && $code >= 200 && $code < 400;
    $result = ['online' => $online, 'code' => $code > 0 ? $code : 'err'];
    $_SESSION[$cacheKey] = ['t' => $now, 'd' => $result];
    return $result;
}

/**
 * URL situs aktif — bisa di-override lewat setting `site_url` (domain kustom
 * yang dikelola di halaman Domain). Fallback ke konstanta APP_URL.
 */
function siteUrl(): string
{
    $custom = (string)getSetting('site_url', '');
    if ($custom !== '') {
        return rtrim($custom, '/');
    }
    return defined('APP_URL') ? APP_URL : '';
}

/**
 * URL cantik ke nota digital (e-receipt): /nota/CGxxxxx
 * Dipakai untuk semua link/QR/notifikasi — ganti index.php?order= yang jelek.
 * @param string $orderNumber Nomor order (mis. CG1808260021).
 * @return string URL absolut (mis. https://cloud.creativegeek-idn.com/nota/CG1808260021)
 */
function notaUrl(string $orderNumber): string
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '') {
        return siteUrl() . '/';
    }
    return siteUrl() . '/nota/' . rawurlencode($orderNumber);
}

/**
 * URL cantik ke halaman AR Simulasi Ukuran: /ar/<ukuran>[/<tipe>]
 * Ukuran sudah DINAMIS (diubah lewat slider di halaman), jadi cukup satu
 * link cantik — pelanggan tinggal geser ukurannya lalu "Lihat di Ruangan".
 * @param string $ukuran  Mis. "2x1", "60x160", "3x4".
 * @param string $tipe    Opsional: spanduk | xbanner | rollup | stiker.
 * @return string URL absolut (mis. .../ar/2x1, .../ar/60x160/xbanner)
 */
function arUrl(string $ukuran, string $tipe = ''): string
{
    $ukuran = strtolower(trim($ukuran));
    $tipe = strtolower(trim($tipe));
    $path = '/ar';
    if ($ukuran !== '') {
        $path .= '/' . rawurlencode($ukuran);
        if (in_array($tipe, ['spanduk', 'xbanner', 'x-banner', 'rollup', 'roll up', 'stiker'], true)) {
            $path .= '/' . rawurlencode($tipe);
        }
    }
    return siteUrl() . $path;
}

/**
 * Status server Docker via docker-socket-proxy (openwa-docker-proxy:2375).
 * Hanya memakai endpoint GET yang diizinkan proxy: /containers/json, /info.
 * Hasil di-cache di session selama 15 detik agar tidak memperlambat halaman.
 * @return array [
 *   'ok' => bool,
 *   'error' => string,
 *   'containers' => list<['name','image','state','status']>,
 *   'system' => ['containers_total','containers_running','cpu','mem_bytes','name','os']
 * ]
 */
function dockerServerStatus(): array
{
    $cacheKey = 'cg_docker_status';
    $now = time();
    if (isset($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])
        && ($_SESSION[$cacheKey]['t'] ?? 0) > $now - 15) {
        return $_SESSION[$cacheKey]['d'];
    }

    $base = 'http://openwa-docker-proxy:2375';
    $result = [
        'ok' => false,
        'error' => '',
        'containers' => [],
        'system' => ['containers_total' => 0, 'containers_running' => 0, 'cpu' => 0, 'mem_bytes' => 0, 'name' => '', 'os' => ''],
    ];

    $fetch = function (string $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err !== '' || $code !== 200) {
            return null;
        }
        $data = json_decode((string)$body, true);
        return is_array($data) ? $data : null;
    };

    $list = $fetch($base . '/containers/json?all=1');
    if ($list === null) {
        $result['error'] = 'Docker API tidak terjangkau';
        $_SESSION[$cacheKey] = ['t' => $now, 'd' => $result];
        return $result;
    }

    $containers = [];
    foreach ($list as $c) {
        $names = (array)($c['Names'] ?? []);
        $name = '';
        foreach ($names as $n) {
            $n = ltrim((string)$n, '/');
            if ($n !== '') { $name = $n; break; }
        }
        $state = (string)($c['State'] ?? 'unknown');
        $containers[] = [
            'name'  => $name !== '' ? $name : mb_substr((string)($c['Id'] ?? '?'), 0, 12),
            'image' => (string)($c['Image'] ?? ''),
            'state' => $state,
            'status' => (string)($c['Status'] ?? ''),
        ];
    }
    usort($containers, function ($a, $b) {
        $order = ['running' => 0, 'created' => 1, 'restarting' => 2, 'paused' => 3, 'exited' => 4, 'dead' => 5];
        $sa = $order[$a['state']] ?? 9;
        $sb = $order[$b['state']] ?? 9;
        if ($sa !== $sb) return $sa <=> $sb;
        return strcmp($a['name'], $b['name']);
    });

    $system = $result['system'];
    $info = $fetch($base . '/info');
    if ($info !== null) {
        $system['containers_total']   = (int)($info['Containers'] ?? count($containers));
        $system['containers_running'] = (int)($info['ContainersRunning'] ?? 0);
        $system['cpu']  = (int)($info['NCPU'] ?? 0);
        $system['mem_bytes'] = (float)($info['MemTotal'] ?? 0);
        $system['name'] = (string)($info['Name'] ?? '');
        $system['os']   = (string)($info['OperatingSystem'] ?? '');
    } else {
        $system['containers_total'] = count($containers);
        $running = 0;
        foreach ($containers as $c) {
            if ($c['state'] === 'running') $running++;
        }
        $system['containers_running'] = $running;
    }

    $result['ok'] = true;
    $result['containers'] = $containers;
    $result['system'] = $system;
    $_SESSION[$cacheKey] = ['t' => $now, 'd' => $result];
    return $result;
}

/* =====================================================================
 * CLOUDFLARE (backend domain) — API v4
 * Setting: cf_api_token, cf_zone_id, cf_zone_name
 * ===================================================================== */
function cloudflareConfig(): array
{
    return [
        'token'     => (string)getSetting('cf_api_token', ''),
        'zone_id'   => (string)getSetting('cf_zone_id', ''),
        'zone_name' => (string)getSetting('cf_zone_name', ''),
    ];
}

function callCloudflare(string $method, string $path, ?array $data = null): array
{
    $cf = cloudflareConfig();
    if ($cf['token'] === '') {
        return ['success' => false, 'error' => 'Cloudflare API Token belum diatur'];
    }
    $url = 'https://api.cloudflare.com/client/v4/' . ltrim($path, '/');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = ['Authorization: Bearer ' . $cf['token'], 'Content-Type: application/json'];
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $json = json_decode((string)$resp, true);
    if ($code >= 200 && $code < 300 && ($json['success'] ?? false)) {
        return ['success' => true, 'data' => $json['result'] ?? [], 'code' => $code];
    }
    $msg = (string)($json['errors'][0]['message'] ?? '');
    if ($msg === '' && $err !== '') {
        $msg = $err;
    }
    if ($msg === '') {
        $msg = 'Cloudflare HTTP ' . $code;
    }
    return ['success' => false, 'error' => $msg, 'code' => $code];
}

/* =====================================================================
 * CLOUDFLARE CACHE RULES — cegah halaman /login ter-cache
 * Penyebab error "Sesi tidak valid (CSRF)": Cloudflare meng-cache HTML
 * login (token CSRF usang + tanpa Set-Cookie). Rule ini mem-bypass cache
 * untuk /login lalu purge cache zone.
 * ===================================================================== */
const CF_CACHE_PHASE = 'http_request_cache_settings';
const CF_BYPASS_RULE_DESC = 'CreativeGeek: bypass cache halaman login (CSRF)';

/**
 * Cek apakah Cache Rule bypass /login sudah aktif di zone Cloudflare.
 */
function cloudflareLoginBypassActive(): bool
{
    $cf = cloudflareConfig();
    if ($cf['zone_id'] === '') {
        return false;
    }
    $zone = 'zones/' . rawurlencode($cf['zone_id']);
    $list = callCloudflare('GET', $zone . '/rulesets');
    if (!$list['success']) {
        return false;
    }
    foreach ($list['data'] as $rs) {
        if (($rs['phase'] ?? '') !== CF_CACHE_PHASE || ($rs['kind'] ?? '') !== 'zone') {
            continue;
        }
        $get = callCloudflare('GET', $zone . '/rulesets/' . rawurlencode($rs['id']));
        if (!$get['success']) {
            continue;
        }
        foreach ($get['data']['rules'] ?? [] as $r) {
            if (($r['description'] ?? '') === CF_BYPASS_RULE_DESC) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Pastikan Cache Rule "bypass" untuk /login ada di zone (tidak menghapus rule lain).
 * Mengembalikan ['success'=>bool, 'error'=>string, 'created'=>bool].
 */
function cloudflareEnsureLoginBypass(): array
{
    $cf = cloudflareConfig();
    if ($cf['zone_id'] === '') {
        return ['success' => false, 'error' => 'Zone ID Cloudflare belum diatur.', 'created' => false];
    }
    $zone = 'zones/' . rawurlencode($cf['zone_id']);

    $rule = [
        'expression'        => '(starts_with(http.request.uri.path, "/login"))',
        'description'       => CF_BYPASS_RULE_DESC,
        'action'            => 'set_cache_settings',
        'action_parameters' => ['cache' => false],
    ];

    $list = callCloudflare('GET', $zone . '/rulesets');
    if (!$list['success']) {
        return ['success' => false, 'error' => 'Gagal membaca ruleset: ' . $list['error'], 'created' => false];
    }

    $rulesetId = null;
    foreach ($list['data'] as $rs) {
        if (($rs['phase'] ?? '') === CF_CACHE_PHASE && ($rs['kind'] ?? '') === 'zone') {
            $rulesetId = $rs['id'];
            break;
        }
    }

    if ($rulesetId === null) {
        // Belum ada ruleset cache di zone — buat baru
        $res = callCloudflare('POST', $zone . '/rulesets', [
            'name'  => 'CreativeGeek Cache Rules',
            'kind'  => 'zone',
            'phase' => CF_CACHE_PHASE,
            'rules' => [$rule],
        ]);
        return ['success' => $res['success'], 'error' => $res['error'] ?? '', 'created' => true];
    }

    // Ambil rule yang sudah ada, buang duplikat, lalu taruh rule bypass PALING BAWAH
    // (Cache Rules: jika beberapa rule cocok, rule terakhir yang menang).
    $get = callCloudflare('GET', $zone . '/rulesets/' . rawurlencode($rulesetId));
    if (!$get['success']) {
        return ['success' => false, 'error' => 'Gagal membaca ruleset: ' . $get['error'], 'created' => false];
    }
    $rules = $get['data']['rules'] ?? [];
    $rules = array_values(array_filter($rules, fn($r) => ($r['description'] ?? '') !== CF_BYPASS_RULE_DESC));
    $rules[] = $rule;

    $res = callCloudflare('PUT', $zone . '/rulesets/' . rawurlencode($rulesetId), ['rules' => $rules]);
    return ['success' => $res['success'], 'error' => $res['error'] ?? '', 'created' => false];
}

/* =====================================================================
 * CLOUDFLARE BYPASS SEMUA HALAMAN DINAMIS — update langsung terlihat
 * Rule ini mem-bypass cache untuk SEMUA path (kecuali aset statis),
 * sehingga perubahan kode/data langsung tampil tanpa purge manual.
 * ===================================================================== */
const CF_DYNAMIC_BYPASS_DESC = 'CreativeGeek: bypass cache halaman dinamis (update langsung)';

/**
 * Cek apakah Cache Rule bypass semua halaman dinamis sudah aktif.
 */
function cloudflareDynamicBypassActive(): bool
{
    $cf = cloudflareConfig();
    if ($cf['zone_id'] === '') {
        return false;
    }
    $zone = 'zones/' . rawurlencode($cf['zone_id']);
    $list = callCloudflare('GET', $zone . '/rulesets');
    if (!$list['success']) {
        return false;
    }
    foreach ($list['data'] as $rs) {
        if (($rs['phase'] ?? '') !== CF_CACHE_PHASE || ($rs['kind'] ?? '') !== 'zone') {
            continue;
        }
        $get = callCloudflare('GET', $zone . '/rulesets/' . rawurlencode($rs['id']));
        if (!$get['success']) {
            continue;
        }
        foreach ($get['data']['rules'] ?? [] as $r) {
            if (($r['description'] ?? '') === CF_DYNAMIC_BYPASS_DESC) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Pastikan ada Cache Rule "bypass" untuk SEMUA path dinamis.
 * Rule ditempatkan PALING BAWAH (Cache Rules: rule terakhir yang cocok = menang),
 * supaya menimpa rule "Cache Everything" yang memaksa cache HTML.
 */
function cloudflareEnsureDynamicBypass(): array
{
    $cf = cloudflareConfig();
    if ($cf['zone_id'] === '') {
        return ['success' => false, 'error' => 'Zone ID Cloudflare belum diatur.', 'created' => false];
    }
    $zone = 'zones/' . rawurlencode($cf['zone_id']);

    // Semua request KECUALI aset statis (gambar/font/css/js) → bypass cache
    // Pakai ends_with (gratis, semua paket) — matches/regex butuh Business/WAF Advanced.
    $static = ['.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp',
               '.ico', '.woff', '.woff2', '.ttf', '.eot', '.pdf', '.zip', '.mp4'];
    $staticExpr = implode(' or ', array_map(
        fn($ext) => 'ends_with(http.request.uri.path, "' . $ext . '")',
        $static
    ));
    $rule = [
        'expression'        => '(not (' . $staticExpr . '))',
        'description'       => CF_DYNAMIC_BYPASS_DESC,
        'action'            => 'set_cache_settings',
        'action_parameters' => ['cache' => false],
    ];

    $list = callCloudflare('GET', $zone . '/rulesets');
    if (!$list['success']) {
        return ['success' => false, 'error' => 'Gagal membaca ruleset: ' . $list['error'], 'created' => false];
    }

    $rulesetId = null;
    foreach ($list['data'] as $rs) {
        if (($rs['phase'] ?? '') === CF_CACHE_PHASE && ($rs['kind'] ?? '') === 'zone') {
            $rulesetId = $rs['id'];
            break;
        }
    }

    if ($rulesetId === null) {
        $res = callCloudflare('POST', $zone . '/rulesets', [
            'name'  => 'CreativeGeek Cache Rules',
            'kind'  => 'zone',
            'phase' => CF_CACHE_PHASE,
            'rules' => [$rule],
        ]);
        return ['success' => $res['success'], 'error' => $res['error'] ?? '', 'created' => true];
    }

    $get = callCloudflare('GET', $zone . '/rulesets/' . rawurlencode($rulesetId));
    if (!$get['success']) {
        return ['success' => false, 'error' => 'Gagal membaca ruleset: ' . $get['error'], 'created' => false];
    }
    $rules = $get['data']['rules'] ?? [];
    $rules = array_values(array_filter($rules, fn($r) => ($r['description'] ?? '') !== CF_DYNAMIC_BYPASS_DESC));
    $rules[] = $rule;

    $res = callCloudflare('PUT', $zone . '/rulesets/' . rawurlencode($rulesetId), ['rules' => $rules]);
    return ['success' => $res['success'], 'error' => $res['error'] ?? '', 'created' => false];
}

/* =====================================================================
 * PREFERENSI PRIBADI PER USER (theme independent per user)
 * Disimpan di tabel user_preferences — tiap user punya warna sendiri
 * TANPA mengganggu user lain. Fallback ke setting global bila kosong.
 * ===================================================================== */
function userPref(string $key, string $default = ''): string
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return $default;
    try {
        $st = db()->prepare("SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?");
        $st->execute([$uid, $key]);
        $v = (string)$st->fetchColumn();
        return $v !== '' ? $v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function setUserPref(string $key, string $value): void
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return;
    try {
        db()->prepare("INSERT INTO user_preferences (user_id, pref_key, pref_value, updated_at) VALUES (?,?,?,NOW())
                       ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = NOW()")
            ->execute([$uid, $key, $value]);
    } catch (Throwable $e) {
        // jangan biarkan error pref menggagalkan halaman
    }
}

function delUserPref(string $key): void
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return;
    try {
        db()->prepare("DELETE FROM user_preferences WHERE user_id = ? AND pref_key = ?")->execute([$uid, $key]);
    } catch (Throwable $e) {
    }
}

/**
 * Mode tema global: 'dark' (default) atau 'light'.
 * Disimpan di settings.theme_mode (GLOBAL — berlaku untuk semua user).
 */
function themeMode(): string
{
    $mode = strtolower((string)getSetting('theme_mode', 'dark'));
    return ($mode === 'light') ? 'light' : 'dark';
}

/**
 * CSS variable override untuk tema kustom — dipakai di <head> (header.php).
 * Menghasilkan nilai turunan (surface-2/3, border, gold-bg, dll) dari
 * 3 warna dasar agar tema tetap koheren.
 *
 * Mode 'light' memakai palet dasar terang; aksen & warna dasar user tetap
 * dipakai (tema PRIBADI user menimpa GLOBAL bila user memilihnya).
 */
function themeCssVars(): string
{
    // Tema PRIBADI user (independent) menimpa tema GLOBAL bila user memilihnya.
    $accent  = userPref('theme_accent', (string)getSetting('theme_accent', '#f5a623'));
    $bg      = userPref('theme_bg', (string)getSetting('theme_bg', '#0a0a0f'));
    $surface = userPref('theme_surface', (string)getSetting('theme_surface', '#15151c'));

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent))  $accent  = '#f5a623';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bg))      $bg      = '#0a0a0f';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $surface)) $surface = '#15151c';

    // Mode terang: pakai palet dasar terang kecuali user/owner sudah menimpa.
    if (themeMode() === 'light') {
        $bgDefault  = '#f4f5f8';
        $bg         = userPref('theme_bg', (string)getSetting('theme_bg', '')) === '' ? $bgDefault : $bg;
        $surfaceDef = '#ffffff';
        $surface    = userPref('theme_surface', (string)getSetting('theme_surface', '')) === '' ? $surfaceDef : $surface;
    }

    if (themeMode() === 'light') {
        return ":root {\n"
            . "  color-scheme: light;\n"
            . "  --gold: {$accent};\n"
            . "  --gold-strong: " . themeMix($accent, '#ffffff', 0.25) . ";\n"
            . "  --gold-dim: " . themeMix($accent, '#000000', 0.25) . ";\n"
            . "  --gold-bg: " . themeRgba($accent, 0.10) . ";\n"
            . "  --gold-border: " . themeRgba($accent, 0.30) . ";\n"
            . "  --bg: {$bg};\n"
            . "  --bg-soft: " . themeMix($bg, $surface, 0.5) . ";\n"
            . "  --surface: {$surface};\n"
            . "  --surface-2: " . themeMix($surface, '#000000', 0.03) . ";\n"
            . "  --surface-3: " . themeMix($surface, '#000000', 0.06) . ";\n"
            . "  --border: " . themeMix($surface, '#000000', 0.12) . ";\n"
            . "  --border-strong: " . themeMix($surface, '#000000', 0.22) . ";\n"
            . "  --text: #14141c;\n"
            . "  --text-muted: #5c5c6b;\n"
            . "  --text-faint: #8b8b98;\n"
            . "  --muted: #5c5c6b;\n"
            . "  --shadow-sm: 0 1px 3px rgba(20,20,30,.08);\n"
            . "  --shadow: 0 6px 20px rgba(20,20,30,.10);\n"
            . "  --shadow-lg: 0 16px 48px rgba(20,20,30,.16);\n"
            . "  --scroll-thumb: " . themeMix($surface, '#000000', 0.18) . ";\n"
            . "}\n";
    }

    return ":root {\n"
        . "  color-scheme: dark;\n"
        . "  --gold: {$accent};\n"
        . "  --gold-strong: " . themeMix($accent, '#ffffff', 0.25) . ";\n"
        . "  --gold-dim: " . themeMix($accent, '#000000', 0.35) . ";\n"
        . "  --gold-bg: " . themeRgba($accent, 0.10) . ";\n"
        . "  --gold-border: " . themeRgba($accent, 0.35) . ";\n"
        . "  --bg: {$bg};\n"
        . "  --bg-soft: " . themeMix($bg, $surface, 0.5) . ";\n"
        . "  --surface: {$surface};\n"
        . "  --surface-2: " . themeMix($surface, '#ffffff', 0.04) . ";\n"
        . "  --surface-3: " . themeMix($surface, '#ffffff', 0.10) . ";\n"
        . "  --border: " . themeMix($surface, '#ffffff', 0.14) . ";\n"
        . "  --border-strong: " . themeMix($surface, '#ffffff', 0.22) . ";\n"
        . "  --text: #eaeaf1;\n"
        . "  --text-muted: #9d9dab;\n"
        . "  --text-faint: #6d6d7a;\n"
        . "  --muted: #9d9dab;\n"
        . "  --shadow-sm: 0 1px 3px rgba(0,0,0,.35);\n"
        . "  --shadow: 0 6px 20px rgba(0,0,0,.35);\n"
        . "  --shadow-lg: 0 16px 48px rgba(0,0,0,.55);\n"
        . "  --scroll-thumb: " . themeMix($surface, '#ffffff', 0.16) . ";\n"
        . "}\n";
}

/* =====================================================================
 * TEMA & WARNA WEBSITE (kustomisasi owner + pribadi per user)
 * ===================================================================== */
function themeHexRgb(string $hex): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return [245, 166, 35];
    }
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function themeMix(string $hex, string $other, float $weight): string
{
    $a = themeHexRgb($hex);
    $b = themeHexRgb($other);
    $r = (int)round($a[0] + ($b[0] - $a[0]) * $weight);
    $g = (int)round($a[1] + ($b[1] - $a[1]) * $weight);
    $bl = (int)round($a[2] + ($b[2] - $a[2]) * $weight);
    return sprintf('#%02x%02x%02x', $r, $g, $bl);
}

function themeRgba(string $hex, float $alpha): string
{
    [$r, $g, $b] = themeHexRgb($hex);
    return "rgba({$r}, {$g}, {$b}, {$alpha})";
}

/**
 * Template pesan WhatsApp per status order — bisa dikustomisasi owner.
 * Placeholder yang didukung:
 *   {nama} {order} {link} {status} {alamat} {app}
 * Jika AI aktif & berhasil (DeepSeek), pesan AI dipakai; jika gagal/kredit
 * habis → otomatis pakai template owner (atau bawaan).
 */
/**
 * Footer detail pesanan yang JELAS (nomor, isi/ukuran, link).
 * Selalu ditampilkan di bagian bawah pesan status agar info penting
 * tidak hilang walau pesan utama dibuat AI/template.
 *
 * PENTING (2026-08-26): HARGA sengaja TIDAK dicantumkan di sini. Tujuan:
 * pelanggan diminta membuka LINK nota untuk melihat detail/harga, sehingga
 * data "siapa buka link nota" bisa di-track (lihat tabel link_clicks) untuk
 * kebutuhan targeting iklan/audience nanti.
 */
function buildOrderFooter(array $data): string
{
    $orderNumber = trim((string)($data['order_number'] ?? ''));
    $items       = array_values(array_filter((array)($data['items'] ?? []), function ($i) {
        return trim((string)$i) !== '';
    }));
    $link        = trim((string)($data['link'] ?? ''));

    $rows = [];
    if ($orderNumber !== '') $rows[] = "• No. Pesanan : {$orderNumber}";
    if ($items)              $rows[] = "• Isi Pesanan : " . implode(', ', $items);
    if ($link !== '')        $rows[] = "• Cek Status  : {$link}";

    if (!$rows) return '';
    return "━━━━━━━━━━━━━━━━━━━━\n"
        . "*DETAIL PESANAN*\n"
        . implode("\n", $rows)
        . "\n━━━━━━━━━━━━━━━━━━━━";
}

/**
 * Nama utk SAPAAN — kosongkan bila generik/placeholder (mis. "Customer",
 * "Tanpa nama", "-", "unknown") atau hanya emoji/simbol (pushName bukan nama asli).
 * Mencegah sapaan robotik seperti "Halo Customer!" / "Halo! 👋" utk yg tak bernama.
 */
function wa_greeting_name(string $name): string
{
    $name = trim((string)$name);
    if ($name === '') return '';

    /* FIX 2026-09-10: buang penanda LEMBAGA/INSTANSI yang ditempel di nama,
       mis. "Ci Angel *Circle" → "Ci Angel", "Abang Arif *Polres" → "Abang Arif",
       "ShukaJajan - Ibu Nurul *Polres" → "ShukaJajan - Ibu Nurul".
       Penanda ini berguna di DATABASE (asal instansi), tapi TIDAK boleh ikut
       diucapkan saat menyapa pelanggan — apalagi tanda "*" di WhatsApp berarti
       HURUF TEBAL, jadi pesan terlihat aneh ("Halo Ci Angel *Circle"). */
    $name = preg_replace('/\s*[|*]\s*[^|*]*$/u', '', (string)$name); // " *Circle" / " |Polres"
    $name = preg_replace('/\s*\([^)]*\)\s*$/u', '', (string)$name);  // " (Polres)"
    $name = trim((string)preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return '';

    $low = mb_strtolower($name);
    if (in_array($low, ['customer', 'tanpa nama', '-', 'unknown', 'guest', 'null'], true)) return '';
    // Buang emoji/simbol → bila tidak tersisa huruf/angka (mis. pushName "." / "🙏") → anggap tanpa nama
    $clean = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
    return trim((string)$clean) !== '' ? $name : '';
}

/**
 * Apakah nama pelanggan SUDAH mengandung gelar/sapaan sendiri di depannya?
 * Dipakai agar bot TIDAK menambah sapaan lain → mencegah dobel sapaan seperti
 * "Kak Ci Angel", "Kak Ko Mikel", "Kak Ka Cika" (keluhan pelanggan 2026-09-10:
 * "masa udah kak ada ci juga").
 * Termasuk sapaan lokal NTT/Timor: om, ci, ko, ka, kaka, bang, tanta.
 */
function wa_name_has_title(string $name): bool
{
    $name = trim((string)$name);
    if ($name === '') return false;
    return (bool)preg_match(
        '/^(pak|bu|bapak|ibu|om|ci|koh|ko|ka|kak|kaka|kk|tante|tanta|bang|abang|bapa|mas|mbak|mama|papa|dokter|dr|pdt|pendeta|ustadz|ustad|haji|hj)\b/i',
        $name
    );
}

function buildStatusMessage(string $status, array $data, ?bool $useAI = null): string
{
    $data += [
        'name'         => '',
        'order_number' => '',
        'link'         => '',
        'status_label' => '',
        'alamat'       => getSetting('address', ''),
        'app'          => defined('APP_NAME') ? APP_NAME : 'Creative Geek',
        'items'        => [],
        'total'        => 0,
        'design_fee'   => null,
    ];
    // Penanda: desain SUDAH dikasih pelanggan / tidak pakai jasa desain kita.
    // design_fee=0 (Tanpa Desain) → file desain sudah dari pelanggan → pesan JANGAN
    // bilang "nunggu desain". design_fee>0 (Dengan Jasa Desain) → kita yg desain.
    $data['has_design'] = ((int)($data['design_fee'] ?? 0) === 0) ? 1 : 0;
    // Sapaan tidak memakai nama generik/placeholder (hindari "Halo Customer!").
    $data['name'] = wa_greeting_name((string)$data['name']);

    /* Footer detail pesanan — selalu ditaruh di bagian bawah */
    $footer = buildOrderFooter($data);

    /* 1) Coba AI (DeepSeek) bila diaktifkan & punya key.
          $useAI: null = otomatis (ikuti setting), true = paksa AI, false = tanpa AI (bawaan/template) */
    $aiWanted = $useAI ?? (wa_ai_master_enabled() && getSetting('ai_enabled', '0') === '1');
    if ($aiWanted && getSetting('ai_api_key', '') !== '') {
        $ai = aiGenerateText($status, $data);
        if ($ai !== '') {
            if ($status === 'sapaan' || $footer === '') return $ai;
            return $ai . "\n\n" . $footer;
        }
    }

    /* 2) Template kustom dari owner */
    $template = (string)getSetting('wa_template_' . $status, '');
    if ($template !== '') {
        $msg = strtr($template, [
            '{nama}'   => $data['name'],
            '{order}'  => $data['order_number'],
            '{link}'   => $data['link'],
            '{status}' => $data['status_label'],
            '{alamat}' => $data['alamat'],
            '{app}'    => $data['app'],
            '{items}'  => implode(', ', (array)$data['items']),
            '{total}'  => formatRupiah((float)$data['total']),
        ]);
        // Tambahkan footer otomatis bila template belum memakai {items}/{total}
        if ($status !== 'sapaan' && strpos($template, '{items}') === false && $footer !== '') {
            $msg .= "\n\n" . $footer;
        }
        return $msg;
    }

    /* 3) Bawaan — pesan singkat; detail lengkap ada di footer bawah */
    switch ($status) {
        case 'diterima':
            return "Halo " . $data['name'] . "! 👋\n\n"
                . "Pesanan #" . $data['order_number'] . " telah kami terima ✅\n"
                . "Saat ini sedang dalam proses *" . $data['status_label'] . "*.\n\n"
                . "Terima kasih telah berbelanja di " . $data['app'] . " 🙏"
                . ($footer !== '' ? "\n\n" . $footer : '');
        case 'finishing':
            return "Halo " . $data['name'] . "! 👋\n\n"
                . "Pesanan #" . $data['order_number'] . " lagi kami proses & hampir selesai! 🎉\n\n"
                . "Penasaran sama progressnya? Di link bawah ini kamu bisa lihat cetaknya realtime, "
                . "cek alamat & jam buka toko, bahkan kasih rating buat kami — semuanya di satu nota digital.\n\n"
                . "Terima kasih sudah berbelanja di " . $data['app'] . " 🙏"
                . ($footer !== '' ? "\n\n" . $footer : '');
        case 'siap_ambil':
            return "Halo " . $data['name'] . "! 🎉\n\n"
                . "PESANAN ANDA TELAH SELESAI dan siap diambil ✅\n"
                . "📍 Alamat kami: " . $data['alamat'] . "\n\n"
                . "Terima kasih telah berbelanja di " . $data['app'] . " 🙏"
                . ($footer !== '' ? "\n\n" . $footer : '');
        case 'sapaan':
            // Sapaan untuk customer BARU yang menghubungi toko via WhatsApp.
            // Beberapa variasi kalimat (dipilih acak) agar tidak terkesan kaku/monoton.
            $sapa = $data['name'] !== '' ? "Halo " . $data['name'] . "! 👋" : "Halo! 👋";
            $variants = [
                $sapa . "\n\n"
                    . "Terima kasih sudah menghubungi " . $data['app'] . " 😊\n"
                    . "Ada yang bisa kami bantu? Kami siap melayani kebutuhan cetak Anda — spanduk/banner, sticker, undangan, kartu nama, X Banner, dan desain grafis.\n\n"
                    . "🕐 Jam operasional: Senin–Sabtu, 08.00–17.00 WITA\n"
                    . "📍 Alamat kami: " . $data['alamat'] . "\n\n"
                    . "Silakan tulis pesanan atau pertanyaan Anda di sini ya 🙏",
                $sapa . " 🙏\n\n"
                    . "Terima kasih sudah menghubungi " . $data['app'] . " 😊\n"
                    . "Kami melayani cetak spanduk/banner, sticker, undangan, kartu nama, X Banner, dan desain grafis.\n\n"
                    . "🕐 Kami buka setiap hari Senin–Sabtu pukul 08.00–17.00 WITA\n"
                    . "📍 Alamat: " . $data['alamat'] . "\n\n"
                    . "Ada yang bisa kami bantu hari ini? 🙌",
                $sapa . " 😊\n\n"
                    . "Selamat datang di " . $data['app'] . "! Terima kasih sudah menghubungi kami.\n"
                    . "Kami siap membantu kebutuhan cetak & desain Anda. Silakan ceritakan keperluan Anda — ukuran, jumlah, dan file desainnya (jika sudah ada).\n\n"
                    . "📍 Alamat kami: " . $data['alamat'] . "\n"
                    . "🕐 Jam layanan: Senin–Sabtu 08.00–17.00 WITA\n\n"
                    . "Kami balas secepatnya ya 🙏",
            ];
            return $variants[array_rand($variants)];
        default:
            return "Halo " . $data['name'] . ",\n\nStatus pesanan #" . $data['order_number'] . " adalah: " . $data['status_label']
                . ($footer !== '' ? "\n\n" . $footer : '');
    }
}

/**
 * Panggil AI (DeepSeek) untuk membuat pesan sapaan status.
 * @return string Pesan AI, atau '' bila gagal (kredit habis/error) → fallback.
 */
function aiGenerateText(string $status, array $data): string
{
    $apiKey = (string)getSetting('ai_api_key', '');
    if ($apiKey === '') return '';
    $base   = rtrim((string)getSetting('ai_base_url', 'https://api.deepseek.com'), '/');
    $model  = (string)getSetting('ai_model', 'deepseek-chat');

    $label = $data['status_label'] ?: getStatusLabel($status);

    /* Penanda desain dari pelanggan (2026-09-10) — memengaruhi nada pesan:
       - has_design=1 (design_fee=0, "Tanpa Desain") → file desain SUDAH dikasih
         pelanggan. JANGAN bilang "nunggu desain" / "akan dikabari setelah desain
         siap" — cukup konfirmasi tercatat & akan diproses/dicetak.
       - has_design=0 (design_fee>0, "Dengan Jasa Desain") → kami yg buatkan desain,
         wajar bilang akan dikabari setelah desain siap. */
    $hasDesign = (int)($data['has_design'] ?? 0) === 1;
    $diterimaNote = $hasDesign
        ? "Pesanan baru DITERIMA dan FILE DESAINNYA SUDAH DIKASIH PELANGGAN (tidak perlu dibuatkan desain). "
            . "JANGAN menyebut 'akan kami desainkan', 'nunggu desain', 'akan dikabari setelah desain siap', atau hal sejenis. "
            . "Cukup: terima kasih + konfirmasi pesanan tercatat & siap diproses (pakai kata alami seperti "
            . "'pesanan sudah kami catat', 'sudah masuk ke sistem kami', 'langsung kami proses ya')."
        : "Pesanan baru DITERIMA dan KAMI yang akan membuatkan desainnya (pakai jasa desain). "
            . "Cukup: terima kasih + konfirmasi pesanan tercatat/diproses + akan dikabari setelah desain siap.";

    /* Nada sesuai status */
    $tone = [
        'diterima'   => 'hangat & meyakinkan, seolah menyambut pesanan baru',
        'desain'     => 'semangat & bangga, memperkenalkan fitur pantau desain realtime yang eksklusif',
        'cetak'      => 'semangat & menenangkan, memberitahu pesanan sedang dicetak',
        'finishing'  => 'semangat & menenangkan, memberitahu pesanan sedang dicetak',
        'siap_ambil' => 'gembira & jelas, memberitahu pesanan siap diambil',
        'sapaan'     => 'ramah & hangat, menyambut pelanggan baru yang baru menghubungi toko',
    ][$status] ?? 'ramah';

    /* Aturan fokus isi per status — cegah kalimat di luar konteks */
    $focus = [
        'diterima'   => $diterimaNote,
        'desain'     => "Pesanan SEDANG dalam proses desain. JANGAN menyebut sudah selesai / siap diambil / sedang dicetak. "
            . "SINGKAT: kabari bahwa desain sedang dikerjakan + ajak pelanggan PANTau PROGRES desainnya REALTIME lewat link "
            . "(pakai frasa penegasan dari daftar 'realtimePhrases', jangan 'fitur eksklusif'). "
            . "Contoh 1 kalimat: 'Desainmu lagi kami garap — pantau progresnya realtime di link, cuma ada di sini'. Jangan sebut harga.",
        'cetak'      => "Pesanan sedang dalam proses pencetakan. JANGAN menyebut sudah selesai/siap diambil. "
            . "Sampaikan bahwa pelanggan bisa memantau progress cetaknya realtime lewat link.",
        'finishing'  => "Pesanan sedang dalam proses pencetakan/finishing. JANGAN menyebut pesanan sudah selesai "
            . "atau siap diambil. TEKANKAN bahwa pelanggan bisa memantau progress cetaknya secara realtime lewat link "
            . "(mis. 'bisa pantau progressnya realtime di link ini ya', 'silakan cek perkembangannya lewat link di bawah').",
        'siap_ambil' => "Pesanan SUDAH SELESAI dan siap diambil di toko. Sampaikan dengan jelas dan sertakan alamat toko.",
        'sapaan'     => "Ini sapaan awal untuk pelanggan baru. Tawarkan bantuan; jangan bahas pesanan atau nomor spesifik.",
    ][$status] ?? '';

    $itemsTxt = $data['items'] ? implode(', ', (array)$data['items']) : '';

    /* Variasi gaya — dipilih ACAK di tiap pesan supaya tidak monoton.
       Setiap gaya punya instruksi spesifik; PHP yang memaksa rotasi, bukan
       hanya berharap AI bervariasi sendiri. */
    $stylePool = [
        'lugas'    => "GAYA: LUGAS & SINGKAT. Langsung ke inti, tanpa basa-basi berlebihan. Kalimat pendek-pendek. "
            . "Contoh pembuka: 'Halo [NAMA], pesananmu sudah kami catat.'",
        'hangat'   => "GAYA: HANGAT & RAMAH. Terasa menyambut, gunakan kata yang bersahabat tapi wajar. "
            . "Contoh pembuka: 'Salam hangat, [NAMA] 🙌'.",
        'natural'  => "GAYA: NATURAL & WAJAR. Ngobrol biasa seperti admin toko yang ramah, tidak berlebihan, "
            . "tanpa metafora, kiasan, atau candaan. Contoh: '[NAMA], pesanannya sudah kami catat, nanti kami kabari ya.'",
    ];
    // Semua gaya WAJIB sopan & wajar — TIDAK ada gaya bercanda/lebay untuk siapa pun.
    // Nama FORMAL tetap pakai bahasa hormat.
    $formalName = (bool)preg_match('/\b(pak|bu|bapak|ibu|om|ci|ko|koh|ka|kak|kaka|kk|tante|tanta|abang|bang|pdt|pendeta|ustadz|ustad|dokter|dr|haji|hj)\b/i', (string)$data['name']);
    // Nama yang SUDAH diawali gelar/sapaan (mis. "OM ADI BJG" → gelar "Om" sudah melekat di nama).
    // → AI dilarang menambah sapaan lain di depannya, agar tidak dobel sapaan ("Pak Om Adi").
    // FIX 2026-09-10: sertakan sapaan lokal (ci/ko/ka/kaka) — sebelumnya "Ci Angel"
    // dianggap belum bersapaan sehingga AI menambah "Kak" → "Kak Ci Angel" (dobel).
    $nameHasTitle = wa_name_has_title((string)$data['name']);
    if ($formalName) {
        $styleRuleFormal = "\nCATATAN: Pelanggan ini disapa FORMAL (Pak/Bu/dll). Gunakan bahasa yang HORMAT & sopan, "
            . "jangan bercanda dan jangan terlalu santai. Sapaan pakai gelar yang sesuai (Pak/Bu/Ibu).\n";
        if ($nameHasTitle) {
            $styleRuleFormal .= "PENTING: Nama pelanggan SUDAH mengandung gelar/sapaan sendiri di depannya "
                . "(mis. \"Om Adi\", \"Pak Budi\", \"Bu Sari\"). JANGAN menambah gelar/sapaan LAIN lagi di depan nama — "
                . "gunakan nama persis seperti tertulis. Contoh BENAR: \"Halo Om Adi\" / \"Om Adi, ...\". "
                . "Contoh SALAH (dobel sapaan): \"Pak Om Adi\", \"Halo Pak Om Adi\", \"Bapak Om\", \"Bu Ibu\".\n";
        }
    } else {
        $styleRuleFormal = '';
    }
    $styleKey = array_rand($stylePool);
    $styleRule = "GAYA PESAN (WAJIB ikuti, ini penentu nada keseluruhan):\n" . $stylePool[$styleKey] . "\n"
        . $styleRuleFormal;

    /* FIX 2026-09-10: aturan cara memanggil nama.
       Kalau nama SUDAH mengandung sapaan (Ci/Ko/Ka/Om/Pak/…) → pakai nama APA ADANYA,
       JANGAN tambah "Kak" lagi. Mencegah "Kak Ci Angel" yang dikeluhkan pelanggan. */
    $sapaanRule = $nameHasTitle
        ? "CARA MEMANGGIL PELANGGAN (WAJIB): nama pelanggan sudah mengandung sapaan sendiri "
            . "(\"{$data['name']}\"). Pakai nama itu APA ADANYA, dan JANGAN menambahkan \"Kak\"/\"Kaka\"/"
            . "\"Bapak\"/\"Bu\"/sapaan lain di depannya. Contoh BENAR: \"Halo {$data['name']}\", "
            . "\"{$data['name']}, ...\". Contoh SALAH (dobel sapaan, DILARANG): "
            . "\"Halo Kak {$data['name']}\", \"Kak {$data['name']}\".\n"
        : "CARA MEMANGGIL PELANGGAN: pakai nama pelanggan apa adanya (mis. \"Halo Kak {$data['name']}\" "
            . "boleh, atau langsung \"{$data['name']}, ...\").\n";

    /* Frasa penegasan fitur pantau realtime — PHP pilih SATU secara acak &
       sisipkan spesifik, supaya rotasi merata & enak (hindari "fitur eksklusif"
       yang kaku & hindari AI selalu pilih frasa yang sama). */
    $realtimePhrases = [
        "fitur andalan kami",
        "cuma ada di sini",
        "khas Creative Geek",
        "fitur keren yang jarang ada di tempat lain",
        "biar tenang sambil menunggu",
        "fitur unggulan kami",
        "bisa langsung dipantau sendiri",
    ];
    $realtimePhrase = $realtimePhrases[array_rand($realtimePhrases)];
    $realtimeRule = "PENEGASAN FITUR: bila menyebut 'pantau progres realtime', WAJIB memakai frasa persis ini: "
        . "\"{$realtimePhrase}\" — rangkai natural dalam kalimat (jangan memakai 'fitur eksklusif'). "
        . "Contoh: 'pantau progresnya realtime di link, {$realtimePhrase}'.\n";

    /* Kalimat yang dilarang owner — AI tidak boleh memakainya */
    $forbiddenList = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)getSetting('ai_forbidden', ''))), function ($v) {
        return $v !== '';
    }));
    $forbiddenRule = $forbiddenList
        ? "KATA/Frasa YANG DILARANG KERAS — JANGAN PERNAH menuliskan kata atau frasa berikut dalam pesan, "
            . "dalam bentuk apa pun (termasuk akhiran partikel seperti 'lho', 'dong', 'kok', 'deh', 'sih', 'nih', 'tuh'):\n"
            . implode("\n", array_map(function ($f) { return '• ' . $f; }, $forbiddenList)) . "\n"
            . "Jika ragu apakah sebuah kata termasuk larangan, JANGAN gunakan — ganti dengan kata yang lebih formal.\n"
        : '';

    /* Panduan pilihan kata (owner, setting ai_wording) — jika kosong memakai bawaan */
    $wordingSetting = trim((string)getSetting('ai_wording', ''));
    $wordingRule = $wordingSetting !== ''
        ? "PILIHAN KATA (ikuti ketat):\n" . $wordingSetting . "\n"
        : "PILIHAN KATA (patuhi): JANGAN pernah memakai frasa 'terima dengan baik', 'kami terima dengan baik', "
            . "atau 'dengan baik' setelah kata terima — terdengar kaku dan kurang natural. Untuk konfirmasi pesanan pakai "
            . "kata alami & netral seperti 'pesanan sudah kami catat', 'sudah masuk ke sistem kami', 'kita proses ya', "
            . "atau 'kami siap kerjakan'. ";

    /* Batasan lingkup bisnis (owner) — agar AI tidak menjawab random/global */
    $scope = (string)getSetting('ai_scope', '');
    if ($scope !== '') {
        $scopeRule = "BATASAN LINGKUP BISNIS (ikuti dengan ketat):\n" . $scope;
    } else {
        $scopeRule = "BATASAN LINGKUP (bawaan): Kamu HANYA melayani topik seputar bisnis percetakan ini — produk cetak "
            . "(spanduk, banner, sticker, undangan, kartu nama, merchandise), harga, status pesanan, dan layanan toko. "
            . "JANGAN menjawab pertanyaan di luar itu; tolak dengan sopan dan arahkan kembali ke layanan toko.";
    }

    $system = "Kamu adalah admin WhatsApp toko percetakan \"" . $data['app']
        . "\". Tugasmu membuat pesan WhatsApp untuk pelanggan. Gunakan bahasa Indonesia yang hangat, alami, "
        . "dan sopan. ATURAN PALING PENTING: pesan HARUS SANGAT PENDEK — maksimal 2 kalimat, total sekitar 15-20 kata. "
        . "JANGAN lebih dari itu, JANGAN bertele-tele, JANGAN menambahkan kalimat harapan/penutup berlebihan. "
        . "Jangan pakai markdown berlebihan (cukup tanda * untuk penekanan ringan). "
        . "Sebutkan nama produk yang dipesan pelanggan secara natural agar pesan terasa personal. "
        . "JANGAN menuliskan daftar rincian pesanan (nomor, isi/ukuran, total) atau link secara terpisah — sampaikan saja "
        . "secara natural dalam kalimat; detail lengkap akan otomatis ditampilkan di bawah pesanmu. "
        . "Jangan pula menyebut-nyebut 'link e-receipt' / 'cek detail di link' / 'link di bawah' — biarkan footer otomatis "
        . "yang menampilkannya, fokus pesanmu hanya ucapan + konfirmasi. "
        . "PENTING: JANGAN PERNAH menyebutkan nominal harga/total (Rp, angka uang, 'total', 'biaya', 'harga') dalam pesan ini. "
        . "Pelanggan diminta melihat detail & harga lewat LINK e-receipt yang tercantum di bawah pesanmu. "
        . "VARIASI GAYA: jangan monoton — setiap pesan harus beda gaya dari yang biasanya, tapi tetap wajar. "
        . "JANGAN BERCANDA & JANGAN LEEBAY: dilarang keras bercanda, metafora, kiasan, kata berbunga-bunga, "
        . "hiperbola, atau kalimat dramatis ('semangatnya nular', 'pesanannya aman ya', 'menari di atas kanvas', "
        . "'lagi kami jagain', 'mantap', 'seru nih'). Tulis wajar, lugas & manusiawi seperti admin toko yang ramah. "
        . "Variasikan kalimat pembukanya secara wajar — jangan selalu 'Halo X, terima kasih sudah mempercayakan' — "
        . ($nameHasTitle
            ? "mis. 'Halo {$data['name']}', 'Salam hangat, {$data['name']} 🙌'. "
            : "mis. 'Halo Kak X', 'Kak X 🙌', 'Salam hangat, Kak X'. ")
        . $styleRule
        . $sapaanRule
        . $realtimeRule
        . $wordingRule
        . ($focus !== '' ? "ATURAN FOKUS STATUS (patuhi dengan ketat):\n" . $focus . "\n" : '')
        . ($forbiddenRule !== '' ? $forbiddenRule : '')
        . "Langsung tulis isi pesan saja, tanpa awalan/keterangan.\n\n"
        . $scopeRule;

    if ($status === 'sapaan') {
        $prompt = "Buat pesan sapaan dengan nada {$tone} untuk pelanggan baru bernama {$data['name']} "
            . "yang baru saja menghubungi toko via WhatsApp.\n"
            . "Sampaikan terima kasih, perkenalkan toko {$data['app']}, dan tawarkan bantuan untuk kebutuhan cetak "
            . "(spanduk, sticker, undangan, dll). Jangan sebut nomor pesanan atau link karena ini sapaan awal.";
    } else {
        $prompt = "Buat pesan dengan nada {$tone} untuk pelanggan bernama {$data['name']}.\n"
            . "Status: {$label}\n"
            . "No. pesanan: {$data['order_number']}\n"
            . ($hasDesign ? "KONTEKS PENTING: File desain pesanan ini SUDAH dikasih pelanggan (tidak perlu dibuatkan desain). "
                . "JANGAN bilang 'nunggu desain' / 'akan dikabari setelah desain siap' / 'kami desainkan'. "
                . "Cukup konfirmasi pesanan tercatat & siap diproses.\n"
                : "KONTEKS: Desain pesanan ini KAMI yang buatkan (pelanggan pakai jasa desain). Wajar bilang akan dikabari setelah desain siap.\n")
            . ($itemsTxt !== '' ? "Nama produk yang dipesan: {$itemsTxt}\n" : '')
            . "Link e-receipt: {$data['link']}\n"
            // SERTAKAN alamat toko ASLI supaya AI tidak mengarang (halusinasi).
            . (($data['alamat'] ?? '') !== '' ? "ALAMAT TOKO (wajib pakai persis, JANGAN mengarang alamat lain): {$data['alamat']}\n" : '')
            . "Gunakan NAMA PRODUK di atas sebagai bahan untuk membuat kalimat yang personal dan natural — "
            . "sebutkan produk yang dipesan pelanggan di dalam pesan (contoh: 'pesanan spanduk syukuran wisudanya'). "
            . "INGAT: TULIS SANGAT SINGKAT — maksimal 2 kalimat, total sekitar 15-20 kata. "
            . "PENTING SOAL UKURAN: Jika kamu menyebut ukuran produk, WAJIB menyalin PERSIS ukuran yang ada di "
            . "nama produk (mis. nama produk 'Spanduk Sambut Baru 2x1.5' → tulis '2x1.5' atau '2 x 1,5 meter', "
            . "JANGAN pernah menyingkat/membulatkan menjadi '2x1' — ukuran harus sama persis dengan yang dipesan). "
            . "Bila tidak yakin atau tidak perlu menyebut ukuran, lebih baik JANGAN menyebutkan ukuran sama sekali. "
            . "Jangan menuliskan daftar rincian terpisah. "
            . "JANGAN sebutkan harga/nominal sama sekali — detail harga ada di link e-receipt yang pelanggan buka.";
    }

    // Pengingat anti dobel sapaan di prompt USER (di samping system prompt), agar AI benar-benar
    // tidak menambah gelar/sapaan saat nama pelanggan SUDAH diawali gelar ("OM ADI BJG" → jangan "Pak Om Adi").
    if (!empty($nameHasTitle)) {
        $prompt .= "\n\nCATATAN PENTING: Nama pelanggan \"{$data['name']}\" SUDAH memuat gelar/sapaan di depannya "
            . "(Pak/Bu/Om/dll). Tulis nama PERSIS seperti itu TANPA menambah gelar/sapaan lain. "
            . "Contoh BENAR: \"Halo {$data['name']}\", \"{$data['name']}, ...\". "
            . "Contoh SALAH: \"Pak {$data['name']}\", \"Halo Pak {$data['name']}\", menambah 'Pak'/'Bapak'/'Bu' di depan.";
    }

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $prompt],
        ],
        'max_tokens'  => 120,
        'temperature' => defined('WA_AI_TEMP_STATUS_MSG') ? WA_AI_TEMP_STATUS_MSG : 1.0,
    ];
    // Matikan thinking mode (default) — lihat wa_ai_apply_thinking().
    $payload = wa_ai_apply_thinking($payload);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base . '/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Timeout 8 dtk (dulu 20 dtk terlalu longgar). Diukur 15 Sep 2026: DeepSeek
    // normal hanya ~0,7-1,3 dtk. Fungsi ini dipanggil SIAP-SIAP dalam alur
    // simpan pesanan, jadi batasnya harus ketat demi respons cepat.
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    /* 402 = kredit habis, 401 = key salah, 429 = rate limit → fallback owner */
    if ($response === false || $code < 200 || $code >= 300) {
        writeLog("AI gagal (http $code) — pakai pesan owner", 'WARN');
        return '';
    }
    $decoded = json_decode((string)$response, true);
    $text = (string)($decoded['choices'][0]['message']['content'] ?? '');
    $text = trim($text);
    if ($text === '') return '';

    /* Lapisan pertahanan terakhir: buang kata/frasa terlarang yang lolos dari AI
       (mis. partikel santai 'nih', 'lho', dll yg sudah terdaftar di ai_forbidden).
       Ini memastikan pesan tetap bersih walau AI kadang bandel. */
    if (!empty($forbiddenList)) {
        foreach ($forbiddenList as $fw) {
            $fw = trim((string)$fw);
            if ($fw === '') continue;
            // Hapus kata sebagai kata utuh (dengan spasi sekeliling), case-insensitive.
            $text = (string)preg_replace('/\s+' . preg_quote($fw, '/') . '(?=\s|$)/iu', '', $text);
        }
        // Bersihkan spasi ganda akibat penghapusan & rapikan.
        $text = trim((string)preg_replace('/\s{2,}/u', ' ', $text));
    }
    return $text;
}

/**
 * Format angka Rupiah (IDR), mis. 1.250.000
 */
function formatRupiah($amount): string
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

/* =====================================================================
 * BACKEND IKLAN (Meta Ads + Google Ads) — 2026-08-26
 * =====================================================================
 * Kumpulan helper utk mengirim data pelanggan ke Meta (Facebook) & Google
 * agar nanti bisa dipakai utk iklan: retargeting, lookalike audience, dan
 * laporan konversi. Semua fungsi AMAN jika belum dikonfigurasi (langsung
 * no-op), jadi siap dipakai kapan pun tanpa merusak apa pun.
 *
 * KONFIGURASI disimpan di tabel settings (lihat public/ads_settings.php):
 *   ads_meta_pixel_id     Pixel ID Meta (mis. 123456789012345)
 *   ads_meta_token        Access Token Meta (Graph API / CAPI)
 *   ads_google_ga4        Measurement ID GA4 (mis. G-XXXXXXX)
 *   ads_google_conversion Conversion ID Google Ads (mis. AW-123456789)
 *   ads_google_aw_conv_label  Label konversi Google (mis. ABCdef_12X)
 *   ads_tiktok_pixel_id   Pixel ID TikTok (mis. ABCDEFGHIJKLMN12)
 *   ads_consent_enabled   '1' = tampilkan banner persetujuan (UU PDP)
 *   ads_tracking_enabled  '1' = aktifkan kirim event (default '1' saat terisi)
 *
 * PENTING (UU PDP): Jangan mengirim data identitas (nomor HP/nama) ke Meta,
 * Google, atau TikTok sebelum ada persetujuan pengunjung. Oleh karena itu:
 *  - Nomor HP/nama selalu di-HASH (SHA-256) sebelum dikirim.
 *  - Event CAPI hanya dikirim bila ads_tracking_enabled='1' (diaktifkan
 *    setelah pengunjung setujui banner consent).
 * ===================================================================== */

/** Ambil konfigurasi iklan terpusat dari settings. */
function ads_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [
        'meta_pixel_id'    => (string)getSetting('ads_meta_pixel_id', ''),
        'meta_token'       => (string)getSetting('ads_meta_token', ''),
        'google_ga4'       => (string)getSetting('ads_google_ga4', ''),
        'google_conversion'=> (string)getSetting('ads_google_conversion', ''),
        'google_label'     => (string)getSetting('ads_google_aw_conv_label', ''),
        'tiktok_pixel_id'  => (string)getSetting('ads_tiktok_pixel_id', ''),
        'consent_enabled'  => (string)getSetting('ads_consent_enabled', '1'),
        'tracking_enabled' => (string)getSetting('ads_tracking_enabled', '1'),
    ];
    return $cfg;
}

/**
 * Konfigurasi LANDING PAGE IKLAN (public/iklan.php) — diatur Owner dari
 * panel "Landing Iklan" (public/landing_settings.php), tersimpan sebagai
 * satu JSON di setting `landing_config`. Semua konten landing bisa diubah
 * tanpa edit kode. Fungsi ini mengembalikan array lengkap dengan default.
 *
 * @return array{
 *   eyebrow:string, hero_title_a:string, hero_title_b:string, hero_sub:string,
 *   cta_text:string, cta_sub:string, trust:array{string,string},
 *   products:array<array{ic:string,name:string,desc:string}>,
 *   stats:array<array{num:string,lbl:string}>,
 *   google_review_url:string, google_rating:string,
 *   wa_message:string, show_rating:bool
 * }
 */
function landing_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $default = [
        'eyebrow'          => 'Percetakan Kefamenanu',
        'hero_title_a'     => 'Cetak Cepat,',
        'hero_title_b'     => 'Hasil Rapi',
        'hero_sub'         => 'Spanduk • Undangan • Stiker & Decal • Stempel • Sablon — langsung jadi, kualitas terjaga.',
        'brand_tagline'    => 'Great Place To Do Cool Things',
        'top_rating'       => '5,0 dari pelanggan',
        'show_top_rating'  => true,
        'font_size'        => 'normal',   // kecil | normal | besar
        'spacing'          => 'normal',   // rapat | normal | lega
        'cta_text'         => 'Order via WhatsApp',
        'cta_sub'          => 'Balasan cepat, bisa tanya dulu',
        'cta2_text'        => 'Chat Sekarang',
        'cta2_sub'         => 'Gratis konsultasi kebutuhan cetak',
        'hours_text'       => 'Senin–Sabtu, 08.00–17.00 WITA',
        'footer_note'      => 'Percetakan & Desain',
        'trust'            => ['Tanpa DP birokrasi', 'Proses cepat'],
        'products'         => [
            ['ic' => 'fa-solid fa-flag',               'name' => 'Spanduk & Banner',       'desc' => 'Ukuran bebas, warna tajam — untuk acara & usaha.'],
            ['ic' => 'fa-solid fa-envelope-open-text', 'name' => 'Undangan',               'desc' => 'Pernikahan & acara, desain rapi & elegan.'],
            ['ic' => 'fa-solid fa-tags',               'name' => 'Stiker, Label & Decal',  'desc' => 'Stiker produk, label, decal — hasil halus & tahan lama.'],
            ['ic' => 'fa-solid fa-id-card',            'name' => 'Kartu Nama',             'desc' => 'Kartu nama profesional untuk usaha & instansi.'],
            ['ic' => 'fa-solid fa-stamp',              'name' => 'Stempel Kilat',          'desc' => 'Stempel toko & instansi, jadi cepat, rapi & awet.'],
            ['ic' => 'fa-solid fa-tshirt',             'name' => 'Sablon',                 'desc' => 'Sablon kaos & merchandise, hasil tajam & nyaman.'],
        ],
        'stats'            => [
            ['num' => '{orders}',  'lbl' => 'Pesanan'],
            ['num' => '{customers}','lbl' => 'Pelanggan'],
            ['num' => '{items}+',  'lbl' => 'Desain Dibuat'],
        ],
        'google_review_url' => 'https://g.page/r/Cb3SSiYG4cJjEAE/review',
        'google_rating'     => '5,0',
        'wa_message'        => 'Halo {app}! Saya lihat iklan Anda. Mau tanya-tanya soal cetak (spanduk, undangan, stiker, kartu nama).',
        'show_rating'       => true,
    ];

    $raw = (string)getSetting('landing_config', '');
    $saved = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($saved)) {
        $cfg = $default;
        return $cfg;
    }
    // Gabungkan: nilai tersimpan menimpa default per-kunci (deep untuk products/stats/trust).
    $merged = array_replace($default, $saved);
    foreach (['trust', 'products', 'stats'] as $arrKey) {
        if (isset($saved[$arrKey]) && is_array($saved[$arrKey])) {
            $merged[$arrKey] = $saved[$arrKey];
        }
    }
    $cfg = $merged;
    return $cfg;
}

/** Hash SHA-256 identitas pelanggan (format Meta: huruf kecil hex). */
function ads_hash(string $value): string
{
    return hash('sha256', mb_strtolower(trim($value)));
}

/**
 * Render Meta Pixel (browser-side) di halaman — OTOMATIS AKTIF hanya bila
 * Pixel ID sudah diisi di Pengaturan Iklan. Kalau belum, return '' (aman).
 *
 * @param string $event  Nama event yg dikirim ('' = hanya inisialisasi pageview,
 *                       'ViewContent' = tracking buka halaman/produk, dll)
 * @param array  $eventData data custom utk event (value, currency, content_ids)
 * @return string  HTML snippet (siap ditaruh di <head> / sebelum </body>)
 */
function ads_render_meta_pixel(string $event = '', array $eventData = []): string
{
    $cfg = ads_config();
    if ($cfg['tracking_enabled'] !== '1' || $cfg['meta_pixel_id'] === '') {
        return ''; // Pixel belum dikonfigurasi / tracking mati
    }
    $pid = htmlspecialchars($cfg['meta_pixel_id'], ENT_QUOTES, 'UTF-8');
    $out = '<!-- Meta Pixel Code -->' . "\n"
        . "<script>\n"
        . "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n"
        . "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;\n"
        . "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;\n"
        . "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,\n"
        . "document,'script','https://connect.facebook.net/en_US/fbevents.js');\n"
        . "fbq('init', '{$pid}');\n"
        . "fbq('track', 'PageView');\n";
    if ($event !== '') {
        $ev = htmlspecialchars($event, ENT_QUOTES, 'UTF-8');
        if ($eventData) {
            $out .= 'fbq(\'track\', \'' . $ev . '\', ' . json_encode($eventData, JSON_UNESCAPED_UNICODE) . ");\n";
        } else {
            $out .= 'fbq(\'track\', \'' . $ev . "');\n";
        }
    }
    $out .= "</script>\n"
        . "<noscript><img height=\"1\" width=\"1\" style=\"display:none\" "
        . "src=\"https://www.facebook.com/tr?id={$pid}&ev=PageView&noscript=1\"/></noscript>\n"
        . "<!-- End Meta Pixel Code -->\n";
    return $out;
}

/**
 * Kirim event ke Meta Conversions API (CAPI).
 * @see https://developers.facebook.com/docs/marketing-api/conversions-api
 * @param string $eventName  Nama event (ViewContent, Purchase, dll)
 * @param array  $userData   Data pengguna: phone, name, email, client_ip, client_user_agent
 * @param array  $eventData  Data event: custom_data (value, currency, content_ids, dll)
 * @return bool
 */
function ads_send_meta_capi(string $eventName, array $userData = [], array $eventData = []): bool
{
    $cfg = ads_config();
    if ($cfg['tracking_enabled'] !== '1' || $cfg['meta_pixel_id'] === '' || $cfg['meta_token'] === '') {
        return false; // belum dikonfigurasi / dinonaktifkan
    }
    $user = [];
    if (!empty($userData['phone'])) {
        // Nomor HP → hash. Format: hilangkan non-digit, pastikan awalan 62 (Meta pakai E.164).
        $phone = preg_replace('/[^0-9]/', '', (string)$userData['phone']);
        if (strlen($phone) > 0 && $phone[0] !== '6') {
            $phone = '62' . ltrim($phone, '0');
        }
        $user['ph'] = ads_hash($phone);
    }
    if (!empty($userData['name'])) {
        $user['fn'] = ads_hash((string)$userData['name']);
    }
    if (!empty($userData['email'])) {
        $user['em'] = ads_hash((string)$userData['email']);
    }
    if (!empty($userData['client_ip'])) $user['client_ip_address'] = (string)$userData['client_ip'];
    if (!empty($userData['client_user_agent'])) $user['client_user_agent'] = (string)$userData['client_user_agent'];

    if (empty($user)) return false; // tidak ada data → tidak kirim

    $url = 'https://graph.facebook.com/v19.0/' . rawurlencode($cfg['meta_pixel_id']) . '/events';
    $payload = [
        'data' => [[
            'event_name' => $eventName,
            'event_time' => time(),
            'event_source_url' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
            'action_source' => 'website',
            'user_data' => $user,
            'custom_data' => $eventData ?: new stdClass(),
        ]],
        'access_token' => $cfg['meta_token'],
        'test_event_code' => '', // kosong = live
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * Kirim event konversi ke Google Ads (Conversion Tracking API).
 * @see https://developers.google.com/google-ads/api/docs/conversions/upload-clicks
 * @param string $eventName   Nama konversi (mis. 'conversion', 'purchase')
 * @param array  $userData    phone, email, name, client_ip, client_user_agent
 * @param array  $eventData   conversion_action, conversion_value, currency
 * @return bool
 */
function ads_send_google_conversion(string $eventName, array $userData = [], array $eventData = []): bool
{
    $cfg = ads_config();
    if ($cfg['tracking_enabled'] !== '1' || $cfg['google_conversion'] === '' || $cfg['google_label'] === '') {
        return false;
    }
    $phone = preg_replace('/[^0-9]/', '', (string)($userData['phone'] ?? ''));
    if (strlen($phone) > 0 && $phone[0] !== '6') {
        $phone = '62' . ltrim($phone, '0');
    }
    $email = trim((string)($userData['email'] ?? ''));
    $name  = trim((string)($userData['name'] ?? ''));

    // Google pakai format hash khusus: lowercase hex, untuk email pakai SHA-256
    // (kombinasi email + SHA256 sesuai spec Enhanced Conversions).
    $hashedEmail = $email !== '' ? ads_hash($email) : '';
    $hashedPhone = $phone !== '' ? ads_hash($phone) : '';

    $payload = [
        'conversion_action' => $cfg['google_conversion'] . '/' . $cfg['google_label'],
        'conversion_date_time' => gmdate('Y-m-d H:i:sP'),
        'conversion_value' => (float)($eventData['conversion_value'] ?? 0),
        'currency_code' => 'IDR',
        'user_identifiers' => [],
    ];
    if ($hashedEmail !== '') $payload['user_identifiers'][] = ['hashed_email' => $hashedEmail];
    if ($hashedPhone !== '') $payload['user_identifiers'][] = ['hashed_phone_number' => $hashedPhone];

    // Endpoint Google Ads Conversions Upload (skema protobuf JSON — via REST).
    $customerId = ''; // opsional; endpoint ini tanpa customer_id
    $url = 'https://googleads.googleapis.com/v16/customers/' . ($customerId !== '' ? $customerId : '0')
        . '/conversions:uploadClickConversions';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['conversions' => [$payload]]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'developer-token: ' . (string)getSetting('ads_google_dev_token', ''),
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * Kirim event ke TikTok Events API (server-side).
 * @see https://business-api.tiktok.com/portal/docs?id=1749262948613121
 * @param string $eventName  Nama event (ViewContent, CompletePayment, dll)
 * @param array  $userData   phone, email, name, client_ip, client_user_agent
 * @param array  $eventData  properties (value, currency, content_id, dll)
 * @return bool
 */
function ads_send_tiktok_api(string $eventName, array $userData = [], array $eventData = []): bool
{
    $cfg = ads_config();
    if ($cfg['tracking_enabled'] !== '1' || $cfg['tiktok_pixel_id'] === '') {
        return false; // belum dikonfigurasi / dinonaktifkan
    }
    // TikTok butuh Access Token utk Events API; Pixel saja cukup utk tracking
    // browser. Bila token kosong → skip (pixel browser yang bekerja).
    $token = (string)getSetting('ads_tiktok_token', '');
    if ($token === '') {
        return false;
    }

    // TikTok pakai format hash SHA-256 lowercase (sama dgn Meta).
    $user = ['sha256_phone_number' => [], 'sha256_email' => [], 'sha256_name' => []];
    $phone = preg_replace('/[^0-9]/', '', (string)($userData['phone'] ?? ''));
    if ($phone !== '' && strlen($phone) > 0 && $phone[0] !== '6') {
        $phone = '62' . ltrim($phone, '0');
    }
    if ($phone !== '') $user['sha256_phone_number'][] = ads_hash($phone);
    if (!empty($userData['email'])) $user['sha256_email'][] = ads_hash((string)$userData['email']);
    if (!empty($userData['name']))  $user['sha256_name'][]  = ads_hash((string)$userData['name']);
    // Bersihkan array kosong supaya tidak error
    foreach ($user as $k => $v) { if (!$v) $user[$k] = null; }

    $properties = [];
    if (isset($eventData['value'])) $properties['value'] = (float)$eventData['value'];
    if (isset($eventData['currency'])) $properties['currency'] = (string)$eventData['currency'];
    if (!empty($eventData['content_id'])) $properties['content_id'] = (string)$eventData['content_id'];
    if (!empty($eventData['content_type'])) $properties['content_type'] = (string)$eventData['content_type'];
    $properties['page'] = [
        'url' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
    ];

    $payload = [
        'event' => $eventName,
        'event_id' => bin2hex(random_bytes(8)),
        'timestamp' => time() * 1000, // milidetik
        'context' => [
            'page' => ['url' => (string)($_SERVER['HTTP_REFERER'] ?? '')],
            'user' => $user,
            'ad' => [
                'callback' => bin2hex(random_bytes(8)),
            ],
        ],
        'properties' => $properties,
    ];

    $url = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Access-Token: ' . $token,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
function ads_head_scripts(): string
{
    $cfg = ads_config();
    $out = '';
    // Meta Pixel
    if ($cfg['tracking_enabled'] === '1' && $cfg['meta_pixel_id'] !== '') {
        $pid = e($cfg['meta_pixel_id']);
        $out .= "\n<!-- Meta Pixel Code -->\n"
            . "<script>\n!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n"
            . "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;\n"
            . "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;\n"
            . "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,\n"
            . "document,'script','https://connect.facebook.net/en_US/fbevents.js');\n"
            . "fbq('init', '" . $pid . "');\n"
            . "fbq('track', 'PageView');\n</script>\n"
            . "<noscript><img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://www.facebook.com/tr?id=" . $pid . "&ev=PageView&noscript=1\"/></noscript>\n"
            . "<!-- End Meta Pixel Code -->\n";
    }
    // Google GA4
    if ($cfg['tracking_enabled'] === '1' && $cfg['google_ga4'] !== '') {
        $ga4 = e($cfg['google_ga4']);
        $out .= "\n<!-- Google tag (gtag.js) -->\n"
            . "<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . $ga4 . "\"></script>\n"
            . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n"
            . "gtag('js',new Date());gtag('config','" . $ga4 . "');</script>\n";
    }
    // Google Ads (Conversion ID AW-...) — tag dasar untuk conversion tracking.
    // Terpisah dari GA4 karena ID-nya berbeda (AW- vs G-).
    if ($cfg['tracking_enabled'] === '1' && $cfg['google_conversion'] !== '') {
        $aw = e($cfg['google_conversion']);
        $out .= "\n<!-- Google tag (gtag.js) - Google Ads -->\n"
            . "<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . $aw . "\"></script>\n"
            . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n"
            . "gtag('js',new Date());gtag('config','" . $aw . "');</script>\n";
    }
    // TikTok Pixel
    if ($cfg['tracking_enabled'] === '1' && $cfg['tiktok_pixel_id'] !== '') {
        $tp = e($cfg['tiktok_pixel_id']);
        $out .= "\n<!-- TikTok Pixel Code -->\n"
            . "<script>\n!function (w, d, t) {\n"
            . "  w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=[\"page\",\"track\",\"identify\",\"instances\",\"debug\",\"on\",\"off\",\"once\",\"ready\",\"alias\",\"group\",\"enableCookie\",\"disableCookie\",\"holdConsent\",\"revokeConsent\",\"grantConsent\"];\n"
            . "  ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};\n"
            . "  for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.defer=!0;\n"
            . "  ttq.load=function(t,e){var n=document.createElement(\"script\");n.type=\"text/javascript\";n.async=!0;\n"
            . "  n.src=\"https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=\"+t+\"&lib=\"+e;var a=document.getElementsByTagName(\"script\")[0];a.parentNode.insertBefore(n,a)};\n"
            . "  ttq.load('" . $tp . "');\n"
            . "  ttq.page();\n"
            . "}(window, document, 'ttq');\n</script>\n"
            . "<!-- End TikTok Pixel Code -->\n";
    }
    return $out;
}

/**
 * Output banner persetujuan (UU PDP) di halaman nota publik.
 * Hanya tampil bila ads_consent_enabled='1' & belum ada cookie consent.
 * @return string HTML (atau '')
 */
function ads_consent_banner(): string
{
    $cfg = ads_config();
    if ($cfg['consent_enabled'] !== '1') return '';
    $hasPixel = $cfg['tracking_enabled'] === '1' && (
        $cfg['meta_pixel_id'] !== '' || $cfg['google_ga4'] !== '' || $cfg['tiktok_pixel_id'] !== ''
    );
    if (!$hasPixel) return ''; // tidak ada tracking → tidak perlu banner

    return '<div id="ads-consent" style="position:fixed;bottom:0;left:0;right:0;z-index:99999;background:#111;color:#eee;padding:14px 18px;font-size:13px;display:none;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;border-top:2px solid #ffd700">'
        . '<div>Kami menggunakan data anonim untuk menampilkan iklan yang lebih relevan. '
        . 'Dengan melanjutkan, Anda menyetujui pemrosesan data ini (UU PDP). '
        . '<a href="#" onclick="event.preventDefault();return false;" style="color:#ffd700;text-decoration:underline">Pelajari</a></div>'
        . '<div style="display:flex;gap:8px">'
        . '<button onclick="adsConsent(false)" style="background:transparent;border:1px solid #888;color:#eee;padding:7px 14px;border-radius:6px;cursor:pointer;font-size:12px">Tolak</button>'
        . '<button onclick="adsConsent(true)" style="background:#ffd700;border:none;color:#111;padding:7px 14px;border-radius:6px;cursor:pointer;font-weight:700;font-size:12px">Setujui</button>'
        . '</div></div>'
        . '<script>'
        . 'function adsConsent(ok){document.cookie="ads_consent="+(ok?"1":"0")+";path=/;max-age="+60*60*24*365;'
        . 'var b=document.getElementById("ads-consent");if(b)b.style.display="none";'
        . 'if(ok){try{fbq&&fbq("consent","grant");}catch(e){};try{gtag("consent","update",{ad_storage:"granted",analytics_storage:"granted"});}catch(e){};'
        . 'try{if(window.ttq){ttq.holdConsent && ttq.holdConsent(false);ttq.grantConsent && ttq.grantConsent();}}catch(e){}}}'
        . 'if(document.cookie.indexOf("ads_consent=")===-1){document.getElementById("ads-consent").style.display="flex";'
        . 'try{if(window.ttq&&ttq.holdConsent){ttq.holdConsent(true);}}catch(e){};}'
        . 'else if(document.cookie.indexOf("ads_consent=1")!==-1){try{fbq&&fbq("consent","grant");}catch(e){};try{if(window.ttq){ttq.holdConsent && ttq.holdConsent(false);}}catch(e){}}'
        . '</script>';
}

/**
 * Kirim pesan WhatsApp ke chatId mentah (mis. "62812xxxx@c.us" / LID).
 * Dipakai webhook & balasan otomatis — chatId TIDAK dinormalisasi.
 */
function sendWhatsAppToChat(string $chatId, string $message): bool
{
    $cfg = whatsapp_config();
    if (empty($cfg['api_url']) || empty($cfg['api_key']) || empty($cfg['session_id'])) {
        writeLog('WhatsApp tidak terkonfigurasi (cek config/services.php / .env)', 'ERROR');
        return false;
    }
    // ===== NATURAL: typing indicator sebelum bot kirim (anti-bot/spam feel) =====
    // FIX 2026-09-05: tiru perilaku manusia — tunjukkan "sedang mengetik..." dulu
    // sebentar sebelum pesan terkirim. Hanya utk chat individual (bukan grup/bulk).
    // Pakai endpoint OpenWA POST /chats/typing {state:'typing'}, lalu 'paused'.
    $isGroup = strpos($chatId, '@g.us') !== false || strpos($chatId, '@newsletter') !== false;
    $enableTyping = (getSetting('wa_typing_indicator', '1') === '1') && !$isGroup;
    if ($enableTyping) {
        try {
            callOpenWA(
                '/api/sessions/' . rawurlencode($cfg['session_id']) . '/chats/typing',
                'POST',
                ['chatId' => $chatId, 'state' => 'typing']
            );
            // Jeda singkat proporsional: ~1 dtk + 0.5 dtk per 100 karakter (maks 4 dtk)
            // supaya terasa seperti orang mengetik, bukan bot instant.
            $delayMs = (int)min(4000, 800 + mb_strlen($message) * 5);
            usleep($delayMs * 1000);
            callOpenWA(
                '/api/sessions/' . rawurlencode($cfg['session_id']) . '/chats/typing',
                'POST',
                ['chatId' => $chatId, 'state' => 'paused']
            );
        } catch (Throwable $e) {
            // typing indicator gagal → jangan halangi kirim pesan
        }
    }
    $result = callOpenWA(
        '/api/sessions/' . rawurlencode($cfg['session_id']) . '/messages/send-text',
        'POST',
        ['chatId' => $chatId, 'text' => $message]
    );
    if ($result['code'] >= 200 && $result['code'] < 300) {
        return true;
    }
    writeLog("WA kirim ke chat gagal: chatId=$chatId http={$result['code']} resp=" . substr($result['raw'], 0, 300), 'ERROR');
    return false;
}

/** Kirim GAMBAR ke chat via OpenWA (base64). */
/**
 * Buat versi kompres dari gambar (resize + kualitas rendah) untuk dikirim ke WA.
 * Tujuan: customer menerima preview kecil yang TIDAK layak cetak ukuran besar,
 * sedangkan file original full-res tetap tersimpan di server (hanya CG yang cetak).
 * @param string $srcPath  path file asli
 * @param int    $maxWidth lebar maks (px) — 1200 cukup utk preview layar
 * @param int    $quality  kualitas JPEG (0-100) — rendah utk anti-cetak
 * @return string path file temp hasil kompres, atau '' bila gagal
 */
function compressImageForWa(string $srcPath, int $maxWidth = 1200, int $quality = 70): string
{
    if (!is_file($srcPath)) return '';
    if (!extension_loaded('gd')) return ''; // tanpa GD, kirim asli

    $info = @getimagesize($srcPath);
    if ($info === false) return '';
    [$w, $h, $type] = $info;
    if ($w <= 0 || $h <= 0) return '';

    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($srcPath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($srcPath);  break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($srcPath); break;
        default: return '';
    }
    if (!$src) return '';

    // Resize bila lebih lebar dari maxWidth (pertahankan rasio)
    if ($w > $maxWidth) {
        $nw = $maxWidth;
        $nh = (int)round($h * ($nw / $w));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
        $w = $nw; $h = $nh;
    }

    // Simpan ke file temp (JPEG — ukuran kecil, tidak layak cetak besar)
    $tmp = tempnam(sys_get_temp_dir(), 'wa_img_') . '.jpg';
    imagejpeg($src, $tmp, $quality);
    imagedestroy($src);
    return is_file($tmp) ? $tmp : '';
}

function sendWhatsAppImage(string $chatId, string $imagePath, string $filename = '', bool $compress = true): bool
{
    if (!is_file($imagePath)) {
        writeLog("Kirim gambar gagal: file tidak ada $imagePath", 'ERROR');
        return false;
    }
    $cfg = whatsapp_config();
    if (empty($cfg['api_url']) || empty($cfg['api_key']) || empty($cfg['session_id'])) {
        writeLog('WhatsApp tidak terkonfigurasi (cek config/services.php / .env)', 'ERROR');
        return false;
    }

    // Kompres utk anti-cetak di tempat lain (default aktif utk desain AI).
    $sendPath = $imagePath;
    $tmpPath  = '';
    if ($compress) {
        $tmpPath = compressImageForWa($imagePath);
        if ($tmpPath !== '') {
            $sendPath = $tmpPath;
        }
    }

    $mime = (string)(mime_content_type($sendPath) ?: 'image/jpeg');
    $result = callOpenWA(
        '/api/sessions/' . rawurlencode($cfg['session_id']) . '/messages/send-image',
        'POST',
        [
            'chatId'   => $chatId,
            'base64'   => base64_encode((string)file_get_contents($sendPath)),
            'mimetype' => $mime,
            'filename' => $filename !== '' ? $filename : basename($imagePath),
        ]
    );

    // Bersihkan file temp
    if ($tmpPath !== '') {
        @unlink($tmpPath);
    }

    if ($result['code'] >= 200 && $result['code'] < 300) {
        return true;
    }
    writeLog("WA kirim gambar gagal: chatId=$chatId http={$result['code']} resp=" . substr($result['raw'], 0, 300), 'ERROR');
    return false;
}

/**
 * Cek koneksi database (dipakai dashboard untuk indikator status).
 */
function checkDatabase(): bool
{
    try {
        db()->query("SELECT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Cek apakah domain/host dapat diakses (dipakai dashboard).
 */
function checkDomain(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }
    $ip = gethostbyname($host);
    if ($ip === $host) {
        return false;
    }
    $port = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 443 : 80;
    $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

/**
 * Format tanggal Indonesia, mis. 14 Agu 2026
 */
function formatDate($date, string $format = 'd M Y'): string
{
    if (empty($date)) {
        return '-';
    }
    try {
        $d = new DateTime((string)$date);
        return $d->format($format);
    } catch (Throwable $e) {
        return (string)$date;
    }
}

/* =====================================================================
 * PELANGGAN — KUNCI IDENTITAS & ANTI-DUPLIKAT  (2026-10-09)
 * =====================================================================
 * MASALAH NYATA YANG DITEMUKAN:
 *   Ditemukan 32 grup pelanggan DOBEL (orang sama, 2 record) dari 602
 *   pelanggan. Polanya SELALU sama:
 *     - 1 record punya `chat_id` (dibuat bot WA) → nomor BERSIH 62xxx
 *     - 1 record chat_id KOSONG (diinput admin) → nomor KOTOR "62 858-4722-8867"
 *   31 dari 32 grup punya nomor kotor di salah satu record.
 *
 * AKAR MASALAH — pencarian memakai PERBANDINGAN PERSIS:
 *     WHERE phone = '6285847228867'   ← nomor bersih dari webhook
 *   Tidak cocok dengan yang tersimpan "62 858-4722-8867" (ada spasi &
 *   tanda hubung) → bot menganggap pelanggan BARU → INSERT record kedua.
 *
 * Yang sudah ada sebelumnya hanya menormalkan sisi YANG DICARI, bukan sisi
 * YANG TERSIMPAN. Jadi selama nomor kotor masih bisa masuk ke database,
 * duplikat akan terus lahir.
 *
 * SOLUSI (dua lapis):
 *   1. `customer_cari_by_phone()` — mencari dengan MEMBANDINGKAN digit saja,
 *      sehingga format apa pun (spasi, +, tanda hubung) tetap ketemu.
 *      Dipakai webhook & form supaya tidak ada lagi "pelanggan baru" palsu.
 *   2. `customer_phone_bersih()` — memaksa nomor disimpan SERAGAM (62xxx).
 *      Nomor kotor tidak boleh masuk lagi ke database.
 * ===================================================================== */

/**
 * Nomor telepon → digit saja, seragam 62xxx.
 * Kosong bila tidak ada digit yang cukup (bukan nomor telepon).
 */
function customer_phone_bersih($phone): string
{
    $d = preg_replace('/\D+/', '', (string)$phone) ?? '';
    if ($d === '') return '';
    // 0xxx → 62xxx ; 8xxx → 628xxx ; 62xxx tetap
    if (strpos($d, '62') === 0)      $d = $d;
    elseif (($d[0] ?? '') === '0')   $d = '62' . substr($d, 1);
    elseif (($d[0] ?? '') === '8')   $d = '62' . $d;
    // Nomor Indonesia yang wajar minimal 10 digit (62 + 8 digit)
    return strlen($d) >= 10 ? $d : '';
}

/**
 * Cari pelanggan berdasarkan NOMOR TELEPON — tahan beda format.
 *
 * Membandingkan DIGIT saja, jadi "62 858-4722-8867", "+62 858 4722 8867",
 * "085847228867", dan "6285847228867" semuanya dianggap nomor yang SAMA.
 *
 * @param int $kecualiId Abaikan record ini (dipakai saat EDIT supaya tidak
 *                       menemukan dirinya sendiri).
 * @return array|null Baris pelanggan, atau null bila tidak ada.
 */
function customer_cari_by_phone($phone, int $kecualiId = 0): ?array
{
    $target = customer_phone_bersih($phone);
    if ($target === '') return null;

    $sql = "SELECT id, name, phone, email, chat_id, name_locked, is_regular, photo
              FROM customers
             WHERE phone IS NOT NULL AND phone <> ''"
        . ($kecualiId > 0 ? " AND id <> " . (int)$kecualiId : '');

    foreach (db()->query($sql)->fetchAll() as $r) {
        if (customer_phone_bersih($r['phone']) === $target) return $r;
    }
    return null;
}

/**
 * Cari pelanggan berdasarkan chat_id (persis) atau nomor telepon (tahan format).
 * Urutan: chat_id dulu (identitas paling pasti), lalu nomor.
 */
function customer_cari(string $chatId = '', string $phone = '', int $kecualiId = 0): ?array
{
    $chatId = trim($chatId);
    if ($chatId !== '') {
        $st = db()->prepare("SELECT id, name, phone, email, chat_id, name_locked, is_regular, photo
                               FROM customers WHERE chat_id = ? LIMIT 1");
        $st->execute([$chatId]);
        $r = $st->fetch();
        if ($r) return $r;
    }
    return customer_cari_by_phone($phone, $kecualiId);
}

/**
 * Daftar kelompok pelanggan DUPLIKAT (orang sama, lebih dari 1 record).
 *
 * Dipakai halaman Pelanggan untuk MEMPERINGATKAN admin — supaya admin tahu
 * datanya sudah ada dan tidak dibuat baru. Penggabungan tetap tindakan
 * sengaja oleh owner/admin (lihat customers.php), tidak otomatis.
 *
 * @return array [ ['nomor'=>'62xxx','alasan'=>'nomor sama','anggota'=>[ [baris...], ... ]], ... ]
 */
function customer_daftar_duplikat(): array
{
    $rows = db()->query("SELECT id, name, phone, chat_id, created_at, is_regular
                           FROM customers WHERE phone IS NOT NULL AND phone <> ''
                          ORDER BY id")->fetchAll();

    $grup = [];
    foreach ($rows as $r) {
        $n = customer_phone_bersih($r['phone']);
        if ($n === '') continue;
        $grup[$n][] = $r;
    }

    $hasil = [];
    foreach ($grup as $nomor => $anggota) {
        if (count($anggota) < 2) continue;
        $punyaChat = 0;
        $kotor = 0;
        foreach ($anggota as $a) {
            if (trim((string)$a['chat_id']) !== '') $punyaChat++;
            if (preg_match('/[^0-9]/', (string)$a['phone'])) $kotor++;
        }
        $alasan = $kotor > 0
            ? 'Nomor tersimpan beda format (ada spasi/tanda hubung)'
            : 'Nomor sama, tercatat lebih dari sekali';
        $hasil[] = [
            'nomor'   => $nomor,
            'alasan'  => $alasan,
            'anggota' => $anggota,
            'punya_chat_id' => $punyaChat,
        ];
    }
    return $hasil;
}

/* =====================================================================
 * GABUNG PELANGGAN DOBEL  (2026-10-09)
 * =====================================================================
 * Permintaan pemilik: "gabung aja ini datanya ribet"
 *
 * Sebab dobel: admin menyimpan nomor apa adanya dari WhatsApp
 * ("62 858-4722-8867"), sedangkan bot WA mencari dengan nomor bersih
 * ("6285847228867") → tidak cocok → record kedua lahir. Ditemukan 32
 * baris berlebih dari 602 pelanggan.
 *
 * ATURAN PENGGABUNGAN:
 *   INDUK = record yang PUNYA `chat_id` — itu identitas paling pasti.
 *           Sudah diperiksa: dari 31 grup, SEMUA punya tepat 1 chat_id,
 *           jadi tidak ada chat_id yang hilang saat penggabungan.
 *
 *   NAMA  = **TIDAK DIUBAH SAMA SEKALI.** Nama di database berasal dari
 *           profil WhatsApp pelanggan (`pushName`) dan diperbarui OTOMATIS
 *           setiap kali dia mengirim pesan. Jadi nama induk dibiarkan apa
 *           adanya — dialah yang akan terus menerima pembaruan dari WA.
 *           Sistem TIDAK menilai/memilih/mengganti nama orang.
 *
 *   DATA YANG DIBANTU DARI RECORD LAIN (hanya yang BUKAN milik orang):
 *     - email        : diambil bila induk belum punya (berharga utk Google Match)
 *     - photo        : diambil bila induk belum punya
 *     - is_regular   : OR (kalau salah satu pelanggan tetap, hasilnya tetap)
 *     - last_reply_at: hanya untuk perbandingan, tidak diubah
 *     - phone        : diseragamkan 62xxx (digit saja) — nomor kotor dibersihkan
 *
 *   RELASI dipindah ke induk (semua tabel ber-`customer_id`):
 *     orders, chat_history, wa_ai_sessions, wa_order_captures,
 *     customer_intents, ai_designs, link_clicks, wa_design_flows,
 *     wa_ai_examples, reminder_nota_queue, email_campaign
 *     ⚠ `email_campaign.customer_id` punya UNIQUE KEY → baris yang bentrok
 *       dihapus DULU (sudah diperiksa: tidak ada baris milik pelanggan dobel).
 *
 *   NON-INDUK dihapus setelah relasinya pindah.
 *
 * @param bool $terapkan false = uji coba (tidak mengubah apa pun)
 * @return array{ok:bool,digabung:int,dilewati:int,rincian:array,pesan:string}
 */
function customer_gabung_duplikat(bool $terapkan = false): array
{
    $tabelRelasi = ['orders','chat_history','wa_ai_sessions','wa_order_captures',
                    'customer_intents','ai_designs','link_clicks','wa_design_flows',
                    'wa_ai_examples','reminder_nota_queue','email_campaign'];

    $grup    = customer_daftar_duplikat();
    $rincian = [];
    $digabung = 0;
    $dilewati = 0;

    foreach ($grup as $g) {
        $anggota = $g['anggota'];
        if (count($anggota) < 2) { $dilewati++; continue; }

        // ---- Tentukan INDUK: yang punya chat_id, else id terkecil ----
        $induk = null;
        foreach ($anggota as $a) {
            if (trim((string)$a['chat_id']) !== '') { $induk = $a; break; }
        }
        if ($induk === null) {
            usort($anggota, static fn($x, $y) => (int)$x['id'] <=> (int)$y['id']);
            $induk = $anggota[0];
        }
        $idInduk = (int)$induk['id'];

        // ---- Kumpulkan nilai gabungan dari SEMUA anggota ----
        $email = ''; $photo = ''; $regular = 0; $lastReply = null;
        $idSemua = [];
        foreach ($anggota as $a) {
            $idSemua[] = (int)$a['id'];
            if ($email === '' && trim((string)($a['email'] ?? '')) !== '') $email = trim((string)$a['email']);
            if ($photo === '' && trim((string)($a['photo'] ?? '')) !== '') $photo = trim((string)$a['photo']);
            if ((int)($a['is_regular'] ?? 0) === 1) $regular = 1;
            $lr = (string)($a['last_reply_at'] ?? '');
            if ($lr !== '' && $lr !== '0000-00-00 00:00:00' && ($lastReply === null || $lr > $lastReply)) $lastReply = $lr;
        }

        /* -------------------------------------------------------------
         * NAMA **TIDAK DIUBAH** — nama milik orangnya, bukan milik sistem.
         * -------------------------------------------------------------
         * Pemilik menegaskan (2026-10-09):
         *   "gini nama tuh dari wa loh kan otomatis tiap ngechat dia
         *    langsung masukan ke data pelanggan"
         *   "ga usah di ubah nama orang paham ga"
         *
         * MEMANG BEGITU CARA KERJANYA: setiap pelanggan mengirim pesan,
         * webhook mengambil `pushName` (nama profil WhatsApp-nya sendiri)
         * dan menyimpannya — jadi nama di database SELALU nama yang dipakai
         * orang itu di WhatsApp, dan ikut berubah sendiri bila dia ganti nama.
         *
         * KONSEKUENSINYA untuk penggabungan:
         *   - INDUK (yang punya chat_id) TETAP memakai namanya APA ADANYA,
         *     karena dialah yang akan terus menerima pembaruan dari WA.
         *   - Nama tidak boleh dinilai, dipilih, atau diganti dari record
         *     lain. Sekadar "menilai nama bagus/jelek" sudah keliru — bisa
         *     jadi nama pendek/emoji itu memang nama asli orangnya.
         *   - `name` SENGAJA TIDAK ikut di-UPDATE di bawah.
         *
         * Yang tetap dibawa dari record lain hanyalah data yang BUKAN milik
         * orang: email, foto, dan penanda langganan.
         * ------------------------------------------------------------- */
        $namaFinal = trim((string)$induk['name']);   // dipertahankan apa adanya

        $idNonInduk = array_values(array_filter($idSemua, static fn($x) => $x !== $idInduk));

        $rincian[] = [
            'nomor'      => (string)$g['nomor'],
            'induk'      => $idInduk,
            'induk_nama' => $namaFinal,
            'dihapus'    => $idNonInduk,
            'email'      => $email,
            'relasi'     => [],   // diisi di bawah
        ];
        $idx = count($rincian) - 1;

        if (!$terapkan) { $digabung++; continue; }

        // ================= EKSEKUSI =================
        try {
            db()->beginTransaction();

            foreach ($idNonInduk as $idBuang) {
                // 1. email_campaign UNIQUE → hapus bentrok dulu
                db()->prepare("DELETE FROM email_campaign WHERE customer_id = ?")->execute([$idBuang]);

                // 2. Pindahkan relasi
                foreach ($tabelRelasi as $t) {
                    $st = db()->prepare("UPDATE `$t` SET customer_id = ? WHERE customer_id = ?");
                    $st->execute([$idInduk, $idBuang]);
                    $n = $st->rowCount();
                    if ($n > 0) {
                        $rincian[$idx]['relasi'][$t] = ($rincian[$idx]['relasi'][$t] ?? 0) + $n;
                    }
                }
            }

            /* 3. Rapikan data induk — NAMA SENGAJA TIDAK DISENTUH.
             *    Nama akan diisi sendiri oleh WhatsApp saat pelanggan chat
             *    (webhook menyimpan pushName). Mengubahnya di sini =
             *    menimpa nama asli orang dengan hasil tebakan sistem.
             *    Yang dirapikan hanya data yang BUKAN milik orang:
             *    nomor (seragamkan 62xxx), email & foto (bila induk kosong),
             *    dan penanda pelanggan tetap. */
            $phoneInduk = customer_phone_bersih($induk['phone']);
            db()->prepare(
                "UPDATE customers
                    SET phone      = ?,
                        email      = COALESCE(NULLIF(email, ''), NULLIF(?, '')),
                        photo      = COALESCE(NULLIF(photo, ''), NULLIF(?, '')),
                        is_regular = ?
                  WHERE id = ?"
            )->execute([
                $phoneInduk !== '' ? $phoneInduk : $induk['phone'],
                $email, $photo, $regular, $idInduk,
            ]);

            // 4. Hapus non-induk
            foreach ($idNonInduk as $idBuang) {
                db()->prepare("DELETE FROM customers WHERE id = ?")->execute([$idBuang]);
            }

            db()->commit();
            $digabung++;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $dilewati++;
            $rincian[$idx]['gagal'] = $e->getMessage();
            writeLog("GABUNG PELANGGAN GAGAL (nomor {$g['nomor']}): " . $e->getMessage(), 'ERROR');
        }
    }

    if ($terapkan && $digabung > 0) {
        writeLog("Gabung pelanggan dobel: $digabung kelompok digabung, $dilewati dilewati", 'WARNING');
    }

    return [
        'ok'       => true,
        'terapkan' => $terapkan,
        'digabung' => $digabung,
        'dilewati' => $dilewati,
        'rincian'  => $rincian,
        'pesan'    => ($terapkan ? 'Digabung' : 'Uji coba') . ": $digabung kelompok"
                      . ($dilewati > 0 ? ", $dilewati dilewati" : ''),
    ];
}
