<?php
/**
 * public/stock.php
 * =====================================================================
 * KARTU STOK BAHAN — Creative Geek
 * =====================================================================
 * Stok bahan (roll spanduk/sablon) dicatat sebagai MODAL, bukan "los".
 *
 * Isi halaman:
 *   1. Ringkasan nilai stok (aset) + pemakaian & kerusakan periode ini
 *   2. Kartu per produk: sisa roll, sisa meter, nilai modal, bar progres
 *   3. Buku besar (kartu stok): masuk / potong / rusak / koreksi / opname
 *   4. Aksi: Terima Roll · Lapor Kerusakan · Stok Opname · Set Harga Modal
 *
 * SEMUA perubahan stok lewat includes/stock.php → mustahil berubah tanpa jejak.
 * =====================================================================
 */
require_once __DIR__ . '/../includes/bootstrap.php';

/* Login dulu (belum login → dialihkan ke halaman login seperti biasa),
 * BARU diperiksa apakah dia owner. Urutan ini sengaja:
 *   - `requireMenuAccess()` di bawah akan MENGALIHKAN admin tanpa pesan apa
 *     pun, sehingga admin mengira halamannya rusak. Kita ingin dia tahu
 *     alasannya.
 *   - Kalau pemeriksaan owner ditaruh paling atas, pengunjung yang BELUM
 *     login akan kena 403 padahal seharusnya diminta login dulu. */
requireLogin();

/* =====================================================================
 * PENJAGA KEDUA: KARTU STOK = OWNER SAJA (revisi pemilik 2026-10-09)
 * ---------------------------------------------------------------------
 * PEMILIK: "saat ini admin masih bisa oprek stock bahan bukannya aku udah
 *           blg stock hanya untuk owner saja ya ?"
 *
 * Benar, dan ini kelalaian kami: waktu itu yang dibatasi hanya HARGA MODAL
 * (angka rupiahnya disembunyikan), sedangkan TOMBOLNYA masih diberikan ke
 * admin — Terima Stok, Lapor Rusak, Opname, Sinkron. Jadi admin tetap bisa
 * MENGUBAH stok walau nominalnya tidak terlihat. Yang diminta pemilik
 * adalah stok itu sendiri tertutup untuk admin.
 *
 * Sebab admin masih bisa membuka menunya: baris `menu_access` untuk admin
 * masih `is_visible = 1`. Baris itu sudah dimatikan, tetapi penjaga DI SINI
 * tetap wajib ada — "menu disembunyikan" bukan berarti aman. Siapa pun yang
 * mengetik URL langsung tetap harus ditolak.
 *
 * PESAN DITULIS JELAS, bukan sekadar "Akses ditolak", supaya admin yang
 * mencoba membuka tahu alasannya dan tidak mengira sistemnya rusak.
 * ===================================================================== */
$role = (string)($_SESSION['role'] ?? '');
if ($role !== 'owner') {
    writeLog('AKSES DITOLAK: ' . (current_user()['username'] ?? '?')
        . " ($role) coba buka Kartu Stok Bahan — stok hanya untuk owner", 'WARNING');
    http_response_code(403);
    exit('Akses ditolak. Kartu Stok Bahan hanya untuk owner.');
}

requireMenuAccess('stock');

$me = current_user();

/* =====================================================================
 * CATATAN RIWAYAT HAK AKSES
 * ---------------------------------------------------------------------
 * Sebelumnya pembatasan di bawah ini HANYA menyembunyikan harga modal dari
 * admin, sementara admin masih boleh mengubah stok fisik.
 * Sejak revisi 2026-10-09 halaman ini TERTUTUP untuk admin seluruhnya
 * (lihat penjaga di atas), jadi `$bolehModal` tinggal mengikuti owner.
 * Pemeriksaan per-tombol sengaja TIDAK dihapus supaya jalur ini tetap benar
 * bila suatu saat pemilik memutuskan memberi admin akses baca-saja.
 * ===================================================================== */
$isOwnerRole  = ($role === 'owner');
$bolehModal   = $isOwnerRole;   // boleh lihat & ubah harga modal / nilai
$bolehSinkron = $isOwnerRole;   // sinkron & pengaturan = owner saja

/** Simpan setting (tanpa bergantung cache getSetting). */
function stock_set_setting(string $key, string $value): void
{
    db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
        ->execute([$key, $value]);
}

/** Upload foto bukti kerusakan → /uploads/stock/ */
function stock_upload_photo(array $file): string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return '';
    if ((int)($file['size'] ?? 0) > 3 * 1024 * 1024) return '';
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return '';
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $ext = $allowed[$info[2]] ?? '';
    if ($ext === '') return '';
    $dir = __DIR__ . '/uploads/stock';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $name = 'dmg_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return '';
    return '/uploads/stock/' . $name;
}

$ERRORS = ['sobek' => 'Sobek / robek', 'salah_cetak' => 'Salah cetak', 'salah_potong' => 'Salah potong',
           'kotor' => 'Kotor / rusak saat kirim', 'afalan' => 'Afalan dibuang', 'lainnya' => 'Lainnya'];

/* =====================================================================
 * AKSI — semua POST + CSRF
 * ===================================================================== */

/* --- 1. TERIMA STOK (roll baru ATAU satuan umum: jergen/botol/pcs) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_rolls'])) {
    verify_csrf();
    $pid   = (int)post('product_id', 0);
    $count = (float)str_replace(',', '.', (string)post('roll_count', '0'));
    $w     = (float)str_replace(',', '.', (string)post('width_m', '0'));
    $len   = (float)str_replace(',', '.', (string)post('length_m', '0'));
    $cost  = (float)preg_replace('/[^0-9.]/', '', (string)post('cost_per_roll', '0'));
    $date  = (string)post('received_at', date('Y-m-d'));
    $note  = trim((string)post('note', ''));

    if ($pid <= 0 || $count <= 0) {
        redirectWithMessage('stock', 'Produk dan jumlah wajib diisi.', 'danger');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $p    = stock_product($pid);
    $unit = (string)($p['unit'] ?? 'pcs');

    /* ===== BAHAN NON-ROLL (tinta jergen/botol, x-banner pcs, lembar) ===== */
    if (!in_array($unit, ['roll', 'meter'], true)) {
        $res = stock_receive_qty($pid, $count, $cost, [
            'reason' => 'beli',
            'note'   => $note !== '' ? $note : ('terima ' . rtrim(rtrim(number_format($count, 2, ',', '.'), '0'), ',') . ' ' . $unit),
        ]);
        if (!$res['ok']) {
            redirectWithMessage('stock', 'Gagal menambah stok: ' . ($res['error'] ?? '?'), 'danger');
        }
        $costTotal = (float)($res['cost_total'] ?? 0);
        // Pengeluaran 'Bahan' HANYA bila owner menyalakannya. Default MATI supaya
        // pembelian tidak dihitung dobel dengan HPP pemakaian (lihat
        // stock_buat_expenses_bahan() di includes/stock.php).
        $buatExpense = ($costTotal > 0 && stock_buat_expenses_bahan());
        if ($buatExpense) {
            db()->prepare("INSERT INTO expenses (amount, category, expense_date, note, created_by) VALUES (?,?,?,?,?)")
                ->execute([$costTotal, 'Bahan', $date,
                    'Beli ' . rtrim(rtrim(number_format($count, 2, ',', '.'), '0'), ',') . ' ' . $unit . ' ' . ($p['category'] ?? '') . ' ' . ($p['variant'] ?? ''),
                    (int)($me['id'] ?? 0) ?: null]);
        }
        writeLog("Stok bahan: terima $count $unit produk #$pid oleh {$me['username']}", 'INFO');
        $msg = 'Berhasil menambah ' . rtrim(rtrim(number_format($count, 2, ',', '.'), '0'), ',') . ' ' . $unit . '.';
        if ($costTotal > 0) {
            $msg .= $buatExpense
                ? ' Modal ' . formatRupiah($costTotal) . ' tercatat di Keuangan.'
                : ' Nilai ' . formatRupiah($costTotal) . ' tersimpan di kartu stok (tidak dobel di Keuangan).';
        }
        redirectWithMessage('stock', $msg, 'success');
    }

    /* ===== BAHAN ROLL ===== */
    if ((float)($p['roll_length_m'] ?? 0) <= 0 && $len <= 0) {
        redirectWithMessage('stock', 'Panjang roll wajib diisi (produk ini belum punya panjang roll).', 'danger');
    }
    if ($w <= 0) $w = (float)($p['roll_width_m'] ?? 0);

    $res = stock_receive_rolls($pid, $count, $w, $len > 0 ? $len : (float)$p['roll_length_m'], $cost, [
        'reason' => 'beli', 'note' => $note, 'received_at' => $date,
    ]);

    if (!$res['ok']) {
        redirectWithMessage('stock', 'Gagal menerima roll: ' . ($res['error'] ?? '?'), 'danger');
    }

    // Catat pengeluaran modal bahan — HANYA bila owner menyalakannya (default
    // MATI supaya tidak dobel dengan HPP pemakaian).
    $costTotal = (float)($res['cost_total'] ?? 0);
    $buatExpense = ($costTotal > 0 && stock_buat_expenses_bahan());
    if ($buatExpense) {
        db()->prepare("INSERT INTO expenses (amount, category, expense_date, note, created_by) VALUES (?,?,?,?,?)")
            ->execute([$costTotal, 'Bahan', $date,
                'Beli ' . (int)round($count) . ' roll ' . ($p['category'] ?? '') . ' ' . ($p['variant'] ?? ''),
                (int)($me['id'] ?? 0) ?: null]);
    }

    writeLog("Stok bahan: terima " . (int)round($count) . " roll produk #$pid oleh {$me['username']}", 'INFO');
    $msg = 'Berhasil menerima ' . (int)round($count) . ' roll.';
    if ($costTotal > 0) {
        $msg .= $buatExpense
            ? ' Modal ' . formatRupiah($costTotal) . ' tercatat di Keuangan.'
            : ' Nilai ' . formatRupiah($costTotal) . ' tersimpan di kartu stok (tidak dobel di Keuangan).';
    }
    redirectWithMessage('stock', $msg, 'success');
}

/* --- 1b. TERIMA STOK PER LEBAR (hasil potong memanjang / slit) ---
 *
 * Kasus nyata: nota supplier menulis "6 roll 3.2m x 70m", tetapi roll itu
 * DIPOTONG MEMANJANG dulu menjadi lebar 2.1m / 1.6m / 1.1m supaya sisa bahan
 * saat mencetak ukuran kecil tidak terbuang. Jadi yang masuk gudang BUKAN
 * 6 roll selebar 3.2m, melainkan beberapa kelompok roll dengan lebar berbeda.
 *
 * Form mengirim:
 *   group_width[]  = 3.2 | 2.1 | 1.6 | 1.1
 *   group_count[]  = 2   | 2   | 1   | 1
 *   group_cost[]   = harga PER ROLL lebar tsb (boleh 0 → pakai harga produk)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_groups'])) {
    verify_csrf();
    $pid  = (int)post('product_id', 0);
    $len  = (float)str_replace(',', '.', (string)post('length_m', '0'));
    $date = (string)post('received_at', date('Y-m-d'));
    $note = trim((string)post('note', ''));

    $ws    = (array)post('group_width', []);
    $cs    = (array)post('group_count', []);
    $co    = (array)post('group_cost', []);
    $groups = [];
    foreach ($ws as $i => $wraw) {
        $w = (float)str_replace(',', '.', (string)$wraw);
        $n = (float)str_replace(',', '.', (string)($cs[$i] ?? 0));
        $c = (float)preg_replace('/[^0-9.]/', '', (string)($co[$i] ?? 0));
        if ($w > 0 && $n > 0) $groups[] = ['width' => $w, 'count' => $n, 'cost' => $c];
    }

    if ($pid <= 0 || !$groups) {
        redirectWithMessage('stock', 'Isi minimal satu baris lebar roll dengan jumlah lebih dari 0.', 'danger');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $res = stock_receive_roll_groups($pid, $groups, $len, [
        'reason' => 'beli', 'note' => $note, 'received_at' => $date,
        'created_by' => (int)($me['id'] ?? 0),
    ]);
    if (!$res['ok']) {
        redirectWithMessage('stock', 'Gagal menerima roll: ' . ($res['error'] ?? '?'), 'danger');
    }

    $p = stock_product($pid);
    writeLog("Stok bahan: terima " . $res['rolls'] . " roll ("
        . implode(' + ', $res['rincian'] ?? []) . ") produk #$pid oleh {$me['username']}", 'INFO');

    $msg = 'Berhasil menerima ' . $res['rolls'] . ' roll (' . implode(' + ', $res['rincian'] ?? []) . ')'
         . ' = ' . rtrim(rtrim(number_format($res['meters'], 2, ',', '.'), '0'), ',') . ' meter.';
    if (($res['cost_total'] ?? 0) > 0) {
        $msg .= ' Modal ' . formatRupiah($res['cost_total'])
              . ($res['expense_dibuat'] ? ' tercatat di Keuangan.' : ' tersimpan di kartu stok (tidak dobel di Keuangan).');
    }
    redirectWithMessage('stock', $msg, 'success');
}

/* --- 1c. PEMBENAHAN STOK FISIK PER LEBAR (opname bertingkat) ---
 *
 * Petugas menghitung sisa nyata TIAP lebar, lalu sistem menyusun ulang baris
 * roll supaya cocok. Selisih dicatat ke buku besar dengan alasan
 * 'penyesuaian_fisik' → ada jejak audit, bukan ubah data diam-diam.
 *
 * Form mengirim: fix_width[] (lebar) + fix_meter[] (sisa nyata dalam meter).
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_rolls'])) {
    verify_csrf();
    $pid  = (int)post('product_id', 0);
    $note = trim((string)post('note', ''));
    $fws  = (array)post('fix_width', []);
    $fms  = (array)post('fix_meter', []);

    $perWidth = [];
    foreach ($fws as $i => $wraw) {
        $w  = (float)str_replace(',', '.', (string)$wraw);
        $mRaw = trim((string)($fms[$i] ?? ''));
        if ($w <= 0 || $mRaw === '') continue;
        $m = (float)str_replace(',', '.', $mRaw);
        if ($m < 0) continue;
        // Kunci WAJIB string: PHP memaksa kunci array desimal menjadi bilangan
        // bulat (3.2 → 3, 1.6 → 1) sehingga 1.6 dan 1.1 saling menimpa.
        $perWidth[number_format(round($w, 2), 2, '.', '')] = $m;
    }
    if ($pid <= 0 || !$perWidth) {
        redirectWithMessage('stock', 'Isi sisa nyata minimal satu lebar (isi 0 bila lebar itu sudah habis).', 'danger');
    }

    $res = stock_set_rolls_by_width($pid, $perWidth, [
        'note' => $note !== '' ? $note : 'pembenahan stok fisik (slit)',
    ]);
    if (!$res['ok']) {
        redirectWithMessage('stock', 'Gagal membenahi stok: ' . ($res['error'] ?? '?'), 'danger');
    }

    $rincian = [];
    foreach ($res['laporan'] as $l) {
        $rincian[] = rtrim(rtrim(number_format($l['width'], 2, ',', '.'), '0'), ',') . 'm → '
                   . rtrim(rtrim(number_format($l['baru'], 2, ',', '.'), '0'), ',') . ' m';
    }
    writeLog("Stok bahan: pembenahan fisik produk #$pid oleh {$me['username']} — " . implode(', ', $rincian), 'INFO');

    $msg = 'Stok disesuaikan: ' . implode(', ', $rincian) . '. '
         . 'Total ' . rtrim(rtrim(number_format($res['total_lama'], 2, ',', '.'), '0'), ',') . ' m → '
         . rtrim(rtrim(number_format($res['total_baru'], 2, ',', '.'), '0'), ',') . ' m (beda '
         . ($res['selisih'] >= 0 ? '+' : '') . rtrim(rtrim(number_format($res['selisih'], 2, ',', '.'), '0'), ',') . ' m).';
    redirectWithMessage('stock', $msg, abs($res['selisih']) < 0.01 ? 'info' : 'success');
}

/* --- 2. LAPOR KERUSAKAN --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_damage'])) {
    verify_csrf();
    $pid    = (int)post('product_id', 0);
    $meters = (float)str_replace(',', '.', (string)post('damage_meters', '0'));
    $reason = (string)post('damage_reason', 'lainnya');
    $note   = trim((string)post('damage_note', ''));
    if (!isset($ERRORS[$reason])) $reason = 'lainnya';

    if ($pid <= 0 || $meters <= 0) {
        redirectWithMessage('stock', 'Produk dan jumlah rusak wajib diisi.', 'danger');
    }

    $photo = isset($_FILES['damage_photo']) ? stock_upload_photo($_FILES['damage_photo']) : '';
    $pu    = stock_product($pid);
    $pun   = (string)($pu['unit'] ?? 'pcs');

    if (in_array($pun, ['roll', 'meter'], true)) {
        $res = stock_report_damage($pid, $meters, $reason, ['note' => $note, 'photo' => $photo ?: null]);
    } else {
        // Satuan umum: rusak dihitung per jergen/botol/pcs
        $res = stock_consume_qty($pid, $meters, $reason, ['note' => $note, 'photo' => $photo ?: null], 'rusak');
    }

    if (!$res['ok']) {
        redirectWithMessage('stock', 'Gagal melaporkan kerusakan: ' . ($res['error'] ?? '?'), 'danger');
    }
    writeLog("Stok bahan RUSAK $meters $pun produk #$pid ($reason) oleh {$me['username']}", 'WARNING');
    redirectWithMessage('stock', 'Kerusakan ' . rtrim(rtrim(number_format($meters, 2, ',', '.'), '0'), ',') . ' ' . $pun . ' dicatat. Terima kasih sudah melaporkan.', 'success');
}

/* --- 3. STOK OPNAME (hitung fisik vs sistem) ---
 * =====================================================================
 * FIX 2026-10-09 — OPNAME TIDAK BEKERJA untuk item non-roll (pcs/jergen/botol)
 * ---------------------------------------------------------------------
 * BUG NYATA (ditemukan saat audit): backend memakai `stock_total_meters()`
 * yang SELALU membaca kolom `stock_meters`. Padahal item non-roll (x-banner,
 * tinta jergen, tinta botol) menyimpan stoknya di `stock_qty`.
 *
 * Akibatnya untuk item non-roll:
 *   1) layar menampilkan angka BENAR (mis. x-banner 135 pcs, dari kartu),
 *      tapi server menghitung "sistem" = 0 → selisih jadi 135
 *   2) pesan salah: "sistem LEBIH 135 pcs" padahal fisik = sistem
 *   3) koreksi ditulis ke `stock_meters` (kolom yang TIDAK dipakai item itu)
 *      → stok sebenarnya TIDAK PERNAH terkoreksi
 *   4) nilai salah itu masuk buku besar sebagai "meter" → mengotori HPP
 *
 * PERBAIKAN:
 *   - satuan diambil dari produk (server, bukan dari input user — user bisa
 *     kirim unit palsu; nota sudah punya preseden validasi begini)
 *   - item roll  → pakai stock_total_meters(), koreksi ke stock_meters
 *   - item non-roll → pakai stock_qty, koreksi ke stock_qty (skip_cache,
 *     sama seperti pola stock_consume_qty)
 *   - pesan memakai satuan yang benar + arah selisih yang benar
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_opname'])) {
    verify_csrf();
    $pid    = (int)post('product_id', 0);
    $fisik  = (float)str_replace(',', '.', (string)post('physical_meters', '0'));
    $note   = trim((string)post('opname_note', ''));

    if ($pid <= 0) redirectWithMessage('stock', 'Produk wajib dipilih.', 'danger');
    if ($fisik < 0) redirectWithMessage('stock', 'Jumlah fisik tidak boleh negatif.', 'warning');

    $p     = stock_product($pid);
    if (!$p) redirectWithMessage('stock', 'Produk tidak ditemukan.', 'danger');

    $unit  = (string)($p['unit'] ?? 'pcs');
    $isRoll = in_array($unit, ['roll', 'meter'], true);
    // Satuan tampil untuk pesan: roll ditampilkan sebagai meter (cara ukur nyata)
    $tampilUnit = $isRoll ? 'm' : $unit;

    // Nilai menurut sistem — sumbernya HARUS sesuai jenis produknya
    if ($isRoll) {
        $sistem = stock_total_meters($pid);                 // meter
    } else {
        $sistem = (float)($p['stock_qty'] ?? 0);            // pcs/jergen/botol
    }

    $diff = round($fisik - $sistem, 3);

    $fmt = static function (float $n) use ($tampilUnit): string {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',') . ' ' . $tampilUnit;
    };

    if (abs($diff) < 0.001) {
        redirectWithMessage('stock', 'Stok fisik SAMA dengan sistem (' . $fmt($sistem) . '). Tidak ada penyesuaian.', 'info');
    }

    if ($isRoll) {
        // Roll: sisa meter disimpan di kolom stock_meters
        db()->prepare("UPDATE product_prices SET stock_meters = GREATEST(stock_meters + ?, 0), updated_at = NOW() WHERE id = ?")
            ->execute([$diff, $pid]);

        stock_ledger_add($pid, 'opname', $diff, [
            'reason'    => 'opname',
            'note'      => 'Fisik ' . $fmt($fisik) . ' vs sistem ' . $fmt($sistem) . ' (selisih ' . $fmt($diff) . '). ' . $note,
            'ref_type'  => 'opname',
            'unit'      => $unit,
            'unit_cost' => stock_cost_per_meter($p),
        ]);
    } else {
        // Non-roll: stok disimpan di stock_qty. JANGAN sentuh cache roll/meter
        // (pola sama dgn stock_consume_qty) supaya tidak mengotori HPP.
        $baru = max(0, (float)($p['stock_qty'] ?? 0) + $diff);
        db()->prepare("UPDATE product_prices SET stock_qty = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$baru, $pid]);

        $unitCost = (float)($p['cost_per_unit'] ?? 0);
        stock_ledger_add($pid, 'opname', $diff, [
            'reason'    => 'opname',
            'note'      => 'Fisik ' . $fmt($fisik) . ' vs sistem ' . $fmt($sistem) . ' (selisih ' . $fmt($diff) . '). ' . $note,
            'ref_type'  => 'opname',
            'unit'      => $unit,
            'qty'       => $diff,                 // nilai utama utk satuan umum
            'unit_cost' => $unitCost > 0 ? $unitCost : null,
            'skip_cache'=> true,
        ]);
    }

    writeLog("Stok opname produk #$pid ($unit): fisik=$fisik sistem=$sistem selisih=$diff oleh {$me['username']}", 'WARNING');
    // Arah pesan: diff negatif = fisik lebih SEDIKIT dari catatan
    $pesan = $diff < 0
        ? 'Stok fisik LEBIH SEDIKIT ' . $fmt(abs($diff)) . ' dari catatan sistem'
        : 'Stok fisik LEBIH BANYAK ' . $fmt($diff) . ' dari catatan sistem';
    redirectWithMessage('stock', 'Opname dicatat: ' . $pesan . '. Selisih masuk buku besar.', 'warning');
}

/* --- 4. SET HARGA MODAL — OWNER SAJA (admin tidak boleh tahu/ubah modal) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_cost'])) {
    verify_csrf();
    if (!$bolehModal) {
        writeLog("AKSES DITOLAK: {$me['username']} ($role) coba ubah harga modal produk #" . (int)post('product_id', 0), 'WARNING');
        redirectWithMessage('stock', 'Hanya owner yang boleh mengubah harga modal.', 'danger');
    }
    $pid  = (int)post('product_id', 0);
    $cost = (float)preg_replace('/[^0-9.]/', '', (string)post('cost_per_roll', '0'));
    if ($pid <= 0) redirectWithMessage('stock', 'Produk wajib dipilih.', 'danger');

    $p    = stock_product($pid);
    $unit = (string)($p['unit'] ?? 'pcs');
    $len  = (float)($p['roll_length_m'] ?? 0);

    if (in_array($unit, ['roll', 'meter'], true)) {
        db()->prepare("UPDATE product_prices SET cost_per_roll = ?, cost_per_meter = ?, track_stock = 1, updated_at = NOW() WHERE id = ?")
            ->execute([$cost > 0 ? $cost : null, ($cost > 0 && $len > 0) ? round($cost / $len, 2) : null, $pid]);
        $info = $cost > 0 && $len > 0 ? ' = ' . formatRupiah(round($cost / $len, 2)) . '/meter' : '';
    } else {
        db()->prepare("UPDATE product_prices SET cost_per_unit = ?, track_stock = 1, updated_at = NOW() WHERE id = ?")
            ->execute([$cost > 0 ? $cost : null, $pid]);
        $info = $cost > 0 ? ' per ' . $unit : '';
    }

    writeLog("Harga modal produk #$pid diset Rp " . number_format($cost, 0, ',', '.') . " oleh {$me['username']}", 'INFO');
    redirectWithMessage('stock', 'Harga modal disimpan' . $info . '.', 'success');
}

/* --- 6b. TANDAI HABIS (bahan non-roll: tinta, pcs) ---
 * Tinta tidak bisa dipotong per order (pemakaian tergantung desain: blok = boros,
 * tulisan = irit). Jadi dicatat saat HABIS. Penanda ini menjadi titik batas
 * untuk menghitung tarif pemakaian nyata saat restock berikutnya. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_empty'])) {
    verify_csrf();
    $pid  = (int)post('product_id', 0);
    $tgl  = (string)post('empty_date', date('Y-m-d'));
    $note = trim((string)post('empty_note', ''));
    if ($pid <= 0) redirectWithMessage('stock', 'Produk wajib dipilih.', 'danger');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $tgl = date('Y-m-d');

    $p    = stock_product($pid);
    $unit = (string)($p['unit'] ?? 'pcs');
    $sisa = (float)($p['stock_qty'] ?? 0);
    if ($sisa <= 0.001) {
        redirectWithMessage('stock', 'Stok produk ini sudah 0 (habis).', 'info');
    }

    $res = stock_consume_qty($pid, $sisa, 'habis_dipakai', [
        'note' => $note !== '' ? $note : ('Ditandai habis pada ' . date('d/m/Y', strtotime($tgl))),
    ], 'koreksi');
    if (empty($res['ok'])) {
        redirectWithMessage('stock', 'Gagal menandai habis: ' . ($res['error'] ?? '?'), 'danger');
    }
    // tanggal kanonik = tanggal yang dipilih (menjadi titik batas analisa tinta)
    db()->prepare("UPDATE stock_movements SET created_at = ? WHERE id = ?")
        ->execute([$tgl . ' 12:00:00', (int)$res['movement_id']]);
    db()->prepare("UPDATE product_prices SET stock_qty = 0, updated_at = NOW() WHERE id = ?")->execute([$pid]);

    writeLog("Stok #$pid ditandai HABIS ({$sisa} $unit) oleh {$me['username']}", 'INFO');
    redirectWithMessage('stock', 'Ditandai habis: ' . rtrim(rtrim(number_format($sisa, 2, ',', '.'), '0'), ',') . ' ' . $unit
        . '. Catat restock saat tinta baru datang — sistem akan menghitung tarif pemakaian nyata.', 'success');
}

/* --- 7. SINKRON PEMAKAIAN — OWNER SAJA --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_sync'])) {
    verify_csrf();
    if (!$bolehSinkron) {
        writeLog("AKSES DITOLAK: {$me['username']} ($role) coba sinkron stok produk #" . (int)post('product_id', 0), 'WARNING');
        redirectWithMessage('stock', 'Sinkron stok hanya untuk owner.', 'danger');
    }
    $pid     = (int)post('product_id', 0);
    $dari    = (string)post('sync_from', '');
    $mode    = (string)post('sync_mode', 'hitung');   // hitung | terapkan
    if ($pid <= 0) redirectWithMessage('stock', 'Produk wajib dipilih.', 'danger');

    $p = stock_product($pid);
    if (!$p) redirectWithMessage('stock', 'Produk tidak ditemukan.', 'danger');

    // Default tanggal mulai: tanggal terima roll paling tua
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
        $stR = db()->prepare("SELECT MIN(received_at) FROM stock_rolls WHERE product_id=? AND received_at IS NOT NULL");
        $stR->execute([$pid]);
        $dari = (string)($stR->fetchColumn() ?: date('Y-m-01'));
    }

    $dry = ($mode !== 'terapkan');
    $res = stock_sync_usage($pid, $dari, date('Y-m-d'), (float)$p['roll_width_m'], $dry);
    if (empty($res['ok'])) {
        redirectWithMessage('stock', 'Gagal sinkron: ' . ($res['error'] ?? '?'), 'danger');
    }

    if ($dry) {
        $dariF = date('d/m/Y', strtotime($dari));
        $msg = 'Hitung ulang: pemakaian ' . rtrim(rtrim(number_format($res['total_m'], 1, ',', '.'), '0'), ',')
             . ' m dari ' . count($res['hari']) . ' hari produksi (sejak ' . $dariF . '). '
             . 'Stok akan menjadi ' . rtrim(rtrim(number_format(max(0, stock_total_meters($pid) - $res['total_m']), 1, ',', '.'), '0'), ',') . ' m. '
             . 'Klik "Terapkan" untuk menyimpan.';
        redirectWithMessage('stock', $msg, 'info');
    }

    writeLog("Sinkron stok produk #$pid: " . $res['total_m'] . " m terpakai oleh {$me['username']}", 'INFO');
    redirectWithMessage('stock', 'Sinkron selesai: ' . rtrim(rtrim(number_format($res['total_m'], 1, ',', '.'), '0'), ',')
        . ' m pemakaian tercatat. Stok sekarang ' . rtrim(rtrim(number_format($res['stok_akhir'] ?? stock_total_meters($pid), 1, ',', '.'), '0'), ',') . ' m.', 'success');
}

/* --- 8. PENGATURAN — OWNER SAJA --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_stock_settings'])) {
    verify_csrf();
    if (!$isOwnerRole) {
        writeLog("AKSES DITOLAK: {$me['username']} ($role) coba ubah pengaturan stok", 'WARNING');
        redirectWithMessage('stock', 'Pengaturan stok hanya untuk owner.', 'danger');
    }
    $mode = (string)post('cut_mode', 'smart');
    stock_set_setting('stock_cut_mode', in_array($mode, ['smart', 'linear'], true) ? $mode : 'smart');
    stock_set_setting('stock_margin_cm', (string)(float)str_replace(',', '.', (string)post('margin_cm', '0')));
    stock_set_setting('stock_alert_below_m', (string)(float)str_replace(',', '.', (string)post('alert_below_m', '0')));

    /* ===== NOTIFIKASI STOK (12 Okt 2026) =====
     * `stock_alert_lebar_m` = ambang menipis PER LEBAR (meter) — dipakai
     * cron/stock_alert.php. `stock_alert_wa` = SAKLAR pengiriman WhatsApp ke grup.
     * Saklar sengaja terpisah supaya pemeriksaan bisa jalan & tercatat di log
     * tanpa mengirim pesan apa pun (aturan: jangan kirim WA tanpa izin). */
    $leb = (float)str_replace(',', '.', (string)post('alert_lebar_m', '5'));
    if ($leb <= 0) $leb = 5.0;
    stock_set_setting('stock_alert_lebar_m', (string)$leb);
    stock_set_setting('stock_alert_wa', post('alert_wa') !== null ? '1' : '0');
    /* Ambang verifikasi (m²): selisih di bawah ini dianggap wajar dan TIDAK
     * dilaporkan. Hitungan kita sengaja +0,1 m lebih panjang (margin 5 cm/sisi)
     * sedangkan mesin hanya +0,01 m — jadi setiap job memang selalu beda kecil.
     * Kalau ambangnya kekecilan, SEMUA job jadi "menyimpang" dan laporan jadi
     * berisik sampai tidak ada yang membacanya lagi. */
    if (post('verify_ambang_m2') !== null && $bolehSinkron) {
        $am = (float)str_replace(',', '.', (string)post('verify_ambang_m2', ''));
        stock_set_setting('stock_verify_ambang_m2', (string)max(0.05, $am));
    }
    redirectWithMessage('stock', 'Pengaturan stok disimpan.', 'success');
}

/* --- 3b. KOREKSI OTOMATIS DARI UKURAN CETAK MESIN (1b) ---
 *
 * MASALAH YANG DIPERBAIKI: order yang dicetak GABUNG dalam satu file dibebani
 * potongan masing-masing, sehingga bahan terpotong LEBIH banyak daripada yang
 * benar-benar keluar dari roll. Bukti nyata: job CG0810260501 + CG0810264012
 * → kotak mesin 2,06 × 1,21 (1,21 m jalan) tetapi catatan kita 1,1 + 1,1 = 2,2 m
 * → **0,99 m tidak pernah kembali ke stok**.
 *
 * Alur: pemilik klik "Periksa" (pratinjau, tidak mengubah apa pun) → periksa
 * daftarnya → baru centang & jalankan. Setiap job hanya dikoreksi SEKALI
 * (penanda `ref_type='rypc_job'`), karena satu job tercatat rata-rata 17,5
 * baris di printer_jobs — tanpa penanda itu koreksi bisa berulang dan merusak
 * stok berkali-kali lipat. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stock_koreksi_apply'])) {
    if (!$bolehSinkron) {
        writeLog("AKSES DITOLAK: {$_SESSION['username']} coba jalankan koreksi stok", 'WARNING');
        redirectWithMessage('stock', 'Koreksi stok hanya untuk owner.', 'danger');
    }
    if (post('koreksi_konfirmasi') === null) {
        redirectWithMessage('stock?koreksi=1', 'Centang pernyataan konfirmasi dulu sebelum menjalankan.', 'warning');
    }
    $kres = stock_correct_prints(stock_verify_mulai(), date('Y-m-d'), ['apply' => true]);
    $kr = $kres['ringkas'] ?? [];
    $nFix = (int)($kr['diperbaiki'] ?? 0);
    writeLog('KOREKSI STOK dijalankan oleh ' . ($_SESSION['username'] ?? '-') . ": $nFix job dikoreksi"
             . ', penyesuaian ' . ($kr['hemat_m'] ?? 0) . ' m', 'INFO');
    redirectWithMessage('stock',
        $nFix > 0
            ? "Koreksi selesai: $nFix job disesuaikan dengan ukuran cetak mesin."
            : 'Tidak ada job baru yang perlu dikoreksi (semuanya sudah pernah dikoreksi).',
        $nFix > 0 ? 'success' : 'info');
}

/* --- 3b. PERBAIKI HPP LAMA (koreksi lebar roll) ---
 * =====================================================================
 * Pemilik memilih opsi B: "perbaiki + hitung ulang bulan-bulan lalu".
 *
 * Latar: sampai 12 Okt 2026 HPP disimpan memakai harga per meter ROLL LEBAR
 * PENUH (Rp 20.800/m, yaitu roll 3,2 m). Pekerjaan dari strip 1,1 m jadi
 * dibebani ~2,9× terlalu mahal → laba bulan lalu dilaporkan terlalu kecil.
 * Hanya menyentuh `unit_cost`/`total_cost` pergerakan `potong` & `koreksi`;
 * JUMLAH METER DAN STOK TIDAK DISENTUH SAMA SEKALI.
 *
 * Pengaman: wajib centang konfirmasi + anti-ganda di fungsinya
 * (`stock_hpp_lebar_fix`) + backup JSON sebelum menulis.
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stock_hpp_fix'])) {
    if (!$bolehSinkron) {
        writeLog("AKSES DITOLAK: {$_SESSION['username']} coba koreksi HPP", 'WARNING');
        redirectWithMessage('stock', 'Koreksi HPP hanya untuk owner.', 'danger');
    }
    if (post('hpp_konfirmasi') === null) {
        redirectWithMessage('stock?hpp=1', 'Centang pernyataan konfirmasi dulu sebelum menjalankan.', 'warning');
    }
    $hres = function_exists('stock_hpp_recompute_width')
        ? stock_hpp_recompute_width(['apply' => true])
        : ['ok' => false, 'error' => 'Fungsi tidak tersedia'];
    if (empty($hres['ok'])) {
        redirectWithMessage('stock', 'Gagal memperbaiki HPP: ' . ($hres['error'] ?? '?'), 'danger');
    }
    $hs = $hres['ringkas'] ?? [];
    $nH = (int)($hs['n'] ?? 0);
    writeLog('HPP: koreksi lebar roll oleh ' . ($_SESSION['username'] ?? '-')
             . " — $nH baris, selisih Rp " . ($hs['beda'] ?? 0), 'INFO');
    redirectWithMessage('stock',
        $nH > 0
            ? "HPP diperbaiki: $nH baris. Selisih Rp "
              . number_format(abs((float)($hs['beda'] ?? 0)), 0, ',', '.')
              . ((float)($hs['beda'] ?? 0) > 0 ? ' (HPP turun → laba naik)' : ' (HPP naik → laba turun)') . '.'
            : 'Tidak ada baris yang perlu diperbaiki.',
        $nH > 0 ? 'success' : 'info');
}

/* --- 4. PINJAM BAHAN (kaki x-banner dll) ---
 * =====================================================================
 * "kadang ada yang pinjam kaki banner dan biasanya di ganti lagi"
 *
 * Bahan yang dipinjam BERKURANG dari stok (barang memang keluar gudang),
 * lalu BERTAMBAH lagi saat dikembalikan. Dicatat dengan jenis tersendiri
 * ('pinjam'/'kembali') supaya TIDAK ikut terhitung sebagai HPP — pinjaman
 * bukan pemakaian produksi, jadi laba tidak boleh terpengaruh.
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_loan'])) {
    verify_csrf();
    $pid = (int)post('loan_product', 0);
    $cid = (int)post('loan_customer', 0);
    $qty = (float)str_replace(',', '.', (string)post('loan_qty', '0'));

    if ($pid <= 0 || $cid <= 0 || $qty <= 0) {
        redirectWithMessage('stock', 'Bahan, pelanggan, dan jumlah pinjam wajib diisi.', 'danger');
    }

    $res = stock_loan_create($pid, $cid, $qty, [
        'tgl_pinjam' => (string)post('loan_date', date('Y-m-d')),
        'tgl_target' => (string)post('loan_due', ''),
        'note'       => trim((string)post('loan_note', '')),
        'created_by' => (int)($me['id'] ?? 0),
    ]);

    if (empty($res['ok'])) {
        redirectWithMessage('stock', 'Gagal mencatat pinjaman: ' . ($res['error'] ?? '?'), 'danger');
    }

    $pu = stock_product($pid);
    $nm = ($pu['category'] ?? '?') . ' ' . ($pu['variant'] ?? '');
    writeLog("Stok: PINJAM " . rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',') .
             " dari $nm oleh {$me['username']} (pinjaman #{$res['loan_id']})", 'INFO');
    redirectWithMessage('stock', 'Pinjaman dicatat — stok berkurang ' .
        rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',') . '. Jangan lupa ditagih kembali.', 'success');
}

/* --- 5. PENGEMBALIAN PINJAMAN (boleh sebagian) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_return'])) {
    verify_csrf();
    $lid = (int)post('loan_id', 0);
    $qty = (float)str_replace(',', '.', (string)post('return_qty', '0'));

    if ($lid <= 0 || $qty <= 0) {
        redirectWithMessage('stock', 'Jumlah yang dikembalikan wajib diisi.', 'danger');
    }

    $res = stock_loan_return($lid, $qty, ['kembali_by' => (int)($me['id'] ?? 0)]);
    if (empty($res['ok'])) {
        redirectWithMessage('stock', 'Gagal mencatat pengembalian: ' . ($res['error'] ?? '?'), 'danger');
    }

    writeLog("Stok: KEMBALI " . rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',') .
             " untuk pinjaman #$lid oleh {$me['username']} (status: {$res['status']})", 'INFO');

    if ($res['status'] === 'kembali') {
        redirectWithMessage('stock', 'Pinjaman #' . $lid . ' LUNAS — stok sudah kembali penuh.', 'success');
    }
    redirectWithMessage('stock', 'Dikembalikan sebagian. Sisa yang masih di pelanggan: ' .
        rtrim(rtrim(number_format((float)$res['sisa'], 2, ',', '.'), '0'), ',') . '.', 'warning');
}

/* --- 6. TANDAI HILANG (dipinjam tapi tidak kembali) ---
 * Kalau barang benar-benar tidak kembali, stok TIDAK boleh dibalikkan —
 * barangnya memang sudah tidak ada. Barisnya ditandai 'hilang' supaya
 * tidak terus muncul sebagai tagihan yang tak akan pernah selesai. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_hilang'])) {
    verify_csrf();
    if (!$isOwnerRole) {
        writeLog("AKSES DITOLAK: {$me['username']} ($role) coba tandai pinjaman hilang", 'WARNING');
        redirectWithMessage('stock', 'Menandai hilang hanya untuk owner.', 'danger');
    }
    $lid = (int)post('loan_id', 0);
    if ($lid > 0) {
        $l = db()->query("SELECT * FROM stock_loans WHERE id=" . $lid)->fetch();
        $sisa = $l ? stock_loan_sisa($l) : 0;
        db()->prepare("UPDATE stock_loans SET status='hilang', note = CONCAT(note, ' | hilang: ', ?) WHERE id = ?")
            ->execute([date('Y-m-d'), $lid]);
        writeLog("Stok: pinjaman #$lid ditandai HILANG (sisa $sisa) oleh {$me['username']}", 'WARNING');
        redirectWithMessage('stock', 'Pinjaman #' . $lid . ' ditandai hilang. Stok tidak dibalikkan karena barangnya memang tidak kembali.', 'warning');
    }
    redirectWithMessage('stock', 'Pinjaman tidak ditemukan.', 'danger');
}

/* =====================================================================
 * DATA TAMPILAN
 * ===================================================================== */
$products   = db()->query("SELECT * FROM product_prices WHERE track_stock = 1 OR unit = 'roll' ORDER BY category, variant")->fetchAll();
$allProducts = db()->query("SELECT id, category, variant, unit, roll_width_m, roll_length_m FROM product_prices ORDER BY category, variant")->fetchAll();

$filterPid = (int)get('product', 0);
$bulan     = (string)get('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) $bulan = date('Y-m');
$startDate = $bulan . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));

// Ringkasan periode
$usage = stock_usage_totals($startDate, $endDate);

// Ringkasan produk (mendukung roll/meter DAN satuan umum: jergen/botol/pcs/lembar)
$summary = [];
foreach ($products as $p) {
    $pid = (int)$p['id'];
    $v   = stock_unit_value($pid);
    $b   = stock_balance_unit($pid);

    // Untuk bahan roll, tetap butuh detail meter (dipakai bar progres & opname)
    $rollsTotal = 0; $rollsUtuh = 0; $len = (float)($p['roll_length_m'] ?? 0);
    $rollW = (float)($p['roll_width_m'] ?? 0);
    if ($v['is_roll']) {
        $stR = db()->prepare("SELECT COUNT(*) AS n FROM stock_rolls WHERE product_id=? AND status='aktif'");
        $stR->execute([$pid]); $rollsTotal = (int)$stR->fetchColumn();
        $stU = db()->prepare("SELECT COUNT(*) FROM stock_rolls WHERE product_id=? AND status='aktif' AND used_m<=0.001");
        $stU->execute([$pid]); $rollsUtuh = (int)$stU->fetchColumn();
    }

    $summary[] = [
        'product_id'     => $pid,
        'product'        => $p,
        'name'           => stock_nama_tampil($p),
        'is_roll'        => $v['is_roll'],
        'unit'           => $v['unit'],
        'qty'            => $v['qty'],
        'unit_cost'      => $v['unit_cost'],
        'cost_meter'     => $v['cost_meter'] ?? 0,
        'nilai_modal'    => $v['value'],
        'rolls_total'    => $rollsTotal,
        'rolls_utuh'     => $rollsUtuh,
        'roll_width_m'   => $rollW,
        'roll_length_m'  => $len,
        'sisa_meter'     => (float)($b['meters'] ?? 0),
        /* Luas (m²) — angka yang SAH untuk dibandingkan antar lebar roll.
           Meter tidak bisa: 1 roll 3,2 m yang digunting jadi 2,1 + 1,1 m
           berubah 70 m → 140 m padahal bahannya tetap 224 m². */
        'area_m2'        => (float)($v['area_m2'] ?? 0),
    ];
}
$totalNilai = 0.0; $totalMeter = 0.0; $totalRolls = 0; $totalUnitLain = 0;
foreach ($summary as $s) {
    $totalNilai += (float)$s['nilai_modal'];
    if ($s['is_roll']) { $totalMeter += (float)$s['sisa_meter']; $totalRolls += (int)$s['rolls_total']; }
    elseif ((float)$s['qty'] > 0) $totalUnitLain++;
}

/* =====================================================================
 * BAHAN AKTIF vs BELUM BERGERAK — 2026-10-09
 * ---------------------------------------------------------------------
 * KELUHAN NYATA pemilik usaha: "bukannya harusnya menyesuaikan nota ya
 * contoh tinta ko jadi banyak bgt card"
 *
 * Sebabnya: setiap bahan yang didaftarkan (mis. 4 varian tinta spanduk
 * ukuran 1 L, 3 ukuran eyelet, 3 jenis powder DTF) LANGSUNG muncul sebagai
 * kartu di halaman Stok, walaupun stoknya masih 0.
 *
 * ATURAN: bahan dianggap BELUM BERGERAK bila KEDUA syarat ini terpenuhi:
 *   1. jumlahnya 0
 *   2. belum pernah ada satu pun pergerakan di kartu stok
 *
 * Riwayat pembelian SENDIRI tidak cukup untuk menampilkan kartu: nota bulan
 * lalu yang diarsipkan saja (tanpa menambah stok) tidak membuat bahan punya
 * isi, jadi kartunya cuma menambah keramaian tanpa informasi.
 *
 * Begitu bahan menerima stok ATAU pernah dipakai, kartunya otomatis naik ke
 * tampilan utama — jadi tidak ada bahan aktif yang hilang dari pengawasan.
 *
 * Bahan yang belum bergerak TIDAK dibuang — diringkas ke satu baris yang bisa
 * dibuka, supaya tetap bisa menerima stok tanpa mengotori tampilan utama.
 * ===================================================================== */
$summaryAktif  = [];
$summaryKosong = [];
foreach ($summary as $s) {
    $pidS = (int)$s['product']['id'];

    $adaIsi = $s['is_roll']
        ? ((float)$s['sisa_meter'] > 0.001 || (int)$s['rolls_total'] > 0)
        : ((float)$s['qty'] > 0.001);

    if ($adaIsi) { $summaryAktif[] = $s; continue; }

    $adaGerak = (int)db()->query(
        "SELECT COUNT(*) FROM stock_movements WHERE product_id = " . $pidS
    )->fetchColumn();

    if ($adaGerak > 0) $summaryAktif[] = $s;
    else               $summaryKosong[] = $s;
}

/* =====================================================================
 * PEMERIKSA KESEHATAN PEMOTONGAN — 2026-10-09
 * ---------------------------------------------------------------------
 * Pemilik: "jujur kalau di sistem aja ga kebaca sudah susah, ini ada cara
 * ga antisipasi biar kedepan ga terjadi gini lagi"
 *
 * Panel ini memeriksa order 14 hari terakhir dan MENUNJUKKAN order mana
 * yang seharusnya memotong bahan tetapi TIDAK ADA jejaknya. Jadi masalah
 * ketahuan dalam hitungan jam, bukan setelah hitung fisik berbulan-bulan.
 *
 * Hanya yang GAWAT (bahan tersedia tapi tidak dipotong) yang ditonjolkan —
 * kalau stok memang kosong, itu bukan bug, hanya perlu terima stok dulu.
 * ===================================================================== */
$auditPemotongan = function_exists('stock_audit_ringkas') ? stock_audit_ringkas(14) : ['n'=>0,'gawat'=>0,'wajar'=>0,'sebab'=>[],'daftar'=>[]];

/* =====================================================================
 * VERIFIKASI vs MESIN CETAK — Tahap 2 (2026-10-12)
 * ---------------------------------------------------------------------
 * Pemilik: "kadang ada cetakan rusak dan operator ga ngasih tau ke owner
 * alhasil bahanya jadi miss/rugi"
 *
 * Audit di atas hanya memeriksa "SUDAH dipotong atau belum". Panel ini
 * memeriksa hal yang berbeda dan lebih dalam: apakah jumlah bahan yang kita
 * potong **SAMA dengan yang benar-benar keluar dari mesin**. Pembandingnya
 * ukuran cetak dari RYPC — bukan laporan operator, jadi tidak bisa
 * "diam-diam" dilewatkan.
 *
 * Satuan pembanding = m² (bukan meter), karena 1 meter dari roll 1,1 m dan
 * 1 meter dari roll 3,2 m adalah dua jumlah bahan yang sangat berbeda.
 * ===================================================================== */
$verif = function_exists('stock_verify_prints')
    ? stock_verify_prints(function_exists('stock_verify_mulai') ? stock_verify_mulai() : date('Y-m-d', strtotime('-7 days')), date('Y-m-d'))
    : ['ok' => true, 'ringkas' => [], 'baris' => [], 'error' => ''];
$verifR = $verif['ringkas'] ?? [];
$verifTemuan = [];
foreach (($verif['baris'] ?? []) as $bv) {
    if (($bv['jenis'] ?? '') === 'beda' || ($bv['jenis'] ?? '') === 'tanpa_catatan' || !empty($bv['terputus'])) {
        $verifTemuan[] = $bv;
    }
}

/* Rencana koreksi otomatis (1b) — HANYA dihitung saat diminta lewat
 * `?koreksi=1`, supaya halaman stok tidak menanggung beban ini tiap dibuka. */
$koreksiPreview = null;
if ($bolehSinkron && isset($_GET['koreksi']) && function_exists('stock_correct_prints')) {
    $koreksiPreview = stock_correct_prints(stock_verify_mulai(), date('Y-m-d'), ['apply' => false]);
}

/* Pratinjau perbaikan HPP (lebar roll) — HANYA saat diminta lewat `?hpp=1`,
 * supaya halaman stok tidak menanggung query ini tiap dibuka. */
$hppPreview = null;
if ($bolehSinkron && isset($_GET['hpp']) && function_exists('stock_hpp_recompute_width')) {
    $hppPreview = stock_hpp_recompute_width(['apply' => false]);
}

/* =====================================================================
 * RINCIAN TEMUAN VERIFIKASI — untuk modal "lihat yang selisih"
 * =====================================================================
 * Pemilik: "aku binggung 8 selisih ini dimana? bisa ga kita buat modal gt
 * biar kliatan".
 *
 * Yang ditampilkan BUKAN hanya angka, tapi juga UKURAN ITEM-nya — supaya
 * terlihat sendiri sebabnya, mis. item 2×1 m dicatat 1,10 m padahal mesin
 * 2,01 m. Tanpa ukuran item, angkanya mustahil dinilai.
 *
 * `$skorSebab` dihitung dari data (bukan ditebak): kalau meter yang dicatat
 * persis sama dengan sisi TERPENDEK ukuran, itu jejak rumus lama
 * "sisi terpendek + margin" — itulah yang dulu bikin catatan kurang. */
$verifBeda = [];
foreach (($verif['baris'] ?? []) as $bv) {
    $j = (string)($bv['jenis'] ?? '');
    if ($j === 'beda' || $j === 'tanpa_catatan') $verifBeda[] = $bv;
}

/* Ukuran item tiap order yang muncul di temuan (satu query untuk semua). */
$verifItems = [];
$semuaOrder = [];
foreach ($verifBeda as $bv) foreach ((array)($bv['orders'] ?? []) as $o) $semuaOrder[] = (string)$o;
$semuaOrder = array_values(array_unique(array_filter($semuaOrder)));
if ($semuaOrder) {
    try {
        $in = implode(',', array_fill(0, count($semuaOrder), '?'));
        $st = db()->prepare(
            "SELECT o.order_number, oi.product_name, oi.width_m, oi.height_m, oi.qty,
                    COALESCE(SUM(ABS(m.meters)), 0) AS meter
               FROM orders o
               JOIN order_items oi ON oi.order_id = o.id
               LEFT JOIN stock_movements m ON m.ref_type = 'order' AND m.ref_id = oi.id
                                          AND m.type = 'potong'
              WHERE o.order_number IN ($in)
              GROUP BY oi.id, o.order_number, oi.product_name, oi.width_m, oi.height_m, oi.qty
              ORDER BY oi.id");
        $st->execute($semuaOrder);
        foreach ($st as $x) $verifItems[(string)$x['order_number']][] = $x;
    } catch (Throwable $e) { /* biarkan kosong — modal tetap jalan */ }
}

/* Tulis satu baris ukuran item jadi teks ringkas: "2×1 m (1x)". */
$verifUkuran = function (string $orderNo) use ($verifItems): string {
    $out = [];
    foreach (($verifItems[$orderNo] ?? []) as $x) {
        $w = (float)($x['width_m'] ?? 0); $h = (float)($x['height_m'] ?? 0);
        $nm = trim((string)($x['product_name'] ?? ''));
        $uk = ($w > 0 && $h > 0)
            ? rtrim(rtrim(number_format($w, 2, ',', '.'), '0'), ',') . '×'
              . rtrim(rtrim(number_format($h, 2, ',', '.'), '0'), ',') . ' m'
            : 'tanpa ukuran';
        $q  = (float)($x['qty'] ?? 0);
        $out[] = $uk . ($q > 0 ? ' (' . rtrim(rtrim(number_format($q, 0, ',', '.'), '0'), ',') . 'x)' : '')
               . ($nm !== '' ? ' — ' . mb_substr($nm, 0, 30) : '');
    }
    return $out ? implode('<br>', array_map('e', $out)) : '<span class="text-faint">—</span>';
};

/* Lebar roll yang benar-benar tercatat, satu per satu. Jangan tampilkan
 * roll_catat (area ÷ meter) sebagai roll fisik: contoh order gabungan
 * CG0910263381 menghasilkan rerata 2,49 m dari catatan roll 2,1 + 3,2 m.
 * Angka rerata itu bukan meter pemakaian dan bukan roll yang sungguh ada. */
$verifLebarRoll = function (array $b): string {
    $raw = trim((string)($b['roll_catat_lebar'] ?? ''));
    if ($raw === '') return '<span class="text-faint">tidak diketahui</span>';
    $lebar = array_values(array_unique(array_filter(array_map('floatval', explode(',', $raw)), fn($w) => $w > 0)));
    if (!$lebar) return '<span class="text-faint">tidak diketahui</span>';
    return implode(' + ', array_map(function ($w) {
        return rtrim(rtrim(number_format($w, 2, ',', '.'), '0'), ',');
    }, $lebar)) . ' m';
};

/* Ambang pembanding meter — dibaca dari hasil verifikasi supaya TIDAK
   ditulis ulang (dulu pernah beda 0,5 vs 1 dan bikin salah tafsir). */
$vbAmbM = (float)($verif['ringkas']['ambang_m'] ?? 0.5);

/* Sebab yang DIHITUNG dari data — bukan ditebak. */
$verifSebab = function (array $b) use ($vbAmbM): array {
    $feed  = (float)($b['feed_m'] ?? 0);
    $catat = (float)($b['catat_m'] ?? 0);
    $beda  = round($catat - $feed, 3);
    if (($b['jenis'] ?? '') === 'tanpa_catatan') {
        return ['<b class="text-warning">belum tercatat</b> — mesin mencetak, catatan potong tidak ada'];
    }
    $out = [];
    if ((int)($b['n_tanpa'] ?? 0) > 0) {
        $out[] = (int)$b['n_tanpa'] . ' dari ' . (int)($b['jejak'] ?? 0)
               . ' catatan potong tidak punya ID roll; koreksi otomatis melewatinya agar roll asal tidak ditebak';
    }
    if ($beda > $vbAmbM) {
        $out[] = 'catatan stok <b>lebih ' . number_format($beda, 2, ',', '.') . ' m daripada feed mesin</b>';
        $out[] = 'kemungkinan: dicatat per-satuan padahal dicetak <b>digabung</b> (lebih rapi &amp; hemat)';
    } elseif ($beda < -$vbAmbM) {
        $out[] = 'catatan stok <b>kurang ' . number_format(abs($beda), 2, ',', '.') . ' m daripada feed mesin</b>';
        $out[] = 'kemungkinan: meter dihitung dari <b>sisi terpendek</b> ukuran (rumus lama), '
               . 'padahal bahan yang lewat mesin sepanjang sisi lainnya';
    }
    if ((int)($b['terputus'] ?? 0) === 1) {
        $out[] = 'riwayat RYPC pernah memuat status <b>gagal</b>, tetapi berkas <code>.rtk</code> menandai job selesai';
    }
    return $out ?: ['perlu diperiksa'];
};

// Buku besar
$where = "DATE(sm.created_at) BETWEEN ? AND ?";
$params = [$startDate, $endDate];
if ($filterPid > 0) { $where .= " AND sm.product_id = ?"; $params[] = $filterPid; }

$stM = db()->prepare(
    "SELECT sm.*, pp.category, pp.variant, u.username AS by_name
       FROM stock_movements sm
       LEFT JOIN product_prices pp ON pp.id = sm.product_id
       LEFT JOIN users u ON u.id = sm.created_by
      WHERE $where
      ORDER BY sm.id DESC LIMIT 300"
);
$stM->execute($params);
$movements = $stM->fetchAll();

$alertBelow = (float)getSetting('stock_alert_below_m', '0');

/* ===== PINJAMAN BAHAN (kaki x-banner dll) =====
 * YANG AKTIF ditampilkan di atas (perlu ditagih), yang sudah kembali
 * disimpan di balik tombol supaya tidak menutupi pekerjaan yang belum
 * selesai — prinsip yang sama dengan panel audit pemotongan. */
$loanProduk   = function_exists('stock_loan_produk') ? stock_loan_produk() : [];
$loanRingkas  = function_exists('stock_loan_ringkas') ? stock_loan_ringkas() : ['n_aktif'=>0,'sisa_unit'=>0,'n_telat'=>0];
$loanAktif    = function_exists('stock_loan_daftar') ? stock_loan_daftar('aktif', 100) : [];
$loanSelesai  = function_exists('stock_loan_daftar') ? stock_loan_daftar('kembali', 30) : [];
$loanPelanggan = db()->query("SELECT id, name, phone FROM customers ORDER BY name LIMIT 500")->fetchAll();

$pageTitle  = 'Kartu Stok Bahan';
$activeMenu = 'stock';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-toolbar">
    <div class="page-toolbar-left">
        <a href="finance" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Keuangan</a>
    </div>
    <div class="page-toolbar-right">
        <?php if ($bolehSinkron): ?>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('stock-sync')"><i class="fas fa-rotate"></i> Sinkron Pemakaian</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('stock-settings')"><i class="fas fa-gear"></i> Pengaturan</button>
        <?php endif; ?>
        <button type="button" class="btn btn-warning btn-sm" onclick="openStockDamage()"><i class="fas fa-triangle-exclamation"></i> Lapor Rusak</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('stock-loan')"><i class="fas fa-hand-holding"></i> Pinjamkan</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="openStockReceive()"><i class="fas fa-plus"></i> Terima Stok</button>
    </div>
</div>

<!-- ===== RINGKASAN ===== -->
<div class="grid grid-4 mb-4">
    <?php if ($bolehModal): ?>
    <div class="stat-card">
        <div class="stat-card-icon gold"><i class="fas fa-coins"></i></div>
        <div class="stat-card-label">Nilai Stok (Modal)</div>
        <div class="stat-card-value"><?= e(formatRupiah($totalNilai)) ?></div>
        <div class="stat-card-sub">Aset bahan saat ini</div>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-card-icon blue"><i class="fas fa-ruler-horizontal"></i></div>
        <div class="stat-card-label">Sisa Bahan</div>
        <div class="stat-card-value"><?= rtrim(rtrim(number_format($totalMeter, 1, ',', '.'), '0'), ',') ?> m</div>
        <?php /* JANGAN tampilkan jumlah roll gabungan di ringkasan (2026-10-09).
               Angka "14 roll" = 12 roll spanduk + 2 roll film PET — dua bahan
               BERBEDA yang tidak boleh dijumlahkan. Pemilik membacanya sbg
               "stok spanduk jadi 14 roll" padahal bukan, lalu wajar bingung.
               Jumlah roll tiap bahan sudah tampil di kartunya masing-masing,
               jadi ringkasan cukup menyebut BERAPA JENIS bahan yang berstok. */ ?>
        <div class="stat-card-sub"><?= count($summaryAktif) ?> jenis bahan berstok</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon green"><i class="fas fa-scissors"></i></div>
        <div class="stat-card-label">Terpakai <?= e(date('M Y', strtotime($startDate))) ?></div>
        <div class="stat-card-value"><?= rtrim(rtrim(number_format($usage['meter_potong'], 1, ',', '.'), '0'), ',') ?> m</div>
        <div class="stat-card-sub"><?= $bolehModal ? 'Modal: ' . e(formatRupiah($usage['biaya_potong'])) : 'Periode ini' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon red"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="stat-card-label">Rusak <?= e(date('M Y', strtotime($startDate))) ?></div>
        <div class="stat-card-value"><?= rtrim(rtrim(number_format($usage['meter_rusak'], 1, ',', '.'), '0'), ',') ?> m</div>
        <div class="stat-card-sub"><?= $bolehModal ? 'Rugi: ' . e(formatRupiah($usage['biaya_rusak'])) : 'Periode ini' ?></div>
    </div>
</div>

<!-- ===== PERINGATAN: PEMOTONGAN BAHAN BERNASALAH ===== -->
<?php if (($auditPemotongan['gawat'] ?? 0) > 0): ?>
<div class="card mb-4" style="border-left:4px solid var(--danger,#e5484d);">
    <div class="card-header d-flex align-center justify-between flex-wrap gap-2">
        <h3 class="card-title mb-0">
            <i class="fas fa-triangle-exclamation" style="color:var(--danger,#e5484d)"></i>
            Pemotongan Bahan Bermasalah
            <span class="badge badge-danger" style="font-weight:800"><?= (int)$auditPemotongan['gawat'] ?> order</span>
        </h3>
        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('panelAudit').toggleAttribute('hidden')">
            <i class="fas fa-eye"></i> Tampilkan / Sembunyikan
        </button>
    </div>
    <div class="card-body">
        <p class="text-sm mb-2">
            <b><?= (int)$auditPemotongan['gawat'] ?> order</b> dalam 14 hari terakhir seharusnya memotong bahan,
            tetapi <b>tidak ada jejak pemotongannya</b> — padahal stok bahannya tersedia.
            Artinya bahan itu <b>sudah terpakai tetapi stok sistem tidak berkurang</b>,
            sehingga stok akan terus tampak lebih banyak dari kenyataan.
        </p>
        <p class="text-faint text-xs mb-3">
            <i class="fas fa-circle-info"></i>
            Diperiksa otomatis tiap halaman ini dibuka. Kalau ada temuan, laporkan —
            penyebab tersering: <b>nama item ditulis berbeda</b> (mis. "Spanduk X Banner"
            padahal bahannya x-banner) sehingga sistem tidak mengenali bahannya.
        </p>

        <div id="panelAudit" hidden>
            <div class="hp-wrap">
                <table class="table" style="min-width:700px;">
                    <thead>
                        <tr>
                            <th style="width:92px;">Tanggal</th>
                            <th style="width:130px;">Order</th>
                            <th>Item</th>
                            <th class="num" style="width:70px;">Qty</th>
                            <th>Penyebab</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $nA = 0; foreach (($auditPemotongan['daftar'] ?? []) as $t): ?>
                        <?php if (strpos((string)$t['sebab'], '⚠') !== 0) continue; ?>
                        <?php if ($nA++ >= 60) break; ?>
                        <tr>
                            <td class="text-xs"><?= e(date('d/m', strtotime((string)$t['tgl']))) ?></td>
                            <td class="text-xs"><b><?= e((string)$t['order']) ?></b></td>
                            <td class="text-xs"><?= e((string)$t['item']) ?>
                                <span class="text-faint">· <?= e((string)$t['status']) ?></span>
                            </td>
                            <td class="num text-xs"><?= e(rtrim(rtrim(number_format((float)$t['qty'], 2, ',', '.'), '0'), ',')) ?></td>
                            <td class="text-xs text-faint"><?= e((string)$t['sebab']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ((int)$auditPemotongan['gawat'] > 60): ?>
                <p class="text-faint text-xs mt-2 mb-0">
                    Ditampilkan 60 teratas dari <?= (int)$auditPemotongan['gawat'] ?> temuan.
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== VERIFIKASI vs MESIN CETAK (Tahap 2) =====
     Menjawab keluhan pemilik: "kadang ada cetakan rusak dan operator ga
     ngasih tau ke owner alhasil bahanya jadi miss/rugi".
     Pembandingnya UKURAN CETAK MESIN (RYPC), bukan laporan operator — jadi
     tidak bisa dilewatkan tanpa ketahuan. -->
<?php if (!empty($verifR['job'])): ?>
<?php
/* Ringkasan lencana header. Sebelumnya dua badge panjang ditulis penuh
    ("8 selisih", "6 log gagal") sehingga di layar 380 px judul
   terpecah dan header mencapai 203 px. Sekarang angkanya disatukan dalam
   satu baris pendek, dan nama panjangnya dipindah ke tooltip + teks di
   badan panel (yang sudah responsif). */
$nBeda = (int)($verifR['beda'] ?? 0);
$nPut  = (int)($verifR['terputus'] ?? 0);
?>
<div class="card mb-4" style="border-left:4px solid <?= ($nBeda > 0 || $nPut > 0) ? 'var(--warning,#f5a623)' : 'var(--success,#2f9e6e)'; ?>;">
    <div class="card-header verif-head">
        <h3 class="card-title mb-0 verif-head-judul">
            <i class="fas fa-print"></i> <span>Bahan vs Mesin Cetak</span>
        </h3>
        <div class="verif-head-kanan">
            <?php if ($nBeda > 0): ?>
                <button type="button" class="badge badge-warning verif-lencana"
                        data-open-modal="verif-beda"
                        title="klik untuk melihat job mana saja yang selisih">
                    <?= $nBeda ?> selisih <i class="fas fa-chevron-right" style="font-size:.7em;"></i>
                </button>
            <?php endif; ?>
            <?php if ($nPut > 0): ?>
                <span class="badge badge-info" title="riwayat database pernah berstatus gagal; keenamnya memiliki penanda selesai .rtk"><?= $nPut ?> log lama*</span>
            <?php endif; ?>
            <button type="button" class="btn btn-ghost btn-sm verif-head-tombol"
                    onclick="document.getElementById('panelVerif').toggleAttribute('hidden')"
                    aria-label="Tampilkan atau sembunyikan rincian">
                <i class="fas fa-eye"></i> <span>Rincian</span>
            </button>
        </div>
    </div>
    <div class="card-body">
        <?php /* Angka selisih ditulis apa adanya. Sengaja TIDAK memakai kata
               "rugi": hitungan kita memang +0,1 m lebih panjang karena pinggir
               potong, jadi beda kecil itu WAJAR dan bukan kerugian. */ ?>
        <?php /* ===== PERBAIKAN 12 Okt 2026 =====
               Pemilik: "aneh bgt di sini udah selisih 4,5 meter loh masa ga bisa
               di cek lagi datanya apa yang buat dia bengkak?"
               Ia BENAR. Panel ini dulu membandingkan m² dengan DUA DEFINISI
               berbeda — kolom "mesin" memakai luas kanvas (w×h), kolom "catatan"
               memakai meter × lebar roll — sehingga muncul selisih 4,5 m² yang
               tidak nyata. Sekarang pembandingnya METER vs METER (satuan stok),
               dan lebar roll ditampilkan terbuka supaya bisa diperiksa sendiri. */
        $vf = function ($v, $d = 2) {
            $v = (float)$v;
            if (abs($v) < (0.5 / pow(10, $d))) return '0';
            return rtrim(rtrim(number_format($v, $d, ',', '.'), '0'), ',');
        };
        $selM  = (float)($verifR['selisih_m'] ?? 0);
        $ambM  = (float)($verifR['ambang_m'] ?? 0.5);
        $selOK = abs($selM) <= $ambM;
        ?>
        <div class="grid grid-4 mb-3">
            <div class="stat-card">
                <div class="stat-card-label">Dicetak Mesin</div>
                <div class="stat-card-value"><?= $vf($verifR['feed_m'] ?? 0) ?> m</div>
                <div class="stat-card-sub"><?= (int)($verifR['job'] ?? 0) ?> job · sejak <?= e(date('d/m/Y', strtotime((string)($verifR['dari'] ?? date('Y-m-d'))))) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Dicatat Terpotong</div>
                <div class="stat-card-value"><?= $vf($verifR['catat_m'] ?? 0) ?> m</div>
                <div class="stat-card-sub">jumlah potongan yang kita catat</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Selisih</div>
                <div class="stat-card-value" style="color:<?= $selOK ? 'var(--success,#2f9e6e)' : 'var(--warning,#f5a623)' ?>;">
                    <?= ($selM > 0 ? '+' : '') . $vf($selM) ?> m
                </div>
                <div class="stat-card-sub"><?= $selOK
                    ? 'cocok — di bawah ' . $vf($ambM, 1) . ' m'
                    : ($selM > 0 ? 'catatan stok lebih dari mesin' : 'catatan stok kurang dari mesin') ?></div>
            </div>
            <div class="stat-card">
                <?php
                /* Label diperjelas 12 Okt 2026. Dulu hanya "Job Cocok 17/26"
                   + sub "8 job selisih" → 17 + 8 = 25, bukan 26, sehingga
                   pemilik wajar bertanya "8 job selisih ??". Sisa 1 job itu
                   job yang BELUM tercatat potong sama sekali. Sekarang sisa
                   itu ditulis terus terang. */
                $nCocok = (int)($verifR['cocok'] ?? 0);
                $nBeda2 = (int)($verifR['beda'] ?? 0);
                $nKosong = (int)($verifR['tanpa_catatan'] ?? 0);
                ?>
                <div class="stat-card-label">Hasil Pemeriksaan</div>
                <div class="stat-card-value"><?= $nCocok ?>/<?= (int)($verifR['job'] ?? 0) ?> <span style="font-size:.7em;font-weight:400;">cocok</span></div>
                <div class="stat-card-sub verif-hasil-rincian">
                    <?php if ($nBeda2 > 0): ?>
                        <button type="button" class="verif-mini-link verif-mini-warning" data-open-modal="verif-beda">
                            <?= $nBeda2 ?> selisih
                        </button>
                    <?php endif; ?>
                    <?php if ($nKosong > 0): ?>
                        <button type="button" class="verif-mini-link" data-open-modal="verif-beda">
                            <?= $nKosong ?> belum tercatat
                        </button>
                    <?php endif; ?>
                    <?php if ($nBeda2 === 0 && $nKosong === 0): ?>semua cocok<?php endif; ?>
                    <?php if ($nBeda2 + $nKosong > 0): ?>
                        <button type="button" class="verif-mini-link verif-mini-rincian" data-open-modal="verif-beda">
                            Lihat <?= $nBeda2 + $nKosong ?> temuan <i class="fas fa-arrow-right"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php /* DIBERSIHKAN 12 Okt 2026 (permintaan pemilik: "anggaplah sekarang
               semua udah benar, tolong hapus kekurangan bahan terbuang dll").
               Yang DIHAPUS dari tampilan: kartu "Bahan Terbuang", kartu
               "Catatan Tanpa Roll", dan peringatan panjang soal roll terhapus.
               Alasannya: sebabnya sudah diperbaiki (opname mengarsipkan roll,
               tidak menghapus), bahan (meter) terbukti tidak hilang, dan opname
               12 Okt sudah menetapkan kenyataan. Angka-angka itu TETAP DIHITUNG
               di stock_verify_prints() (boros_m2 / n_hapus / n_tanpa) sehingga
               bisa ditampilkan lagi kapan pun tanpa mengubah mesinnya.
               Panel sekarang hanya berisi pembanding yang masih bermakna. */ ?>
        <p class="text-faint text-xs mb-3 verif-note">
            <i class="fas fa-circle-info"></i>
            Dibandingkan dengan <b>ukuran cetak mesin RYPC</b>, bukan laporan operator.
            <span class="verif-note-pisah">·</span>
            Selisih = <b>meter catatan stok − meter feed mesin</b> (plus berarti catatan lebih banyak)
            (bahan terpakai <?= $vf($verifR['bahan_m2'] ?? 0, 1) ?> m²).
            <span class="verif-note-pisah">·</span>
            Beda ≤ <b><?= $vf($ambM, 1) ?> m</b> dianggap wajar<?= !empty($verifR['era'])
                ? ' · berlaku sejak ' . e(date('d/m/Y', strtotime((string)$verifR['era']))) : '' ?>.
        </p>

        <?php /* ===== PERBAIKAN HPP (lebar roll) — pilihan B pemilik =====
               HPP lama memakai harga per meter roll LEBAR PENUH (Rp 20.800/m)
               untuk semua lebar, sehingga pekerjaan dari strip 1,1 m dibebani
               ~2,9× terlalu mahal → laba bulan lalu terlalu kecil.
               Tombol ini membuka pratinjau; tidak ada yang berubah sebelum
               dicentang & dijalankan. Meter & stok TIDAK disentuh. */ ?>
        <?php if ($bolehSinkron && function_exists('stock_hpp_recompute_width')): ?>
            <div class="mt-3" style="border-top:1px solid var(--border,#2a3040); padding-top:12px;">
                <?php
                $hFixAt = (string)(getSetting('stock_hpp_lebar_fix', '') ?? '');
                $hp = (array)($hppPreview['ringkas'] ?? []);
                ?>
                <?php if ($hFixAt === ''): ?>
                    <a href="stock?hpp=1" class="btn btn-secondary btn-sm">
                        <i class="fas fa-calculator"></i> Periksa &amp; perbaiki HPP
                    </a>
                    <span class="text-faint text-xs" style="margin-left:8px;">
                        HPP lama masih memakai harga roll lebar penuh untuk semua lebar.
                        Pratinjau dulu — tidak langsung mengubah apa pun.
                    </span>
                <?php else: ?>
                    <span class="text-xs" style="color:var(--success,#2f9e6e);">
                        <i class="fas fa-circle-check"></i> HPP sudah diperbaiki
                        (<?= e(date('d/m/Y H:i', strtotime($hFixAt))) ?>).
                    </span>
                <?php endif; ?>

                <?php if ($hppPreview && empty($hppPreview['ok'])): ?>
                    <div class="alert alert-danger p-2 text-xs mt-2 mb-0">
                        Gagal membaca data: <?= e((string)($hppPreview['error'] ?? '?')) ?>
                    </div>
                <?php elseif ($hppPreview): ?>
                    <div class="mt-2">
                        <?php if ((int)($hp['n'] ?? 0) === 0): ?>
                            <p class="text-sm mb-0"><i class="fas fa-circle-check" style="color:var(--success,#2f9e6e)"></i>
                                Tidak ada baris yang perlu diperbaiki.</p>
                        <?php else: ?>
                            <div class="alert alert-info p-2 text-xs mb-2">
                                <b>Rencana perbaikan HPP</b> — <?= (int)$hp['n'] ?> baris diperbaiki
                                (<?= (int)($hp['dilewati'] ?? 0) ?> dilewati karena roll-nya sudah dihapus).
                                <br>HPP lama <b>Rp <?= number_format(abs((float)($hp['lama'] ?? 0)), 0, ',', '.') ?></b>
                                → baru <b>Rp <?= number_format(abs((float)($hp['baru'] ?? 0)), 0, ',', '.') ?></b>
                                · selisih <b><?= ((float)($hp['beda'] ?? 0) < 0 ? '−' : '+') . number_format(abs((float)($hp['beda'] ?? 0)), 0, ',', '.') ?></b>
                                <?php if ((float)($hp['beda'] ?? 0) < 0): ?>
                                    → <b>laba naik</b> (sebelumnya HPP terlalu tinggi)
                                <?php endif; ?>
                                <br><span class="text-faint">Meter &amp; stok TIDAK diubah — hanya nilai HPP.</span>
                            </div>
                            <div class="hp-wrap mb-2">
                                <table class="table" style="min-width:620px;">
                                    <thead>
                                        <tr>
                                            <th style="width:110px;">Bulan</th>
                                            <th class="num" style="width:70px;">Baris</th>
                                            <th class="num">HPP Lama</th>
                                            <th class="num">HPP Baru</th>
                                            <th class="num">Selisih</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ((array)($hp['bulan'] ?? []) as $bl => $v): ?>
                                        <tr>
                                            <td class="text-xs"><?= e($bl) ?></td>
                                            <td class="num text-xs"><?= (int)($v['n'] ?? 0) ?></td>
                                            <td class="num text-xs">Rp <?= number_format(abs((float)($v['lama'] ?? 0)), 0, ',', '.') ?></td>
                                            <td class="num text-xs">Rp <?= number_format(abs((float)($v['baru'] ?? 0)), 0, ',', '.') ?></td>
                                            <td class="num text-xs" style="color:var(--success,#2f9e6e);">
                                                <?= ((float)($v['baru'] ?? 0) - (float)($v['lama'] ?? 0) < 0 ? '−' : '+')
                                                    . 'Rp ' . number_format(abs((float)($v['baru'] ?? 0) - (float)($v['lama'] ?? 0)), 0, ',', '.') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <form method="POST" action="stock"
                                  onsubmit="return confirm('Perbaiki HPP <?= (int)$hp['n'] ?> baris?\n\nHanya NILAI HPP yang diubah. Meter dan stok tidak disentuh.\nSetelah ini tidak bisa dijalankan lagi (dijaga penanda anti-ganda).');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="stock_hpp_fix" value="1">
                                <label class="text-xs d-block mb-2">
                                    <input type="checkbox" name="hpp_konfirmasi" value="1">
                                    Saya sudah memeriksa rencana di atas dan setuju HPP disesuaikan.
                                </label>
                                <button type="submit" class="btn btn-warning btn-sm">
                                    <i class="fas fa-wand-magic-sparkles"></i> Perbaiki HPP (<?= (int)$hp['n'] ?> baris)
                                </button>
                                <a href="stock" class="btn btn-ghost btn-sm">Batal</a>
                                <div class="text-faint text-xs mt-2">
                                    Salinan semua baris yang diubah disimpan ke
                                    <code>logs/hpp_lebar_backup_*.json</code> sebelum ditulis — bisa dikembalikan manual.
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php /* ===== KOREKSI OTOMATIS (1b) =====
               Order yang dicetak GABUNG dalam satu file dibebani potongan
               masing-masing → bahan terpotong lebih banyak daripada yang
               benar-benar keluar dari roll. Tombol di sini menyiapkan
               penyesuaiannya: batalkan catatan lama, lalu potong ulang memakai
               METER dari kotak mesin dan ROLL dengan lebar terdekat.
               Selalu pratinjau dulu — tidak ada yang berubah sebelum Anda
               centang & menekan tombol jalankan. */ ?>
        <div class="mt-3" style="border-top:1px solid var(--border,#2a3040); padding-top:12px;">
            <?php if (!$koreksiPreview): ?>
                <a href="stock?koreksi=1" class="btn btn-secondary btn-sm">
                    <i class="fas fa-calculator"></i> Periksa &amp; siapkan koreksi otomatis
                </a>
                <span class="text-faint text-xs" style="margin-left:8px;">
                    Menyiapkan penyesuaian untuk order gabungan. Tidak langsung mengubah apa pun.
                </span>
            <?php else:
                $kBaris = (array)($koreksiPreview['baris'] ?? []);
                $kPerlu = array_values(array_filter($kBaris, fn($x) => ($x['status'] ?? '') === 'perlu'));
                $kHemat = array_sum(array_map(fn($x) => (float)($x['hemat_m'] ?? 0), $kPerlu));
            ?>
                <div class="d-flex align-center justify-between flex-wrap gap-2 mb-2">
                    <div>
                        <b>Rencana koreksi</b>
                        <span class="text-faint text-xs">
                            — <?= count($kPerlu) ?> job perlu disesuaikan ·
                            penyesuaian total <b><?= ($kHemat > 0 ? '−' : '+') . e(rtrim(rtrim(number_format(abs($kHemat), 2, ',', '.'), '0'), ',')) ?> m</b>
                            <?= $kHemat > 0 ? '(dikembalikan ke stok)' : '(perlu dipotong lagi)' ?>
                        </span>
                    </div>
                    <a href="stock" class="btn btn-ghost btn-sm"><i class="fas fa-xmark"></i> Tutup</a>
                </div>

                <?php if (!$kPerlu): ?>
                    <p class="text-sm mb-0"><i class="fas fa-circle-check" style="color:var(--success,#2f9e6e)"></i>
                        Tidak ada job yang perlu disesuaikan.</p>
                <?php else: ?>
                    <div class="hp-wrap mb-3">
                        <table class="table" style="min-width:820px;">
                            <thead>
                                <tr>
                                    <th style="width:80px;">Tanggal</th>
                                    <th style="width:150px;">Order</th>
                                    <th style="width:96px;">Kotak Mesin</th>
                                    <th class="num" style="width:78px;">Catatan</th>
                                    <th class="num" style="width:78px;">Menurut Mesin</th>
                                    <th style="width:104px;">Roll</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($kPerlu as $kb): ?>
                                <tr>
                                    <td class="text-xs"><?= e(date('d/m H:i', strtotime((string)$kb['mulai']))) ?></td>
                                    <td class="text-xs">
                                        <?php foreach (array_slice((array)$kb['orders'], 0, 3) as $on): ?>
                                            <b><?= e((string)$on) ?></b><br>
                                        <?php endforeach; ?>
                                        <?php if (count((array)$kb['rinci']) > 1): ?>
                                            <span class="text-faint">gabungan <?= count((array)$kb['rinci']) ?> order</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-xs"><?= e((string)$kb['kotak']) ?> m
                                        <?php if ((int)$kb['lembar'] > 1): ?><br><span class="text-faint"><?= (int)$kb['lembar'] ?> lembar</span><?php endif; ?>
                                    </td>
                                    <td class="num text-xs"><?= e(rtrim(rtrim(number_format((float)$kb['lama_m'], 2, ',', '.'), '0'), ',')) ?> m</td>
                                    <td class="num text-xs"><b><?= e(rtrim(rtrim(number_format((float)$kb['target_m'], 2, ',', '.'), '0'), ',')) ?> m</b></td>
                                    <td class="text-xs"><?= e(rtrim(rtrim(number_format((float)$kb['roll_lama'], 2, ',', '.'), '0'), ',')) ?> → <b><?= e(rtrim(rtrim(number_format((float)$kb['roll_baru'], 2, ',', '.'), '0'), ',')) ?></b> m</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form method="POST" action="stock"
                          onsubmit="return confirm('Jalankan koreksi untuk <?= count($kPerlu) ?> job?\n\nStok akan disesuaikan menurut ukuran cetak mesin. Setiap job hanya dikoreksi sekali.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="stock_koreksi_apply" value="1">
                        <label class="text-xs d-block mb-2">
                            <input type="checkbox" name="koreksi_konfirmasi" value="1">
                            Saya sudah memeriksa daftar di atas dan setuju stok disesuaikan.
                        </label>
                        <button type="submit" class="btn btn-warning btn-sm">
                            <i class="fas fa-wand-magic-sparkles"></i> Jalankan koreksi (<?= count($kPerlu) ?> job)
                        </button>
                        <div class="text-faint text-xs mt-2">
                            Setiap job hanya dikoreksi <b>sekali</b> — dijaga penanda <code>rypc_job</code>,
                            jadi menekan tombol dua kali tidak akan menggandakan.
                            Riwayatnya tetap ada di Buku Besar (bisa ditelusuri).
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div id="panelVerif" hidden>
            <?php /* Selalu tampilkan rincian per job (bukan hanya yang bermasalah)
                   supaya angkanya bisa diperiksa sendiri baris per baris. */ ?>
            <?php if (empty($verif['baris'])): ?>
                <p class="text-sm mb-0"><i class="fas fa-circle-info"></i>
                    Belum ada job yang bisa dibandingkan pada periode ini.</p>
            <?php else: ?>
                <div class="hp-wrap">
                    <table class="table" style="min-width:840px;">
                        <thead>
                            <tr>
                                <th style="width:80px;">Tanggal</th>
                                <th style="width:124px;">Order</th>
                                <th style="width:98px;">Kotak Mesin</th>
                                <th class="num" style="width:70px;">Mesin (m)</th>
                                <th class="num" style="width:70px;">Catatan (m)</th>
                                <th class="num" style="width:68px;">Selisih</th>
                                <th style="width:130px;">Lebar roll</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        /* DIPERBAIKI 12 Okt 2026: sekarang menampilkan SEMUA job
                           (bukan hanya yang bermasalah) beserta rincian angkanya,
                           supaya pemilik bisa memeriksa sendiri — "masa ga bisa
                           di cek lagi datanya apa yang buat dia bengkak".
                           Urutannya: yang bermasalah dulu. */
                        $vfBaris = array_values((array)($verif['baris'] ?? []));
                        usort($vfBaris, function ($a, $b) {
                            $p = fn($x) => ($x['jenis'] ?? '') === 'beda' ? 0
                                         : (($x['jenis'] ?? '') === 'tanpa_catatan' ? 1 : 2);
                            $d = $p($a) <=> $p($b);
                            return $d !== 0 ? $d : strcmp((string)($b['mulai'] ?? ''), (string)($a['mulai'] ?? ''));
                        });
                        $nV = 0; foreach ($vfBaris as $bv): ?>
                            <?php if ($nV++ >= 80) break; ?>
                            <?php $selB = (float)($bv['selisih_m'] ?? 0); $bedaB = abs($selB) > $ambM; ?>
                            <tr>
                                <td class="text-xs"><?= e(date('d/m H:i', strtotime((string)$bv['mulai']))) ?></td>
                                <td class="text-xs">
                                    <?php foreach (array_slice((array)$bv['orders'], 0, 3) as $on): ?>
                                        <b><?= e((string)$on) ?></b><br>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-xs">
                                    <?= $vf($bv['lebar'] ?? 0) ?>×<?= $vf($bv['panjang'] ?? 0) ?> m
                                    <?php if ((int)($bv['lembar'] ?? 1) > 1): ?><br><span class="text-faint"><?= (int)$bv['lembar'] ?> lembar</span><?php endif; ?>
                                </td>
                                <td class="num text-xs"><?= $vf($bv['feed_m'] ?? 0) ?></td>
                                <td class="num text-xs"><?= $vf($bv['catat_m'] ?? 0) ?></td>
                                <td class="num text-xs" style="color:<?= $bedaB ? 'var(--warning,#f5a623)' : 'inherit' ?>;">
                                    <?= ($selB > 0 ? '+' : '') . $vf($selB) ?>
                                </td>
                                <td class="text-xs">
                                    <?php if ((float)($bv['roll_fisik'] ?? 0) > 0): ?>
                                        <span>perlu <?= $vf($bv['roll_fisik'], 2) ?> m</span><br>
                                    <?php else: ?>
                                        <span class="text-faint">lebar perlu: tidak diketahui</span><br>
                                    <?php endif; ?>
                                    <span class="text-faint">tercatat: <?= $verifLebarRoll($bv) ?></span>
                                </td>
                                <td class="text-xs">
                                    <?php if (!empty($bv['terputus'])): ?>
                                        <span class="badge badge-info" title="status lama; job memiliki penanda selesai .rtk">log lama*</span>
                                    <?php endif; ?>
                                    <?php if ($bedaB): ?>
                                        <span style="color:var(--warning,#f5a623);">
                                            <?= $selB > 0
                                                ? 'catatan stok +' . $vf(abs($selB)) . ' m dari mesin'
                                                : 'catatan stok −' . $vf(abs($selB)) . ' m dari mesin' ?>
                                        </span>
                                    <?php elseif (($bv['jenis'] ?? '') === 'tanpa_catatan'): ?>
                                        <span class="text-faint">bahan tidak terpotong sama sekali</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($vfBaris) > 80): ?>
                    <p class="text-faint text-xs mt-2 mb-0">Ditampilkan 80 teratas dari <?= count($vfBaris) ?> job.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== PINJAMAN BAHAN (kaki x-banner dll) =====
     Pemilik: "kadang ada yang pinjam kaki banner dan biasanya di ganti lagi"
     Kaki banner itu ASET — kalau tidak dicatat, hilang begitu saja tanpa ada
     yang tahu siapa masih pegang. Panel ini menutup celah itu: siapa meminjam,
     berapa, sejak kapan, dan berapa yang masih beredar di pelanggan. -->
<div class="card mb-4" style="border-left:4px solid <?= $loanRingkas['n_aktif'] > 0 ? 'var(--warning,#f5a623)' : 'var(--border,#2a3040)'; ?>;">
    <div class="card-header d-flex justify-between align-center">
        <h3 class="card-title mb-0">
            <i class="fas fa-hand-holding"></i> Pinjaman Bahan
            <?php if ($loanRingkas['n_aktif'] > 0): ?>
                <span class="badge badge-warning"><?= (int)$loanRingkas['n_aktif'] ?> belum kembali</span>
            <?php endif; ?>
            <?php if ($loanRingkas['n_telat'] > 0): ?>
                <span class="badge badge-danger"><?= (int)$loanRingkas['n_telat'] ?> lewat tenggat</span>
            <?php endif; ?>
        </h3>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('stock-loan')">
            <i class="fas fa-plus"></i> Catat Pinjaman
        </button>
    </div>
    <div class="card-body">
        <?php if ($loanRingkas['n_aktif'] > 0): ?>
            <div class="alert alert-warning mb-3 py-2 text-sm">
                <i class="fas fa-circle-info"></i>
                <div>
                    <b><?= rtrim(rtrim(number_format((float)$loanRingkas['sisa_unit'], 2, ',', '.'), '0'), ',') ?> unit</b>
                    masih di pelanggan. Stok sudah dikurangi sejak dipinjam &amp;
                    <b>tidak dihitung sebagai HPP</b> karena barangnya akan kembali.
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$loanAktif): ?>
            <p class="text-faint text-sm mb-0">
                <i class="fas fa-circle-check text-success"></i>
                Tidak ada pinjaman yang belum kembali. Kaki banner semuanya di gudang.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Pelanggan</th>
                            <th>Bahan</th>
                            <th class="text-right">Dipinjam</th>
                            <th class="text-right">Kembali</th>
                            <th class="text-right">Sisa</th>
                            <th>Sejak</th>
                            <th>Tenggat</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loanAktif as $l):
                            $fmtL   = fn($n) => rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
                            $sisa   = stock_loan_sisa($l);
                            $telat  = !empty($l['tgl_target']) && $l['tgl_target'] < date('Y-m-d');
                            $hariLama = (int)floor((strtotime(date('Y-m-d')) - strtotime((string)$l['tgl_pinjam'])) / 86400);
                        ?>
                        <tr>
                            <td>
                                <div class="fw-600"><?= e((string)$l['customer_name']) ?></div>
                                <div class="text-faint text-xs"><?= e((string)$l['customer_phone']) ?></div>
                            </td>
                            <td class="text-xs"><?= e((string)$l['product_name']) ?></td>
                            <td class="text-right"><?= $fmtL($l['unit'] === 'meter' || $l['unit'] === 'roll' ? $l['meters'] : $l['qty']) ?> <?= e((string)$l['unit']) ?></td>
                            <td class="text-right text-success">
                                <?= $fmtL($l['unit'] === 'meter' || $l['unit'] === 'roll' ? $l['meters_kembali'] : $l['qty_kembali']) ?>
                            </td>
                            <td class="text-right fw-700 <?= $sisa > 0 ? 'text-warning' : 'text-success' ?>">
                                <?= $fmtL($sisa) ?> <?= e((string)$l['unit']) ?>
                                <?php if ($l['status'] === 'sebagian'): ?>
                                    <div class="text-faint text-xs">sebagian</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs">
                                <?= e(date('d/m/y', strtotime((string)$l['tgl_pinjam']))) ?>
                                <div class="text-faint"><?= $hariLama ?> hari</div>
                            </td>
                            <td class="text-xs">
                                <?php if (!empty($l['tgl_target'])): ?>
                                    <span class="<?= $telat ? 'text-danger fw-600' : '' ?>">
                                        <?= e(date('d/m/y', strtotime((string)$l['tgl_target']))) ?>
                                        <?= $telat ? ' (lewat)' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-faint">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-primary btn-sm"
                                        onclick="openStockReturn(<?= (int)$l['id'] ?>, <?= htmlspecialchars(json_encode($l['customer_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($l['product_name']), ENT_QUOTES) ?>, <?= $sisa ?>, <?= htmlspecialchars(json_encode($l['unit']), ENT_QUOTES) ?>)">
                                    <i class="fas fa-rotate-left"></i> Kembalikan
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($loanSelesai): ?>
            <details class="mt-3">
                <summary class="text-sm text-faint" style="cursor:pointer;">
                    <i class="fas fa-clock-rotate-left"></i>
                    <?= count($loanSelesai) ?> pinjaman sudah lunas — klik untuk melihat riwayat
                </summary>
                <div class="table-responsive mt-2">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Pelanggan</th><th>Bahan</th><th class="text-right">Jumlah</th><th>Pinjam</th><th>Kembali</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loanSelesai as $l):
                                $fmtL = fn($n) => rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
                            ?>
                            <tr>
                                <td class="text-xs"><?= e((string)$l['customer_name']) ?></td>
                                <td class="text-xs"><?= e((string)$l['product_name']) ?></td>
                                <td class="text-right text-xs"><?= $fmtL($l['unit'] === 'meter' || $l['unit'] === 'roll' ? $l['meters'] : $l['qty']) ?> <?= e((string)$l['unit']) ?></td>
                                <td class="text-xs"><?= e(date('d/m/y', strtotime((string)$l['tgl_pinjam']))) ?></td>
                                <td class="text-xs text-success"><?= !empty($l['tgl_kembali']) ? e(date('d/m/y', strtotime((string)$l['tgl_kembali']))) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </div>
</div>

<!-- ===== KARTU PER PRODUK ===== -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-boxes-stacked"></i> Saldo Bahan</h3>
    </div>
    <div class="card-body">
        <?php if (!$summary): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-box-open"></i></div>
                <h3>Belum ada bahan terdaftar</h3>
                <p>Klik "Terima Roll" untuk mulai mendata stok bahan.</p>
            </div>
        <?php else: ?>
            <?php if (!$summaryAktif): ?>
                <div class="alert alert-info mb-3">
                    <i class="fas fa-circle-info"></i>
                    <div>
                        Semua bahan masih kosong &amp; belum pernah ada pembelian tercatat.
                        Gunakan <b>Unggah Nota Supplier</b> atau <b>Terima Stok</b> untuk mengisi.
                    </div>
                </div>
            <?php endif; ?>
            <div class="grid grid-2">
                <?php foreach ($summaryAktif as $s):
                    $p      = $s['product'];
                    $len    = (float)$s['roll_length_m'];
                    $awal   = (float)$s['rolls_total'] * $len;
                    $pct    = $awal > 0 ? min(100, max(0, ((float)$s['sisa_meter'] / $awal) * 100)) : 100;
                    $low    = $alertBelow > 0 && (float)$s['qty'] <= $alertBelow;
                    $warn   = $s['is_roll'] ? ($pct <= 20 && $awal > 0) : ((float)$s['qty'] <= 2);
                    $fmt    = fn($n) => rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
                ?>
                <div class="stock-card">
                    <div class="d-flex justify-between align-center mb-2">
                        <div>
                            <div class="fw-600"><?= e(stock_nama_tampil($p)) ?></div>
                            <div class="text-faint text-xs">
                                <?php if ($s['is_roll']): ?>
                                    roll <?= $fmt($s['roll_width_m']) ?>×<?= $fmt($len) ?> m
                                <?php else: ?>
                                    per <?= e($s['unit']) ?><?= ($bolehModal && (float)$s['unit_cost'] > 0) ? ' · beli ' . e(formatRupiah($s['unit_cost'])) : '' ?>
                                <?php endif; ?>
                                <?php if ($bolehModal && (float)$s['unit_cost'] <= 0): ?>
                                    · <span class="text-warning">modal belum diisi</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="fw-700" style="font-size:1.35rem;">
                                <?php /* Bahan ROLL → tampilkan JUMLAH ROLL NYATA (baris fisik),
                                       bukan hasil bagi meter ÷ panjang roll (2026-10-09).
                                       Sebab: setelah dipotong memanjang, satu bahan punya
                                       beberapa LEBAR berbeda (3,2m / 2,1m / 1,6m / 1,1m).
                                       Membagi total meter dgn 70 menghasilkan angka pecahan
                                       (9,16 roll) yang TIDAK ADA di gudang — pemilik
                                       mengira stoknya salah. Jumlah roll fisik (12) dan
                                       rincian per lebar tampil di kartu "Sisa per Lebar". */ ?>
                                <?php if ($s['is_roll']): ?>
                                    <?= (int)$s['rolls_total'] ?>
                                    <span class="text-faint" style="font-size:.8rem;">roll</span>
                                <?php else: ?>
                                    <?= $fmt($s['qty']) ?>
                                    <span class="text-faint" style="font-size:.8rem;"><?= e($s['unit']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="stock-bar<?= $warn ? ' stock-bar-warn' : '' ?>">
                        <div class="stock-bar-fill" style="width: <?= round($pct, 1) ?>%"></div>
                    </div>

                    <div class="d-flex justify-between text-xs mt-2">
                        <span>
                            <?php if ($s['is_roll']): ?>
                                <?php /* Tampilkan METER dan LUAS sekaligus.
                                       Meter saja menyesatkan begitu bahan digunting
                                       memanjang: 1 roll 3,2 m yang dibelah jadi
                                       2,1 m + 1,1 m berubah dari 70 m menjadi 140 m
                                       padahal bahannya TETAP 224 m². Jadi luas
                                       (m²) adalah angka yang sah untuk dijumlah. */ ?>
                                Sisa <b><?= $fmt($s['sisa_meter']) ?> m</b>
                                <?php $m2s = $s['area_m2'] ?? 0; if ($m2s > 0): ?>
                                    <span class="text-faint">· <?= $fmt($m2s) ?> m²</span>
                                <?php endif; ?>
                            <?php else: ?>
                                Sisa <b><?= $fmt($s['qty']) ?> <?= e($s['unit']) ?></b>
                            <?php endif; ?>
                        </span>
                        <span class="text-faint">
                            <?= $s['is_roll'] ? (int)$s['rolls_utuh'] . ' roll utuh' : '' ?>
                            <?= ($bolehModal && (float)$s['nilai_modal'] > 0) ? (($s['is_roll'] && (int)$s['rolls_utuh'] > 0) ? ' · ' : '') . 'nilai ' . e(formatRupiah($s['nilai_modal'])) : '' ?>
                        </span>
                    </div>

                    <?php if ($low): ?>
                        <div class="alert alert-warning mt-2 mb-0 p-2 text-xs">
                            <i class="fas fa-triangle-exclamation"></i> Stok menipis.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2 mt-3 flex-wrap">
                        <button type="button" class="btn btn-ghost btn-sm"
                                onclick="openStockReceive(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['category'] . ' ' . $p['variant']), ENT_QUOTES) ?>, <?= (float)$s['roll_width_m'] ?>, <?= $len ?>, <?= (float)($p['cost_per_roll'] ?? 0) ?>, <?= htmlspecialchars(json_encode($s['unit']), ENT_QUOTES) ?>, <?= (float)$s['unit_cost'] ?>)">
                            <i class="fas fa-plus"></i> Terima
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm"
                                onclick="openStockDamage(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['category'] . ' ' . $p['variant']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($s['unit']), ENT_QUOTES) ?>)">
                            <i class="fas fa-triangle-exclamation text-warning"></i> Rusak
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm"
                                onclick="openStockOpname(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['category'] . ' ' . $p['variant']), ENT_QUOTES) ?>, <?= (float)$s['qty'] ?>, <?= htmlspecialchars(json_encode($s['unit']), ENT_QUOTES) ?>)">
                            <i class="fas fa-clipboard-check"></i> Opname
                        </button>
                        <?php if ($bolehModal): ?>
                        <button type="button" class="btn btn-ghost btn-sm"
                                onclick="openStockCost(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['category'] . ' ' . $p['variant']), ENT_QUOTES) ?>, <?= (float)$s['unit_cost'] ?>, <?= htmlspecialchars(json_encode($s['unit']), ENT_QUOTES) ?>)">
                            <i class="fas fa-tag"></i> Harga Modal
                        </button>
                        <?php endif; ?>
                        <?php /* Tombol "Tandai Habis" hanya untuk bahan NON-ROLL yang
                                 masih ada isinya (tinta/pcs). Bahan roll tidak perlu —
                                 sudah otomatis terpotong per order. */ ?>
                        <?php if (!$s['is_roll'] && (float)$s['qty'] > 0.001): ?>
                        <button type="button" class="btn btn-ghost btn-sm"
                                onclick="openStockEmpty(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['category'] . ' ' . $p['variant']), ENT_QUOTES) ?>, <?= (float)$s['qty'] ?>, <?= htmlspecialchars(json_encode($s['unit']), ENT_QUOTES) ?>)">
                            <i class="fas fa-battery-empty text-danger"></i> Tandai Habis
                        </button>
                        <?php endif; ?>
                        <?php /* Tombol "Pinjam" hanya untuk bahan yang WAJAR
                                 dipinjamkan (kaki/tiang banner). Tinta & roll
                                 flexy tidak lazim dipinjamkan, jadi tombolnya
                                 tidak ditampilkan supaya daftar tetap relevan. */
                              $bolehPinjam = false;
                              foreach ((array)$loanProduk as $lp) {
                                  if ((int)$lp['id'] === (int)$p['id']) { $bolehPinjam = true; break; }
                              }
                              if ($bolehPinjam && (float)$s['qty'] > 0.001): ?>
                        <button type="button" class="btn btn-ghost btn-sm"
                                onclick="openStockLoan(<?= (int)$p['id'] ?>)">
                            <i class="fas fa-hand-holding text-warning"></i> Pinjam
                        </button>
                        <?php endif; ?>
                        <a href="stock?product=<?= (int)$p['id'] ?>&month=<?= e($bulan) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-list"></i> Riwayat</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($summaryKosong): ?>
                <!-- Bahan yang belum pernah bergerak: stok 0, tanpa riwayat
                     pembelian, tanpa pergerakan. Diringkas di sini supaya
                     halaman utama tidak penuh kartu "0" — tetapi tetap bisa
                     dibuka & diisi stok tanpa harus ke menu Produk. -->
                <details class="mt-3">
                    <summary class="text-sm text-faint" style="cursor:pointer;">
                        <i class="fas fa-circle-plus"></i>
                        <?= count($summaryKosong) ?> bahan belum bergerak (stok 0, belum ada pembelian) — klik untuk melihat
                    </summary>
                    <div class="stock-kosong-wrap">
                        <?php foreach ($summaryKosong as $s): $p = $s['product']; ?>
                            <div class="stock-kosong-item">
                                <span><?= e(stock_nama_tampil($p)) ?></span>
                                <span class="text-faint">0 <?= e($s['unit']) ?></span>
                                <button type="button" class="btn btn-ghost btn-sm"
                                        onclick="openStockReceive(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['category'] . ' ' . $p['variant']), ENT_QUOTES) ?>, <?= (float)($s['roll_width_m'] ?? 0) ?>, <?= (float)($s['roll_length_m'] ?? 0) ?>, <?= (float)($p['cost_per_roll'] ?? 0) ?>, <?= htmlspecialchars(json_encode($s['unit']), ENT_QUOTES) ?>, <?= (float)($s['unit_cost'] ?? 0) ?>)">
                                    <i class="fas fa-plus"></i> Terima
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ===== FILTER BUKU BESAR ===== -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <?php
        /* CATATAN RESPONSIVE (2026-10-09):
         * Sebelumnya form ini memakai `style="width:auto"` pada input bulan &
         * dropdown produk. Dua masalah:
         *   1. Inline style tidak bisa ditimpa media query → di layar sempit
         *      dropdown memaksa lebar isinya dan mendorong halaman bisa
         *      di-scroll ke samping (terukur meluber 34px).
         *   2. `width:auto` pada <select> tidak konsisten di semua browser.
         * Sekarang memakai kelas `.stock-filter-*` yang lebarnya diatur di CSS,
         * termasuk aturan layar sempit. */
        ?>
        <form method="GET" action="stock" class="stock-filter-form">
            <div class="stock-filter-field">
                <label class="form-label mb-0">Bulan:</label>
                <input type="month" name="month" class="form-input" value="<?= e($bulan) ?>">
            </div>
            <div class="stock-filter-field stock-filter-field-wide">
                <label class="form-label mb-0">Produk:</label>
                <select name="product" class="form-select">
                    <option value="0">Semua Produk</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $filterPid === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e(stock_nama_tampil($p)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="stock-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
                <a href="stock" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ===== BUKU BESAR ===== -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-book"></i> Buku Besar Pergerakan Bahan</h3>
        <span class="text-faint text-xs">Tidak ada catatan yang bisa dihapus — semua perubahan punya jejak</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Produk</th>
                    <th>Jenis</th>
                    <th class="text-right">Jumlah</th>
                    <?php if ($bolehModal): ?><th class="text-right">Nilai</th><?php endif; ?>
                    <th>Alasan / Catatan</th>
                    <th>Oleh</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$movements): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="fas fa-book-open"></i></div>
                                <h3>Belum ada pergerakan</h3>
                                <p>Pergerakan muncul saat bahan diterima, terpakai produksi, atau dilaporkan rusak.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($movements as $m):
                    $mt = (string)$m['type'];
                    $badge = ['masuk' => 'badge-success', 'potong' => 'badge-info', 'rusak' => 'badge-danger',
                              'koreksi' => 'badge-warning', 'opname' => 'badge-secondary',
                              'pinjam' => 'badge-warning', 'kembali' => 'badge-success'][$mt] ?? 'badge-secondary';
                    $label = ['masuk' => 'Masuk', 'potong' => 'Terpakai', 'rusak' => 'Rusak',
                              'koreksi' => 'Koreksi', 'opname' => 'Opname',
                              'pinjam' => 'Dipinjam', 'kembali' => 'Kembali'][$mt] ?? $mt;
                    $mtr = (float)$m['meters'];
                ?>
                    <tr>
                        <td class="text-xs"><?= e(date('d/m/y H:i', strtotime((string)$m['created_at']))) ?></td>
                        <td><?= e(stock_nama_tampil(['category' => (string)($m['category'] ?? '?'), 'variant' => (string)($m['variant'] ?? '')])) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= e($label) ?></span></td>
                        <td class="text-right fw-600 <?= $mtr >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?php
                            $mUnit = (string)($m['unit'] ?? '');
                            $mQty  = ($mUnit !== '' && !in_array($mUnit, ['roll', 'meter'], true) && $m['qty'] !== null)
                                ? (float)$m['qty'] : $mtr;
                            $mUnit = $mUnit !== '' ? $mUnit : 'm';
                            ?>
                            <?= ($mQty > 0 ? '+' : '') . rtrim(rtrim(number_format($mQty, 2, ',', '.'), '0'), ',') ?> <?= e($mUnit) ?>
                        </td>
                        <?php if ($bolehModal): ?>
                        <td class="text-right text-xs">
                            <?= $m['total_cost'] !== null ? e(formatRupiah(abs((float)$m['total_cost']))) : '<span class="text-faint">—</span>' ?>
                        </td>
                        <?php endif; ?>
                        <td class="text-xs">
                            <?php
                            /* Admin TIDAK boleh tahu harga beli. Catatan yang memuat
                               nominal Rp disembunyikan (mis. "beli 8 roll @ Rp 1.500.000"). */
                            $mNote = (string)($m['note'] ?? '');
                            if (!$bolehModal) {
                                $mNote = preg_replace('/@?\s*Rp\s*[\d.,]+/iu', '', $mNote);
                                $mNote = trim(preg_replace('/\s{2,}/', ' ', $mNote), " ·-@");
                            }
                            ?>
                            <?= e((string)($m['reason'] ?? '')) ?>
                            <?php if ($mNote !== ''): ?><span class="text-faint">· <?= e($mNote) ?></span><?php endif; ?>
                            <?php if (!empty($m['photo'])): ?>
                                <a href="<?= e((string)$m['photo']) ?>" target="_blank" class="text-gold"><i class="fas fa-image"></i> foto</a>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs"><?= e((string)($m['by_name'] ?? 'sistem')) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<!-- ===== MODAL: TERIMA ROLL ===== -->
<!-- ===== MODAL: TERIMA ROLL =====
     Dua cara penerimaan:
       (a) SATU LEBAR — cara lama, untuk bahan yang tidak dipotong.
       (b) BEBERAPA LEBAR (hasil potong memanjang / slit) — roll lebar 3,2 m
           dipotong memanjang menjadi 2,1 m / 1,6 m / 1,1 m supaya saat
           mencetak ukuran kecil (mis. 1x1 m) sisanya tidak terbuang banyak.
           Yang disimpan ke gudang = roll hasil potongan, bukan roll aslinya. -->
<div class="modal-backdrop" id="modal-stock-receive">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-plus"></i> Terima Stok Bahan</h3>
            <button type="button" class="modal-close" data-close-modal="stock-receive" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <!-- Pilih cara penerimaan -->
            <div class="rcv-tab">
                <button type="button" class="rcv-tab-btn active" data-rcv-tab="satu">
                    <i class="fas fa-scroll"></i> Satu Lebar
                </button>
                <button type="button" class="rcv-tab-btn" data-rcv-tab="banyak">
                    <i class="fas fa-scissors"></i> Beberapa Lebar (hasil potong)
                </button>
            </div>

            <!-- ---- (a) SATU LEBAR ---- -->
            <form method="POST" action="stock" id="formRcvSatu">
                <?= csrf_field() ?>
                <input type="hidden" name="receive_rolls" value="1">
                <div class="mb-3">
                    <label class="form-label">Produk Bahan <span class="text-danger">*</span></label>
                    <select name="product_id" id="rcvProduct" class="form-select" required>
                        <?php foreach ($allProducts as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e(stock_nama_tampil($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-2">
                    <div class="mb-3">
                        <label class="form-label">Jumlah <span id="rcvUnitLabel">roll</span> <span class="text-danger">*</span></label>
                        <input type="text" name="roll_count" id="rcvCount" class="form-input" inputmode="decimal" placeholder="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal Terima</label>
                        <input type="date" name="received_at" class="form-input" value="<?= e(date('Y-m-d')) ?>">
                    </div>
                </div>
                <div class="grid grid-3">
                    <div class="mb-3">
                        <label class="form-label">Lebar (m)</label>
                        <input type="text" name="width_m" id="rcvWidth" class="form-input" placeholder="3">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Panjang (m)</label>
                        <input type="text" name="length_m" id="rcvLength" class="form-input" placeholder="70">
                    </div>
                    <div class="mb-3"<?= $bolehModal ? '' : ' style="display:none"' ?>>
                        <label class="form-label">Harga / Roll</label>
                        <input type="text" name="cost_per_roll" id="rcvCost" class="form-input" inputmode="numeric" placeholder="1.500.000"<?= $bolehModal ? '' : ' disabled' ?>>
                    </div>
                </div>
                <?php if ($bolehModal): ?>
                <div class="form-hint mb-3">Harga per roll dipakai menghitung modal &amp; untung. Isi sekali, otomatis dipakai seterusnya.</div>
                <?php else: ?>
                <div class="alert alert-info p-2 text-xs mb-3">
                    <i class="fas fa-circle-info"></i>
                    Isi jumlah stok yang datang. <b>Harga beli diisi owner</b> di menu
                    Produk (tidak perlu diisi di sini).
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="note" class="form-input" placeholder="mis. beli dari supplier A">
                </div>
                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-receive">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Simpan</button>
                </div>
            </form>

            <!-- ---- (b) BEBERAPA LEBAR (hasil potong memanjang) ---- -->
            <form method="POST" action="stock" id="formRcvBanyak" style="display:none;">
                <?= csrf_field() ?>
                <input type="hidden" name="receive_groups" value="1">
                <div class="alert alert-info p-2 text-xs mb-3">
                    <i class="fas fa-scissors"></i>
                    Pakai ini bila roll <b>dipotong memanjang</b> dulu menjadi beberapa lebar supaya bahan tidak terbuang.
                    Contoh: beli 3 roll @3,2 m, lalu dipotong menjadi 1 roll 2,1 m + 1 roll 1,1 m.
                </div>
                <div class="mb-3">
                    <label class="form-label">Produk Bahan <span class="text-danger">*</span></label>
                    <select name="product_id" id="rcvProductBanyak" class="form-select" required>
                        <?php foreach ($allProducts as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e(stock_nama_tampil($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-2 mb-2">
                    <div class="mb-3">
                        <label class="form-label">Panjang tiap roll (m)</label>
                        <input type="text" name="length_m" id="rcvLengthBanyak" class="form-input" placeholder="70" value="70">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal Terima</label>
                        <input type="date" name="received_at" class="form-input" value="<?= e(date('Y-m-d')) ?>">
                    </div>
                </div>

                <label class="form-label">Rincian per Lebar</label>
                <div class="rcv-grup-head<?= $bolehModal ? ' rcv-h' : '' ?>">
                    <span>Lebar (m)</span><span>Jumlah roll</span><?= $bolehModal ? '<span>Harga/roll</span>' : '' ?>
                </div>
                <div id="rcvGrupList"></div>
                <button type="button" class="btn btn-ghost btn-sm mt-1" id="rcvTambahBaris">
                    <i class="fas fa-plus"></i> Tambah lebar
                </button>
                <div class="form-hint mt-2" id="rcvGrupTotal"></div>

                <div class="mb-3 mt-3">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="note" class="form-input" placeholder="mis. potong dari roll 3,2 m">
                </div>
                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-receive">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: LAPOR RUSAK ===== -->
<div class="modal-backdrop" id="modal-stock-damage">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-triangle-exclamation text-warning"></i> Lapor Kerusakan Bahan</h3>
            <button type="button" class="modal-close" data-close-modal="stock-damage" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="stock" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="report_damage" value="1">
                <div class="mb-3">
                    <label class="form-label">Produk Bahan <span class="text-danger">*</span></label>
                    <select name="product_id" id="dmgProduct" class="form-select" required>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e(stock_nama_tampil($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-2">
                    <div class="mb-3">
                        <label class="form-label">Panjang Rusak (<span id="dmgUnitLabel">meter</span>) <span class="text-danger">*</span></label>
                        <input type="text" name="damage_meters" class="form-input" inputmode="decimal" placeholder="4" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sebab</label>
                        <select name="damage_reason" class="form-select">
                            <?php foreach ($ERRORS as $k => $v): ?>
                                <option value="<?= e($k) ?>"><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Keterangan</label>
                    <textarea name="damage_note" class="form-textarea" rows="2" placeholder="mis. sobek saat pemasangan"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Foto Bukti</label>
                    <input type="file" name="damage_photo" class="form-input" accept="image/*">
                    <div class="form-hint">Opsional, maks 3MB (jpg/png/webp)</div>
                </div>
                <div class="alert alert-warning p-2 text-xs mb-3">
                    <i class="fas fa-circle-info"></i> Kerusakan langsung mengurangi stok &amp; tercatat sebagai kerugian di Keuangan.
                </div>
                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-damage">Batal</button>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-check"></i> Laporkan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: STOK OPNAME =====
     PERUBAHAN 12 Okt 2026: modal dibuat LEBIH LEBAR (`modal-lg`).
     Opname sekarang diisi PER ROLL — tiap baris lebar memuat 2 isian
     (jumlah roll utuh + sisa meter) plus hasil hitung dan pembanding "sistem".
     Pada modal 400px, kolom label & sistem terhimpit jadi ~46px dan ~37px
     sehingga teks terpotong dan barisnya membungkus jadi sangat tinggi
     (terukur 131px per baris). Dengan `modal-lg` (~760px) semuanya muat
     sebaris dan justru lebih ringkas. -->
<div class="modal-backdrop" id="modal-stock-opname">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-clipboard-check"></i> Stok Opname</h3>
            <button type="button" class="modal-close" data-close-modal="stock-opname" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="stock">
                <?= csrf_field() ?>
                <input type="hidden" name="do_opname" value="1">
                <div class="mb-3">
                    <label class="form-label">Produk Bahan <span class="text-danger">*</span></label>
                    <select name="product_id" id="opnProduct" class="form-select" required>
                        <?php foreach ($products as $p):
                            /* Stok sistem harus dihitung dengan cara yang SAMA
                               seperti backend (roll→meter, non-roll→stock_qty),
                               supaya angka di form tidak pernah beda dengan
                               angka yang dipakai server. */
                            $uOpn   = (string)($p['unit'] ?? 'pcs');
                            $isRollOpn = in_array($uOpn, ['roll', 'meter'], true);
                            $sysOpn = $isRollOpn
                                ? stock_total_meters((int)$p['id'])
                                : (float)($p['stock_qty'] ?? 0);
                        ?>
                            <option value="<?= (int)$p['id'] ?>"
                                    data-unit="<?= e($isRollOpn ? 'meter' : $uOpn) ?>"
                                    data-sistem="<?= e((string)round($sysOpn, 3)) ?>"><?= e(stock_nama_tampil($p)) ?> — <?= e(rtrim(rtrim(number_format($sysOpn, 2, ',', '.'), '0'), ',')) ?> <?= e($isRollOpn ? 'm' : $uOpn) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alert alert-info p-2 text-xs mb-3">
                    Stok menurut sistem: <b id="opnSystem">-</b> <span id="opnSystemUnit">meter</span>.
                </div>

                <!-- Opname bertingkat: untuk bahan yang roll-nya punya LEBAR
                     BERBEDA hasil potong memanjang. Petugas mengisi sisa nyata
                     per lebar; sistem menyusun ulang roll supaya cocok. -->
                <div id="opnPerLebar" style="display:none;">
                    <?php /* PANDUAN DIPERBAIKI 12 Okt 2026.
                           Sebelumnya satu paragraf panjang dengan 4 bagian tebal —
                           di layar 420 px tingginya mencapai 249 px (dinding teks)
                           sehingga petugas malas membacanya. Sekarang dipecah jadi
                           2 langkah ringkas + rumus, dan otomatis satu kolom pada
                           layar sempit. */ ?>
                    <div class="opn-panduan">
                        <div class="opn-panduan-judul"><i class="fas fa-clipboard-check"></i> Hitung <b>per roll</b></div>
                        <ol class="opn-panduan-langkah">
                            <li><span>Jumlah <b>roll utuh</b> — yang belum dibuka</span></li>
                            <li><span><b>Sisa meter</b> pada roll yang terbuka</span></li>
                        </ol>
                        <div class="opn-panduan-rumus">
                            total = (roll utuh × <b class="opn-rumus-panjang">—</b> m) + sisa
                            <span class="opn-panduan-pisah">·</span>
                            isi <b>0</b> bila lebar sudah habis
                        </div>
                        <div class="opn-panduan-catatan">
                            Kolom <b>sistem</b> = catatan kita. Selisih muncul di kolom kanan,
                            supaya bisa diperiksa ulang saat itu juga.
                        </div>
                    </div>
                    <div id="opnPerLebarList"></div>
                    <div class="form-hint mb-3" id="opnPerLebarTotal"></div>
                </div>

                <div class="mb-3" id="opnFisikWrap">
                    <label class="form-label">Hasil Hitung Fisik (<span id="opnUnitLabel">meter</span>) <span class="text-danger">*</span></label>
                    <input type="text" name="physical_meters" class="form-input" inputmode="decimal" placeholder="mis. 556" required>
                    <div class="form-hint">Tulis jumlah yang <b>benar-benar ada</b> di gudang. Untuk bahan roll, isi <b>total meter</b> sisa (roll utuh + sisa roll terbuka).</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="opname_note" class="form-input" placeholder="mis. hasil hitung 09/10 oleh Stevani">
                </div>
                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-opname">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="opnTombol"><i class="fas fa-check"></i> Simpan Selisih</button>
                </div>
            </form>

            <!-- Form kedua: pembenahan per lebar (dikirim terpisah) -->
            <form method="POST" action="stock" id="formOpnPerLebar" style="display:none;">
                <?= csrf_field() ?>
                <input type="hidden" name="fix_rolls" value="1">
                <input type="hidden" name="product_id" id="opnFixProduct" value="">
                <input type="hidden" name="note" id="opnFixNote" value="">
                <div id="opnFixInputs"></div>
            </form>
        </div>
    </div>
</div>

<?php if ($bolehModal): ?>
<!-- ===== MODAL: HARGA MODAL (owner saja) ===== -->
<div class="modal-backdrop" id="modal-stock-cost">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-tag"></i> Harga Modal Bahan</h3>
            <button type="button" class="modal-close" data-close-modal="stock-cost" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="stock">
                <?= csrf_field() ?>
                <input type="hidden" name="set_cost" value="1">
                <div class="mb-3">
                    <label class="form-label">Produk Bahan</label>
                    <select name="product_id" id="costProduct" class="form-select" required>
                        <?php foreach ($allProducts as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e(stock_nama_tampil($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Harga / <span id="costUnitLabel">roll</span> (Rp)</label>
                    <input type="text" name="cost_per_roll" id="costValue" class="form-input" inputmode="numeric" placeholder="1.500.000">
                    <div class="form-hint">Untuk bahan roll: harga per ROLL (otomatis dibagi panjang → biaya per meter). Untuk tinta/pcs: harga per satuan.</div>
                </div>
                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-cost">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($isOwnerRole): ?>
<!-- ===== MODAL: PENGATURAN (owner saja) ===== -->
<div class="modal-backdrop" id="modal-stock-settings">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-gear"></i> Pengaturan Stok</h3>
            <button type="button" class="modal-close" data-close-modal="stock-settings" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="stock">
                <?= csrf_field() ?>
                <input type="hidden" name="save_stock_settings" value="1">
                <div class="mb-3">
                    <label class="form-label">Cara Hitung Pemakaian Bahan</label>
                    <select name="cut_mode" class="form-select">
                        <option value="smart" <?= stock_cut_mode() === 'smart' ? 'selected' : '' ?>>Hemat (orientasi terbaik)</option>
                        <option value="linear" <?= stock_cut_mode() === 'linear' ? 'selected' : '' ?>>Linear (sisi terpanjang)</option>
                    </select>
                    <div class="form-hint">
                        Contoh roll 3m, pesanan 2×1m → <b>Hemat</b>: 1 m · <b>Linear</b>: 2 m
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Tambahan Pinggir / Bleed (cm per sisi)</label>
                    <input type="text" name="margin_cm" class="form-input" inputmode="decimal"
                           value="<?= e(getSetting('stock_margin_cm', '0')) ?>">
                    <div class="form-hint">Kelebihan cetak kiri/kanan/atas/bawah. Ikut dihitung sebagai pemakaian.</div>
                </div>
                <div class="mb-3">
                    <?php /* LABEL DIPERBAIKI 12 Okt 2026.
                           Sebelumnya tertulis "Peringatan Stok Menipis (meter)" padahal
                           yang dibandingkan adalah `stock_balance_unit()['qty']` — untuk
                           bahan roll itu = JUMLAH ROLL, bukan meter. Jadi kalau diisi
                           "1", peringatan menyala saat sisa 1 ROLL (± 70 m), bukan 1 m.
                           Nama lama menyesatkan pengguna. */ ?>
                    <label class="form-label">Peringatan Jumlah Roll Menipis (roll)</label>
                    <input type="text" name="alert_below_m" class="form-input" inputmode="decimal"
                           value="<?= e(getSetting('stock_alert_below_m', '0')) ?>">
                    <div class="form-hint">
                        Peringatan warna di kartu bahan saat sisa <b>jumlah roll</b> ≤ angka ini.
                        0 = peringatan mati. <b>Bukan</b> meter — untuk meter pakai pengaturan di bawah.
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notifikasi WhatsApp: Ambang Menipis per LEBAR (m)</label>
                    <input type="text" name="alert_lebar_m" class="form-input" inputmode="decimal"
                           value="<?= e(getSetting('stock_alert_lebar_m', '5')) ?>">
                    <div class="form-hint">
                        Dihitung <b>per lebar</b>, bukan per produk. Contoh: lebar 2,1 m tinggal 0,2 m akan
                        dilaporkan walau total bahan masih 590 m. Usul: <b>5</b> m.
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">
                        <input type="checkbox" name="alert_wa" value="1" <?= getSetting('stock_alert_wa', '0') === '1' ? 'checked' : '' ?>>
                        Kirim notifikasi WhatsApp ke grup
                    </label>
                    <div class="form-hint">
                        Tujuan: grup pada pengaturan <b>monitor_notif_groups</b> (grup CG Crew).
                        Dikirim <b>sekali</b> setiap status berubah — tidak berulang tiap jam.
                        Bila dimatikan, pemeriksaan tetap jalan tetapi hanya dicatat di
                        <code>logs/cron/stock_alert.log</code> tanpa kirim WhatsApp.
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Ambang Selisih Verifikasi (m²)</label>
                    <input type="text" name="verify_ambang_m2" class="form-input" inputmode="decimal"
                           value="<?= e(getSetting('stock_verify_ambang_m2', '1')) ?>">
                    <div class="form-hint">
                        Selisih bahan <b>sistem vs mesin cetak</b> di bawah angka ini dianggap wajar.
                        Hitungan kita memang +0,1 m lebih panjang (pinggir potong), jadi jangan diisi terlalu kecil.
                        Usul: <b>1</b> m².
                    </div>
                </div>
                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-settings">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($bolehSinkron): ?>
<!-- ===== MODAL: SINKRON PEMAKAIAN (owner saja) ===== -->
<div class="modal-backdrop" id="modal-stock-sync">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-rotate"></i> Sinkron Pemakaian Bahan</h3>
            <button type="button" class="modal-close" data-close-modal="stock-sync" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="stock">
                <?= csrf_field() ?>
                <input type="hidden" name="do_sync" value="1">
                <div class="mb-3">
                    <label class="form-label">Produk Bahan <span class="text-danger">*</span></label>
                    <select name="product_id" class="form-select" required>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e(stock_nama_tampil($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Mulai Dari Tanggal</label>
                    <input type="date" name="sync_from" class="form-input" value="<?= e(date('Y-m-d')) ?>">
                    <div class="form-hint">Hitung pemakaian dari order sejak tanggal ini. Kosongkan = otomatis dari tanggal terima roll paling tua.</div>
                </div>
                <div class="alert alert-info p-2 text-xs mb-3">
                    <i class="fas fa-circle-info"></i>
                    Sistem menghitung ulang pemakaian dari data order (cara <b>gabung menyamping</b>
                    supaya hemat). Hitungan lama diganti — jadi selalu konsisten, tidak menumpuk.
                </div>
                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-sync">Batal</button>
                    <button type="submit" name="sync_mode" value="hitung" class="btn btn-outline btn-sm"><i class="fas fa-calculator"></i> Hitung Dulu</button>
                    <button type="submit" name="sync_mode" value="terapkan" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Terapkan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; // end modal sinkron (owner) ?>

<!-- ===== MODAL: TANDAI HABIS (tinta & bahan non-roll) ===== -->
<div class="modal-backdrop" id="modal-stock-empty">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-battery-empty"></i> Tandai Bahan Habis</h3>
            <button type="button" class="modal-close" data-close-modal="stock-empty" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="stock">
                <?= csrf_field() ?>
                <input type="hidden" name="mark_empty" value="1">
                <div class="mb-3">
                    <label class="form-label">Produk Bahan <span class="text-danger">*</span></label>
                    <select name="product_id" id="empProduct" class="form-select" required>
                        <?php foreach ($products as $p): if (($p['unit'] ?? '') === 'roll') continue; ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e(stock_nama_tampil($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alert alert-info p-2 text-xs mb-3" id="empInfo">
                    Tinta dipakai bertahap — tidak dicatat per order karena pemakaiannya
                    tergantung desain (blok = boros, tulisan = irit).
                    Tandai HABIS saat tinta benar-benar habis, lalu catat restock berikutnya.
                    Sistem akan menghitung <b>tarif pemakaian nyata</b> dari selisih waktu itu.
                </div>
                <div class="mb-3">
                    <label class="form-label">Tanggal Habis</label>
                    <input type="date" name="empty_date" class="form-input" value="<?= e(date('Y-m-d')) ?>">
                    <div class="form-hint">Tanggal ini jadi titik batas analisa pemakaian.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="empty_note" class="form-input" placeholder="mis. habis dipakai order minggu ini">
                </div>
                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-empty">Batal</button>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-check"></i> Tandai Habis</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: RINCIAN SELISIH BAHAN vs MESIN (2026-10-12) =====
     Pemilik: "aku binggung 8 selisih ini dimana? bisa ga kita buat modal gt
     biar kliatan"
     Isinya BUKAN sekadar angka selisih — tapi juga UKURAN ITEM tiap order,
     supaya terlihat sendiri sebabnya. Contoh nyata yang langsung kelihatan:
     item "2×1 m" dicatat 1,10 m padahal mesin mencetak 2,01 m → jelas meter
     dihitung dari sisi TERPENDEK (rumus lama), sehingga catatan jadi kurang.
     Sebab ditulis sebagai "kemungkinan" karena dihitung dari pola data,
     bukan dari laporan orang. -->
<?php if ($verifBeda): ?>
<div class="modal-backdrop" id="modal-verif-beda">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-magnifying-glass-dollar"></i> Rincian Bahan vs Mesin</h3>
            <button type="button" class="modal-close" data-close-modal="verif-beda" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="alert alert-info p-2 text-xs mb-3">
                <b>Kenapa ada selisih?</b> Sistem membandingkan <b>meter yang lewat mesin RYPC</b>
                dengan <b>meter yang kita catat</b>. Tiga sebab yang wajar ditemukan:
                <br>· <b>Digabung</b> — beberapa cetakan disusun berdampingan, jadi bahan terpakai
                lebih sedikit daripada jumlah per-satuan → <b>hemat</b>, bukan kerugian.
                <br>· <b>Rumus sisi terpendek</b> — meter dihitung dari sisi pendek ukuran padahal
                bahan yang lewat mesin sepanjang sisi lainnya (rumus lama; sudah diperbaiki 12 Okt).
                <br>· <b>Belum tercatat</b> — mesin mencetak, catatan potong tidak ada.
            </div>
            <div class="alert alert-warning p-2 text-xs mb-3">
                <b>Enam log berstatus gagal ini bukan vonis cetakan gagal.</b> Saya cocokkan semuanya
                ke PC operator; keenamnya punya berkas <code>.rtk</code> (penanda job selesai RYPC).
                Koreksi stok tidak mengubah riwayat <code>printer_jobs</code>, jadi status lama tetap
                tersimpan sebagai informasi, bukan bukti hasil fisik gagal.
            </div>

            <?php
            $vbBeda  = array_values(array_filter($verifBeda, fn($x) => ($x['jenis'] ?? '') === 'beda'));
            $vbKosong = array_values(array_filter($verifBeda, fn($x) => ($x['jenis'] ?? '') === 'tanpa_catatan'));
            ?>
            <?php if ($vbBeda): ?>
                <h4 class="text-sm mb-2">
                    <i class="fas fa-scale-unbalanced"></i> Selisih meter (<?= count($vbBeda) ?> job)
                </h4>
                <div class="hp-wrap mb-4">
                    <table class="table" style="min-width:900px;">
                        <thead>
                            <tr>
                                <th style="width:72px;">Tanggal</th>
                                <th style="width:118px;">Order</th>
                                <th>Ukuran Item</th>
                                <th class="num" style="width:74px;">Mesin</th>
                                <th class="num" style="width:74px;">Dicatat</th>
                                <th class="num" style="width:66px;">Selisih</th>
                                <th style="width:130px;">Lebar roll</th>
                                <th style="width:190px;">Kemungkinan sebab</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($vbBeda as $bv): $sb = (float)($bv['selisih_m'] ?? 0); ?>
                            <tr>
                                <td class="text-xs"><?= e(date('d/m H:i', strtotime((string)$bv['mulai']))) ?></td>
                                <td class="text-xs">
                                    <?php foreach ((array)$bv['orders'] as $on): ?>
                                        <b><?= e((string)$on) ?></b><br>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-xs">
                                    <?php foreach ((array)$bv['orders'] as $on): ?>
                                        <?= $verifUkuran((string)$on) ?>
                                    <?php endforeach; ?>
                                </td>
                                <td class="num text-xs"><?= $vf($bv['feed_m'] ?? 0) ?></td>
                                <td class="num text-xs"><?= $vf($bv['catat_m'] ?? 0) ?></td>
                                <td class="num text-xs" style="color:var(--warning,#f5a623);">
                                    <?= ($sb > 0 ? '+' : '') . $vf($sb) ?>
                                </td>
                                <td class="text-xs">
                                    <?php if ((float)($bv['roll_fisik'] ?? 0) > 0): ?>
                                        <span>perlu <?= $vf($bv['roll_fisik'], 2) ?> m</span><br>
                                    <?php else: ?>
                                        <span class="text-faint">lebar perlu: tidak diketahui</span><br>
                                    <?php endif; ?>
                                    <span class="text-faint">tercatat: <?= $verifLebarRoll($bv) ?></span>
                                </td>
                                <td class="text-xs"><?= implode('<br>', $verifSebab($bv)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($vbKosong): ?>
                <h4 class="text-sm mb-2">
                    <i class="fas fa-circle-question"></i> Belum tercatat potong (<?= count($vbKosong) ?> job)
                </h4>
                <div class="hp-wrap">
                    <table class="table" style="min-width:760px;">
                        <thead>
                            <tr>
                                <th style="width:80px;">Tanggal</th>
                                <th style="width:130px;">Order</th>
                                <th>Ukuran Item</th>
                                <th class="num" style="width:80px;">Mesin</th>
                                <th class="num" style="width:80px;">Dicatat</th>
                                <th style="width:180px;">Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($vbKosong as $bv): ?>
                            <tr>
                                <td class="text-xs"><?= e(date('d/m H:i', strtotime((string)$bv['mulai']))) ?></td>
                                <td class="text-xs">
                                    <?php foreach ((array)$bv['orders'] as $on): ?>
                                        <b><?= e((string)$on) ?></b><br>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-xs">
                                    <?php foreach ((array)$bv['orders'] as $on): ?>
                                        <?= $verifUkuran((string)$on) ?>
                                    <?php endforeach; ?>
                                </td>
                                <td class="num text-xs"><?= $vf($bv['feed_m'] ?? 0) ?></td>
                                <td class="num text-xs">0</td>
                                <td class="text-xs"><?= implode('<br>', $verifSebab($bv)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p class="text-faint text-xs mt-3 mb-0">
                <i class="fas fa-circle-info"></i>
                Panel ini <b>hanya menampilkan</b> — tidak mengubah apa pun. Job yang selisih saling
                menutup: ada yang catatannya lebih, ada yang kurang, sehingga total keseluruhan tetap
                dekat dengan mesin.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== MODAL: PINJAMKAN BAHAN (kaki x-banner dll) ===== -->
<div class="modal-backdrop" id="modal-stock-loan">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-hand-holding"></i> Pinjamkan Bahan</h3>
            <button type="button" class="modal-close" data-close-modal="stock-loan" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="stock">
                <?= csrf_field() ?>
                <input type="hidden" name="do_loan" value="1">

                <div class="alert alert-info p-2 text-xs mb-3">
                    <i class="fas fa-circle-info"></i>
                    Bahan yang dipinjam <b>berkurang dari stok</b> karena barangnya memang
                    keluar gudang, lalu <b>kembali lagi</b> saat dikembalikan.
                    Pinjaman <b>tidak dihitung sebagai HPP</b> — jadi laba tidak terpengaruh.
                </div>

                <div class="mb-3">
                    <label class="form-label">Bahan yang Dipinjam <span class="text-danger">*</span></label>
                    <select name="loan_product" id="loanProduct" class="form-select" required>
                        <option value="">— pilih bahan —</option>
                        <?php foreach ($loanProduk as $lp):
                            /* BUG KECIL yang diperbaiki (2026-10-09): memakai
                             * rtrim(rtrim(number_format(...), '0'), ',') untuk
                             * membuang nol di belakang membuat angka "0"
                             * berubah jadi teks KOSONG — di dropdown muncul
                             * "— sisa  pcs" seolah datanya rusak. Untuk jumlah
                             * satuan (pcs) tidak perlu desimal sama sekali,
                             * jadi cukup number_format biasa. */
                            $stokLp = (float)$lp['stock_qty'];
                            $teksLp = $stokLp == floor($stokLp)
                                ? number_format($stokLp, 0, ',', '.')
                                : rtrim(rtrim(number_format($stokLp, 2, ',', '.'), '0'), ',');
                        ?>
                            <option value="<?= (int)$lp['id'] ?>" data-stok="<?= $stokLp ?>" data-unit="<?= e((string)$lp['unit']) ?>">
                                <?= e(ucfirst((string)$lp['category']) . ' ' . $lp['variant']) ?>
                                — sisa <?= $teksLp ?> <?= e((string)$lp['unit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$loanProduk): ?>
                        <div class="form-hint text-warning">Belum ada bahan berkategori banner yang dilacak stoknya.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label">Pelanggan <span class="text-danger">*</span></label>
                    <?php /* PENCARIAN PELANGGAN (revisi pemilik 2026-10-09:
                             "saat pilih pinjamkan kok pelanggan ga ada fitur cari
                              ini ribet bgt loh soalnya banyak").
                             Sebelumnya hanya <select> berisi 500 pelanggan — harus
                             menggeser satu per satu untuk menemukan nama. Sekarang
                             ada kotak cari seperti di form order: ketik nama ATAU
                             nomor telepon, hasil muncul langsung.
                             Memakai endpoint `api/customers?q=` yang SUDAH ADA
                             (dipakai form order), jadi tidak ada logika pencarian
                             kedua yang perlu dirawat. */ ?>
                    <div class="loan-cust-search">
                        <i class="fas fa-magnifying-glass loan-cust-icon"></i>
                        <input type="text" id="loanCustSearch" class="form-input"
                               placeholder="Ketik nama atau nomor telepon pelanggan…"
                               autocomplete="off">
                        <button type="button" class="loan-cust-clear" id="loanCustClear"
                                title="Hapus pilihan" style="display:none;">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                    <div id="loanCustSuggest" class="loan-cust-suggest" style="display:none;"></div>
                    <div id="loanCustPicked" class="loan-cust-picked" style="display:none;"></div>
                    <input type="hidden" name="loan_customer" id="loanCustomer" value="">
                    <div class="form-hint" id="loanCustHint">
                        Ketik minimal 2 huruf untuk mencari. Bisa juga pakai nomor telepon.
                    </div>
                </div>

                <div class="loan-grid-2">
                    <div class="mb-3">
                        <label class="form-label">Jumlah <span class="text-danger">*</span></label>
                        <input type="text" name="loan_qty" id="loanQty" class="form-input" placeholder="mis. 2" required>
                        <div class="form-hint" id="loanQtyHint">Jumlah yang dibawa pelanggan.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal Pinjam</label>
                        <input type="date" name="loan_date" class="form-input" value="<?= e(date('Y-m-d')) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Rencana Dikembalikan</label>
                    <input type="date" name="loan_due" class="form-input">
                    <div class="form-hint">
                        Opsional. Kalau diisi, sistem bisa mengingatkan lewat WhatsApp
                        saat tanggalnya lewat.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="loan_note" class="form-input" placeholder="mis. ambil sendiri di toko">
                </div>

                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-loan">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-hand-holding"></i> Catat Pinjaman</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: PENGEMBALIAN PINJAMAN (boleh sebagian) ===== -->
<div class="modal-backdrop" id="modal-stock-return">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-rotate-left"></i> Kembalikan Pinjaman</h3>
            <button type="button" class="modal-close" data-close-modal="stock-return" aria-label="Tutup"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" action="stock">
                <?= csrf_field() ?>
                <input type="hidden" name="do_return" value="1">
                <input type="hidden" name="loan_id" id="retLoanId" value="">

                <div class="alert alert-info p-2 text-xs mb-3">
                    <b id="retCust">—</b> · <span id="retProd">—</span><br>
                    Sisa yang masih di pelanggan: <b id="retSisa">—</b>
                </div>

                <div class="mb-3">
                    <label class="form-label">Jumlah Dikembalikan <span class="text-danger">*</span></label>
                    <input type="text" name="return_qty" id="retQty" class="form-input" required>
                    <div class="form-hint">
                        Boleh dikembalikan <b>sebagian</b> — mis. pinjam 2, baru kembali 1.
                        Stok bertambah sejumlah ini.
                    </div>
                </div>

                <div class="d-flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-close-modal="stock-return">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Catat Kembali</button>
                </div>
            </form>

            <?php if ($isOwnerRole): ?>
            <hr class="my-3">
            <form method="POST" action="stock" onsubmit="return confirm('Tandai pinjaman ini HILANG? Stok TIDAK akan dibalikkan karena barangnya memang tidak kembali.');">
                <?= csrf_field() ?>
                <input type="hidden" name="do_hilang" value="1">
                <input type="hidden" name="loan_id" id="hilLoanId" value="">
                <button type="submit" class="btn btn-ghost btn-sm text-danger w-full">
                    <i class="fas fa-ban"></i> Tandai Hilang (tidak dikembalikan)
                </button>
                <div class="form-hint text-center mt-1">
                    Dipakai kalau barang benar-benar tidak kembali — supaya tidak
                    terus muncul sebagai tagihan yang tak akan selesai.
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Data sisa meter per produk untuk JS (opname & validasi).
// `lebar[]` dipakai opname BERTINGKAT: bahan roll sering DIPOTONG MEMANJANG
// (slit) menjadi beberapa lebar (3,2 m → 2,1 m / 1,6 m / 1,1 m) supaya sisa
// bahan saat mencetak ukuran kecil tidak terbuang. Kalau ada lebih dari satu
// lebar, petugas harus mengisi sisa per lebar — bukan satu angka total —
// karena kalau ditotal saja, sistem tidak tahu lebar mana yang berkurang.
$stockJs = [];
foreach ($summary as $s) {
    $pid  = (int)$s['product_id'];
    $isRoll = ($s['unit'] ?? '') === 'roll' || ($s['unit'] ?? '') === 'meter';
    $lebar = [];
    if ($isRoll && function_exists('stock_roll_summary_by_width')) {
        foreach (stock_roll_summary_by_width($pid) as $b) {
            $lebar[] = ['width' => $b['width'], 'meters' => $b['meters'],
                        'rolls' => $b['rolls'], 'utuh' => $b['utuh'], 'terbuka' => $b['terbuka']];
        }
    }
    $stockJs[$pid] = [
        'meters' => (float)$s['sisa_meter'],
        'name'   => $s['name'],
        'isRoll' => $isRoll,
        // Panjang 1 roll (mis. 70 m) — dipakai opname PER ROLL:
        // total = (jumlah roll utuh × panjang roll) + sisa roll terbuka.
        // Tanpa angka ini petugas harus mengalikan sendiri dan rawan salah.
        'rollLen' => (float)($s['roll_length_m'] ?? 0),
        'lebar'  => $lebar,
    ];
}
?>
<script>
window.STOCK_DATA = <?= json_encode($stockJs, JSON_UNESCAPED_UNICODE) ?>;

window.openStockReceive = function (pid, name, w, len, cost, unit, unitCost) {
    if (pid) document.getElementById('rcvProduct').value = pid;
    if (w)    document.getElementById('rcvWidth').value  = w;
    if (len)  document.getElementById('rcvLength').value = len;
    // Harga: roll → per roll; satuan lain → per satuan
    var harga = (unit === 'roll' || unit === 'meter') ? cost : unitCost;
    document.getElementById('rcvCost').value = harga > 0
        ? String(Math.round(harga)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
    document.getElementById('rcvCount').value = '';
    var lbl = document.getElementById('rcvUnitLabel');
    if (lbl) lbl.textContent = unit || 'roll';
    window.openModal('stock-receive');
};

window.openStockDamage = function (pid, name, unit) {
    if (pid) document.getElementById('dmgProduct').value = pid;
    var lbl = document.getElementById('dmgUnitLabel');
    if (lbl) lbl.textContent = unit || 'meter';
    window.openModal('stock-damage');
};

/* ===== PINJAMAN BAHAN =====
   Modal "Pinjamkan" dipakai untuk mencatat kaki x-banner yang dibawa
   pelanggan; "Kembalikan" untuk mencatat barang yang sudah balik.
   Pengembalian diisi sisa TERBANYAK lebih dulu (kasus tersering: kembali
   penuh) supaya petugas tinggal menekan tombol, tidak perlu menghitung. */
window.openStockLoan = function (pid) {
    if (pid) {
        var sel = document.getElementById('loanProduct');
        if (sel) sel.value = pid;
    }
    // Reset pilihan pelanggan setiap kali modal dibuka, supaya tidak ada
    // sisa pilihan dari pinjaman sebelumnya yang terbawa tanpa disadari.
    loanCustReset();
    window.openModal('stock-loan');
};

/* =====================================================================
 * PENCARIAN PELANGGAN DI MODAL PINJAMKAN
 * =====================================================================
 * Revisi pemilik: "saat pilih pinjamkan kok pelanggan ga ada fitur cari
 * ini ribet bgt loh soalnya banyak".
 *
 * Memakai endpoint `api/customers?q=` yang SUDAH dipakai form order, jadi
 * tidak ada logika pencarian kedua yang perlu dirawat dan perilakunya
 * konsisten di kedua tempat.
 *
 * Detail penting:
 *  - Mengetik minimal 2 huruf (mengurangi beban server & hasil lebih relevan)
 *  - Ada jeda 300ms sebelum memanggil server, supaya tidak ada satu request
 *    per ketukan tombol saat operator mengetik cepat
 *  - Balasan yang datang TERLAMBAT diabaikan bila sudah ada pencarian lebih
 *    baru (kalau tidak, hasil lama bisa menimpa hasil baru)
 *  - Nomor telepon boleh diketik dengan spasi/tanda hubung
 * ===================================================================== */
var loanCustTimer = null;
var loanCustSeq   = 0;

function loanCustReset() {
    var s = document.getElementById('loanCustSearch');
    var h = document.getElementById('loanCustomer');
    var sg = document.getElementById('loanCustSuggest');
    var pk = document.getElementById('loanCustPicked');
    var cl = document.getElementById('loanCustClear');
    if (s)  s.value = '';
    if (h)  h.value = '';
    if (sg) { sg.style.display = 'none'; sg.innerHTML = ''; }
    if (pk) pk.style.display = 'none';
    if (cl) cl.style.display = 'none';
    var ht = document.getElementById('loanCustHint');
    if (ht) ht.textContent = 'Ketik minimal 2 huruf untuk mencari. Bisa juga pakai nomor telepon.';
}

function loanCustTampil(hasil) {
    var sg = document.getElementById('loanCustSuggest');
    if (!sg) return;
    sg.innerHTML = '';

    if (!hasil.length) {
        sg.innerHTML = '<div class="loan-cust-empty">'
            + '<i class="fas fa-face-frown"></i> Pelanggan tidak ditemukan. '
            + 'Cek ejaan nama atau nomor teleponnya.</div>';
        sg.style.display = 'block';
        return;
    }

    hasil.slice(0, 40).forEach(function (c) {
        var el = document.createElement('div');
        el.className = 'loan-cust-opt';
        el.setAttribute('tabindex', '0');

        var ava = '';
        if (c.photo) {
            ava = '<img src="' + c.photo + '" alt="" loading="lazy">';
        } else {
            // Inisial nama sebagai pengganti foto — supaya baris tetap sejajar
            var ini = (c.name || '?').trim().charAt(0).toUpperCase();
            ava = '<span class="loan-cust-ava">' + ini + '</span>';
        }

        el.innerHTML = ava
            + '<div class="loan-cust-opt-body">'
            +   '<div class="loan-cust-opt-name">' + (c.name || '(tanpa nama)') + '</div>'
            +   '<div class="loan-cust-opt-sub">' + (c.phone || 'tanpa nomor')
            +     (c.is_regular == 1 ? ' · <b>Pelanggan tetap</b>' : '') + '</div>'
            + '</div>';

        var pilih = function () {
            var h = document.getElementById('loanCustomer');
            var s = document.getElementById('loanCustSearch');
            var pk = document.getElementById('loanCustPicked');
            var cl = document.getElementById('loanCustClear');
            if (h) h.value = c.id;
            if (s) s.value = c.name || '';
            if (pk) {
                pk.style.display = 'flex';
                pk.innerHTML = '<i class="fas fa-circle-check"></i>'
                    + '<div class="loan-cust-opt-body">'
                    +   '<div class="loan-cust-opt-name">' + (c.name || '') + '</div>'
                    +   '<div class="loan-cust-opt-sub">' + (c.phone || 'tanpa nomor') + '</div>'
                    + '</div>';
            }
            if (cl) cl.style.display = 'block';
            sg.style.display = 'none';
            var ht = document.getElementById('loanCustHint');
            if (ht) ht.textContent = 'Pelanggan sudah dipilih. Ganti dengan mengetik nama lain.';
        };
        el.addEventListener('click', pilih);
        el.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); pilih(); }
        });

        sg.appendChild(el);
    });
    sg.style.display = 'block';
}

function loanCustCari(q) {
    var sg = document.getElementById('loanCustSuggest');
    if (!q || q.trim().length < 2) {
        if (sg) { sg.style.display = 'none'; sg.innerHTML = ''; }
        return;
    }
    // Nomor telepon: buang spasi/tanda hubung supaya "0812-345" tetap ketemu
    var kata = q.trim().replace(/[\s\-().]/g, '');
    var seq = ++loanCustSeq;

    fetch('api/customers?q=' + encodeURIComponent(kata))
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (d) {
            // Abaikan balasan basi (pencarian lebih baru sudah dijalankan)
            if (seq !== loanCustSeq) return;
            loanCustTampil(Array.isArray(d) ? d : []);
        })
        .catch(function () {
            if (seq !== loanCustSeq) return;
            if (sg) {
                sg.innerHTML = '<div class="loan-cust-empty">'
                    + '<i class="fas fa-triangle-exclamation"></i> Gagal memuat daftar pelanggan. Coba lagi.</div>';
                sg.style.display = 'block';
            }
        });
}

(function pasangPencarianPelanggan() {
    var inp = document.getElementById('loanCustSearch');
    if (!inp) return;

    inp.addEventListener('input', function () {
        var h = document.getElementById('loanCustomer');
        // Mengetik lagi = pilihan sebelumnya batal, supaya tidak tersimpan
        // pelanggan lama padahal namanya sudah diganti.
        if (h) h.value = '';
        var pk = document.getElementById('loanCustPicked');
        var cl = document.getElementById('loanCustClear');
        if (pk) pk.style.display = 'none';
        if (cl) cl.style.display = 'none';

        var v = this.value;
        clearTimeout(loanCustTimer);
        loanCustTimer = setTimeout(function () { loanCustCari(v); }, 300);
    });

    inp.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            var sg = document.getElementById('loanCustSuggest');
            if (sg) sg.style.display = 'none';
        }
    });

    var cl = document.getElementById('loanCustClear');
    if (cl) cl.addEventListener('click', function () { loanCustReset(); inp.focus(); });

    // Klik di luar area pencarian → daftar saran ditutup
    document.addEventListener('click', function (ev) {
        if (!ev.target.closest('#loanCustSearch') && !ev.target.closest('#loanCustSuggest')) {
            var sg = document.getElementById('loanCustSuggest');
            if (sg) sg.style.display = 'none';
        }
    });
})();

window.openStockReturn = function (loanId, custName, prodName, sisa, unit) {
    /* Semua elemen diambil dengan pemeriksaan null.
     * Tanpa ini, satu elemen yang tidak ada membuat SELURUH fungsi gagal
     * ("Cannot set properties of null") — modal tidak terbuka sama sekali
     * dan tombolnya tampak mati, padahal hanya satu bagian yang bermasalah. */
    var el;
    if ((el = document.getElementById('retLoanId'))) el.value = loanId;
    if ((el = document.getElementById('hilLoanId'))) el.value = loanId;
    if ((el = document.getElementById('retCust'))) el.textContent = custName || '—';
    if ((el = document.getElementById('retProd'))) el.textContent = prodName || '—';
    if ((el = document.getElementById('retSisa'))) {
        el.textContent = Number(sisa || 0).toLocaleString('id-ID') + ' ' + (unit || 'pcs');
    }
    // Isi otomatis dengan SISA PENUH — pengembalian penuh yang paling sering.
    if ((el = document.getElementById('retQty'))) el.value = sisa;
    window.openModal('stock-return');
};

// Petunjuk satuan pada modal pinjam mengikuti bahan yang dipilih
document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'loanProduct') {
        var opt = e.target.options[e.target.selectedIndex];
        if (!opt) return;
        var unit = opt.getAttribute('data-unit') || 'pcs';
        var hint = document.getElementById('loanQtyHint');
        if (hint) {
            var stok = Number(opt.getAttribute('data-stok') || 0).toLocaleString('id-ID');
            hint.innerHTML = 'Satuan <b>' + unit + '</b> · sisa stok sekarang <b>' + stok + '</b> ' + unit +
                             '. Stok langsung berkurang sejumlah ini.';
        }
    }
});

window.openStockOpname = function (pid, name, sisa, unit) {
    if (pid) document.getElementById('opnProduct').value = pid;
    var el = document.getElementById('opnSystem');
    /* Kalau fungsi ini dipanggil dari tautan/dropdown, 'sisa' bisa berupa
       objek atau undefined → Number(objek) = NaN, dan NaN tampil apa adanya
       di layar sebagai "NaN". Jatuhkan ke angka dari data-sistem milik
       produk yang dipilih, baru ke 0. */
    var angka = Number(sisa);
    if (!isFinite(angka)) {
        var selOpn = document.getElementById('opnProduct');
        var optOpn = (selOpn && selOpn.selectedIndex >= 0) ? selOpn.options[selOpn.selectedIndex] : null;
        angka = optOpn ? (parseFloat(optOpn.getAttribute('data-sistem') || '0') || 0) : 0;
    }
    if (el) el.textContent = angka.toLocaleString('id-ID');
    // Satuan ikut produk yang dipilih (bukan selalu "meter").
    // Sebelumnya label selalu "meter" walau produknya pcs/jergen/botol,
    // sehingga petugas bisa salah mengisi angka untuk item non-roll.
    var satuan = unit || 'meter';
    var su = document.getElementById('opnSystemUnit');
    if (su) su.textContent = satuan;
    var lbl = document.getElementById('opnUnitLabel');
    if (lbl) lbl.textContent = satuan;
    pasangOpnameBertingkat(pid, satuan);
    window.openModal('stock-opname');
};

/* ---------------------------------------------------------------------
 * OPNAME BERTINGKAT PER LEBAR
 * ---------------------------------------------------------------------
 * Bahan roll sering dipotong memanjang menjadi beberapa lebar. Kalau roll-nya
 * punya >1 lebar, isian "total meter" saja tidak cukup: sistem tidak tahu
 * lebar mana yang berkurang, dan pembanding per lebar ikut hilang.
 * Karena itu tampilkan isian per lebar + lebar maksimum yang bisa ditambah.
 * ------------------------------------------------------------------- */
var OPN_LEBAR_MAKS = 8;

function pasangOpnameBertingkat(pid, satuan) {
    var wrap = document.getElementById('opnPerLebar');
    var list = document.getElementById('opnPerLebarList');
    var fisikWrap = document.getElementById('opnFisikWrap');
    var formFix = document.getElementById('formOpnPerLebar');
    var formUtama = document.getElementById('formOpnameUtama');
    if (!wrap || !list) return;

    var d = (window.STOCK_DATA || {})[pid] || null;
    var lebarArr = d && d.lebar ? d.lebar : [];

    /* PERUBAHAN 12 Okt 2026 — OPNAME DIISI PER ROLL, bukan per meter.
     * Permintaan pemilik: "ini kan per roll ya sedangkan kamu permeter bisa ga ya
     * di tambah per roll jadi bisa di kurangi rollnya gt dan di tambah".
     *
     * Alasannya masuk akal: di gudang orang menghitung **jumlah roll utuh** lalu
     * sisa meter pada roll yang sedang TERBUKA — bukan menjumlahkan meter semua
     * roll. Dulu formnya hanya minta satu angka meter, sehingga petugas harus
     * mengalikan sendiri (70 m × berapa roll + sisa) dan rawan salah.
     *
     * Sekarang tiap lebar punya:
     *   [roll utuh] × [panjang roll]  +  [sisa roll terbuka]  = [total meter]
     * Total meter itu yang dikirim ke server (input tersembunyi `.opn-mtr`),
     * jadi perhitungan backend tetap sama dan sudah terbukti aman. */
    if (!d || !d.isRoll || lebarArr.length < 1) {
        // Bukan roll → pakai cara lama (satu angka total)
        wrap.style.display = 'none';
        if (fisikWrap) fisikWrap.style.display = '';
        list.innerHTML = '';
        return;
    }

    wrap.style.display = '';
    if (fisikWrap) fisikWrap.style.display = 'none';

    var rollLen = Number(d.rollLen || 0);

    // Tampilkan panjang roll yang SEBENARNYA di panduan (Flexy 70 m, PET 100 m)
    // supaya petugas tidak perlu tahu sendiri — dan tidak salah mengalikan.
    var rumusEl = wrap.querySelector('.opn-rumus-panjang');
    if (rumusEl) rumusEl.textContent = rollLen > 0 ? fmtOpn(rollLen) : 'panjang roll';

    var html = '';
    lebarArr.forEach(function (b, i) {
        var utuh    = Number(b.utuh || 0);
        var terbuka = Number(b.terbuka || 0);
        var sisaTerbuka = Math.max(0, Number(b.meters || 0) - utuh * rollLen);
        var totalSys    = Number(b.meters || 0);

        html += '<div class="opn-baris" data-roll-len="' + rollLen + '">'
             +  '<div class="opn-lebar">lebar <b>' + b.width + ' m</b>'
             +     '<div class="text-faint text-xs">sistem: ' + b.rolls + ' roll ('
             +     utuh + ' utuh, ' + terbuka + ' terbuka)</div></div>'
             +  '<div class="opn-sistem"><span class="text-faint text-xs">sistem</span><br>'
             +     fmtOpn(totalSys) + ' m</div>'
             +  '<div class="opn-inp">'
             +     '<span class="opn-inp-lbl">utuh</span>'
             +     '<input type="text" class="form-input form-input-sm opn-utuh" inputmode="numeric"'
             +        ' value="' + utuh + '" title="Jumlah roll yang masih utuh (belum dipotong)">'
             +     '<span class="opn-inp-unit">roll</span>'
             +     '<span class="opn-inp-lbl">+ sisa</span>'
             +     '<input type="text" class="form-input form-input-sm opn-sisa" inputmode="decimal"'
             +        ' value="' + (Number(sisaTerbuka.toFixed(2))) + '" title="Sisa meter pada roll yang sedang terbuka">'
             +     '<span class="opn-inp-unit">m</span>'
             +     '<div class="opn-total" data-total="' + totalSys + '">= <b>—</b></div>'
             +  '</div>'
             +  '<input type="hidden" class="opn-mtr" data-lebar="' + b.width + '" data-i="' + i + '"'
             +     ' value="' + totalSys + '">'
             + '</div>';
    });
    list.innerHTML = html;

    // Hitung total tiap baris SEKARANG (bukan menunggu petugas mengetik), supaya
    // saat modal terbuka angkanya sudah tampil — bukan "—" kosong yang bikin
    // mengira formnya rusak.
    list.querySelectorAll('.opn-baris').forEach(function (row) {
        var iu = row.querySelector('.opn-utuh');
        if (iu) sinkronBarisOpname(iu);
    });

    // Baris kosong tambahan (kalau petugas menemukan lebar baru hasil potong)
    tambahBarisOpname(list, lebarArr, rollLen);
    hitungOpnameTotal();

    list.querySelectorAll('.opn-utuh, .opn-sisa').forEach(function (inp) {
        inp.addEventListener('input', function () { sinkronBarisOpname(this); hitungOpnameTotal(); });
    });
}

/** Angka gaya Indonesia tanpa nol berekor. */
function fmtOpn(v) {
    var n = Number(v || 0);
    return n.toLocaleString('id-ID', { maximumFractionDigits: 2 });
}

/**
 * Hitung ulang total 1 baris opname dari (roll utuh × panjang roll) + sisa terbuka,
 * lalu simpan hasilnya ke input tersembunyi `.opn-mtr` yang dikirim ke server.
 */
function sinkronBarisOpname(el) {
    var row = el.closest ? el.closest('.opn-baris') : null;
    if (!row) return;
    var rollLen = parseFloat(row.getAttribute('data-roll-len') || '0') || 0;
    var iu = row.querySelector('.opn-utuh');
    var is = row.querySelector('.opn-sisa');
    var im = row.querySelector('.opn-mtr');

    var utuh = parseFloat(String(iu ? iu.value : '').replace(',', '.'));
    var sisa = parseFloat(String(is ? is.value : '').replace(',', '.'));
    var kosongSemua = (!iu || String(iu.value).trim() === '') && (!is || String(is.value).trim() === '');
    if (isNaN(utuh) || utuh < 0) utuh = 0;
    if (isNaN(sisa) || sisa < 0) sisa = 0;

    var total = utuh * rollLen + sisa;
    total = Math.round(total * 1000) / 1000;

    if (im) im.value = kosongSemua ? '' : total;

    var out = row.querySelector('.opn-total');
    if (out) {
        /* Baris BARU yang belum diisi → tampilkan "—" (bukan 0), supaya jelas
         * bahwa baris itu belum dipakai dan tidak ikut dihitung. */
        if (kosongSemua) { out.innerHTML = '= <b>—</b>'; return; }
        var badge = out.querySelector('b');
        if (badge) badge.textContent = fmtOpn(total) + ' m';
        /* Bandingkan langsung dengan sistem supaya selisih terlihat SEKARANG,
         * bukan setelah disimpan — petugas bisa langsung periksa ulang. */
        var sysV = parseFloat(out.getAttribute('data-total') || '0') || 0;
        var beda = Math.round((total - sysV) * 100) / 100;
        out.innerHTML = '= <b>' + fmtOpn(total) + ' m</b>'
            + (Math.abs(beda) < 0.01
                ? ' <span class="opn-sama">sama</span>'
                : ' <span class="opn-beda">' + (beda > 0 ? '+' : '−') + fmtOpn(Math.abs(beda)) + ' m</span>');
    }
}

function tambahBarisOpname(list, lebarArr, rollLen) {
    rollLen = Number(rollLen || 0);
    for (var i = 0; i < OPN_LEBAR_MAKS - lebarArr.length; i++) {
        var row = document.createElement('div');
        row.className = 'opn-baris opn-baris-baru';
        row.setAttribute('data-roll-len', rollLen);
        // Baris BARU: hanya dihitung kalau LEBAR dan minimal satu angka diisi.
        // Strukturnya sengaja SAMA dengan baris tetap (utuh + sisa) supaya
        // petugas tidak berpikir beda untuk lebar yang baru ditemukan.
        row.innerHTML = '<input type="text" class="form-input form-input-sm opn-mtr-lebar" inputmode="decimal" placeholder="lebar">'
                      + '<div class="opn-sistem"><span class="text-faint text-xs">baru</span><br>—</div>'
                      + '<div class="opn-inp">'
                      +   '<span class="opn-inp-lbl">utuh</span>'
                      +   '<input type="text" class="form-input form-input-sm opn-utuh" inputmode="numeric" placeholder="0">'
                      +   '<span class="opn-inp-unit">roll</span>'
                      +   '<span class="opn-inp-lbl">+ sisa</span>'
                      +   '<input type="text" class="form-input form-input-sm opn-sisa" inputmode="decimal" placeholder="0">'
                      +   '<span class="opn-inp-unit">m</span>'
                      +   '<div class="opn-total">= <b>—</b></div>'
                      + '</div>'
                      + '<input type="hidden" class="opn-mtr" value="">';
        list.appendChild(row);
        row.querySelectorAll('.opn-utuh, .opn-sisa').forEach(function (inp) {
            inp.addEventListener('input', function () { sinkronBarisOpname(this); hitungOpnameTotal(); });
        });
    }
}

function hitungOpnameTotal() {
    var list = document.getElementById('opnPerLebarList');
    var out  = document.getElementById('opnPerLebarTotal');
    if (!list || !out) return;
    var totalM = 0, n = 0, adaBaruSetengah = false;
    list.querySelectorAll('.opn-baris').forEach(function (row) {
        var m = row.querySelector('.opn-mtr');
        if (!m) return;
        var lEl = row.querySelector('.opn-mtr-lebar');
        if (lEl) {
            // Baris BARU: butuh lebar DAN total > 0
            var lv = parseFloat(String(lEl.value).replace(',', '.'));
            var mv = parseFloat(String(m.value).replace(',', '.'));
            if (isNaN(lv)) { if (lEl.value.trim() !== '') adaBaruSetengah = true; return; }
            if (isNaN(mv) || m.value.trim() === '') { adaBaruSetengah = true; return; }
            totalM += mv; n++;
        } else {
            // Baris LEBAR TETAP: 0 = lebar habis (dihitung, tidak diabaikan)
            var mv2 = parseFloat(String(m.value).replace(',', '.'));
            if (isNaN(mv2)) return;
            totalM += mv2; n++;
        }
    });
    var selEl = document.getElementById('opnProduct');
    var dJs = (selEl && window.STOCK_DATA) ? window.STOCK_DATA[selEl.value] : null;
    var sistem = Number((dJs && dJs.meters) || 0);
    var beda = Math.round((totalM - sistem) * 100) / 100;
    out.innerHTML = n
        ? '<i class="fas fa-calculator"></i> Total sisa nyata: <b>' + fmtOpn(totalM) + ' meter</b> dari ' + n + ' lebar'
          + '<br><span class="text-faint">Stok sistem: ' + fmtOpn(sistem) + ' m</span>'
          + (Math.abs(beda) < 0.01
              ? ' · <span class="opn-sama">cocok</span>'
              : ' · <span class="opn-beda">selisih ' + (beda > 0 ? '+' : '−') + fmtOpn(Math.abs(beda)) + ' m</span>')
          + (adaBaruSetengah ? ' <span class="text-warning">(ada baris lebar baru yang belum lengkap — diabaikan)</span>' : '')
        : '<i class="fas fa-calculator"></i> Isi <b>jumlah roll utuh</b> dan <b>sisa meter</b> tiap lebar (isi 0 bila lebar itu habis).';
}

/* Tombol Simpan di modal opname: arahkan ke form yang benar
 * (per lebar bila bertingkat, satu angka bila tidak). */
document.addEventListener('DOMContentLoaded', function () {
    var tombol = document.getElementById('opnTombol');
    var formFix = document.getElementById('formOpnPerLebar');
    var fisikWrap = document.getElementById('opnFisikWrap');
    if (!tombol || !formFix) return;

    tombol.addEventListener('click', function (e) {
        var bertingkat = fisikWrap && fisikWrap.style.display === 'none';
        if (!bertingkat) return;   // biarkan form utama yang dikirim

        e.preventDefault();

        var list = document.getElementById('opnPerLebarList');
        var inputs = document.getElementById('opnFixInputs');
        var sel = document.getElementById('opnProduct');
        if (!list || !inputs || !sel) return;

        var isi = '';
        var n = 0;
        list.querySelectorAll('.opn-baris').forEach(function (row) {
            var lEl = row.querySelector('.opn-mtr-lebar');
            var mEl = row.querySelector('.opn-mtr');
            if (!mEl) return;

            var lebarVal;
            if (lEl) {
                lebarVal = parseFloat(String(lEl.value).replace(',', '.'));
                if (isNaN(lebarVal)) return;
            } else {
                var badge = row.querySelector('.opn-lebar b');
                if (!badge) return;
                lebarVal = parseFloat(String(badge.textContent).replace(',', '.'));
                if (isNaN(lebarVal)) return;
            }
            var meterVal = parseFloat(String(mEl.value).replace(',', '.'));
            if (isNaN(meterVal) || meterVal < 0) return;

            isi += '<input type="hidden" name="fix_width[]" value="' + lebarVal + '">'
                 + '<input type="hidden" name="fix_meter[]" value="' + meterVal + '">';
            n++;
        });

        if (n === 0) {
            alert('Isi sisa nyata minimal satu lebar.\nIsi 0 bila lebar itu sudah habis.');
            return;
        }
        var totalMeter = 0;
        list.querySelectorAll('.opn-mtr').forEach(function (m) {
            var v = parseFloat(String(m.value).replace(',', '.'));
            if (!isNaN(v) && v > 0) totalMeter += v;
        });
        if (!confirm('Sesuaikan stok menjadi ' + totalMeter.toLocaleString('id-ID') + ' meter dari ' + n + ' lebar?\n\n'
                   + 'Roll akan disusun ulang sesuai hitungan ini, dan selisihnya dicatat di kartu stok.')) return;

        inputs.innerHTML = isi;
        document.getElementById('opnFixProduct').value = sel.value;
        document.getElementById('opnFixNote').value =
            (document.querySelector('input[name="opname_note"]') || {}).value || 'penyesuaian per lebar';
        formFix.submit();
    });
});

/* Ganti produk di dropdown opname → perbarui angka sistem + satuannya.
   Tanpa ini, memilih produk lain membuat angka & satuan tetap dari produk
   sebelumnya → petugas mengisi angka dengan pembanding yang salah. */
(function bindOpnameSelect() {
    var sel = document.getElementById('opnProduct');
    if (!sel) return;
    function sinkron() {
        var opt = sel.options[sel.selectedIndex];
        if (!opt) return;
        var satuan = opt.getAttribute('data-unit') || 'meter';
        var sistem = parseFloat(opt.getAttribute('data-sistem') || '0') || 0;
        var el = document.getElementById('opnSystem');
        if (el) el.textContent = sistem.toLocaleString('id-ID');
        var su = document.getElementById('opnSystemUnit');
        if (su) su.textContent = satuan;
        var lbl = document.getElementById('opnUnitLabel');
        if (lbl) lbl.textContent = satuan;
        pasangOpnameBertingkat(sel.value, satuan);
    }
    sel.addEventListener('change', sinkron);
    sinkron();
})();

window.openStockEmpty = function (pid, name, qty, unit) {
    if (pid) document.getElementById('empProduct').value = pid;
    var info = document.getElementById('empInfo');
    if (info) {
        info.innerHTML = '<b>' + (name || '') + '</b> — stok sekarang <b>'
            + Number(qty || 0).toLocaleString('id-ID') + ' ' + (unit || '') + '</b>.<br>'
            + 'Tandai habis saat benar-benar habis, lalu catat restock berikutnya. '
            + 'Sistem akan menghitung <b>tarif pemakaian nyata</b> dari selisih waktu itu.';
    }
    window.openModal('stock-empty');
};

window.openStockCost = function (pid, name, cost, unit) {
    if (pid) document.getElementById('costProduct').value = pid;
    var input = document.getElementById('costValue');
    input.value = cost > 0 ? String(Math.round(cost)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
    var lbl = document.getElementById('costUnitLabel');
    if (lbl) lbl.textContent = unit || 'roll';
    window.openModal('stock-cost');
};

/* =====================================================================
 * TERIMA STOK — TAB "BEBERAPA LEBAR" (hasil potong memanjang / slit)
 * =====================================================================
 * Roll lebar 3,2 m dipotong memanjang menjadi 2,1 m / 1,6 m / 1,1 m supaya
 * saat mencetak ukuran kecil (mis. 1x1 m) sisanya tidak terbuang banyak.
 * Jadi yang MASUK GUDANG adalah roll hasil potongan, bukan roll aslinya.
 * =================================================================== */
(function initRcvGrup() {
    var list = document.getElementById('rcvGrupList');
    if (!list) return;

    var bolehHarga = <?= $bolehModal ? 'true' : 'false' ?>;

    function barisHtml(w, n, c) {
        var h = '<div class="rcv-grup">'
              + '<input type="text" class="form-input form-input-sm g-lebar" name="group_width[]" inputmode="decimal" placeholder="3.2" value="' + (w || '') + '">'
              + '<input type="text" class="form-input form-input-sm g-jml" name="group_count[]" inputmode="decimal" placeholder="2" value="' + (n || '') + '">';
        if (bolehHarga) {
            h += '<input type="text" class="form-input form-input-sm g-harga" name="group_cost[]" inputmode="numeric" placeholder="1.456.000" value="' + (c || '') + '">';
        }
        h += '</div>';
        return h;
    }

    function pasang() {
        list.querySelectorAll('.g-harga').forEach(function (inp) {
            inp.addEventListener('input', function () {
                var d = this.value.replace(/\D/g, '');
                this.value = d ? Number(d).toLocaleString('id-ID') : '';
            });
        });
        list.querySelectorAll('.g-lebar, .g-jml').forEach(function (inp) {
            inp.addEventListener('input', hitung);
        });
        hitung();
    }

    function hitung() {
        var out = document.getElementById('rcvGrupTotal');
        if (!out) return;
        var roll = 0, lebar = [];
        list.querySelectorAll('.rcv-grup').forEach(function (row) {
            var w = parseFloat(String(row.querySelector('.g-lebar').value).replace(',', '.'));
            var n = parseFloat(String(row.querySelector('.g-jml').value).replace(',', '.'));
            if (!isNaN(w) && !isNaN(n) && n > 0) { roll += n; lebar.push(w + 'm×' + n); }
        });
        var panjang = parseFloat(String((document.getElementById('rcvLengthBanyak') || {}).value || '0').replace(',', '.'));
        out.innerHTML = roll > 0
            ? '<i class="fas fa-calculator"></i> Total <b>' + roll + ' roll</b> (' + lebar.join(' + ') + ')'
              + (panjang > 0 ? ' = <b>' + (roll * panjang).toLocaleString('id-ID') + ' meter</b>' : '')
            : '<i class="fas fa-scissors"></i> Isi lebar (mis. 3.2 / 2.1 / 1.6 / 1.1) dan jumlah roll tiap lebar.';
    }

    // Tiga baris awal
    list.innerHTML = barisHtml('', '', '') + barisHtml('', '', '') + barisHtml('', '', '');
    pasang();

    var tambah = document.getElementById('rcvTambahBaris');
    if (tambah) tambah.addEventListener('click', function () {
        list.insertAdjacentHTML('beforeend', barisHtml('', '', ''));
        pasang();
    });

    var lenInp = document.getElementById('rcvLengthBanyak');
    if (lenInp) lenInp.addEventListener('input', hitung);

    // Pindah tab Satu Lebar ↔ Beberapa Lebar
    document.querySelectorAll('.rcv-tab-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            var tab = this.getAttribute('data-rcv-tab');
            document.querySelectorAll('.rcv-tab-btn').forEach(function (x) { x.classList.remove('active'); });
            this.classList.add('active');
            document.getElementById('formRcvSatu').style.display   = tab === 'satu'   ? '' : 'none';
            document.getElementById('formRcvBanyak').style.display = tab === 'banyak' ? '' : 'none';
        });
    });
})();

document.addEventListener('DOMContentLoaded', function () {
    // Tutup modal
    document.querySelectorAll('[data-close-modal]').forEach(function (el) {
        el.addEventListener('click', function () { window.closeModal(el.getAttribute('data-close-modal')); });
    });
    /* DIHAPUS 12 Okt 2026 — jangan dihidupkan lagi.
       Dulu di sini ada listener change KEDUA pada #opnProduct yang menulis
       angka dari window.STOCK_DATA[pid].meters. Itu BUKAN sumber yang sama
       dengan server (server pakai stock_total_meters() untuk roll dan
       stock_qty untuk non-roll), dan STOCK_DATA tidak memuat sisa untuk
       produk non-roll → nilainya 0.
       Akibat nyata (terukur 12 Okt 2026): 10 dari 17 produk menampilkan
       "Stok menurut sistem: 0" padahal aslinya berisi —
       Y Banner 86 pcs, 5 Tinta DTF @1 botol, 4 Tinta Spanduk @1 jergen.
       Owner yang mempercayai angka 0 itu akan MENGHAPUS 86 pcs Y Banner.
       Sekarang angka hanya diisi oleh bindOpnameSelect() yang membaca
       data-sistem — sumber yang sama dengan teks di dropdown & server. */
    // Format titik ribuan pada input rupiah
    document.querySelectorAll('input[name="cost_per_roll"]').forEach(function (el) {
        el.addEventListener('input', function () {
            var v = this.value.replace(/[^\d]/g, '');
            this.value = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        });
    });
});
</script>
