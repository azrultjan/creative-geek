<?php
/**
 * public/cg_monitor.php
 * =====================================================================
 * MONITOR — Dashboard pantau mesin cetak (printer) secara realtime.
 * - Daftar printer + order aktif + progress.
 * - Tombol SCAN untuk deteksi printer di jaringan.
 * - Auto-refresh AJAX tiap 5 detik.
 * (Server/host dipindah ke server_monitor.php)
 * =====================================================================
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireMenuAccess('cg_monitor');

$pageTitle  = getSetting('menu_label_monitor', 'Printer');
$activeMenu = 'cg_monitor';
$hidePageHeading = true;   // sembunyikan heading default (pakai heading sendiri)

$company = getCompanyData();

// ===== AJAX: kirim data printer sebagai JSON (untuk auto-refresh) =====
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    // Halaman Printer = khusus MESIN CETAK. Host/server/NAS/router TIDAK di sini.
    $list = db()->query("
        SELECT id, name, ip, type, status, current_order, progress, progress_stalled_at,
               remaining_time, print_status, extra_info, total_pages, printed_pages,
               TIMESTAMPDIFF(SECOND, last_heartbeat, NOW()) AS secs_ago
        FROM printers
        WHERE type IN ('uv','epson','inkjet','canon','hp','laser','other')
        ORDER BY id ASC
    ")->fetchAll();
    // Map info press per current_order (utk tombol/status DTF press)
    $pressOrderMap = [];
    $coAjList = [];
    foreach ($list as $lp) { $c = trim((string)($lp['current_order'] ?? '')); if ($c !== '') $coAjList[] = $c; }
    if ($coAjList) {
        try {
            $coAjUnique = array_unique($coAjList);
            $ph = implode(',', array_fill(0, count($coAjUnique), '?'));
            $qP = db()->prepare("SELECT order_number, status, dtf_per_lembar, is_dtf, press_menit_per_baju, press_started_at, press_wa_at, press_paused_at FROM orders WHERE order_number IN ($ph)");
            $qP->execute($coAjUnique);
            foreach ($qP->fetchAll() as $r) { $pressOrderMap[$r['order_number']] = $r; }
        } catch (Throwable $e) { /* opsional */ }
    }
    $out = [];
    foreach ($list as $p) {
        $secs = $p['secs_ago'] === null ? 999 : (int)$p['secs_ago'];
        // Heartbeat datang tiap ~60 detik (relay Mac) / ~10 detik (monitor exe).
        // Ambang offline 120 detik agar tidak "kedip Offline" di antara heartbeat.
        $threshold = 120;
        // Berapa menit progress sudah macet (klaim Print tapi progress tidak naik).
        $stalledMin = 0;
        if (!empty($p['progress_stalled_at'])) {
            $stalledMin = (int)floor((time() - strtotime((string)$p['progress_stalled_at'])) / 60);
        }
        $coAj = trim((string)$p['current_order']);
        $isDtfAj = false;
        if (!empty($p['extra_info'])) {
            $epAj = json_decode((string)$p['extra_info'], true);
            if (is_array($epAj) && !empty($epAj['dtf'])) $isDtfAj = true;
        }
        $orderStatAj = '';
        $pressStartAj = null;
        $pressPausedAj = null;
        if ($coAj !== '' && isset($pressOrderMap[$coAj])) {
            $orderStatAj = (string)($pressOrderMap[$coAj]['status'] ?? '');
            $pressStartAj = $pressOrderMap[$coAj]['press_started_at'];
            $pressPausedAj = $pressOrderMap[$coAj]['press_paused_at'];
            if (!empty($pressOrderMap[$coAj]['is_dtf']) || !empty($pressOrderMap[$coAj]['dtf_per_lembar'])) $isDtfAj = true;
        }
        $out[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'ip' => $p['ip'],
            'type' => $p['type'],
            'status' => $p['status'],
            'current_order' => $p['current_order'],
            'progress' => (int)$p['progress'],
            'progress_stalled_at' => $p['progress_stalled_at'],
            'stalled_min' => $stalledMin,
            'remaining_time' => $p['remaining_time'],
            'print_status' => $p['print_status'],
            'extra_info' => (string)($p['extra_info'] ?? ''),
            'total_pages' => isset($p['total_pages']) ? (int)$p['total_pages'] : 0,
            'printed_pages' => isset($p['printed_pages']) ? (int)$p['printed_pages'] : 0,
            'is_offline' => ($secs > $threshold),
            'secs_display' => $secs >= 999 ? 'belum pernah' : ($secs . ' dtk lalu'),
            'secs_ago' => $secs,
            'is_dtf' => $isDtfAj,
            'order_status' => $orderStatAj,
            'press_started_at' => $pressStartAj,
            'press_paused_at' => $pressPausedAj,
        ];
    }
    // Gabung printer jaringan duplikat → 1 (update realtime konsisten dgn render).
    $out = cgDedupeNetworkPrinters($out);
    // Buang printer yang DISEMBUNYIKAN (kecuali ?show_hidden=1).
    $hIdsAj = cg_hidden_printer_ids();
    if ($hIdsAj) {
        $showHidAj = !empty($_GET['show_hidden']);
        $out = array_values(array_filter($out, function ($x) use ($hIdsAj, $showHidAj) {
            if (!in_array((int)$x['id'], $hIdsAj, true)) return true;
            return $showHidAj;
        }));
    }
    echo json_encode(['success' => true, 'printers' => $out]);
    exit;
}

// Ambil semua printer (khusus MESIN CETAK — bukan host/server/nas/router)
$printers = db()->query("
    SELECT id, name, ip, type, location, status, current_order, progress, progress_stalled_at,
           remaining_time, print_status, last_heartbeat, extra_info, total_pages, printed_pages,
           TIMESTAMPDIFF(SECOND, last_heartbeat, NOW()) AS secs_ago
    FROM printers
    WHERE type IN ('uv','epson','inkjet','canon','hp','laser','other')
    ORDER BY id ASC
")->fetchAll();

foreach ($printers as &$p) {
    $secs = $p['secs_ago'] === null ? 999 : (int)$p['secs_ago'];
    $p['is_offline'] = ($secs > 120);
    $p['secs_display'] = $secs >= 999 ? 'belum pernah' : ($secs . ' dtk lalu');
}
unset($p);

// ===== URUTAN KARTU (drag & drop oleh Owner/Admin) =====
$printerOrder = [];
$st = db()->query("SELECT printer_id, sort_order FROM printer_layout ORDER BY sort_order ASC");
foreach ($st->fetchAll() as $r) {
    $printerOrder[(int)$r['printer_id']] = (int)$r['sort_order'];
}
if (!empty($printerOrder)) {
    usort($printers, function ($a, $b) use ($printerOrder) {
        $sa = $printerOrder[(int)$a['id']] ?? 999;
        $sb = $printerOrder[(int)$b['id']] ?? 999;
        return $sa <=> $sb;
    });
}
$canReorder = true; // halaman ini owner/admin (requireRole di atas)

// ===== PEMBERSIHAN OTOMATIS (1x/hari) + DAFTAR TERSEMBUNYI =====
// Agent PC melaporkan SEMUA printer Windows yg terpasang di PC-nya, jadi kartu
// printer yg alatnya mati/tidak dipakai tetap "Online" selamanya. Aturan user
// (11 Okt 2026): printer yg tidak heartbeat > 2 hari = romol -> hapus
// (kalau punya riwayat, cukup disembunyikan). Printer baru tetap terdeteksi
// otomatis oleh heartbeat agent.
$cleanupInfo = null;
try {
    $lastClean = (string)getSetting('printer_cleanup_last', '');
    if ($lastClean === '' || strtotime($lastClean) < strtotime('-1 day')) {
        $cleanupInfo = cg_cleanup_stale_printers(2);
        setSetting('printer_cleanup_last', date('Y-m-d H:i:s'));
    }
} catch (Throwable $e) {
    $cleanupInfo = null;
}
$hiddenIds = cg_hidden_printer_ids();
$showHidden = !empty($_GET['show_hidden']);

// ===== HANYA PRINTER (mesin cetak nyata) — server di halaman terpisah =====
$printerCards = [];
$hiddenCards = [];
$hiddenCount = 0;
foreach ($printers as $p) {
    if (in_array($p['type'], ['host', 'server'], true)) continue;
    if (in_array((int)$p['id'], $hiddenIds, true)) {
        // Disembunyikan: hanya ditampilkan kalau user minta lihat ($showHidden)
        if ($showHidden) { $p['_is_hidden'] = 1; $hiddenCards[] = $p; }
        else { $hiddenCount++; }
        continue;
    }
    $p['_is_hidden'] = 0;
    $printerCards[] = $p;
}
// Gabung printer jaringan duplikat (EPSON dll yg terdeteksi banyak PC) → 1 kartu.
$printerCards = cgDedupeNetworkPrinters($printerCards);
// Kalau user minta melihat yg tersembunyi → gabungkan (ditandai _is_hidden=1).
if ($showHidden && $hiddenCards) {
    $printerCards = array_merge($printerCards, $hiddenCards);
}

// ===== INFO PRESS BAJU per current_order (DTF sablon) =====
// Ambil sekaligus (1 query) status press utk SEMUA order yg lagi nempel di
// kartu printer → dipakai tampilkan tombol "Mulai Press" / info press.
$pressOrderMap = [];
$coList = [];
foreach ($printerCards as $p) {
    $co = trim((string)($p['current_order'] ?? ''));
    if ($co !== '') $coList[] = $co;
}
if ($coList) {
    try {
        $ph = implode(',', array_fill(0, count($coList), '?'));
        $qP = db()->prepare("SELECT order_number, status, dtf_per_lembar, is_dtf, press_menit_per_baju, press_started_at, press_wa_at, press_paused_at FROM orders WHERE order_number IN ($ph)");
        $qP->execute($coList);
        foreach ($qP->fetchAll() as $r) {
            $pressOrderMap[$r['order_number']] = $r;
        }
    } catch (Throwable $e) {
        // abaikan — info press opsional
    }
}
foreach ($printerCards as &$pc) {
    $co = trim((string)($pc['current_order'] ?? ''));
    $pc['is_dtf'] = false;
    $pc['order_status'] = '';
    $pc['press_started_at'] = null;
    $pc['press_menit_per_baju'] = null;
    $pc['press_paused_at'] = null;
    if (!empty($pc['extra_info'])) {
        $epc = json_decode((string)$pc['extra_info'], true);
        if (is_array($epc) && !empty($epc['dtf'])) $pc['is_dtf'] = true;
    }
    if ($co !== '' && isset($pressOrderMap[$co])) {
        $pc['order_status'] = (string)($pressOrderMap[$co]['status'] ?? '');
        $pc['press_started_at'] = $pressOrderMap[$co]['press_started_at'];
        $pc['press_menit_per_baju'] = $pressOrderMap[$co]['press_menit_per_baju'];
        $pc['press_paused_at'] = $pressOrderMap[$co]['press_paused_at'];
        if (!empty($pressOrderMap[$co]['is_dtf']) || !empty($pressOrderMap[$co]['dtf_per_lembar'])) $pc['is_dtf'] = true;
    }
}
unset($pc);

// ===== PANEL "PRESS BAJU" — SEMUA order sablon yg film-nya selesai =====
// Daftar order yg WAJIB di-press ke baju (tidak peduli lagi nempel di printer
// atau tidak). Sinyal "film selesai & wajib press" = orders.press_wa_at terisi
// (diset otomatis heartbeat saat film DTF tuntas + WA grup "wajib press").
// Termasuk juga order yg lagi press (press_started_at ada) walau press_wa_at
// kosong (data lama) — asal punya jejak DTF (dtf_per_lembar).
// FIX 2026-09-10: `press_menit_per_baju` DIBUANG dari kondisi jejak DTF — order
// BALIHO/SPANDUK ikut nongol di panel Press Baju hanya karena kolom itu terisi
// nilai default 3 (bug input form). Harus benar-benar order DTF.
$pressQueue = [];
try {
    $pqQ = db()->query("
        SELECT o.order_number, o.status, o.press_wa_at, o.press_started_at, o.press_paused_at,
               o.press_pause_total, o.press_menit_per_baju, o.dtf_per_lembar, o.is_dtf,
               o.order_date, o.created_at, o.updated_at,
               c.name AS customer_name, c.phone AS customer_phone,
               COALESCE((SELECT SUM(oi.qty) FROM order_items oi WHERE oi.order_id=o.id),0) AS item_qty
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.status IN ('finishing','cetak','desain','diterima')
          AND (o.press_wa_at IS NOT NULL
               OR o.press_started_at IS NOT NULL
               OR o.is_dtf = 1
               OR o.dtf_per_lembar IS NOT NULL)
        ORDER BY COALESCE(o.press_wa_at, o.press_started_at, o.updated_at) DESC
        LIMIT 60
    ");
    $pressQueue = $pqQ->fetchAll();
    foreach ($pressQueue as &$pqRow) {
        // Jumlah baju = SUM item yg mengindikasikan sablon/kaos (fallback total)
        $pqRow['baju'] = 0;
        try {
            $bQ = db()->prepare("SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE o.order_number=? AND (LOWER(oi.product_name) LIKE '%kaos%' OR LOWER(oi.product_name) LIKE '%baju%' OR LOWER(oi.product_name) LIKE '%shirt%' OR LOWER(oi.product_name) LIKE '%sablon%')");
            $bQ->execute([$pqRow['order_number']]);
            $pqRow['baju'] = (int)round((float)$bQ->fetchColumn());
        } catch (Throwable $e) { $pqRow['baju'] = 0; }
        if ($pqRow['baju'] <= 0) $pqRow['baju'] = (int)round((float)($pqRow['item_qty'] ?? 0));

        $pqRow['press_started_at_raw'] = $pqRow['press_started_at'];
        $pqRow['press_paused_at_raw']  = $pqRow['press_paused_at'];
        if ($pqRow['press_started_at'] === null || $pqRow['press_started_at'] === '0000-00-00 00:00:00') $pqRow['press_started_at'] = null;
        if ($pqRow['press_paused_at'] === null || $pqRow['press_paused_at'] === '0000-00-00 00:00:00') $pqRow['press_paused_at'] = null;
        $pqRow['menit_per_baju'] = (int)($pqRow['press_menit_per_baju'] ?? 0);
        if ($pqRow['menit_per_baju'] <= 0) $pqRow['menit_per_baju'] = max(1, (int)getSetting('press_menit_per_baju', '3'));
        $pqRow['total_menit'] = $pqRow['baju'] * $pqRow['menit_per_baju'];
        // Status logika
        $pqRow['st_paused']  = ($pqRow['press_started_at'] !== null && $pqRow['press_paused_at'] !== null);
        $pqRow['st_running'] = ($pqRow['press_started_at'] !== null && $pqRow['press_paused_at'] === null);
        $pqRow['st_waiting'] = ($pqRow['press_started_at'] === null);
    }
    unset($pqRow);
} catch (Throwable $e) {
    $pressQueue = []; // gagal query → panel kosong (opsional, tidak fatal)
}

// ===== GABUNG PRINTER JARINGAN DUPLIKAT (FIX 2026-09-04) =====
// 1 printer fisik jaringan (mis. EPSON L15150) bisa terdeteksi dari BANYAK PC
// (tiap PC punya driver dgn nama Windows beda: "L15150 Series", "Copy 2",
// "EPSON7FF2D8"). Akibatnya muncul 2-3 kartu utk 1 printer yg sama.
// Solusi: gabung by MODEL (nama dinormalisasi, buang "Copy N"/serial) — tampilkan
// SATU entri per model: pilih yg SEDANG AKTIF cetak (Print), atau heartbeat
// terbaru kalau semua idle. UV (RYPC) & printer yg modelnya unik tetap semua.
function cgNormalizePrinterModel(string $name): string
{
    $n = mb_strtolower($name);
    // Buang penanda duplikat Windows: "(copy 2)"
    $n = preg_replace('/\s*\(copy\s*\d+\)/i', '', $n);
    // Pola "EPSON7FF2D8 (L15150 Series)" / "XXXX (Model)" -> ambil isi kurung (model)
    if (preg_match('/^[a-z0-9]{4,}\s*\((.+)\)\s*$/', $n, $m)) {
        $n = $m[1];
    }
    // Buang sisa kurung lain
    $n = preg_replace('/\s*\(.+\)\s*$/', '', $n);
    // Buang kata generik yg bikin beda padahal model sama: epson, series, printer, scanner, inkjet
    $n = preg_replace('/\b(epson|series|printer|printers|scanner|inkjet|all-in-one|aio)\b/', ' ', $n);
    $n = preg_replace('/\s+/', ' ', $n);
    return trim($n);
}

function cgDedupeNetworkPrinters(array $rows): array
{
    // Kelompokkan berdasarkan (type, model normal)
    $groups = [];
    foreach ($rows as $p) {
        if (!in_array($p['type'], ['epson', 'inkjet', 'canon', 'hp', 'laser'], true)) {
            $groups['unique_' . $p['id']][] = $p; // UV & lain: jangan gabung
            continue;
        }
        $model = cgNormalizePrinterModel((string)($p['name'] ?? ''));
        if ($model === '') { $groups['unique_' . $p['id']][] = $p; continue; }
        $groups['m_' . $p['type'] . '_' . $model][] = $p;
    }
    $out = [];
    foreach ($groups as $list) {
        if (count($list) === 1) { $out[] = $list[0]; continue; }
        // Banyak entri model sama → pilih yg paling relevan:
        // 1) yg print_status aktif (Print/printing), 2) kalau semua idle → heartbeat terbaru.
        usort($list, function ($a, $b) {
            $rankA = cgPrintActiveRank($a);
            $rankB = cgPrintActiveRank($b);
            if ($rankA !== $rankB) return $rankB <=> $rankA;
            $sa = (int)($a['secs_ago'] ?? 999);
            $sb = (int)($b['secs_ago'] ?? 999);
            return $sa <=> $sb;
        });
        $out[] = $list[0];
    }
    return $out;
}

function cgPrintActiveRank(array $p): int
{
    $ps = mb_strtolower((string)($p['print_status'] ?? ''));
    if (strpos($ps, 'print') !== false || strpos($ps, 'mencetak') !== false || strpos($ps, 'cetak') !== false) return 4;
    if (strpos($ps, 'pause') !== false) return 3;
    if (strpos($ps, 'stop') !== false) return 2;
    return 1; // idle/done/lain
}

// ===== LOG AUDIT PROGRESS MACET (anti-kecurangan) =====
// Riwayat progress yang "nge-stuck" (tidak naik dalam waktu lama saat Print).
$stallLogs = [];
try {
    $stallLogs = db()->query(
        "SELECT l.*, p.print_status AS current_status
         FROM printer_stall_logs l
         LEFT JOIN printers p ON p.id = l.printer_id
         ORDER BY l.stalled_at DESC
         LIMIT 30"
    )->fetchAll();
} catch (\Throwable $e) {
    $stallLogs = [];
}

// Tanggal WITA (server) utk default filter laporan — JS pakai UTC yang bisa
// beda sehari, jadi ambil dari PHP agar konsisten dgn data.
$todayWita = date('Y-m-d');
$monthWita = date('Y-m');

require __DIR__ . '/../includes/header.php';
?>

<?php menu_tab_render('cg_monitor'); ?>

<div class="page-heading cg-page-head">
    <div>
        <h1 class="page-heading-title"><i class="fas fa-print"></i> <?= e($pageTitle) ?></h1>
        <p class="page-heading-sub">Pantau mesin cetak &amp; order aktif secara realtime.</p>
    </div>
</div>

<!-- ===== TOOLBAR: SCAN PRINTER ===== -->
<div class="toolbar">
    <div class="scan-box">
        <label class="lbl-inline">Subnet:</label>
        <input type="text" id="subnetInput" class="subnet-input" value="" placeholder="otomatis" title="Kosongkan = deteksi otomatis dari IP server">
        <button type="button" class="btn-scan" id="btnScan"><i class="fas fa-magnifying-glass"></i> SCAN PRINTER</button>
        <button type="button" class="btn-sync-all" id="btnSyncAll"><i class="fas fa-rotate"></i> SYNC SEMUA</button>
        <span class="scan-status" id="scanStatus"></span>
    </div>
</div>

<!-- ===== KARTU PRINTER (mesin cetak nyata) ===== -->
<div class="cg-section-head">
    <h2 class="cg-section-title"><i class="fas fa-print"></i> Printer</h2>
    <span class="cg-section-count"><?= count($printerCards) ?> mesin</span>
    <?php if ($hiddenCount > 0): ?>
        <a class="cg-hidden-toggle" href="?show_hidden=1"
           title="Tampilkan printer yang disembunyikan (mis. printer tidak dipakai yang tetap dilaporkan agent PC)">
            <i class="fas fa-eye"></i> <?= (int)$hiddenCount ?> disembunyikan
        </a>
    <?php elseif ($showHidden): ?>
        <a class="cg-hidden-toggle" href="?" title="Sembunyikan lagi printer yang tidak dipakai">
            <i class="fas fa-eye-slash"></i> sembunyikan lagi
        </a>
    <?php endif; ?>
    <button type="button" class="btn-act btn-cleanup" id="btnCleanup"
            title="Hapus printer yang tidak terdeteksi/lapor lebih dari 2 hari">
        <i class="fas fa-broom"></i>
    </button>
</div>
<div class="printer-grid" id="printerGrid">
    <?php foreach ($printerCards as $p): ?>
    <?php
        $cardClass = $p['is_offline'] ? 'printer-card offline' : 'printer-card online';
        $statusClass = $p['is_offline'] ? 'text-danger' : 'text-success';
        $statusText = $p['is_offline'] ? 'Offline' : 'Online';
        $typeLabel = $p['type'] === 'epson' ? 'Epson' : ($p['type'] === 'uv' ? 'UV Printer' : 'Printer');
        // Semua printer → icon printer (konsisten, tidak aneh)
        $icon = 'fa-print';
        $prog = (int)$p['progress'];
        // ===== PROGRESS PER LEMBAR (printer konvensional) =====
        // Kalau total_pages ada → progress = printed_pages / total_pages * 100.
        // Kalau TIDAK ada (mis. cetak dari Mac tanpa data lembar) → tampilkan '—'
        // (jangan angka 0% palsu / tebakan).
        $tp = (int)($p['total_pages'] ?? 0);
        $pp = (int)($p['printed_pages'] ?? 0);
        $pagesText = '';
        if ($tp > 0) {
            $calc = min(100, (int)round($pp * 100 / $tp));
            if ($prog === 0) $prog = $calc; // pakai hitungan lembar kalau belum ada
            $pagesText = ' · ' . $pp . '/' . $tp . ' lbr';
        } elseif ($p['type'] === 'uv') {
            // RYPC UV: progress = dari UI mesin (kolom progress) — data nyata.
            // Tampilkan '—' HANYA saat mesin idle/unknown (tidak ada cetakan).
            // Saat Pause/Print/Stop, tampilkan progress apa adanya (0% pun
            // tetap tampil) supaya bar & angka tidak hilang saat jeda.
            $psLow = mb_strtolower((string)($p['print_status'] ?? ''));
            $activeNow = in_array($psLow, ['print', 'printing', 'mencetak', 'cetak', 'running', 'pause', 'stop'], true);
            if ($prog <= 0 && !$activeNow) $prog = -1;
        } else {
            $prog = -1; // tanda: tidak ada data lembar → tampil '—'
        }
        // ===== WARNA PROGRESS SESUAI STATUS CETAK =====
        // Pakai variabel global CSS (sync dgn tema). print=hijau, pause=kuning,
        // stop/abort=merah, done=hijau, idle/default=biru/emas.
        $stKey = strtolower((string)($p['print_status'] ?? ''));
        if (strpos($stKey, 'print') !== false) $fillClass = 'st-print';
        elseif (strpos($stKey, 'pause') !== false) $fillClass = 'st-pause';
        elseif (strpos($stKey, 'stop') !== false || strpos($stKey, 'abort') !== false || strpos($stKey, 'cancel') !== false) $fillClass = 'st-stop';
        elseif (strpos($stKey, 'done') !== false || strpos($stKey, 'finish') !== false) $fillClass = 'st-done';
        elseif (strpos($stKey, 'idle') !== false) $fillClass = 'st-idle';
        else $fillClass = 'st-default';
        // ===== ANTI-KECURANGAN: PROGRESS MACET =====
        // Klaim Print tapi progress tidak naik (progress_stalled_at lama).
        $isStalled = false;
        $stalledMin = 0;
        if (in_array($stKey, ['print', 'printing', 'mencetak', 'cetak', 'running'], true)
            && !empty($p['progress_stalled_at'])) {
            $stalledMin = (int)floor((time() - strtotime((string)$p['progress_stalled_at'])) / 60);
            if ($stalledMin >= 3 && $prog > 0) {
                $isStalled = true; // progress macet > 3 menit (tidak naik)
            } elseif ($stalledMin >= 5) {
                $isStalled = true; // klaim cetak tapi progress 0 > 5 menit
            }
        }
        $isRealPrinter = true;
    ?>
    <div class="<?= $cardClass ?>" data-id="<?= (int)$p['id'] ?>">
        <div class="printer-card-head">
            <?php if ($canReorder): ?><span class="printer-drag" title="Seret untuk pindah posisi kartu"><i class="fas fa-grip-vertical"></i></span><?php endif; ?>
            <i class="fas <?= $icon ?> printer-icon"></i>
            <div class="printer-meta">
                <div class="printer-name" title="<?= e($p['name']) ?>"><?= e($p['name']) ?></div>
                <div class="printer-sub-row">
                    <div class="printer-sub" title="<?= e($p['ip']) ?> · <?= e($typeLabel) ?>"><?= e($p['ip']) ?> · <?= e($typeLabel) ?></div>
                    <div class="printer-status <?= $statusClass ?>">● <?= $statusText ?></div>
                </div>
            </div>
            <?php if (!empty($p['_is_hidden'])): ?>
                <button type="button" class="btn-hide btn-unhide" data-id="<?= (int)$p['id'] ?>"
                        title="Tampilkan kembali printer ini di monitor"><i class="fas fa-eye"></i></button>
            <?php else: ?>
                <button type="button" class="btn-hide" data-id="<?= (int)$p['id'] ?>"
                        title="Sembunyikan dari monitor (printer tidak dipakai) — data tetap tersimpan"><i class="fas fa-eye-slash"></i></button>
            <?php endif; ?>
        </div>
        <div class="printer-card-body">
            <div class="printer-order">
                <span class="lbl">Order Aktif</span>
                <?php
                // Tampilkan SEMUA order yg sedang dicetak (multi-order RYPC).
                // Sumber: extra_info.orders_list (dipisah koma) — lebih lengkap
                // dari current_order (cuma 1). Fallback ke current_order.
                $orderList = [];
                $extraOrders = [];
                // Kalau printer tidak sedang cetak (Idle/Online/Done) → jangan
                // tampilkan order lama yang menempel di orders_list/current_order.
                $psLow = mb_strtolower((string)($p['print_status'] ?? ''));
                $isPrintingNowUi = in_array($psLow, ['print', 'printing', 'mencetak', 'cetak', 'running', 'pause', 'stop'], true);
                if ($isPrintingNowUi) {
                    if (!empty($p['extra_info'])) {
                        $ep = json_decode((string)$p['extra_info'], true);
                        if (is_array($ep) && !empty($ep['orders_list'])) {
                            $extraOrders = array_values(array_filter(array_map('trim', explode(',', (string)$ep['orders_list']))));
                        }
                    }
                    $orderList = $extraOrders ?: ($p['current_order'] !== '' ? [$p['current_order']] : []);
                }
                ?>
                <?php if (count($orderList) > 1): ?>
                    <span class="val" style="font-size:13px;"><?= e(implode(', ', $orderList)) ?></span>
                <?php else: ?>
                    <span class="val"><?= !empty($orderList) ? e($orderList[0]) : '—' ?></span>
                <?php endif; ?>
            </div>
            <div class="progress-track">
                <div class="progress-fill <?= $fillClass ?>" style="width: <?= ($prog === -1) ? 0 : min(100, $prog) ?>%"></div>
            </div>
            <div class="printer-row">
                <span class="lbl">Progress</span>
                <?php $progDisplay = ($prog === -1) ? '—' : ($prog . '%' . e($pagesText)); ?>
                <span class="val gold"><?= $progDisplay ?></span>
                <span class="lbl">Sisa</span>
                <span class="val"><?= e($p['remaining_time']) ?></span>
                <span class="lbl">Status</span>
                <?php
                // Saat printer OFFLINE, print_status di DB itu basi (mis. "Idle"
                // dari set manual) — tampilkan "Offline" jujur, bukan status lama.
                $statusValCard = $p['print_status'] !== '' ? $p['print_status'] : '—';
                $statusValCard = $p['is_offline'] ? 'Offline' : $statusValCard;
                $statusFillClass = $p['is_offline'] ? 'st-off' : $fillClass;
                ?>
                <span class="val st-<?= $statusFillClass ?>"><?= e($statusValCard) ?></span>
            </div>
            <?php if ($isStalled): ?>
            <div class="printer-stall-warn" title="Progress tidak naik selama <?= (int)$stalledMin ?> menit padahal status Print — kemungkinan mesin tidak benar-benar berjalan / tidak ada update progress.">
                <i class="fas fa-triangle-exclamation"></i> Progress macet <?= (int)$stalledMin ?> mnt — cek mesin!
            </div>
            <?php endif; ?>
            <div class="printer-heartbeat">
                <i class="fas fa-heartbeat"></i> <?= e($p['secs_display']) ?>
            </div>
            <?php if (!empty($p['extra_info'])): ?>
                <?php
                $extraParsed = json_decode((string)$p['extra_info'], true);
                $extraText = '';
                if (is_array($extraParsed)) {
                    if (!empty($extraParsed['hostname'])) {
                        $extraText = 'Host: ' . $extraParsed['hostname'];
                    }
                    if (isset($extraParsed['uptime_seconds'])) {
                        $u = (int)$extraParsed['uptime_seconds'];
                        $extraText .= ($extraText !== '' ? ' · ' : '') . 'Aktif ' . gmdate('H:i:s', $u) . ' jam';
                    }
                    if (isset($extraParsed['cpu_percent'])) {
                        $extraText .= ($extraText !== '' ? ' · ' : '') . 'CPU ' . (int)$extraParsed['cpu_percent'] . '%';
                    }
                    if (isset($extraParsed['mem_mb'])) {
                        $extraText .= ($extraText !== '' ? ' · ' : '') . 'RAM ' . round((int)$extraParsed['mem_mb'] / 1024, 1) . ' GB';
                    }
                    if ($extraText === '' && !empty($extraParsed['docker'])) {
                        $extraText = $extraParsed['docker'];
                    }
                } else {
                    $extraText = (string)$p['extra_info'];
                }
                ?>
                <?php if ($extraText !== ''): ?>
                <div class="printer-extra" title="<?= e((string)$p['extra_info']) ?>">
                    <i class="fas fa-circle-info"></i> <?= e($extraText) ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php
            // ===== STATUS / AKSI PRESS BAJU (DTF sablon) =====
            // Tampil hanya utk printer DTF yg lagi nempel order sablon (is_dtf).
            // Alur: film selesai (Done/Print selesai) → tombol "Mulai Press".
            // Sudah press (press_started_at) → label "Sedang Press" + tombol
            // Complete utk selesaikan total. Info press diambil dari orders map.
            $isDtfCard = !empty($p['is_dtf']);
            $curOrderCard = trim((string)($p['current_order'] ?? ''));
            $ordStatusCard = (string)($p['order_status'] ?? '');
            $pressStartCard = $p['press_started_at'] ?? null;
            $pressMenitCard = (int)($p['press_menit_per_baju'] ?? 0);
            if ($pressMenitCard <= 0) $pressMenitCard = max(1, (int)getSetting('press_menit_per_baju', '3'));
            $psCard = mb_strtolower((string)($p['print_status'] ?? ''));
            $showPressBlock = $isDtfCard && $curOrderCard !== ''
                && !in_array($ordStatusCard, ['siap_ambil', 'batal'], true);
            if ($showPressBlock):
                $filmDoneCard = in_array($psCard, ['done', 'idle'], true);
                $pressPausedCardRaw = $p['press_paused_at'] ?? null;
                $pressActiveCard = ($pressStartCard !== null && $pressStartCard !== '' && $pressStartCard !== '0000-00-00 00:00:00');
                $pressPausedCard = $pressActiveCard && ($pressPausedCardRaw !== null && $pressPausedCardRaw !== '' && $pressPausedCardRaw !== '0000-00-00 00:00:00');
            ?>
            <div class="press-block">
                <?php if ($pressPausedCard): ?>
                    <div class="press-status" style="color:#f59e0b;font-size:11px;margin:6px 0 2px;font-weight:700;">
                        <i class="fas fa-pause-circle"></i> Press DI-JEDA / HOLD (<?= e($curOrderCard) ?>) — lanjutkan saat siap
                    </div>
                <?php elseif ($pressActiveCard): ?>
                    <div class="press-status" style="color:var(--warning);font-size:11px;margin:6px 0 2px;">
                        <i class="fas fa-fire"></i> Press berjalan (<?= e($curOrderCard) ?>) — est. <?= (int)$pressMenitCard ?> mnt/baju
                    </div>
                <?php elseif ($filmDoneCard): ?>
                    <div class="press-status" style="color:var(--gold);font-size:11px;margin:6px 0 2px;">
                        <i class="fas fa-tshirt"></i> Film selesai — <b>wajib press ke baju</b> (<?= e($curOrderCard) ?>)
                    </div>
                <?php endif; ?>
                <div class="printer-actions" style="margin-top:4px;">
                    <button type="button" class="btn-act btn-sync" data-id="<?= (int)$p['id'] ?>" title="Cek printer online/offline">
                        <i class="fas fa-rotate"></i> Sync
                    </button>
                    <?php if (!$pressActiveCard && $filmDoneCard): ?>
                        <button type="button" class="btn-act btn-startpress" data-id="<?= (int)$p['id'] ?>" title="Mulai tahap press ke baju (order ini)">
                            <i class="fas fa-fire"></i> Mulai Press
                        </button>
                    <?php elseif ($pressPausedCard): ?>
                        <button type="button" class="btn-act btn-resumepress" data-id="<?= (int)$p['id'] ?>" title="Lanjutkan press yg di-jeda">
                            <i class="fas fa-play"></i> Lanjut
                        </button>
                    <?php elseif ($pressActiveCard): ?>
                        <button type="button" class="btn-act btn-pausepress" data-id="<?= (int)$p['id'] ?>" title="Jeda press (hold) — mis. sudah malam / di luar jam kerja">
                            <i class="fas fa-pause"></i> Jeda
                        </button>
                    <?php endif; ?>
                    <?php if ($pressActiveCard): ?>
                        <button type="button" class="btn-act btn-resetpress" data-id="<?= (int)$p['id'] ?>" title="Reset press — balik ke 'menunggu press' (utk test / salah mulai)">
                            <i class="fas fa-rotate-left"></i> Reset
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn-act btn-complete" data-id="<?= (int)$p['id'] ?>" title="Tandai order selesai manual (press selesai / semua beres)">
                        <i class="fas fa-check"></i> Complete
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div class="printer-actions">
                <button type="button" class="btn-act btn-sync" data-id="<?= (int)$p['id'] ?>" title="Cek printer online/offline">
                    <i class="fas fa-rotate"></i> Sync
                </button>
                <button type="button" class="btn-act btn-complete" data-id="<?= (int)$p['id'] ?>" title="Tandai order selesai manual">
                    <i class="fas fa-check"></i> Complete
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ===== PANEL PRESS BAJU (DTF sablon — film siap, wajib di-press) ===== -->
<?php $pressQueueCount = count($pressQueue); ?>
<div class="press-queue-panel" id="pressQueuePanel" style="<?= $pressQueueCount === 0 ? 'display:none;' : '' ?>">
    <div class="press-queue-head">
        <h3 class="press-queue-title"><i class="fas fa-tshirt"></i> Press Baju <span class="press-queue-badge"><?= $pressQueueCount ?></span></h3>
        <span class="press-queue-sub">Film selesai — wajib di-press ke baju. Bisa dikerjakan tidak berurutan / loncat order prioritas.</span>
    </div>
    <?php if ($pressQueueCount > 0): ?>
    <div class="press-queue-wrap">
        <table class="press-queue-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Baju</th>
                    <th>Status Press</th>
                    <th class="pq-aksi">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pressQueue as $pq): ?>
                <?php
                    $pqLabel = 'Menunggu press';
                    $pqIcon  = 'fa-hourglass-half';
                    $pqColor = 'var(--gold)';
                    if ($pq['st_paused']) {
                        $pqLabel = 'Di-hold';
                        $pqIcon  = 'fa-pause-circle';
                        $pqColor = '#f59e0b';
                    } elseif ($pq['st_running']) {
                        $pqLabel = 'Press berjalan';
                        $pqIcon  = 'fa-fire';
                        $pqColor = 'var(--danger,#e53935)';
                    }
                    // Estimasi sisa press utk yg berjalan (paused → beku)
                    $pqRemainTxt = '';
                    if ($pq['st_running']) {
                        $nowTs = time();
                        $startTs = (int)strtotime($pq['press_started_at_raw']);
                        $pauseTot = (int)$pq['press_pause_total'];
                        $elapsed = max(0, $nowTs - $startTs - $pauseTot);
                        $remain = max(0, $pq['total_menit'] * 60 - $elapsed);
                        $h = intdiv($remain, 3600); $m = intdiv($remain % 3600, 60);
                        $pqRemainTxt = ($h > 0 ? $h . 'j ' : '') . $m . 'm';
                    } elseif ($pq['st_paused']) {
                        $startTs = (int)strtotime($pq['press_started_at_raw']);
                        $pauseTs = (int)strtotime($pq['press_paused_at_raw']);
                        $pauseTot = (int)$pq['press_pause_total'];
                        $elapsed = max(0, $pauseTs - $startTs - $pauseTot);
                        $remain = max(0, $pq['total_menit'] * 60 - $elapsed);
                        $h = intdiv($remain, 3600); $m = intdiv($remain % 3600, 60);
                        $pqRemainTxt = ($h > 0 ? $h . 'j ' : '') . $m . 'm';
                    }
                ?>
                <tr data-order="<?= e($pq['order_number']) ?>" data-id="<?= e($pq['order_number']) ?>">
                    <td class="pq-order"><?= e($pq['order_number']) ?></td>
                    <td class="pq-cust"><?= e($pq['customer_name'] ?: '—') ?></td>
                    <td class="pq-baju"><?= (int)$pq['baju'] ?> baju</td>
                    <td class="pq-status">
                        <span class="pq-st" style="color:<?= $pqColor ?>"><i class="fas <?= $pqIcon ?>"></i> <?= $pqLabel ?><?= $pqRemainTxt !== '' ? ' · sisa ' . $pqRemainTxt : '' ?></span>
                    </td>
                    <td class="pq-aksi">
                        <div class="printer-actions" style="margin-top:0;">
                            <?php if ($pq['st_waiting']): ?>
                                <button type="button" class="btn-act btn-startpress" data-order="<?= e($pq['order_number']) ?>" title="Mulai press order ini">
                                    <i class="fas fa-fire"></i> Mulai Press
                                </button>
                            <?php elseif ($pq['st_paused']): ?>
                                <button type="button" class="btn-act btn-resumepress" data-order="<?= e($pq['order_number']) ?>" title="Lanjutkan press yg di-hold">
                                    <i class="fas fa-play"></i> Lanjut
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn-act btn-pausepress" data-order="<?= e($pq['order_number']) ?>" title="Jeda press (hold)">
                                    <i class="fas fa-pause"></i> Jeda
                                </button>
                            <?php endif; ?>
                            <?php if (!$pq['st_waiting']): ?>
                                <button type="button" class="btn-act btn-resetpress" data-order="<?= e($pq['order_number']) ?>" title="Reset — balik ke menunggu press">
                                    <i class="fas fa-rotate-left"></i> Reset
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn-act btn-complete" data-order="<?= e($pq['order_number']) ?>" title="Tandai order selesai (press selesai)">
                                <i class="fas fa-check"></i> Complete
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="press-queue-empty"><i class="fas fa-tshirt"></i> Tidak ada order sablon yg menunggu press. Semua film sudah dibereskan.</div>
    <?php endif; ?>
</div>

<!-- ===== STATISTIK CETAK HARI INI ===== -->
<div class="stats-bar" id="statsBar">
    <div class="stat-item"><span class="stat-icon"><i class="fas fa-check-circle"></i></span><span class="stat-label">Cetak Selesai</span><span class="stat-val" id="statSelesai">0</span></div>
    <div class="stat-item"><span class="stat-icon"><i class="fas fa-times-circle"></i></span><span class="stat-label">Gagal</span><span class="stat-val" id="statGagal">0</span></div>
    <div class="stat-item"><span class="stat-icon"><i class="fas fa-ruler-combined"></i></span><span class="stat-label">Total Cetak</span><span class="stat-val" id="statMeter">0 m²</span></div>
    <div class="stat-item"><span class="stat-icon"><i class="fas fa-clock"></i></span><span class="stat-label">Durasi</span><span class="stat-val" id="statDurasi">0 mnt</span></div>
    <div class="stat-item"><span class="stat-icon"><i class="fas fa-clipboard-check" style="color:var(--success);"></i></span><span class="stat-label">Sesuai Orderan</span><span class="stat-val" id="statOrder">0</span></div>
    <div class="stat-item"><span class="stat-icon"><i class="fas fa-exclamation-triangle" style="color:var(--warning);"></i></span><span class="stat-label">Di Luar Orderan</span><span class="stat-val" id="statLuar">0</span></div>
</div>

<!-- ===== LAPORAN CETAK (per hari / per bulan) ===== -->
<div class="report-panel">
    <div class="report-head">
        <h3 class="report-title"><i class="fas fa-file-lines"></i> Laporan Cetak</h3>
        <div class="report-filter">
            <select id="reportPrinter" class="report-select" title="Pilih mesin">
                <?php foreach ($printerCards as $pr): ?>
                <option value="<?= (int)$pr['id'] ?>" <?= (int)$pr['id'] === 1 ? 'selected' : '' ?>><?= e($pr['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="reportPeriode" class="report-select">
                <option value="hari">Per Hari</option>
                <option value="bulan">Per Bulan</option>
            </select>
            <input type="date" id="reportTanggal" class="report-date">
            <button type="button" class="btn-scan" id="btnReport"><i class="fas fa-search"></i> Tampilkan</button>
            <button type="button" class="btn-act btn-delete-all" id="btnDeleteAll" title="Hapus semua data laporan pada periode & tanggal terpilih"><i class="fas fa-trash"></i> Hapus Semua</button>
        </div>
    </div>
    <div class="report-table-wrap report-table-scroll">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Waktu Mulai</th>
                    <th>Mesin</th>
                    <th>Nama Order / File</th>
                    <th>Jenis</th>
                    <th>Ukuran</th>
                    <th>Luas</th>
                    <th>Durasi</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="reportBody">
                <tr><td colspan="9" class="report-empty">Pilih periode & tanggal lalu klik Tampilkan.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="report-pager">
        <button type="button" class="btn-scan" id="reportPrev"><i class="fas fa-chevron-left"></i> Sebelumnya</button>
        <span class="report-pageinfo" id="reportPageInfo"></span>
        <button type="button" class="btn-scan" id="reportNext">Berikutnya <i class="fas fa-chevron-right"></i></button>
    </div>
</div>

<!-- ===== LOG AUDIT PROGRESS MACET (anti-kecurangan) ===== -->
<div class="report-panel stall-log-panel">
    <h3 class="report-title"><i class="fas fa-clock"></i> Log Audit Progress Macet <span class="stall-badge">Anti-Kecurangan</span></h3>
    <p class="report-sub">Riwayat progress cetak yang <strong>tidak naik ≥ 5 menit</strong> saat status Print. Ini bukti kalau ada orang yang "menahan" progress biar tidak keliatan. Log otomatis tercatat dari heartbeat printer.</p>
    <?php if (empty($stallLogs)): ?>
        <div class="stall-empty">
            <i class="fas fa-shield-check"></i> Tidak ada catatan progress macet. Semua mesin berjalan normal.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table stall-log-table">
                <thead>
                    <tr>
                        <th>Printer</th>
                        <th>Order</th>
                        <th>Progress</th>
                        <th>Mulai Macet</th>
                        <th>Selesai / Pulih</th>
                        <th>Durasi</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stallLogs as $log): ?>
                        <?php
                            $resolved = !empty($log['resolved_at']);
                            $durasi   = '';
                            if ($resolved) {
                                $durasi = gmdate('H:i:s', max(0, strtotime($log['resolved_at']) - strtotime($log['stalled_at'])));
                            } else {
                                $durasi = '<span class="badge-warn">masih macet</span>';
                            }
                        ?>
                        <tr>
                            <td><?= e($log['printer_name']) ?></td>
                            <td><?= e($log['order_number'] ?: '—') ?></td>
                            <td><?= (int)$log['progress'] ?>%</td>
                            <td><?= e(date('d/m H:i', strtotime($log['stalled_at']))) ?></td>
                            <td><?= $resolved ? e(date('d/m H:i', strtotime($log['resolved_at']))) : '—' ?></td>
                            <td><?= $durasi ?></td>
                            <td>
                                <?php if ($resolved): ?>
                                    <span class="stall-status resolved"><i class="fas fa-check"></i> Pulih</span>
                                <?php else: ?>
                                    <span class="stall-status active"><i class="fas fa-exclamation-triangle"></i> Macet</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
/* Monitor — memakai design tokens global (ikut tema kustom owner) */
.cg-page-head { padding: 20px 24px 8px; margin-bottom: 0; }
.cg-page-head .page-heading-title i { color: var(--gold); margin-right: 4px; }

.toolbar { padding: 10px 24px; }
.scan-box { display: flex; align-items: center; gap: 10px; }
.lbl-inline { color: var(--text-muted); font-size: 12px; }
.subnet-input {
    background: var(--surface); border: 1px solid var(--border); color: var(--text);
    border-radius: var(--radius-sm); padding: 8px 12px; font-size: 13px; width: 140px;
}
.subnet-input:focus { outline: none; border-color: var(--gold); }
.btn-scan {
    background: var(--gold); color: var(--bg); border: none;
    border-radius: var(--radius-sm); padding: 9px 16px; font-weight: 700;
    cursor: pointer; font-size: 13px; transition: background var(--tr);
}
.btn-scan:hover { background: var(--gold-strong); }
.btn-scan:disabled { opacity: .6; cursor: not-allowed; }
.scan-status { color: var(--text-muted); font-size: 12px; }

.printer-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px; padding: 8px 24px 24px;
}
@media (max-width: 767.98px) {
    .printer-grid { grid-template-columns: 1fr; padding: 8px 12px 16px; }
    .stats-bar { padding: 12px; grid-template-columns: repeat(2, 1fr); }
    .report-panel { margin: 0 12px 16px; padding: 12px; }
    .report-head { flex-direction: column; align-items: stretch; }
    .report-filter { width: 100%; }
    .report-select, .report-date { flex: 1 1 auto; }
    .report-table-wrap { max-width: 100%; }
}

/* Area tabel laporan: batasi tinggi + scroll internal, header tetap menempel */
.report-table-scroll {
    max-height: 60vh;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
}
.report-table-scroll .report-table th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--bg-soft);
}

/* Judul section Printer / Server */
.cg-section-head {
    display: flex; align-items: center; gap: 10px;
    padding: 20px 24px 0;
}
.cg-section-head-server { margin-top: 8px; }
.cg-section-title {
    font-size: 15px; font-weight: 800; margin: 0; color: var(--text);
    text-transform: uppercase; letter-spacing: .6px;
}
.cg-section-title i { color: var(--gold); margin-right: 2px; }
.cg-section-count {
    font-size: 11px; color: var(--text-muted); font-weight: 600;
    background: var(--bg-soft); border: 1px solid var(--border);
    padding: 2px 10px; border-radius: 999px;
}
@media (max-width: 767.98px) {
    .cg-section-head { padding: 14px 12px 0; }
}

.printer-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 16px;
    transition: box-shadow var(--tr), border-color var(--tr);
}
.printer-card.online { border-left: 4px solid var(--success); }
.printer-card.offline { border-left: 4px solid var(--danger); opacity: .8; }
.printer-card-head { display: flex; align-items: center; gap: 12px; min-width: 0; }
.printer-icon { font-size: 26px; color: var(--gold); flex-shrink: 0; }
.printer-meta { flex: 1; min-width: 0; overflow: hidden; }
.printer-name { font-weight: 700; font-size: 15px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.printer-sub-row { display: flex; align-items: center; gap: 8px; min-width: 0; }
.printer-sub { color: var(--text-muted); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; min-width: 0; }
.printer-status { font-weight: 700; font-size: 12px; white-space: nowrap; flex-shrink: 0; }

/* Drag handle kartu printer (Owner/Admin) */
.printer-drag {
    cursor: grab;
    color: var(--text-faint);
    padding: 3px 7px;
    border-radius: 6px;
    font-size: 13px;
    opacity: .55;
    transition: opacity .15s ease, color .15s ease, background .15s ease;
    user-select: none;
    -webkit-user-select: none;
    flex-shrink: 0;
}
.printer-card:hover .printer-drag { opacity: 1; }
.printer-drag:hover { color: var(--gold); background: color-mix(in srgb, var(--gold) 14%, transparent); }
.printer-drag:active { cursor: grabbing; }
body.dash-sorting { cursor: grabbing; }
body.dash-sorting .printer-card { transition: none; }
.printer-ph {
    background: color-mix(in srgb, var(--gold) 08%, transparent);
    border: 2px dashed var(--gold);
    border-radius: var(--radius);
    opacity: .7;
    pointer-events: none;
    min-width: 0;
}
.printer-ghost {
    position: fixed;
    z-index: 999;
    pointer-events: none;
    opacity: .96;
    transform: rotate(1.5deg) scale(1.015);
    box-shadow: 0 18px 50px rgba(0,0,0,.5);
    border-radius: var(--radius);
    overflow: hidden;
    will-change: left, top;
}
.printer-hidden { display: none !important; }
.printer-card-body { margin-top: 14px; }
.printer-order {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 10px; gap: 10px;
}
.lbl {
    color: var(--text-muted); font-size: 11px;
    text-transform: uppercase; letter-spacing: .4px; white-space: nowrap;
}
.val { font-weight: 700; font-size: 13px; color: var(--text); }
.val.gold { color: var(--gold); }
/* ===== PROGRESS BAR PREMIUM (elegan, sync tema global) ===== */
.progress-track {
    height: 10px;
    border-radius: 999px;
    background: var(--surface-3);
    border: 1px solid var(--border);
    box-shadow: inset 0 1px 3px rgba(0,0,0,.35);
    overflow: hidden;
    margin-bottom: 10px;
    position: relative;
}
.progress-fill {
    height: 100%;
    border-radius: 999px;
    position: relative;
    transition: background .4s ease;   /* width di-animasi JS (interpolasi halus) */
    box-shadow: 0 0 12px color-mix(in srgb, currentColor 45%, transparent);
}
/* shimmer halus di atas fill (premium) */
.progress-fill::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(110deg, transparent 20%, rgba(255,255,255,.22) 50%, transparent 80%);
    background-size: 200% 100%;
    animation: pbar-shimmer 2.2s linear infinite;
    border-radius: inherit;
}
@keyframes pbar-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
/* Warna per status — palet fresh & elegan (teal/cyan/indigo/rose), pakai variabel global */
.progress-fill.st-print   { color: #2dd4bf; background: linear-gradient(90deg, #0d9488, #2dd4bf, #22d3ee); }
.progress-fill.st-pause   { color: #fbbf24; background: linear-gradient(90deg, #f59e0b, #fbbf24, #fcd34d); }
.progress-fill.st-stop    { color: #fb7185; background: linear-gradient(90deg, #e11d48, #fb7185, #fda4af); }
.progress-fill.st-done    { color: #34d399; background: linear-gradient(90deg, #059669, #34d399, #6ee7b7); }
.progress-fill.st-idle    { color: #818cf8; background: linear-gradient(90deg, #4f46e5, #818cf8, #a5b4fc); }
.progress-fill.st-default { color: var(--gold); background: linear-gradient(90deg, var(--gold-dim), var(--gold), var(--gold-strong)); }
/* Teks status ikut warna status (konsisten dgn progress bar) */
.val.st-print   { color: #2dd4bf !important; }
.val.st-pause   { color: #fbbf24 !important; }
.val.st-stop    { color: #fb7185 !important; }
.val.st-done    { color: #34d399 !important; }
.val.st-idle    { color: #818cf8 !important; }
.val.st-default { color: var(--gold) !important; }
.val.st-off     { color: var(--text-muted, #888) !important; }
.progress-fill.st-off { color: var(--text-muted, #888); background: linear-gradient(90deg, #6b7280, #9ca3af, #6b7280); }
/* Baris info: label & nilai sejajar, spasi konsisten */
.printer-row {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 6px 12px;
    align-items: baseline;
    margin-bottom: 6px;
}
.printer-row .lbl { text-align: left; }
.printer-row .val { text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.printer-heartbeat {
    color: var(--text-muted); font-size: 11px; margin-top: 8px;
    display: flex; align-items: center; gap: 5px;
}
.printer-heartbeat i { color: var(--gold); }
/* Peringatan progress macet (anti-kecurangan) */
.printer-stall-warn {
    margin-top: 8px;
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700;
    color: #f87171;
    background: rgba(239,68,68,.12);
    border: 1px solid rgba(239,68,68,.3);
    border-radius: var(--radius-sm);
    padding: 6px 10px;
    animation: stallPulse 1.6s ease-in-out infinite;
}
.printer-stall-warn i { color: #f87171; }
@keyframes stallPulse { 0%,100% { opacity: 1; } 50% { opacity: .55; } }
.printer-extra {
    color: var(--text-muted); font-size: 10.5px; margin-top: 6px;
    /* Jangan potong — biarkan wrap ke beberapa baris supaya info (host, uptime,
       CPU, RAM, docker) terbaca LENGKAP. */
    overflow: visible; white-space: normal; word-break: break-word;
    line-height: 1.45;
    background: var(--bg-soft); border: 1px solid var(--border);
    border-radius: 6px; padding: 4px 8px;
}
.printer-extra i { color: var(--gold); margin-right: 4px; }
.printer-actions { display: flex; gap: 8px; margin-top: 12px; }

/* Statistik cetak harian */
.stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    padding: 12px 24px;
}
.stat-item {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 12px 16px;
    display: flex; flex-direction: column; gap: 4px;
}
.stat-item .stat-icon { font-size: 16px; color: var(--gold); }
.stat-item .stat-label { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.stat-item .stat-val { font-size: 20px; font-weight: 800; color: var(--text); }

/* Laporan cetak */
.report-panel {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); margin: 0 24px 24px; padding: 16px;
    min-width: 0;
    max-width: 100%;
}
.report-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.report-title { font-size: 15px; font-weight: 800; color: var(--text); margin: 0; }
.report-filter { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.report-select, .report-date {
    background: var(--bg-soft); border: 1px solid var(--border); color: var(--text);
    border-radius: var(--radius-sm); padding: 7px 10px; font-size: 12.5px;
}
.report-table-wrap { overflow-x: auto; max-width: 100%; }
.report-table { width: 100%; border-collapse: collapse; font-size: 12.5px; table-layout: auto; }
.report-table th {
    text-align: left; padding: 7px 8px; color: var(--text-muted);
    border-bottom: 1px solid var(--border); font-size: 11px; text-transform: uppercase; letter-spacing: .4px;
    white-space: nowrap;
}
.report-table td { padding: 7px 8px; border-bottom: 1px solid var(--border-strong); color: var(--text); vertical-align: top; }
.report-table tr:hover td { background: var(--bg-soft); }
.report-table .td-file { max-width: 220px; word-break: break-word; }
.report-empty { text-align: center; color: var(--text-muted); padding: 20px !important; }
.report-pager { display: flex; justify-content: center; align-items: center; gap: 14px; margin-top: 12px; }
.report-pageinfo { color: var(--text-muted); font-size: 12px; }
.report-pager .btn-scan:disabled { opacity: .5; cursor: not-allowed; }

/* ===== Log Audit Progress Macet ===== */
.stall-log-panel { margin-top: 16px; }
.stall-badge {
    background: var(--danger-bg); color: var(--danger); border: 1px solid rgba(248,113,113,.35);
    border-radius: 999px; padding: 2px 10px; font-size: 10.5px; font-weight: 700;
    vertical-align: middle; margin-left: 6px; letter-spacing: .3px;
}
.report-sub { color: var(--text-muted); font-size: 12px; margin: 4px 0 12px; }
.stall-empty {
    display: flex; align-items: center; gap: 8px; justify-content: center;
    color: var(--success); background: var(--success-bg); border: 1px dashed rgba(52,211,153,.4);
    border-radius: var(--radius-sm); padding: 16px; font-size: 13px; font-weight: 600;
}
.stall-empty i { font-size: 18px; }
.stall-log-table th { font-size: 11px; }
.stall-log-table td { padding: 7px 10px; }
.stall-status {
    display: inline-flex; align-items: center; gap: 5px;
    border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 700; white-space: nowrap;
}
.stall-status.resolved { background: var(--success-bg); color: var(--success); border: 1px solid rgba(52,211,153,.25); }
.stall-status.active { background: var(--danger-bg); color: var(--danger); border: 1px solid rgba(248,113,113,.35); animation: stallPulse 1.6s ease-in-out infinite; }
.badge-warn { background: var(--warning-bg); color: var(--warning); border-radius: 999px; padding: 2px 8px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.badge-order { background: var(--success-bg); color: var(--success); border: 1px solid rgba(52,211,153,.25); border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.badge-luar { background: var(--warning-bg); color: var(--warning); border: 1px solid rgba(251,191,36,.25); border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.badge-ok { background: var(--success-bg); color: var(--success); border: 1px solid rgba(52,211,153,.25); border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.badge-fail { background: var(--danger-bg); color: var(--danger); border: 1px solid rgba(248,113,113,.25); border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.badge-reprint { background: var(--warning-bg); color: var(--warning); border: 1px solid rgba(251,191,36,.35); border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.badge-reprint::before { content: '↻ '; }
.rc-sub { opacity: .75; font-weight: 600; }
.td-mesin { white-space: nowrap; }

/* Tombol aksi per printer */
.btn-sync-all {
    background: var(--surface-2); color: var(--text); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 9px 14px; font-weight: 700;
    cursor: pointer; font-size: 13px; transition: background var(--tr);
}
.btn-sync-all:hover { background: var(--surface-3); }
.printer-actions { display: flex; gap: 8px; margin-top: 12px; }
.btn-act {
    flex: 1; border: none; border-radius: var(--radius-sm); padding: 7px 10px;
    font-weight: 700; font-size: 12px; cursor: pointer; transition: opacity var(--tr);
}
.btn-act:disabled { opacity: .5; cursor: not-allowed; }
.btn-sync { background: var(--info-bg); color: var(--info); }
.btn-sync:hover { background: var(--info); color: var(--bg); }
/* Tombol sembunyikan/tampilkan printer (bersihkan monitor dari printer tak dipakai) */
.btn-hide {
    background: transparent; border: 1px solid var(--border); color: var(--muted);
    border-radius: 8px; padding: 4px 8px; cursor: pointer; line-height: 1;
    margin-left: auto; flex: 0 0 auto; transition: .15s;
}
.btn-hide:hover { background: var(--surface-3); color: var(--text); }
.btn-unhide { color: var(--success); border-color: rgba(46,204,113,.45); }
/* Kartu yang disembunyikan → diredupkan supaya beda jelas */
.printer-card.offline .btn-hide, .printer-card[data-hidden="1"] { opacity: .55; }
.cg-hidden-toggle {
    margin-left: 10px; font-size: 12px; color: var(--muted); text-decoration: none;
    border: 1px dashed var(--border); border-radius: 20px; padding: 3px 10px;
}
.cg-hidden-toggle:hover { color: var(--text); border-style: solid; }
.btn-cleanup { margin-left: auto; }
.btn-complete { background: var(--success-bg); color: var(--success); }
.btn-complete:hover { background: var(--success); color: var(--bg); }
.btn-startpress { background: #fff3cd; color: #b45309; }
.btn-startpress:hover { background: #f59e0b; color: var(--bg); }
.btn-pausepress { background: #fef3c7; color: #b45309; }
.btn-pausepress:hover { background: #d97706; color: var(--bg); }
.btn-resumepress { background: #d1fae5; color: #047857; }
.btn-resumepress:hover { background: #10b981; color: var(--bg); }
.btn-resetpress { background: #fee2e2; color: #b91c1c; }
.btn-resetpress:hover { background: #dc2626; color: var(--bg); }

/* ===== PANEL PRESS BAJU ===== */
.press-queue-panel {
    margin: 16px 24px 8px; padding: 18px 20px; border-radius: var(--radius);
    background: var(--surface); border: 1px solid var(--border);
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,.06));
}
.press-queue-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.press-queue-title { font-size: 16px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 8px; margin: 0; }
.press-queue-title i { color: var(--gold); }
.press-queue-badge {
    background: var(--warning-bg, #fef3c7); color: #b45309; border-radius: 999px;
    font-size: 11px; font-weight: 800; padding: 1px 9px; min-width: 20px; text-align: center;
}
.press-queue-sub { color: var(--text-muted, #888); font-size: 12px; }
.press-queue-wrap { overflow-x: auto; }
.press-queue-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.press-queue-table th {
    text-align: left; padding: 8px 10px; color: var(--text-muted, #888);
    font-size: 11px; text-transform: uppercase; letter-spacing: .4px; border-bottom: 1px solid var(--border);
}
.press-queue-table td { padding: 9px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.press-queue-table tr:last-child td { border-bottom: none; }
.press-queue-table tr:hover td { background: rgba(0,0,0,.015); }
.pq-order { font-weight: 800; color: var(--text); font-variant-numeric: tabular-nums; }
.pq-cust { color: var(--text); }
.pq-baju { color: var(--text-muted, #666); white-space: nowrap; }
.pq-st { font-weight: 700; font-size: 12px; white-space: nowrap; }
.press-queue-empty { color: var(--text-muted, #888); font-size: 13px; padding: 6px 2px; }
.press-queue-table .printer-actions { justify-content: flex-end; }
@media (max-width: 700px) {
    .press-queue-panel { margin: 12px 12px 6px; padding: 14px; }
    .press-queue-table .printer-actions { flex-wrap: wrap; }
}
.btn-delete-all { background: rgba(239,68,68,.14); color: var(--danger); border: 1px solid rgba(239,68,68,.3); }
.btn-delete-all:hover { background: var(--danger); color: var(--bg); }
.btn-del-row {
    border: none; background: transparent; color: var(--text-faint);
    border-radius: 6px; padding: 4px 8px; font-size: 13px; cursor: pointer;
    transition: color .15s ease, background .15s ease;
}
.btn-del-row:hover { color: var(--danger); background: rgba(239,68,68,.14); }
</style>

<script>
// ===== AJAX SCAN PRINTER =====
document.getElementById('btnScan').addEventListener('click', function () {
    const subnet = document.getElementById('subnetInput').value.trim();
    const statusEl = document.getElementById('scanStatus');
    const btn = document.getElementById('btnScan');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memindai...';
    statusEl.textContent = subnet ? ('Memindai ' + subnet + '.0/24 ...') : 'Memindai jaringan otomatis ...';

    fetch('/api/scan_printers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subnet: subnet })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const names = (data.printers || []).map(p => p.ip + ' (' + p.name + ')');
            statusEl.innerHTML = '<i class="fas fa-circle-check text-success"></i> Ditemukan ' + data.found_count + ' printer: ' + (names.join(', ') || '-');
            if (data.found_count > 0) {
                setTimeout(() => location.reload(), 1200);
            }
        } else {
            statusEl.innerHTML = '<i class="fas fa-triangle-exclamation text-warning"></i> Gagal scan';
        }
    })
    .catch(err => {
        statusEl.innerHTML = '<i class="fas fa-circle-xmark text-danger"></i> Error: ' + err;
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magnifying-glass"></i> SCAN PRINTER';
    });
});

// ===== AKSI PRINTER / PRESS (sync / complete / start_press / pause / resume / reset) =====
// Dipakai dua tempat:
//   - Tombol di kartu printer : data-id = printer_id (payload printer_id)
//   - Tombol di panel Press Baju: data-order = order_number (payload order_number)
function printerAction(action, id, btnEl, orderNo) {
    const fromPanel = !!(orderNo && String(orderNo).length > 0);
    const label = fromPanel ? ('order ' + orderNo) : ('printer ini');
    if (action === 'complete') {
        const pesan = fromPanel
            ? 'Tandai order ' + orderNo + ' sebagai SELESAI (siap_ambil)?\n\nPress baju selesai / semua beres utk order ini.'
            : 'Tandai order printer ini sebagai SELESAI (siap_ambil)?\n\nIni untuk fallback saat aplikasi PC crash & progress macet.';
        if (!confirm(pesan)) { return; }
    }
    if (action === 'start_press') {
        if (!confirm('Mulai tahap PRESS ke baju utk ' + label + '?\n\nEstimasi press dihitung dari menit/baju. Klik Complete setelah press selesai.')) {
            return;
        }
    }
    if (action === 'pause_press') {
        if (!confirm('JEDA / HOLD press utk ' + label + '?\n\nDipakai kalau press TIDAK bisa dikerjakan sekarang (mis. sudah malam / di luar jam kerja).\n\nNota akan tampil \'Ditunda\' & countdown membeku. Lanjutkan lagi kapan pun.')) {
            return;
        }
    }
    if (action === 'resume_press') {
        if (!confirm('Lanjutkan press yg sedang di-jeda utk ' + label + '?\n\nDurasi jeda akan diakumulasikan — estimasi selesai digeser sesuai lama jeda.')) {
            return;
        }
    }
    if (action === 'reset_press') {
        if (!confirm('RESET tahap press utk ' + label + '?\n\nMenghapus waktu mulai & jeda — kembali ke \'Menunggu Press ke baju\'.\n\nDipakai kalau kepencet Mulai Press / masih data test & belum benar2 ngepress.')) {
            return;
        }
    }
    const original = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    const payload = fromPanel
        ? { action: action, order_number: orderNo }
        : { action: action, printer_id: id };
    fetch('api/printer_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Tampilkan pesan singkat & reload setelah 1 detik
            const st = document.getElementById('scanStatus');
            if (st) {
                st.innerHTML = '<i class="fas fa-circle-check text-success"></i> ' + data.message;
            }
            setTimeout(() => location.reload(), 1000);
        } else {
            alert(data.message || 'Gagal');
        }
    })
    .catch(err => alert('Error: ' + err))
    .finally(() => {
        btnEl.disabled = false;
        btnEl.innerHTML = original;
    });
}

document.querySelectorAll('.btn-sync').forEach(btn => {
    btn.addEventListener('click', () => printerAction('sync', btn.dataset.id, btn));
});

// ===== SEMBUNYIKAN / TAMPILKAN / BERSIHKAN PRINTER (2026-10-11) =====
// Bertujuan membersihkan halaman dari printer yg alatnya mati/tidak dipakai tapi
// tetap dilaporkan "Online" oleh agent PC.
function hideShowAction(action, id, btn) {
    const st = document.getElementById('scanStatus');
    if (btn) btn.disabled = true;
    fetch('api/printer_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(id ? { action: action, printer_id: id } : { action: action })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            if (st) st.innerHTML = '<i class="fas fa-circle-check text-success"></i> ' + (d.message || 'OK');
            setTimeout(() => location.reload(), 800);
        } else {
            alert(d.message || 'Gagal');
            if (btn) btn.disabled = false;
        }
    })
    .catch(e => { alert('Error: ' + e); if (btn) btn.disabled = false; });
}
document.querySelectorAll('.btn-hide').forEach(btn => {
    btn.addEventListener('click', () => hideShowAction('hide', btn.dataset.id, btn));
});
document.querySelectorAll('.btn-unhide').forEach(btn => {
    btn.addEventListener('click', () => hideShowAction('unhide', btn.dataset.id, btn));
});
const btnCleanup = document.getElementById('btnCleanup');
if (btnCleanup) {
    btnCleanup.addEventListener('click', () => {
        if (!confirm('Bersihkan printer yang tidak terdeteksi lebih dari 2 hari?\n\n' +
                     'Printer yang tidak pernah dipakai akan DIHAPUS; yang punya riwayat ' +
                     'cukup disembunyikan. Printer baru tetap terdeteksi otomatis.')) return;
        hideShowAction('cleanup', null, btnCleanup);
    });
}
document.querySelectorAll('.btn-startpress').forEach(btn => {
    const orderNo = btn.dataset.order || '';
    btn.addEventListener('click', () => printerAction('start_press', btn.dataset.id, btn, orderNo || undefined));
});
document.querySelectorAll('.btn-pausepress').forEach(btn => {
    const orderNo = btn.dataset.order || '';
    btn.addEventListener('click', () => printerAction('pause_press', btn.dataset.id, btn, orderNo || undefined));
});
document.querySelectorAll('.btn-resumepress').forEach(btn => {
    const orderNo = btn.dataset.order || '';
    btn.addEventListener('click', () => printerAction('resume_press', btn.dataset.id, btn, orderNo || undefined));
});
document.querySelectorAll('.btn-resetpress').forEach(btn => {
    const orderNo = btn.dataset.order || '';
    btn.addEventListener('click', () => printerAction('reset_press', btn.dataset.id, btn, orderNo || undefined));
});
document.querySelectorAll('.btn-complete').forEach(btn => {
    const orderNo = btn.dataset.order || '';
    btn.addEventListener('click', () => printerAction('complete', btn.dataset.id, btn, orderNo || undefined));
});
document.getElementById('btnSyncAll').addEventListener('click', function () {
    const btns = document.querySelectorAll('.btn-sync');
    btns.forEach((b, i) => setTimeout(() => printerAction('sync', b.dataset.id, b), i * 300));
});

// ===== LOAD STATISTIK CETAK HARI INI =====
// Tanggal default dari server (WITA) — bukan new Date() UTC agar konsisten dgn data.
const CG_TODAY = '<?= $todayWita ?>';
const CG_MONTH = '<?= $monthWita ?>';

function currentReportPrinter() {
    const sel = document.getElementById('reportPrinter');
    return sel ? (parseInt(sel.value, 10) || 1) : 1;
}
function currentReportPrinterIsLembar() {
    // Printer per lembar (EPSON) → hitung jumlah lembar, bukan m²
    const sel = document.getElementById('reportPrinter');
    if (!sel) return false;
    const opt = sel.options[sel.selectedIndex];
    return opt ? /epson|L15150|inkjet|sheet/i.test(opt.text) : false;
}
function loadStats() {
    const pid = currentReportPrinter();
    fetch('api/printer_stats.php?printer_id=' + pid + '&tanggal=' + CG_TODAY)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.stats) return;
            const s = data.stats;
            const el = id => document.getElementById(id);
            if (el('statSelesai')) el('statSelesai').textContent = s.selesai || 0;
            if (el('statGagal')) el('statGagal').textContent = s.gagal || 0;
            const lembar = currentReportPrinterIsLembar();
            if (el('statMeter')) {
                el('statMeter').textContent = lembar
                    ? ((s.total_pages || 0) + ' lbr')
                    : ((s.total_m2 || 0) + ' m²');
            }
            const meterLabel = document.querySelector('#statsBar .stat-item:nth-child(3) .stat-label');
            if (meterLabel) meterLabel.textContent = lembar ? 'Total Lembar' : 'Total Cetak';
            if (el('statDurasi')) {
                const mnt = Math.round((s.total_detik || 0) / 60);
                el('statDurasi').textContent = mnt + ' mnt';
            }
            if (el('statOrder')) el('statOrder').textContent = s.selesai_order || 0;
            if (el('statLuar')) el('statLuar').textContent = s.selesai_luar || 0;
        })
        .catch(() => {});
}
loadStats();

// ===== LOAD LAPORAN CETAK (per hari / per bulan) =====
// Simpan semua jobs + pagination 15 per halaman agar tabel rapi.
let reportJobs = [];
let reportPage = 1;
const REPORT_PER_PAGE = 500;

function renderReportRows() {
    const body = document.getElementById('reportBody');
    if (!body) return;
    body.innerHTML = '';
    const start = (reportPage - 1) * REPORT_PER_PAGE;
    const slice = reportJobs.slice(start, start + REPORT_PER_PAGE);
    slice.forEach(j => {
        const tr = document.createElement('tr');
        const startAt = (j.start_at || '').replace('T', ' ').slice(0, 19);
        const mesin = j.printer_name || 'Printer #' + (j.printer_id || '-');
        const file = j.file_name || j.order_number || '-';
        const order = j.order_number || '-';
        const jenis = (j.sesuai_order === 'order')
            ? '<span class="badge-order">Sesuai Order</span>'
            : (j.sesuai_order === 'luar' ? '<span class="badge-luar">Luar Order</span>' : '<span class="badge-ok">-</span>');
        const ukuran = (j.total_pages) ? (j.total_pages + ' lbr') : ((j.width_m && j.height_m) ? (j.width_m + ' x ' + j.height_m + ' m') : '-');
        const luas = j.total_pages ? (j.total_pages + ' lbr') : (j.area_m2 ? (j.area_m2 + ' m²') : '-');
        const durasi = j.duration_sec ? (Math.round(j.duration_sec / 60) + ' mnt') : '-';
        // Status asli ORDER (sumber kebenaran) — untuk job yg sesuai orderan.
        // Kalau order sudah siap_ambil/batal, tampilkan status itu (bukan status
        // catatan mesin yg kadang 'gagal' karena transisi terlewat). Cetakan luar
        // (tanpa order) tetap pakai status mesin.
        const os = (j.order_status || '').toLowerCase();
        let status;
        // Job yang di-group (1 order = beberapa sesi cetak)
        if (j._group_id) {
            if (j.status === 'gagal') {
                // TIDAK ada sesi yang berhasil → semua gagal
                const n = j._attempts || 1;
                status = '<span class="badge-fail">Gagal ×' + n + '</span>';
            } else if (j._reprint) {
                // Ada >1 sesi, minimal 1 berhasil → RE-CETAK
                const n = j._attempts || 1;
                const g = j._gagal_count || 0;
                // Jika semua sesi berhasil → "Re-Cetak ×N"; jika ada yg gagal → "Re-Cetak ×N (M gagal)"
                status = '<span class="badge-reprint">Re-Cetak ×' + n + (g > 0 ? ' <span class="rc-sub">(' + g + ' gagal)</span>' : '') + '</span>';
            } else if (os === 'batal') {
                status = '<span class="badge-fail">Batal</span>';
            } else {
                status = '<span class="badge-ok">Selesai</span>';
            }
        } else if (j.sesuai_order === 'order' && os) {
            if (os === 'siap_ambil') {
                status = '<span class="badge-ok">Selesai</span>';
            } else if (os === 'batal') {
                status = '<span class="badge-fail">Batal</span>';
            } else {
                // masih proses (diterima/desain/cetak/finishing)
                status = '<span class="badge-order">' + j.order_status + '</span>';
            }
        } else {
            status = (j.status === 'selesai')
                ? '<span class="badge-ok">Selesai</span>'
                : (j.status === 'gagal' ? '<span class="badge-fail">Gagal</span>' : '<span class="badge-ok">' + j.status + '</span>');
        }
        // Tombol hapus: grup hapus semua id percobaan, tunggal hapus 1 id
        const delIds = j._group_ids && j._group_ids.length
            ? j._group_ids.join(',')
            : (j.id || '');
        tr.innerHTML = '<td>' + startAt + '</td>'
            + '<td class="td-mesin"><i class="fas fa-print"></i> ' + mesin + '</td>'
            + '<td class="td-file"><b>' + (file) + '</b>' + (order && order !== file ? '<br><span class="text-muted">' + order + '</span>' : '') + '</td>'
            + '<td>' + jenis + '</td>'
            + '<td>' + ukuran + '</td>'
            + '<td>' + luas + '</td>'
            + '<td>' + durasi + '</td>'
            + '<td>' + status + '</td>'
            + '<td><button type="button" class="btn-del-row" data-id="' + delIds + '" title="' + (j._group_ids && j._group_ids.length ? 'Hapus ' + j._group_ids.length + ' percobaan cetak order ini' : 'Hapus data ini') + '"><i class="fas fa-trash"></i></button></td>';
        body.appendChild(tr);
    });
    // Update info pagination
    const info = document.getElementById('reportPageInfo');
    if (info) {
        const totalPages = Math.max(1, Math.ceil(reportJobs.length / REPORT_PER_PAGE));
        info.textContent = 'Hal ' + reportPage + ' dari ' + totalPages + ' (' + reportJobs.length + ' data)';
    }
    const prev = document.getElementById('reportPrev');
    const next = document.getElementById('reportNext');
    if (prev) prev.disabled = reportPage <= 1;
    if (next) next.disabled = reportPage >= Math.ceil(reportJobs.length / REPORT_PER_PAGE);
}

function loadReport() {
    const pid = currentReportPrinter();
    const periode = document.getElementById('reportPeriode') ? document.getElementById('reportPeriode').value : 'hari';
    const tanggal = document.getElementById('reportTanggal') ? document.getElementById('reportTanggal').value : CG_TODAY;
    const body = document.getElementById('reportBody');
    if (!body) return;
    body.innerHTML = '<tr><td colspan="9" class="report-empty">Memuat...</td></tr>';

    fetch('api/printer_stats.php?printer_id=' + pid + '&periode=' + periode + '&tanggal=' + tanggal)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.stats) {
                body.innerHTML = '<tr><td colspan="9" class="report-empty">Gagal memuat laporan.</td></tr>';
                return;
            }
            reportJobs = data.stats.jobs || [];
            reportPage = 1;
            const info = document.getElementById('reportPageInfo');
            const prev = document.getElementById('reportPrev');
            const next = document.getElementById('reportNext');
            if (reportJobs.length === 0) {
                body.innerHTML = '<tr><td colspan="9" class="report-empty">Tidak ada data cetak pada periode ini.</td></tr>';
                if (info) info.textContent = '0 data';
                if (prev) prev.disabled = true;
                if (next) next.disabled = true;
                return;
            }
            renderReportRows();
        })
        .catch(() => {
            body.innerHTML = '<tr><td colspan="9" class="report-empty">Error memuat laporan.</td></tr>';
        });
}

// Pagination
const reportPrev = document.getElementById('reportPrev');
const reportNext = document.getElementById('reportNext');
if (reportPrev) reportPrev.addEventListener('click', () => { if (reportPage > 1) { reportPage--; renderReportRows(); } });
if (reportNext) reportNext.addEventListener('click', () => { if (reportPage < Math.ceil(reportJobs.length / REPORT_PER_PAGE)) { reportPage++; renderReportRows(); } });

// Event: tombol Tampilkan laporan + ganti printer (auto refresh)
const btnReport = document.getElementById('btnReport');
if (btnReport) btnReport.addEventListener('click', function () { loadReport(); loadStats(); });
const reportPrinter = document.getElementById('reportPrinter');
if (reportPrinter) reportPrinter.addEventListener('change', function () { loadReport(); loadStats(); });

// ===== HAPUS DATA LAPORAN =====
function cgCsrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
}
function cgDeleteJob(id, btn) {
    const label = String(id).indexOf(',') >= 0 ? 'hapus ' + String(id).split(',').length + ' percobaan cetak order ini?' : 'hapus data laporan cetak ini?';
    if (!confirm(label)) return;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    fetch('api/printer_jobs_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': cgCsrf() },
        body: JSON.stringify({ action: 'delete', ids: String(id).split(',').filter(Boolean).map(Number) })
    }).then(r => r.json())
      .then(res => {
          if (window.toast) window.toast(res.ok ? 'Data dihapus' : 'Gagal hapus', res.ok ? 'info' : 'danger');
          if (res.ok) loadReport();
      })
      .catch(() => { if (window.toast) window.toast('Gagal hapus', 'danger'); });
}
function cgDeleteAll() {
    const pid = currentReportPrinter();
    const periode = document.getElementById('reportPeriode') ? document.getElementById('reportPeriode').value : 'hari';
    const tanggal = document.getElementById('reportTanggal') ? document.getElementById('reportTanggal').value : CG_TODAY;
    const label = periode === 'bulan' ? 'bulan ' + tanggal : 'tanggal ' + tanggal;
    if (!confirm('HAPUS SEMUA data laporan cetak pada ' + label + '?\n\nTindakan ini tidak bisa dibatalkan!')) return;
    const btn = document.getElementById('btnDeleteAll');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    fetch('api/printer_jobs_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': cgCsrf() },
        body: JSON.stringify({ action: 'delete_all', periode: periode, tanggal: tanggal, printer_id: pid })
    }).then(r => r.json())
      .then(res => {
          if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash"></i> Hapus Semua'; }
          if (window.toast) window.toast(res.ok ? ('Dihapus ' + (res.deleted || 0) + ' data') : 'Gagal hapus', res.ok ? 'info' : 'danger');
          if (res.ok) { loadReport(); loadStats(); }
      })
      .catch(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash"></i> Hapus Semua'; } if (window.toast) window.toast('Gagal hapus', 'danger'); });
}
// Event delegasi: tombol hapus per baris (muncul saat render)
document.getElementById('reportBody').addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-del-row');
    if (btn) cgDeleteJob(btn.getAttribute('data-id'), btn);
});
const btnDeleteAll = document.getElementById('btnDeleteAll');
if (btnDeleteAll) btnDeleteAll.addEventListener('click', cgDeleteAll);

// Set default tanggal ke hari ini (server/WITA)
const reportTanggal = document.getElementById('reportTanggal');
if (reportTanggal && !reportTanggal.value) reportTanggal.value = CG_TODAY;
// Load pertama kali
loadReport();

// ===== SISA WAKTU DINAMIS (countdown 1 detik) =====
// Helper parse "HH:MM:SS" → detik
function parseRemainingToSec(str) {
    const parts = String(str || '').split(':').map(x => parseInt(x, 10) || 0);
    if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
    if (parts.length === 2) return parts[0] * 60 + parts[1];
    return 0;
}
function fmtRemaining(secs) {
    secs = Math.max(0, Math.round(secs));
    const h = String(Math.floor(secs / 3600)).padStart(2, '0');
    const m = String(Math.floor((secs % 3600) / 60)).padStart(2, '0');
    const s = String(secs % 60).padStart(2, '0');
    return h + ':' + m + ':' + s;
}
// Interval 1 detik: kurangi sisa waktu yg punya data-countdown="1"
setInterval(function () {
    document.querySelectorAll('.printer-row .val[data-countdown="1"]').forEach(function (el) {
        const total = parseFloat(el.dataset.remainSec) || 0;
        const at = parseFloat(el.dataset.remainAt) || 0;
        const elapsed = (Date.now() - at) / 1000;
        el.textContent = fmtRemaining(total - elapsed);
    });
}, 1000);

// ===== INTERPOLASI PROGRESS BAR (halus, tidak kaku) =====
// Animasi width dari nilai lama ke target memakai easing, durasi ~800ms.
function animateProgress(fill, targetPct) {
    if (!fill) return;
    const cur = parseFloat(fill.dataset.width || '0') || 0;
    const target = Math.max(0, Math.min(100, targetPct));
    fill.dataset.width = target;
    // batalkan animasi sebelumnya
    if (fill._raf) cancelAnimationFrame(fill._raf);
    const start = cur;
    const diff = target - start;
    if (Math.abs(diff) < 0.01) { fill.style.width = target + '%'; return; }
    const dur = 800; // ms
    const t0 = performance.now();
    const ease = function (t) { return 1 - Math.pow(1 - t, 3); }; // easeOutCubic
    function step(now) {
        const p = Math.min(1, (now - t0) / dur);
        const v = start + diff * ease(p);
        fill.style.width = v + '%';
        if (p < 1) {
            fill._raf = requestAnimationFrame(step);
        } else {
            fill.style.width = target + '%';
        }
    }
    fill._raf = requestAnimationFrame(step);
}

// ===== AUTO REFRESH AJAX (tanpa reload penuh) =====
// Jeda refresh saat user sedang drag kartu (biar drag mulus)
var cgDragging = false;
setInterval(function () {
    if (cgDragging) return;
    // Ikutkan ?show_hidden=1 kalau user sedang melihat printer tersembunyi.
    const ajaxUrl = 'cg_monitor.php?ajax=1' +
        (location.search.indexOf('show_hidden') !== -1 ? '&show_hidden=1' : '');
    fetch(ajaxUrl)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            data.printers.forEach(p => {
                const card = document.querySelector('[data-id="' + p.id + '"]');
                if (!card) return;
                const isHost = p.type === 'host' || p.type === 'server';
                // update status dot
                const statusEl = card.querySelector('.printer-status');
                if (statusEl) {
                    statusEl.textContent = '● ' + (p.is_offline ? 'Offline' : 'Online');
                    statusEl.classList.toggle('text-danger', p.is_offline);
                    statusEl.classList.toggle('text-success', !p.is_offline);
                }
                card.className = (p.is_offline ? 'printer-card offline' : 'printer-card online') + (isHost ? ' server-card' : '');
                // update order + progress (multi-order dari extra_info.orders_list)
                const orderEl = card.querySelector('.printer-order .val');
                if (orderEl) {
                    // Jangan tampilkan order lama kalau printer tidak sedang cetak
                    // (Idle/Online/Done) — supaya tidak ada sisa order menempel.
                    const psLow2 = String(p.print_status || '').toLowerCase();
                    const isPrinting2 = ['print', 'printing', 'mencetak', 'cetak', 'running', 'pause', 'stop'].includes(psLow2);
                    let orders2 = [];
                    if (isPrinting2) {
                        try {
                            const ep = p.extra_info ? JSON.parse(p.extra_info) : {};
                            if (ep && ep.orders_list) orders2 = String(ep.orders_list).split(',').map(s => s.trim()).filter(Boolean);
                        } catch (err) {}
                        if (!orders2.length && p.current_order) orders2 = [p.current_order];
                    }
                    if (orders2.length > 1) {
                        orderEl.style.fontSize = '13px';
                        orderEl.textContent = orders2.join(', ');
                    } else {
                        orderEl.style.fontSize = '';
                        orderEl.textContent = orders2.length ? orders2[0] : '—';
                    }
                }
                // Progress: hitung dari lembar kalau total_pages ada (konvensional).
                // RYPC UV: pakai progress dari UI mesin (kolom progress).
                let prog = parseInt(p.progress, 10) || 0;
                let pagesText = '';
                let progUnknown = false;
                const tp = parseInt(p.total_pages, 10) || 0;
                const pp = parseInt(p.printed_pages, 10) || 0;
                if (tp > 0) {
                    const calc = Math.min(100, Math.round(pp * 100 / tp));
                    if (prog === 0) prog = calc;
                    pagesText = ' · ' + pp + '/' + tp + ' lbr';
                } else if (p.type === 'uv') {
                    // RYPC UV: tampilkan '—' HANYA saat mesin idle/unknown.
                    // Saat Pause/Print/Stop tetap tampilkan progress (0 pun tampil).
                    const stLow = String(p.print_status || '').toLowerCase();
                    const activeNow = ['print','printing','mencetak','cetak','running','pause','stop'].includes(stLow);
                    if (prog <= 0 && !activeNow) progUnknown = true;
                } else {
                    progUnknown = true; // tidak ada data lembar → tampil '—'
                }
                const fill = card.querySelector('.progress-fill');
                if (fill) {
                    // INTERPOLASI HALUS: jangan langsung set width (kaku),
                    // tapi animasikan dari nilai lama ke baru via requestAnimationFrame.
                    const targetW = progUnknown ? 0 : Math.min(100, prog);
                    animateProgress(fill, targetW);
                    // warna progress sesuai status (sync dgn class CSS global)
                    const st = String(p.print_status || '').toLowerCase();
                    let cls = 'st-default';
                    if (st.includes('print')) cls = 'st-print';
                    else if (st.includes('pause')) cls = 'st-pause';
                    else if (st.includes('stop') || st.includes('abort') || st.includes('cancel')) cls = 'st-stop';
                    else if (st.includes('done') || st.includes('finish')) cls = 'st-done';
                    else if (st.includes('idle')) cls = 'st-idle';
                    fill.className = 'progress-fill ' + cls;
                }
                const rows = card.querySelectorAll('.printer-row .val');
                if (rows.length >= 3) {
                    rows[0].textContent = progUnknown ? '—' : (prog + '%' + pagesText);
                    // SISA WAKTU DINAMIS: simpan nilai+timestamp utk countdown 1 detik.
                    // Berkurang tiap detik. FIX 2026-09-04: JANGAN reset countdown ke
                    // nilai server yg sama/lebih besar — kalau tidak, tiap refresh AJAX
                    // countdown restart ke nilai penuh & tampak "tidak turun" (stuck).
                    // Countdown lanjut menurun; hanya sinkron ulang kalau server kirim
                    // nilai LEBIH KECIL (pembaruan sisa yg benar dari mesin/OCR).
                    const remEl = rows[1];
                    const activeSt = String(p.print_status || '').toLowerCase();
                    // FIX 2026-10-11 (beku saat PAUSE): countdown 1 detik HANYA
                    // jalan saat mesin MENCETAK. Saat Pause/Stop/Hold → countdown
                    // DIHENTIKAN (data-countdown=0) & tampilkan nilai server apa
                    // adanya (server kirim nilai beku saat pause) → sama seperti
                    // layar RYPC yg membeku. Dulu 'pause' ikut di-set countdown=1
                    // → angka terus turun padahal mesin berhenti.
                    const isPrintingNow = ['print', 'printing', 'mencetak', 'cetak', 'running'].includes(activeSt);
                    if (isPrintingNow && p.remaining_time && p.remaining_time !== '00:00:00') {
                        const secs = parseRemainingToSec(p.remaining_time);
                        if (secs > 0) {
                            const running = remEl.dataset.countdown === '1';
                            let remainLeft = null;
                            if (running) {
                                const total = parseFloat(remEl.dataset.remainSec) || 0;
                                const at = parseFloat(remEl.dataset.remainAt) || 0;
                                remainLeft = total - (Date.now() - at) / 1000;
                            }
                            // Reset hanya kalau: (a) belum ada countdown, atau
                            // (b) nilai server lebih kecil dr sisa countdown skrg
                            // (server kasih sisa baru yg lebih akurat/lebih pendek).
                            if (!running || remainLeft === null || secs < remainLeft) {
                                remEl.dataset.remainSec = secs;
                                remEl.dataset.remainAt = Date.now();
                                remEl.dataset.countdown = '1';
                                remEl.textContent = fmtRemaining(secs);
                            }
                            // else: countdown yg sedang berjalan tetap dipakai (turun)
                        } else {
                            remEl.dataset.countdown = '0';
                            remEl.textContent = p.remaining_time;
                        }
                    } else {
                        remEl.dataset.countdown = '0';
                        remEl.textContent = p.remaining_time;
                    }
                    // Saat OFFLINE, print_status di DB basi (mis. "Idle" sisa set
                    // manual) — tampilkan "Offline" jujur & warna abu, konsisten
                    // dgn dot ● Offline di header kartu.
                    if (p.is_offline) {
                        rows[2].textContent = 'Offline';
                        rows[2].className = 'val st-off';
                    } else {
                        rows[2].textContent = p.print_status || '—';
                        const stV = String(p.print_status || '').toLowerCase();
                        let vCls = 'st-default';
                        if (stV.includes('print')) vCls = 'st-print';
                        else if (stV.includes('pause')) vCls = 'st-pause';
                        else if (stV.includes('stop') || stV.includes('abort') || stV.includes('cancel')) vCls = 'st-stop';
                        else if (stV.includes('done') || stV.includes('finish')) vCls = 'st-done';
                        else if (stV.includes('idle')) vCls = 'st-idle';
                        rows[2].className = 'val ' + vCls;
                    }
                } else if (rows.length === 1) {
                    // host/server: hanya kolom status
                    rows[0].textContent = p.is_offline ? 'Offline' : (p.print_status || '—');
                }
                // ANTI-KECURANGAN: warning progress macet (klaim Print tapi progress tidak naik)
                const stallEl = card.querySelector('.printer-stall-warn');
                const psLow = String(p.print_status || '').toLowerCase();
                const isPrinting = ['print','printing','mencetak','cetak','running'].includes(psLow);
                let stallMin = parseInt(p.stalled_min, 10) || 0;
                const progNow = parseInt(p.progress, 10) || 0;
                let showStall = false;
                if (isPrinting && stallMin >= 3 && progNow > 0) showStall = true;
                else if (isPrinting && stallMin >= 5) showStall = true;
                if (showStall) {
                    if (!stallEl) {
                        const div = document.createElement('div');
                        div.className = 'printer-stall-warn';
                        div.title = 'Progress tidak naik selama ' + stallMin + ' menit padahal status Print';
                        const hbRef = card.querySelector('.printer-heartbeat');
                        if (hbRef && hbRef.previousSibling) card.insertBefore(div, hbRef);
                        else card.appendChild(div);
                        div.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Progress macet ' + stallMin + ' mnt — cek mesin!';
                    } else {
                        stallEl.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Progress macet ' + stallMin + ' mnt — cek mesin!';
                    }
                } else if (stallEl) {
                    stallEl.remove();
                }
                const hb = card.querySelector('.printer-heartbeat');
                if (hb) hb.innerHTML = '<i class="fas fa-heartbeat"></i> ' + p.secs_display;
                // update extra_info — format manusiawi (printer & server)
                let ex = card.querySelector('.printer-extra');
                let exText = p.extra_info || '';
                if (exText) {
                    try {
                        const parsed = JSON.parse(exText);
                        const parts = [];
                        if (parsed.hostname) parts.push('Host: ' + parsed.hostname);
                        if (parsed.uptime_seconds != null) {
                            const s = parseInt(parsed.uptime_seconds, 10) || 0;
                            parts.push('Aktif ' + new Date(s * 1000).toISOString().substr(11, 8) + ' jam');
                        }
                        if (parsed.cpu_percent != null) parts.push('CPU ' + parseInt(parsed.cpu_percent, 10) + '%');
                        if (parsed.mem_mb != null) parts.push('RAM ' + (parseInt(parsed.mem_mb, 10) / 1024).toFixed(1) + ' GB');
                        if (parts.length === 0 && parsed.docker) parts.push(parsed.docker);
                        exText = parts.join(' · ');
                    } catch (e) { /* raw string */ }
                }
                if (exText) {
                    if (!ex) {
                        ex = document.createElement('div');
                        ex.className = 'printer-extra';
                        const hbEl = card.querySelector('.printer-heartbeat');
                        if (hbEl && hbEl.nextSibling) card.insertBefore(ex, hbEl.nextSibling);
                        else card.appendChild(ex);
                    }
                    ex.innerHTML = '<i class="fas fa-circle-info"></i> ' + exText;
                } else if (ex) {
                    ex.remove();
                }
            });
        })
        .catch(() => {});
}, 2000);

// ===== DRAG & DROP KARTU PRINTER (Owner/Admin) =====
// Mendukung 2 grid: #printerGrid (printer) & #serverGrid (server)
(function () {
    var grids = Array.prototype.slice.call(document.querySelectorAll('.printer-grid'));
    if (!grids.length) return;

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var dragEl = null, ghost = null, ph = null, curGrid = null;
    var startX = 0, startY = 0, offX = 0, offY = 0;

    function getItems() {
        return Array.prototype.slice.call(curGrid.querySelectorAll('.printer-card'));
    }

    function onMove(e) {
        if (!dragEl) return;
        offX = e.clientX - startX;
        offY = e.clientY - startY;
        ghost.style.left = (startX + offX) + 'px';
        ghost.style.top = (startY + offY) + 'px';

        var cX = startX + offX + (ghost.offsetWidth || 100) / 2;
        var cY = startY + offY + (ghost.offsetHeight || 60) / 2;
        var others = getItems().filter(function (p) { return p !== ph; });
        var target = others.length;
        for (var i = 0; i < others.length; i++) {
            var r = others[i].getBoundingClientRect();
            var mX = r.left + r.width / 2;
            var mY = r.top + r.height / 2;
            // Grid bisa beberapa kolom: prioritas baris (Y), lalu kolom (X)
            if (cY < mY || (Math.abs(cY - mY) < 10 && cX < mX)) {
                target = i;
                break;
            }
        }
        if (target >= others.length) { curGrid.appendChild(ph); }
        else { others[target].parentNode.insertBefore(ph, others[target]); }
    }

    function onUp() {
        if (!dragEl) return;
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);

        var tp = ph.parentNode, tb = ph.nextSibling;
        tp.removeChild(ph);
        if (tb) tp.insertBefore(dragEl, tb);
        else tp.appendChild(dragEl);
        dragEl.classList.remove('printer-hidden');

        var fRect = dragEl.getBoundingClientRect();
        var dx = (startX + offX) - fRect.left;
        var dy = (startY + offY) - fRect.top;
        dragEl.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
        dragEl.style.opacity = '1';

        var done = function () {
            if (!dragEl) return;
            dragEl.style.transform = '';
            cleanup();
        };
        if (reduce) { dragEl.style.transform = ''; cleanup(); return; }
        var t0 = Date.now(), dur = 240;
        function step() {
            var p = Math.min((Date.now() - t0) / dur, 1);
            var e = 1 - Math.pow(1 - p, 3);
            var x = Math.round(dx * (1 - e));
            var y = Math.round(dy * (1 - e));
            dragEl.style.transform = (x === 0 && y === 0) ? '' : 'translate(' + x + 'px,' + y + 'px)';
            if (p < 1) setTimeout(step, 16);
            else done();
        }
        setTimeout(step, 0);
        setTimeout(done, 460);
    }

    function saveOrder(grid) {
        if (!grid) return;
        var order = Array.prototype.slice.call(grid.querySelectorAll('.printer-card'))
            .map(function (p) { return p.getAttribute('data-id'); });
        if (!order.length) return;
        var csrf = document.querySelector('meta[name="csrf-token"]');
        fetch('api/printer_reorder.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf ? csrf.getAttribute('content') : ''
            },
            body: JSON.stringify({ order: order })
        }).then(function (r) { return r.json(); })
          .then(function (res) {
              if (window.toast) window.toast(res && res.ok ? 'Urutan kartu tersimpan' : 'Gagal menyimpan urutan', res && res.ok ? 'info' : 'danger');
          })
          .catch(function () { if (window.toast) window.toast('Gagal menyimpan urutan', 'danger'); });
    }

    function cleanup() {
        if (ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost);
        ghost = null; ph = null;
        var savedGrid = curGrid;
        dragEl = null; curGrid = null;
        document.body.classList.remove('dash-sorting');
        cgDragging = false;
        saveOrder(savedGrid);
    }

    grids.forEach(function (grid) {
        var handles = grid.querySelectorAll('.printer-drag');
        if (!handles.length) return;
        handles.forEach(function (h) {
            h.addEventListener('mousedown', function (e) {
                if (e.button !== 0) return;
                var item = h.closest('.printer-card');
                if (!item || dragEl) return;
                e.preventDefault();
                e.stopPropagation();
                var r = item.getBoundingClientRect();
                dragEl = item;
                curGrid = grid;
                cgDragging = true;
                startX = e.clientX; startY = e.clientY;
                offX = 0; offY = 0;

                ph = document.createElement('div');
                ph.className = 'printer-ph';
                ph.style.height = r.height + 'px';
                item.parentNode.insertBefore(ph, item);

                ghost = document.createElement('div');
                ghost.className = 'printer-ghost';
                ghost.innerHTML = item.innerHTML;
                ghost.style.width = r.width + 'px';
                ghost.style.height = r.height + 'px';
                ghost.style.left = r.left + 'px';
                ghost.style.top = r.top + 'px';
                document.body.appendChild(ghost);

                item.classList.add('printer-hidden');
                document.body.classList.add('dash-sorting');
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
