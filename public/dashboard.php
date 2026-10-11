<?php
/**
 * public/dashboard.php
 * =====================================================================
 * Dashboard utama (desain referensi V3):
 *   - Welcome banner dengan tanggal & sparkline mini
 *   - 5 KPI card dengan tren naik/turun
 *   - Grafik penjualan 30 hari (ApexCharts)
 *   - Pesanan terbaru, produk terlaris
 *   - Ringkasan keuangan & WhatsApp center
 * =====================================================================
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireMenuAccess('dashboard');

// Auto-purge Cloudflare bila kode berubah (owner membuka dashboard → pemicu)
autoPurgeCloudflareIfChanged();

$role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? 'User';

$isOwner = $role === 'owner';

/* =====================================================================
 * TOGGLE AI MASTER (kill switch) — PER-PENGGUNA (owner & admin)
 * Tiap user punya toggle sendiri di user_preferences. Global master tetap
 * berlaku (bila owner matikan global, semua AI mati).
 * ===================================================================== */
if (in_array($role, ['owner', 'admin'], true) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_master_toggle'])) {
    verify_csrf();
    $aiVal = (($_POST['ai_master_toggle'] ?? '') === 'on') ? '1' : '0';
    setUserPref('ai_master_enabled', $aiVal);
    writeLog("AI kill switch per-user diset {$aiVal} oleh {$_SESSION['username']}", 'INFO');
    header('Location: dashboard');
    exit;
}
$aiMasterOn = wa_ai_master_enabled();

/* =====================================================================
 * TANDAI BACA / BELUM BACA NOTIFIKASI ORDER WA (panel dashboard)
 * - mark_read      : toggle per item (baru <-> diproses) via AJAX JSON
 * - mark_all_read  : tandai SEMUA notifikasi baru -> diproses (AJAX JSON)
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (post('action') === 'mark_read' || post('action') === 'mark_all_read')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        verify_csrf();
        $action = (string)post('action');
        if ($action === 'mark_read') {
            $id = (int)post('id', 0);
            $markAs = (string)post('mark_as', '');   // 'diproses' (dibaca) atau 'baru' (belum dibaca)
            if ($id <= 0 || !in_array($markAs, ['baru', 'diproses'], true)) {
                echo json_encode(['ok' => false, 'message' => 'Data tidak valid.']);
                exit;
            }
            db()->prepare("UPDATE wa_order_captures SET status=?, updated_at=? WHERE id=?")
                ->execute([$markAs, date('Y-m-d H:i:s'), $id]);
            writeLog("Order WA #{$id} ditandai {$markAs} dari dashboard oleh {$_SESSION['username']}", 'INFO');
        } else {
            $n = (int)db()->prepare("UPDATE wa_order_captures SET status='diproses', updated_at=? WHERE status='baru'")
                ->execute([date('Y-m-d H:i:s')]);
            writeLog("Semua notifikasi Order WA ({$n}) ditandai dibaca dari dashboard oleh {$_SESSION['username']}", 'INFO');
        }
        $newCount = (int)db()->query("SELECT COUNT(*) FROM wa_order_captures WHERE status = 'baru'")->fetchColumn();
        echo json_encode(['ok' => true, 'new_count' => $newCount]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

/* =====================================================================
 * STOR (SETORAN HARIAN) — simpan / daftar / hapus
 * ---------------------------------------------------------------------
 * Kartu "Stor" di dashboard: admin/owner menekan "Sudah Stor" lalu mengisi
 * nominal CASH dan TRANSFER. Dicatat HARIAN; beberapa kali sehari boleh
 * (mis. pagi & sore) dan semuanya diakumulasi jadi "stor hari ini" serta
 * "stor bulan ini".
 * Balasan: JSON { ok, message, ... }
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)post('action'), ['stor_save', 'stor_delete'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        verify_csrf();
        if (!in_array($role, ['owner', 'admin'], true)) {
            echo json_encode(['ok' => false, 'message' => 'Hanya owner/admin yang boleh mencatat setoran.']);
            exit;
        }
        $action = (string)post('action');

        if ($action === 'stor_delete') {
            $sid = (int)post('id', 0);
            if ($sid <= 0) {
                echo json_encode(['ok' => false, 'message' => 'Data tidak valid.']);
                exit;
            }
            $stDel = db()->prepare("SELECT stor_date, total_amount FROM cash_stor WHERE id=?");
            $stDel->execute([$sid]);
            $rowDel = $stDel->fetch();
            if (!$rowDel) {
                echo json_encode(['ok' => false, 'message' => 'Catatan setoran tidak ditemukan.']);
                exit;
            }
            db()->prepare("DELETE FROM cash_stor WHERE id=?")->execute([$sid]);
            writeLog("Setoran #{$sid} (" . formatRupiah((float)$rowDel['total_amount']) . " tanggal {$rowDel['stor_date']}) DIHAPUS oleh {$_SESSION['username']}", 'INFO');
            echo json_encode(['ok' => true, 'message' => 'Catatan setoran dihapus.']);
            exit;
        }

        // ===== SIMPAN SETORAN =====
        // Tanggal: default HARI INI. Format YYYY-MM-DD dari <input type="date">.
        $storDate = trim((string)post('stor_date', ''));
        if ($storDate === '') {
            $storDate = date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $storDate) || strtotime($storDate) === false) {
            echo json_encode(['ok' => false, 'message' => 'Tanggal tidak valid.']);
            exit;
        }
        // PENTING: nominal dari form bisa berformat Indonesia ("1.250.000").
        // JANGAN pakai (float) langsung — "27.000" jadi 27. Pakai parser nota.
        $cash = nota_supplier_angka((string)post('cash_amount', '0'));
        $trf  = nota_supplier_angka((string)post('transfer_amount', '0'));
        $cash = max(0.0, round($cash, 2));
        $trf  = max(0.0, round($trf, 2));
        $total = $cash + $trf;
        if ($total <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Nominal setoran masih kosong — isi cash dan/atau transfer.']);
            exit;
        }
        $note = trim((string)post('note', ''));
        if (mb_strlen($note) > 255) {
            $note = mb_substr($note, 0, 255);
        }
        db()->prepare("INSERT INTO cash_stor (stor_date, cash_amount, transfer_amount, total_amount, note, created_by, created_by_name)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $storDate, $cash, $trf, $total,
                ($note !== '' ? $note : null),
                (int)($_SESSION['user_id'] ?? 0) ?: null,
                (string)($_SESSION['username'] ?? 'User'),
            ]);
        writeLog("STOR dicatat: " . formatRupiah($total) . " (cash " . formatRupiah($cash)
            . " + transfer " . formatRupiah($trf) . ") tanggal {$storDate} oleh {$_SESSION['username']}", 'INFO');

        // Ringkasan terbaru supaya kartu bisa langsung diperbarui tanpa reload.
        $stToday = db()->prepare("SELECT COALESCE(SUM(cash_amount),0) c, COALESCE(SUM(transfer_amount),0) t, COALESCE(SUM(total_amount),0) tot, COUNT(*) n
                                  FROM cash_stor WHERE stor_date = ?");
        $stToday->execute([$storDate]);
        $rToday = $stToday->fetch() ?: ['c' => 0, 't' => 0, 'tot' => 0, 'n' => 0];
        echo json_encode([
            'ok' => true,
            'message' => 'Setoran tercatat: ' . formatRupiah($total),
            'hari' => [
                'tanggal'  => $storDate,
                'cash'     => (float)$rToday['c'],
                'transfer' => (float)$rToday['t'],
                'total'    => (float)$rToday['tot'],
                'jumlah'   => (int)$rToday['n'],
            ],
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

/* =====================================================================
 * GANTI TEMA DARK / LIGHT (GLOBAL — semua user ikut)
 * Simpan di settings.theme_mode = 'dark' | 'light'
 * Dipanggil via AJAX dari tombol theme di navbar (lihat app.js).
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle_theme') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        verify_csrf();
        $mode  = ((string)post('mode', '') === 'light') ? 'light' : 'dark';
        db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('theme_mode', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$mode]);
        writeLog("Tema diubah ke {$mode} oleh {$_SESSION['username']}", 'INFO');
        echo json_encode(['ok' => true, 'mode' => $mode]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

/* =====================================================================
 * TUKAR POSISI PANEL ROW_TOP (Produk Terlaris <-> Aktivitas Terbaru)
 * Simpan preferensi di settings: dashboard_aktivitas_swap = 1 (aktivitas kiri)
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'swap_aktivitas') {
    verify_csrf();
    $cur = (string)getSetting('dashboard_aktivitas_swap', '0');
    $new = $cur === '1' ? '0' : '1';
    db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('dashboard_aktivitas_swap', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$new]);
    writeLog("Posisi panel dashboard ditukar ({$new}) oleh {$_SESSION['username']}", 'INFO');
    header('Location: dashboard');
    exit;
}

/* =====================================================================
 * WIDGET YANG DIIZINKAN PER ROLE (sesuai project lama: dashboard_widgets)
 * - Owner: semua widget.
 * - Role lain: hanya widget yang terdaftar di tabel dashboard_widgets.
 * ===================================================================== */
$allowedWidgets = [];
$stmt = db()->prepare("SELECT widget_key FROM dashboard_widgets WHERE role = ? AND is_visible = 1");
$stmt->execute([$role]);
$allowedWidgets = array_column($stmt->fetchAll(), 'widget_key');
$showTotalRevenue = $isOwner || in_array('total_revenue', $allowedWidgets, true);
/* WhatsApp kini khusus owner (menu & API) — widget hanya untuk owner */
$showWhatsapp    = $isOwner && in_array('whatsapp_chat', $allowedWidgets, true);
/* Tampilkan card Pengeluaran di dashboard? (setting owner, default tampil) */
$showExpensesDash = (int)getSetting('show_expenses_dashboard', '1') === 1;

$canFinance  = $showTotalRevenue;  // kompatibilitas referensi lama
$canWhatsapp = $showWhatsapp;

/* =====================================================================
 * URUTAN PANEL (bisa di-drag & drop oleh Owner/Admin)
 * ===================================================================== */
$canReorder = in_array($role, ['owner', 'admin'], true);

/* Definisi panel: key => [label, kondisi tampil] */
$panelDefs = [
    'grafik'   => 'Grafik Penjualan',
    'pesanan'  => 'Pesanan Terbaru',
    'produk'   => 'Produk Terlaris',
    'keuangan' => 'Ringkasan Keuangan',
    'whatsapp' => 'WhatsApp Center',
];
$panelVisible = [
    'grafik'   => $canFinance,
    'pesanan'  => in_array('order_list', $allowedWidgets, true) || in_array('recent_activities', $allowedWidgets, true),
    'produk'   => in_array('active_orders', $allowedWidgets, true),
    'keuangan' => $canFinance,
    'whatsapp' => $canWhatsapp,
];

/* Baca urutan & status sembunyi tersimpan (fallback ke default 5 panel) */
$savedOrder = [];
$hiddenPanels = [];
$stmt = db()->query("SELECT panel_key, sort_order, is_hidden FROM dashboard_layout ORDER BY sort_order ASC");
foreach ($stmt->fetchAll() as $row) {
    $savedOrder[$row['panel_key']] = (int)$row['sort_order'];
    if ((int)$row['is_hidden'] === 1) {
        $hiddenPanels[$row['panel_key']] = true;
    }
}
$orderKeys = array_keys($panelDefs);
usort($orderKeys, function ($a, $b) use ($savedOrder) {
    $sa = $savedOrder[$a] ?? 999;
    $sb = $savedOrder[$b] ?? 999;
    return $sa <=> $sb;
});
/* Hanya panel yang terlihat (sesuai role + tidak disembunyikan owner/admin) */
$visibleKeys = array_values(array_filter($orderKeys, function ($k) use ($panelVisible, $hiddenPanels) {
    return $panelVisible[$k] && empty($hiddenPanels[$k]);
}));
$topKeys  = array_slice($visibleKeys, 0, 2);   // baris atas: maks 2 panel
$bottomKeys = array_slice($visibleKeys, 2);    // baris bawah: sisanya
$hiddenCount = count(array_filter($hiddenPanels, fn($k) => !empty($panelVisible[$k]), ARRAY_FILTER_USE_KEY));

/* =====================================================================
 * URUTAN KARTU KPI (drag & drop oleh Owner/Admin)
 * ===================================================================== */
$kpiDefs = [
    'total_orders'    => 'Pesanan Bulan Ini',
    'total_revenue'   => 'Total Pendapatan',
    'today_revenue'   => 'Pesanan Hari Ini',
    'total_customers' => 'Pelanggan Aktif',
    'active_orders'   => 'Produk Terjual',
    'unpaid_today'    => 'Belum Bayar',
    'total_expenses'  => 'Pengeluaran Bulan Ini',
    'expenses_today'  => 'Pengeluaran',
    'month_revenue'   => 'Pendapatan Bulan Ini',
    'month_profit'    => 'Keuntungan',
    'orders_in_progress' => 'Pesanan Diproses',
    'done_today'      => 'Selesai Hari Ini',
    'pending_orders'  => 'Perlu Diproses',
    'total_receivable'=> 'Total Piutang',
    'new_customers_today' => 'Pelanggan Baru Hari Ini',
    'stor'            => 'Stor (Setoran Harian)',
];
$kpiVisible = [
    'total_orders'    => in_array('total_orders', $allowedWidgets, true),
    'total_revenue'   => in_array('total_revenue', $allowedWidgets, true),
    'today_revenue'   => in_array('today_revenue', $allowedWidgets, true),
    'total_customers' => in_array('total_customers', $allowedWidgets, true),
    'active_orders'   => in_array('active_orders', $allowedWidgets, true),
    'unpaid_today'    => in_array('unpaid_today', $allowedWidgets, true),
    'total_expenses'  => in_array('total_expenses', $allowedWidgets, true),
    'expenses_today'  => in_array('expenses_today', $allowedWidgets, true),
    'month_revenue'   => in_array('month_revenue', $allowedWidgets, true),
    'month_profit'    => in_array('month_profit', $allowedWidgets, true),
    'orders_in_progress' => in_array('orders_in_progress', $allowedWidgets, true),
    'done_today'      => in_array('done_today', $allowedWidgets, true),
    'pending_orders'  => in_array('pending_orders', $allowedWidgets, true),
    'total_receivable'=> in_array('total_receivable', $allowedWidgets, true),
    'new_customers_today' => in_array('new_customers_today', $allowedWidgets, true),
    'stor'            => in_array('stor', $allowedWidgets, true),
];
$savedKpiOrder = [];
$hiddenKpis = [];
$stmt = db()->query("SELECT kpi_key, sort_order, is_hidden FROM dashboard_kpi_layout ORDER BY sort_order ASC");
foreach ($stmt->fetchAll() as $row) {
    $savedKpiOrder[$row['kpi_key']] = (int)$row['sort_order'];
    if ((int)$row['is_hidden'] === 1) {
        $hiddenKpis[$row['kpi_key']] = true;
    }
}
$kpiOrderKeys = array_keys($kpiDefs);
usort($kpiOrderKeys, function ($a, $b) use ($savedKpiOrder) {
    $sa = $savedKpiOrder[$a] ?? 999;
    $sb = $savedKpiOrder[$b] ?? 999;
    return $sa <=> $sb;
});
$visibleKpiKeys = array_values(array_filter($kpiOrderKeys, fn($k) => !empty($kpiVisible[$k]) && empty($hiddenKpis[$k])));

/* =====================================================================
 * URUTAN SEKSI DASHBOARD (kpi / row_top / row_bottom / aktivitas)
 * ===================================================================== */
$sectionDefs = ['kpi', 'row_top', 'row_bottom', 'aktivitas'];
$savedSectionOrder = [];
$stmt = db()->query("SELECT section_key, sort_order FROM dashboard_section_layout ORDER BY sort_order ASC");
foreach ($stmt->fetchAll() as $row) {
    $savedSectionOrder[$row['section_key']] = (int)$row['sort_order'];
}
usort($sectionDefs, function ($a, $b) use ($savedSectionOrder) {
    $sa = $savedSectionOrder[$a] ?? 999;
    $sb = $savedSectionOrder[$b] ?? 999;
    return $sa <=> $sb;
});
$sectionVisible = [
    'kpi'       => count($visibleKpiKeys) > 0,
    'row_top'   => count($topKeys) > 0,
    'row_bottom'=> count($bottomKeys) > 0,
    'aktivitas' => in_array('recent_activities', $allowedWidgets, true),
];
$visibleSections = array_values(array_filter($sectionDefs, fn($s) => !empty($sectionVisible[$s])));

/* Gabungkan "Aktivitas Terbaru" ke baris atas bila row_top tampil dengan 1 panel
 * (jadi 2 kolom berdampingan: [panel row_top | Aktivitas Terbaru]). */
$aktivitasInRowTop = in_array('row_top', $visibleSections, true)
    && in_array('aktivitas', $visibleSections, true)
    && count($topKeys) <= 1;
if ($aktivitasInRowTop) {
    // Aktivitas sudah dirender di dalam row_top — jangan render section terpisah
    $visibleSections = array_values(array_filter($visibleSections, fn($s) => $s !== 'aktivitas'));
}
/* Preferensi tukar posisi: 1 = Aktivitas di kiri, 0 = Aktivitas di kanan */
$aktivitasSwap = ((string)getSetting('dashboard_aktivitas_swap', '0')) === '1';

/* =====================================================================
 * RASIO LEBAR KOLOM (resize kiri-kanan oleh Owner/Admin)
 * ===================================================================== */
$colRatios = [];
$stmt = db()->query("SELECT row_key, ratios FROM dashboard_column_layout");
foreach ($stmt->fetchAll() as $row) {
    $colRatios[$row['row_key']] = (string)$row['ratios'];
}
$rowTopStyle    = !empty($colRatios['row_top']) ? ' style="grid-template-columns:' . e($colRatios['row_top']) . '"' : '';
$rowBottomStyle = !empty($colRatios['row_bottom']) ? ' style="grid-template-columns:' . e($colRatios['row_bottom']) . '"' : '';

/* Subtitle banner (bisa diedit via Pengaturan) */
$bannerSub = getSetting('banner_subtitle', 'Pantau bisnis Anda dengan mudah dan efisien.');

/* KATA HARIAN (fun) — bila mode aktif, teks di bawah nama diganti kata-kata
   motivasi selengean yang berbeda tiap hari. Di-generate AI oleh cron
   public/cron/banner_pantun_harian.php; kalau AI mati → pakai cadangan. */
$bannerPantun = cg_pantun_enabled();
if ($bannerPantun) {
    $p = trim(cg_pantun_current());
    if ($p !== '') { $bannerSub = $p; }
}

/* Status domain (online/offline) — ditampilkan di strip info */
$domainStatus = domainStatus();

/* Foto user yang login (untuk banner) */
$userPhoto = $_SESSION['user_photo'] ?? '';
$meBanner = current_user();
if ($meBanner && !empty($meBanner['photo'])) { $userPhoto = $meBanner['photo']; }

/* ===== RENDER PANEL ===== */
function renderPanel(string $key): void {
    global $topKeys;
    $showHandle = $GLOBALS['canReorder'];
    /* Header control (Owner/Admin): grip drag + tombol sembunyikan (×) */
    $hdr = $showHandle
        ? '<span class="dash-drag" title="Seret untuk pindah posisi"><i class="fas fa-grip-vertical"></i></span>'
        . '<span class="dash-close" data-hide-panel="' . e($key) . '" title="Sembunyikan panel dari dashboard"><i class="fas fa-xmark"></i></span>'
        : '';
    if ($key === 'grafik') {
        /* GRAFIK PENJUALAN */ ?>
        <div class="dash-col" data-panel-key="grafik">
            <div class="panel">
                <div class="panel-header">
                    <?= $hdr ?>
                    <h3 class="panel-title"><i class="fas fa-chart-line"></i> Grafik Penjualan</h3>
                    <span class="tag">30 hari terakhir</span>
                </div>
                <div class="panel-body">
                    <div id="salesChart" style="height: 100%; width: 100%;"></div>
                </div>
            </div>
        </div>
    <?php } elseif ($key === 'pesanan') {
        global $recentOrders; ?>
        <!-- PESANAN TERBARU -->
        <div class="dash-col" data-panel-key="pesanan">
            <div class="panel">
                <div class="panel-header">
                    <?= $hdr ?>
                    <h3 class="panel-title"><i class="fas fa-clock-rotate-left"></i> Pesanan Terbaru</h3>
                    <a href="orders" class="panel-link">Lihat Semua <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>No. Pesanan</th>
                                <th>Pelanggan</th>
                                <th>Status</th>
                                <th class="num">Total</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recentOrders) > 0): ?>
                                <?php foreach ($recentOrders as $o): ?>
                                <tr>
                                    <td class="font-mono text-gold"><?= e($o['order_number']) ?></td>
                                    <td><?= e($o['customer_name'] ?? 'Unknown') ?></td>
                                    <td><span class="<?= getStatusBadgeClass($o['status']) ?>"><?= e(getStatusLabel($o['status'])) ?></span></td>
                                    <td class="num"><?= e(formatRupiah($o['grand_total'])) ?></td>
                                    <td class="text-faint"><?= e(timeAgo($o['updated_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                                            <h3>Belum ada pesanan</h3>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php } elseif ($key === 'produk') {
        global $bestSellers, $productImagePool; ?>
        <!-- PRODUK TERLARIS -->
        <div class="dash-col" data-panel-key="produk">
            <div class="panel">
                <div class="panel-header">
                    <?= $hdr ?>
                    <h3 class="panel-title"><i class="fas fa-fire"></i> Produk Terlaris</h3>
                    <a href="products" class="panel-link">Lihat Semua <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="panel-body" style="padding-top:8px;">
                    <?php if (count($bestSellers) > 0): ?>
                        <?php foreach ($bestSellers as $i => $p):
                            $bsImg = resolveProductImage((string)$p['product_name'], $productImagePool);
                        ?>
                        <div class="product-rank">
                            <span class="rank-no"><?= $i + 1 ?></span>
                            <?php if ($bsImg !== ''): ?>
                                <img src="<?= e($bsImg) ?>" alt="" class="bs-thumb" loading="lazy">
                            <?php else: ?>
                                <span class="bs-thumb bs-thumb-empty"><i class="fas fa-image"></i></span>
                            <?php endif; ?>
                            <div class="rank-info">
                                <div class="rank-name"><?= e($p['product_name']) ?></div>
                                <div class="rank-meta">Terjual <?= (int)$p['qty'] ?> pcs</div>
                            </div>
                            <span class="rank-price"><?= e(formatRupiah($p['price'])) ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-box-open"></i></div>
                            <h3>Belum ada produk terjual</h3>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php } elseif ($key === 'keuangan') { ?>
        <!-- RINGKASAN KEUANGAN -->
        <div class="dash-col" data-panel-key="keuangan">
            <div class="panel">
                <div class="panel-header">
                    <?= $hdr ?>
                    <h3 class="panel-title"><i class="fas fa-coins"></i> Ringkasan Keuangan</h3>
                    <span class="tag">30 hari terakhir</span>
                </div>
                <div class="panel-body">
                    <div class="finance-stats">
                        <div class="finance-row">
                            <span class="f-label"><i class="fas fa-circle-check text-success"></i> Total Pendapatan</span>
                            <span class="f-value text-success"><?= e(formatRupiah($GLOBALS['finRevenue'])) ?></span>
                        </div>
                        <div class="finance-row">
                            <span class="f-label"><i class="fas fa-hand-holding-dollar text-danger"></i> Total Belum Bayar</span>
                            <span class="f-value text-danger"><?= e(formatRupiah($GLOBALS['finPiutang'])) ?></span>
                        </div>
                        <div class="finance-row">
                            <span class="f-label"><i class="fas fa-calculator text-info"></i> Laba Bersih</span>
                            <span class="f-value"><?= e(formatRupiah($GLOBALS['finLaba'])) ?></span>
                        </div>
                        <div class="finance-margin">
                            <span class="f-label"><i class="fas fa-percent"></i> Margin Keuntungan</span>
                            <span class="f-value"><?= e(number_format($GLOBALS['finMargin'], 1)) ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php } elseif ($key === 'whatsapp') {
        global $waStatus, $waSession, $waToday, $waMonth; ?>
        <!-- WHATSAPP CENTER -->
        <div class="dash-col" data-panel-key="whatsapp">
            <div class="panel">
                <div class="panel-header">
                    <?= $hdr ?>
                    <h3 class="panel-title"><i class="fab fa-whatsapp"></i> WhatsApp Center</h3>
                    <a href="whatsapp" class="panel-link">Lihat Semua <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="panel-body">
                    <div class="wa-status-row">
                        <div class="wa-icon"><i class="fab fa-whatsapp"></i></div>
                        <div class="wa-info">
                            <div class="wa-title">
                                Status Koneksi
                                <span class="badge js-wa-badge <?= $waStatus['connected'] ? 'badge-success' : 'badge-danger' ?>">
                                    <i class="fas fa-circle"></i> <?= $waStatus['connected'] ? 'Terhubung' : 'Terputus' ?>
                                </span>
                                <button type="button" class="btn btn-ghost btn-sm js-wa-reconnect-btn js-wa-panel-connect-btn" data-open-reconnect title="<?= $waStatus['connected'] ? 'Status koneksi WhatsApp' : 'WhatsApp terputus — klik untuk scan QR & hubungkan kembali' ?>" style="<?= $waStatus['connected'] ? 'display:none' : '' ?>">
                                    <i class="fas fa-qrcode"></i> <span class="hide-mobile">Hubungkan</span>
                                </button>
                            </div>
                            <div class="wa-session">Session: <?= e($waSession !== '' ? $waSession : '-') ?></div>
                        </div>
                    </div>
                    <div class="wa-stats">
                        <div class="wa-stat">
                            <div class="wa-stat-value text-gold"><?= e($waToday) ?></div>
                            <div class="wa-stat-label">Terkirim Hari Ini</div>
                        </div>
                        <div class="wa-stat">
                            <div class="wa-stat-value text-gold"><?= e($waMonth) ?></div>
                            <div class="wa-stat-label">Terkirim Bulan Ini</div>
                        </div>
                        <div class="wa-stat">
                            <div class="wa-stat-value text-danger">0</div>
                            <div class="wa-stat-label">Gagal Terkirim</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php }
}

/* ===== RENDER KARTU KPI (urut sesuai dashboard_kpi_layout) ===== */
function renderKpi(string $key): void {
    $grip = $GLOBALS['canReorder'] ? '<span class="kpi-drag" title="Seret untuk pindah"><i class="fas fa-grip-vertical"></i></span>' : '';
    $close = $GLOBALS['canReorder'] ? '<span class="kpi-close" data-hide-kpi="' . e($key) . '" title="Sembunyikan kartu"><i class="fas fa-xmark"></i></span>' : '';
    if ($key === 'total_orders') { ?>
    <a href="orders" class="kpi-card" data-kpi-key="total_orders"
       title="Pesanan masuk BULAN INI (<?= e(number_format($GLOBALS['ordersThisMonth'], 0, ',', '.')) ?>) dibanding periode sama bulan lalu">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon gold"><i class="fas fa-box"></i></div>
        <div class="kpi-label">Pesanan Bulan Ini</div>
        <div class="kpi-value" data-count="<?= (int)$GLOBALS['ordersThisMonth'] ?>"><?= e(number_format($GLOBALS['ordersThisMonth'], 0, ',', '.')) ?></div>
        <?php $trend = pctChange($GLOBALS['ordersThisMonth'], $GLOBALS['ordersSameLast']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare"><?= e($GLOBALS['labelAkumulatif']) ?> · <?= e(number_format($GLOBALS['totalOrders'], 0, ',', '.')) ?> total</span>
    </a>
    <?php } elseif ($key === 'total_revenue') { ?>
    <a href="orders?payment=lunas&date_preset=today" class="kpi-card" data-kpi-key="total_revenue">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon green"><i class="fas fa-wallet"></i></div>
        <div class="kpi-label">Pendapatan</div>
        <div class="kpi-value" data-count="<?= (int)round($GLOBALS['todayRevenue']) ?>"><?= e(formatRupiah($GLOBALS['todayRevenue'])) ?></div>
        <?php $trend = pctChange($GLOBALS['todayRevenue'], $GLOBALS['yesterdayRevenue']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare">hari ini · <?= e(formatRupiah($GLOBALS['monthRevenue'])) ?> bulan ini</span>
    </a>
    <?php } elseif ($key === 'today_revenue') { ?>
    <a href="orders?date_preset=today" class="kpi-card" data-kpi-key="today_revenue">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon blue"><i class="fas fa-calendar-day"></i></div>
        <div class="kpi-label">Pesanan</div>
        <div class="kpi-value" data-count="<?= (int)$GLOBALS['todayOrders'] ?>"><?= e($GLOBALS['todayOrders']) ?></div>
        <?php $trend = pctChange($GLOBALS['todayOrders'], $GLOBALS['yesterdayOrders']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare">Hari ini · <?= (int)$GLOBALS['totalOrders'] ?> total</span>
    </a>
    <?php } elseif ($key === 'total_customers') { ?>
    <a href="customers?aktif=1" class="kpi-card" data-kpi-key="total_customers"
       title="Pelanggan yang PERNAH bertransaksi. Total pelanggan terdaftar: <?= e(number_format($GLOBALS['totalCustomers'], 0, ',', '.')) ?>">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon purple"><i class="fas fa-users"></i></div>
        <div class="kpi-label">Pelanggan Aktif</div>
        <div class="kpi-value" data-count="<?= (int)$GLOBALS['activeCustomers'] ?>"><?= e(number_format($GLOBALS['activeCustomers'], 0, ',', '.')) ?></div>
        <?php $trend = pctChange($GLOBALS['activeCustomers'], $GLOBALS['activeCustomersPrev']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare">pernah order · <?= e(number_format($GLOBALS['totalCustomers'], 0, ',', '.')) ?> terdaftar</span>
    </a>
    <?php } elseif ($key === 'active_orders') { ?>
    <a href="orders" class="kpi-card" data-kpi-key="active_orders"
       title="Jumlah ITEM terjual bulan ini (<?= e($GLOBALS['satuanInfo']) ?>)">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon yellow"><i class="fas fa-cubes"></i></div>
        <div class="kpi-label">Produk Terjual</div>
        <div class="kpi-value" data-count="<?= (int)$GLOBALS['productsSold'] ?>"><?= e(number_format($GLOBALS['productsSold'], 0, ',', '.')) ?></div>
        <?php $trend = pctChange($GLOBALS['productsSold'], $GLOBALS['productsSoldPrev']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare">item · <?= e($GLOBALS['satuanInfo']) ?></span>
    </a>
    <?php } elseif ($key === 'unpaid_today') { ?>
    <a href="orders?payment=belum&date_preset=today" class="kpi-card" data-kpi-key="unpaid_today">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon red"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="kpi-label">Belum Bayar Hari Ini</div>
        <div class="kpi-value" data-count="<?= (int)$GLOBALS['unpaidTodayCount'] ?>"><?= e($GLOBALS['unpaidTodayCount']) ?></div>
        <span class="kpi-trend down"><i class="fas fa-hourglass-half"></i> <?= e(formatRupiah($GLOBALS['unpaidTodayTotal'])) ?></span>
        <span class="kpi-compare">belum bayar hari ini</span>
    </a>
    <?php } elseif ($key === 'total_expenses') { ?>
    <a href="expenses" class="kpi-card" data-kpi-key="total_expenses">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon red"><i class="fas fa-receipt"></i></div>
        <div class="kpi-label">Pengeluaran Bulan Ini</div>
        <div class="kpi-value" data-count="<?= (int)round($GLOBALS['expensesMonth']) ?>"><?= e(formatRupiah($GLOBALS['expensesMonth'])) ?></div>
        <?php $trend = pctChange($GLOBALS['expensesMonth'], $GLOBALS['expensesPrev']); ?>
        <?= kpiTrendBadge($trend, false) ?>
        <span class="kpi-compare"><?= e($GLOBALS['labelAkumulatif']) ?></span>
    </a>
    <?php } elseif ($key === 'expenses_today') { ?>
    <a href="expenses" class="kpi-card" data-kpi-key="expenses_today">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon red"><i class="fas fa-wallet"></i></div>
        <div class="kpi-label">Pengeluaran</div>
        <div class="kpi-value" data-count="<?= (int)round($GLOBALS['expensesToday']) ?>"><?= e(formatRupiah($GLOBALS['expensesToday'])) ?></div>
        <?php $trend = pctChange($GLOBALS['expensesToday'], $GLOBALS['expensesTodayPrev']); ?>
        <?= kpiTrendBadge($trend, false) ?>
        <span class="kpi-compare">Hari ini · <?= e(formatRupiah($GLOBALS['expensesMonth'])) ?> bulan ini</span>
    </a>
    <?php } elseif ($key === 'month_revenue') { ?>
    <a href="finance" class="kpi-card" data-kpi-key="month_revenue">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon green"><i class="fas fa-chart-line"></i></div>
        <div class="kpi-label">Pendapatan Bulan Ini</div>
        <div class="kpi-value" data-count="<?= (int)round($GLOBALS['monthRevenue']) ?>"><?= e(formatRupiah($GLOBALS['monthRevenue'])) ?></div>
        <?php $trend = pctChange($GLOBALS['monthRevenue'], $GLOBALS['monthRevenuePrev']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare"><?= e($GLOBALS['labelAkumulatif']) ?></span>
    </a>
    <?php } elseif ($key === 'month_profit') { ?>
    <a href="finance" class="kpi-card" data-kpi-key="month_profit"
       title="Pendapatan − Pengeluaran − HPP bahan − Rugi rusak. Angka ini SAMA dengan halaman Keuangan.">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon gold"><i class="fas fa-piggy-bank"></i></div>
        <div class="kpi-label">Keuntungan Bulan Ini</div>
        <div class="kpi-value" data-count="<?= (int)round($GLOBALS['monthProfit']) ?>"><?= e(formatRupiah($GLOBALS['monthProfit'])) ?></div>
        <?php $trend = pctChange($GLOBALS['monthProfit'], $GLOBALS['monthProfitPrev']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare">setelah HPP <?= e(formatRupiah($GLOBALS['hppMonth'])) ?><?= $GLOBALS['rusakMonth'] > 0 ? ' + rusak ' . e(formatRupiah($GLOBALS['rusakMonth'])) : '' ?></span>
    </a>
    <?php } elseif ($key === 'orders_in_progress') { ?>
    <a href="orders" class="kpi-card" data-kpi-key="orders_in_progress"
       title="Termasuk yang belum dikerjakan (<?= (int)$GLOBALS['pendingOrders'] ?>) + sedang desain/cetak/finishing">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon blue"><i class="fas fa-gears"></i></div>
        <div class="kpi-label">Pesanan Diproses</div>
        <div class="kpi-value" data-count="<?= (int)$GLOBALS['ordersInProgress'] ?>"><?= e($GLOBALS['ordersInProgress']) ?></div>
        <?php $trend = pctChange($GLOBALS['ordersInProgress'], $GLOBALS['ordersInProgressPrev']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare">termasuk <?= (int)$GLOBALS['pendingOrders'] ?> belum mulai</span>
    </a>
    <?php } elseif ($key === 'done_today') { ?>
    <a href="orders?status=siap_ambil" class="kpi-card" data-kpi-key="done_today">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon green"><i class="fas fa-circle-check"></i></div>
        <div class="kpi-label">Selesai Hari Ini</div>
        <div class="kpi-value" data-count="<?= (int)$GLOBALS['doneToday'] ?>"><?= e($GLOBALS['doneToday']) ?></div>
        <?php $trend = pctChange($GLOBALS['doneToday'], $GLOBALS['doneTodayPrev']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare">vs kemarin</span>
    </a>
    <?php } elseif ($key === 'pending_orders') { ?>
    <a href="orders?status=diterima" class="kpi-card" data-kpi-key="pending_orders"
       title="Pesanan berstatus 'Order Masuk & Desain' yang BELUM mulai dikerjakan">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon yellow"><i class="fas fa-hourglass-half"></i></div>
        <div class="kpi-label">Belum Dikerjakan</div>
        <div class="kpi-value" data-count="<?= (int)$GLOBALS['pendingOrders'] ?>"><?= e($GLOBALS['pendingOrders']) ?></div>
        <span class="kpi-trend flat"><i class="fas fa-inbox"></i> dari <?= e($GLOBALS['ordersInProgress']) ?> diproses</span>
        <span class="kpi-compare">order masuk, belum mulai</span>
    </a>
    <?php } elseif ($key === 'total_receivable') { ?>
    <a href="piutang" class="kpi-card" data-kpi-key="total_receivable"
       title="Total tagihan yang belum dibayar (posisi saldo saat ini)">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon red"><i class="fas fa-hand-holding-dollar"></i></div>
        <div class="kpi-label">Total Piutang</div>
        <div class="kpi-value" data-count="<?= (int)round($GLOBALS['totalReceivable']) ?>"><?= e(formatRupiah($GLOBALS['totalReceivable'])) ?></div>
        <?php $trend = pctChange($GLOBALS['totalReceivable'], $GLOBALS['totalReceivablePrev']); ?>
        <?= kpiTrendBadge($trend, false) ?>
        <span class="kpi-compare">posisi akhir bln lalu</span>
    </a>
    <?php } elseif ($key === 'new_customers_today') { ?>
    <a href="customers" class="kpi-card" data-kpi-key="new_customers_today">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon purple"><i class="fas fa-user-plus"></i></div>
        <div class="kpi-label">Pelanggan Baru Hari Ini</div>
        <div class="kpi-value" data-count="<?= (int)$GLOBALS['newCustomersToday'] ?>"><?= e($GLOBALS['newCustomersToday']) ?></div>
        <?php $trend = pctChange($GLOBALS['newCustomersToday'], $GLOBALS['newCustomersTodayPrev']); ?>
        <?= kpiTrendBadge($trend) ?>
        <span class="kpi-compare">vs kemarin</span>
    </a>
    <?php } elseif ($key === 'stor') { ?>
    <!-- ===== KARTU STOR (SETORAN HARIAN) ===== -->
    <!-- Struktur SENGAJA dibuat sama seperti kartu lain (ikon → label → angka →
         tren → 1 baris keterangan) supaya TINGGINYA sama dan ikon semua kartu
         sejajar. Rincian lain ( bulan ini, belum disetor ) pindah ke MODAL —
         sebelumnya ditumpuk di kartu sampai ikonnya naik-turun & tidak enak
         dilihat (keluhan user 12 Okt 2026). -->
    <!-- Bukan <a> (kartu lain pakai link) supaya tombol "Sudah Stor" di dalamnya
         sah secara HTML — tombol di dalam <a> tidak boleh. -->
    <div class="kpi-card kpi-card-stor" data-kpi-key="stor"
         title="Setoran uang yang sudah diserahkan, dipisah cash &amp; transfer. Buka untuk rincian.">
        <?= $grip ?><?= $close ?>
        <div class="kpi-card-icon teal"><i class="fas fa-hand-holding-dollar"></i></div>
        <div class="kpi-label">Stor</div>
        <div class="kpi-value" data-count="<?= (int)round($GLOBALS['storTodayTotal']) ?>"><?= e(formatRupiah($GLOBALS['storTodayTotal'])) ?></div>
        <span class="kpi-compare">
            Cash <?= e(formatRupiah($GLOBALS['storTodayCash'])) ?> · Transfer <?= e(formatRupiah($GLOBALS['storTodayTransfer'])) ?>
        </span>
        <!-- Tombol + status dibuat SATU baris ringkas (btn-xs) dan badge tren
             DIHILANGKAN, supaya tinggi kartu kira-kira sama dengan kartu lain
             → ikon semua kartu tetap sejajar (keluhan user 12 Okt 2026). -->
        <div class="kpi-stor-actions">
            <button type="button" class="btn btn-primary btn-xs js-stor-open" data-open-modal="stor"
                    title="Catat setoran hari ini (cash &amp; transfer)">
                <i class="fas fa-money-bill-transfer"></i> Sudah Stor
            </button>
            <?php if ($GLOBALS['storTodayCount'] > 0): ?>
            <span class="badge badge-success" title="Setoran hari ini sudah dicatat">✓ tercatat</span>
            <?php else: ?>
            <span class="badge badge-warning" title="Belum ada setoran yang dicatat hari ini">belum stor</span>
            <?php endif; ?>
        </div>
    </div>
    <?php }
}

/* =====================================================================
 * KPI + TREN
 * ===================================================================== */
/**
 * Hitung persentase perubahan, dengan penanganan NOL yang jujur.
 *
 * BUG LAMA (ditemukan 2026-10-09 saat pemeriksaan kartu): bila pembandingnya
 * NOL dan angkanya juga NOL, fungsi mengembalikan 0,0% — aman. Tetapi bila
 * pembanding NOL dan angkanya ADA, ia mengembalikan **100,0%**. Di dashboard
 * ini tampil sebagai "PENDAPATAN Rp 0 · 100.0%" dan "PESANAN 0 · 100.0%" —
 * angka 100% itu MENGARAHKAN pembaca ke kesimpulan yang salah, karena
 * sebenarnya tidak ada data pembanding sama sekali (bukan naik 100%).
 *
 * Kembalikan null = "tidak bisa dibandingkan" supaya tampilan bisa menulis
 * "—" atau "baru" alih-alih angka palsu. Pemanggil yang memakai `abs()`
 * tetap aman karena sudah dijaga di sisi tampilan.
 */
function pctChange($current, $previous)
{
    $current  = (float)$current;
    $previous = (float)$previous;

    // Tidak ada pembanding → JANGAN mengarang persentase.
    if ($previous == 0.0) {
        return $current == 0.0 ? 0.0 : null;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

/**
 * HTML lencana tren KPI.
 *
 * Dipakai agar SEMUA kartu konsisten: bila pembandingnya tidak ada, yang
 * tampil "baru" (bukan angka persen yang menyesatkan).
 *
 * @param float|null $trend hasil pctChange()
 * @param bool $naikItuBagus true untuk pendapatan/pesanan, false untuk
 *        pengeluaran (naik = kurang bagus, jadi warnanya dibalik).
 */
function kpiTrendBadge($trend, bool $naikItuBagus = true): string
{
    if ($trend === null) {
        return '<span class="kpi-trend" title="Belum ada pembanding"><i class="fas fa-minus"></i> baru</span>';
    }
    $naik = $trend >= 0;
    $kelas = ($naik === $naikItuBagus) ? 'up' : 'down';
    return '<span class="kpi-trend ' . $kelas . '">'
         . '<i class="fas fa-arrow-' . ($naik ? 'up' : 'down') . '"></i> '
         . e(number_format(abs($trend), 1)) . '%</span>';
}

$monthStart = date('Y-m-01');
$prevStart  = date('Y-m-01', strtotime('-1 month'));

$totalOrders     = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status != 'batal'")->fetchColumn();
$totalOrdersPrev = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status != 'batal' AND order_date >= '$prevStart' AND order_date < '$monthStart'")->fetchColumn();

/* ==== PERIODE PEMBANDING YANG SEHAT (FIX 2026-10-09) ====
 * BUG LAMA: beberapa card membandingkan angka SEPANJANG WAKTU dengan angka
 * SATU BULAN, mis.
 *     "Total Pesanan = 815"  vs  "bulan lalu = 411"  → tren +98,3%
 *     "Pendapatan (semua) = 73,1 jt" vs "bulan lalu = 35 jt" → +108,9%
 *     "Total Piutang = 44,6 jt" vs "piutang bulan lalu = 23,4 jt" → +90,2%
 * Angka-angka itu membuat pemilik mengira toko tumbuh ~100% padahal yang
 * dibandingkan BUKAN hal yang setara (semua-waktu vs 1 bulan; piutang itu
 * SALDO, bukan arus bulanan).
 *
 * Sekarang pembandingnya setara:
 *   - card bulan ini  → dibanding BULAN LALU penuh
 *   - card hari ini   → dibanding KEMARIN
 *   - card akumulatif → dibanding PERIODE SAMA bulan lalu (bukan penuh),
 *     ditandai label "periode sama bulan lalu" supaya jelas.
 * ===================================================================== */
$hariIniAngka   = (int)date('j');                                  // tgl ke-N bulan ini
$akhirBanding   = date('Y-m-d', strtotime($prevStart . ' +' . ($hariIniAngka - 1) . ' day'));
$labelAkumulatif= 'periode sama bln lalu';

/* Pesanan MASUK bulan ini vs periode sama bulan lalu */
$ordersThisMonth = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status!='batal' AND order_date >= '$monthStart'")->fetchColumn();
$ordersSameLast  = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status!='batal' AND order_date >= '$prevStart' AND order_date <= '$akhirBanding'")->fetchColumn();

/* Pendapatan bulan ini vs periode sama bulan lalu */
$totalRevenue     = (float)db()->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status='lunas' AND order_date >= '$monthStart'")->fetchColumn();
$totalRevenuePrev = (float)db()->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status='lunas' AND order_date >= '$prevStart' AND order_date <= '$akhirBanding'")->fetchColumn();

$todayOrders     = (int)db()->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date)=CURDATE() AND status!='batal'")->fetchColumn();
$yesterdayOrders = (int)db()->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date)=DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status!='batal'")->fetchColumn();

$todayRevenue     = (float)db()->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE DATE(order_date)=CURDATE() AND payment_status='lunas' AND status!='batal'")->fetchColumn();
$yesterdayRevenue = (float)db()->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE DATE(order_date)=DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND payment_status='lunas' AND status!='batal'")->fetchColumn();

$totalCustomers = (int)db()->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$customersPrev  = (int)db()->query("SELECT COUNT(*) FROM customers WHERE created_at >= '$prevStart' AND created_at < '$monthStart'")->fetchColumn();

/* Pelanggan AKTIF — benar-benar pernah bertransaksi.
   BUG LAMA (diperbaiki 2026-10-09): card berlabel "Pelanggan Aktif" memakai
   COUNT(*) customers = SEMUA pelanggan (600), padahal 284 di antaranya
   BELUM PERNAH order. Yang benar-benar bertransaksi cuma 316 → labelnya
   menyesatkan. Sekarang dihitung dari pesanan (bukan batal). */
$totalCustomersLabel = 'Pelanggan Aktif';
$activeCustomers     = (int)db()->query(
    "SELECT COUNT(DISTINCT customer_id) FROM orders WHERE status != 'batal'"
)->fetchColumn();
$activeCustomersPrev = (int)db()->query(
    "SELECT COUNT(DISTINCT customer_id) FROM orders
      WHERE status != 'batal' AND order_date >= '$prevStart' AND order_date < '$monthStart'"
)->fetchColumn();

/* Produk terjual — dari SUM(qty) menjadi JUMLAH ITEM.
   BUG LAMA (diperbaiki 2026-10-09): dulu `SUM(oi.qty)` mentah sehingga
   satuan tercampur (lembar 46 + lembar 32 + pcs 27 + m² 16 …) dan hasilnya
   "316" — angka yang TIDAK punya arti karena satuannya beda-beda.
   `order_items` TIDAK punya kolom `unit` (sudah dicek), jadi pengelompokan
   diambil dari nama produk (kata pertama) untuk memberi konteks.
   Angka utama = jumlah ITEM/baris pesanan yang terjual. */
$productsSold     = (int)db()->query("SELECT COUNT(*) FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE o.status!='batal' AND o.order_date >= '$monthStart'")->fetchColumn();
$productsSoldPrev = (int)db()->query("SELECT COUNT(*) FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE o.status!='batal' AND o.order_date >= '$prevStart' AND o.order_date < '$monthStart'")->fetchColumn();

/* Komposisi jenis produk bulan ini (untuk keterangan card "Produk Terjual") */
$satuanRows = db()->query(
    "SELECT SUBSTRING_INDEX(TRIM(oi.product_name), ' ', 1) AS jenis, COUNT(*) AS n
       FROM order_items oi JOIN orders o ON oi.order_id=o.id
      WHERE o.status!='batal' AND o.order_date >= '$monthStart'
      GROUP BY jenis ORDER BY n DESC LIMIT 3"
)->fetchAll();
$satuanTeks = [];
foreach ($satuanRows as $sr) {
    $j = trim((string)($sr['jenis'] ?? ''));
    if ($j !== '') $satuanTeks[] = $j . ' ' . (int)$sr['n'];
}
$satuanInfo = $satuanTeks ? implode(' · ', $satuanTeks) : 'belum ada item';

/* Belum Bayar Hari Ini (widget unpaid_today) */
$unpaidTodayCount = (int)db()->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date)=CURDATE() AND payment_status='belum' AND status!='batal'")->fetchColumn();
$unpaidTodayTotal = (float)db()->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE DATE(order_date)=CURDATE() AND payment_status='belum' AND status!='batal'")->fetchColumn();

/* =====================================================================
 * PENGELUARAN (expenses) — untuk card dashboard
 * Setting owner 'show_expenses_dashboard' mengontrol tampil/tidak.
 * ===================================================================== */
$expensesMonth    = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= '" . date('Y-m-01') . "' AND expense_date < '" . date('Y-m-01', strtotime('+1 month')) . "'")->fetchColumn();
/* Pembanding memakai PERIODE SAMA bulan lalu — bukan bulan lalu penuh.
   BUG LAMA: "Pengeluaran bulan ini (1–9 Okt)" dibanding "seluruh September",
   sehingga awal bulan selalu terlihat turun drastis (-80%) padahal baru
   separuh jalan. Sama dengan kesalahan pada card pendapatan. */
$expensesPrev     = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= '$prevStart' AND expense_date <= '$akhirBanding'")->fetchColumn();
$expensesToday    = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = CURDATE()")->fetchColumn();
$expensesTodayPrev= (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();

/* =====================================================================
 * STOR (SETORAN HARIAN) — untuk kartu dashboard
 * Dicatat HARIAN (cash + transfer), lalu diakumulasi: hari ini & bulan ini.
 * Beberapa catatan per hari boleh (mis. setor pagi & sore) → dijumlahkan.
 *
 * PENTING: "hari" di sini memakai HARI KERJA (mulai jam 06:00), bukan
 * kalender biasa — karena kerja sering lewat tengah malam. Lihat
 * workday_today() di includes/functions.php.
 * ===================================================================== */
$storWorkday   = workday_today();
$storWorkdayH  = workday_start_hour();
/* Apakah hari kerja ini sudah masuk masa pencatatan stor? */
$storWorkdayAktif = ($storWorkday >= stor_start_date());
/* Tanggal default di form: jangan sampai jatuh ke hari SEBELUM masa pencatatan
   (mis. jam 00:30 → hari kerja masih kemarin, padahal pencatatan mulai hari ini).
   Kalau begitu, isikan tanggal mulai supaya setoran tidak masuk hari yg diabaikan. */
$storDefaultDate = $storWorkdayAktif ? $storWorkday : stor_start_date();

$storTodayCash = $storTodayTransfer = $storTodayTotal = 0.0;
$storTodayCount = 0;
try {
    $rq = db()->prepare("SELECT COALESCE(SUM(cash_amount),0) c, COALESCE(SUM(transfer_amount),0) t,
                                COALESCE(SUM(total_amount),0) tot, COUNT(*) n
                         FROM cash_stor WHERE stor_date = ?");
    $rq->execute([$storWorkday]);
    $r = $rq->fetch();
    if ($r) {
        $storTodayCash     = (float)$r['c'];
        $storTodayTransfer = (float)$r['t'];
        $storTodayTotal    = (float)$r['tot'];
        $storTodayCount    = (int)$r['n'];
    }
} catch (Throwable $e) { /* tabel belum ada (deploy lama) */ }

/* Rekap setoran BULAN INI — HANYA dihitung untuk OWNER.
   Admin tidak perlu tahu total penerimaan setoran sebulan (itu informasi omzet),
   jadi querynya sekalian tidak dijalankan (lebih ringan & tidak ada yang bocor). */
$storMonthCash = $storMonthTransfer = $storMonthTotal = 0.0;
$storMonthCount = 0;
$storRevMonth = $storRevMonthTransfer = $storRevMonthCash = 0.0;
$storRevMonthCount = 0;
if ($isOwner) {
    try {
        $mStart = substr($storWorkday, 0, 7) . '-01';
        $mEnd   = date('Y-m-d', strtotime($mStart . ' +1 month'));
        /* (a) SETORAN yang sudah DICATAT admin lewat tombol "Sudah Stor". */
        $rq = db()->prepare("SELECT COALESCE(SUM(cash_amount),0) c, COALESCE(SUM(transfer_amount),0) t,
                                    COALESCE(SUM(total_amount),0) tot, COUNT(*) n
                             FROM cash_stor WHERE stor_date >= ? AND stor_date < ?");
        $rq->execute([$mStart, $mEnd]);
        $r = $rq->fetch();
        if ($r) {
            $storMonthCash     = (float)$r['c'];
            $storMonthTransfer = (float)$r['t'];
            $storMonthTotal    = (float)$r['tot'];
            $storMonthCount    = (int)$r['n'];
        }

        /* (b) UANG MASUK bulan ini = order LUNAS (verified) bulan ini.
         * PENTING (keluhan user 12 Okt 2026): "kan statusnya verified berarti
         * lunas, trus kenapa bulan ini tf-nya masih 0 di sudah stor (modal)?".
         * Sebabnya: kotak "Bulan ini" hanya menampilkan SETORAN YANG DICATAT
         * (uang yang benar-benar sudah diserahkan & dicatat admin) — BUKAN uang
         * masuk dari order. Dua hal itu berbeda: order lunas TIDAK otomatis
         * menjadi "sudah disetor".
         * Supaya jelas, sekarang ditampilkan JUGA uang masuknya (label eksplisit
         * "uang masuk" vs "sudah disetor"). */
        $wExpr = workday_sql_date('order_date');
        $rq = db()->prepare("SELECT COUNT(*) n,
                                    COALESCE(SUM(grand_total),0) tot,
                                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(payment_method,'')) LIKE '%transfer%' THEN grand_total ELSE 0 END),0) trf,
                                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(payment_method,'')) LIKE '%cash%' THEN grand_total ELSE 0 END),0) csh
                             FROM orders
                             WHERE payment_status='lunas' AND status!='batal'
                               AND $wExpr >= ? AND $wExpr < ?");
        $rq->execute([$mStart, $mEnd]);
        $r = $rq->fetch();
        if ($r) {
            $storRevMonth         = (float)$r['tot'];
            $storRevMonthTransfer = (float)$r['trf'];
            $storRevMonthCash     = (float)$r['csh'];
            $storRevMonthCount    = (int)$r['n'];
        }
    } catch (Throwable $e) { /* abaikan */ }
}

/* Hari sebelumnya tidak lagi dihitung: badge tren sengaja TIDAK dipakai di
   kartu Stor supaya tingginya sama dengan kartu lain (ikon sejajar). */

/* Riwayat setoran terakhir (ditampilkan di modal kartu). */
$storRecent = [];
try {
    $storRecent = db()->query("SELECT id, stor_date, cash_amount, transfer_amount, total_amount, note,
                                      created_by_name, created_at
                               FROM cash_stor ORDER BY stor_date DESC, id DESC LIMIT 12")->fetchAll();
} catch (Throwable $e) { $storRecent = []; }

/* =====================================================================
 * BELUM DISETOR — HANYA sejak tanggal mulai pencatatan stor
 * ---------------------------------------------------------------------
 * KELUHAN USER (12 Okt 2026): "perhari ini data yang belum di stor,
 * hitungan sebelum-sebelumnya kan udah pasti selesai di stor dong".
 * BENAR — fitur stor baru dipasang, jadi tidak ada catatan setoran utk hari
 * sebelumnya. Kalau seluruh riwayat dihitung, kartu menampilkan "belum
 * disetor" puluhan juta (tumpukan bulan lalu) padahal uang itu SUDAH beres
 * disetor di luar sistem.
 *
 * MAKA: uang SEBELUM `stor_start_date` dianggap sudah disetor.
 * Rumus: (pendapatan lunas sejak mulai) − (stor sejak mulai), memakai
 * HARI KERJA supaya order lewat tengah malam tidak terbelah.
 * ===================================================================== */
$storStartDate   = stor_start_date();
$storRevSince    = 0.0;   // pendapatan lunas sejak tanggal mulai
$storSince       = 0.0;   // total disetor sejak tanggal mulai
try {
    $wExpr = workday_sql_date('order_date');
    $qRev = db()->prepare("SELECT COALESCE(SUM(grand_total),0) FROM orders
                           WHERE payment_status='lunas' AND status!='batal' AND $wExpr >= ?");
    $qRev->execute([$storStartDate]);
    $storRevSince = (float)$qRev->fetchColumn();

    $qSt = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM cash_stor WHERE stor_date >= ?");
    $qSt->execute([$storStartDate]);
    $storSince = (float)$qSt->fetchColumn();
} catch (Throwable $e) { /* tabel belum ada → 0 */ }
$storBelum = $storRevSince - $storSince;

/* =====================================================================
 * KPI TAMBAHAN: Pendapatan bulan ini, Keuntungan bulan ini, Pesanan diproses
 * ===================================================================== */
$monthRevenue     = (float)db()->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status='lunas' AND order_date >= '" . date('Y-m-01') . "' AND order_date < '" . date('Y-m-01', strtotime('+1 month')) . "'")->fetchColumn();
/* Sama: dibanding PERIODE SAMA bulan lalu, bukan bulan lalu penuh. */
$monthRevenuePrev = (float)db()->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status='lunas' AND order_date >= '$prevStart' AND order_date <= '$akhirBanding'")->fetchColumn();

/* =====================================================================
 * LABA BERSIH — DISAMAKAN DENGAN HALAMAN KEUANGAN (FIX 2026-10-09)
 * ---------------------------------------------------------------------
 * BUG LAMA (serius): card "Keuntungan Bulan Ini" hanya
 *     pendapatan − pengeluaran
 * sehingga HPP bahan & barang rusak TIDAK dikurangkan. Pada data nyata
 * (Okt 2026) card menampilkan Rp 8.140.500 padahal laba bersih
 * Rp 5.460.714 → LEBIH BESAR Rp 2.679.786. Owner jadi mengira untung
 * jauh lebih besar dari kenyataan, padahal belanja bahan bulan itu
 * Rp 21.250.000.
 *
 * Sekarang memakai SUMBER YANG SAMA dengan finance.php:
 *   stock_hpp_period()  → HPP bahan terpakai + rugi barang rusak
 * supaya dashboard & halaman Keuangan TIDAK PERNAH berbeda angka.
 *
 * CATATAN: `stock_hpp_period()` mengembalikan ARRAY, bukan angka.
 * Kalau ditulis (float)stock_hpp_period(...) hasilnya 1 (PHP meng-cast
 * array jadi 1) → pernah membuat kesimpulan "HPP = Rp 1" yang keliru.
 * ===================================================================== */
$bulanIni  = date('Y-m-01');
$bulanDepan= date('Y-m-01', strtotime('+1 month'));
$bulanLalu = date('Y-m-01', strtotime('-1 month'));

$hppBulanIni  = stock_hpp_period($bulanIni, date('Y-m-d'));
$hppBulanLalu = stock_hpp_period($bulanLalu, date('Y-m-d', strtotime($bulanIni . ' -1 day')));

/* HPP ESTIMASI untuk order LAMA (dibuat sebelum 2026-10-09, ukuran bahan
   belum tersimpan di order_items). finance.php SUDAH memakai ini — kalau
   dashboard tidak, dua halaman akan berbeda angka. Wajib sama. */
$hppEstIni  = function_exists('stock_hpp_estimasi_period')
    ? stock_hpp_estimasi_period($bulanIni, date('Y-m-d'))
    : ['hpp' => 0];
$hppEstLalu = function_exists('stock_hpp_estimasi_period')
    ? stock_hpp_estimasi_period($bulanLalu, date('Y-m-d', strtotime($bulanIni . ' -1 day')))
    : ['hpp' => 0];

$hppMonth  = (float)($hppBulanIni['hpp'] ?? 0) + (float)($hppEstIni['hpp'] ?? 0);
$rusakMonth= (float)($hppBulanIni['rugi_rusak'] ?? 0);
$hppPrev   = (float)($hppBulanLalu['hpp'] ?? 0) + (float)($hppEstLalu['hpp'] ?? 0);
$rusakPrev = (float)($hppBulanLalu['rugi_rusak'] ?? 0);

$monthProfit      = $monthRevenue  - $expensesMonth - $hppMonth  - $rusakMonth;
$monthProfitPrev  = $monthRevenuePrev - $expensesPrev - $hppPrev  - $rusakPrev;

$ordersInProgress = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status IN ('diterima','desain','cetak','finishing')")->fetchColumn();
$ordersInProgressPrev = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status IN ('diterima','desain','cetak','finishing') AND order_date >= '" . date('Y-m-01', strtotime('-1 month')) . "' AND order_date < '" . date('Y-m-01') . "'")->fetchColumn();

/* =====================================================================
 * KPI ADMIN: Selesai hari ini, Perlu diproses, Total piutang
 * ===================================================================== */
/* "Selesai Hari Ini" — pakai siap_ambil_at, BUKAN updated_at.
   BUG LAMA (diperbaiki 2026-10-09): memakai DATE(updated_at)=CURDATE().
   `updated_at` berubah SETIAP kali pesanan diedit — jadi mengedit pesanan
   lama bisa membuatnya terhitung "selesai hari ini" (padahal tidak), dan
   pesanan yang benar-benar selesai tapi pernah diedit jadi salah tanggal.
   `siap_ambil_at` diisi sekali saat status berubah ke siap_ambil.
   Cadangan ke updated_at hanya bila siap_ambil_at kosong (data lama). */
$doneToday       = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status='siap_ambil' AND (DATE(siap_ambil_at)=CURDATE() OR (siap_ambil_at IS NULL AND DATE(updated_at)=CURDATE()))")->fetchColumn();
$doneTodayPrev   = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status='siap_ambil' AND (DATE(siap_ambil_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY) OR (siap_ambil_at IS NULL AND DATE(updated_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)))")->fetchColumn();
$pendingOrders   = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status='diterima'")->fetchColumn();
$pendingOrdersPrev = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status='diterima' AND order_date >= '" . date('Y-m-01', strtotime('-1 month')) . "' AND order_date < '" . date('Y-m-01') . "'")->fetchColumn();
$totalReceivable = (float)db()->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status='belum' AND status!='batal'")->fetchColumn();
/* Piutang = SALDO (uang yang masih menggantung), bukan arus bulanan.
   BUG LAMA: dibandingkan dgn "piutang order bulan lalu" (23,4 jt) sehingga
   trennya +90% — membandingkan saldo sekarang dgn saldo lama tidak bermakna.
   Sekarang dibandingkan dgn SALDO pada akhir bulan lalu (posisi setara). */
$totalReceivablePrev = (float)db()->query("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status='belum' AND status!='batal' AND order_date < '$monthStart'")->fetchColumn();
$newCustomersToday     = (int)db()->query("SELECT COUNT(*) FROM customers WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$newCustomersTodayPrev = (int)db()->query("SELECT COUNT(*) FROM customers WHERE DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();

/* =====================================================================
 * GRAFIK 30 HARI (pendapatan lunas per hari)
 * ===================================================================== */
$dailyRevenue = [];
foreach (db()->query("SELECT DATE(order_date) AS d, COALESCE(SUM(grand_total),0) AS total
                      FROM orders WHERE payment_status='lunas' AND order_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                      GROUP BY DATE(order_date)")->fetchAll() as $row) {
    $dailyRevenue[$row['d']] = (float)$row['total'];
}
$chartLabels = [];
$chartValues = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('d M', strtotime($d));
    $chartValues[] = $dailyRevenue[$d] ?? 0;
}

/* =====================================================================
 * PESANAN TERBARU
 * ===================================================================== */
$recentOrders = db()->query("
    SELECT o.id, o.order_number, o.status, o.grand_total, o.updated_at,
           c.name AS customer_name
    FROM orders o LEFT JOIN customers c ON o.customer_id = c.id
    ORDER BY o.updated_at DESC LIMIT 6
")->fetchAll();

function timeAgo($datetime): string
{
    $t = strtotime((string)$datetime);
    if (!$t) return '-';
    $diff = time() - $t;
    if ($diff < 60) return 'baru saja';
    if ($diff < 3600) return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';
    if ($diff < 604800) return floor($diff / 86400) . ' hari lalu';
    return date('d M Y', $t);
}

/* =====================================================================
 * PRODUK TERLARIS
 * ===================================================================== */
$bestSellers = db()->query("
    SELECT oi.product_name, SUM(oi.qty) AS qty, MAX(oi.price_per_unit) AS price
    FROM order_items oi JOIN orders o ON oi.order_id = o.id
    WHERE o.status != 'batal'
    GROUP BY oi.product_name ORDER BY qty DESC LIMIT 5
")->fetchAll();

/* ===== GAMBAR PRODUK untuk Produk Terlaris ===== */
$productImagePool = [];
foreach (db()->query("SELECT category, variant, image FROM product_prices WHERE image IS NOT NULL AND image != ''")->fetchAll() as $pi) {
    $productImagePool[] = $pi;
}
function resolveProductImage(string $name, array $pool): string
{
    $n = mb_strtolower(trim($name));
    if ($n === '') return '';
    foreach ($pool as $pi) {
        $cat = mb_strtolower($pi['category']);
        $var = mb_strtolower($pi['variant']);
        if ($n === $cat || $n === $var || strpos($n, $cat) !== false || strpos($n, $var) !== false || strpos($cat, $n) !== false) {
            return (string)$pi['image'];
        }
    }
    return '';
}

/* =====================================================================
 * AKTIVITAS TERBARU (timeline: order baru, pembayaran, pelanggan baru)
 * ===================================================================== */
$activities = [];
foreach (db()->query("
    SELECT 'order' AS type, o.id, o.order_number, o.status, o.grand_total,
           c.name AS customer_name, o.order_date AS event_time
    FROM orders o LEFT JOIN customers c ON o.customer_id = c.id
    ORDER BY o.order_date DESC LIMIT 20
")->fetchAll() as $row) { $activities[] = $row; }
foreach (db()->query("
    SELECT 'payment' AS type, o.id, o.order_number, o.payment_status, o.grand_total,
           c.name AS customer_name, o.order_date AS event_time
    FROM orders o LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.payment_status = 'lunas'
    ORDER BY o.order_date DESC LIMIT 20
")->fetchAll() as $row) { $activities[] = $row; }
foreach (db()->query("
    SELECT 'customer' AS type, c.id, c.name AS customer_name, c.photo AS customer_photo,
           COALESCE(c.created_at, c.last_reply_at) AS event_time
    FROM customers c
    WHERE c.created_at IS NOT NULL OR c.last_reply_at IS NOT NULL
    ORDER BY c.id DESC LIMIT 20
")->fetchAll() as $row) { $activities[] = $row; }
usort($activities, function ($a, $b) {
    return strtotime((string)($b['event_time'] ?? 'now')) - strtotime((string)($a['event_time'] ?? 'now'));
});
$activities = array_slice($activities, 0, 15);

/* =====================================================================
 * GAMBAR PRODUK UNTUK TIMELINE (order/payment)
 * Dicocokkan nama produk → kata kunci kategori product_prices.
 * ===================================================================== */
$prodImgKeywords = [];
// Ambil gambar TERBARU per kata kunci (hindari placeholder lama)
foreach (db()->query("SELECT category, image FROM product_prices WHERE image IS NOT NULL AND image != '' ORDER BY updated_at DESC, id DESC")->fetchAll() as $row) {
    $parts = preg_split('/\s+/', trim((string)$row['category']));
    $kw = strtolower((string)($parts[0] ?? ''));
    if ($kw !== '' && !isset($prodImgKeywords[$kw])) {
        $prodImgKeywords[$kw] = (string)$row['image'];
    }
}
$orderProdImgs = [];
if ($prodImgKeywords) {
    foreach ($activities as $act) {
        if (($act['type'] ?? '') === 'customer') continue;
        $oid = (int)$act['id'];
        if ($oid <= 0 || isset($orderProdImgs[$oid])) continue;
        $imgs = [];
        $stmt = db()->prepare("SELECT product_name FROM order_items WHERE order_id = ? ORDER BY id LIMIT 6");
        $stmt->execute([$oid]);
        foreach ($stmt->fetchAll() as $item) {
            $nm = strtolower((string)($item['product_name'] ?? ''));
            foreach ($prodImgKeywords as $kw => $img) {
                if (strpos($nm, $kw) !== false) {
                    $imgs[$img] = true;
                    break;
                }
            }
            if (count($imgs) >= 3) break;
        }
        if ($imgs) $orderProdImgs[$oid] = array_keys($imgs);
    }
}

/* =====================================================================
 * RINGKASAN KEUANGAN (30 hari)
 * ===================================================================== */
$fin = db()->query("SELECT
    COALESCE(SUM(CASE WHEN payment_status='lunas' THEN grand_total ELSE 0 END),0) AS rev,
    COALESCE(SUM(CASE WHEN payment_status='belum' THEN grand_total ELSE 0 END),0) AS unpaid,
    COALESCE(SUM(discount),0) AS disc
    FROM orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND status != 'batal'")->fetch();

$finRevenue = (float)$fin['rev'];
$finPiutang = (float)$fin['unpaid'];
$finDisc    = (float)$fin['disc'];
$finLaba    = $finRevenue - $finDisc;
$finMargin  = $finRevenue > 0 ? round(($finLaba / $finRevenue) * 100, 1) : 0;

/* =====================================================================
 * WHATSAPP CENTER
 * ===================================================================== */
$waStatus = checkWhatsAppStatus();
$waSession = (string)whatsapp_config('session_id');
$waToday = (int)db()->query("SELECT COUNT(*) FROM orders o JOIN customers c ON o.customer_id=c.id
                             WHERE DATE(o.order_date)=CURDATE() AND o.status!='batal' AND c.phone != ''")->fetchColumn();
$waMonth = (int)db()->query("SELECT COUNT(*) FROM orders o JOIN customers c ON o.customer_id=c.id
                             WHERE o.order_date >= '$monthStart' AND o.status!='batal' AND c.phone != ''")->fetchColumn();

/* =====================================================================
 * NOTIFIKASI ORDER WA (panel dashboard di samping KPI).
 * Ambil notifikasi terbaru dari wa_order_captures utk ditampilkan ringkas.
 * ===================================================================== */
$waNotifList = [];
$waNotifNewCount = 0;
try {
    $waNotifList = db()->query(
        "SELECT w.id, w.customer_name, w.message, w.products, w.pickup_time, w.status, w.created_at
         FROM wa_order_captures w
         WHERE w.status IN ('baru', 'diproses')
           -- Sembunyikan order WA yang SUDAH dikerjakan: customer-nya sudah punya
           -- order di sistem berstatus desain/cetak/finishing/siap_ambil.
           AND NOT EXISTS (
             SELECT 1 FROM orders o
             JOIN customers c ON o.customer_id = c.id
             WHERE c.chat_id = w.chat_id COLLATE utf8mb4_unicode_ci
               AND o.status IN ('desain','cetak','finishing','siap_ambil')
           )
         ORDER BY w.id DESC LIMIT 30"
    )->fetchAll();
    // Sinkron dgn panel Order WA: sembunyikan yang BELUM ADA ORDER JELAS
    // (produk cuma kata generik / pesan "(belum ada order jelas)").
    $waNotifList = array_values(array_filter($waNotifList, function ($w) {
        return wa_ai_capture_is_clear((string)($w['message'] ?? ''), $w['products'] ?? null);
    }));
    $waNotifList = array_slice($waNotifList, 0, 6);
    $waNotifNewCount = count(array_filter($waNotifList, function ($w) {
        return ($w['status'] ?? '') === 'baru';
    }));
} catch (Throwable $e) {
    $waNotifList = [];
}
$waNotifMeta = [
    'baru'       => ['Baru',       'badge-danger'],
    'diproses'   => ['Diproses',   'badge-info'],
    'selesai'    => ['Selesai',    'badge-success'],
    'dibatalkan' => ['Dibatalkan', 'badge-muted'],
];

/* =====================================================================
 * BAR CHART PENDAPATAN (SVG) untuk welcome banner — animasi looping via JS.
 * Bisa di-setup admin di Pengaturan: tampil/sembunyi, periode per hari
 * atau per jam (jumlah hari/jam bisa dipilih).
 * ===================================================================== */
$chartEnabled = getSetting('chart_enabled', '1') === '1';
$chartMode    = (string)getSetting('chart_mode', 'days'); // days | hours
$chartDays    = (int)getSetting('chart_days', '14');
$chartHours   = (int)getSetting('chart_hours', '24');

// FIX 2026-08-28: pisah tampilan per role.
//   - OWNER: grafik owner (default 7 hari) + backend keuangan.
//   - ADMIN: grafik ADMIN diatur oleh OWNER via settings (chart_admin_*).
//     Owner bisa set periode/hari/jam utk dashboard admin — terpisah dari
//     grafik owner. Default admin: per-hari 1 hari (hari ini saja).
if ($role === 'admin') {
    $chartEnabled  = getSetting('chart_admin_enabled', '1') === '1';
    $chartMode     = (string)getSetting('chart_admin_mode', 'days');
    $chartDays     = (int)getSetting('chart_admin_days', '1');
    $chartHours    = (int)getSetting('chart_admin_hours', '24');
} elseif ($chartDays === 14 && $isOwner) {
    $chartDays = 7;             // owner default 7 hari
}

$chartTitle = 'Pendapatan';
$spark = [];
$bars  = [];
$chartTotal = 0;

if ($chartEnabled) {
    // FIX 2026-08-28: kalau ADMIN diset per-hari dgn 1 hari (default), tampilkan
    // grafik PER JAM 24 jam hari ini — biar tetap visual (1 titik terlalu datar).
    // Judul tetap "Pendapatan Hari Ini". Setting owner (chart_admin_days) tetap
    // dihormati utk nilai 7/14/30 (tampil tren hari).
    if ($role === 'admin' && $chartMode === 'days' && $chartDays <= 1) {
        $chartMode = 'hours';
        $chartHours = 24;
    }
    if ($chartMode === 'hours') {
        $chartHours = in_array($chartHours, [12, 24, 48], true) ? $chartHours : 24;
        $hourly = [];
        foreach (db()->query("SELECT DATE(order_date) AS d, HOUR(order_date) AS h, COALESCE(SUM(grand_total),0) AS total
                              FROM orders WHERE payment_status='lunas'
                              AND order_date >= DATE_SUB(NOW(), INTERVAL {$chartHours} HOUR)
                              GROUP BY DATE(order_date), HOUR(order_date)")->fetchAll() as $row) {
            $key = $row['d'] . ' ' . str_pad((string)$row['h'], 2, '0', STR_PAD_LEFT);
            $hourly[$key] = (float)$row['total'];
        }
        for ($i = $chartHours - 1; $i >= 0; $i--) {
            $ts = time() - $i * 3600;
            $spark[] = $hourly[date('Y-m-d H', $ts)] ?? 0;
        }
        $chartTitle = $role === 'admin' ? 'Pendapatan Hari Ini' : 'Pendapatan ' . $chartHours . ' jam';
    } else {
        // FIX 2026-08-28: admin → ikut setting owner (chart_admin_days),
        // owner → 7/14/30 hari.
        if ($role === 'admin') {
            $chartDays = max(1, min(30, (int)$chartDays));
        } else {
            $chartDays = in_array($chartDays, [7, 14, 30], true) ? $chartDays : 7;
        }
        for ($i = $chartDays - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $spark[] = $dailyRevenue[$d] ?? 0;
        }
        $chartTitle = $role === 'admin'
            ? ($chartDays <= 1 ? 'Pendapatan Hari Ini' : 'Pendapatan ' . $chartDays . ' hari')
            : 'Pendapatan ' . $chartDays . ' hari';
    }

    $cnt = count($spark);
    $barW = 320;
    $barH = 84;
    $padX = 7;
    $padTop = 6;
    $padBot = 10;
    $maxSpark = max(1, max($spark));
    $stepX = $cnt > 1 ? (($barW - 2 * $padX) / ($cnt - 1)) : 0;
    $chartPoints = [];
    foreach ($spark as $i => $v) {
        $x = $padX + $i * $stepX;
        $h = ($v / $maxSpark) * ($barH - $padTop - $padBot);
        $y = $barH - $padBot - $h;
        /* Label tiap titik: jam atau tanggal */
        $off = $cnt - 1 - $i;
        if ($chartMode === 'hours') {
            $lbl = date('d M H:00', time() - $off * 3600);
        } else {
            $lbl = date('d M', strtotime("-$off days"));
        }
        $chartPoints[] = ['x' => $x, 'y' => $y, 'v' => (float)$v, 'label' => $lbl];
    }
    /* Kurva halus Catmull-Rom → cubic bezier */
    $lineD = '';
    $areaD = '';
    $n = count($chartPoints);
    if ($n > 0) {
        $lineD = 'M' . round($chartPoints[0]['x'], 2) . ',' . round($chartPoints[0]['y'], 2);
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $chartPoints[max(0, $i - 1)];
            $p1 = $chartPoints[$i];
            $p2 = $chartPoints[$i + 1];
            $p3 = $chartPoints[min($n - 1, $i + 2)];
            $c1x = $p1['x'] + ($p2['x'] - $p0['x']) / 6;
            $c1y = $p1['y'] + ($p2['y'] - $p0['y']) / 6;
            $c2x = $p2['x'] - ($p3['x'] - $p1['x']) / 6;
            $c2y = $p2['y'] - ($p3['y'] - $p1['y']) / 6;
            $lineD .= ' C' . round($c1x, 2) . ',' . round($c1y, 2)
                    . ' ' . round($c2x, 2) . ',' . round($c2y, 2)
                    . ' ' . round($p2['x'], 2) . ',' . round($p2['y'], 2);
        }
        $areaD = $lineD . ' L' . round($chartPoints[$n - 1]['x'], 2) . ',' . ($barH - $padBot)
               . ' L' . round($chartPoints[0]['x'], 2) . ',' . ($barH - $padBot) . ' Z';
    }
    /* Label sumbu: awal / tengah / akhir */
    $chartLabels = [];
    if ($n > 0) {
        $lblIdx = array_values(array_unique([0, intdiv($n - 1, 2), $n - 1]));
        foreach ($lblIdx as $li) {
            if ($li < $n) {
                $chartLabels[] = ['x' => round($chartPoints[$li]['x'], 2), 't' => $chartPoints[$li]['label']];
            }
        }
    }
    $lastPoint = $n > 0 ? $chartPoints[$n - 1] : null;
    $chartTotal = array_sum($spark);
}

$pageTitle       = 'Dashboard';
$activeMenu      = 'dashboard';
$hidePageHeading = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dash-wrap">

<!-- ===== WELCOME BANNER (tidak bisa di-drag) ===== -->
<?php $bannerBg = brandBanner(); ?>
<div class="welcome-banner" <?= $bannerBg !== '' ? 'style="background-image:linear-gradient(rgba(15,17,24,.72),rgba(15,17,24,.78)), url(' . e($bannerBg) . ');background-size:cover;background-position:center;"' : '' ?>>
    <div class="banner-main">
        <span class="avatar-wrap">
        <span class="banner-avatar">
            <?php if ($userPhoto !== ''): ?>
                <img src="<?= e($userPhoto) ?>" alt="<?= e($username) ?>">
            <?php else: ?>
                <i class="fas fa-user"></i>
            <?php endif; ?>
        </span>
        </span>
        <div class="banner-text">
            <p class="banner-greeting"><i class="fas fa-hands-clapping"></i> Selamat datang kembali,</p>
            <p class="banner-name"><?= e($username) ?> <span class="hl"><?= $isOwner ? '(Owner)' : '' ?></span></p>
            <?php if ($bannerPantun): ?>
                <p class="banner-sub banner-pantun" title="Kata motivasi hari ini">
                    <i class="fas fa-bolt banner-pantun-ico"></i>
                    <span class="banner-pantun-lines"><?php
                        foreach (array_slice(preg_split('/\r\n|\r|\n/', (string)$bannerSub) ?: [], 0, 2) as $pl) {
                            echo '<span class="banner-pantun-line">' . e(trim((string)$pl)) . '</span>';
                        }
                    ?></span>
                </p>
            <?php else: ?>
                <p class="banner-sub"><?= e($bannerSub) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    // FIX 2026-08-28: SLIDESHOW banner — grafik pendapatan + status server
    // bergantian tiap ~60 detik (kalau keduanya aktif). Bila hanya salah satu
    // aktif, tampil statis seperti biasa.
    $serverStatusEnabled = getSetting('server_status_enabled', '0') === '1';
    $bannerHasChart = $chartEnabled;
    $bannerHasSrv   = $serverStatusEnabled;
    $bannerSlideshow = $bannerHasChart && $bannerHasSrv;
    ?>
    <?php if ($bannerHasChart || $bannerHasSrv): ?>
    <div class="banner-side <?= $bannerSlideshow ? 'banner-slideshow' : '' ?>" data-slideshow="<?= $bannerSlideshow ? '1' : '0' ?>">
        <?php if ($bannerHasSrv):
            $srv = dockerServerStatus();
            // FIX 2026-08-28: hanya tampilkan kontainer yang RELEVAN (jalan /
            // restarting). Kontainer mati/sisa lama (exited, dead, created) tidak
            // ditampilkan — dulu tampil merah & menyesatkan (kontainer lama yang
            // sudah tidak dipakai, mis. creativegeek-db tanpa strip).
            $srvVisible = array_values(array_filter($srv['containers'], function ($c) {
                return in_array((string)$c['state'], ['running', 'restarting'], true);
            }));
            // Hitung ulang angka supaya konsisten dgn daftar yang ditampilkan.
            $srvRunning = count(array_filter($srvVisible, function ($c) { return $c['state'] === 'running'; }));
            $srvTotal   = count($srvVisible);
        ?>
        <!-- ===== SLIDE 2: STATUS SERVER DOCKER ===== -->
        <div class="banner-slide banner-slide-srv <?= $bannerSlideshow ? '' : 'is-active' ?>" data-slide="server">
            <div class="banner-srv">
                <div class="banner-srv-head">
                    <span><i class="fas fa-server"></i> Server Docker</span>
                    <span class="banner-srv-live <?= $srv['ok'] ? 'on' : '' ?>"><i class="fas fa-circle"></i> <?= $srv['ok'] ? 'LIVE' : 'OFFLINE' ?></span>
                </div>
                <div class="banner-srv-stats">
                    <div class="srv-stat">
                        <div class="srv-stat-num"><?= (int)$srvRunning ?><small>/<?= (int)$srvTotal ?></small></div>
                        <div class="srv-stat-lbl">Kontainer jalan</div>
                    </div>
                    <div class="srv-stat">
                        <div class="srv-stat-num"><?= (int)$srv['system']['cpu'] ?></div>
                        <div class="srv-stat-lbl">CPU Core</div>
                    </div>
                    <div class="srv-stat">
                        <div class="srv-stat-num"><?= $srv['system']['mem_bytes'] > 0 ? number_format($srv['system']['mem_bytes'] / 1073741824, 0) : '?' ?><small>GB</small></div>
                        <div class="srv-stat-lbl">RAM Total</div>
                    </div>
                </div>
                <div class="banner-srv-list">
                    <?php if (empty($srvVisible)): ?>
                        <div class="srv-empty"><?= $srv['error'] !== '' ? e($srv['error']) : 'Tidak ada kontainer.' ?></div>
                    <?php else: ?>
                        <?php foreach ($srvVisible as $c): ?>
                        <div class="srv-row srv-<?= e($c['state']) ?>">
                            <span class="srv-dot"></span>
                            <span class="srv-name" title="<?= e($c['name']) ?>"><?= e($c['name']) ?></span>
                            <span class="srv-state"><?= $c['state'] === 'running' ? 'jalan' : e($c['state']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="banner-srv-foot">
                    <span><i class="fas fa-database"></i> <?= e($srv['system']['name'] !== '' ? $srv['system']['name'] : 'docker') ?></span>
                    <span class="srv-refresh"><i class="fas fa-rotate"></i> auto</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($bannerHasChart): ?>
        <!-- ===== SLIDE 1: GRAFIK PENDAPATAN ===== -->
        <div class="banner-slide banner-slide-chart <?= $bannerSlideshow ? 'is-active' : '' ?>" data-slide="chart">
            <div class="banner-chart">
                <div class="banner-chart-head">
                    <span><i class="fas fa-chart-column"></i> <?= e($chartTitle) ?></span>
                    <strong><?= formatRupiah((float)$chartTotal) ?></strong>
                </div>
                <svg id="bannerChart" viewBox="0 0 <?= $barW ?> <?= $barH ?>" width="100%" height="84" preserveAspectRatio="none">
                    <defs>
                        <?php $barTop = userPref('theme_accent', getSetting('theme_accent', '#f5a623')); $barBot = themeMix($barTop, '#000000', 0.35); ?>
                        <linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="<?= e($barTop) ?>" stop-opacity="0.5"/>
                            <stop offset="100%" stop-color="<?= e($barTop) ?>" stop-opacity="0.02"/>
                        </linearGradient>
                        <linearGradient id="lineGrad" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stop-color="<?= e($barBot) ?>"/>
                            <stop offset="100%" stop-color="<?= e($barTop) ?>"/>
                        </linearGradient>
                    </defs>
                    <?php if ($lineD !== ''): ?>
                    <path id="areaPath" d="<?= $areaD ?>" fill="url(#areaGrad)" opacity="0"/>
                    <path id="linePath" d="<?= $lineD ?>" fill="none" stroke="url(#lineGrad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="700" stroke-dashoffset="700"/>
                    <g id="chartDots">
                        <?php foreach ($chartPoints as $pi => $pt): ?>
                        <circle class="chart-dot" data-i="<?= $pi ?>" cx="<?= round($pt['x'], 2) ?>" cy="<?= round($pt['y'], 2) ?>" r="0" data-day="<?= e($pt['label']) ?>" data-val="<?= round($pt['v']) ?>">
                            <title><?= e($pt['label']) ?> • Rp <?= number_format((float)$pt['v'], 0, ',', '.') ?></title>
                        </circle>
                        <?php endforeach; ?>
                    </g>
                    <g id="chartLabels" font-size="7.5" fill="#8b8b98" text-anchor="middle">
                        <?php foreach ($chartLabels as $lb): ?>
                        <text x="<?= $lb['x'] ?>" y="<?= $barH - 1.5 ?>"><?= e($lb['t']) ?></text>
                        <?php endforeach; ?>
                    </g>
                    <?php endif; ?>
                    <rect id="barSweep" x="-40" y="0" width="40" height="<?= $barH ?>" fill="rgba(255,255,255,0.10)" rx="4"/>
                </svg>
                <div class="banner-chart-foot">
                    <span><?= $lastPoint ? e($lastPoint['label']) . ' • ' . formatRupiah((float)$lastPoint['v']) : 'terakhir' ?></span>
                    <span class="dot-legend"><i></i> total pendapatan</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($bannerSlideshow): ?>
        <!-- ===== INDIKATOR SLIDE (titik) ===== -->
        <div class="banner-slide-dots" role="tablist" aria-label="Banner bergantian">
            <button type="button" class="banner-slide-dot is-active" data-slide-to="chart" title="Grafik pendapatan" aria-label="Tampilkan grafik"></button>
            <button type="button" class="banner-slide-dot" data-slide-to="server" title="Status server" aria-label="Tampilkan status server"></button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===== STRIP INFO TAMBAHAN (bukan duplikat kartu KPI) ===== -->
<div class="dash-info-strip">
    <div class="dash-info-item">
        <i class="fas fa-globe <?= $domainStatus['online'] ? 'text-success' : 'text-danger' ?>"></i>
        <div class="dash-info-meta">
            <span title="<?= e(siteUrl()) ?>">Domain <?= e(parse_url(siteUrl(), PHP_URL_HOST) ?: '') ?></span>
            <b><?= $domainStatus['online'] ? 'Online' : ('Offline' . ($domainStatus['code'] !== 'err' ? ' (' . $domainStatus['code'] . ')' : '')) ?></b>
        </div>
    </div>
    <div class="dash-info-item js-wa-strip-item">
        <i class="fab fa-whatsapp js-wa-strip-icon <?= $waStatus['connected'] ? 'text-success' : 'text-danger' ?>"></i>
        <div class="dash-info-meta">
            <span>Status WhatsApp</span>
            <b class="js-wa-strip-text"><?= $waStatus['connected'] ? 'Terhubung' : 'Terputus' ?></b>
        </div>
        <?php if (in_array($role, ['owner', 'admin'], true)): ?>
        <button type="button" class="btn btn-ghost btn-icon btn-sm js-wa-reconnect-btn" data-open-reconnect title="<?= $waStatus['connected'] ? 'Lihat status / kelola koneksi WhatsApp' : 'WhatsApp terputus — scan QR untuk hubungkan' ?>" aria-label="Kelola koneksi WhatsApp">
            <i class="fas <?= $waStatus['connected'] ? 'fa-circle-info' : 'fa-qrcode' ?> js-wa-strip-btn-icon"></i>
        </button>
        <?php endif; ?>
    </div>
    <?php if (in_array($role, ['owner', 'admin'], true)): ?>
    <div class="dash-info-item ai-master-toggle">
        <i class="fas fa-robot <?= $aiMasterOn ? 'text-success' : 'text-danger' ?>"></i>
        <div class="dash-info-meta">
            <span>AI Saya (kill switch)</span>
            <b class="<?= $aiMasterOn ? 'text-success' : 'text-danger' ?>"><?= $aiMasterOn ? 'Aktif' : 'Mati' ?></b>
        </div>
        <form method="POST" action="dashboard" class="ai-toggle-form" title="<?= $aiMasterOn ? 'AI aktif untuk Anda — klik untuk matikan' : 'AI mati untuk Anda — klik untuk aktifkan' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="ai_master_toggle" value="<?= $aiMasterOn ? 'off' : 'on' ?>">
            <button type="submit" class="ai-switch <?= $aiMasterOn ? 'on' : 'off' ?>" aria-label="<?= $aiMasterOn ? 'Matikan AI saya' : 'Aktifkan AI saya' ?>">
                <span class="ai-switch-track"><span class="ai-switch-knob"></span></span>
                <span class="ai-switch-label"><?= $aiMasterOn ? 'Hidup' : 'Mati' ?></span>
            </button>
        </form>
    </div>
    <?php endif; ?>
    <div class="dash-info-item">
        <i class="fas fa-calendar-day text-gold"></i>
        <div class="dash-info-meta"><span>Tanggal</span><b><?= e(date('l, d M Y')) ?></b></div>
    </div>
</div>

<?php
// ===== RUNNING TEXT PENGINGAT (di atas section drag) =====
require_once __DIR__ . '/../includes/reminder_data.php';
$reminderCfg = cg_reminder_settings();
$dashTickerMsgs = [];
if ($reminderCfg['enabled'] && $reminderCfg['ticker_enabled']) {
    $dashTickerMsgs = cg_reminder_ticker_messages();
}
$tickerAnim  = preg_replace('/[^a-z0-9\-]/', '', $reminderCfg['ticker_anim'] ?: 'scroll');
$tickerSpeed = in_array($reminderCfg['ticker_speed'], ['slow', 'fast'], true) ? $reminderCfg['ticker_speed'] : 'normal';
$tickerJson  = json_encode(array_map(function ($m) {
    return ['text' => is_string($m) ? $m : ($m['text'] ?? ''), 'type' => is_string($m) ? 'info' : ($m['type'] ?? 'info')];
}, $dashTickerMsgs), JSON_UNESCAPED_UNICODE);
?>
<?php if (!empty($dashTickerMsgs)): ?>
<div class="reminder-ticker ticker-anim-<?= e($tickerAnim) ?> ticker-speed-<?= e($tickerSpeed) ?>"
     id="reminderTicker" aria-label="Pengingat kerjaan"
     data-anim="<?= e($tickerAnim) ?>" data-speed="<?= e($tickerSpeed) ?>" data-messages='<?= e($tickerJson) ?>'>
    <span class="reminder-ticker-icon" title="Pengingat kerjaan"><i class="fas fa-bell"></i></span>
    <span class="reminder-ticker-greet"><?= e(cg_reminder_greeting()) ?></span>
    <div class="reminder-ticker-viewport">
        <div class="reminder-ticker-track">
            <?php foreach ($dashTickerMsgs as $i => $m): ?>
            <span class="reminder-ticker-item ticker-type-<?= e(is_string($m) ? 'info' : ($m['type'] ?? 'info')) ?>" style="--ticker-i: <?= $i ?>"><?= e(is_string($m) ? $m : ($m['text'] ?? '')) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="dashSections">
    <?php foreach ($visibleSections as $sec): ?>
    <div class="dash-section" data-section-key="<?= e($sec) ?>">
        <?php if ($canReorder): ?><span class="section-drag" title="Seret seksi untuk pindah"><i class="fas fa-grip-vertical"></i></span><?php endif; ?>

        <?php if ($sec === 'kpi'): ?>
        <div class="dash-kpi-wrap">
            <div class="kpi-grid" id="kpiGrid">
                <?php foreach ($visibleKpiKeys as $k): renderKpi($k); endforeach; ?>
            </div>
            <!-- ===== PANEL NOTIFIKASI ORDER WA (di samping KPI) ===== -->
            <div class="panel wa-notif-panel">
                <div class="panel-header">
                    <?php if ($canReorder): ?><span class="section-drag section-drag-head" title="Seret untuk pindah"><i class="fas fa-grip-vertical"></i></span><?php endif; ?>
                    <h3 class="panel-title"><i class="fas fa-bell"></i> Order WA
                        <?php if ($waNotifNewCount > 0): ?>
                        <span class="wa-notif-title-count" title="<?= $waNotifNewCount ?> notifikasi baru"><?= $waNotifNewCount > 99 ? '99+' : $waNotifNewCount ?></span>
                        <?php endif; ?>
                    </h3>
                    <div class="d-flex align-center gap-1">
                        <?php if ($waNotifNewCount > 0): ?>
                        <button type="button" class="btn btn-ghost btn-sm js-wa-mark-all" title="Tandai semua sudah dibaca"><i class="fas fa-check-double text-success"></i></button>
                        <?php endif; ?>
                        <a href="wa_notifications" class="btn btn-ghost btn-sm" title="Lihat semua notifikasi"><i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
                <div class="panel-body wa-notif-body">
                    <?php if (empty($waNotifList)): ?>
                        <div class="empty-state" style="padding:20px;">
                            <div class="empty-state-icon" style="width:42px;height:42px;font-size:15px;"><i class="fas fa-bell-slash"></i></div>
                            <p class="text-muted text-sm">Belum ada notifikasi order dari WhatsApp.</p>
                        </div>
                    <?php else: ?>
                        <div class="wa-notif-table">
                            <?php foreach ($waNotifList as $wn):
                                $wnMeta = $waNotifMeta[$wn['status']] ?? ['Baru', 'badge-danger'];
                                $wnProducts = json_decode((string)$wn['products'], true);
                                $wnProducts = is_array($wnProducts) ? array_values(array_filter(array_map('strval', $wnProducts))) : [];
                                $wnPickup = (string)$wn['pickup_time'];
                            ?>
                            <a href="wa_notifications" class="wa-notif-row <?= $wn['status'] === 'baru' ? 'is-new' : '' ?>" data-id="<?= (int)$wn['id'] ?>" data-status="<?= e($wn['status']) ?>">
                                <span class="wa-notif-main">
                                    <span class="wa-notif-name"><?= e((string)($wn['customer_name'] ?: 'Pelanggan')) ?></span>
                                    <?php if (!empty($wnProducts)): ?><span class="wa-notif-prods"><?= e(implode(', ', $wnProducts)) ?></span><?php endif; ?>
                                    <?php if ($wnPickup !== ''): ?><span class="wa-notif-pickup"><i class="fas fa-truck-fast"></i> <?= e($wnPickup) ?></span><?php endif; ?>
                                </span>
                                <span class="wa-notif-side">
                                    <span class="badge <?= e($wnMeta[1]) ?>"><?= e($wnMeta[0]) ?></span>
                                    <span class="wa-notif-time"><?= e(date('d/m H:i', strtotime((string)$wn['created_at']))) ?></span>
                                    <button type="button" class="btn btn-icon btn-sm js-wa-toggle-read" data-id="<?= (int)$wn['id'] ?>"
                                            title="<?= $wn['status'] === 'baru' ? 'Tandai sudah dibaca' : 'Tandai belum dibaca' ?>">
                                        <?php if ($wn['status'] === 'baru'): ?>
                                            <i class="fas fa-check text-success"></i>
                                        <?php else: ?>
                                            <i class="fas fa-rotate-left text-muted"></i>
                                        <?php endif; ?>
                                    </button>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php elseif ($sec === 'row_top'): ?>
        <div class="dash-row-top" id="dashRowTop" data-resize="row_top"<?= $rowTopStyle ?>>
            <?php if ($aktivitasInRowTop && $aktivitasSwap): ?>
            <div class="dash-col" data-panel-key="aktivitas">
                <?php include __DIR__ . '/_aktivitas_panel.php'; ?>
            </div>
            <?php endif; ?>
            <?php foreach ($topKeys as $k): renderPanel($k); endforeach; ?>
            <?php if ($aktivitasInRowTop && !$aktivitasSwap): ?>
            <div class="dash-col" data-panel-key="aktivitas">
                <?php include __DIR__ . '/_aktivitas_panel.php'; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php elseif ($sec === 'row_bottom'): ?>
        <div class="dash-row-bottom" id="dashRowBottom" data-resize="row_bottom"<?= $rowBottomStyle ?>>
            <?php foreach ($bottomKeys as $k): renderPanel($k); endforeach; ?>
        </div>
        <?php elseif ($sec === 'aktivitas'): ?>
<!-- ===== AKTIVITAS TERBARU (timeline) — skip bila sudah digabung ke row_top ===== -->
<?php if (!$aktivitasInRowTop && in_array('recent_activities', $allowedWidgets, true)): ?>
<?php include __DIR__ . '/_aktivitas_panel.php'; ?>
<?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div><!-- /#dashSections -->
</div><!-- /.dash-wrap -->

<?php if ($canWhatsapp): ?>
<!-- ===== WIDGET WHATSAPP MELAYANG (balas langsung dari dashboard) ===== -->
<div class="wa-fab" id="waFab" title="Buka WhatsApp">
    <i class="fab fa-whatsapp"></i>
    <span class="wa-fab-badge" id="waFabBadge" style="display:none;">0</span>
</div>
<div class="wa-widget" id="waWidget" aria-hidden="true">
    <div class="wa-widget-head">
        <div class="wa-widget-title">
            <i class="fab fa-whatsapp"></i>
            <div>
                <b>WhatsApp</b>
                <span class="wa-widget-status" id="waWidgetStatus">memeriksa...</span>
            </div>
        </div>
        <button type="button" class="wa-widget-close" id="waWidgetClose" title="Tutup"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="wa-widget-body">
        <div class="wa-widget-search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" id="waWidgetSearch" placeholder="Cari chat..." autocomplete="off">
        </div>
        <div class="wa-widget-list" id="waWidgetList">
            <div class="wa-widget-loading"><div class="spinner"></div> Memuat chat...</div>
        </div>
        <div class="wa-widget-conv" id="waWidgetConv" style="display:none;">
            <div class="wa-widget-conv-head">
                <button type="button" class="wa-widget-back" id="waWidgetBack" title="Kembali"><i class="fas fa-arrow-left"></i></button>
                <span class="wa-widget-conv-name" id="waWidgetConvName">-</span>
                <span class="wa-widget-conv-refresh" id="waWidgetRefresh" title="Muat ulang"><i class="fas fa-rotate"></i></span>
                <button type="button" class="wa-widget-conv-refresh wa-widget-ai" id="waWidgetAI" title="Bantu balas dengan AI"><i class="fas fa-wand-magic-sparkles"></i></button>
            </div>
            <div class="wa-widget-msgs" id="waWidgetMsgs"></div>
            <div class="wa-widget-drop" id="waWidgetDrop" aria-hidden="true">
                <i class="fas fa-image"></i>
                <span>Lepaskan gambar untuk mengirim</span>
            </div>
            <div class="wa-widget-input">
                <label class="wa-widget-attach" id="waWidgetAttach" title="Kirim gambar">
                    <i class="fas fa-image"></i>
                    <input type="file" id="waWidgetFile" accept="image/*" hidden>
                </label>
                <textarea id="waWidgetInput" rows="1" placeholder="Ketik balasan... atau seret gambar ke sini"></textarea>
                <button type="button" class="btn btn-primary" id="waWidgetSend" title="Kirim"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== LIGHTBOX GAMBAR WA (zoom + unduh) ===== -->
<div class="wa-lightbox" id="waLightbox" aria-hidden="true">
    <div class="wa-lightbox-top">
        <span class="wa-lightbox-title"><i class="fas fa-image"></i> Gambar</span>
        <div class="wa-lightbox-actions">
            <button type="button" class="wa-lightbox-btn" id="waLightboxDownload" title="Unduh gambar" aria-label="Unduh gambar"><i class="fas fa-download"></i></button>
            <button type="button" class="wa-lightbox-btn" id="waLightboxClose" title="Tutup (Esc)" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
    </div>
    <div class="wa-lightbox-stage" id="waLightboxStage">
        <img id="waLightboxImg" alt="Pratinjau gambar">
    </div>
    <div class="wa-lightbox-zoom">
        <button type="button" class="wa-lightbox-btn" id="waLightboxZoomOut" title="Perkecil" aria-label="Perkecil"><i class="fas fa-minus"></i></button>
        <span class="wa-lightbox-pct" id="waLightboxZoomPct">100%</span>
        <button type="button" class="wa-lightbox-btn" id="waLightboxZoomIn" title="Perbesar" aria-label="Perbesar"><i class="fas fa-plus"></i></button>
        <span class="wa-lightbox-hint">klik gambar / gulir = zoom</span>
    </div>
</div>

<?php if (!empty($kpiVisible['stor'])): ?>
<!-- =====================================================================
     MODAL STOR (SETORAN HARIAN) — dibuka dari kartu "Stor" di dashboard
     Admin/owner mengisi nominal CASH & TRANSFER, lalu tersimpan sebagai
     1 catatan setoran. Beberapa kali sehari boleh (mis. pagi & sore).
     ===================================================================== -->
<div class="modal-backdrop" id="modal-stor" aria-hidden="true">
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="storModalTitle">
        <div class="modal-header">
            <h3 class="modal-title" id="storModalTitle"><i class="fas fa-hand-holding-dollar"></i> Sudah Stor</h3>
            <button type="button" class="modal-close" data-close-modal="stor" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="stor-hero">
                <div class="stor-hero-main">
                    <span class="stor-hero-label">Stor hari ini</span>
                    <span class="stor-hero-value" id="storHeroToday"><?= e(formatRupiah($storTodayTotal)) ?></span>
                </div>
                <div class="stor-hero-sub">
                    Cash <b><?= e(formatRupiah($storTodayCash)) ?></b> · Transfer <b><?= e(formatRupiah($storTodayTransfer)) ?></b><?= $storTodayCount > 0 ? ' · ' . (int)$storTodayCount . '× setor' : '' ?>
                </div>
                <!-- Rincian tambahan yang DULU menumpuk di kartu dashboard,
                     sekarang ditaruh di sini supaya kartunya rapi & ikon sejajar.
                     PENTING: "Bulan ini" (omzet setoran sebulan) HANYA untuk
                     OWNER — admin tidak perlu tahu total penerimaan sebulan
                     (keluhan user 12 Okt 2026: "admin masa perlu tau bulan ini
                     dapat berapa, aneh bgt"). "Belum disetor" tetap tampil utk
                     semua karena admin memang butuh tahu sisa uang yang harus
                     disetor. -->
                <div class="stor-hero-grid<?= $isOwner ? '' : ' is-single' ?>">
                    <?php if ($isOwner): ?>
                    <div>
                        <span class="stor-hero-grid-label">Uang masuk bulan ini</span>
                        <b><?= e(formatRupiah($storRevMonth)) ?></b>
                        <span class="stor-hero-grid-sub">
                            <?= (int)$storRevMonthCount ?> order lunas
                            <?php if ($storRevMonthTransfer > 0 || $storRevMonthCash > 0): ?>
                                · trf <?= e(formatRupiah($storRevMonthTransfer)) ?> · cash <?= e(formatRupiah($storRevMonthCash)) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div>
                        <span class="stor-hero-grid-label">Belum disetor</span>
                        <b class="<?= $storBelum > 0 ? 'text-warning' : 'text-success' ?>"><?= e(formatRupiah(max(0.0, $storBelum))) ?></b>
                        <span class="stor-hero-grid-sub">
                            <?php if ($storBelum > 0): ?>
                                pendapatan lunas − stor
                            <?php else: ?>
                                semua sudah disetor
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <?php if ($isOwner): ?>
                <!-- Baris penjelas: "uang masuk" ≠ "sudah disetor". Order lunas
                     TIDAK otomatis terhitung sebagai setoran — setoran dicatat
                     manual lewat tombol "Sudah Stor" (uang yang benar-benar
                     diserahkan). Tanpa baris ini, owner bingung melihat
                     "bulan ini Rp 0" padahal banyak order lunas. -->
                <div class="stor-hero-split">
                    Sudah disetor bulan ini: <b><?= e(formatRupiah($storMonthTotal)) ?></b>
                    <span class="text-muted">(<?= (int)$storMonthCount ?>× setor<?= $storMonthTotal > 0 ? ' · cash ' . e(formatRupiah($storMonthCash)) . ' · trf ' . e(formatRupiah($storMonthTransfer)) : '' ?>)</span>
                </div>
                <?php endif; ?>
                <div class="stor-hero-note">
                    <i class="fas fa-circle-info"></i>
                    “Belum disetor” dihitung sejak <b><?= e(formatDate($storStartDate, 'd/m/Y')) ?></b>
                    — uang sebelum tanggal itu dianggap sudah disetor.
                </div>
                <?php if ($isOwner): ?>
                <div class="stor-hero-sub">
                    <!-- Halaman Keuangan khusus owner (requireOwner) — tautan
                         hanya ditampilkan untuk owner supaya admin tidak
                         diarahkan ke halaman yang tidak dia punya akses. -->
                    <a href="finance" class="stor-hero-link">Lihat rekap di Keuangan <i class="fas fa-arrow-right"></i></a>
                </div>
                <?php endif; ?>
            </div>

            <form id="storForm" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label" for="storDate">Tanggal rekap</label>
                    <input type="date" name="stor_date" id="storDate" class="form-input" value="<?= e($storDefaultDate) ?>">
                    <div class="form-hint">
                        Rekap harian dihitung <b><?= sprintf('%02d:00', $storWorkdayH) ?></b> sampai
                        <b><?= sprintf('%02d:59', ($storWorkdayH + 23) % 24) ?></b> besoknya — karena kerja sering
                        lewat tengah malam, setoran jam 01:00 masuk hari sebelumnya. Tanggal sudah diisi otomatis.
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="storCash"><i class="fas fa-money-bill-wave"></i> Cash (Rp)</label>
                    <input type="text" inputmode="numeric" name="cash_amount" id="storCash" class="form-input" placeholder="mis. 500.000">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="storTransfer"><i class="fas fa-building-columns"></i> Transfer (Rp)</label>
                    <input type="text" inputmode="numeric" name="transfer_amount" id="storTransfer" class="form-input" placeholder="mis. 1.000.000">
                </div>
                <div class="stor-total-preview">Total setoran: <b id="storTotalPreview">Rp 0</b></div>
                <div class="mb-3">
                    <label class="form-label" for="storNote">Keterangan <span class="hint">(opsional)</span></label>
                    <input type="text" name="note" id="storNote" class="form-input" maxlength="255" placeholder="mis. setor ke owner">
                </div>
                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stor">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="storSaveBtn"><i class="fas fa-check"></i> Simpan Setoran</button>
                </div>
                <div class="stor-msg" id="storMsg" role="status" aria-live="polite"></div>
            </form>

            <div class="stor-history">
                <div class="stor-history-head">
                    <span><i class="fas fa-clock-rotate-left"></i> Riwayat setoran</span>
                    <span class="text-muted text-sm">12 terakhir</span>
                </div>
                <div class="stor-history-list">
                    <?php if (empty($storRecent)): ?>
                        <div class="stor-history-empty">Belum ada catatan setoran.</div>
                    <?php else: ?>
                        <?php foreach ($storRecent as $sr):
                            $srTotal = (float)$sr['total_amount'];
                            $srCash  = (float)$sr['cash_amount'];
                            $srTrf   = (float)$sr['transfer_amount'];
                        ?>
                        <div class="stor-history-row" data-stor-id="<?= (int)$sr['id'] ?>">
                            <div class="stor-history-line1">
                                <b><?= e(formatDate((string)$sr['stor_date'], 'd/m/Y')) ?></b>
                                <span class="stor-history-total"><?= e(formatRupiah($srTotal)) ?></span>
                                <button type="button" class="btn btn-icon btn-sm js-stor-del" data-stor-id="<?= (int)$sr['id'] ?>" title="Hapus catatan ini"><i class="fas fa-trash text-danger"></i></button>
                            </div>
                            <div class="stor-history-line2">
                                cash <?= e(formatRupiah($srCash)) ?> · trf <?= e(formatRupiah($srTrf)) ?>
                                <?php if ((string)($sr['note'] ?? '') !== ''): ?> · <?= e((string)$sr['note']) ?><?php endif; ?>
                                <?php if ((string)($sr['created_by_name'] ?? '') !== ''): ?> · <?= e((string)$sr['created_by_name']) ?><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('storForm');
    if (!form) return;

    var cashEl  = document.getElementById('storCash');
    var trfEl   = document.getElementById('storTransfer');
    var prevEl  = document.getElementById('storTotalPreview');
    var msgEl   = document.getElementById('storMsg');
    var btnEl   = document.getElementById('storSaveBtn');

    // Format titik ribuan saat mengetik (25000 -> 25.000). Server tetap
    // menerima titik (nota_supplier_angka paham format Indonesia).
    // PENTING: JANGAN pakai (float) di server — "27.000" jadi 27.
    function fmtThousands(s) {
        var raw = String(s || '').replace(/[^\d]/g, '');
        return raw.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    function toNum(s) {
        var raw = String(s || '').replace(/[^\d]/g, '');
        return raw === '' ? 0 : parseInt(raw, 10);
    }
    function rupiah(n) {
        return 'Rp ' + (n || 0).toLocaleString('id-ID');
    }
    function refreshPreview() {
        var tot = toNum(cashEl.value) + toNum(trfEl.value);
        prevEl.textContent = rupiah(tot);
        prevEl.classList.toggle('is-empty', tot === 0);
    }
    [cashEl, trfEl].forEach(function (el) {
        el.addEventListener('input', function () {
            var atEnd = this.selectionStart === this.value.length;
            this.value = fmtThousands(this.value);
            if (atEnd) { try { this.setSelectionRange(this.value.length, this.value.length); } catch (e) {} }
            refreshPreview();
        });
    });

    // Tombol simpan
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var cash = toNum(cashEl.value);
        var trf  = toNum(trfEl.value);
        if (cash + trf <= 0) {
            msgEl.className = 'stor-msg is-error';
            msgEl.textContent = 'Isi dulu nominal cash dan/atau transfer.';
            return;
        }
        var csrfInput = document.querySelector('input[name="csrf_token"]');
        var fd = new FormData();
        fd.append('action', 'stor_save');
        fd.append('csrf_token', csrfInput ? csrfInput.value : '');
        fd.append('stor_date', document.getElementById('storDate').value || '');
        fd.append('cash_amount', cashEl.value || '0');
        fd.append('transfer_amount', trfEl.value || '0');
        fd.append('note', document.getElementById('storNote').value || '');

        btnEl.disabled = true;
        msgEl.className = 'stor-msg';
        msgEl.textContent = 'Menyimpan…';
        fetch('dashboard', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) {
                    msgEl.className = 'stor-msg is-ok';
                    msgEl.textContent = (d.message || 'Tersimpan.') + ' Memuat ulang…';
                    setTimeout(function () { location.reload(); }, 700);
                } else {
                    btnEl.disabled = false;
                    msgEl.className = 'stor-msg is-error';
                    msgEl.textContent = (d && d.message) ? d.message : 'Gagal menyimpan.';
                }
            })
            .catch(function () {
                btnEl.disabled = false;
                msgEl.className = 'stor-msg is-error';
                msgEl.textContent = 'Gagal menghubungi server.';
            });
    });

    // Hapus catatan setoran (dengan konfirmasi)
    document.querySelectorAll('.js-stor-del').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-stor-id');
            if (!id) return;
            if (!confirm('Hapus catatan setoran ini?')) return;
            var csrfInput = document.querySelector('input[name="csrf_token"]');
            var fd = new FormData();
            fd.append('action', 'stor_delete');
            fd.append('csrf_token', csrfInput ? csrfInput.value : '');
            fd.append('id', id);
            btn.disabled = true;
            fetch('dashboard', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.ok) { location.reload(); }
                    else { btn.disabled = false; alert((d && d.message) ? d.message : 'Gagal menghapus.'); }
                })
                .catch(function () { btn.disabled = false; alert('Gagal menghubungi server.'); });
        });
    });

    // Reset pesan tiap modal dibuka + hitung ulang pratinjau
    document.querySelectorAll('[data-open-modal="stor"]').forEach(function (b) {
        b.addEventListener('click', function () {
            msgEl.className = 'stor-msg';
            msgEl.textContent = '';
            btnEl.disabled = false;
            refreshPreview();
        });
    });
    refreshPreview();
});
</script>
<?php endif; ?>

<?php if (in_array($role, ['owner', 'admin'], true)): ?>
<!-- ===== MODAL HUBUNGKAN WHATSAPP (QR auto-refresh) ===== -->
<div class="modal-backdrop" id="waReconnectModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="waReconnectTitle">
        <div class="modal-header">
            <h3 class="modal-title" id="waReconnectTitle"><i class="fab fa-whatsapp"></i> Hubungkan WhatsApp</h3>
            <button type="button" class="modal-close js-wa-reconnect-close" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <!-- Status ringkas -->
            <div class="d-flex align-center gap-2" style="margin-bottom:14px;">
                <span class="badge js-wa-modal-badge badge-danger" style="flex-shrink:0;"><i class="fas fa-circle"></i> Menghubungkan…</span>
                <span class="text-sm text-muted js-wa-modal-sub" style="min-width:0; flex:1; overflow-wrap:anywhere;"></span>
            </div>

            <!-- Area QR -->
            <div id="waReconnectQrBox" style="text-align:center; padding:8px 0;">
                <div class="qr-frame" style="display:inline-block; background:#fff; border-radius:12px; padding:12px; box-shadow:var(--shadow-lg);">
                    <img id="waReconnectQrImg" class="wa-qr-img" alt="QR Code WhatsApp" src="" style="display:block; image-rendering:pixelated;">
                </div>
                <div class="text-sm text-muted" style="margin-top:10px;">
                    <i class="fas fa-qrcode"></i> Scan QR ini dengan <b>WhatsApp di HP</b> →
                    <b>Settings</b> → <b>Linked Devices</b> → <b>Link a Device</b>
                </div>
                <div class="js-wa-qr-status text-sm" style="margin-top:6px; color:var(--gold);"></div>
            </div>

            <!-- Ringkasan terhubung -->
            <div id="waReconnectOkBox" style="display:none; text-align:center; padding:18px 0;">
                <div style="font-size:52px; color:var(--success,#2ecc71);"><i class="fas fa-circle-check"></i></div>
                <div class="h4" style="margin:8px 0 2px;">WhatsApp Terhubung</div>
                <div class="text-sm text-muted js-wa-modal-ok-detail">Perangkat aktif</div>
            </div>

            <div class="alert alert-warning" style="margin-top:8px;">
                <i class="fas fa-info-circle"></i>
                <span>QR diperbarui otomatis tiap beberapa detik. Jika QR tidak muncul, klik
                <b>Muat Ulang QR</b>. <b>Logout</b> dipakai bila ingin memutus & memasang ulang di HP lain.</span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost js-wa-logout-btn" title="Putuskan link WhatsApp dari server (perlu scan ulang)">
                <i class="fas fa-right-from-bracket"></i> Logout
            </button>
            <button type="button" class="btn btn-secondary js-wa-qr-refresh" title="Minta QR baru dari server">
                <i class="fas fa-rotate"></i> Muat Ulang QR
            </button>
            <button type="button" class="btn btn-primary js-wa-reconnect-close"><i class="fas fa-check"></i> Selesai</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
/* ===== TANDAI BACA / BELUM BACA NOTIFIKASI ORDER WA (AJAX) ===== */
document.addEventListener('DOMContentLoaded', function () {
    const panel = document.querySelector('.wa-notif-panel');
    if (!panel) return;

    const csrfInput = document.querySelector('input[name="csrf_token"]');
    const csrfVal = csrfInput ? csrfInput.value : '';

    function refreshPanelBadge(newCount) {
        const titleCount = panel.querySelector('.wa-notif-title-count');
        const markAllBtn = panel.querySelector('.js-wa-mark-all');
        if (newCount > 0) {
            if (!titleCount) {
                const h3 = panel.querySelector('.panel-title');
                if (h3) {
                    h3.insertAdjacentHTML('beforeend', '<span class="wa-notif-title-count" title="' + newCount + ' notifikasi baru">' + (newCount > 99 ? '99+' : newCount) + '</span>');
                }
            } else {
                titleCount.textContent = newCount > 99 ? '99+' : newCount;
                titleCount.title = newCount + ' notifikasi baru';
            }
            if (!markAllBtn) {
                const head = panel.querySelector('.panel-header .d-flex');
                if (head) {
                    head.insertAdjacentHTML('afterbegin', '<button type="button" class="btn btn-ghost btn-sm js-wa-mark-all" title="Tandai semua sudah dibaca"><i class="fas fa-check-double text-success"></i></button>');
                    bindMarkAll();
                }
            }
        } else {
            if (titleCount) titleCount.remove();
            if (markAllBtn) markAllBtn.remove();
        }
    }

    function setRowStatus(row, status) {
        row.classList.toggle('is-new', status === 'baru');
        row.dataset.status = status;
        const badge = row.querySelector('.badge');
        if (badge) {
            if (status === 'baru') { badge.textContent = 'Baru'; badge.className = 'badge badge-danger'; }
            else { badge.textContent = 'Diproses'; badge.className = 'badge badge-info'; }
        }
        const btn = row.querySelector('.js-wa-toggle-read');
        if (btn) {
            btn.title = status === 'baru' ? 'Tandai sudah dibaca' : 'Tandai belum dibaca';
            btn.innerHTML = status === 'baru'
                ? '<i class="fas fa-check text-success"></i>'
                : '<i class="fas fa-rotate-left text-muted"></i>';
        }
    }

    function post(data) {
        const fd = new FormData();
        fd.append('csrf_token', csrfVal);
        Object.keys(data).forEach(k => fd.append(k, data[k]));
        return fetch('dashboard', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json());
    }

    function bindMarkAll() {
        panel.querySelectorAll('.js-wa-mark-all').forEach(btn => {
            btn.addEventListener('click', async function () {
                const old = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                try {
                    const d = await post({ action: 'mark_all_read' });
                    if (d.ok) {
                        panel.querySelectorAll('.wa-notif-row').forEach(row => setRowStatus(row, 'diproses'));
                        refreshPanelBadge(d.new_count || 0);
                    }
                } catch (e) { /* ignore */ }
                btn.disabled = false;
                btn.innerHTML = old;
            });
        });
    }

    panel.querySelectorAll('.js-wa-toggle-read').forEach(btn => {
        btn.addEventListener('click', async function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            const row = btn.closest('.wa-notif-row');
            const id = btn.dataset.id;
            const next = (row && row.dataset.status === 'baru') ? 'diproses' : 'baru';
            const old = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            try {
                const d = await post({ action: 'mark_read', id: id, mark_as: next });
                if (d.ok) {
                    if (row) setRowStatus(row, next);
                    refreshPanelBadge(d.new_count || 0);
                }
            } catch (e) { /* ignore */ }
            btn.disabled = false;
            btn.innerHTML = old;
        });
    });
    bindMarkAll();
});
</script>

<?php if (in_array($role, ['owner', 'admin'], true)): ?>
<script>
/* ===== WHATSAPP RECONNECT MODAL (auto QR refresh) =====
 * - Polling status tiap 5 dtk lewat whatsapp_ajax?action=get_connection.
 * - Kalau WA putus (qr_ready/disconnected), tombol Hubungkan muncul &
 *   modal otomatis terbuka (sekali per sesi halaman) supaya admin tinggal scan.
 * - Saat modal terbuka: panggil wa_start lalu tampilkan QR; QR auto-refresh
 *   tiap beberapa detik selama status belum 'ready'.
 */
document.addEventListener('DOMContentLoaded', function () {
    // Hanya dirender utk owner/admin (blok PHP di atas) → modal pasti ada.
    const modal     = document.getElementById('waReconnectModal');
    if (!modal) return;

    const qrBox     = document.getElementById('waReconnectQrBox');
    const okBox     = document.getElementById('waReconnectOkBox');
    const qrImg     = document.getElementById('waReconnectQrImg');
    const badge     = modal.querySelector('.js-wa-modal-badge');
    const sub       = modal.querySelector('.js-wa-modal-sub');
    const qrStatus  = modal.querySelector('.js-wa-qr-status');
    const okDetail  = modal.querySelector('.js-wa-modal-ok-detail');
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    const csrfVal   = csrfInput ? csrfInput.value : '';

    let pollTimer   = null;
    let qrTimer     = null;
    let modalOpen   = false;
    let autoShown   = false;   // auto-popup hanya SEKALI per muat halaman
    let starting    = false;
    let lastWasConnected = null;
    let qrSeq       = 0;

    // ---- update tampilan status di dashboard ----
    function updateDashboardUi(status, connected, phone) {
        // Tombol di strip info: selalu tampil utk owner/admin; ikon berubah.
        // Ikon QR saat putus (mengundang scan), info saat terhubung (kelola).
        const stripBtnIcon = document.querySelector('.js-wa-strip-btn-icon');
        if (stripBtnIcon) {
            stripBtnIcon.className = 'fas js-wa-strip-btn-icon ' + (connected ? 'fa-circle-info' : 'fa-qrcode');
        }
        // Tombol "Hubungkan" di WhatsApp Center panel: hanya saat putus.
        document.querySelectorAll('.js-wa-badge').forEach(function (b) {
            b.className = 'badge js-wa-badge ' + (connected ? 'badge-success' : 'badge-danger');
            b.innerHTML = connected
                ? '<i class="fas fa-circle"></i> Terhubung'
                : '<i class="fas fa-circle"></i> Terputus';
        });
        // Tombol reconnect di WhatsApp Center panel (label "Hubungkan") hanya saat putus
        document.querySelectorAll('.js-wa-panel-connect-btn').forEach(function (btn) {
            btn.style.display = connected ? 'none' : '';
        });
        // Strip info atas
        const stripItem = document.querySelector('.js-wa-strip-item');
        if (stripItem) {
            const icon = stripItem.querySelector('.js-wa-strip-icon');
            const txt  = stripItem.querySelector('.js-wa-strip-text');
            if (icon) icon.className = 'fab fa-whatsapp js-wa-strip-icon ' + (connected ? 'text-success' : 'text-danger');
            if (txt)  txt.textContent = connected ? 'Terhubung' : 'Terputus';
        }
    }

    // ---- update tampilan dalam modal ----
    function updateModal(status, connected, phone, pushName) {
        const name = pushName || phone || '';
        if (connected) {
            qrBox.style.display = 'none';
            okBox.style.display = '';
            badge.className = 'badge js-wa-modal-badge badge-success';
            badge.innerHTML = '<i class="fas fa-circle"></i> Terhubung';
            sub.textContent = '';
            okDetail.textContent = name ? (phone + ' • ' + name) : (phone || 'Perangkat aktif');
        } else {
            qrBox.style.display = '';
            okBox.style.display = 'none';
            badge.className = 'badge js-wa-modal-badge badge-danger';
            badge.innerHTML = '<i class="fas fa-circle"></i> ' + (status === 'qr_ready' || status === 'scanning' ? 'Menunggu Scan' : 'Terputus');
            sub.textContent = status === 'qr_ready' ? 'QR siap — scan dengan HP' : (status || '');
        }
    }

    // ---- set status kecil di bawah QR ----
    function setQrStatus(msg, isErr) {
        if (qrStatus) {
            qrStatus.innerHTML = msg;
            qrStatus.style.color = isErr ? 'var(--danger,#e74c3c)' : 'var(--gold,#f5a623)';
        }
    }

    // ---- ambil QR dari server & tampilkan ----
    async function loadQr() {
        const seq = ++qrSeq;
        try {
            const res = await fetch('whatsapp_ajax?action=get_connection', { credentials: 'same-origin' });
            const d = await res.json();
            if (seq !== qrSeq) return; // sudah ada request lebih baru
            if (d.connected) {
                updateModal(d.status, true, d.phone, d.pushName);
                return;
            }
            updateModal(d.status, false, d.phone, d.pushName);
            if (d.qr) {
                qrImg.src = d.qr;
                setQrStatus('QR siap — scan sekarang');
            } else if (d.throttled) {
                /* Server WA sedang membatasi permintaan (429). Pesan lama
                 * "QR belum tersedia" membuat operator mengira fiturnya rusak,
                 * padahal QR-nya ada dan hanya belum boleh diambil. */
                setQrStatus('Server WA sedang sibuk (batas permintaan) — QR akan muncul otomatis…');
            } else {
                setQrStatus('QR belum tersedia, mencoba lagi…');
            }
        } catch (e) {
            if (seq !== qrSeq) return;
            setQrStatus('Gagal ambil QR — cek koneksi server', true);
        }
    }

    // ---- polling: update status dashboard + auto-popup modal saat putus ----
    async function poll() {
        try {
            const res = await fetch('whatsapp_ajax?action=get_connection', { credentials: 'same-origin' });
            const d = await res.json();
            if (!d.success) return;
            const connected = !!d.connected;

            /* Sedang dibatasi server (429) dan belum ada keadaan terakhir yang
             * tersimpan → jangan anggap WhatsApp putus dan jangan auto-popup.
             * Tanpa penjaga ini, 429 membuat tombol "Hubungkan" muncul tanpa
             * sebab, operator menekannya, permintaan bertambah → makin 429. */
            if (d.throttled && !d.cached) return;

            updateDashboardUi(d.status, connected, d.phone);
            if (modalOpen) updateModal(d.status, connected, d.phone, d.pushName);

            // Deteksi transisi terhubung → putus: popup otomatis (sekali per sesi halaman,
            // setelah delay kecil supaya tidak mengganggu interaksi admin).
            if (!connected && !modalOpen && !autoShown && lastWasConnected === true) {
                autoShown = true;
                setTimeout(function () { if (!modalOpen) openModal(); }, 800);
            }

            // Halaman pertama dimuat SUDAH putus: biarkan badge merah + tombol
            // Hubungkan tampil; auto-popup ditunda 3 dtk (tidak agresif).
            if (!connected && !autoShown && lastWasConnected === null) {
                autoShown = true;
                setTimeout(function () { if (!modalOpen) openModal(); }, 3000);
            }

            lastWasConnected = connected;
        } catch (e) { /* jaringan / login kedaluwarsa — abaikan */ }
    }

    // ---- mulai QR auto-refresh saat modal terbuka & belum connect ----
    /* 8 detik (dulu 4 detik). QR WhatsApp berlaku ~20-60 detik, jadi 4 detik
     * hanya memboroskan jatah permintaan — OpenWA membatasi 100/60 dtk dan
     * itulah sebab QR sempat "tidak tersedia" (lihat catatan di
     * whatsapp_ajax.php action=get_connection). */
    function startQrLoop() {
        stopQrLoop();
        loadQr();
        qrTimer = setInterval(loadQr, 8000);
    }
    function stopQrLoop() {
        if (qrTimer) { clearInterval(qrTimer); qrTimer = null; }
    }

    // ---- buka modal ----
    async function openModal() {
        if (modalOpen) return;
        modalOpen = true;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        badge.innerHTML = '<i class="fas fa-circle"></i> Menghubungkan…';
        sub.textContent = 'Menyiapkan sesi…';
        // Paksa engine start (idempoten) supaya QR muncul.
        if (!starting) {
            starting = true;
            try {
                await fetch('whatsapp_ajax?action=wa_start', { method: 'POST', credentials: 'same-origin' });
            } catch (e) {}
            starting = false;
        }
        startQrLoop();
    }

    // ---- tutup modal ----
    function closeModal() {
        modalOpen = false;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        stopQrLoop();
    }

    // ---- event handlers ----
    document.querySelectorAll('.js-wa-reconnect-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openModal();
        });
    });
    document.querySelectorAll('.js-wa-reconnect-close').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalOpen) closeModal();
    });
    modal.querySelector('.js-wa-qr-refresh').addEventListener('click', function () {
        setQrStatus('Memuat ulang QR…');
        loadQr();
    });
    modal.querySelector('.js-wa-logout-btn').addEventListener('click', async function () {
        if (!confirm('Putuskan WhatsApp dari server? Perangkat akan di-unlink dan perlu scan QR ulang.')) return;
        const btn = this;
        const old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logout…';
        try {
            await fetch('whatsapp_ajax?action=wa_logout', { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'csrf_token=' + encodeURIComponent(csrfVal) });
        } catch (e) {}
        btn.disabled = false;
        btn.innerHTML = old;
        setQrStatus('Sesi di-logout — siapkan QR baru…');
        setTimeout(loadQr, 1200);
    });

    // ---- mulai polling ----
    // Sinkronkan UI dgn status server saat load. lastWasConnected dibiarkan
    // null supaya poll() pertama tahu apakah halaman dimuat dlm keadaan putus.
    updateDashboardUi(
        <?= json_encode($waStatus['connected'] ? 'ready' : 'qr_ready') ?>,
        <?= json_encode($waStatus['connected']) ?>,
        <?= json_encode((string)whatsapp_config('session_id')) ?>
    );
    poll();
    /* 10 detik (dulu 5 detik). OpenWA membatasi 100 permintaan / 60 detik;
     * polling 5 detik dari beberapa tab sudah menghabiskan jatah itu sehingga
     * permintaan lain (termasuk QR) kena HTTP 429. Status WA tidak perlu
     * secepat 5 detik, dan endpoint-nya sendiri sudah punya cache 8 detik. */
    pollTimer = setInterval(poll, 10000);
});
</script>
<?php endif; ?>

<?php if (in_array('grafik', $visibleKeys, true)): ?>
<script src="/assets/vendor/apexcharts/apexcharts.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const labels = <?= json_encode($chartLabels) ?>;
    const values = <?= json_encode($chartValues) ?>;
    const themeGold = (getComputedStyle(document.documentElement).getPropertyValue('--gold') || '#f5a623').trim();

    const options = {
        series: [{
            name: 'Pendapatan',
            data: values
        }],
        chart: {
            type: 'area',
            height: '100%',
            toolbar: { show: false },
            background: 'transparent',
            fontFamily: 'inherit'
        },
        colors: [themeGold],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 3 },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.35,
                opacityTo: 0.02,
                stops: [0, 90, 100]
            }
        },
        grid: { borderColor: '#26262f', strokeDashArray: 4 },
        xaxis: {
            categories: labels,
            axisBorder: { show: false },
            labels: { style: { colors: '#6d6d7a', fontSize: '10px' }, rotate: -45 }
        },
        yaxis: {
            labels: {
                style: { colors: '#6d6d7a', fontSize: '10px' },
                formatter: v => v >= 1000000 ? (v / 1000000).toFixed(1) + 'jt' : (v >= 1000 ? (v / 1000).toFixed(0) + 'rb' : v)
            }
        },
        tooltip: {
            theme: 'dark',
            y: { formatter: v => 'Rp ' + Number(v).toLocaleString('id-ID') }
        }
    };

    new ApexCharts(document.querySelector('#salesChart'), options).render();
});
</script>
<?php endif; ?>
