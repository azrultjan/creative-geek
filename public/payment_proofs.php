<?php
/**
 * public/payment_proofs.php
 * =====================================================================
 * VERIFIKASI BUKTI PEMBAYARAN — 2026-10-09
 * ---------------------------------------------------------------------
 * Pelanggan mengunggah foto bukti bayar dari nota digital publik
 * (nota_upload_bukti.php) → masuk ke sini dengan status 'pending'.
 *
 * Alur kerja:
 *   1. Petugas melihat foto bukti + nominal yang diklaim + catatan.
 *   2. "Terima"  → payment_proof_status='verified', payment_status='lunas'.
 *      Nominal yang diisi pelanggan otomatis disimpan sebagai
 *      payment_amount_paid supaya laporan tidak salah.
 *   3. "Tolak"   → payment_proof_status='rejected' + alasan.
 *      Alasan WAJIB diisi agar pelanggan tahu harus apa (dikirim ke nota).
 *
 * Hak akses: owner + admin (petugas yang menerima pembayaran di toko).
 * Kolom modal/HPP tidak ada di halaman ini.
 * =====================================================================
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireMenuAccess('payment_proofs');

$isOwnerRole = (($_SESSION['role'] ?? '') === 'owner');

/* =====================================================================
 * PROSES AKSI (POST)
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $id     = (int)post('id');
    $aksi   = (string)post('aksi');
    $alasan = trim((string)post('alasan', ''));

    if ($id <= 0 || !in_array($aksi, ['terima', 'tolak'], true)) {
        redirectWithMessage('payment_proofs', 'Permintaan tidak valid.', 'danger');
    }

    $oSt = db()->prepare("SELECT id, order_number, payment_status, payment_proof_status,
                                 payment_proof_note,
                                 payment_amount_paid, grand_total, customer_id
                          FROM orders WHERE id = ? LIMIT 1");
    $oSt->execute([$id]);
    $ord = $oSt->fetch();

    if (!$ord) {
        redirectWithMessage('payment_proofs', 'Pesanan tidak ditemukan.', 'danger');
    }

    // user_id bisa kosong/kedaluwarsa (sesi lama). Kalau diisi tapi tidak ada
    // di tabel users, FK menolak → dulu gagal SENYAP tanpa pesan apa pun.
    // Sekarang diperiksa dulu supaya pesannya jelas, bukan diam-diam gagal.
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $cekUser = db()->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $cekUser->execute([$uid]);
        if ((int)$cekUser->fetchColumn() === 0) {
            writeLog("Verifikasi bukti gagal: user_id {$uid} tidak ada di tabel users (sesi lama)", 'WARN');
            $uid = 0;
        }
    }

    try {
        if ($aksi === 'terima') {
            // Nominal: pakai yang diisi pelanggan bila ada; kalau kosong
            // anggap dibayar penuh sebesar grand_total.
            $dibayar = (float)($ord['payment_amount_paid'] ?? 0);
            if ($dibayar <= 0) $dibayar = (float)$ord['grand_total'];

            /* ===== CATATAN PETUGAS WAJIB DIPERBARUI (FIX 2026-10-12) =====
             * BUG NYATA (ditanyakan user: "CG0910266349 bukannya sudah di
             * verifikasi pembayarannya?"):
             * Path TOLAK memperbarui catatan, tapi path TERIMA **tidak**.
             * Akibatnya catatan selamanya berbunyi "PERLU CEK (nominal beda)"
             * walau statusnya sudah 'verified' → petugas/owner mengira belum
             * diverifikasi. Status & catatan jadi saling bertentangan.
             *
             * Sekarang: label STATUS lama di ujung catatan dibuang (nominal,
             * metode, a/n, waktu TETAP disimpan — itu keterangan asli
             * pelanggan), lalu ditambahi cap verifikasi. */
            $noteLama = trim((string)($ord['payment_proof_note'] ?? ''));
            // Kalau sebelumnya pernah DITOLAK, ambil bagian catatan aslinya saja.
            if (preg_match('~\|\s*Asli:\s*(.*)$~su', $noteLama, $mmNote)) {
                $noteLama = trim($mmNote[1]);
            } elseif (stripos($noteLama, 'DITOLAK:') === 0) {
                $noteLama = '';
            }
            // Buang label status lama di UJUNG (status baru sudah ada di kolomnya).
            $noteBersih = preg_replace(
                '~\s*·\s*(TERBACA OTOMATIS|PERLU CEK|NOMINAL TIDAK TERBACA|DIVERIFIKASI)[^·]*$~u',
                '', $noteLama);
            $noteBersih = rtrim(trim((string)$noteBersih), " \t·");
            $capVerif = 'DIVERIFIKASI ' . date('d/m/Y H:i') . ' oleh ' . (string)($_SESSION['username'] ?? 'petugas');
            $noteBaru = mb_substr(($noteBersih !== '' ? $noteBersih . ' · ' : '') . $capVerif, 0, 255);

            db()->prepare("
                UPDATE orders
                   SET payment_proof_status = 'verified',
                       payment_proof_note   = ?,
                       payment_proof_by     = ?,
                       payment_amount_paid  = COALESCE(NULLIF(payment_amount_paid, 0), ?),
                       payment_status       = 'lunas',
                       updated_at           = NOW(),
                       updated_by           = ?
                 WHERE id = ?
            ")->execute([$noteBaru, $uid > 0 ? $uid : null, $dibayar, $uid > 0 ? $uid : null, $id]);

            writeLog("Bukti bayar order {$ord['order_number']} DIVERIFIKASI oleh {$_SESSION['username']} (Rp " . number_format($dibayar, 0, ',', '.') . ")", 'INFO');
            redirectWithMessage('payment_proofs', "Bukti bayar {$ord['order_number']} diterima — pesanan ditandai LUNAS.", 'success');
        }

        // ===== TOLAK =====
        if ($alasan === '') {
            redirectWithMessage('payment_proofs', 'Alasan penolakan wajib diisi.', 'warning');
        }

        // Simpan alasan TANPA menghapus catatan asli pelanggan (nominal/nama
        // pengirim). Format: "DITOLAK: <alasan> | Asli: <catatan lama>"
        // — dibatasi agar tetap muat di kolom varchar(255).
        $noteLama = trim((string)($ord['payment_proof_note'] ?? ''));
        $noteBaru = 'DITOLAK: ' . $alasan;
        if ($noteLama !== '' && stripos($noteLama, 'DITOLAK:') !== 0) {
            $sisa = 255 - mb_strlen($noteBaru) - 9; // ' | Asli: ' = 9 karakter
            if ($sisa > 12) {
                $noteBaru .= ' | Asli: ' . mb_substr($noteLama, 0, $sisa);
            }
        }

        db()->prepare("
            UPDATE orders
               SET payment_proof_status = 'rejected',
                   payment_proof_note   = ?,
                   payment_proof_by     = ?,
                   updated_at           = NOW(),
                   updated_by           = ?
             WHERE id = ?
        ")->execute([$noteBaru, $uid > 0 ? $uid : null, $uid > 0 ? $uid : null, $id]);

        writeLog("Bukti bayar order {$ord['order_number']} DITOLAK oleh {$_SESSION['username']}: {$alasan}", 'WARN');
        redirectWithMessage('payment_proofs', "Bukti bayar {$ord['order_number']} ditolak. Pelanggan akan melihat alasannya di nota.", 'warning');

    } catch (Throwable $ex) {
        // Jangan biarkan gagal tanpa jejak — pengguna harus tahu.
        writeLog("GAGAL proses bukti bayar {$ord['order_number']} (aksi={$aksi}): " . $ex->getMessage(), 'ERROR');
        redirectWithMessage('payment_proofs', 'Gagal memproses bukti bayar: ' . $ex->getMessage(), 'danger');
    }
}

/* =====================================================================
 * AMBIL DATA
 * ===================================================================== */
$filter = trim((string)get('status', 'pending'));
if (!in_array($filter, ['pending', 'verified', 'rejected', 'cash', 'all'], true)) $filter = 'pending';

// 'pending' = yang MASIH PERLU DITINDAK oleh petugas. Termasuk 'auto_ok'
// (nominal sudah terbaca OCR & sesuai — petugas tinggal 1 klik Terima)
// dan 'cash_intent' (pelanggan bilang mau bayar tunai di toko).
// 'all' = semua yang PUNYA bukti (bukan seluruh pesanan toko).
if ($filter === 'all') {
    $where = "(o.payment_proof IS NOT NULL AND o.payment_proof <> '')"
           . " OR o.payment_proof_status = 'cash_intent'";
} elseif ($filter === 'pending') {
    $where = "o.payment_proof_status IN ('auto_ok','pending','cash_intent')";
} elseif ($filter === 'cash') {
    $where = "o.payment_proof_status = 'cash_intent'";
} else {
    $where = "o.payment_proof_status = " . db()->quote($filter);
}

$ppRows = db()->query("
    SELECT o.id, o.order_number, o.grand_total, o.payment_status,
           o.payment_method, o.payment_proof, o.payment_proof_at,
           o.payment_proof_note, o.payment_proof_status, o.payment_amount_paid,
           o.created_at, c.name AS customer_name, c.phone AS customer_phone
      FROM orders o
      LEFT JOIN customers c ON o.customer_id = c.id
     WHERE {$where}
     ORDER BY FIELD(o.payment_proof_status, 'auto_ok', 'pending', 'cash_intent', 'rejected', 'verified'),
              o.payment_proof_at DESC
     LIMIT 300
")->fetchAll();

$counts = db()->query("
    SELECT
        SUM(payment_proof_status IN ('auto_ok','pending')) AS pending,
        SUM(payment_proof_status = 'auto_ok')            AS auto_ok,
        SUM(payment_proof_status = 'verified')           AS verified,
        SUM(payment_proof_status = 'rejected')           AS rejected,
        SUM(payment_proof_status = 'cash_intent')        AS cash,
        SUM(payment_proof IS NOT NULL)                   AS total
      FROM orders
")->fetch() ?: [];

$nPending  = (int)($counts['pending']  ?? 0);
$nAutoOk   = (int)($counts['auto_ok']  ?? 0);
$nVerified = (int)($counts['verified'] ?? 0);
$nRejected = (int)($counts['rejected'] ?? 0);
$nCash     = (int)($counts['cash']     ?? 0);
$nTotal    = (int)($counts['total']    ?? 0);

$pageTitle  = 'Verifikasi Bukti Bayar';
$activeMenu = 'payment_proofs';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===== STATISTIK ===== -->
<div class="grid grid-4 mb-4">
    <a href="payment_proofs?status=pending" class="stat-card" style="text-decoration:none;">
        <div class="stat-card-icon gold"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-card-label">Perlu Ditindak</div>
        <div class="stat-card-value"><?= $nPending ?></div>
        <div class="text-faint text-xs">
            <?= $nAutoOk > 0 ? $nAutoOk . ' terbaca otomatis' : 'menunggu verifikasi' ?>
        </div>
    </a>
    <a href="payment_proofs?status=cash" class="stat-card" style="text-decoration:none;">
        <div class="stat-card-icon blue"><i class="fas fa-money-bill-wave"></i></div>
        <div class="stat-card-label">Akan Bayar Tunai</div>
        <div class="stat-card-value"><?= $nCash ?></div>
        <div class="text-faint text-xs">datang ke toko</div>
    </a>
    <a href="payment_proofs?status=verified" class="stat-card" style="text-decoration:none;">
        <div class="stat-card-icon green"><i class="fas fa-circle-check"></i></div>
        <div class="stat-card-label">Diterima</div>
        <div class="stat-card-value"><?= $nVerified ?></div>
    </a>
    <a href="payment_proofs?status=all" class="stat-card" style="text-decoration:none;">
        <div class="stat-card-icon gold"><i class="fas fa-images"></i></div>
        <div class="stat-card-label">Total Bukti Masuk</div>
        <div class="stat-card-value"><?= $nTotal ?></div>
        <?php if ($nRejected > 0): ?>
            <div class="text-faint text-xs"><?= $nRejected ?> ditolak</div>
        <?php endif; ?>
    </a>
</div>

<!-- ===== DAFTAR BUKTI ===== -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-receipt"></i> Bukti Pembayaran Masuk</h3>
        <span class="tag"><i class="fas fa-filter"></i>
            <?= $filter === 'pending' ? 'Perlu Ditindak' : ($filter === 'verified' ? 'Diterima' : ($filter === 'rejected' ? 'Ditolak' : ($filter === 'cash' ? 'Akan Bayar Tunai' : 'Semua'))) ?>
        </span>
    </div>
    <div class="card-body">
        <?php if ($filter === 'pending' && $nPending === 0): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-mug-hot"></i></div>
                <h3>Tidak ada yang perlu ditindak</h3>
                <p class="text-faint">Semua bukti pembayaran sudah diproses. 🎉</p>
            </div>
        <?php elseif (count($ppRows) === 0): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                <h3>Belum ada bukti pembayaran pada kategori ini</h3>
            </div>
        <?php else: ?>

        <div class="pp-grid">
            <?php foreach ($ppRows as $r):
                $st         = (string)$r['payment_proof_status'];
                $nominal    = (float)($r['payment_amount_paid'] ?? 0);
                $total      = (float)$r['grand_total'];
                $kurang     = max(0, $total - $nominal);
                $fotoUrl    = (string)$r['payment_proof'];
                // 'auto_ok' = nominal terbaca OCR & sesuai. Petugas tinggal
                // menekan Terima, jadi dibedakan supaya bisa diproses duluan.
                $isCash     = ($st === 'cash_intent');
                $isAuto     = ($st === 'auto_ok');
                if ($isAuto)      { $badge = 'blue';  $stLabel = 'Terbaca Otomatis'; }
                elseif ($isCash)  { $badge = 'blue';  $stLabel = 'Akan Bayar Tunai'; }
                elseif ($st === 'pending')  { $badge = 'gold';  $stLabel = 'Perlu Cek'; }
                elseif ($st === 'verified') { $badge = 'green'; $stLabel = 'Diterima'; }
                else                        { $badge = 'red';   $stLabel = 'Ditolak'; }
            ?>
            <div class="pp-card pp-<?= e($st) ?>">
                <div class="pp-head">
                    <div>
                        <div class="pp-order"><?= e((string)$r['order_number']) ?></div>
                        <div class="pp-cust"><?= e((string)($r['customer_name'] ?: 'Tanpa nama')) ?></div>
                    </div>
                    <span class="pp-badge pp-badge-<?= e($badge) ?>"><?= e($stLabel) ?></span>
                </div>

                <?php if ($isCash): ?>
                    <div class="pp-cashno">
                        <i class="fas fa-store"></i>
                        <div>
                            <b>Pelanggan akan bayar TUNAI di toko</b>
                            <span>Tagihan <?= e(formatRupiah($total)) ?> — siapkan & tandai lunas setelah dibayar.</span>
                        </div>
                    </div>
                <?php elseif ($fotoUrl !== ''): ?>
                    <a href="<?= e($fotoUrl) ?>" target="_blank" rel="noopener" class="pp-foto" title="Buka bukti ukuran penuh">
                        <img src="<?= e($fotoUrl) ?>" alt="Bukti bayar <?= e((string)$r['order_number']) ?>" loading="lazy">
                        <span class="pp-foto-zoom"><i class="fas fa-magnifying-glass-plus"></i> Perbesar</span>
                    </a>
                <?php endif; ?>

                <div class="pp-meta">
                    <div class="pp-row"><span>Total Tagihan</span><b><?= e(formatRupiah($total)) ?></b></div>
                    <?php if (!$isCash): ?>
                        <div class="pp-row"><span>Terbaca / Diklaim</span><b class="<?= $nominal > 0 ? 'text-success' : '' ?>">
                            <?= $nominal > 0 ? e(formatRupiah($nominal)) : '—' ?>
                            <?php if ($isAuto): ?><i class="fas fa-wand-magic-sparkles" title="Dibaca otomatis dari foto" style="color:var(--accent);margin-left:4px;"></i><?php endif; ?>
                        </b></div>
                        <?php if ($nominal > 0 && $kurang > 0): ?>
                            <div class="pp-row"><span>Kurang</span><b class="text-danger"><?= e(formatRupiah($kurang)) ?></b></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="pp-row"><span>Metode</span><b><?= e((string)($r['payment_method'] ?: '—')) ?></b></div>
                    <div class="pp-row"><span><?= $isCash ? 'Diminta' : 'Dikirim' ?></span><b>
                        <?= !empty($r['payment_proof_at']) ? e(date('d M Y, H:i', strtotime((string)$r['payment_proof_at']))) : '—' ?></b></div>
                    <div class="pp-row"><span>Status Bayar</span><b class="<?= $r['payment_status'] === 'lunas' ? 'text-success' : 'text-danger' ?>">
                        <?= $r['payment_status'] === 'lunas' ? 'LUNAS' : 'BELUM' ?></b></div>
                </div>

                <?php if (!empty($r['payment_proof_note']) && !$isCash): ?>
                    <div class="pp-note"><i class="fas fa-comment-dots"></i> <?= e((string)$r['payment_proof_note']) ?></div>
                <?php endif; ?>

                <div class="pp-actions">
                    <?php if ($st !== 'verified'): ?>
                        <form method="POST" action="payment_proofs" style="flex:1;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="aksi" value="terima">
                            <button type="submit" class="btn btn-success btn-sm" style="width:100%;"
                                    onclick="return confirm('<?= $isCash ? 'Pelanggan sudah bayar tunai? Pesanan ' . e((string)$r['order_number']) . ' akan ditandai LUNAS.' : 'Terima bukti ini dan tandai pesanan ' . e((string)$r['order_number']) . ' sebagai LUNAS?' ?>');">
                                <i class="fas fa-circle-check"></i> <?= $isCash ? 'Sudah Bayar (LUNAS)' : 'Terima & LUNAS' ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="pp-done"><i class="fas fa-circle-check"></i> Sudah diverifikasi</div>
                    <?php endif; ?>

                    <?php if (!$isCash && $st !== 'rejected'): ?>
                        <button type="button" class="btn btn-danger btn-sm" style="flex:1;"
                                onclick="ppTolak(<?= (int)$r['id'] ?>, '<?= e((string)$r['order_number']) ?>')">
                            <i class="fas fa-circle-xmark"></i> Tolak
                        </button>
                    <?php elseif ($isCash && $st !== 'verified'): ?>
                        <button type="button" class="btn btn-ghost btn-sm" style="flex:1;"
                                onclick="ppTolak(<?= (int)$r['id'] ?>, '<?= e((string)$r['order_number']) ?>')" title="Pelanggan batal bayar tunai">
                            <i class="fas fa-ban"></i> Batal
                        </button>
                    <?php endif; ?>

                    <a href="nota/<?= e((string)$r['order_number']) ?>" target="_blank" rel="noopener"
                       class="btn btn-ghost btn-sm" title="Buka nota digital pelanggan">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>
</div>

<!-- ===== MODAL TOLAK ===== -->
<div class="modal" id="ppTolakModal">
    <div class="modal-content" style="max-width:440px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-circle-xmark text-danger"></i> Tolak Bukti Pembayaran</h3>
            <button type="button" class="modal-close" onclick="ppClose()" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="payment_proofs" id="ppTolakForm">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="ppTolakId">
            <input type="hidden" name="aksi" value="tolak">
            <div class="modal-body">
                <p class="text-faint text-sm mb-3">
                    Alasan ini akan <strong>tampil di nota digital pelanggan</strong>, jadi tulis dengan jelas
                    (mis. foto kurang jelas, nominal tidak sesuai, bukan bukti transfer ke rekening toko).
                </p>
                <div class="form-group">
                    <label class="form-label" for="ppAlasan">Alasan Penolakan <span class="text-danger">*</span></label>
                    <textarea class="form-input" name="alasan" id="ppAlasan" rows="3" required
                              placeholder="mis. Foto buram, mohon unggah ulang yang lebih jelas."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="ppClose()">Batal</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-paper-plane"></i> Tolak Bukti</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Kartu bukti bayar — grid responsif, foto selalu terlihat utuh */
.pp-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(285px,1fr)); gap:14px; }
.pp-card{ border:1px solid var(--border); border-radius:14px; overflow:hidden; background:var(--surface);
          display:flex; flex-direction:column; transition:border-color .2s, transform .15s; }
.pp-card:hover{ border-color:var(--accent); transform:translateY(-2px); }
.pp-pending{ border-left:4px solid var(--accent); }
.pp-verified{ border-left:4px solid var(--success); }
.pp-rejected{ border-left:4px solid var(--danger); }
.pp-auto_ok{ border-left:4px solid #2563eb; }
.pp-cash_intent{ border-left:4px solid #2563eb; }
.pp-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:8px; padding:11px 12px; border-bottom:1px solid var(--border); }
.pp-order{ font-size:13px; font-weight:800; letter-spacing:.3px; }
.pp-cust{ font-size:11px; color:var(--text-muted); margin-top:1px; }
.pp-badge{ font-size:9.5px; font-weight:800; text-transform:uppercase; letter-spacing:.5px;
           padding:3px 8px; border-radius:999px; white-space:nowrap; }
.pp-badge-gold{ background:rgba(245,166,35,.15); color:var(--accent); }
.pp-badge-green{ background:rgba(34,197,94,.15); color:var(--success); }
.pp-badge-red{ background:rgba(239,68,68,.15); color:var(--danger); }
.pp-badge-blue{ background:rgba(59,130,246,.15); color:#2563eb; }
/* Kartu "akan bayar tunai" — tidak ada foto bukti, jadi diberi panel sendiri */
.pp-cashno{ display:flex; align-items:flex-start; gap:10px; margin:12px; padding:12px;
            background:#eff6ff; border:1px dashed #bfdbfe; border-radius:12px; }
.pp-cashno i{ color:#2563eb; font-size:18px; margin-top:2px; flex:0 0 auto; }
.pp-cashno b{ display:block; font-size:12.5px; color:#1e40af; margin-bottom:2px; }
.pp-cashno span{ display:block; font-size:11px; color:#3b82f6; line-height:1.5; }
.pp-foto{ position:relative; display:block; background:#000; }
.pp-foto img{ width:100%; height:190px; object-fit:contain; display:block; }
.pp-foto-zoom{ position:absolute; bottom:7px; right:7px; font-size:10px; font-weight:700;
               background:rgba(0,0,0,.65); color:#fff; padding:3px 8px; border-radius:999px; }
.pp-meta{ padding:10px 12px; display:grid; gap:4px; }
.pp-row{ display:flex; justify-content:space-between; gap:10px; font-size:11.5px; }
.pp-row span{ color:var(--text-muted); }
.pp-row b{ text-align:right; }
.pp-note{ margin:0 12px 10px; padding:8px 10px; background:var(--surface-3); border-radius:9px;
          font-size:11px; line-height:1.5; color:var(--text-muted); }
.pp-actions{ display:flex; gap:7px; padding:10px 12px; border-top:1px solid var(--border); margin-top:auto; }
.pp-done{ flex:1; display:flex; align-items:center; justify-content:center; gap:6px;
          font-size:11.5px; font-weight:700; color:var(--success); }
@media (max-width:520px){ .pp-grid{ grid-template-columns:1fr; } }
</style>

<script>
function ppTolak(id, order) {
    document.getElementById('ppTolakId').value = id;
    document.getElementById('ppAlasan').value = '';
    document.getElementById('ppTolakModal').classList.add('active');
    setTimeout(() => document.getElementById('ppAlasan').focus(), 120);
}
function ppClose() {
    document.getElementById('ppTolakModal').classList.remove('active');
}
// Klik latar gelap = tutup
document.getElementById('ppTolakModal').addEventListener('click', function (e) {
    if (e.target === this) ppClose();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
