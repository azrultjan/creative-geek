<?php
/**
 * public/api/printer_heartbeat.php
 * =====================================================================
 * Endpoint monitor printer: kirim status printer (heartbeat) ke server.
 * Dipanggil oleh CG_MONITOR / monitor printer (V4) tiap beberapa detik.
 *
 * Request JSON:
 *   { "name": "RYPC UV Printer", "ip": "192.168.11.252",
 *     "current_order": "CG1708261694", "progress": 45,
 *     "remaining": "00:03:09", "print_status": "Print" }
 * Header: X-API-Key: <PRINT_API_KEY>
 * =====================================================================
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

require_print_api_auth();
header('Content-Type: application/json');

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Body harus JSON']);
    exit;
}

// ===== LOG IP ASLI =====
// Access log Docker selalu tampil sbg bridge (172.22.0.1), jadi di sini
// kita catat REMOTE_ADDR + IP LAN yg dikirim agent (payload 'ip') + hostname,
// utk diagnosa "PC tidak online". Throttle tulis ~1x per 5 detik per IP.
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$clientName = trim((string)($input['name'] ?? ''));
$clientLanIp = trim((string)($input['ip'] ?? ''));
$clientHost = trim((string)($input['hostname'] ?? ''));
if ($clientIp !== '') {
    $beatLog = '/var/www/logs/heartbeat_ips.log';
    $beatKey = md5($clientIp . '|' . $clientLanIp . '|' . $clientName);
    $beatNow = time();
    $beatLock = "/var/www/logs/locks/hb_$beatKey.lock";
    if (!is_dir(dirname($beatLock))) { @mkdir(dirname($beatLock), 0777, true); }
    $okW = @fopen($beatLock, 'c');
    if ($okW) {
        $okL = flock($okW, LOCK_EX);
        $lastLog = (int)@file_get_contents($beatLock);
        if ($okL && ($beatNow - $lastLog >= 5)) {
            @file_put_contents($beatLock, (string)$beatNow);
            @file_put_contents($beatLog, date('H:i:s') . " RMT=$clientIp LAN=$clientLanIp host=$clientHost name=$clientName\n", FILE_APPEND | LOCK_EX);
        }
        @flock($okW, LOCK_UN);
        @fclose($okW);
    }
}

$name     = trim((string)($input['name'] ?? ''));
$ip       = trim((string)($input['ip'] ?? ''));
$order    = trim((string)($input['current_order'] ?? ''));
// NORMALISASI order number: selalu ekstrak CGxxxxxxxxxx dari current_order.
// Monitor/agent bisa kirim nama file ("CG1708261694 halaman1.png") — nota &
// e-receipt butuh order number MURNI supaya bisa cocokkan dengan benar.
if ($order !== '' && preg_match('/CG\d{10}/', $order, $mOrder)) {
    $order = $mOrder[0];
}
$progress = isset($input['progress']) ? (int)$input['progress'] : null;
$remaining = trim((string)($input['remaining'] ?? '00:00:00'));
$printStatus = trim((string)($input['print_status'] ?? ''));
$type = trim((string)($input['type'] ?? ''));
if (!in_array($type, ['uv', 'epson', 'canon', 'hp', 'other', 'host', 'inkjet', 'laser', 'server', 'nas', 'router'], true)) {
    $type = 'other';
}

// ===== DEBUG EPSON (sementara — hapus setelah diagnosa) =====
if (strpos($name, 'L15150') !== false) {
    @file_put_contents('/var/www/logs/hb_epson_debug.log',
        date('H:i:s') . " ip={$ip} agent=" . (!empty($input['agent']) ? 'Y' : 'N')
        . " relay=" . (!empty($input['relay']) ? 'Y' : 'N')
        . " order={$order} st={$printStatus} prog=" . var_export($progress, true)
        . " tot=" . ($input['total_pages'] ?? '-') . " prt=" . ($input['printed_pages'] ?? '-')
        . "\n", FILE_APPEND | LOCK_EX);
}

if ($name === '' && $ip === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'name atau ip wajib diisi']);
    exit;
}

// ===== FILTER PRINTER VIRTUAL / NON-FISIK (server-side) =====
// Menolak printer virtual Windows yang tidak punya fisik (AnyDesk, Printer Nota,
// Microsoft Print to PDF, XPS, Fax, dsb) supaya tidak mengotori halaman Printer.
// Berlaku untuk heartbeat printer (bukan host/server).
// CATATAN: 'copy' TIDAK disaring — "EPSON ... (Copy 2)" adalah printer EPSON
// ASLI (EpsonNet port), bukan virtual.
$isHostType = in_array($type, ['host', 'server', 'nas', 'router'], true);
if (!$isHostType && $name !== '') {
    $nameLow = mb_strtolower($name);
    $virtualHints = ['anydesk', 'printer nota', 'microsoft print', 'print to pdf', 'xps', 'fax', 'onenote', 'microsoft xps', 'adobe pdf', 'send to'];
    foreach ($virtualHints as $vh) {
        if (strpos($nameLow, $vh) !== false) {
            http_response_code(200);
            echo json_encode(['success' => true, 'printer_id' => null, 'skipped_virtual' => true]);
            exit;
        }
    }
}

// ===== JARING PENGAMAN: FORCE IDLE SAAT SEMUA ORDER SUDAH SELESAI =====
// Monitor kadang terus mengirim status 'Print' padahal semua order sudah
// siap_ambil (finish_print sudah dikirim) — karena UI mesin masih menampilkan
// sisa cetakan (progress bisa macet di 90-99%, mis. 97%) sebelum reset ke Idle.
// Tanpa ini, printer card tampil 'Print/Stuck' selamanya padahal order selesai.
//
// FIX 2026-08-26: 
//  - Tipe UV (RYPC): progress >= 90 — UI RYPC bisa macet di 97% saat lembar
//    terakhir selesai tapi belum reset ke Idle. Selama SEMUA order sudah
//    siap_ambil/batal → force Idle.
//  - Tipe LAIN (EPSON/inkjet/canon/dll): TETAP >= 100 — progress EPSON dari
//    spooler per-file, TIDAK boleh ditutup di 90% (masih bisa ada job tersisa).
//    Ini memastikan fix RYPC TIDAK mempengaruhi logika EPSON.
$isPrintingBeat = in_array(mb_strtolower($printStatus), ['print', 'printing', 'mencetak', 'cetak', 'running'], true);
$isUVType = ($type === 'uv');
$forceIdleThreshold = $isUVType ? 90 : 100;
if ($isPrintingBeat && $progress !== null && $progress >= $forceIdleThreshold) {
    try {
        // Kumpulkan semua order: current_order + orders_list (dipisah koma)
        $allOrdersCheck = [];
        if ($order !== '') { $allOrdersCheck[] = $order; }
        foreach (preg_split('/[,;]/', (string)($input['orders_list'] ?? '')) as $part) {
            $part = trim($part);
            if ($part !== '') { $allOrdersCheck[] = $part; }
        }
        $allOrdersCheck = array_values(array_unique($allOrdersCheck));

        if ($allOrdersCheck) {
            $ph = [];
            $wheres = [];
            foreach ($allOrdersCheck as $i => $oc) {
                $wheres[] = "order_number = ?";
                $ph[] = $oc;
            }
            $stChk = db()->prepare("SELECT order_number, status FROM orders WHERE " . implode(' OR ', $wheres));
            $stChk->execute($ph);
            $found = 0;
            $allDone = true;
            while ($rChk = $stChk->fetch()) {
                $found++;
                if (!in_array((string)$rChk['status'], ['siap_ambil', 'batal'], true)) {
                    $allDone = false;
                }
            }
            // Semua order yang ada di DB sudah selesai/batal → force Idle
            if ($found > 0 && $allDone) {
                $printStatus = 'Idle';
                $progress = 0;
            }
        }
    } catch (Throwable $e) {
        // abaikan — jaring pengaman tidak boleh memblokir heartbeat
    }
}

try {
    // ===== PENCARIAN & DEDUPE PRINTER =====
    // Urutan: (1) by IP → (2) by name tepat → (3) insert baru.
    // Mencegah duplikat tak terbatas dari PC Agent (banyak PC, printer sama).
    $printerId = 0;
    $isRelayBeat = !empty($input['relay']);
    $matchedViaHostname = false;   // jadi true kalau record host dicocokkan via hostname (agent)

    // RELAY HOST (dari Mac): cocokkan by NAME dulu — karena satu IP bisa punya
    // 2 record beda type (mis. PC Operator .252 [host] + RYPC UV .252 [uv]).
    if ($isRelayBeat && ($type === 'host' || $type === 'server') && $name !== '') {
        $stmt = db()->prepare("SELECT id, type FROM printers WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if ($row) {
            $printerId = (int)$row['id'];
        }
    }

    // AGENT PRINTER: match by NAME DULU (sebelum by IP).
    // PC Agent mengirim ip = IP PC-nya sendiri (mis. .252), BUKAN IP printer
    // jaringan (mis. EPSON .251). Kalau match by IP dulu, agent bisa kena record
    // printer LAIN di IP yang sama (mis. RYPC UV di .252) → salah & di-skip.
    // Jadi untuk heartbeat agent type printer, prioritaskan nama printer.
    $isAgentPrinter = !empty($input['agent']) && in_array($type, ['uv', 'epson', 'canon', 'hp', 'inkjet', 'laser', 'other'], true);
    if ($isAgentPrinter && $name !== '') {
        $stmtN = db()->prepare("SELECT id, type FROM printers WHERE name = ? ORDER BY id ASC LIMIT 1");
        $stmtN->execute([$name]);
        $row = $stmtN->fetch();
        if ($row) {
            $printerId = (int)$row['id'];
        }
    }

    // 1) Cocokkan by IP (unik — perangkat fisik punya IP sendiri).
    // FIX 2026-08-28: utamakan record PRINTER FISIK di IP itu. Sebelumnya
    // ambil record pertama (bisa host/CGwindows) → jenis beda → reset → bikin
    // duplikat printer (mis. "EPSON @ Mac Pro" + "EPSON (Copy 2)" di IP sama).
    if ($printerId === 0 && $ip !== '') {
        $isPhysBeat = in_array($type, ['uv', 'epson', 'canon', 'hp', 'inkjet', 'laser', 'other'], true);
        if ($isPhysBeat) {
            $stmtP = db()->prepare("SELECT id, type FROM printers WHERE ip = ? AND type IN ('uv','epson','canon','hp','inkjet','laser','other') ORDER BY id ASC LIMIT 1");
            $stmtP->execute([$ip]);
            $rowP = $stmtP->fetch();
            if ($rowP) {
                $printerId = (int)$rowP['id'];
                $row = $rowP;
            }
        }
        if ($printerId === 0) {
            $stmt = db()->prepare("SELECT id, type FROM printers WHERE ip = ? ORDER BY id ASC LIMIT 1");
            $stmt->execute([$ip]);
            $row = $stmt->fetch();
            if ($row) {
                $printerId = (int)$row['id'];
            }
        }
    }

    // 2) Cocokkan by name tepat (mis. "EPSON L15150 Series" sudah ada)
    if ($printerId === 0 && $name !== '') {
        $stmt = db()->prepare("SELECT id, type FROM printers WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if ($row) {
            $printerId = (int)$row['id'];
        }
    }

    if ($printerId > 0) {
        // PENTING: jangan biarkan heartbeat menimpa jenis record yang salah.
        // - Record type 'host' hanya di-update oleh heartbeat type 'host'.
        // - Record printer hanya di-update oleh heartbeat printer.
        $existingType = (string)$row['type'];
        $isHostBeat = ($type === 'host' || $type === 'server');
        $isHostRecord = ($existingType === 'host' || $existingType === 'server');
        if ($isHostBeat !== $isHostRecord) {
            // Jenis beda → jangan timpa; buat record baru utk heartbeat ini.
            $printerId = 0;
        }
    }

    if ($printerId === 0) {
        // ===== AGENT HOST: cocokkan by HOSTNAME ke record host =====
        // PC Agent mengirim name = hostname (mis. "DESKTOP-AQF61OD"), sedangkan
        // record host di DB bisa bernama lain (mis. "PC Operator (DESKTOP-AQF61OD)").
        // Match by name gagal & match by IP bisa kena record printer (uv) di IP
        // yang sama → agent tidak pernah update record host → CPU/RAM PC hilang.
        // Diletakkan DI SINI (setelah validasi type reset printerId=0) supaya
        // benar-benar dijalankan saat by-IP kena record yang salah jenis.
        if (!empty($input['agent']) && in_array($type, ['host', 'server', 'nas', 'router'], true) && $clientHost !== '') {
            $stmtH = db()->prepare("SELECT id, type, extra_info FROM printers WHERE type IN ('host','server','nas','router') ORDER BY id ASC");
            $stmtH->execute();
            while ($rowH = $stmtH->fetch()) {
                $extraH = (string)($rowH['extra_info'] ?? '');
                if ($extraH === '') { continue; }
                $jH = json_decode($extraH, true);
                if (!is_array($jH)) { continue; }
                $hostH = strtoupper(trim((string)($jH['hostname'] ?? '')));
                if ($hostH !== '' && $hostH === strtoupper($clientHost)) {
                    $printerId = (int)$rowH['id'];
                    $row = $rowH;
                    $matchedViaHostname = true;
                    break;
                }
            }
        }

        // ===== DETEKSI DUPLIKAT KUAT =====
        // Sebelum membuat record baru, cek apakah sudah ada record untuk
        // (a) IP yang sama, ATAU (b) nama yang sama, ATAU (c) hostname yang sama.
        // Ini mencegah banjir duplikat dari relay Mac / scan berulang.
        $dupStmt = db()->prepare("SELECT id, name, type FROM printers WHERE ip = ? OR name = ? LIMIT 1");
        $dupStmt->execute([$ip, $name]);
        $dup = $dupStmt->fetch();

        // Cek juga hostname (extra_info berisi JSON hostname)
        if (!$dup && $name !== '') {
            $dupStmt2 = db()->prepare("SELECT id, name, type, extra_info FROM printers WHERE name LIKE ? OR extra_info LIKE ? LIMIT 1");
            $dupStmt2->execute(['%' . $name . '%', '%' . $name . '%']);
            $dup = $dupStmt2->fetch();
        }

        if ($dup) {
            $dupId = (int)$dup['id'];
            $dupType = (string)$dup['type'];
            $isHostBeat = ($type === 'host' || $type === 'server' || $type === 'nas' || $type === 'router');
            $isHostRecord = ($dupType === 'host' || $dupType === 'server' || $dupType === 'nas' || $dupType === 'router');
            $isPrinterBeat = in_array($type, ['uv', 'epson', 'canon', 'hp', 'inkjet', 'laser', 'other'], true);
            $isPrinterRecord = in_array($dupType, ['uv', 'epson', 'canon', 'hp', 'inkjet', 'laser', 'other'], true);

            // Jika record lama type 'other' (belum jelas) → ambil alih type baru
            $needsTypeFix = ($dupType === 'other' && $isHostBeat);

            // Jika keduanya host (host/nas/router) atau keduanya printer → reuse record
            if (($isHostBeat && $isHostRecord) || ($isPrinterBeat && $isPrinterRecord)) {
                $printerId = $dupId;
                if ($needsTypeFix) {
                    db()->prepare("UPDATE printers SET type=? WHERE id=?")->execute([$type, $dupId]);
                }
            } elseif ($needsTypeFix) {
                $printerId = $dupId;
                db()->prepare("UPDATE printers SET type=? WHERE id=?")->execute([$type, $dupId]);
            }
            // Jika beda kategori (host vs printer) dengan IP sama → ini memang
            // 2 perangkat beda di IP sama (mis. PC Operator + RYPC UV) → izinkan
            // record baru, TAPI pastikan nama berbeda (hindari duplikat nama).
        }

        // ===== BLOCK AGENT / BLOCK DUPLIKAT IP HOST =====
        // Agent (PC Agent) TIDAK boleh MEMBUAT printer baru (anti-duplikat),
        // TAPI boleh UPDATE printer yang SUDAH dikenal (by name) supaya status/
        // progress printer dari PC Agent tetap tampil di monitor.
        $blocked = !empty($input['agent']) && $printerId === 0;
        // Untuk AGENT printer: cocokkan dengan printer existing yang nama-nya
        // mengandung kata kunci (mis. "EPSON L15150 Series (Copy 2)" → EPSON 226).
        // Pilih record dengan id TERKECIL (record asli/pertama), bukan duplikat.
        if (!empty($input['agent']) && in_array($type, ['uv', 'epson', 'canon', 'hp', 'inkjet', 'laser', 'other'], true) && $name !== '') {
            // Ekstrak kata kunci: buang tanda kurung/sufiks "(Copy N)"
            $nameKey = preg_replace('/\s*\(.*?\)\s*/i', ' ', (string)$name);
            $nameKey = trim(preg_replace('/\s+/', ' ', $nameKey));
            if ($nameKey !== '') {
                $stmtK = db()->prepare("SELECT id, name, type FROM printers WHERE type IN ('uv','epson','canon','hp','inkjet','laser','other') AND (name LIKE ? OR name LIKE ?) ORDER BY id ASC LIMIT 1");
                $stmtK->execute(['%' . $nameKey . '%', '%' . $name . '%']);
                $rowK = $stmtK->fetch();
                if ($rowK) {
                    $printerId = (int)$rowK['id'];
                    $blocked = false;
                }
            }
        }
        if (!$blocked && $printerId === 0 && $ip !== '') {
            $stmt = db()->prepare("SELECT COUNT(*) FROM printers WHERE ip = ? AND type IN ('host','server','nas','router')");
            $stmt->execute([$ip]);
            $hostCount = (int)$stmt->fetchColumn();
            // Jika IP sudah punya record HOST dan heartbeat ini host lagi → jangan dobel
            if ($hostCount > 0 && in_array($type, ['host', 'server', 'nas', 'router'], true)) {
                // Reuse salah satu record host yang ada
                $stmt2 = db()->prepare("SELECT id FROM printers WHERE ip = ? AND type IN ('host','server','nas','router') LIMIT 1");
                $stmt2->execute([$ip]);
                $hid = (int)$stmt2->fetchColumn();
                if ($hid > 0) {
                    $printerId = $hid;
                } else {
                    $blocked = true;
                }
            }
        }

        // FIX 2026-08-26: AGENT kini BOLEH auto-register printer FISIK
        // (uv/epson/canon/hp/inkjet/laser/other) yang belum ada di DB —
        // mis. agent baru di PC lain (Canon LBP6030, EPSON L15150 Copy 2).
        // Anti-banjir: hanya tipe printer fisik (bukan host/server/nas),
        // dan nama harus non-kosong. Match-by-keyword di atas sudah
        // mencegah duplikat kalau nama mirip dgn record existing.
        $isPhysType = in_array($type, ['uv', 'epson', 'canon', 'hp', 'inkjet', 'laser', 'other'], true);
        if ($printerId === 0 && $blocked) {
            // Blocked (agent + tak dikenal): IZINKAN auto-register kalau ini
            // printer fisik (bukan host/server/nas/router).
            if (!empty($input['agent']) && $isPhysType && $name !== '') {
                // FIX 2026-08-28 (anti-duplikat): kalau IP SUDAH punya printer
                // fisik (mis. Epson L15150 sudah ada dari sumber lain), REUSE
                // record itu — jangan bikin duplikat dengan nama beda dari
                // sumber berbeda (Mac vs PC vs nama serial).
                if ($ip !== '') {
                    $ipPrinter = db()->prepare("SELECT id, name FROM printers WHERE ip = ? AND type IN ('uv','epson','canon','hp','inkjet','laser','other') ORDER BY id ASC LIMIT 1");
                    $ipPrinter->execute([$ip]);
                    $ipRow = $ipPrinter->fetch();
                    if ($ipRow) {
                        $printerId = (int)$ipRow['id'];
                        // REUSE record — JANGAN timpa nama existing (record asli
                        // tetap dipakai; nama dari sumber lain tidak menimpa).
                        db()->prepare("UPDATE printers SET status='online' WHERE id=?")
                            ->execute([$printerId]);
                    }
                }
                if ($printerId === 0) {
                    $dupC = db()->prepare("SELECT COUNT(*) FROM printers WHERE LOWER(name) = LOWER(?) AND type IN ('uv','epson','canon','hp','inkjet','laser','other')");
                    $dupC->execute([$name]);
                    if ((int)$dupC->fetchColumn() > 0) {
                        $dupR = db()->prepare("SELECT id FROM printers WHERE LOWER(name) = LOWER(?) AND type IN ('uv','epson','canon','hp','inkjet','laser','other') ORDER BY id ASC LIMIT 1");
                        $dupR->execute([$name]);
                        $printerId = (int)$dupR->fetchColumn();
                    } else {
                        db()->prepare("INSERT INTO printers (name, ip, type, location) VALUES (?, ?, ?, '')")
                            ->execute([$name, $ip !== '' ? $ip : $name, $type]);
                        $printerId = (int)db()->lastInsertId();
                    }
                }
            } else {
                // Bukan printer fisik → skip (jangan bikin sampah)
                $printerId = 0;
            }
        } elseif ($printerId === 0) {
            if (!empty($input['agent']) && !$isPhysType) {
                $printerId = 0; // agent host/non-printer → jangan auto-create
            } else {
                // FIX 2026-08-28 (anti-duplikat): non-agent juga reuse printer
                // fisik dengan IP sama (printer sama terdeteksi dari sumber beda).
                if ($ip !== '' && $isPhysType) {
                    $ipPrinter = db()->prepare("SELECT id FROM printers WHERE ip = ? AND type IN ('uv','epson','canon','hp','inkjet','laser','other') ORDER BY id ASC LIMIT 1");
                    $ipPrinter->execute([$ip]);
                    $ipRow = $ipPrinter->fetch();
                    if ($ipRow) {
                        $printerId = (int)$ipRow['id'];
                    }
                }
                if ($printerId === 0) {
                    db()->prepare("INSERT INTO printers (name, ip, type, location) VALUES (?, ?, ?, '')")
                        ->execute([$name !== '' ? $name : $ip, $ip !== '' ? $ip : $name, $type]);
                    $printerId = (int)db()->lastInsertId();
                }
            }
        }
    }

    if ($printerId === 0) {
        // Tidak bisa daftar (agent, tak dikenal) → respond tanpa update printer
        echo json_encode(['success' => true, 'printer_id' => null, 'skipped_agent' => true]);
        exit;
    }

    // ===== RELAY MODE =====
    // Heartbeat dari Mac host (relay) hanya untuk menjaga printer ONLINE
    // (last_heartbeat), JANGAN menimpa print_status / current_order / progress /
    // extra_info yang dikirim monitor asli (exe di PC). Tanpa ini, relay akan
    // menimpa status "Pause/Print" dari mesin dengan "online".
    $isRelay = !empty($input['relay']);
    // ===== PROGRESS-ONLY MODE =====
    // Heartbeat dari sumber progress sekunder (mis. Windows spooler utk EPSON
    // yang dicetak dari Mac). HANYA update data lembar/progress, JANGAN menimpa
    // current_order / print_status / remaining_time yang dikirim Mac (pencatat
    // job utama). Ini mencegah dua sumber rebutan status.
    $isProgressOnly = !empty($input['progress_only']);

    // ===== FILTER: heartbeat PC AGENT TIDAK boleh "menghidupkan" printer RYPC UV =====
    // RYPC UV (type='uv') online-nya harus ditentukan oleh APP RYPC MONITOR asli
    // (type='uv', TANPA flag agent). PC Agent (agent=true) mengirim heartbeat utk
    // SEMUA printer Windows — termasuk "RYPC UV Printer" — sehingga saat app RYPC
    // ditutup, agent tetap meng-update last_heartbeat → status tetap Online (salah).
    // Solusi: kalau heartbeat berasal dari agent & target record adalah printer
    // type='uv', ABaIKAN (jangan update last_heartbeat) supaya offline terdeteksi.
    $isAgentBeat = !empty($input['agent']);
    if ($isAgentBeat && $printerId > 0) {
        $prRec = db()->prepare("SELECT type FROM printers WHERE id=?");
        $prRec->execute([$printerId]);
        $prType = (string)$prRec->fetchColumn();
        if ($prType === 'uv') {
            // Agent tidak boleh menghidupkan RYPC UV — abaikan heartbeat ini.
            echo json_encode(['success' => true, 'printer_id' => $printerId, 'skipped_agent_uv' => true]);
            exit;
        }
    }

    // Update status printer
    $sql = "UPDATE printers SET last_heartbeat=NOW(), updated_at=NOW()";
    $params = [];
    // FIX 2026-09-04: flag order yg dikirim monitor sudah selesai (siap_ambil) —
    // reset printer & jangan timpa progress/status. Default false.
    $orderSelesaiReset = false;
    $extra = [];   // inisialisasi lebih awal — dipakai blok progress per lembar (pcs)
    // SNAPSHOT state printer SEBELUM update utama (2026-09-10).
    // Dipakai guard monotonic DTF (blok extra & blok final) supaya tidak
    // membaca DB yg sudah ketimpa update $sql di tengah request. Nilai
    // printed_pages/current_order SEBELUM heartbeat ini = "terakhir tercatat".
    $prevOrderSnap = '';
    $prevPrintedSnap = 0;
    $prevProgressSnap = 0;
    $prevExtraArr = [];
    $progress_stalled_at_snap = null;
    try {
        $stSnap = db()->prepare("SELECT current_order, printed_pages, progress, extra_info, progress_stalled_at FROM printers WHERE id=?");
        $stSnap->execute([$printerId]);
        $snapRow = $stSnap->fetch();
        if ($snapRow) {
            $prevOrderSnap = (string)($snapRow['current_order'] ?? '');
            $prevPrintedSnap = (int)($snapRow['printed_pages'] ?? 0);
            $prevProgressSnap = (int)($snapRow['progress'] ?? 0);
            $prevExtraArr = json_decode((string)($snapRow['extra_info'] ?? ''), true) ?: [];
            // Kapan progress terakhir kali NAIK (dipakai utk batas jeda DTF):
            // kalau sudah lama tidak naik, mesin dianggap berhenti beneran.
            $progress_stalled_at_snap = $snapRow['progress_stalled_at'] ?? null;
        }
    } catch (Throwable $e) {
        // abaikan — snapshot tidak fatal
    }
    // JANGAN timpa name kalau record host dicocokkan via hostname (agent) —
    // record sudah punya nama deskriptif (mis. "PC Operator (DESKTOP-AQF61OD)")
    // yang lebih jelas daripada hostname telanjang "DESKTOP-AQF61OD".
    if ($name !== '' && !$matchedViaHostname) { $sql .= ", name=?"; $params[] = $name; }
    if ($ip !== '') { $sql .= ", ip=?"; $params[] = $ip; }
    if (!$isRelay) {
        // ===== PROGRESS-ONLY (sumber progress sekunder utk EPSON) =====
        // Hanya update total_pages/printed_pages/progress — TIDAK sentuh
        // status/order & TIDAK mencatat job (job dicatat Mac).
        if (!empty($input['progress_only'])) {
            $hTotal = isset($input['total_pages']) ? (int)$input['total_pages'] : 0;
            $hPrinted = isset($input['printed_pages']) ? (int)$input['printed_pages'] : 0;
            if ($hTotal > 0 || $hPrinted > 0) {
                $sql .= ", total_pages=?, printed_pages=?";
                $params[] = $hTotal > 0 ? $hTotal : null;
                $params[] = $hPrinted > 0 ? $hPrinted : null;
                if ($hTotal > 0) {
                    $calc = min(100, (int)round($hPrinted * 100 / $hTotal));
                    $sql .= ", progress=?";
                    $params[] = $calc;
                }
            }
        } else {
        // FIX 2026-09-04 (order selesai tp masih dikirim monitor): UI RYPC
        // tidak clear job setelah selesai → monitor terus kirim heartbeat dgn
        // order yg SUDAH siap_ambil (selesai). Kalau dibiarkan, printer card
        // nempel ke order selesai (stuck 99% Pause). Cek: kalau order yg
        // dikirim monitor sudah status 'siap_ambil' di DB → order selesai,
        // JANGAN set current_order/progress/status (reset bersih).
        if ($order !== '' && !$isRelay) {
            try {
                $stOd = db()->prepare("SELECT status FROM orders WHERE order_number=? LIMIT 1");
                $stOd->execute([$order]);
                $odStat = $stOd->fetchColumn();
                if ($odStat === 'siap_ambil') {
                    $orderSelesaiReset = true;
                }
            } catch (Throwable $e) {
                // abaikan — kalau gagal query, lanjut normal
            }
            if ($orderSelesaiReset) {
                $sql .= ", current_order='', progress=0, total_pages=NULL, printed_pages=NULL, remaining_time='00:00:00', print_status='Idle', progress_stalled_at=NULL";
            } else {
                $sql .= ", current_order=?"; $params[] = $order;
            }
        } elseif (in_array(mb_strtolower($printStatus), ['idle', 'done', 'online', ''], true) && !$isRelay) {
            // Saat printer idle → kosongkan order aktif + reset progress/pages
            // (hindari sisa tampilan order & progress dari cetakan sebelumnya).
            // PENTING: relay (Mac) TIDAK boleh reset — relay sering kirim "Idle"
            // untuk printer yang sebenarnya masih dipantau PC agent. Kalau relay
            // reset, dia menghapus progress/order yang ditulis PC agent (konflik
            // sumber). Hanya sumber utama (agent/monitor asli) yang boleh reset.
            //
            // ANTI-HILANG PROGRESS (real-time multi-job): JANGAN reset saat Idle
            // jika masih ada job AKTIF (printing) yg belum printed penuh utk
            // printer ini dalam sesi 120 detik. Contoh: order 2 gambar → job1
            // selesai, job2 masih printing → agent kadang kirim 'Idle' di sela-
            // sela → kalau reset, progress bar hilang (0%) padahal belum selesai.
            // Progress harus TERTAHAN di 50% sampai semua job selesai.
            $resetIdle = true;
            if ($printerId > 0) {
                try {
                    $stIdle = db()->prepare("SELECT COUNT(*) FROM printer_jobs WHERE printer_id=? AND status='printing' AND start_at > DATE_SUB(NOW(), INTERVAL 120 SECOND) AND (total_pages IS NULL OR printed_pages IS NULL OR printed_pages < total_pages)");
                    $stIdle->execute([$printerId]);
                    if ((int)$stIdle->fetchColumn() > 0) {
                        $resetIdle = false;
                    }
                } catch (Throwable $e) {
                    // abaikan — default reset
                }
            }
            if ($resetIdle) {
                $sql .= ", current_order='', progress=0, total_pages=NULL, printed_pages=NULL, remaining_time='00:00:00', progress_stalled_at=NULL";
            }
        }
        if ($progress !== null && !$orderSelesaiReset) {
            // Cap progress 0-100% (spooler bisa kirim >100% saat total_pages
            // menurun — tampilkan wajar, jangan 416%).
            $progress = max(0, min(100, $progress));
            $sql .= ", progress=?"; $params[] = $progress;
        }
        // RYPC (uv): FIX 2026-09-04 — kalau monitor kirim remaining VALID (bukan
        // 00:00:00), itu nilai "Remain Time" yang dibaca dari LAYAR RYPC (OCR) =
        // angka yg sama dgn tampilan mesin → PAKAI apa adanya. Hanya kalau monitor
        // kirim 00:00:00 (tak terbaca), server hitung estimasi sendiri.
        // Printer lain (EPSON/dll): tetap pakai remaining dari monitor.
        $isRyPc = ($type === 'uv');
        $isRyPcRemainFromOcr = ($isRyPc && $remaining !== '' && $remaining !== '00:00:00');
        if ($remaining !== '' && (!$isRyPc || $isRyPcRemainFromOcr)) {
            $sql .= ", remaining_time=?";
            $params[] = $remaining;
        }
        // PENTING: relay (Mac) JANGAN menimpa print_status printer yang dikelola
        // PC agent — relay sering kirim "Idle/online" dan akan menimpa status
        // "Print" yang ditulis agent → konflik sumber. Relay hanya keep-alive.
        // FIX 2026-09-04: kalau order yg dikirim sudah selesai (siap_ambil),
        // jangan timpa print_status (sudah di-reset Idle di blok atas).
        if ($printStatus !== '' && !$isRelay && !$orderSelesaiReset) { $sql .= ", print_status=?"; $params[] = $printStatus; }

        // ===== ANTI-KECURANGAN: LACAK PROGRESS MACET =====
        // Saat printer klaim Print/Printing, progress HARUS naik.
        // - progress > 0 → tandai progress_stalled_at = NOW() (progress aktif).
        // - progress 0 terus → progress_stalled_at tetap lama → UI bisa hitung
        //   berapa lama "cetak" tanpa progress (indikasi tidak benar-benar jalan).
        //
        // FIX 2026-09-10 (keluhan user: "progress macet 571 mnt" — padahal mesin
        // tidak mencetak). DULU tidak ada satu pun cabang yang MEMBERSIHKAN
        // progress_stalled_at saat printer jadi Idle. Jadi setelah sekali macet
        // (mis. agent salah lapor 'Print' lalu diperbaiki), nilai lama itu
        // MENEMPEL dan terus dihitung sebagai durasi macet berjam-jam — angka
        //nya bohong. Sekarang: begitu status BUKAN Printing → bersihkan.
        $isPrintingBeatNow = in_array(mb_strtolower($printStatus), ['print', 'printing', 'mencetak', 'cetak', 'running'], true);
        // FIX 2026-09-11: progress_stalled_at HANYA di-reset saat progress
        // BENAR-BENAR NAIK (bukan tiap heartbeat).
        // BUG SEBELUMNYA: syaratnya cuma `progress > 0` → setiap heartbeat
        // menulis progress_stalled_at=NOW(). Akibatnya nilai itu SELALU baru
        // (~0 menit) sehingga peringatan "progress macet" di halaman monitor
        // TIDAK PERNAH muncul — padahal itu satu-satunya pengaman anti-diam.
        // Ini penting sekarang karena kartu sengaja DIPERTAHANKAN di 'Print'
        // selama masih ada sisa lembar (jeda antar-lembar tidak boleh dianggap
        // selesai). Tanpa pelacak yg benar, cetakan yg benar-benar berhenti
        // (operator lupa / mesin error) akan tampak "sedang jalan" selamanya.
        if ($isPrintingBeatNow && $progress !== null && (int)$progress > (int)$prevProgressSnap) {
            // Progress NAIK → tandai waktu progress terakhir bergerak.
            $sql .= ", progress_stalled_at=?";
            $params[] = date('Y-m-d H:i:s');
        } elseif ($isPrintingBeatNow && $progress !== null && $progress === 0 && !isset($input['progress_stalled_keep'])) {
            // progress 0 saat Print — biarkan progress_stalled_at apa adanya
            // (tidak di-reset), supaya durasi macet terhitung.
        } elseif (!$isPrintingBeatNow && !$isRelay && $printStatus !== '') {
            // Printer TIDAK mencetak (Idle/Done/Online/Pause) → durasi macet
            // tidak berlaku lagi. Bersihkan supaya UI tidak menampilkan
            // "macet 571 menit" padahal mesin sudah berhenti.
            $sql .= ", progress_stalled_at=NULL";
        }

        // ===== LOG AUDIT: CATAT PROGRESS MACET =====
        // Jika status Print & progress sudah lama tidak naik (>5 menit), catat
        // ke printer_stall_logs (sekali per kejadian) sebagai bukti audit.
        if ($isPrintingBeatNow) {
            try {
                $stNow = date('Y-m-d H:i:s');
                $stChk = db()->prepare("SELECT progress_stalled_at FROM printers WHERE id = ?");
                $stChk->execute([$printerId]);
                $stalledVal = $stChk->fetchColumn();
                if ($stalledVal !== null && $stalledVal !== false && $stalledVal !== '') {
                    $stallSec = time() - strtotime((string)$stalledVal);
                    // Ambang: > 5 menit macet (300 detik) saat klaim Print
                    if ($stallSec >= 300) {
                        // Catat sekali — hanya jika belum ada log aktif (resolved_at IS NULL) utk printer ini
                        $activeChk = db()->prepare("SELECT id FROM printer_stall_logs WHERE printer_id = ? AND resolved_at IS NULL ORDER BY id DESC LIMIT 1");
                        $activeChk->execute([$printerId]);
                        if (!$activeChk->fetch()) {
                            $insLog = db()->prepare("INSERT INTO printer_stall_logs (printer_id, printer_name, order_number, progress, stalled_at) VALUES (?,?,?,?,?)");
                            $insLog->execute([$printerId, $name, $order, (int)$progress, $stalledVal]);
                            writeLog("ANTI-KECURANGAN: progress macet {$stallSec}s utk printer #$printerId ($name) order $order progress=$progress", 'WARN');
                        }
                    }
                }
            } catch (Throwable $e) {
                // abaikan — log audit tidak boleh mengganggu heartbeat
            }
        } else {
            // Tidak lagi Print → tutup log stall yang masih aktif (resolved)
            try {
                db()->prepare("UPDATE printer_stall_logs SET resolved_at = ? WHERE printer_id = ? AND resolved_at IS NULL")
                    ->execute([date('Y-m-d H:i:s'), $printerId]);
            } catch (Throwable $e) {
                // abaikan
            }
        }

        // ===== PROGRESS PER LEMBAR (printer konvensional) =====
        // total_pages & printed_pages dari monitor (Get-PrintJob Windows).
        // UI menghitung progress = printed_pages / total_pages * 100.
        // Khusus printer konvensional (bukan m² RYPC) — total_pages ada.
        $hTotal = isset($input['total_pages']) ? (int)$input['total_pages'] : 0;
        $hPrinted = isset($input['printed_pages']) ? (int)$input['printed_pages'] : 0;

        // ===== STABILKAN TOTAL_PAGES (jangan menyusut) =====
        // Spooler Windows (Notepad/IPP) sering memberi total_pages yang MENURUN
        // selama cetak berjalan (30 -> 26 -> 21 -> 16), bukan total tetap. Ini
        // bikin progress melonjak cepat ke 100% padahal baru cetak sedikit.
        // Solusi: ambil total_pages TERBESAR yang pernah terlihat utk job ini
        // (pertahankan nilai puncak, jangan timpa dengan nilai yang lebih kecil).
        // PENGECUALIAN: kalau sumber mengirim accurate_total=1 (V2 agent yang
        // hitung dari isi file — nilai PASTI benar), langsung pakai & bypass.
        $accurateTotal = !empty($input['accurate_total']);
        $isPrintingBeatNow2 = in_array(mb_strtolower($printStatus), ['print', 'printing', 'mencetak', 'cetak', 'running'], true);
        if ($isPrintingBeatNow2 && $hTotal > 0 && !$accurateTotal) {
            try {
                $stTot = db()->prepare("SELECT total_pages FROM printers WHERE id = ?");
                $stTot->execute([$printerId]);
                $curTot = (int)$stTot->fetchColumn();
                if ($curTot > 0 && $hTotal < $curTot) {
                    $hTotal = $curTot;   // pertahankan nilai puncak (jangan menyusut)
                }
            } catch (Throwable $e) {
                // abaikan — pakai nilai input
            }
        }

        // ===== AGREGASI MULTI-JOB PER ORDER (progress GABUNGAN) =====
        // Satu order cetak bisa terdiri dari BANYAK job spooler (mis. 10 undangan
        // = 10 gambar terpisah = 10 job). Tanpa agregasi, progress per-job jalan
        // sendiri (0/1 -> 1/1) lalu reset -> pelanggan tidak bisa pantau "udah
        // selesai berapa". Dengan agregasi, kita SUM total_pages & printed_pages
        // SEMUA job (printing + selesai) utk order yg sama, sehingga progress
        // GABUNGAN naik bertahap (mis. 0/10 -> 1/10 -> ... -> 10/10).
        //
        // Pertama: update printed_pages job printing terbaru secara real-time
        // (job dimulai dgn printed_pages=0 di INSERT), supaya agregasi
        // mencerminkan progress yg sedang berjalan, bukan hanya saat selesai.
        if ($order !== '' && $printerId > 0 && ($hTotal > 0 || $hPrinted > 0)) {
            try {
                db()->prepare("UPDATE printer_jobs SET printed_pages=? WHERE printer_id=? AND order_number=? AND status='printing' ORDER BY id DESC LIMIT 1")
                    ->execute([max(0, (int)$hPrinted), $printerId, $order]);
                // Agregasi semua job utk order ini dalam SESI CETAK TERAKHIR
                // (helper di functions.php — gap > 5 menit = sesi baru).
                $agg = getOrderPrintAggregation($order);
                if ($agg['has_data']) {
                    $hTotal = $agg['total_pages'];
                    $hPrinted = $agg['printed_pages'];
                }
            } catch (Throwable $e) {
                // abaikan — kalau agregasi gagal, pakai nilai per-job biasa
            }
        }

        if ($hTotal > 0 || $hPrinted > 0) {
            $sql .= ", total_pages=?, printed_pages=?";
            $params[] = $hTotal > 0 ? $hTotal : null;
            $params[] = $hPrinted > 0 ? $hPrinted : null;

            // ===== PROGRESS PALING AKURAT = printed_pages / total_pages =====
            // PENTING: JANGAN percaya progress dari agent — agent hitung dari
            // spooler yang total_pages-nya berubah-ubah (menyusut) → progress
            // melonjak. Hitung ulang dari printed_pages / total_pages yang SUDAH
            // distabilkan di puncak (di atas). Ini yang benar & proporsional.
            $isKonvPrinting = in_array(mb_strtolower($printStatus), ['print', 'printing', 'mencetak', 'cetak', 'running'], true);
            if ($hTotal > 0 && $isKonvPrinting) {
                $calc = min(100, (int)round($hPrinted * 100 / $hTotal));
                $sql .= ", progress=?";
                $params[] = $calc;
            } elseif ($hTotal > 0 && $progress === null) {
                $calc = min(100, (int)round($hPrinted * 100 / $hTotal));
                $sql .= ", progress=?";
                $params[] = $calc;
            }

            // ===== HITUNG JUMLAH UNDANGAN/PCS TERPRINT =====
            // Untuk printer konvensional (EPSON dll) yang mencetak per lembar.
            // 1 lembar bisa berisi beberapa undangan (pcs_per_lembar, di-set per
            // order). Undangan terprint = lembar tercetak × pcs_per_lembar.
            // Ambil pcs_per_lembar dari order yang sedang dicetak (kalau ada).
            // Disimpan ke extra_info printer → dibaca nota (e-receipt) real-time.
            $pcsPerLembar = 1;
            if ($order !== '') {
                try {
                    $ppl = db()->prepare("SELECT pcs_per_lembar FROM orders WHERE order_number = ? LIMIT 1");
                    $ppl->execute([$order]);
                    $pVal = $ppl->fetchColumn();
                    if ($pVal !== false && $pVal !== null && (int)$pVal > 0) {
                        $pcsPerLembar = (int)$pVal;
                    }
                } catch (Throwable $e) {
                    // abaikan — default 1
                }
            }
            $pcsPrinted = $hPrinted * $pcsPerLembar;
            $pcsTarget  = $hTotal * $pcsPerLembar;
            // Amankan angka aneh dari spooler (total_pages bisa menurun saat print
            // berjalan/selesai): target minimal = printed, progress cap 100%.
            if ($pcsTarget > 0 && $pcsTarget < $pcsPrinted) {
                $pcsTarget = $pcsPrinted;
            }
            $extra['pcs_per_lembar'] = $pcsPerLembar;
            $extra['pcs_printed']    = $pcsPrinted;
            $extra['pcs_target']     = $pcsTarget;
            $extra['unit_label']     = 'undangan';

            // ===== ESTIMASI SISA WAKTU (per lembar) =====
            // Kecepatan = printed_pages / waktu berjalan (sejak mulai cetak).
            // Sisa waktu = (total - printed) / kecepatan.
            // Hanya jika sedang Print/Printing & sudah ada beberapa lembar.
            $isPrintingNow = in_array(mb_strtolower($printStatus), ['print', 'printing', 'mencetak', 'cetak', 'running'], true);
            if ($isPrintingNow && $hTotal > 0 && $hPrinted > 0 && $remaining === '00:00:00') {
                // Cari waktu mulai job printing terakhir utk printer ini
                try {
                    $st2 = db()->prepare("SELECT start_at FROM printer_jobs WHERE printer_id=? AND status='printing' ORDER BY id DESC LIMIT 1");
                    $st2->execute([$printerId]);
                    $startAt = $st2->fetchColumn();
                    if (!$startAt) {
                        // Fallback: updated_at printer (mulai Print)
                        $st3 = db()->prepare("SELECT updated_at FROM printers WHERE id=?");
                        $st3->execute([$printerId]);
                        $startAt = $st3->fetchColumn();
                    }
                    if ($startAt) {
                        $elapsed = max(1, time() - strtotime((string)$startAt));
                        $speed = $hPrinted / $elapsed; // lembar per detik
                        if ($speed > 0) {
                            $remainPages = max(0, $hTotal - $hPrinted);
                            $remainSec = (int)ceil($remainPages / $speed);
                            if ($remainSec > 0) {
                                $hh = floor($remainSec / 3600);
                                $mm = floor(($remainSec % 3600) / 60);
                                $ss = $remainSec % 60;
                                $sql .= ", remaining_time=?";
                                $params[] = sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // abaikan — gagal hitung sisa waktu tidak fatal
                }
            }
        }

        // ===== ESTIMASI SISA WAKTU UNTUK RYPC (type uv) =====
        // FIX 2026-09-04: RYPC sendiri menghitung "Remain Time" dari SISA
        // HALAMAN x laju (tampil di layar, mis. "232/256 (90%)" → sisa 24 hal).
        // Supaya cg_monitor SAMA dengan remain di layar RYPC, hitung pakai
        // done/total halaman DULU (data yg dibaca monitor dari teks "N/M").
        // real_output (m²/jam) x luas HANYA fallback kalau data halaman tidak
        // ada — rumus luas itu yg bikin angka beda ("ngaco") dari punya RYPC.
        // Dihitung saat Print MAUPUN Pause (pause hanya sementara, mesin lanjut).
        $isRyPc = ($type === 'uv');
        $isPrintingNow2 = in_array(mb_strtolower($printStatus), ['print', 'printing', 'mencetak', 'cetak', 'running', 'pause'], true);
        // FIX 2026-09-04: kalau remaining SUDAH dari OCR layar RYPC (monitor kirim
        // nilai valid = angka yg sama dgn tampilan mesin), JANGAN timpa dgn hitung.
        if ($isRyPc && $isRyPcRemainFromOcr) {
            // remaining_time sudah di-set dari OCR di atas → skip hitungan
        } elseif ($isRyPc && $isPrintingNow2 && ($progress !== null && $progress > 0)) {
            try {
                $remainSecR = 0;
                $rypcDone = isset($input['rypc_done_pages']) ? (int)$input['rypc_done_pages'] : 0;
                $rypcTotal = isset($input['rypc_total_pages']) ? (int)$input['rypc_total_pages'] : 0;
                $stR = db()->prepare("SELECT start_at FROM printer_jobs WHERE printer_id=? AND status='printing' ORDER BY id DESC LIMIT 1");
                $stR->execute([$printerId]);
                $startR = $stR->fetchColumn();
                if (!$startR) {
                    $stR2 = db()->prepare("SELECT start_at FROM printer_jobs WHERE printer_id=? ORDER BY id DESC LIMIT 1");
                    $stR2->execute([$printerId]);
                    $startR = $stR2->fetchColumn();
                }
                if (!$startR) {
                    $stR3 = db()->prepare("SELECT updated_at FROM printers WHERE id=?");
                    $stR3->execute([$printerId]);
                    $startR = $stR3->fetchColumn();
                }
                if ($startR) {
                    $elapsedR = max(1, time() - strtotime((string)$startR));
                    // ===== PRIORITAS 1: laju per HALAMAN (sama spt RYPC) =====
                    if ($rypcTotal > 0 && $rypcDone > 0 && $rypcDone <= $rypcTotal) {
                        $speedPage = $rypcDone / $elapsedR; // halaman/detik
                        if ($speedPage > 0) {
                            $remainSecR = (int)ceil(($rypcTotal - $rypcDone) / $speedPage);
                        }
                    } else {
                        $speedPct = $progress / $elapsedR;
                        if ($speedPct > 0) {
                            $remainSecR = (int)ceil((100 - $progress) / $speedPct);
                        }
                    }
                }

                // ===== FALLBACK: real output (m²/jam) x luas =====
                // Hanya kalau hitungan halaman tidak menghasilkan nilai.
                if ($remainSecR <= 0) {
                    $ro = (float)($input['real_output'] ?? 0);
                    if ($ro <= 0) {
                        $ro = (float)getSetting('rypc_default_speed', '18');
                    }
                    $wM = (float)($input['width_m'] ?? 0);
                    $hM = (float)($input['height_m'] ?? 0);
                    if ($ro > 0 && $wM > 0 && $hM > 0) {
                        $totalArea = $wM * $hM;                     // m²
                        $remainArea = $totalArea * (1 - $progress / 100.0);
                        if ($remainArea > 0) {
                            $remainSecR = (int)ceil($remainArea / ($ro / 3600.0));
                        }
                    }
                }
                if ($remainSecR > 0 && $remainSecR < 24 * 3600) {
                    $hh = floor($remainSecR / 3600);
                    $mm = floor(($remainSecR % 3600) / 60);
                    $ss = $remainSecR % 60;
                    $sql .= ", remaining_time=?";
                    $params[] = sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
                }
            } catch (Throwable $e) {
                // abaikan — gagal hitung sisa tidak fatal
            }
        }
        } // end else (bukan progress-only)
    }
    if ($type !== '') { $sql .= ", type=?"; $params[] = $type; }

    // Simpan info tambahan (mis. status docker & session WA dari host) ke extra_info.
    // Relay tidak menimpa — hanya monitor asli yang boleh set extra_info.
    $extraInfo = (string)($input['docker'] ?? $input['extra'] ?? '');
    if ($extraInfo !== '' && !$isRelay) {
        $sql .= ", extra_info=?";
        $params[] = substr($extraInfo, 0, 500);
    }

    // ===== NOTIF "MULAI CETAK" OTOMATIS =====
    // Saat monitor Python mendeteksi mesin mulai cetak (print_status berubah
    // dari Idle/Online → Print/Printing) dan ada current_order → kirim notif
    // WhatsApp ke pelanggan + update status order jadi 'finishing'.
    // Gunakan kolom cetak_at sbg penanda: hanya kirim SEKALI per order.
    // DIPERBAIKI: proses SEMUA order di orders_list (multi-order dalam satu file
    // .prn), bukan hanya current_order — agar tiap pelanggan dapat notif.
    $isPrinting = in_array(mb_strtolower($printStatus), ['print', 'printing', 'mencetak', 'cetak', 'running'], true);
    if ($isPrinting && $printerId > 0) {
        // Kumpulkan semua order yang akan diproses: current_order + orders_list
        $allOrders = [];
        if ($order !== '') { $allOrders[] = $order; }
        $ordersListRaw = (string)($input['orders_list'] ?? '');
        foreach (preg_split('/[,;]/', $ordersListRaw) as $part) {
            $part = trim($part);
            if ($part !== '') { $allOrders[] = $part; }
        }
        $allOrders = array_values(array_unique($allOrders));

        try {
            // Catatan: tidak pakai guard "wasIdle" karena order multi-order yang
            // bukan current_order utama TIDAK pernah mengalami transisi idle→print
            // (hanya order utama yang jadi patokan status printer). Pengaman yang
            // benar adalah kolom cetak_at: order hanya dikirim SEKALI (sebelumnya
            // pernah dicetak → cetak_at terisi → skip). Karena kita hanya iterasi
            // order yang sedang dicetak SEKARANG (dari current_order + orders_list),
            // order lama/macet tidak ikut tersapu.
            foreach ($allOrders as $ordNo) {
                // Cari order + pelanggan
                $ost = db()->prepare("SELECT o.id, o.order_number, o.status, o.grand_total,
                                             o.cetak_at, c.name AS customer_name, c.phone
                                      FROM orders o JOIN customers c ON o.customer_id = c.id
                                      WHERE o.order_number = ? LIMIT 1");
                $ost->execute([$ordNo]);
                $o = $ost->fetch();
                if (!$o) { continue; }
                // Hanya kirim notif jika order belum pernah dicetak (cetak_at kosong),
                // dan status order masih 'diterima'/'desain'/'finishing' (bukan sudah
                // siap_ambil/selesai — jangan turunkan).
                $orderEligible = in_array((string)$o['status'], ['diterima', 'desain', 'finishing'], true);
                if (empty($o['cetak_at']) && $orderEligible) {
                    $nowStr = date('Y-m-d H:i:s');
                    // Update status order → finishing + tandai mulai cetak
                    db()->prepare("UPDATE orders SET status='finishing', cetak_at=?, updated_at=? WHERE id=?")
                        ->execute([$nowStr, $nowStr, (int)$o['id']]);

                    // Kirim notif "mulai proses cetak" ke SEMUA pelanggan (ada nomor)
                    if (!empty($o['phone'])) {
                        $msg = buildStatusMessage('finishing', [
                            'name'         => (string)($o['customer_name'] ?? ''),
                            'order_number' => (string)($o['order_number'] ?? ''),
                            'status_label' => 'Pencetakan & Finishing',
                            'items'        => [],
                            'total'        => (float)($o['grand_total'] ?? 0),
                            'link'         => notaUrl((string)$o['order_number']),
                            'app'          => APP_NAME,
                        ], false);
                        $sent = sendWhatsAppNotification((string)$o['phone'], $msg);
                        writeLog("NOTIF MULAI CETAK: #{$o['order_number']} ke {$o['phone']} " . ($sent ? 'OK' : 'GAGAL'), $sent ? 'INFO' : 'WARN');
                    }

                    // ===== INFO KE INTERNAL (OWNER/KARYAWAN) — "infokan kesini juga" =====
                    // Aktif via setting `notif_owner_on_print` (Pengaturan → Notifikasi
                    // Monitor RYPC UV). Kirim SALINAN singkat ke nomor + grup internal
                    // bahwa order mulai dicetak. Link e-receipt TETAP cloud (notaUrl),
                    // BUKAN IP LAN — jangan pernah ganti.
                    if ((string)getSetting('notif_owner_on_print', '1') === '1') {
                        $intMsg = "🖨️ *MULAI CETAK — " . APP_NAME . "*\n\n"
                            . "• No. Pesanan : #{$o['order_number']}\n"
                            . "• Pelanggan   : " . (string)($o['customer_name'] ?: '-') . "\n"
                            . "• Status      : Pencetakan & Finishing\n"
                            . "• Total       : " . formatRupiah((float)($o['grand_total'] ?? 0)) . "\n"
                            . "• Cek Status  : " . notaUrl((string)$o['order_number']) . "\n\n"
                            . "Notif pelanggan sudah terkirim otomatis.";
                        $nSent = sendInternalNotice($intMsg);
                        writeLog("NOTIF INTERNAL MULAI CETAK: #{$o['order_number']} terkirim ke {$nSent} tujuan", 'INFO');
                    }
                }
            }
        } catch (Throwable $e) {
            writeLog('printer_heartbeat notif mulai cetak error: ' . $e->getMessage(), 'ERROR');
        }
    }

    // Simpan field tambahan (hostname, uptime, cpu, mem, docker, dsb) ke extra_info
    // (relay TIDAK menimpa extra_info — itu milik monitor asli)
    // orders_list = SEMUA order yg sedang dicetak (dipisah koma) — UI pakai ini
    // utk tampilkan multi-order di card printer.
    $extraFields = ['hostname', 'uptime_seconds', 'version', 'cpu_percent', 'mem_mb', 'docker', 'cpu', 'orders_list'];
    // $extra sudah diinisialisasi di atas (baris awal update) — jangan reset,
    // supaya field pcs_printed/pcs_target/pcs_per_lembar dari blok progress
    // per lembar tetap ikut tersimpan.
    if (!isset($extra)) { $extra = []; }
    if (!$isRelay) {
        foreach ($extraFields as $f) {
            if (isset($input[$f])) {
                $extra[$f] = is_numeric($input[$f]) ? (int)$input[$f] : (string)$input[$f];
            }
        }
    }

    // ===== DUKUNGAN PRINTER DTF / SABLON (EPSON L1800 Digital Factory) =====
    // Agent cg_dtf_monitor membaca log Digital Factory & mengirim:
    //   dtf_sheets_printed : jumlah LEMBAR FILM yg sudah selesai dicetak
    //   dtf_sheets_target  : target lembar (qty order / baju per lembar)
    //   dtf_processing     : 1 kalau sedang ada lembar diproses
    // Satu lembar film = beberapa desain baju (mis. 6). Server simpan info ini
    // ke extra_info supaya e-receipt bisa tampilkan "N dari M lembar film" +
    // estimasi baju (qty order dihitung di sisi nota dari pcs_per_lembar/order).
    // total_pages/printed_pages/progress/status sudah dikirim agent sbg
    // printed_pages = lembar selesai → diproses alur utama $sql di atas.
    if (isset($input['dtf_sheets_printed']) && !$isRelay) {
        $dtfDone   = max(0, (int)$input['dtf_sheets_printed']);
        $dtfTarget = max(0, (int)($input['dtf_sheets_target'] ?? 0));
        $dtfProc   = !empty($input['dtf_processing']) ? 1 : 0;
        // ===== ORDER DARI SNAPSHOT (FIX 2026-09-11) =====
        // KELUHAN USER: "dtf lagi cetak ga ada progressbar, notanya aneh,
        // padahal dtf notanya ada press baju seperti konsep awal."
        //
        // MASALAH: saat agent menyimpulkan Idle di sela antar-lembar, dia
        // mengirim `current_order: ""`. Akibatnya seluruh blok DTF ini berjalan
        // dengan `$order` KOSONG:
        //   - `$extra['dtf_order']` jadi '' → nota kehilangan rujukan order
        //   - `$extra['dtf_sheets_target']` tidak dihitung (butuh qty order)
        //   - pembacaan lembar dari `dtf_sheet_log` (butuh order) dilewati
        // Padahal PRINTER masih terikat order itu (lihat SNAPSHOT `$prevOrderSnap`
        // yg dibaca SEBELUM update). Jadi order yg tersimpan dipakai sbg
        // fallback supaya progress & info lembar film TIDAK hilang di sela
        // antar-lembar. Order BARU dari agent selalu menang kalau ada.
        if ($order === '' && $prevOrderSnap !== '') {
            $order = $prevOrderSnap;
        }
        /* ===== TOLAK ORDER BASI DARI AGENT (FIX 2026-10-09) =====
         * GEJALA YANG DILAPORKAN PEMILIK: "monitor ga ngebaca file padahal
         * lagi cetak dtf".
         *
         * KENYATAAN DI DATA: agent melaporkan order CG1409266954 (order tanggal
         * 14 September — 25 hari lalu) berkali-kali, padahal yang dicetak
         * operator adalah order LAIN. Karena agent melaporkan order basi itu,
         * kartu DTF menampilkan order yang salah dan order yang SEDANG dicetak
         * tidak pernah muncul.
         *
         * SEBAB: Digital Factory TIDAK menghapus job yang sudah selesai dari
         * daftar antreannya. Agent membaca daftar itu dan mengirim job paling
         * atas — yang bisa jadi job berhari-hari lalu. Jejak cetaknya juga
         * sudah lama tidak berubah (`dtf_sheet_log` terakhir 21 Sep).
         *
         * CARA MEMBEDAKAN dengan cetak lembar TAMBAHAN yang SAH:
         * cetak ulang nama yang gagal / lembar tambahan itu TERJADI SEKARANG,
         * jadi agent pasti mengirim `dtf_processing=1` (ada lembar aktif).
         * Kalau agent bilang TIDAK ada lembar aktif (`dtf_processing=0`) TAPI
         * menempelkan order yang filmnya sudah selesai berhari-hari lalu →
         * itu PASTI sisa daftar lama, bukan pekerjaan sekarang. Aman ditolak.
         *
         * SYARAT TOLAK (ketiganya harus benar):
         *   1. `dtf_processing == 0`  → agent sendiri mengaku tak ada lembar aktif
         *   2. `press_wa_at` terisi   → film order itu SUDAH selesai (tanda permanen)
         *   3. umur > 1 hari          → bukan pekerjaan hari ini
         * ------------------------------------------------------------ */
        if ($order !== '' && $dtfProc === 0) {
            try {
                $stBasi = db()->prepare(
                    "SELECT press_wa_at, order_date,
                            DATEDIFF(NOW(), COALESCE(press_wa_at, order_date)) AS umur_hari
                       FROM orders WHERE order_number = ? LIMIT 1"
                );
                $stBasi->execute([$order]);
                $odBasi = $stBasi->fetch();
                if ($odBasi && !empty($odBasi['press_wa_at']) && (int)$odBasi['umur_hari'] > 1) {
                    writeLog("DTF-TOLAK-BASI: agent lapor order #$order padahal film sudah "
                        . "selesai {$odBasi['umur_hari']} hari lalu & tidak ada lembar aktif "
                        . "→ diabaikan (sisa daftar lama Digital Factory)", 'INFO');
                    $order   = '';
                    $dtfDone = 0;
                }
            } catch (Throwable $e) {
                // abaikan — kalau query gagal, lanjut perilaku lama
            }
        }
        // ===== FRAKSI LEMBAR AKTIF (2026-09-10) =====
        // Agent kirim `dtf_frac` = seberapa jauh lembar yg SEDANG dicetak
        // (0..1). Agent sering TIDAK tahu target (state file-log kosong karena
        // Page*.log baru terisi setelah 1 lembar selesai) → dia kirim
        // target=0 & progress=0, padahal SERVER sudah punya target sendiri
        // (dihitung dari orders.dtf_per_lembar). Dengan frac ini, SERVER bisa
        // menghitung progress global = (lembar selesai + frac) / target_server,
        // benar utk order berapa lembar pun — tanpa agent perlu tahu target.
        $dtfFrac = isset($input['dtf_frac']) ? (float)$input['dtf_frac'] : null;
        if ($dtfFrac !== null) {
            $dtfFrac = min(0.999, max(0.0, $dtfFrac));
        }
        $extra['dtf_frac'] = $dtfFrac !== null ? round($dtfFrac, 4) : 0;
        // ===== GUARD MONOTONIK PER ORDER (sama spt blok final) =====
        // extra_info (dtf_sheets_printed) adalah sumber yg nota baca utk
        // "N dari M lembar film". Kalau agent restart/kirim nilai kecil utk
        // order yg sama, jangan biarkan turun dr nilai terakhir tersimpan.
        // Pakai SNAPSHOT awal ($prevOrderSnap/$prevPrintedSnap) — bukan query
        // ulang yg sudah ketimpa update $sql.
        if ($order !== '' && $order === $prevOrderSnap && $prevPrintedSnap > 0 && $dtfDone < $prevPrintedSnap) {
            $dtfDone = $prevPrintedSnap;
        }
        // ===== SUMBER LEMBAR SELESAI: dtf_sheet_log (PERMANEN) =====
        // FIX 2026-09-11: nilai dari snapshot di atas HILANG kalau
        // `current_order` sudah dikosongkan (snapshot jadi kosong) atau agent
        // baru restart. Server punya catatan PERMANEN di tabel `dtf_sheet_log`
        // (diisi dari `dtf_sheet_events` tiap lembar selesai, tahan rotate file
        // log DF) - tabel itu JUSTRU BELUM PERNAH dipakai utk menghitung
        // progress. Hitung dari sana & pakai nilai TERBESAR = MUSTAHIL MUNDUR.
        if ($order !== '' && $printerId > 0) {
            try {
                $qSh0 = db()->prepare("SELECT COUNT(DISTINCT sheet_no) FROM dtf_sheet_log
                    WHERE printer_id=? AND order_number=? AND status='selesai'");
                $qSh0->execute([$printerId, $order]);
                $logSh0 = (int)$qSh0->fetchColumn();
                if ($logSh0 > $dtfDone) {
                    $dtfDone = $logSh0;
                }
            } catch (Throwable $e) {
                // abaikan - tabel mungkin belum ada
            }
        }
        $extra['dtf']                = 1;
        $extra['dtf_sheets_printed'] = $dtfDone;
        $extra['dtf_sheets_target']  = $dtfTarget;
        $extra['dtf_processing']     = $dtfProc;
        $extra['dtf_order']          = $order;
        // Label pcs utk tampilan nota — 'lembar film' (bukan 'undangan')
        $extra['pcs_printed']    = $dtfDone;
        $extra['pcs_target']     = $dtfTarget;
        $extra['unit_label']     = 'lembar film';
        $extra['pcs_per_lembar'] = 1;
        // ===== ESTIMASI BAJU (DTF sablon) =====
        // Ambil qty baju dari item order yang TIDAK spanduk/banner/stiker
        // (dianggap item DTF/sablon kaos). target lembar -> baju per lembar,
        // lalu estimasi baju selesai = lembar selesai x baju per lembar.
        // Disimpan ke extra supaya e-receipt bisa tampil "±X dari Y baju".
        if ($order !== '' && $printerId > 0) {
            try {
                $qQ = db()->prepare("SELECT COALESCE(SUM(oi.qty),0) AS total_qty,
                                            COALESCE(MAX(CASE WHEN LOWER(oi.product_name) LIKE '%kaos%' OR LOWER(oi.product_name) LIKE '%baju%' OR LOWER(oi.product_name) LIKE '%shirt%' OR LOWER(oi.product_name) LIKE '%sablon%' OR LOWER(oi.product_name) LIKE '%dtf%' THEN oi.qty END),0) AS shirt_qty
                                     FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE o.order_number=?");
                $qQ->execute([$order]);
                $qRow = $qQ->fetch();
                $totalQty = (int)round((float)($qRow['total_qty'] ?? 0));
                $shirtQty = (int)round((float)($qRow['shirt_qty'] ?? 0));
                $bajuTarget = $shirtQty > 0 ? $shirtQty : $totalQty;
                // dtf_per_lembar order diambil terpisah (orders = 1 baris, aman)
                $dtfPerLembarOrder = 0;
                try {
                    $dQ = db()->prepare("SELECT dtf_per_lembar FROM orders WHERE order_number=? LIMIT 1");
                    $dQ->execute([$order]);
                    $dtfPerLembarOrder = (int)($dQ->fetchColumn() ?? 0);
                } catch (Throwable $e) {
                    $dtfPerLembarOrder = 0;
                }
                if ($bajuTarget > 0) {
                    $extra['dtf_qty_baju'] = $bajuTarget;
                    // ===== TARGET LEMBAR AUTO dari isian order (dtf_per_lembar) =====
                    // Admin isi "isi baju/desain per lembar film" di order. Target
                    // lembar otomatis = ceil(total baju / isi per lembar). Ini
                    // sumber kebenaran — menimpa target yg dikirim agent (yg
                    // sebelumnya di-set manual di state agent). Kalau order belum
                    // punya dtf_per_lembar, fallback ke target agent.
                    //
                    // FIX 2026-09-10: BARIS INI DULU MENIMPA nilai yg sudah dibaca
                    // benar di atas ($dtfPerLembarOrder dari query `orders`) dgn
                    // $qRow['dtf_per_lembar'] — padahal query $qRow (order_items
                    // JOIN orders) tidak SELECT kolom itu → SELALU 0 → target
                    // lembar 0 → progress nota selalu 0% walau mesin sedang cetak.
                    // JANGAN tambahkan baris penimpa tsb lagi.
                    if ($dtfPerLembarOrder > 0) {
                        $dtfTarget = (int)ceil($bajuTarget / $dtfPerLembarOrder);
                        $extra['dtf_sheets_target'] = $dtfTarget;
                        $extra['pcs_target'] = $dtfTarget;
                        $extra['dtf_baju_per_lembar'] = $dtfPerLembarOrder;
                        $extra['dtf_baju_printed'] = min($bajuTarget, $dtfDone * $dtfPerLembarOrder);
                    } elseif ($dtfTarget > 0) {
                        $bajuPerLembar = (int)round($bajuTarget / $dtfTarget);
                        $extra['dtf_baju_per_lembar'] = max(1, $bajuPerLembar);
                        $extra['dtf_baju_printed'] = min($bajuTarget, $dtfDone * $bajuPerLembar);
                    } else {
                        // ===== TARGET TIDAK DIISI → ANGGAP 1 LEMBAR FILM =====
                        // FIX 2026-09-11 (keluhan user: "kenapa nota ga baca
                        // processnya ya" / "kenapa nota ga baca processnya").
                        //
                        // MASALAH: operator SERING tidak mengisi "Isi baju per
                        // lembar film". Terbukti di data produksi: 5 dari 7 order
                        // DTF aktif TIDAK mengisi kolom itu. Tanpa target:
                        //   - progress mustahil dihitung (bagi nol) → nota tampil
                        //     0% & "Jumlah terprint: -" padahal mesin MENCETAK
                        //   - tahap "film selesai → press baju" TIDAK PERNAH
                        //     muncul (butuh target > 0) → konsep awal hilang
                        //
                        // SOLUSI: default 1 LEMBAR FILM. Ini sesuai realita
                        // produksi yg diamati — order tanpa isian (1-3 baju) semua
                        // dicetak dgn SATU gang-sheet lalu langsung lanjut ke
                        // press. Jadi:
                        //   - progress = kemajuan lembar itu (naik mulus 0→99%)
                        //   - begitu lembar selesai → done=1=target → mesin balik
                        //     Idle & tahap "Menunggu Press ke baju" MUNCUL
                        //     (konsep awal utuh lagi)
                        //   - TIDAK ada lagi kartu nyangkut 20% selamanya
                        //
                        // Order yg MEMANG butuh banyak lembar (mis. 100 baju):
                        // operator cukup mengisi "Isi baju per lembar film" seperti
                        // biasa → cabang di atas yg dipakai (akurat per lembar).
                        //
                        // FIX 2026-10-11 (keluhan user: "kok ada lembaran, ini kan
                        // 1 cetakan saja"): DULU dipaksa $dtfTarget = 1. Padahal
                        // realita workshop = **1 baju = 1 lembar film** (Digital
                        // Factory menerima 1 gambar per baju: "<order>.png",
                        // "<order>.png 2", "<order>.png 3"). Dipaksa 1 membuat
                        // order 3 baju ditandai SELESAI setelah 1 lembar.
                        // Sekarang: target = jumlah baju (per lembar = 1).
                        $dtfTarget = max(1, $bajuTarget);
                        $extra['dtf_sheets_target'] = $dtfTarget;
                        $extra['pcs_target'] = $dtfTarget;
                        $extra['dtf_baju_per_lembar'] = 1;
                        $extra['dtf_baju_printed'] = min($bajuTarget, $dtfDone);
                        if ($loopLogOk ?? true) {
                            writeLog("DTF-TARGET-QTY: order #$order tidak isi "
                                . "dtf_per_lembar → target = jumlah baju = $dtfTarget lembar "
                                . "(lembar selesai=$dtfDone)", 'INFO');
                        }
                    }
                }
            } catch (Throwable $e) {
                // abaikan — estimasi baju tidak fatal
            }
        }
    }
    // ===== FALLBACK TARGET: pakai angka agent kalau server tak bisa menghitung ==
    // Terjadi bila order tidak ada di DB / qty tidak terbaca. Agent mengirim
    // `dtf_sheets_fallback` = jumlah job nyata di Digital Factory (1 job = 1
    // lembar). Dipakai HANYA saat target server masih 0 supaya qty order tetap
    // jadi sumber utama.
    if ((int)($extra['dtf_sheets_target'] ?? 0) <= 0 && !empty($input['dtf_sheets_fallback'])) {
        $extra['dtf_sheets_target'] = (int)$input['dtf_sheets_fallback'];
        $extra['pcs_target'] = (int)$input['dtf_sheets_fallback'];
    }
    // ===== OVERRIDE target lembar utk blok final =====
    // Kalau target auto dihitung ulang dari order (dtf_per_lembar), timpa
    // $input['dtf_sheets_target'] supaya blok OVERRIDE FINAL DTF (di akhir,
    // yg update total_pages/progress) memakai target yg benar — bukan angka
    // manual yg dikirim agent. (Blok final membaca $input['dtf_sheets_target'].)
    if (isset($extra['dtf_sheets_target']) && $extra['dtf_sheets_target'] > 0 && isset($input['dtf_sheets_printed']) && !$isRelay) {
        $input['dtf_sheets_target'] = (int)$extra['dtf_sheets_target'];
    }
    if ($extra) {
        $sql .= ", extra_info=?";
        $params[] = json_encode($extra, JSON_UNESCAPED_UNICODE);
    }

    // ===== STATISTIK CETAK (printer_jobs) =====
    // Deteksi transisi status utk mencatat: mulai cetak, selesai, gagal.
    // Mencatat SEMUA cetakan — termasuk file di LUAR orderan (tanpa nomor CG),
    // agar operator yang bandel cetak sembarangan tetap terdata.
    $jobPrinterId = $printerId;
    $jobOrder     = $order;
    $jobStatus    = strtolower(trim($printStatus));
    $jobFile      = trim((string)($input['file_name'] ?? $input['display'] ?? ''));
    if ($printerId > 0 && $jobStatus !== '') {
        try {
            $prevSt = db()->prepare("SELECT print_status FROM printers WHERE id = ?");
            $prevSt->execute([$printerId]);
            $prevStatus = strtolower((string)$prevSt->fetchColumn());

            $isPrinting = in_array($jobStatus, ['print', 'printing', 'mencetak', 'cetak', 'running'], true);
            $wasPrinting = in_array($prevStatus, ['print', 'printing', 'mencetak', 'cetak', 'running'], true);
            $isIdleDone = in_array($jobStatus, ['idle', 'done', 'online', ''], true);

            // Klasifikasi: 'order' HANYA jika nomor order benar-benar ADA di database.
            // Jika nama file ada CG tapi order tidak ditemukan (mis. CG1208261707
            // yang sudah tidak ada), tetap dianggap 'luar' — karena tidak ada order
            // valid yang dipertanggungjawabkan. Ini mencegah operator lolos dengan
            // menulis nomor order fiktif di nama file.
            $sesuai = 'luar';
            $orderAda = false;
            if ($jobOrder !== '') {
                $cek = db()->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ?");
                $cek->execute([$jobOrder]);
                if ((int)$cek->fetchColumn() > 0) {
                    $orderAda = true;
                    $sesuai = 'order';
                }
            }

            // ===== MULAI CETAK (Idle/Done -> Print) =====
            // Dicatat UNTUK SEMUA cetakan (ada order ATAU file luar), agar semua terdata.
            if ($isPrinting && !$wasPrinting) {
                // ===== ANTI-FLAPPING: jangan buat job ganda =====
                // Monitor kadang kirim status flapping (Print → non-Print → Print)
                // dalam SATU cetakan yang sama. Jika sudah ada job 'printing'
                // untuk FILE yang sama yang dimulai < 90 detik lalu, itu bukan
                // cetakan baru — pertahankan job lama (jangan gagalkan & buat baru).
                //
                // PENTING: cek file SAMA (bukan order sama) — satu order bisa
                // dicetak dalam BANYAK job file berbeda (10 undangan = 10 gambar).
                // KALAU file yang sama SUDAH ADA (printing ATAU selesai, dalam
                // 90 detik) → flapping (status Idle sesaat dari agent) → JANGAN
                // buat job baru; cukup "hidupkan kembali" job yang ada sbg printing
                // supaya tidak duplikat.
                $flappingSameFile = false;
                $flapJobId = 0;
                if ($jobFile !== '') {
                    $flChk = db()->prepare("SELECT id FROM printer_jobs WHERE printer_id=? AND LOWER(TRIM(file_name))=LOWER(TRIM(?)) AND status IN ('printing','selesai') AND start_at > DATE_SUB(NOW(), INTERVAL 90 SECOND) ORDER BY id DESC LIMIT 1");
                    $flChk->execute([$printerId, $jobFile]);
                    $flapJobId = (int)$flChk->fetchColumn();
                    $flappingSameFile = $flapJobId > 0;
                }
                if ($flappingSameFile) {
                    // Flapping — hidupkan kembali job yang ada (jangan duplikat).
                    // Kalau job sempat di-mark 'selesai' krn flapping Idle sesaat,
                    // kembalikan ke 'printing' supaya tidak jadi duplikat baru.
                    db()->prepare("UPDATE printer_jobs SET status='printing', end_at=NULL WHERE id=?")
                        ->execute([$flapJobId]);
                    // Update printed_pages real-time
                    $flPp = isset($input['printed_pages']) ? (int)$input['printed_pages'] : 0;
                    $flTp = isset($input['total_pages']) ? (int)$input['total_pages'] : 0;
                    if ($flTp > 0 || $flPp > 0) {
                        db()->prepare("UPDATE printer_jobs SET total_pages=COALESCE(total_pages,?), printed_pages=? WHERE id=?")
                            ->execute([$flTp > 0 ? $flTp : null, max(0, $flPp), $flapJobId]);
                    }
                    writeLog("ANTI-FLAPPING: hidupkan kembali job id=$flapJobId printer #$printerId file='$jobFile' order='$jobOrder' (flapping < 90s)", 'INFO');
                } else {
                // Tutup job 'printing' lama yang belum selesai (anggap gagal/terputus)
                db()->prepare("UPDATE printer_jobs SET status='gagal', end_at=NOW() WHERE printer_id=? AND status='printing'")
                    ->execute([$printerId]);

                // Cari ukuran dari item order (pola WxH di nama produk) — hanya jika ada order
                $w = null; $h = null; $area = null;

                // PRIORITAS 1: ukuran dikirim monitor (hasil baca header .prn / name file)
                if (isset($input['width_m']) && isset($input['height_m'])) {
                    $pw = (float)$input['width_m'];
                    $ph = (float)$input['height_m'];
                    if ($pw > 0 && $ph > 0 && $pw <= 30 && $ph <= 30) {
                        $w = $pw; $h = $ph;
                        $area = round($w * $h, 2);
                    }
                }

                if ($w === null && $orderAda) {
                    $sz = db()->prepare("SELECT product_name FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE o.order_number=? LIMIT 8");
                    $sz->execute([$jobOrder]);
                    foreach ($sz->fetchAll() as $it) {
                        $pname = (string)$it['product_name'];
                        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)/i', $pname, $mm)) {
                            $n1 = (float)str_replace(',', '.', $mm[1]);
                            $n2 = (float)str_replace(',', '.', $mm[2]);
                            // Deteksi satuan pada nama produk: mm / m / (default cm)
                            $unit = 0.01; // default cm → meter (÷100)
                            if (preg_match('/mm\b/i', $pname)) {
                                $unit = 0.001;  // mm → meter (÷1000)
                            } elseif (preg_match('/(?:^|[\s_])(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*m\b/i', $pname)) {
                                $unit = 1.0;    // "3x1m" → meter
                            }
                            $w = $n1 * $unit;
                            $h = $n2 * $unit;
                            // Cegah hasil konyol: lebar ≤ 3m (roll), panjang ≤ 30m.
                            if ($w > 30 || $h > 30 || $w <= 0 || $h <= 0) {
                                $w = null; $h = null;
                            }
                            break;
                        }
                    }
                    if ($w !== null && $h !== null) {
                        $area = round($w * $h, 2);
                    }
                }

                // Jika tidak ada ukuran dari order, coba parse dari NAMA FILE .
                // Ukuran cetak RYPC ada di nama file dalam cm/mm/m, misal:
                //   "CG SP 120x180 cm.prn" → 120cm x 180cm = 2.16 m²
                //   "140x186.prn"          → 140cm x 186cm = 2.604 m²
                //   "bjg 3x1m.prn"          → 3m x 1m = 3 m²
                //   "312x80cm.prn"          → 3.12m x 0.8m
                if ($w === null && $jobFile !== '') {
                    // Deteksi satuan dari konteks nama file (cm/mm/m)
                    $unit = 0.01; // default cm → meter (÷100)
                    if (preg_match('/mm\b/i', $jobFile)) {
                        $unit = 0.001; // mm → meter (÷1000)
                    } elseif (preg_match('/(?:^|[\s_])(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*m\b/i', $jobFile)) {
                        $unit = 1.0; // "3x1m" → meter
                    } elseif (preg_match('/cm\b/i', $jobFile)) {
                        $unit = 0.01;
                    }

                    if (preg_match('/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)/i', $jobFile, $mm)) {
                        $w = (float)str_replace(',', '.', $mm[1]) * $unit;
                        $h = (float)str_replace(',', '.', $mm[2]) * $unit;
                        if ($w > 0 && $h > 0) {
                            // Cegah hasil konyol: lebar roll maks ~2m, panjang bisa panjang.
                            // Wajar: lebar ≤ 3m, panjang ≤ 30m (roll panjang). Batasi agar tidak
                            // salah tangkap pola lain (mis. nomor "1500X458" cm = 15m x 4.58m).
                            if ($w <= 30 && $h <= 30) {
                                $area = round($w * $h, 2);
                            } else {
                                $w = null; $h = null;
                            }
                        } else {
                            $w = null; $h = null;
                        }
                    }
                }

                // ===== PENCATATAN PRINTER PER LEMBAR (mis. EPSON L15150) =====
                // Monitor mengirim total_pages/printed_pages (Windows print queue).
                // Jika ada, simpan jumlah lembar — printer ini dihitung per lembar,
                // bukan per m² (width/height/area tetap null).
                $totalPages = null; $printedPages = null;
                if (isset($input['total_pages']) || isset($input['printed_pages'])) {
                    $tp = isset($input['total_pages']) ? (int)$input['total_pages'] : 0;
                    $pp = isset($input['printed_pages']) ? (int)$input['printed_pages'] : 0;
                    if ($tp > 0 || $pp > 0) {
                        $totalPages = $tp > 0 ? $tp : null;
                        $printedPages = $pp > 0 ? $pp : null;
                        // Per lembar → kosongkan ukuran m² (tidak relevan)
                        $w = null; $h = null; $area = null;
                    }
                }

                // HANYA catat job kalau ada FILE/DOKUMEN nyata (file_name).
                // Heartbeat status saja (dari Mac / relay) tanpa file_name TIDAK
                // membuat job kosong — cuma update status printer.
                if ($jobFile !== '') {
                    db()->prepare("INSERT INTO printer_jobs (printer_id, order_number, file_name, sesuai_order, start_at, status, width_m, height_m, area_m2, total_pages, printed_pages) VALUES (?,?,?,?,NOW(),'printing',?,?,?,?,?)")
                        ->execute([$printerId, $jobOrder !== '' ? $jobOrder : null, $jobFile !== '' ? $jobFile : null, $sesuai, $w, $h, $area, $totalPages, $printedPages]);
                }
                } // end else (bukan flapping)
            }

            // ===== FILE BERUBAH SAAT MASIH PRINTING (multi-file berurutan) =====
            // Kasus: operator mencetak BANYAK file untuk SATU order TANPA jeda
            // (mis. 10 undangan = 10 gambar, dikirim berurutan). Status printer
            // tetap 'Print' terus → block "MULAI CETAK" di atas TIDAK jalan lagi
            // utk file ke-2 (karena !$wasPrinting = false). Akibatnya job ke-2
            // tidak pernah tercatat → agregasi multi-job tidak lengkap.
            // Solusi: selama status printing, kalau job 'printing' aktif saat ini
            // punya file_name yang BERBEDA dari heartbeat ini → tutup job lama
            // sebagai 'selesai' (sambil mengunci printed_pages=total_pages) dan
            // buat job baru utk file yang sekarang.
            //
            // ANTI-DUPLIKAT (penting): V2 mengirim heartbeat per-job spooler.
            // Kalau 2 job spooler (file beda) ada BERSAMAAN di queue (mis. agent
            // polling bolak-balik halaman1 → halaman2 → halaman1), flip file akan
            // membuat duplikat tiap flip. Guard: kalau sudah ada job utk file yg
            // SAMA + order SAMA dalam 120 detik → JANGAN insert baru; cukup
            // perbarui printed_pages job printing utk file tsb (jika ada).
            if ($isPrinting && $jobFile !== '') {
                try {
                    $curJob = db()->prepare("SELECT id, file_name FROM printer_jobs WHERE printer_id=? AND status='printing' ORDER BY id DESC LIMIT 1");
                    $curJob->execute([$printerId]);
                    $cjr = $curJob->fetch();
                    if ($cjr) {
                        $curFile = strtolower(trim((string)($cjr['file_name'] ?? '')));
                        $newFile = strtolower(trim($jobFile));
                        $fileChanged = ($curFile !== $newFile);
                        if ($fileChanged) {
                            // ===== ANTI-DUPLIKAT: file yg sama sudah ada? =====
                            $dupFile = false;
                            if ($jobOrder !== '') {
                                $dupChk = db()->prepare("SELECT COUNT(*) FROM printer_jobs WHERE printer_id=? AND order_number=? AND LOWER(TRIM(file_name))=LOWER(TRIM(?)) AND start_at > DATE_SUB(NOW(), INTERVAL 120 SECOND)");
                                $dupChk->execute([$printerId, $jobOrder, $jobFile]);
                            } else {
                                $dupChk = db()->prepare("SELECT COUNT(*) FROM printer_jobs WHERE printer_id=? AND LOWER(TRIM(file_name))=LOWER(TRIM(?)) AND start_at > DATE_SUB(NOW(), INTERVAL 120 SECOND)");
                                $dupChk->execute([$printerId, $jobFile]);
                            }
                            $dupFile = (int)$dupChk->fetchColumn() > 0;

                            if ($dupFile) {
                                // Sudah ada job utk file ini (dari flip sebelumnya) —
                                // jangan buat duplikat. Hanya tutup job 'printing'
                                // lama yang file-nya beda (biar status bersih), dan
                                // pastikan job utk file ini tercatat selesai/aktif.
                                writeLog("ANTI-DUPLIKAT FILE BERUBAH: printer #$printerId '$curFile' -> '$newFile' (file sudah ada, skip insert)", 'INFO');
                                db()->prepare("UPDATE printer_jobs SET status='selesai', end_at=NOW(), printed_pages=COALESCE(total_pages, printed_pages) WHERE printer_id=? AND status='printing' AND id <> ?")
                                    ->execute([$printerId, (int)$cjr['id']]);
                                // Update printed_pages job utk file ini (kalau masih printing)
                                $pp2 = isset($input['printed_pages']) ? (int)$input['printed_pages'] : 0;
                                db()->prepare("UPDATE printer_jobs SET printed_pages=? WHERE printer_id=? AND order_number=? AND LOWER(TRIM(file_name))=LOWER(TRIM(?)) AND status='printing' ORDER BY id DESC LIMIT 1")
                                    ->execute([max(0, $pp2), $printerId, $jobOrder !== '' ? $jobOrder : '', $jobFile]);
                            } else {
                                // File beda & belum ada → job lama selesai, buat job baru.
                                writeLog("FILE BERUBAH: printer #$printerId '$curFile' -> '$newFile' (job id={$cjr['id']} ditutup, job baru utk file baru)", 'INFO');
                                db()->prepare("UPDATE printer_jobs SET status='selesai', end_at=NOW(), printed_pages=COALESCE(total_pages, printed_pages) WHERE id=?")
                                    ->execute([(int)$cjr['id']]);

                                $tp2 = isset($input['total_pages']) ? (int)$input['total_pages'] : 0;
                                $pp2 = isset($input['printed_pages']) ? (int)$input['printed_pages'] : 0;
                                $ins2 = db()->prepare("INSERT INTO printer_jobs (printer_id, order_number, file_name, sesuai_order, start_at, status, total_pages, printed_pages) VALUES (?,?,?,?,NOW(),'printing',?,?)");
                                $ins2->execute([$printerId, $jobOrder !== '' ? $jobOrder : null, $jobFile, $sesuai, $tp2 > 0 ? $tp2 : null, $pp2 > 0 ? $pp2 : null]);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    writeLog('printer_heartbeat file-changed error: ' . $e->getMessage(), 'ERROR');
                }
            }

            // ===== SELESAI / IDLE (Print -> Idle/Done) =====
            if ($wasPrinting && $isIdleDone) {
                // ===== ANTI-FLAPPING saat SELESAI =====
                // Jangan tandai "selesai" jika job berjalan BARU SAJA dimulai
                // (< 30 dtk) — itu kemungkinan transisi status sesaat (mesin
                // lanjut mencetak), bukan cetakan selesai beneran. Kalau dipaksa
                // selesai, nanti server buat job baru lagi = duplikat.
                $skipDone = false;
                if ($jobFile !== '' || $jobOrder !== '') {
                    $dChk = db()->prepare("SELECT id, start_at FROM printer_jobs WHERE printer_id=? AND status='printing' ORDER BY id DESC LIMIT 1");
                    $dChk->execute([$printerId]);
                    $dRow = $dChk->fetch();
                    if ($dRow) {
                        $ageSec = time() - strtotime((string)$dRow['start_at']);
                        if ($ageSec < 30) {
                            $skipDone = true;
                            writeLog("ANTI-FLAPPING: tahan tandai selesai printer #$printerId job id={$dRow['id']} (umur {$ageSec}s)", 'INFO');
                        }
                    }
                }
                // ===== ANTI-HILANG PROGRESS MULTI-JOB =====
                // Agent sering kirim 'Idle' di SELANG-SELANG job (job1 selesai,
                // job2 masih jalan). Kalau kita tandai selesai, job2 (printing)
                // ikut di-reset → progress hilang (padahal belum selesai semua).
                // Guard: kalau masih ada job 'printing' yang BELUM PRINTED PENUH
                // (printed_pages < total_pages) dalam sesi 120 detik → JANGAN
                // tandai selesai; biarkan job tetap 'printing' & progress tertahan.
                // Kalau semua job SUDAH printed penuh → boleh tandai selesai & reset.
                if (!$skipDone) {
                    try {
                        $activeOther = db()->prepare("SELECT COUNT(*) FROM printer_jobs WHERE printer_id=? AND status='printing' AND start_at > DATE_SUB(NOW(), INTERVAL 120 SECOND) AND (total_pages IS NULL OR printed_pages IS NULL OR printed_pages < total_pages)");
                        $activeOther->execute([$printerId]);
                        if ((int)$activeOther->fetchColumn() > 0) {
                            // Masih ada job printing yg belum selesai → jangan
                            // tandai selesai (progress harus tertahan).
                            $skipDone = true;
                            writeLog("ANTI-HILANG: tahan tandai selesai printer #$printerId (masih ada job printing belum penuh dalam sesi)", 'INFO');
                        }
                    } catch (Throwable $e) {
                        // abaikan
                    }
                }
                if (!$skipDone) {
                    // Saat job selesai, pastikan printed_pages tercatat PENUH
                    // (sama dgn total_pages) — agregasi multi-job butuh ini utk
                    // menghitung progress gabungan dgn benar.
                    $upd = db()->prepare("UPDATE printer_jobs SET status='selesai', end_at=NOW(), printed_pages=COALESCE(total_pages, printed_pages), duration_sec=TIMESTAMPDIFF(SECOND, start_at, NOW()) WHERE printer_id=? AND status='printing' ORDER BY id DESC LIMIT 1");
                    $upd->execute([$printerId]);
                }
            }

            // ===== PAUSE (Print -> Pause) =====
            if ($wasPrinting && $jobStatus === 'pause') {
                // Biarkan job tetap 'printing' (bisa dilanjutkan). Gagal dihitung saat idle tanpa selesai.
                db()->prepare("UPDATE printer_jobs SET status='printing' WHERE printer_id=? AND status='printing'")
                    ->execute([$printerId]);
            }
        } catch (Throwable $e) {
            writeLog('printer_heartbeat job stat error: ' . $e->getMessage(), 'ERROR');
        }
    }

    $sql .= ", status='online' WHERE id=?";
    $params[] = $printerId;
    db()->prepare($sql)->execute($params);

    // ===== SAPU JOB BASI (2026-09-11) — WAJIB, sebelum apa pun =====
    // MASALAH (keluhan user: "skrg job ga ada knpa ada progress?"):
    // Job berstatus 'printing' TIDAK PERNAH ditutup kalau printer langsung
    // berpindah ke Idle tanpa melewati jalur "Print -> Idle" yang normal (mis.
    // agent mati, agen lain menimpa, atau order sudah siap_ambil). Akibatnya:
    //   - Job 'printing' menggantung BERJAM-JAM. Bukti nyata di DB: ada job
    //     menggantung 117, 170, dan 295 JAM.
    //   - `getOrderPrintAggregation()` (dipakai NOTA) membaca job
    //     status IN ('printing','selesai') → job hantu ini dihitung sebagai
    //     progres cetak → nota menampilkan progress padahal TIDAK ada job.
    //   - Sisa printed_pages 1/2 = 50% ikut "menempel" di kartu.
    // FIX: setiap heartbeat, job 'printing' yang sudah LEWAT BATAS WAJAR
    // ditutup (selesai kalau halaman sudah penuh, gagal kalau belum). Batas 30
    // menit: jauh lebih lama dari cetakan normal (lembar DTF ~46-90 dtk,
    // undangan per halaman beberapa menit) sehingga TIDAK memutus cetakan
    // panjang yang benar-benar berjalan, tapi cukup cepat membersihkan hantu.
    if ($printerId > 0 && !$isRelay) {
        try {
            // SYARAT AMAN: hanya disapu kalau PRINTER SEDANG TIDAK MELAPORKAN
            // CETAK. Cetakan besar yang SAH bisa berlangsung berjam-jam
            // (mis. spanduk 1.5x6 m di RYPC UV, atau undangan banyak halaman).
            // Kalau disapu berdasarkan umur saja, cetakan panjang akan dipotong
            // di tengah jalan → progress & laporan cetak rusak.
            // Jadi: sapu HANYA bila status printer saat ini bukan Print/Pause.
            $bykCetak = in_array(mb_strtolower(trim((string)$printStatus)),
                                 ['print', 'printing', 'mencetak', 'cetak', 'running', 'pause'], true);
            if (!$bykCetak) {
                // CATATAN: nilai INTERVAL ditulis LITERAL (bukan binding `?`)
                // karena PDO mode emulated-prepare bisa mengutip angka sebagai
                // string → `INTERVAL '1800' SECOND` berisiko error di MySQL.
                $staleCut = 1800; // detik (30 menit)
                // (a) halaman sudah penuh → SELESAI
                db()->prepare("UPDATE printer_jobs SET status='selesai', end_at=NOW(),
                        printed_pages=COALESCE(total_pages, printed_pages),
                        duration_sec=TIMESTAMPDIFF(SECOND, start_at, NOW())
                    WHERE printer_id=? AND status='printing'
                      AND start_at < DATE_SUB(NOW(), INTERVAL $staleCut SECOND)
                      AND total_pages IS NOT NULL AND printed_pages IS NOT NULL
                      AND printed_pages >= total_pages")
                    ->execute([$printerId]);
                // (b) belum penuh (terputus) → GAGAL, supaya tidak dihitung nota
                db()->prepare("UPDATE printer_jobs SET status='gagal', end_at=NOW(),
                        duration_sec=TIMESTAMPDIFF(SECOND, start_at, NOW())
                    WHERE printer_id=? AND status='printing'
                      AND start_at < DATE_SUB(NOW(), INTERVAL $staleCut SECOND)
                      AND (total_pages IS NULL OR printed_pages IS NULL OR printed_pages < total_pages)")
                    ->execute([$printerId]);
            }
        } catch (Throwable $e) {
            writeLog("sapu job basi error: " . $e->getMessage(), 'ERROR');
        }
    }

    // ===== AGREGASI AKHIR STATE PRINTER (SETELAH $sql update) =====
    // Dijalankan SETELAH update utama supaya hasil agregasi (SUM semua job utk
    // order aktif dalam sesi cetak) yang MENANG, bukan nilai per-job dari input.
    // Kondisi: masih ada job PRINTING (aktif) utk order yg sama — jadi saat
    // agent kirim 'Idle' di sela-sela job (job1 selesai, job2 baru mulai),
    // progress TERTAHAN (mis. 50%), TIDAK hilang/reset. Kalau SEMUA job sudah
    // selesai → biarkan reset Idle (state printer bersih), nota tetap baca
    // progress 100% dari printer_jobs (agregasi sesi).
    // PENTING (2026-09-10): LEWATI utk request DTF (agent kirim dtf_sheets_printed).
    // Progress DTF dihitung dari LOG Digital Factory (done lembar), BUKAN dari
    // spooler/printed_pages konvensional. Kalau agregasi spooler ikut jalan utk
    // printer DTF, nilainya bisa beda & MENIMPA progress DTF yg benar → bikin
    // bar naik-turun. DTF punya jalur sendiri (blok OVERRIDE FINAL DTF) yg menang.
    $isDtfRequest = isset($input['dtf_sheets_printed']);
    $aggOrder = '';
    if ($order !== '' && !$isDtfRequest) {
        $aggOrder = $order;
    }
    $hasActiveJob = false;
    if ($printerId > 0 && $aggOrder === '' && !$isDtfRequest) {
        // Order tidak dikirim agent (Idle) — cek apakah masih ada job printing
        // utk printer ini (percobaan pertahankan progress saat sela-sela job).
        try {
            $stAO = db()->prepare("SELECT order_number FROM printer_jobs WHERE printer_id=? AND status='printing' AND order_number IS NOT NULL AND order_number != '' AND start_at > DATE_SUB(NOW(), INTERVAL 120 SECOND) ORDER BY id DESC LIMIT 1");
            $stAO->execute([$printerId]);
            $ao = $stAO->fetchColumn();
            if ($ao) { $aggOrder = (string)$ao; $hasActiveJob = true; }
        } catch (Throwable $e) {
            // abaikan
        }
    } elseif ($printerId > 0 && $aggOrder !== '') {
        // Ada order dikirim — cek apakah masih ada job printing utk order ini
        try {
            $stAJ = db()->prepare("SELECT COUNT(*) FROM printer_jobs WHERE printer_id=? AND order_number=? AND status='printing' AND start_at > DATE_SUB(NOW(), INTERVAL 120 SECOND)");
            $stAJ->execute([$printerId, $aggOrder]);
            $hasActiveJob = (int)$stAJ->fetchColumn() > 0;
        } catch (Throwable $e) {
            // abaikan
        }
    }
    if ($printerId > 0 && $aggOrder !== '' && $hasActiveJob) {
        try {
            // ===== PROGRESS DARI PRINTED_PAGES SPOOLER (BENAR utk Corel/PDF) =====
            // Untuk operasi NYATA (CorelDRAW/PDF), spooler Windows memberi
            // PagesPrinted BERTAHAP per halaman — jadi progress = printed/total
            // akurat & real-time. Ambil nilai printed_pages/total_pages dari job
            // printing aktif utk order ini.
            $aggTotal = 0;
            $aggPrinted = 0;
            $aggProg = 0;
            $jobSt = db()->prepare("SELECT id, start_at, total_pages, printed_pages FROM printer_jobs WHERE printer_id=? AND order_number=? AND status='printing' ORDER BY id DESC LIMIT 1");
            $jobSt->execute([$printerId, $aggOrder]);
            $jobRow = $jobSt->fetch();
            if ($jobRow && (int)$jobRow['total_pages'] > 0) {
                $aggTotal = (int)$jobRow['total_pages'];
                $aggPrinted = (int)($jobRow['printed_pages'] ?? 0);
                if ($aggPrinted > $aggTotal) { $aggPrinted = $aggTotal; }
                $aggProg = min(100, (int)round($aggPrinted * 100 / $aggTotal));
            } else {
                // Fallback: agregasi semua job utk order (sesi terakhir)
                $aggP = getOrderPrintAggregation($aggOrder);
                if ($aggP['has_data']) {
                    $aggTotal = $aggP['total_pages'];
                    $aggPrinted = $aggP['printed_pages'];
                    $aggProg = min(100, (int)round($aggPrinted * 100 / $aggTotal));
                }
            }

            if ($aggTotal > 0) {
                // JANGAN reset: pertahankan total_pages puncak kalau lebih kecil
                $stCur = db()->prepare("SELECT total_pages FROM printers WHERE id=?");
                $stCur->execute([$printerId]);
                $curTot = (int)$stCur->fetchColumn();
                if ($curTot > 0 && $aggTotal < $curTot) {
                    $aggTotal = $curTot;
                    $aggProg = min(100, (int)round($aggPrinted * 100 / $aggTotal));
                }
                db()->prepare("UPDATE printers SET total_pages=?, printed_pages=?, progress=? WHERE id=?")
                    ->execute([$aggTotal, $aggPrinted, $aggProg, $printerId]);
                writeLog("AGREGASI-PRINTED: printer #$printerId order=$aggOrder -> {$aggPrinted}/{$aggTotal} ({$aggProg}%)", 'INFO');
            }
        } catch (Throwable $e) {
            writeLog('printer_heartbeat agregasi akhir error: ' . $e->getMessage(), 'ERROR');
        }
    }

    // ===== OVERRIDE FINAL DTF (nilai akurat dari agent) =====
    // Harus di PALING AKHIR (setelah agregasi) supaya MENANG. Agent DTF mengukur
    // durasi lembar nyata dari log Digital Factory, jadi:
    //   - progress      = dtf_progress_float (fraksional → bar "jalan" mulus saat
    //                     1 lembar sedang dicetak, bukan diam lalu lompat).
    //   - remaining_time = dtf_remaining_sec (sisa waktu akurat = sisa lembar x
    //                     durasi rata2) — ini yg bikin countdown benar, bukan
    //                     angka melonjak dari estimasi spooler konvensional.
    if (isset($input['dtf_sheets_printed']) && $printerId > 0 && !$isRelay) {
        try {
            $dtfDone   = max(0, (int)$input['dtf_sheets_printed']);
            // FIX 2026-09-10: TARGET harus dari $extra (hasil hitungan server
            // dari orders.dtf_per_lembar), BUKAN dari $input (kiriman agent).
            // Agent sering kirim target=0 karena state file-log-nya kosong →
            // blok ini jatuh ke jalur "$dtfTarget=0" → progress macet walau
            // dtf_frac sudah 0.999. Server SUDAH tahu target sebenarnya di
            // $extra['dtf_sheets_target'].
            $dtfTarget = max(0, (int)($extra['dtf_sheets_target'] ?? ($input['dtf_sheets_target'] ?? 0)));
            $dtfProc   = !empty($input['dtf_processing']) ? 1 : 0;
            // ===== SUMBER LEMBAR SELESAI: dtf_sheet_log (PERMANEN) =====
            // FIX 2026-09-11 (keluhan user: "dtf lagi cetak ga ada progressbar,
            //    padahal notanya ada press baju seperti konsep awal").
            // MASALAH: agent sering kirim `dtf_sheets_printed=0` saat dia
            // menyimpulkan "Idle" (menjelang/tengah lembar ke-2, window DF
            // tertutup). Nilai 0 itu MENIMPA jumlah lembar yang sudah BENAR &
            // sudah tersimpan → progress nota jatuh ke 0% walau 1 lembar
            // sungguh sudah selesai dicetak.
            // PADAHAL server punya CATATAN PERMANEN: tabel `dtf_sheet_log`
            // (diisi dari `dtf_sheet_events` tiap lembar selesai — app tidak
            // bergantung file log DF yang bisa hilang). Tabel ini JUSTRU TIDAK
            // PERNAH dipakai utk menghitung progress.
            // Sekarang: hitung lembar selesai dari `dtf_sheet_log` (COUNT
            // DISTINCT sheet_no, status='selesai') dan pakai nilai TERBESAR
            // antara laporan agent vs catatan permanen. Ini monotonic: mustahil
            // mundur, dan tetap benar walau agent restart / kirim 0.
            if ($printerId > 0 && $order !== '') {
                try {
                    $qSheet = db()->prepare("SELECT COUNT(DISTINCT sheet_no) FROM dtf_sheet_log
                        WHERE printer_id=? AND order_number=? AND status='selesai'");
                    $qSheet->execute([$printerId, $order]);
                    $logSheets = (int)$qSheet->fetchColumn();
                    if ($logSheets > $dtfDone) {
                        $dtfDone = $logSheets;
                    }
                } catch (Throwable $e) {
                    // abaikan — tabel mungkin belum ada (deploy lama)
                }
            }
            // ===== GUARD MONOTONIK PER ORDER (2026-09-10) =====
            // cg_monitor & nota membaca baris printers yg SAMA — kalau agent
            // salah kirim jumlah lembar lebih kecil utk order yg sama (bug/
            // restart di tengah order/agent lama), dua-duanya ikut turun.
            // Di sini server (sumber tunggal penulisan) menahan: utk order yg
            // SAMA, printed_pages (lembar selesai) TIDAK boleh turun dr nilai
            // terakhir yg sudah tersimpan (dari SNAPSHOT awal). Order BARU →
            // boleh reset bersih.
            if ($order !== '' && $order === $prevOrderSnap && $prevPrintedSnap > 0 && $dtfDone < $prevPrintedSnap) {
                $dtfDone = $prevPrintedSnap; // jangan biarkan lembar selesai turun
            }
            // ===== CLAMP TAMPILAN: lembar selesai maksimal = target ORDER =====
            // FIX 2026-10-11: operator sering mencetak ULANG utk uji coba
            // (kejadian nyata: order 1 baju CG1110264985 dicetak 3 lembar).
            // Tanpa clamp, kartu tampil "3 dari 1 lembar" yg membingungkan user
            // ("kok ada lembaran, ini kan 1 cetakan saja"). Statistik lembar
            // SEBENARNYA tetap utuh di tabel `dtf_sheet_log`.
            if ($dtfTarget > 0 && $dtfDone > $dtfTarget) {
                $dtfDone = $dtfTarget;
            }
            $dtfProg   = isset($input['dtf_progress_float']) ? min(100.0, max(0.0, (float)$input['dtf_progress_float'])) : null;
            $dtfRemain = isset($input['dtf_remaining_sec']) ? max(0, (int)$input['dtf_remaining_sec']) : null;
            $dtfAvg    = isset($input['dtf_avg_sec']) ? max(1.0, (float)$input['dtf_avg_sec']) : null;
            // ===== APAKAH MESIN DTF MASIH MENCETAK (kata AGENT)? =====
            // Dipakai 3 tempat di bawah supaya kartu TIDAK direset ke Idle selama
            // mesin masih bekerja. Kejadian nyata 11 Okt 2026 23:42: DF CPU 100%
            // dan sudah 2x kirim data ke L1800 (977 MB & 813 MB), tapi kartu sablon
            // kosong "Idle" karena target ORDER (1 lembar) sudah tercapai -> server
            // mengira pekerjaan tuntas. Operator hanya mencetak lembar tambahan.
            $agentPrintingDtf = in_array(
                mb_strtolower(trim((string)$printStatus)),
                ['print', 'printing', 'mencetak', 'cetak', 'running'], true
            );

            // ===== GUARD MONOTONIK PROGRESS (2026-09-10) =====
            // Akar "progress naik-turun (95→90)" = frac (fraksi lembar aktif) di-reset
            // agent saat df_pct tidak terbaca / lembar berganti → nilai progress baru
            // lebih kecil dari yg sudah tercapai utk ORDER YG SAMA. Server (sumber
            // tunggal) menahan: utk order yg SAMA, progress TIDAK boleh turun dr nilai
            // snapshot. Naik boleh; turun ditahan ke nilai terakhir (monotonik).
            // Catatan: kalau agent restart & kirim order sama dgn nilai kecil, progress
            // tetap di posisi yg sudah benar (tidak mundur). Order BARU → reset normal.
            if ($order !== '' && $order === $prevOrderSnap) {
                if ($dtfProg !== null && (int)round($dtfProg) < $prevProgressSnap && $prevProgressSnap > 0) {
                    $dtfProg = (float)$prevProgressSnap; // jangan biarkan progress turun
                }
            }

            $upP = "UPDATE printers SET ";
            $setP = [];
            $prmP = [];
            $idleReset = false;   // true = film selesai → kartu printer balik Idle
            // ===== HITUNG PROGRESS dari TARGET SERVER + FRAKSI AGENT =====
            // (FIX 2026-09-10) Agent sering kirim target=0 karena state
            // file-log-nya kosong (Page*.log baru terisi setelah 1 lembar
            // selesai) → $dtfProg=0 → progress nota SELALU 0% walau mesin
            // mencetak. Server punya $dtfTarget sendiri (dari
            // orders.dtf_per_lembar), dan agent kini mengirim `dtf_frac`
            // (seberapa jauh lembar aktif). Jadi server HITUNG SENDIRI:
            //   progress = (lembar selesai + frac) / target_server * 100
            // Dipakai HANYA bila agent tidak memberi progress yg berguna
            // (dtfProg null/0) tapi server tahu targetnya.
            $dtfFracSrv = isset($extra['dtf_frac']) ? (float)$extra['dtf_frac'] : 0.0;
            // ===== FRAKSI HANYA BERLAKU BILA LEMBAR BENAR-BENAR AKTIF =====
            // PENTING (2026-09-10): `dtf_processing=0` berarti agent menyatakan
            // TIDAK ada lembar yang sedang dicetak. Kalau frac tetap dipakai,
            // progress naik PALSU (kejadian: prog 99% padahal lembar masih
            // 0/1 — bar bohong). Jadi frac = 0 bila proc=0.
            if (!$dtfProc) {
                $dtfFracSrv = 0.0;
            }
            if ($dtfTarget > 0) {
                $doneEff = $dtfDone + $dtfFracSrv;
                $pctCalc = $doneEff * 100.0 / $dtfTarget;
                if ($dtfDone < $dtfTarget) {
                    $pctCalc = min(99.0, $pctCalc);
                }
                $pctCalc = max(0.0, min(100.0, $pctCalc));
                // Hitung ulang dari FRAKSI hanya bila hasilnya masuk akal:
                // fraksi dipakai saat lembar aktif (proc=1), ATAU saat sudah
                // ada lembar selesai (done>0, maju bertahap per lembar).
                if ($dtfProc || $dtfDone > 0) {
                    if ($dtfProg === null || $pctCalc > (float)$dtfProg) {
                        $dtfProg = $pctCalc;
                    }
                } elseif ($dtfProg === null) {
                    $dtfProg = $pctCalc;  // belum ada apa-apa → 0%
                }
            }
            // JANGAN turunkan target di sini. Target dari `dtf_per_lembar` adalah
            // NIAT OPERATOR (mis. 25 baju ÷ 6 = 5 lembar) — kalau diturunkan saat
            // mesin jeda, order multi-lembar bisa ditandai SELESAI prematur
            // padahal lembar berikutnya belum dicetak. Kejujuran progress (mis.
            // "1 dari 5 lembar film" = 20%) justru BENAR: 1 lembar sudah jadi,
            // 4 belum. Yang harus jujur adalah STATUS — lihat blok `$masihJeda`
            // di bawah: mesin berhenti lama → 'Pause', bukan 'Print'.
            // Progress fraksional (utama — bikin bar animasi)
            if ($dtfProg !== null) {
                // Selagi lembar aktif belum selesai semua (done < target), jangan
                // biarkan 100% — baru 100% saat done == target. (FIX 2026-09-10:
                // agent sempat kirim 100% saat done=9/target=10 krn round(99.9).)
                $progVal = (float)$dtfProg;
                if ($dtfDone < $dtfTarget && $progVal >= 99.5) {
                    $progVal = 99.0;
                }
                // Mesin MASIH mencetak lembar tambahan (target ORDER sudah
                // tercapai, mis. operator cetak ulang) -> JANGAN tampilkan 100%:
                // kartu jadi terlihat "selesai" padahal mesin masih jalan
                // (kejadian nyata 11 Okt 2026 23:44: prog 100% + status Print).
                if ($agentPrintingDtf && $dtfDone >= $dtfTarget && $progVal >= 99.5) {
                    $progVal = 99.0;
                }
                $setP[] = "progress=?";
                $prmP[] = (int)round($progVal);
            } elseif ($dtfTarget > 0) {
                $pct = $dtfDone * 100 / $dtfTarget;
                // Mesin MASIH mencetak lembar tambahan (di luar target order) ->
                // jangan tampilkan 100%: kartu akan terlihat "selesai" padahal
                // mesin masih jalan.
                if ($dtfDone < $dtfTarget || $agentPrintingDtf) $pct = min(99.0, $pct);
                $setP[] = "progress=?";
                $prmP[] = (int)round($pct);
            }
            // ===== SISA WAKTU DTF (2026-09-10, revisi) =====
            // Arah yg benar = BERBASIS LEMBAR (bukan laju %/detik ala RYPC yg
            // cocok utk mesin kontinu, TIDAK utk DTF yg progress-nya naik per
            // lembar & suka lompat). Agent mengirim dtf_remaining_sec =
            //   (sisa lembar penuh x durasi/lembar) + (durasi - elapsed lembar aktif)
            // yg dihitung dari durasi cetak MURNI per lembar (log Start->done).
            // Server tinggal menyimpan + GUARD MONOTONIK: utk order yg SAMA,
            // remain TIDAK boleh MEMBESAR drastis (agent restart / nilai salah
            // bisa kirim remain lebih besar → UI restart countdown ke nilai besar
            // → terasa "mundur"). Kalau remain baru > remain lama + toleransi,
            // pertahankan nilai lama (countdown tetap turun, tidak loncat balik).
            $remainFinal = ($dtfRemain !== null && $dtfRemain > 0 && ($dtfDone < $dtfTarget || $agentPrintingDtf)) ? $dtfRemain : null;
            if ($remainFinal !== null) {
                // Baca remaining_time yg tersimpan saat ini (dari snapshot kolom)
                $prevRemainSec = 0;
                try {
                    $stRm = db()->prepare("SELECT remaining_time FROM printers WHERE id=?");
                    $stRm->execute([$printerId]);
                    $prevRemainStr = (string)$stRm->fetchColumn();
                    if ($prevRemainStr !== '' && $prevRemainStr !== '00:00:00') {
                        $pp = explode(':', $prevRemainStr);
                        if (count($pp) === 3) $prevRemainSec = (int)$pp[0]*3600 + (int)$pp[1]*60 + (int)$pp[2];
                    }
                } catch (Throwable $e) { $prevRemainSec = 0; }
                // Order sama & remain baru lebih besar dr nilai lama + toleransi
                // 30 dtk (mis. agent restart) → tahan ke nilai lama (jangan naik).
                if ($order !== '' && $order === $prevOrderSnap && $prevRemainSec > 0 && $remainFinal > $prevRemainSec + 30) {
                    $remainFinal = $prevRemainSec;
                }
                $setP[] = "remaining_time=?";
                $hh = intdiv($remainFinal, 3600);
                $mm = intdiv($remainFinal % 3600, 60);
                $ss = $remainFinal % 60;
                $prmP[] = sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
            } elseif ($dtfTarget > 0 && $dtfDone >= $dtfTarget) {
                // Film SELESAI → sisa waktu 0 (konsisten dgn status Done).
                $setP[] = "remaining_time=?";
                $prmP[] = '00:00:00';
                $dtfRemain = 0;
            } elseif ($dtfDone < $dtfTarget) {
                // Masih cetak tapi agent belum kirim remain valid → 0 (UI akan
                // tampilkan "—" / diam, bukan angka basi yg menyesatkan).
                $setP[] = "remaining_time=?";
                $prmP[] = '00:00:00';
                $dtfRemain = 0;
            }
            // Lembar selesai & target
            $setP[] = "printed_pages=?";
            $prmP[] = $dtfDone;
            if ($dtfTarget > 0) {
                $setP[] = "total_pages=?";
                $prmP[] = $dtfTarget;
            }
            // Status Print/Idle + current_order
            // PENTING (revisi 2026-09-10): cek "SEMUA LEMBAR SELESAI" HARUS lebih
            // dulu daripada cek $dtfProc. Sebab agent bisa MASIH melaporkan
            // dtf_processing=1 (layer OCR masih melihat job terakhir di layar DF /
            // heartbeat agak telat) padahal lembar sudah penuh (done >= target).
            // Kalau $dtfProc dicek duluan, kartu balik 'Print' + order muncul lagi
            // dan reset Idle tidak pernah bertahan.
            if ($dtfTarget > 0 && $dtfDone >= $dtfTarget && $agentPrintingDtf) {
                // Target ORDER sudah tercapai TAPI mesin MASIH mencetak (operator
                // cetak lembar tambahan / RIP lembar berikutnya). JANGAN reset kartu:
                // kalau direset, kartu sablon HILANG padahal mesin bekerja. Mesin
                // yang benar-benar berhenti akan kirim Idle sendiri -> kartu bersih.
                $setP[] = "print_status='Print'";
                if ($order !== '') {
                    $setP[] = "current_order=?";
                    $prmP[] = $order;
                }
            } elseif ($dtfTarget > 0 && $dtfDone >= $dtfTarget) {
                // Semua lembar film SELESAI. PERILAKU (minta user):
                // mesin BALIK IDLE & hitungan lembar direset 0 — konsisten dgn RYPC.
                // Alasan: kalau dibiarkan 'Done' + "1 dari 1 lembar film", kartu
                // tampak menggantung seolah masih ada pekerjaan, padahal film sudah
                // keluar semua. Info "film selesai → wajib press" ditangani TERPISAH
                // oleh tahap press di NOTA (orders.press_wa_at) — jadi TIDAK hilang
                // walau kartu printer direset. current_order dikosongkan supaya mesin
                // siap menerima order berikutnya tanpa sisa data lama.
                $idleReset = true;
            } elseif ($dtfProc) {
                $setP[] = "print_status='Print'";
                if ($order !== '') {
                    $setP[] = "current_order=?";
                    $prmP[] = $order;
                }
            } else {
                // FIX 2026-09-10 (keluhan user: "progress macet 571 mnt").
                // DULU di sini SELALU dipaksa print_status='Print' walau agent
                // sudah melaporkan mesin BERHENTI (agent kirim Idle karena
                // window DF tertutup → tidak ada cetakan nyata). Akibatnya
                // kartu DTF nyangkut 'Print 0%' BERJAM-JAM: progress tak pernah
                // naik → server mencatat progress macet ratusan menit, padahal
                // tidak ada lembar yang sedang dicetak. Data jadi bohong.
                //
                // ATURAN BARU: kalau AGENT menyatakan mesin tidak mencetak
                // (Idle/Done/Online) → IKUTI agent (kartu jadi Idle bersih).
                // Paksa 'Print' HANYA saat agent memang bilang Print/Pause —
                // itu kondisi sah "jeda antar lembar" dalam satu job.
                $agentStatDtf = mb_strtolower(trim((string)$printStatus));
                // ===== JEDA ANTAR LEMBAR BUKAN "SELESAI" (FIX 2026-09-11) =====
                // KELUHAN USER: "dtf lagi cetak ga ada progressbar, notanya aneh,
                // padahal dtf notanya ada press baju seperti konsep awal".
                //
                // MASALAH: agent menyimpulkan "Idle" setiap kali tidak menemukan
                // bukti cetak selama 45 dtk - dan itu WAJAR di sela antar-lembar
                // (ganti roll / jeda mesin). Karena di bawah ini `current_order`
                // dikosongkan, NOTA kehilangan rujukan ke printer (nota mencari
                // `WHERE current_order = ? OR extra_info LIKE ?`) sehingga
                // progress 0% & info lembar film HILANG, padahal baru 1 dari 5
                // lembar yang selesai. Konsep awal (film N/M lembar lalu press
                // baju) jadi rusak.
                //
                // ATURAN BARU: selama MASIH ADA SISA LEMBAR (done > 0 dan
                // done < target), pekerjaan BELUM tuntas -> PERTAHANKAN order &
                // progress supaya pelanggan tetap melihat "1 dari 5 lembar film".
                // Kartu ditandai 'Pause' (jujur: mesin tidak sedang mengirim data,
                // tapi order belum selesai). Kartu baru dibersihkan kalau SEMUA
                // lembar selesai ($idleReset di atas) atau belum ada lembar
                // selesai sama sekali.
                $adaSisaLembar = ($dtfTarget > 0 && $dtfDone > 0 && $dtfDone < $dtfTarget);
                if ($adaSisaLembar) {
                    // Pekerjaan belum tuntas → JANGAN lepas order (supaya nota
                    // tetap bisa tampilkan "1 dari 5 lembar film").
                    // TAPI STATUS harus JUJUR: jangan mengaku "sedang mencetak"
                    // kalau mesin sudah berhenti.
                    //
                    // KELUHAN USER: "lah kocak skarang ga ada cetakan ngapain 20%?"
                    // → kartu bertahan 'Print 20%' padahal mesin berhenti. Itu BUG
                    // tambalan saya sebelumnya yg memaksa 'Print' TANPA BATAS.
                    //
                    // PATOKAN (data nyata, bukan tebakan): kapan lembar terakhir
                    // SELESAI dicetak (dari `dtf_sheet_log` yg permanen) atau kapan
                    // progress terakhir NAIK (`progress_stalled_at`). Kalau sudah
                    // lewat JEDA_WAJAR, mesin jelas berhenti:
                    //   < JEDA_WAJAR  → 'Print' (masih jeda antar-lembar normal:
                    //                   ganti roll / mesin menyiapkan lembar baru)
                    //   >= JEDA_WAJAR → 'Pause' (mesin berhenti; nota jujur bilang
                    //                   "Cetak DTF Ditunda", progress & info lembar
                    //                   TETAP tersimpan — tidak hilang)
                    $JEDA_WAJAR = 300; // 5 menit
                    $acuanTs = 0;
                    if (!empty($progress_stalled_at_snap)) {
                        $acuanTs = (int)strtotime((string)$progress_stalled_at_snap);
                    }
                    // Lembar terakhir selesai (lebih akurat utk DTF).
                    try {
                        $qLast = db()->prepare("SELECT UNIX_TIMESTAMP(MAX(end_at)) FROM dtf_sheet_log
                            WHERE printer_id=? AND order_number=? AND status='selesai'");
                        $qLast->execute([$printerId, $order]);
                        $lastSheetTs = (int)$qLast->fetchColumn();
                        if ($lastSheetTs > $acuanTs) { $acuanTs = $lastSheetTs; }
                    } catch (Throwable $e) {
                        // abaikan
                    }
                    if ($acuanTs === 0) {
                        // Belum ada acuan → mulai hitung dari SEKARANG supaya jeda
                        // benar-benar terukur (tanpa ini kartu bisa 'Print' selamanya).
                        $acuanTs = time();
                        $setP[] = "progress_stalled_at=?";
                        $prmP[] = date('Y-m-d H:i:s');
                    }
                    $stallAge = max(0, time() - $acuanTs);
                    $masihJeda = ($stallAge < $JEDA_WAJAR);
                    $setP[] = $masihJeda ? "print_status='Print'" : "print_status='Pause'";
                    if ($order !== '') {
                        $setP[] = "current_order=?";
                        $prmP[] = $order;
                    }
                } elseif (in_array($agentStatDtf, ['idle', 'done', 'online', ''], true)) {
                    // Mesin tidak mencetak → bersihkan kartu (jujur, tanpa sisa
                    // order/progress lama yang bikin tampak menggantung).
                    // CATATAN: TIDAK menulis `progress=0` di sini — kolom
                    // progress mungkin sudah di-set di blok progress acima;
                    // menulis dua kali = error SQL "duplicate column".
                    $setP[] = "print_status='Idle'";
                    $setP[] = "current_order=''";
                } elseif ($agentStatDtf === 'pause') {
                    $setP[] = "print_status='Pause'";
                    if ($order !== '') {
                        $setP[] = "current_order=?";
                        $prmP[] = $order;
                    }
                } else {
                    // Agent bilang Print (jeda antar lembar) → pertahankan Print.
                    $setP[] = "print_status='Print'";
                    if ($order !== '' && $dtfDone > 0) {
                        $setP[] = "current_order=?";
                        $prmP[] = $order;
                    }
                }
            }
            // Simpan avg_sec ke extra_info utk referensi (gabung tanpa menimpa
            // field dtf_* lain yg sudah ada). State laju progress (dtf_prog_prev/
            // dtf_prog_at) TIDAK di extra_info — pakai kolom khusus yg di-set di atas.
            if ($dtfAvg !== null) {
                try {
                    $exCur = db()->prepare("SELECT extra_info FROM printers WHERE id=?");
                    $exCur->execute([$printerId]);
                    $exCurRaw = (string)$exCur->fetchColumn();
                    $exCurA = json_decode($exCurRaw, true);
                    if (!is_array($exCurA)) { $exCurA = []; }
                    $exCurA['dtf_avg_sec'] = round($dtfAvg, 1);
                    $setP[] = "extra_info=?";
                    $prmP[] = json_encode($exCurA, JSON_UNESCAPED_UNICODE);
                } catch (Throwable $e) {
                    // abaikan — avg_sec opsional
                }
            }
            // ===== FILM SELESAI → MESIN BALIK IDLE (revisi 2026-09-10) =====
            // Minta user: begitu cetak tuntas, kartu printer kembali "Idle" &
            // hitungan lembar direset (0), biar konsisten dgn RYPC dan tidak
            // menggantung di "1 dari 1 lembar film". Nota TETAP menampilkan tahap
            // "Menunggu Press ke baju" karena bersumber dari orders.press_wa_at
            // (dependen dari order, bukan dari kartu printer).
            //
            // PENTING: reset di sini HARUS SELALU ditulis (bukan sekali saja).
            // Sebab blok UPDATE sebelumnya ($sql, "sumber utama") tetap menulis
            // print_status='Print' + current_order=... karena agent masih
            // melaporkan order itu. Kalau reset dilewati (mis. utk hemat tulis),
            // nilai Print dari blok atas itulah yg bertahan → kartu balik nyangkut
            // di "Print 100%". Urutan eksekusi: blok atas dulu, lalu blok ini →
            // jadi blok inilah yg menang & wajib konsisten menulis.
            if (!empty($idleReset)) {
                $setP = ["current_order=''", "progress=0", "remaining_time='00:00:00'",
                         "total_pages=NULL", "printed_pages=NULL", "print_status='Idle'"];
                $prmP = [];
                // Catat log hanya saat TRANSISI (jangan banjiri log tiap heartbeat
                // 5 detik): transisi = snapshot awal masih memegang order ini.
                if ($prevOrderSnap === $order) {
                    writeLog("DTF-SELESAI: printer #$printerId balik Idle (order #$order film selesai)", 'INFO');
                }
            }
            if ($setP) {
                $upP .= implode(", ", $setP) . " WHERE id=?";
                $prmP[] = $printerId;
                db()->prepare($upP)->execute($prmP);
                writeLog("DTF-OVERRIDE: printer #$printerId prog=$dtfProg remain={$dtfRemain}s done=$dtfDone target=$dtfTarget", 'INFO');
            }

            // ===== NOTIF WA GRUP: FILM DTF SELESAI → WAJIB PRESS =====
            // Saat SEMUA lembar film selesai (done >= target) utk order sablon,
            // kirim notif sekali ke grup CG Crew File (guard press_wa_at) supaya
            // semua admin baca: "order selesai dicetak, wajib di-press ke baju".
            if ($dtfTarget > 0 && $dtfDone >= $dtfTarget && $order !== '') {
                try {
                    // Guard anti-dobel: cek apakah sudah pernah kirim utk order ini
                    $wChk = db()->prepare("SELECT press_wa_at, status, press_menit_per_baju FROM orders WHERE order_number=? LIMIT 1");
                    $wChk->execute([$order]);
                    $wRow = $wChk->fetch();
                    $pressWaAt = $wRow ? $wRow['press_wa_at'] : null;
                    $ordStatus = $wRow ? (string)$wRow['status'] : '';
                    if (!$pressWaAt && in_array($ordStatus, ['finishing', 'cetak'], true)) {
                        // Ambil qty baju (SUM item sablon/kaos) & menit press
                        $bajuTotal = 0;
                        try {
                            $qB = db()->prepare("SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE o.order_number=?");
                            $qB->execute([$order]);
                            $bajuTotal = (int)round((float)$qB->fetchColumn());
                        } catch (Throwable $e) { $bajuTotal = 0; }
                        $pressMenit = (int)($wRow['press_menit_per_baju'] ?? 0);
                        if ($pressMenit <= 0) $pressMenit = max(1, (int)getSetting('press_menit_per_baju', '3'));
                        $estMin = $bajuTotal > 0 ? ($bajuTotal * $pressMenit) : 0;
                        $estTxt = $estMin > 0
                            ? (($estMin >= 60) ? sprintf('%d jam %d mnt', intdiv($estMin, 60), $estMin % 60) : ($estMin . ' mnt'))
                            : '';
                        // Grup CG Crew File (dari Pengaturan monitor_notif_groups;
                        // fallback ke ID grup yg dikenal)
                        // Grup tujuan: setting 'monitor_notif_groups' (bisa berisi
                        // banyak @g.us dipisah koma). Kalau kosong, fallback ke grup
                        // CG Crew File (ID dikenal).
                        $gid = trim((string)getSetting('monitor_notif_groups', ''));
                        if ($gid === '') {
                            $gid = '120363028738145649@g.us'; // CG Crew File
                        }
                        $gids = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $gid) ?: []), static fn($g) => strpos($g, '@') !== false));
                        if ($gids) {
                            $bajuTxt = $bajuTotal > 0 ? " ($bajuTotal baju" . ($estTxt !== '' ? ", estimasi press ±$estTxt" : '') . ')' : '';
                            $msg = "🖨️ ORDERAN *#$order* SELESAI DICETAK FILM DTF" . $bajuTxt . ".\n\n⚠️ *Wajib di-press ke baju* ya Crew! Jangan sampai ketunda.\n\nTerima kasih 🙏";
                            $sentCnt = 0;
                            foreach ($gids as $g) {
                                if (function_exists('sendWhatsAppToChat') && sendWhatsAppToChat($g, $msg)) $sentCnt++;
                            }
                            writeLog("DTF-WA-GRUP: order #$order film selesai → WA ke grup (" . $sentCnt . '/'. count($gids) . ')', 'INFO');
                            // Tandai sudah kirim (guard) walau gagal sebagian — cegah spam
                            db()->prepare("UPDATE orders SET press_wa_at=NOW() WHERE order_number=? AND press_wa_at IS NULL")->execute([$order]);
                        }
                    }
                } catch (Throwable $e) {
                    writeLog('printer_heartbeat dtf-wa-grup error: ' . $e->getMessage(), 'ERROR');
                }
            }
        } catch (Throwable $e) {
            writeLog('printer_heartbeat dtf override error: ' . $e->getMessage(), 'ERROR');
        }
    }

    // ===== CATAT RINWAYAT PER LEMBAR (dtf_sheet_log) — 2026-09-10 =====
    // Agent DTF mengirim array event lembar yg baru selesai/gagal:
    //   dtf_sheet_events: [{order_number, status:'selesai'|'gagal'|'dibatalkan',
    //     event_uid (SIDIK JARI FISIK: "<order>|<file>|<urutan End ke-N>"),
    //     start_at (ISO/WITA), end_at, duration_sec, bytes_sent, cause}]
    // Server catat permanen ke dtf_sheet_log — app TIDAK bergantung file log DF
    // (yg bisa hilang/ter-rotate). Deteksi by LOG (done/aborted), bukan ukuran.
    //
    // ===== FIX 2026-09-12: NOMOR LEMBAR DITENTUKAN SERVER, BUKAN AGENT =====
    // BUG NYATA (order CG1109267733): agent kehilangan state saat restart ->
    // nomor lembar yg dikirim mundur (4 -> 3) sehingga `COUNT(DISTINCT sheet_no)`
    // mentok di 4, PADAHAL 5 film fisikal sudah jadi. Nota jadi salah ("4/5")
    // dan order tak pernah dianggap tuntas.
    // Sekarang:
    //   1. `event_uid` = sidik jari FISIK dari agent; UNIQUE di DB. Event yg
    //      sama (file lama dibaca ulang) DITOLAK -> tidak ada baris dobel.
    //   2. `sheet_no` = MAX(sheet_no)+1 utk order itu. STRICTLY NAIK walau agent
    //      restart, karena yg menghitung server (DB permanen). Jadi lembar ke-5
    //      mengembalikan sheet_no=5, bukan 3.
    $sheetEvents = isset($input['dtf_sheet_events']) && is_array($input['dtf_sheet_events']) ? $input['dtf_sheet_events'] : [];
    if ($sheetEvents && $printerId > 0) {
        // Nomor lembar berikutnya utk order ini (server-side, permanen).
        $qMaxSheet = db()->prepare("SELECT COALESCE(MAX(sheet_no), 0) FROM dtf_sheet_log WHERE order_number = ?");
        $insSh = db()->prepare("INSERT INTO dtf_sheet_log
            (printer_id, event_uid, order_number, sheet_no, file_name, status, start_at, end_at, duration_sec, bytes_sent, cause)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $qMaxSheetByOrd = [];
        foreach ($sheetEvents as $ev) {
            if (!is_array($ev)) continue;
            $evOrd = trim((string)($ev['order_number'] ?? $order ?? ''));
            if ($evOrd === '') continue;
            $evStatus = in_array(($ev['status'] ?? ''), ['selesai', 'gagal', 'dibatalkan'], true) ? $ev['status'] : 'selesai';
            // ---- 1. TOLAK DUPLIKAT (file lama dipindai ulang) ----
            $evUid = isset($ev['event_uid']) ? mb_substr(trim((string)$ev['event_uid']), 0, 190) : null;
            if ($evUid !== null && $evUid !== '') {
                try {
                    $qDup = db()->prepare("SELECT id FROM dtf_sheet_log WHERE event_uid = ? LIMIT 1");
                    $qDup->execute([$evUid]);
                    if ($qDup->fetchColumn()) {
                        continue; // sudah tercatat — jangan dobel
                    }
                } catch (Throwable $eDup) {
                    // kolom mungkin belum ada (deploy lama) — lanjut seperti biasa
                }
            }
            // ---- 2. NOMOR LEMBAR DARI SERVER (monotonik) ----
            $evSheet = null;
            if ($evStatus === 'selesai') {
                try {
                    if (!array_key_exists($evOrd, $qMaxSheetByOrd)) {
                        $qMaxSheet->execute([$evOrd]);
                        $qMaxSheetByOrd[$evOrd] = (int)$qMaxSheet->fetchColumn();
                    }
                    $qMaxSheetByOrd[$evOrd] += 1;
                    $evSheet = $qMaxSheetByOrd[$evOrd];
                } catch (Throwable $eS) {
                    $evSheet = isset($ev['sheet_no']) ? (int)$ev['sheet_no'] : null;
                }
            } else {
                // Gagal/dibatalkan: jangan pakai nomor lembar (biar tidak bentrok
                // dgn lembar sah, dan tidak menaikkan hitungan).
                $evSheet = null;
            }
            try {
                $insSh->execute([
                    $printerId,
                    ($evUid !== null && $evUid !== '') ? $evUid : null,
                    $evOrd,
                    $evSheet,
                    isset($ev['file_name']) ? mb_substr((string)$ev['file_name'], 0, 200) : null,
                    $evStatus,
                    !empty($ev['start_at']) ? $ev['start_at'] : null,
                    !empty($ev['end_at']) ? $ev['end_at'] : null,
                    isset($ev['duration_sec']) ? (int)$ev['duration_sec'] : null,
                    isset($ev['bytes_sent']) ? (int)$ev['bytes_sent'] : null,
                    isset($ev['cause']) ? mb_substr((string)$ev['cause'], 0, 255) : null,
                ]);
                writeLog("DTF-SHEET-LOG: #{$evOrd} {$evStatus}" . ($evSheet !== null ? " sheet={$evSheet}" : "") . (isset($ev['duration_sec']) ? " dur={$ev['duration_sec']}s" : ''), 'INFO');
            } catch (Throwable $e2) {
                writeLog('printer_heartbeat dtf_sheet_log error: ' . $e2->getMessage(), 'ERROR');
            }
        }
    }

    echo json_encode(['success' => true, 'printer_id' => $printerId]);
} catch (Throwable $e) {
    writeLog('printer_heartbeat error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server']);
}
