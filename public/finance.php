<?php
/**
 * public/finance.php
 * =====================================================================
 * Laporan keuangan: pendapatan, piutang, rata-rata per pesanan,
 * grafik tren bulanan, dan tabel detail per bulan.
 * =====================================================================
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireOwner();

$startMonth = (string)get('start_month', date('Y-m', strtotime('-5 months')));
$endMonth   = (string)get('end_month', date('Y-m'));

if ($startMonth > $endMonth) {
    [$startMonth, $endMonth] = [$endMonth, $startMonth];
}
if (!preg_match('/^\d{4}-\d{2}$/', $startMonth)) $startMonth = date('Y-m', strtotime('-5 months'));
if (!preg_match('/^\d{4}-\d{2}$/', $endMonth))   $endMonth = date('Y-m');

$start = $startMonth . '-01';
$end   = date('Y-m-t', strtotime($endMonth . '-01'));

$stmt = db()->prepare("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status='lunas' AND order_date BETWEEN ? AND ?");
$stmt->execute([$start, $end]);
$totalRevenue = (float)$stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE payment_status='lunas' AND order_date BETWEEN ? AND ?");
$stmt->execute([$start, $end]);
$totalOrders = (int)$stmt->fetchColumn();

$avgOrder = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

$stmt = db()->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS tot FROM orders WHERE payment_status='belum' AND order_date BETWEEN ? AND ?");
$stmt->execute([$start, $end]);
$unpaid = $stmt->fetch();
$unpaidCount = (int)($unpaid['cnt'] ?? 0);
$unpaidTotal = (float)($unpaid['tot'] ?? 0);

/* =====================================================================
 * BIAYA & LABA (2026-10-09) — supaya laporan bukan cuma "omzet"
 * ---------------------------------------------------------------------
 * Sebelum ini finance.php HANYA menghitung pendapatan. Modal bahan,
 * pengeluaran, dan laba tidak pernah muncul → laporan terasa "los".
 *
 * Rumus yang dipakai:
 *   Laba Kotor  = Pendapatan − HPP bahan (bahan yang terpakai produksi)
 *   Laba Bersih = Laba Kotor − Pengeluaran operasional − Kerugian rusak
 * ===================================================================== */

// --- Pengeluaran operasional periode ini ---
$stExp = db()->prepare("SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM expenses WHERE expense_date BETWEEN ? AND ?");
$stExp->execute([$start, $end]);
$expRow = $stExp->fetch();
$totalExpense = (float)($expRow['total'] ?? 0);
$expenseCount = (int)($expRow['cnt'] ?? 0);

// --- HPP bahan (dari kartu stok) ---
$hpp = function_exists('stock_hpp_period')
    ? stock_hpp_period($start, $end)
    : ['hpp' => 0, 'meter' => 0, 'rugi_rusak' => 0, 'meter_rusak' => 0, 'beli' => 0, 'n_potong' => 0];

/* --- HPP ESTIMASI untuk order LAMA (sebelum 2026-10-09, ukuran belum tersimpan) ---
 * Tanpa ini, bulan berjalan akan tampak untung 100% karena modal bahan
 * tidak pernah terhitung. Angka ini SELALU ditandai "estimasi". */
$hppEst = function_exists('stock_hpp_estimasi_period')
    ? stock_hpp_estimasi_period($start, $end)
    : ['hpp' => 0, 'meter' => 0, 'n' => 0, 'n_gagal' => 0];

$hppTotal = (float)$hpp['hpp'] + (float)$hppEst['hpp'];
$meterTotal = (float)$hpp['meter'] + (float)$hppEst['meter'];

// --- Nilai persediaan saat ini (aset) ---
$inventoryValue = function_exists('stock_inventory_value_all') ? stock_inventory_value_all() : 0.0;

// --- Laba ---
$labaKotor = $totalRevenue - $hppTotal;
$labaBersih = $labaKotor - $totalExpense - (float)$hpp['rugi_rusak'];
$marginPct = $totalRevenue > 0 ? ($labaKotor / $totalRevenue) * 100 : 0;

// --- Belanja bahan periode ini (arus kas keluar, informasi) ---
$belanjaBahan = (float)($hpp['beli'] ?? 0);

/* =====================================================================
 * STOR / SETORAN (2026-10-12) — uang yang SUDAH disetor
 * ---------------------------------------------------------------------
 * Kartu dashboard "Stor" mencatat setoran harian (cash + transfer).
 * Di sini diakumulasi untuk periode terpilih supaya laporan lengkap.
 *
 * PENTING (permintaan user 12 Okt 2026): "perhari ini data yang belum di
 * stor, hitungan sebelum-sebelumnya kan udah pasti selesai di stor dong".
 * BENAR — fitur stor baru dipasang 12 Okt, jadi TIDAK ADA catatan setoran
 * utk hari sebelumnya. Maka uang SEBELUM `stor_start_date` dianggap SUDAH
 * disetor dan TIDAK ikut dihitung "belum disetor".
 *
 *   Selisih = Pendapatan (Lunas, sejak mulai) − Stor (sejak mulai)
 *     positif  → masih ada uang yang BELUM disetor/diterima (di tangan)
 *     negatif  → setoran lebih besar dari pendapatan sejak mulai (wajar
 *                bila menyetor uang hari sebelumnya)
 * CATATAN: "Stor" TIDAK mengubah laba — ini arus kas, bukan biaya.
 * ===================================================================== */
$storStartDate = stor_start_date();
/* Titik mulai efektif = yang PALING AKHIR antara awal periode & tanggal mulai
   pencatatan stor. Kalau periode filter seluruhnya SEBELUM tanggal mulai
   (mis. lihat September), tidak ada yang dihitung → belum disetor = 0. */
$storFrom   = ($storStartDate > $start) ? $storStartDate : $start;
$storAktif  = ($storFrom <= $end);   // apakah periode ini menyentuh masa pencatatan stor?

/* HARI KERJA (mulai jam 06:00) — karena kerja sering lewat tengah malam.
   Diterapkan KHUSUS bagian Stor supaya kartu KPI lain tidak ikut berubah. */
$storWdHour   = workday_start_hour();
$storWdToday  = workday_today();
/* Catatan: kerja berlangsung tiap hari (Senin–Minggu), jadi istilah "hari kerja"
   membingungkan. Yang dimaksud cuma BATAS JAM rekap harian (06:00–05:59). */
$storWdLabel  = sprintf('rekap harian %02d:00–%02d:59', $storWdHour, ($storWdHour + 23) % 24);
$storWdExpr   = workday_sql_date('order_date');

$storCash = $storTransfer = $storTotal = 0.0;
$storCount = 0;
$storMonthly = [];
try {
    // Total setoran untuk PERIODE terpilih (apa adanya, tidak dibatasi tanggal mulai)
    $stStor = db()->prepare("SELECT COALESCE(SUM(cash_amount),0) c, COALESCE(SUM(transfer_amount),0) t,
                                    COALESCE(SUM(total_amount),0) tot, COUNT(*) n
                             FROM cash_stor WHERE stor_date BETWEEN ? AND ?");
    $stStor->execute([$start, $end]);
    $rSt = $stStor->fetch();
    if ($rSt) {
        $storCash     = (float)$rSt['c'];
        $storTransfer = (float)$rSt['t'];
        $storTotal    = (float)$rSt['tot'];
        $storCount    = (int)$rSt['n'];
    }
    $stStorM = db()->prepare("SELECT DATE_FORMAT(stor_date,'%Y-%m') AS month,
                                     COALESCE(SUM(cash_amount),0) c,
                                     COALESCE(SUM(transfer_amount),0) t,
                                     COALESCE(SUM(total_amount),0) tot, COUNT(*) n
                              FROM cash_stor WHERE stor_date BETWEEN ? AND ?
                              GROUP BY month ORDER BY month ASC");
    $stStorM->execute([$start, $end]);
    foreach ($stStorM->fetchAll() as $row) {
        $storMonthly[$row['month']] = $row;
    }
} catch (Throwable $e) {
    // tabel belum ada (deploy lama) — biarkan 0
}

/* Pendapatan lunas & setoran SEJAK tanggal mulai (untuk "belum disetor"). */
$storRevSejak = 0.0;
$storSejak    = 0.0;
$storRevBulan = [];    // pendapatan lunas per bulan sejak tanggal mulai
try {
    if ($storAktif) {
        $q1 = db()->prepare("SELECT COALESCE(SUM(grand_total),0) FROM orders
                             WHERE payment_status='lunas' AND status!='batal'
                               AND $storWdExpr BETWEEN ? AND ?");
        $q1->execute([$storFrom, $end]);
        $storRevSejak = (float)$q1->fetchColumn();

        $q2 = db()->prepare("SELECT COALESCE(SUM(total_amount),0) FROM cash_stor WHERE stor_date BETWEEN ? AND ?");
        $q2->execute([$storFrom, $end]);
        $storSejak = (float)$q2->fetchColumn();

        $q3 = db()->prepare("SELECT DATE_FORMAT($storWdExpr,'%Y-%m') m, COALESCE(SUM(grand_total),0) rev
                             FROM orders WHERE payment_status='lunas' AND status!='batal'
                               AND $storWdExpr BETWEEN ? AND ?
                             GROUP BY m");
        $q3->execute([$storFrom, $end]);
        foreach ($q3->fetchAll() as $row) {
            $storRevBulan[$row['m']] = (float)$row['rev'];
        }
    }
} catch (Throwable $e) { /* abaikan */ }
$storSelisih = $storRevSejak - $storSejak;
/* Untuk TAMPILAN "Belum Disetor" pakai minimal 0 — uang yang belum disetor
   tidak mungkin negatif. Nilai negatif artinya setoran melebihi pendapatan
   (mis. menyetor uang hari sebelumnya) → ditampilkan sebagai penjelasan. */
$storBelumTampil = max(0.0, $storSelisih);
$storKelebihan   = $storSelisih < 0 ? abs($storSelisih) : 0.0;
$storPctDariPendapatan = $storRevSejak > 0 ? ($storSejak / $storRevSejak) * 100 : 0;

/* =====================================================================
 * RINCIAN PER HARI (14 hari terakhir) — permintaan user 12 Okt 2026
 * Kolom: tanggal · pendapatan lunas · stor · belum disetor.
 * Hari SEBELUM tanggal mulai ditandai "sudah beres" (tidak dihitung).
 * ===================================================================== */
$storDaily = [];
try {
    /* 14 HARI KERJA terakhir (bukan 14 hari kalender) supaya satu shift tidak
       terbelah. Hari kerja hari ini = $storWdToday. */
    $dFrom  = date('Y-m-d', strtotime($storWdToday . ' -13 days'));
    $revHari = [];
    $qD = db()->prepare("SELECT $storWdExpr d, COALESCE(SUM(grand_total),0) rev
                         FROM orders WHERE payment_status='lunas' AND status!='batal'
                           AND $storWdExpr BETWEEN ? AND ?
                         GROUP BY d");
    $qD->execute([$dFrom, $storWdToday]);
    foreach ($qD->fetchAll() as $row) { $revHari[(string)$row['d']] = (float)$row['rev']; }

    $stHari = [];
    $qE = db()->prepare("SELECT stor_date d, COALESCE(SUM(cash_amount),0) c,
                                COALESCE(SUM(transfer_amount),0) t,
                                COALESCE(SUM(total_amount),0) tot, COUNT(*) n
                         FROM cash_stor WHERE stor_date BETWEEN ? AND ?
                         GROUP BY stor_date");
    $qE->execute([$dFrom, $storWdToday]);
    foreach ($qE->fetchAll() as $row) { $stHari[(string)$row['d']] = $row; }

    for ($i = 0; $i < 14; $i++) {
        $d = date('Y-m-d', strtotime("$storWdToday -$i days"));
        $aktif = ($d >= $storStartDate);          // belum masuk masa pencatatan stor?
        $rev = $revHari[$d] ?? 0.0;
        $st  = $stHari[$d] ?? null;
        $stTot = $st ? (float)$st['tot'] : 0.0;
        $storDaily[] = [
            'date'      => $d,
            'aktif'     => $aktif,
            'rev'       => $rev,
            'stor'      => $stTot,
            'stor_cash' => $st ? (float)$st['c'] : 0.0,
            'stor_trf'  => $st ? (float)$st['t'] : 0.0,
            'n'         => $st ? (int)$st['n'] : 0,
            'selisih'   => $aktif ? ($rev - $stTot) : 0.0,
        ];
    }
} catch (Throwable $e) { $storDaily = []; }

$monthly = db()->prepare("
    SELECT DATE_FORMAT(order_date,'%Y-%m') AS month,
           SUM(CASE WHEN payment_status='lunas' THEN grand_total ELSE 0 END) AS revenue,
           COUNT(CASE WHEN payment_status='lunas' THEN 1 END) AS orders,
           SUM(CASE WHEN payment_status='belum' THEN grand_total ELSE 0 END) AS unpaid_revenue,
           COUNT(CASE WHEN payment_status='belum' THEN 1 END) AS unpaid_orders
    FROM orders WHERE order_date BETWEEN ? AND ?
    GROUP BY month ORDER BY month ASC
");
$monthly->execute([$start, $end]);
$monthly = $monthly->fetchAll();

$labels = [];
$revenueData = [];
$orderData = [];
$unpaidRevenueData = [];
$unpaidOrderData = [];
foreach ($monthly as $m) {
    $labels[] = date('M Y', strtotime($m['month'] . '-01'));
    $revenueData[] = (float)$m['revenue'];
    $orderData[] = (int)$m['orders'];
    $unpaidRevenueData[] = (float)$m['unpaid_revenue'];
    $unpaidOrderData[] = (int)$m['unpaid_orders'];
    // Stor bulan ini (0 kalau belum ada catatan setoran)
    $storData[] = isset($storMonthly[$m['month']]) ? (float)$storMonthly[$m['month']]['tot'] : 0.0;
}

$pageTitle  = 'Laporan Keuangan';
$activeMenu = 'finance';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===== FILTER PERIODE ===== -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="finance" class="d-flex align-center flex-wrap gap-2">
            <label class="form-label mb-0">Dari:</label>
            <input type="month" name="start_month" class="form-input" style="width:auto;" value="<?= e($startMonth) ?>">
            <label class="form-label mb-0">Sampai:</label>
            <input type="month" name="end_month" class="form-input" style="width:auto;" value="<?= e($endMonth) ?>">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
            <a href="finance" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
        </form>
    </div>
</div>

<!-- ===== STATISTIK ===== -->
<div class="grid grid-5 mb-4">
    <a href="orders?payment=lunas" class="stat-card">
        <div class="stat-card-icon green"><i class="fas fa-circle-check"></i></div>
        <div class="stat-card-label">Pendapatan (Lunas)</div>
        <div class="stat-card-value"><?= e(formatRupiah($totalRevenue)) ?></div>
        <div class="stat-card-sub">Klik untuk detail</div>
    </a>
    <a href="orders?payment=lunas" class="stat-card">
        <div class="stat-card-icon blue"><i class="fas fa-clipboard-list"></i></div>
        <div class="stat-card-label">Pesanan Lunas</div>
        <div class="stat-card-value"><?= e($totalOrders) ?></div>
        <div class="stat-card-sub">Periode terpilih</div>
    </a>
    <div class="stat-card">
        <div class="stat-card-icon yellow"><i class="fas fa-calculator"></i></div>
        <div class="stat-card-label">Rata-rata per Pesanan</div>
        <div class="stat-card-value"><?= e(formatRupiah($avgOrder)) ?></div>
        <div class="stat-card-sub">Lunas</div>
    </div>
    <a href="orders?payment=belum" class="stat-card">
        <div class="stat-card-icon red"><i class="fas fa-circle-xmark"></i></div>
        <div class="stat-card-label">Pesanan Belum Lunas</div>
        <div class="stat-card-value"><?= e($unpaidCount) ?></div>
        <div class="stat-card-sub">Klik untuk detail</div>
    </a>
    <a href="orders?payment=belum" class="stat-card">
        <div class="stat-card-icon red"><i class="fas fa-hand-holding-dollar"></i></div>
        <div class="stat-card-label">Total Belum Bayar</div>
        <div class="stat-card-value"><?= e(formatRupiah($unpaidTotal)) ?></div>
        <div class="stat-card-sub">Klik untuk detail</div>
    </a>
</div>

<!-- ===== LABA RUGI (BARU 2026-10-09) ===== -->
<div class="grid grid-2 mb-4">
    <!-- Kiri: ringkasan laba -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-scale-balanced"></i> Laba &amp; Modal</h3>
            <span class="text-faint text-xs"><?= e(date('M Y', strtotime($start))) ?> – <?= e(date('M Y', strtotime($end))) ?></span>
        </div>
        <div class="card-body">
            <table class="table" style="margin:0;">
                <tbody>
                    <tr>
                        <td>Pendapatan (Lunas)</td>
                        <td class="text-right fw-600"><?= e(formatRupiah($totalRevenue)) ?></td>
                    </tr>
                    <tr>
                        <td>
                            − HPP Bahan Terpakai
                            <div class="text-faint text-xs">
                                <?= rtrim(rtrim(number_format($meterTotal, 1, ',', '.'), '0'), ',') ?> m ·
                                <?= (int)$hpp['n_potong'] ?> tercatat
                                <?php if ((float)$hppEst['hpp'] > 0): ?>
                                    + <?= (int)$hppEst['n'] ?> estimasi
                                <?php endif; ?>
                            </div>
                            <?php if ((float)$hppEst['hpp'] > 0): ?>
                                <div class="text-faint text-xs">
                                    <i class="fas fa-circle-info"></i>
                                    Estimasi <?= e(formatRupiah((float)$hppEst['hpp'])) ?> dari
                                    <?= (int)$hppEst['n'] ?> order lama (ukuran belum tersimpan saat itu).
                                    Real-time sejak 09/10.
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right text-danger">−<?= e(formatRupiah($hppTotal)) ?></td>
                    </tr>
                    <tr style="border-top:2px solid var(--border);">
                        <td class="fw-700">Laba Kotor</td>
                        <td class="text-right fw-700 <?= $labaKotor >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= e(formatRupiah($labaKotor)) ?>
                            <div class="text-faint text-xs" style="font-weight:400;">margin <?= number_format($marginPct, 1, ',', '.') ?>%</div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            − Pengeluaran Operasional
                            <div class="text-faint text-xs"><?= (int)$expenseCount ?> transaksi ·
                                <a href="expenses" class="text-gold">kelola</a>
                            </div>
                        </td>
                        <td class="text-right text-danger">−<?= e(formatRupiah($totalExpense)) ?></td>
                    </tr>
                    <tr>
                        <td>
                            − Kerugian Bahan Rusak
                            <div class="text-faint text-xs">
                                <?= rtrim(rtrim(number_format((float)$hpp['meter_rusak'], 1, ',', '.'), '0'), ',') ?> m ·
                                <a href="stock" class="text-gold">kartu stok</a>
                            </div>
                        </td>
                        <td class="text-right text-danger">−<?= e(formatRupiah((float)$hpp['rugi_rusak'])) ?></td>
                    </tr>
                    <tr style="border-top:2px solid var(--border);">
                        <td class="fw-700" style="font-size:1.05rem;">LABA BERSIH</td>
                        <td class="text-right fw-700" style="font-size:1.15rem;color:<?= $labaBersih >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                            <?= e(formatRupiah($labaBersih)) ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Kanan: persediaan & belanja bahan -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-warehouse"></i> Persediaan Bahan</h3>
        </div>
        <div class="card-body">
            <div class="stat-card mb-3" style="border-color:var(--gold-border);">
                <div class="stat-card-icon gold"><i class="fas fa-coins"></i></div>
                <div class="stat-card-label">Nilai Persediaan (Aset)</div>
                <div class="stat-card-value"><?= e(formatRupiah($inventoryValue)) ?></div>
                <div class="stat-card-sub">Bahan yang masih ada di gudang — <a href="stock" class="text-gold">buka kartu stok</a></div>
            </div>

            <table class="table" style="margin:0;">
                <tbody>
                    <tr>
                        <td>Beli bahan periode ini</td>
                        <td class="text-right"><?= e(formatRupiah($belanjaBahan)) ?></td>
                    </tr>
                    <tr>
                        <td>Bahan terpakai produksi</td>
                        <td class="text-right"><?= e(formatRupiah($hppTotal)) ?></td>
                    </tr>
                    <tr>
                        <td>Bahan rusak (kerugian)</td>
                        <td class="text-right text-danger"><?= e(formatRupiah((float)$hpp['rugi_rusak'])) ?></td>
                    </tr>
                </tbody>
            </table>

            <?php if ($inventoryValue <= 0 && $hppTotal <= 0): ?>
                <div class="alert alert-info mt-3 mb-0 p-2 text-xs">
                    <i class="fas fa-circle-info"></i>
                    Belum ada pergerakan bahan bulan ini. Isi harga modal &amp; stok di
                    <a href="stock" class="text-gold">Kartu Stok Bahan</a> supaya HPP &amp; laba terhitung otomatis.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== STOR / SETORAN (BARU 2026-10-12) ===== -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-hand-holding-dollar"></i> Stor (Setoran)</h3>
        <span class="text-faint text-xs"><?= e(date('M Y', strtotime($start))) ?> – <?= e(date('M Y', strtotime($end))) ?> · <?= (int)$storCount ?>× setor</span>
    </div>
    <div class="card-body">
        <div class="grid grid-4 mb-3">
            <div class="stat-card">
                <div class="stat-card-icon teal"><i class="fas fa-money-bill-transfer"></i></div>
                <div class="stat-card-label">Total Disetor</div>
                <div class="stat-card-value"><?= e(formatRupiah($storTotal)) ?></div>
                <div class="stat-card-sub">cash + transfer</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon gold"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-card-label">Cash</div>
                <div class="stat-card-value"><?= e(formatRupiah($storCash)) ?></div>
                <div class="stat-card-sub">tunai</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon blue"><i class="fas fa-building-columns"></i></div>
                <div class="stat-card-label">Transfer</div>
                <div class="stat-card-value"><?= e(formatRupiah($storTransfer)) ?></div>
                <div class="stat-card-sub">bank / e-wallet</div>
            </div>
            <div class="stat-card" style="<?= $storBelumTampil > 0 ? 'border-color:var(--warning);' : '' ?>">
                <div class="stat-card-icon <?= $storBelumTampil > 0 ? 'yellow' : 'green' ?>"><i class="fas fa-scale-unbalanced"></i></div>
                <div class="stat-card-label">Belum Disetor</div>
                <div class="stat-card-value <?= $storBelumTampil > 0 ? 'text-warning' : 'text-success' ?>"><?= e(formatRupiah($storBelumTampil)) ?></div>
                <div class="stat-card-sub"><?= $storKelebihan > 0 ? 'lebih setor ' . e(formatRupiah($storKelebihan)) : 'pendapatan lunas − stor' ?></div>
            </div>
        </div>

        <table class="table" style="margin:0;">
            <tbody>
                <tr>
                    <td>
                        Pendapatan (Lunas)
                        <div class="text-faint text-xs">
                            <?php if ($storAktif && $storFrom !== $start): ?>
                                <i class="fas fa-circle-info"></i> dihitung sejak <?= e(formatDate($storStartDate, 'd/m/Y')) ?>
                                (sebelumnya dianggap sudah disetor)
                            <?php else: ?>
                                periode ini
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-right fw-600"><?= e(formatRupiah($storRevSejak)) ?></td>
                </tr>
                <tr>
                    <td>
                        − Sudah disetor
                        <div class="text-faint text-xs">
                            <?php if ($storAktif): ?>
                                cash <?= e(formatRupiah($storCash)) ?> · transfer <?= e(formatRupiah($storTransfer)) ?>
                                <?php if ($storRevSejak > 0): ?>
                                    · <?= number_format($storPctDariPendapatan, 1, ',', '.') ?>% dari pendapatan
                                <?php endif; ?>
                            <?php else: ?>
                                belum ada pencatatan setoran pada periode ini
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-right text-danger">−<?= e(formatRupiah($storSejak)) ?></td>
                </tr>
                <tr style="border-top:2px solid var(--border);">
                    <td class="fw-700">
                        Selisih (pendapatan − stor)
                        <div class="text-faint text-xs">
                            <?php if (!$storAktif): ?>
                                Periode ini sepenuhnya <b>sebelum</b> pencatatan stor dimulai
                                (<?= e(formatDate($storStartDate, 'd/m/Y')) ?>) → dianggap sudah disetor semua.
                            <?php elseif ($storSelisih > 0): ?>
                                Masih ada <b><?= e(formatRupiah($storSelisih)) ?></b> yang belum disetor/diterima.
                            <?php elseif ($storSelisih < 0): ?>
                                Setoran <b><?= e(formatRupiah($storKelebihan)) ?></b> LEBIH BESAR dari pendapatan sejak
                                <?= e(formatDate($storStartDate, 'd/m/Y')) ?> — biasanya karena menyetor uang hari sebelumnya
                                (jadi belum disetor = Rp 0, bukan minus).
                            <?php else: ?>
                                Semua pendapatan sudah disetor. 👍
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-right fw-700 <?= $storSelisih > 0 ? 'text-warning' : 'text-success' ?>">
                        <?= e(formatRupiah($storSelisih)) ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="alert alert-info mt-3 mb-0 p-2 text-xs">
            <i class="fas fa-circle-info"></i>
            <b>Stor tidak mengurangi laba</b> — ini perpindahan uang (arus kas), bukan biaya.
            Catat setoran dari tombol <b>“Sudah Stor”</b> di <a href="dashboard" class="text-gold">Dashboard</a>.
            <br><b>Mulai dihitung <?= e(formatDate($storStartDate, 'd/m/Y')) ?></b> —
            uang sebelum tanggal itu dianggap sudah disetor (fitur stor baru dipasang tanggal tersebut).
            <?php if ($storCount === 0 && $storAktif): ?>
                <br>Belum ada setoran tercatat sejak tanggal itu.
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== RINCIAN STOR PER HARI (14 hari terakhir) ===== -->
<?php if (!empty($storDaily)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-calendar-day"></i> Stor Per Hari</h3>
        <span class="text-faint text-xs">14 hari terakhir · <?= e($storWdLabel) ?> · hari sebelum <?= e(formatDate($storStartDate, 'd/m/Y')) ?> dianggap sudah disetor</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th class="num">Pendapatan Lunas</th>
                    <th class="num">Stor (Cash)</th>
                    <th class="num">Stor (Transfer)</th>
                    <th class="num">Total Disetor</th>
                    <th class="num">Belum Disetor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($storDaily as $d):
                    $isToday = ($d['date'] === $storWdToday);
                    $tglLabel = formatDate($d['date'], 'd/m/Y') . ' · ' . date('D', strtotime($d['date']));
                ?>
                <tr style="<?= $isToday ? 'background:var(--surface-2);' : '' ?>">
                    <td class="font-medium" data-label="Tanggal">
                        <?= e($tglLabel) ?>
                        <?php if ($isToday): ?><span class="badge badge-muted">sedang berjalan</span><?php endif; ?>
                        <?php if (!$d['aktif']): ?>
                            <div class="text-faint text-xs">sebelum pencatatan stor — dianggap sudah disetor</div>
                        <?php elseif ((int)$d['n'] > 1): ?>
                            <div class="text-faint text-xs"><?= (int)$d['n'] ?>× setor</div>
                        <?php endif; ?>
                    </td>
                    <td class="num" data-label="Pendapatan Lunas"><?= e(formatRupiah($d['rev'])) ?></td>
                    <?php if (!$d['aktif']): ?>
                        <td class="num text-faint" colspan="4" data-label="Stor">— sudah beres —</td>
                    <?php else: ?>
                        <td class="num" data-label="Stor (Cash)"><?= $d['stor_cash'] > 0 ? e(formatRupiah($d['stor_cash'])) : '<span class="text-faint">—</span>' ?></td>
                        <td class="num" data-label="Stor (Transfer)"><?= $d['stor_trf'] > 0 ? e(formatRupiah($d['stor_trf'])) : '<span class="text-faint">—</span>' ?></td>
                        <td class="num fw-600" data-label="Total Disetor"><?= $d['stor'] > 0 ? e(formatRupiah($d['stor'])) : '<span class="text-faint">belum stor</span>' ?></td>
                        <td class="num fw-700 <?= $d['selisih'] > 0 ? 'text-warning' : 'text-success' ?>" data-label="Belum Disetor">
                            <?= e(formatRupiah(max(0.0, $d['selisih']))) ?>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ===== GRAFIK ===== -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-chart-line"></i> Tren Keuangan Per Bulan</h3>
    </div>
    <div class="card-body">
        <?php if (count($monthly) > 0): ?>
            <div style="position:relative; height:380px;">
                <canvas id="mainChart"></canvas>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Belum ada data untuk periode ini</h3>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== TABEL BULANAN ===== -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-table"></i> Rincian Per Bulan</h3>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Bulan</th>
                    <th class="num">Lunas (Order)</th>
                    <th class="num">Lunas (Rp)</th>
                    <th class="num">Stor (Rp)</th>
                    <th class="num">Belum Disetor</th>
                    <th class="num">Belum Lunas (Order)</th>
                    <th class="num">Belum Lunas (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($monthly) > 0): ?>
                    <?php foreach ($monthly as $m):
                        $mKey = $m['month'];
                        /* Bulan SEBELUM tanggal mulai pencatatan stor → tidak
                           dihitung belum disetor (dianggap sudah beres). */
                        $mStorAktif = ($mKey >= substr($storStartDate, 0, 7));
                        $smRow = $storMonthly[$mKey] ?? null;
                        $mStor = $smRow ? (float)$smRow['tot'] : 0.0;
                        $mRev = $mStorAktif ? (float)($storRevBulan[$mKey] ?? 0.0) : 0.0;
                        $mSelisih = $mRev - $mStor;
                    ?>
                    <tr>
                        <td class="font-medium" data-label="Bulan"><?= date('M Y', strtotime($m['month'] . '-01')) ?></td>
                        <td class="num" data-label="Lunas (Order)"><?= (int)$m['orders'] ?></td>
                        <td class="num text-success" data-label="Lunas (Rp)"><?= e(formatRupiah($m['revenue'])) ?></td>
                        <td class="num" data-label="Stor (Rp)">
                            <?php if ($mStor > 0): ?>
                                <?= e(formatRupiah($mStor)) ?>
                                <div class="text-faint text-xs">
                                    cash <?= e(formatRupiah((float)$smRow['c'])) ?> · trf <?= e(formatRupiah((float)$smRow['t'])) ?>
                                </div>
                            <?php else: ?>
                                <span class="text-faint">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="num" data-label="Belum Disetor">
                            <?php if (!$mStorAktif): ?>
                                <span class="text-faint text-xs">sudah beres</span>
                            <?php else: ?>
                                <span class="<?= $mSelisih > 0 ? 'text-warning' : 'text-success' ?> fw-600"><?= e(formatRupiah(max(0.0, $mSelisih))) ?></span>
                                <?php if ($mKey === substr($storStartDate, 0, 7) && $storStartDate > $mKey . '-01'): ?>
                                    <div class="text-faint text-xs">sejak <?= e(formatDate($storStartDate, 'd/m')) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="num" data-label="Belum (Order)"><?= (int)$m['unpaid_orders'] ?></td>
                        <td class="num text-danger" data-label="Belum (Rp)"><?= e(formatRupiah($m['unpaid_revenue'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                                <h3>Belum ada data</h3>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php if (count($monthly) > 0): ?>
<script src="/assets/vendor/chartjs/chart.umd.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const labels = <?= json_encode($labels) ?>;
    const revenueData = <?= json_encode($revenueData) ?>;
    const storData = <?= json_encode($storData ?? []) ?>;
    const orderData = <?= json_encode($orderData) ?>;
    const unpaidRevenueData = <?= json_encode($unpaidRevenueData) ?>;
    const unpaidOrderData = <?= json_encode($unpaidOrderData) ?>;

    const ctx = document.getElementById('mainChart').getContext('2d');

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Pendapatan Lunas (Rp)',
                    data: revenueData,
                    type: 'line',
                    borderColor: '#FFD700',
                    backgroundColor: 'rgba(255,215,0,0.12)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#FFD700',
                    pointRadius: 5,
                    yAxisID: 'y'
                },
                {
                    label: 'Total Belum Bayar (Rp)',
                    data: unpaidRevenueData,
                    type: 'line',
                    borderColor: '#f87171',
                    backgroundColor: 'rgba(248,113,113,0.10)',
                    borderWidth: 2,
                    borderDash: [6, 4],
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#f87171',
                    pointRadius: 4,
                    yAxisID: 'y'
                },
                {
                    label: 'Stor / Sudah Disetor (Rp)',
                    data: storData,
                    type: 'line',
                    borderColor: '#2dd4bf',
                    backgroundColor: 'rgba(45,212,191,0.12)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    pointBackgroundColor: '#2dd4bf',
                    pointRadius: 4,
                    yAxisID: 'y'
                },
                {
                    label: 'Jumlah Pesanan Lunas',
                    data: orderData,
                    type: 'bar',
                    backgroundColor: 'rgba(96,165,250,0.55)',
                    borderColor: '#60a5fa',
                    borderWidth: 1.5,
                    borderRadius: 6,
                    yAxisID: 'y1'
                },
                {
                    label: 'Jumlah Belum Lunas',
                    data: unpaidOrderData,
                    type: 'bar',
                    backgroundColor: 'rgba(248,113,113,0.45)',
                    borderColor: '#f87171',
                    borderWidth: 1.5,
                    borderRadius: 6,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 1200, easing: 'easeInOutQuart' },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#9d9dab', boxWidth: 16, padding: 14 } },
                tooltip: {
                    backgroundColor: '#1b1b24',
                    borderColor: '#34343f',
                    borderWidth: 1,
                    titleColor: '#fff',
                    bodyColor: '#ddd',
                    cornerRadius: 10,
                    padding: 12,
                    callbacks: {
                        label: function (c) {
                            const label = c.dataset.label || '';
                            return label.includes('Rp')
                                ? label + ': Rp ' + Number(c.parsed.y).toLocaleString('id-ID')
                                : label + ': ' + c.parsed.y + ' pesanan';
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { color: '#6d6d7a' }, grid: { color: 'rgba(255,255,255,0.04)' } },
                y: {
                    position: 'left',
                    beginAtZero: true,
                    ticks: { color: '#6d6d7a', callback: v => 'Rp ' + Number(v).toLocaleString('id-ID') },
                    grid: { color: 'rgba(255,255,255,0.06)' }
                },
                y1: {
                    position: 'right',
                    beginAtZero: true,
                    ticks: { color: '#6d6d7a', stepSize: 1 },
                    grid: { drawOnChartArea: false }
                }
            }
        }
    });
});
</script>
<?php endif; ?>
