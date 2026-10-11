<?php
/**
 * includes/stock.php
 * =====================================================================
 * STOK BAHAN (KARTU STOK) — Creative Geek
 * =====================================================================
 * Semua pergerakan stok bahan WAJIB lewat stock_ledger_add().
 * Itulah satu-satunya pintu masuk (single gate) sehingga tidak mungkin
 * ada perubahan stok tanpa jejak catatan → anti kecurangan.
 *
 * Satuan kanonik = METER LINIER (meter jalan roll).
 *
 * Model data:
 *   stock_movements  = buku besar (sumber kebenaran, tidak dihapus)
 *   stock_rolls      = pendataan per roll fisik
 *   product_prices.stock_rolls / stock_meters = CACHE saldo (dibaca
 *                      halaman lama products.php)
 *
 * Aturan konversi ke cache (mengikuti cara products.php membaca):
 *   stock_rolls  (cache) = jumlah roll yang BELUM dibuka (utuh)
 *   stock_meters (cache) = sisa meter pada roll yang SEDANG terbuka
 *   total_meter          = stock_rolls * roll_length + stock_meters
 * =====================================================================
 */

if (!function_exists('stock_cut_mode')) {

/** Mode hitung pemakaian bahan: 'smart' (efisien) atau 'linear' (sisi terpanjang). */
function stock_cut_mode(): string
{
    $m = getSetting('stock_cut_mode', 'smart');
    return in_array($m, ['smart', 'linear'], true) ? $m : 'smart';
}

/**
 * LABEL KATEGORI BAHAN/PRODUK untuk ditampilkan ke pengguna.
 * =====================================================================
 * KENAPA PERLU: kategori `x-banner` menaungi DUA tiang berbeda (X dan Y)
 * dengan harga & modal berbeda. Kalau ditampilkan apa adanya sebagai
 * "X-banner" (huruf kecil + tanda hubung, persis nilai di database), operator
 * mengira Y Banner belum terdaftar — padahal produknya ada tepat di bawah label
 * itu. Label harus seragam di SEMUA halaman supaya tidak ada dua nama untuk
 * hal yang sama.
 *
 * PENTING: ini HANYA untuk tampilan. Nilai kategori di database TETAP
 * 'x-banner' karena dipakai belasan tempat untuk logika pemotongan stok
 * (mis. `$category === 'x-banner'` di order_form) — mengubah nilainya akan
 * merusak potong stok. Jadi: nilai tetap, tampilan dipercantik.
 */
function stock_label_kategori(string $kategori): string
{
    static $peta = [
        'spanduk'  => 'Spanduk',
        'baliho'   => 'Baliho',
        'sticker'  => 'Stiker',
        'x-banner' => 'X / Y Banner',
        'sablon'   => 'Sablon',
        'undangan' => 'Undangan',
        'kartu'    => 'Kartu Nama',
        'poster'   => 'Poster',
    ];
    $k = strtolower(trim($kategori));
    return $peta[$k] ?? ucfirst($kategori);
}

/**
 * Nama lengkap bahan untuk tampilan: "Label Kategori + Varian".
 * =====================================================================
 * KHUSUS `x-banner` diperlakukan berbeda — dan ini penting.
 *
 * Kategori `x-banner` menaungi dua produk dengan varian "X 60x160 cm" dan
 * "Y 60x160 cm". Kalau digabung apa adanya, hasilnya jadi
 *   "X / Y Banner Y 60x160 cm"
 * yang berulang dan justru membingungkan: label kategorinya menyebut X DAN Y,
 * padahal baris itu adalah satu produk yang pasti (Y saja).
 *
 * Untuk daftar produk & dropdown, yang dibutuhkan adalah nama PRODUK yang
 * menyebut tiang dengan PASTI — dan variannya sudah memuat huruf itu. Jadi:
 *   varian "X 60x160 cm" → "X Banner 60x160 cm"
 *   varian "Y 60x160 cm" → "Y Banner 60x160 cm"
 * Susunannya sengaja dibuat SAMA dengan nama produk yang tersimpan di order &
 * nota ("X Banner" / "Y Banner"), supaya operator melihat nama yang sama di
 * mana pun dan tidak perlu menerjemahkan sendiri.
 *
 * Label kategori "X / Y Banner" tetap dipakai untuk badge kategori, judul
 * grup, dan dropdown kategori — di situ memang benar menyebut keduanya.
 */
function stock_nama_tampil(array $produk): string
{
    $kategori = (string)($produk['category'] ?? '');
    $varian   = trim((string)($produk['variant'] ?? ''));

    if (strtolower(trim($kategori)) === 'x-banner' && $varian !== '') {
        $awal = strtoupper(mb_substr($varian, 0, 1));
        if ($awal === 'X' || $awal === 'Y') {
            // Buang huruf awal dari varian, lalu susun ulang sebagai nama produk
            $sisa = trim(mb_substr($varian, 1));
            return $awal . ' Banner' . ($sisa !== '' ? ' ' . $sisa : '');
        }
    }

    return stock_label_kategori($kategori) . ($varian !== '' ? ' ' . $varian : '');
}

/** Tambahan pinggir (bleed) per sisi, dalam meter. Ikut dihitung pemakaian. */
function stock_margin_m(): float
{
    $cm = (float)str_replace(',', '.', (string)getSetting('stock_margin_cm', '0'));
    return $cm > 0 ? $cm / 100 : 0.0;
}

/**
 * Hitung pemakaian bahan (meter linier) untuk 1 potong ukuran W x H.
 *
 * Roll selebar `$rollW`. Banner diletakkan melintang pada lebar roll,
 * sehingga panjang yang dihabiskan dari roll = sisi lainnya.
 *
 *   mode 'smart'  : pilih orientasi paling hemat (butuh sisi ≤ lebar roll).
 *                   Contoh roll 3m, pesanan 2x1  → habis 1 m  (1 ketemu 2 ≤ 3)
 *   mode 'linear' : ambil sisi terpanjang (= asumsi lama products.php)
 *                   Contoh roll 3m, pesanan 2x1  → habis 2 m
 *
 * Mengembalikan null bila ukuran TIDAK MUAT di lebar roll (perlu panel sambung).
 */
function stock_meters_for_size(float $w, float $h, float $rollW, ?string $mode = null): ?float
{
    $plan = stock_cut_plan($w, $h, $rollW, $mode);
    return $plan ? (float)$plan['meters'] : null;
}

/**
 * RENCANA POTONG 1 lembar ukuran W x H pada roll selebar $rollW.
 * =====================================================================
 * PERBAIKAN 12 Okt 2026 (fix 1a + 1c). Mengembalikan:
 *
 *   meters = panjang yang HABIS dari roll (meter jalan)
 *   across = sisi yang dibentangkan MELINTANG
 *            → inilah LEBAR ROLL MINIMUM yang dibutuhkan
 *
 * `across` yang dulu tidak pernah dihitung itulah penyebab bug nyata:
 *   cetakan 3x5 (butuh lebar 3,1 m) dipotong dari roll Flexy 1,1 m, dan
 *   meter-nya selalu dihitung memakai lebar produk (3,2 m) walau roll yang
 *   benar-benar dipotong lebih sempit. Akibatnya pemakaian bahan salah
 *   selama berbulan-bulan.
 *
 * Ini SATU-SATUNYA tempat aturan orientasi ditulis, supaya angka di form
 * order, perhitungan potong, dan laporan stok tidak pernah berbeda.
 *
 * Mengembalikan null bila ukuran tidak muat (perlu sambung panel).
 */
function stock_cut_plan(float $w, float $h, float $rollW, ?string $mode = null): ?array
{
    $mode = $mode ?? stock_cut_mode();
    $m    = stock_margin_m();
    $ew   = $w > 0 ? $w + 2 * $m : 0.0;   // ukuran efektif + pinggir
    $eh   = $h > 0 ? $h + 2 * $m : 0.0;
    if ($ew <= 0 && $eh <= 0) return null;
    if ($ew <= 0) $ew = $eh;
    if ($eh <= 0) $eh = $ew;

    if ($mode === 'linear' || $rollW <= 0) {
        return ['meters' => round(max($ew, $eh), 3), 'across' => round(min($ew, $eh), 3)];
    }
    $fitW = $ew <= $rollW;   // sisi W muat melintang di lebar roll?
    $fitH = $eh <= $rollW;
    if ($fitW && $fitH) {
        return ['meters' => round(min($ew, $eh), 3), 'across' => round(max($ew, $eh), 3)];
    }
    if ($fitW) return ['meters' => round($eh, 3), 'across' => round($ew, 3)];
    if ($fitH) return ['meters' => round($ew, 3), 'across' => round($eh, 3)];
    return null; // tidak muat — perlu sambung panel
}

/** Ambil baris produk dari product_prices. */
function stock_product(int $productId): ?array
{
    if ($productId <= 0) return null;
    $st = db()->prepare("SELECT * FROM product_prices WHERE id = ?");
    $st->execute([$productId]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Biaya per meter linier (modal). Prioritas: kolom manual → cost_per_roll/panjang → harga jual. */
function stock_cost_per_meter(array $p): float
{
    $manual = (float)($p['cost_per_meter'] ?? 0);
    if ($manual > 0) return round($manual, 2);

    $perRoll = (float)($p['cost_per_roll'] ?? 0);
    $len     = (float)($p['roll_length_m'] ?? 0);
    if ($perRoll > 0 && $len > 0) return round($perRoll / $len, 2);

    return 0.0;
}

/**
 * Biaya per meter linier UNTUK ROLL TERTENTU — dipakai menghitung HPP.
 * =====================================================================
 * 🔴 KENAPA WAJIB (bukti nyata 12 Okt 2026)
 *
 * `stock_cost_per_meter()` selalu memakai `cost_per_roll ÷ panjang` = **Rp 20.800/m**,
 * yaitu harga satu meter ROLL LEBAR PENUH (3,2 m). Itu benar HANYA bila bahan
 * yang dipotong memang selebar produk.
 *
 * Begitu roll digunting memanjang, satu meter strip 1,1 m hanya berisi
 * **1,1 m²** bahan, sedangkan satu meter roll 3,2 m berisi 3,2 m² — tiga kali
 * lebih banyak. Kalau keduanya dihargai Rp 20.800/m, HPP pekerjaan strip jadi
 * **~2,9× terlalu tinggi** dan laba yang dilaporkan terlalu kecil.
 *
 * Terukur pada order nyata (Okt 2026):
 *   Spanduk 1×1 m  jual Rp 40.000
 *     · HPP cara lama  : Rp 22.880 → laba dilaporkan Rp 17.120
 *     · HPP cara benar : Rp  7.865 → laba sebenarnya Rp 32.135  (hampir 2×)
 *
 * Rumus: `lebar roll yang DIPAKAI × harga per m²`.
 *   roll 3,2 m → 3,2 × 6.500 = 20.800/m  (sama dengan cara lama — memang benar)
 *   roll 1,1 m → 1,1 × 6.500 =  7.150/m  (inilah perbaikannya)
 *
 * @param float $rollWidth lebar roll yang benar-benar dipotong (0 = tidak diketahui)
 */
function stock_cost_per_meter_for_roll(array $p, float $rollWidth): float
{
    /* Prioritas utama harga per m²: `cost_per_meter` pada master roll adalah
     * harga untuk LEBAR PRODUK penuh (mis. 3,2 m), bukan harga semua strip.
     * Mengembalikan nilai manual sebelum melihat lebar akan menagih Rp20.800/m
     * juga untuk strip 1,6 m (seharusnya Rp10.400/m) dan 1,1 m (Rp7.150/m).
     * HPP lampau sudah dikoreksi dengan basis luas pada 12 Okt, jadi pemakaian
     * baru wajib konsisten dengan basis yang sama. */
    $perM2 = stock_cost_per_m2($p);
    if ($perM2 > 0 && $rollWidth > 0) return round($rollWidth * $perM2, 2);

    // Kalau ukuran/biaya per m² tidak tersedia, baru gunakan harga manual lama.
    $manual = (float)($p['cost_per_meter'] ?? 0);
    return $manual > 0 ? round($manual, 2) : stock_cost_per_meter($p);
}

/** Normalisasi nama untuk pencocokan: huruf kecil, hanya a-z & 0-9. */
function stock_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    return preg_replace('/[^a-z0-9]+/u', '', $s) ?? '';
}

/**
 * Cari produk product_prices yang cocok dengan nama item order.
 * Pencocokan memakai bentuk TERNORMALISASI (spasi & tanda hubung dibuang)
 * supaya "X Banner" cocok dengan produk berkategori "x-banner".
 *
 * Skor: kategori+varian cocok (3) > varian saja (2) > kategori saja (1).
 * Seri diputus dengan kategori terpanjang (lebih spesifik).
 */
function stock_match_product(string $itemName): ?array
{
    $name = stock_norm($itemName);
    if ($name === '') return null;

    static $cache = null;
    if ($cache === null) {
        $cache = db()->query("SELECT * FROM product_prices WHERE track_stock = 1 OR unit = 'roll'")->fetchAll();
    }

    /* ------------------------------------------------------------------
     * PENJAGA X/Y BANNER — 2026-10-09
     * ------------------------------------------------------------------
     * MASALAH NYATA: order "X Banner" dipotong dari produk **Y Banner**
     * (#18, modal Rp 27.000), padahal X Banner ada sendiri (#121, Rp 22.000).
     * Akibatnya modal x-banner kelebihan Rp 5.000/pcs SELAMA BERBULAN, dan
     * stok Y Banner berkurang padahal yang dipakai X Banner.
     *
     * SEBAB: X dan Y adalah DUA MODEL TIANG BERBEDA dengan harga berbeda,
     * tetapi keduanya berkategori "x-banner" sehingga skor pencocokan SAMA
     * dan produk yang lebih dulu ditemukan yang menang (urutan id).
     *
     * ATURAN: huruf X/Y di AWAL nama item harus menentukan produk mana yang
     * dipakai — bukan urutan id. Kalau item menyebut "Y", pakai varian Y;
     * kalau "X", pakai varian X. Kalau tidak menyebut (mis. "X Banner Duka"),
     * ikuti aturan lama supaya perilaku tidak berubah.
     * ------------------------------------------------------------------ */
    /* Huruf X/Y diambil dari nama ASLI (huruf kecil, spasi dirapikan),
     * BUKAN dari bentuk ternormalisasi — karena `stock_norm` membuang spasi
     * sehingga "Spanduk X Banner" menjadi "spandukxbanner" dan huruf X di
     * tengah kata tidak lagi bisa dikenali sebagai penanda model. */
    $hurufItem = '';
    $namaAsli  = mb_strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/u', ' ', $itemName) ?? ''));
    /* Huruf X/Y hanya dipercaya sebagai penanda MODEL kalau nama item memang
     * menyebut "banner". Tanpa syarat ini, nama spanduk biasa yang kebetulan
     * memuat huruf berdiri sendiri bisa salah dibaca sebagai X/Y Banner. */
    if (strpos($namaAsli, 'banner') !== false) {
        if (preg_match('/(?:^|\s)([xy])(?:\s|$|-?banner)/i', $namaAsli, $mh)) {
            $hurufItem = strtoupper($mh[1]);
        } elseif (preg_match('/^([xy])banner/i', $name, $mh2)) {
            $hurufItem = strtoupper($mh2[1]);
        }
    }

    $best = null; $bestScore = 0; $bestLen = 0;
    $bestXY = null; $bestXYScore = -1;   // kandidat yang hurufnya cocok
    foreach ($cache as $p) {
        $cat = stock_norm((string)$p['category']);
        $var = stock_norm((string)$p['variant']);
        $catHit = $cat !== '' && $var !== '' ? (strpos($name, $cat . $var) !== false) : false;
        $varHit = $var !== '' && strpos($name, $var) !== false;
        $catOnly = $cat !== '' && strpos($name, $cat) !== false;

        if ($catHit)        $score = 3;
        elseif ($varHit)    $score = 2;
        elseif ($catOnly)   $score = 1;
        else                $score = 0;

        /* Kandidat X/Y diperiksa SEBELUM penyaringan skor.
         *
         * MASALAH NYATA: "Spanduk Y Banner 0.6x1.6" dipotong dari bahan roll
         * Flexy (#29) padahal itu Y Banner (#18) — karena nama item tidak
         * memuat kata "xbanner" utuh ("spandukybanner..."), pencocokan kategori
         * gagal (skor 0), lalu `continue` melompati pemeriksaan huruf X/Y
         * sehingga produk Y yang benar TIDAK PERNAH dipertimbangkan.
         * Akibatnya stok tiang Y tidak berkurang dan yang terpotong malah
         * bahan spanduk — dua-duanya salah.
         *
         * PENTING: hanya kandidat dengan skor > 0 yang diterima di sini.
         * Kalau skor 0 ikut diterima, huruf pertama varian yang kebetulan X/Y
         * (mis. "Cleaner Spanduk Yaselan" → "Y") akan menang padahal produk
         * itu sama sekali tidak disebut di nama item. */
        if ($hurufItem !== '' && $score > 0) {
            $varLow = mb_strtoupper(mb_substr(trim((string)$p['variant']), 0, 1));
            if ($varLow === $hurufItem && $score > $bestXYScore) {
                $bestXYScore = $score;
                $bestXY = $p;
            }
        }

        if ($score === 0) continue;

        $len = $catOnly ? mb_strlen($cat) : 0;
        if ($score > $bestScore || ($score === $bestScore && $len > $bestLen)) {
            $bestScore = $score; $bestLen = $len; $best = $p;
        }
    }

    // Huruf X/Y yang cocok MENANG atas pilihan berdasarkan skor
    if ($bestXY !== null) return $bestXY;

    /* "X Banner" / "Y Banner" — nama PENDEK yang tidak memuat ukuran.
     *
     * Produk x-banner punya varian "X 60x160 cm" / "Y 60x160 cm", jadi nama
     * "Y Banner" TIDAK memuat potongan varian tersebut dan tidak pernah
     * menjadi kandidat. Akibatnya order yang ditulis "Y Banner" tidak
     * menemukan bahannya sama sekali.
     *
     * MASALAH TAMBAHAN (terbukti): operator menulis "Spanduk Y Banner 0.6x1.6".
     * Kata "spanduk" membuatnya cocok dengan bahan ROLL Flexy (#29) — padahal
     * itu Y Banner, produk TIANG (#18). Kalau kasus ini dibiarkan, order
     * kehilangan potongan tiang DAN bahan spanduknya ikut terpotong padahal
     * yang dihitung seharusnya tiang. Karena itu: begitu nama item memuat
     * "banner" beserta huruf X/Y yang berdiri sendiri, produk tiang-lah yang
     * dipakai — bukan bahan roll — selama produk tiang dengan huruf itu ada.
     *
     * Syarat huruf wajib "berdiri sendiri" supaya nama spanduk biasa tidak
     * salah dianggap x-banner hanya karena memuat huruf X atau Y di dalam kata.
     */
    $bestIsBanner = $best && strpos(stock_norm((string)$best['category']), 'banner') !== false;
    if (!$bestIsBanner && $hurufItem !== '' && strpos($namaAsli, 'banner') !== false) {
        foreach ($cache as $p) {
            if (strpos(stock_norm((string)$p['category']), 'banner') === false) continue;
            $v0 = mb_strtoupper(mb_substr(trim((string)$p['variant']), 0, 1));
            if ($v0 === $hurufItem) return $p;
        }
    }

    return $best;
}

/**
 * ★ SATU-SATUNYA PINTU perubahan stok ★
 *
 * Mendukung DUA mode satuan:
 *   - Satuan umum (`unit`/`qty`)  → jerjen/botol/pcs/lembar/roll
 *   - Meter linier (`meters`)     → khusus bahan roll (dipotong per meter)
 * Bila salah satu tidak diisi, yang lain disalin otomatis (roll: 1 roll =
 * panjang roll; satuan lain: 1:1). `stock_total_meters()` tetap bisa
 * mengonversi saldo satuan umum ke meter untuk pelaporan luas bahan.
 *
 * @param int         $productId  product_prices.id
 * @param string      $type       masuk|potong|rusak|koreksi|opname
 * @param float       $meters     meter linier (+ menambah saldo, − mengurangi)
 * @param array       $opt        reason, note, photo, ref_type, ref_id, roll_id,
 *                                unit_cost, created_by, skip_cache,
 *                                unit (satuan umum), qty (jumlah satuan umum)
 * @return array{ok:bool,movement_id?:int,balance?:float,error?:string}
 */
function stock_ledger_add(int $productId, string $type, float $meters, array $opt = []): array
{
    /* `pinjam` & `kembali` = bahan KELUAR karena dipinjam & MASUK karena
     * dikembalikan (mis. kaki x-banner yang dipinjam pelanggan lalu dibawa
     * balik). Sengaja jenis TERSENDIRI, bukan `potong`, karena:
     *   - bahan pinjaman akan KEMBALI → bukan pemakaian produksi
     *   - `stock_hpp_period()` menjumlah SEMUA `type='potong'` tanpa melihat
     *     `reason`, jadi memakai `potong` + reason 'pinjam' akan membuat
     *     pinjaman ikut terhitung sebagai HPP → laba tampak kecil palsu. */
    $allowed = ['masuk', 'potong', 'rusak', 'koreksi', 'opname', 'pinjam', 'kembali'];
    if (!in_array($type, $allowed, true)) {
        return ['ok' => false, 'error' => "Jenis pergerakan tidak dikenal: $type"];
    }
    $p = stock_product($productId);
    if (!$p) return ['ok' => false, 'error' => 'Produk tidak ditemukan'];

    // Normalisasi arah: potong/rusak/pinjam selalu negatif, masuk/kembali positif
    if (($type === 'potong' || $type === 'rusak' || $type === 'pinjam') && $meters > 0) $meters = -$meters;
    if (($type === 'masuk' || $type === 'kembali') && $meters < 0) $meters = -$meters;

    $unitCost  = isset($opt['unit_cost']) ? (float)$opt['unit_cost'] : stock_cost_per_meter($p);
    $totalCost = round($meters * $unitCost, 2);
    $rollsDl   = isset($opt['rolls_delta']) ? (float)$opt['rolls_delta'] : 0.0;

    /* ===== SATUAN UMUM (jerjen/botol/pcs/lembar) =====
     * Bahan non-roll tidak punya "meter". Kalau $opts['qty'] diberikan,
     * pakai itu sebagai nilai utama; kalau tidak, turunkan dari $meters. */
    $unit = (string)($opt['unit'] ?? ($p['unit'] ?? ''));
    $isRollUnit = in_array($unit, ['roll', 'meter'], true);
    $len = (float)($p['roll_length_m'] ?? 0);
    if (array_key_exists('qty', $opt) && $opt['qty'] !== null) {
        $qty = (float)$opt['qty'];
    } elseif ($isRollUnit && $len > 0) {
        $qty = round($meters / $len, 3);          // roll ⇄ meter
    } else {
        $qty = $meters;                            // 1:1 (botol, pcs, liter)
    }

    // Biaya: untuk satuan umum, harga beli biasanya PER satuan (per jergen).
    if (isset($opt['unit_cost']) && $opt['unit_cost'] !== null && !$isRollUnit && $qty != 0) {
        $totalCost = round($qty * (float)$opt['unit_cost'], 2);
    } else {
        // Roll: pakai harga per ROLL (presisi penuh) supaya total modal pasti tepat
        $perRoll = (float)($p['cost_per_roll'] ?? 0);
        if ($isRollUnit && $perRoll > 0 && $len > 0) {
            $perMeter  = $perRoll / $len;
            $unitCost  = round($perMeter, 2);
            $totalCost = round($meters * $perMeter, 2);
        }
    }

    /* PENTING: stock_ledger_add sering dipanggil DARI DALAM transaksi lain
     * (mis. order_form.php membungkus penyimpanan order dengan beginTransaction).
     * Kalau kita paksa beginTransaction lagi, PDO melempar "already an active
     * transaction" dan rollBack() di catch akan MENGHANCURKAN transaksi luar
     * (order ikut hilang). Jadi: hanya kelola transaksi kalau kita yang mulai. */
    $ownTx = false;
    try {
        if (!db()->inTransaction()) {
            db()->beginTransaction();
            $ownTx = true;
        }

        // Simpan dengan penanda anti-duplikat lewat SESSION (tidak butuh ALTER lagi)
        $ins = db()->prepare(
            "INSERT INTO stock_movements
               (product_id, roll_id, type, meters, rolls_delta, unit_cost, total_cost,
                reason, note, photo, ref_type, ref_id, created_by, qty, unit)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $productId,
            $opt['roll_id'] ?? null,
            $type,
            round($meters, 3),
            $rollsDl,
            $unitCost > 0 ? $unitCost : null,
            $unitCost > 0 ? $totalCost : null,
            isset($opt['reason']) ? mb_substr((string)$opt['reason'], 0, 60) : null,
            isset($opt['note'])   ? mb_substr((string)$opt['note'], 0, 250)   : null,
            $opt['photo'] ?? null,
            $opt['ref_type'] ?? 'manual',
            isset($opt['ref_id']) ? (int)$opt['ref_id'] : null,
            $opt['created_by'] ?? ($_SESSION['user_id'] ?? null),
            round($qty, 3),
            $unit !== '' ? mb_substr($unit, 0, 20) : null,
        ]);
        $movementId = (int)db()->lastInsertId();

        // Sinkronkan pendataan per-roll (bila menyangkut roll tertentu)
        if (!empty($opt['roll_id'])) {
            $sisaTerap = stock_roll_apply((int)$opt['roll_id'], $meters);

            /* ===== BAHAN TIDAK BOLEH HILANG SAAT PENGEMBALIAN =====
             * Kasus nyata 12 Okt 2026: koreksi 1b mengembalikan 13,70 m ke
             * roll 100 yang SUDAH PENUH (0/70 terpakai). `stock_roll_apply()`
             * memakai max(0, …) sehingga pengembalian itu MENGUAP tanpa pesan:
             * total roll jadi 717,48 m sedangkan buku besar 731,18 m (beda
             * 13,70 m ≈ Rp 89.050). Baru ketahuan karena sengaja dicocokkan.
             *
             * Sekarang: sisa yang tidak muat di roll itu DIPINDAH ke roll lain
             * yang LEBARNYA SAMA dan masih punya ruang. Kalau tidak ada, sisa
             * ditulis ke `stock_shortages` sebagai temuan agar kelihatan —
             * tidak pernah dibuang diam-diam. */
            if ($sisaTerap > 0.0001 && $meters > 0) {
                $stW = db()->prepare("SELECT width_m FROM stock_rolls WHERE id = ?");
                $stW->execute([(int)$opt['roll_id']]);
                $wRoll = (float)($stW->fetchColumn() ?: 0);
                $sisaPutar = $sisaTerap;
                if ($wRoll > 0) {
                    $stAlt = db()->prepare(
                        "SELECT id FROM stock_rolls
                          WHERE product_id = ? AND status = 'aktif'
                            AND ABS(width_m - ?) < 0.005
                            AND length_m - used_m > 0.001
                          ORDER BY (length_m - used_m) DESC, id");
                    $stAlt->execute([$productId, $wRoll]);
                    foreach ($stAlt->fetchAll(PDO::FETCH_COLUMN) as $altId) {
                        if ($sisaPutar <= 0.0001) break;
                        $sisaPutar = stock_roll_apply((int)$altId, $sisaPutar);
                    }
                }
                if ($sisaPutar > 0.0001) {
                    if (function_exists('writeLog')) {
                        writeLog('Pengembalian bahan ' . round($sisaPutar, 3) . ' m (produk #' . $productId
                               . ') TIDAK MUAT di roll selebar ' . $wRoll . ' m — perlu ditindak lanjut', 'WARNING');
                    }
                    if (function_exists('stock_log_shortage')) {
                        stock_log_shortage($productId, round($sisaPutar, 3), stock_total_meters($productId),
                            'pengembalian tidak muat di roll (perlu roll baru / opname)');
                    }
                }
            }
        }

        // Perbarui saldo cache
        if (empty($opt['skip_cache'])) {
            stock_recalc_cache($productId);
        }

        $balance = stock_total_meters($productId);

        db()->exec("UPDATE stock_movements SET balance_after = " . db()->quote(round($balance, 3)) . " WHERE id = $movementId");

        if ($ownTx) db()->commit();
        $balUnit = stock_balance_unit($productId);
        return ['ok' => true, 'movement_id' => $movementId, 'balance' => $balance,
                'qty' => $balUnit['qty'], 'unit' => $balUnit['unit']];
    } catch (Throwable $e) {
        if ($ownTx && db()->inTransaction()) db()->rollBack();
        if (function_exists('writeLog')) {
            writeLog("Gagal catat pergerakan stok produk #$productId ($type $meters m): " . $e->getMessage(), 'ERROR');
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Tambah/ubah pemakaian pada satu baris roll fisik. */
/**
 * Terapkan pemakaian/pengembalian ke SATU roll.
 *
 * @return float Sisa meter yang TIDAK berhasil diterapkan (0 = semua masuk).
 *               Untuk pemakaian ('potong') selalu 0 karena roll memang boleh
 *               dikurangi melebihi sisanya (jejaknya dicatat stok menipis).
 *               Untuk PENGEMBALIAN, sisa > 0 berarti roll sudah penuh —
 *               pemanggil WAJIB memindahkannya, jangan dibuang.
 */
function stock_roll_apply(int $rollId, float $meters): float
{
    $st = db()->prepare("SELECT * FROM stock_rolls WHERE id = ?");
    $st->execute([$rollId]);
    $r = $st->fetch();
    if (!$r) return abs($meters);

    $usedAwal = (float)$r['used_m'];
    if ($meters > 0) {
        // Pengembalian: tidak boleh melebihi panjang roll
        $used = max(0, $usedAwal - $meters);
        $tidakTerap = round(max(0, $meters - $usedAwal), 3);
    } else {
        $used = $usedAwal + abs($meters);
        $tidakTerap = 0.0;
    }

    $len    = (float)$r['length_m'];
    $status = (string)$r['status'];
    if ($len > 0 && $used >= $len - 0.001) {
        $used   = $len;
        $status = 'habis';
    } elseif ($status === 'habis' && $used < $len - 0.001) {
        $status = 'aktif';
    }

    db()->prepare("UPDATE stock_rolls SET used_m = ?, status = ? WHERE id = ?")
        ->execute([round($used, 3), $status, $rollId]);

    return $tidakTerap;
}

/**
 * Total meter tersedia untuk bahan ROLL.
 * =====================================================================
 * SUMBER KEBENARAN = baris `stock_rolls` (data fisik tiap roll).
 *   total = Σ (length_m − used_m) untuk roll yang masih ada.
 *
 * PENTING (perbaikan 2026-10-09): JANGAN menambahkan `product_prices.stock_meters`
 * di sini. Kolom itu adalah nilai TAMPILAN (sisa roll yang sedang terbuka) —
 * kalau ikut dijumlahkan, sisa roll parsial terhitung DUA KALI dan stok justru
 * BERTAMBAH setiap kali dipotong (bug terbukti: 560 m → 692 m).
 *
 * `stock_meters` hanya dipakai sebagai fallback bila produk TIDAK punya baris
 * roll sama sekali (produk lama / dikelola manual).
 */
function stock_total_meters(int $productId): float
{
    $st = db()->prepare("SELECT COUNT(*) FROM stock_rolls WHERE product_id = ?");
    $st->execute([$productId]);
    $adaRoll = (int)$st->fetchColumn() > 0;

    if ($adaRoll) {
        $st2 = db()->prepare(
            "SELECT COALESCE(SUM(GREATEST(length_m - used_m, 0)), 0)
               FROM stock_rolls WHERE product_id = ? AND status IN ('aktif','habis')"
        );
        $st2->execute([$productId]);
        return round((float)$st2->fetchColumn(), 3);
    }

    $st3 = db()->prepare("SELECT stock_meters FROM product_prices WHERE id = ?");
    $st3->execute([$productId]);
    return round((float)($st3->fetchColumn() ?: 0), 3);
}

/**
 * Saldo stok dalam SATUAN PRODUK (jerjen / botol / pcs / roll).
 * - Bahan roll  : diturunkan dari total meter ÷ panjang roll
 * - Satuan lain : dibaca dari product_prices.stock_qty
 */
function stock_balance_unit(int $productId): array
{
    $p = stock_product($productId);
    if (!$p) return ['qty' => 0.0, 'unit' => ''];

    $unit = (string)($p['unit'] ?? 'pcs');
    if (in_array($unit, ['roll', 'meter'], true)) {
        $len = (float)($p['roll_length_m'] ?? 0);
        $meters = stock_total_meters($productId);
        return ['qty' => $len > 0 ? round($meters / $len, 3) : $meters, 'unit' => $unit, 'meters' => $meters];
    }
    return ['qty' => (float)($p['stock_qty'] ?? 0), 'unit' => $unit];
}

/**
 * Hitung ulang nilai TAMPILAN (stock_rolls = jumlah roll utuh,
 * stock_meters = sisa meter pada roll yang sudah terbuka).
 * Sumber kebenaran tetap baris `stock_rolls`; kolom ini hanya untuk
 * tampilan halaman lama (products.php).
 */
function stock_recalc_cache(int $productId): void
{
    $p = stock_product($productId);
    if (!$p) return;

    // Roll UTUH = belum dipotong sama sekali (used_m ≈ 0)
    $stU = db()->prepare(
        "SELECT COUNT(*) FROM stock_rolls
          WHERE product_id = ? AND status = 'aktif' AND used_m <= 0.001"
    );
    $stU->execute([$productId]);
    $rollsUtuh = (int)$stU->fetchColumn();

    // Sisa meter pada roll yang SUDAH terbuka (parsial) → ditampilkan sebagai meter
    $stP = db()->prepare(
        "SELECT COALESCE(SUM(length_m - used_m), 0)
           FROM stock_rolls
          WHERE product_id = ? AND status = 'aktif' AND used_m > 0.001"
    );
    $stP->execute([$productId]);
    $partial = round((float)$stP->fetchColumn(), 3);

    db()->prepare("UPDATE product_prices SET stock_rolls = ?, stock_meters = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$rollsUtuh, $partial, $productId]);
}

/**
 * Terima roll baru (pembelian bahan).
 * $count roll seharga $costPerRoll masing-masing → otomatis jadi modal & HPP.
 */
function stock_receive_rolls(int $productId, float $count, float $widthM, float $lengthM,
                             float $costPerRoll = 0, array $opt = []): array
{
    $p = stock_product($productId);
    if (!$p) return ['ok' => false, 'error' => 'Produk tidak ditemukan'];
    $count = (int)round($count);
    if ($count <= 0) return ['ok' => false, 'error' => 'Jumlah roll harus lebih dari 0'];

    $stMax = db()->prepare("SELECT COALESCE(MAX(roll_no),0) FROM stock_rolls WHERE product_id = ?");
    $stMax->execute([$productId]);
    $next = (int)$stMax->fetchColumn() + 1;

    $newIds = [];
    $today  = $opt['received_at'] ?? date('Y-m-d');
    for ($i = 0; $i < $count; $i++) {
        db()->prepare(
            "INSERT INTO stock_rolls (product_id, roll_no, label, width_m, length_m, status, received_at, note, created_by)
             VALUES (?,?,?,?,?,'aktif',?,?,?)"
        )->execute([
            $productId, $next + $i,
            $opt['label'] ?? null,
            $widthM, $lengthM, $today,
            $opt['note'] ?? null,
            $_SESSION['user_id'] ?? null,
        ]);
        $newIds[] = (int)db()->lastInsertId();
    }

    // Update spesifikasi roll pada produk bila belum ada
    if ($widthM > 0 && (float)($p['roll_width_m'] ?? 0) <= 0) {
        db()->prepare("UPDATE product_prices SET roll_width_m = ? WHERE id = ?")->execute([$widthM, $productId]);
    }
    if ($lengthM > 0 && (float)($p['roll_length_m'] ?? 0) <= 0) {
        db()->prepare("UPDATE product_prices SET roll_length_m = ? WHERE id = ?")->execute([$lengthM, $productId]);
    }
    if ($costPerRoll > 0) {
        db()->prepare("UPDATE product_prices SET cost_per_roll = ?, cost_per_meter = ?, track_stock = 1, updated_at = NOW() WHERE id = ?")
            ->execute([$costPerRoll, $lengthM > 0 ? round($costPerRoll / $lengthM, 2) : null, $productId]);
    }

    $unitCost = $costPerRoll > 0 && $lengthM > 0 ? round($costPerRoll / $lengthM, 2) : stock_cost_per_meter($p);
    $res = stock_ledger_add($productId, 'masuk', $count * $lengthM, [
        'reason'      => $opt['reason'] ?? 'beli',
        'note'        => $opt['note'] ?? ($count . ' roll ' . rtrim(rtrim(number_format($widthM,2,'.',''),'0'),'.') . 'x' . rtrim(rtrim(number_format($lengthM,2,'.',''),'0'),'.') . 'm'),
        'rolls_delta' => $count,
        'unit_cost'   => $unitCost,
        'ref_type'    => 'manual',
    ]);

    if ($res['ok']) {
        $res['roll_ids'] = $newIds;
        $res['cost_total'] = round($count * $costPerRoll, 2);
        stock_recalc_cache($productId);
    }
    return $res;
}

/**
 * Daftar roll AKTIF sebuah produk, sudah dilengkapi lebar & sisa meter.
 *
 * @return array<int,array> baris stock_rolls + 'lebar' (m) + 'sisa' (m)
 */
function stock_rolls_available(int $productId): array
{
    $st = db()->prepare(
        "SELECT * FROM stock_rolls
          WHERE product_id = ? AND status = 'aktif' AND used_m < length_m - 0.001
          ORDER BY width_m ASC, roll_no ASC"
    );
    $st->execute([$productId]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$r) {
        $r['lebar'] = (float)$r['width_m'];
        $r['sisa']  = max(0.0, (float)$r['length_m'] - (float)$r['used_m']);
    }
    unset($r);
    return $rows;
}

/**
 * Pilih roll untuk memotong `$meters` meter, dengan syarat LEBAR MINIMUM.
 * =====================================================================
 * PERBAIKAN 12 Okt 2026 (fix 1a). Keluhan pemilik:
 *   "jika cetak 3x5 masa pakai bahan 2 kan ga mungkin, yang paling
 *    mendekati 3.2"  …  "ada order yang kadang kita gabung 2x1 gabung
 *    dengan 1x1 jadi kita cetak di ukuran 3.2"
 *
 * ATURAN:
 *   1. Roll yang LEBIH SEMPIT dari $needWidth TIDAK BOLEH dipakai.
 *      (Lebar 0 = data lama tanpa lebar → tidak boleh ikut diblokir.)
 *   2. Yang dipilih = lebar PALING MENDEKATI kebutuhan (paling sempit yang
 *      muat), supaya roll lebar tetap utuh untuk order besar / gabungan.
 *   3. Roll yang SISANYA CUKUP didahulukan. Bila tidak ada satu pun yang
 *      cukup, dipakai roll dengan sisa TERBANYAK dan `cukup` = false
 *      (pemanggil mencatat kekurangannya — bukan gagal diam-diam).
 *
 * @return array|null null bila tidak ada roll selebar itu (pemanggil harus
 *                    melaporkan, bukan memotong dari roll yang salah)
 */
function stock_pick_roll(int $productId, float $needWidth, float $meters): ?array
{
    $rolls = stock_rolls_available($productId);
    if (!$rolls) return null;

    $kandidat = [];
    foreach ($rolls as $r) {
        $rw = (float)$r['lebar'];
        if ($needWidth > 0 && $rw > 0.001 && $rw + 0.005 < $needWidth) continue;
        $kandidat[] = $r;
    }
    if (!$kandidat) return null;

    // (a) lebar paling mendekati kebutuhan → roll lebar disisakan
    // (b) roll yang sudah terbuka didahulukan (jangan buka roll baru sia-sia)
    usort($kandidat, function ($a, $b) {
        $aw = (float)$a['lebar']; $bw = (float)$b['lebar'];
        $ao = $aw <= 0.001 ? 9999.0 : $aw;   // tanpa info lebar → paling akhir
        $bo = $bw <= 0.001 ? 9999.0 : $bw;
        if (abs($ao - $bo) > 0.005) return $ao <=> $bo;
        $at = (float)$a['used_m'] > 0.001 ? 0 : 1;
        $bt = (float)$b['used_m'] > 0.001 ? 0 : 1;
        if ($at !== $bt) return $at <=> $bt;
        return (int)$a['roll_no'] <=> (int)$b['roll_no'];
    });

    foreach ($kandidat as $c) {
        if ((float)$c['sisa'] + 0.001 >= $meters) { $c['cukup'] = true; return $c; }
    }

    // Tidak ada yang cukup → pakai sisa terbanyak supaya kekurangan seminimal mungkin
    usort($kandidat, function ($a, $b) {
        if (abs((float)$a['sisa'] - (float)$b['sisa']) > 0.001) {
            return (float)$b['sisa'] <=> (float)$a['sisa'];
        }
        return (float)$a['lebar'] <=> (float)$b['lebar'];
    });
    $c = $kandidat[0];
    $c['cukup'] = false;
    return $c;
}

/**
 * Pilih roll + hitung meter untuk SATU item order (bahan roll bermeter).
 * =====================================================================
 * PERBAIKAN 12 Okt 2026 (fix 1a + 1c). Sebelumnya:
 *   - seluruh potongan Flexy dibebankan ke roll #1 (lebar 1,1 m) walau
 *     ukurannya mustahil (3x5 butuh lebar 3,1 m);
 *   - meter selalu dihitung memakai lebar PRODUK (3,2 m) walau roll yang
 *     dipotong lebih sempit → angka pemakaian bahan salah.
 *
 * ATURAN SEKARANG (keputusan pemilik 12 Okt 2026 — pilihan "b"):
 *   Pilih kombinasi (ROLL + ORIENTASI) yang **paling sedikit membuang bahan**,
 *   yaitu luas bahan terpakai terkecil = lebar_roll × meter_jalan.
 *
 *   KENAPA BUKAN "roll tersempit": keluhan pemilik membuktikan operator memang
 *   bebas memilih roll sesuai yang ada di lantai —
 *     "contoh 120x80 kadang admin pakai bahan 1.1m atau kadang pakai bahan 1.6m"
 *   dan pilihan itu WAJAR, karena luas terpakainya hampir sama:
 *     120x80 → 1,3x0,9 : roll 1,1 → 1,3 m jalan = 1,43 m²
 *                        roll 1,6 → 0,9 m jalan = 1,44 m²
 *   Hitungan "tersempit" justru kadang LEBIH BOROS:
 *     120x90 → 1,3x1,0 : roll 1,1 → 1,43 m² (hemat)
 *                        roll 1,6 → 1,60 m² (boros)
 *
 *   Efek sampingnya: kandidat dengan lebar hampir sama luasnya akan seri, dan
 *   seri diputus dengan memilih roll yang LEBIH SEMPIT supaya roll lebar tetap
 *   utuh — persis kebiasaan operator. Bila pilihan sistem tetap berbeda dari
 *   kenyataan, itu **bukan kesalahan**: koreksi otomatis dari RYPC (kotak cetak
 *   nyata) yang menyusul akan memindahkan potongan ke roll yang benar-benar
 *   dipakai (dipilih lebar TERDEKAT dengan kotak mesin).
 *
 *   Ukuran yang lebih lebar dari SEMUA roll tetap dicatat jujur sebagai
 *   potong-memanjang + sambung panel, supaya bahan yang terbuang terlihat.
 *
 * @return array|null null bila produk TIDAK punya data roll (pemanggil
 *                    memakai cara lama memakai roll_width_m produk)
 */
function stock_pick_roll_for_item(int $productId, float $w, float $h, float $qty): ?array
{
    $rolls = stock_rolls_available($productId);
    if (!$rolls) return null;

    $m  = stock_margin_m();
    $ew = $w > 0 ? $w + 2 * $m : 0.0;
    $eh = $h > 0 ? $h + 2 * $m : 0.0;
    if ($ew <= 0 && $eh <= 0) return null;
    if ($ew <= 0) $ew = $eh;
    if ($eh <= 0) $eh = $ew;

    $q = max(1, (float)$qty);

    /* Susun SEMUA kemungkinan: tiap roll × 2 orientasi.
     * Untuk tiap kemungkinan dihitung:
     *   panels   = berapa panel memanjang (1 bila muat sekaligus)
     *   per_cut  = meter jalan yang habis dari roll
     *   area     = lebar roll × meter jalan  → INI yang diminimalkan
     *   cukup    = sisa roll masih cukup untuk seluruh qty                       */
    $kandidat = [];
    foreach ($rolls as $r) {
        $R = (float)$r['lebar'];
        if ($R <= 0.001) continue;   // lebar tak diketahui → jangan dipakai memilih

        foreach ([[$ew, $eh], [$eh, $ew]] as $ori) {
            $across = (float)$ori[0];   // sisi yang dibentangkan melintang
            $jalan  = (float)$ori[1];   // sisi yang menjadi meter jalan
            if ($across <= 0 || $jalan <= 0) continue;

            $panel  = max(1, (int)ceil($across / $R - 0.0001));
            $perCut = round($jalan * $panel, 3);
            if ($perCut <= 0) continue;

            $kandidat[] = [
                'roll_id'   => (int)$r['id'],
                'roll_no'   => (int)$r['roll_no'],
                'width'     => round($R, 2),
                'panels'    => $panel,
                'per_cut'   => $perCut,
                'min_width' => round($R, 2),
                'area'      => round($R * $perCut, 4),
                'cukup'     => (float)$r['sisa'] + 0.001 >= $perCut * $q,
                'sisa_roll' => round((float)$r['sisa'], 3),
                'terbuka'   => (float)$r['used_m'] > 0.001,
            ];
        }
    }
    if (!$kandidat) return null;

    /* Urutan pilihan:
     *   1. roll yang sisanya CUKUP (jangan membebani roll yang hampir habis)
     *   2. jumlah PANEL paling sedikit → hindari sambungan
     *      (0,6x1,6 di roll 1,1 m sebenernya bisa 2 panel & lebih hemat 0,3 m²,
     *       TAPI hasilnya banner bersambung — itu BUKAN penghematan, itu cacat.
     *       Jadi sambungan selalu dihindari selama ada pilihan utuh.)
     *   3. buangan paling sedikit → area terkecil
     *   4. seri → roll lebih sempit (sisakan roll lebar)
     *   5. seri → roll yang sudah terbuka (jangan sobek roll baru)
     *   6. seri → nomor roll terkecil
     */
    usort($kandidat, function ($a, $b) {
        if ($a['cukup'] !== $b['cukup']) return $a['cukup'] ? -1 : 1;
        if ($a['panels'] !== $b['panels']) return $a['panels'] <=> $b['panels'];
        if (abs($a['area'] - $b['area']) > 0.0005) return $a['area'] <=> $b['area'];
        if (abs($a['width'] - $b['width']) > 0.005) return $a['width'] <=> $b['width'];
        if ($a['terbuka'] !== $b['terbuka']) return $a['terbuka'] ? -1 : 1;
        return $a['roll_no'] <=> $b['roll_no'];
    });

    $best = $kandidat[0];
    unset($best['terbuka']);
    $best['total'] = round($best['per_cut'] * $q, 3);
    return $best;
}

/** Laporkan bahan rusak / afalan dibuang. */
function stock_report_damage(int $productId, float $meters, string $reason, array $opt = []): array
{
    if ($meters <= 0) return ['ok' => false, 'error' => 'Meter rusak harus lebih dari 0'];
    // Kurangi dari roll terbuka lebih dulu (FIFO)
    $res = stock_consume($productId, $meters, [
        'type'    => 'rusak',
        'reason'  => $reason,
        'note'    => $opt['note'] ?? null,
        'photo'   => $opt['photo'] ?? null,
        'ref_type'=> 'manual',
    ]);
    return $res;
}

/**
 * Kurangi bahan dari roll aktif (FIFO: roll paling tua & yang sudah terbuka dulu).
 * Dipakai oleh potong order & lapor rusak.
 */
function stock_consume(int $productId, float $meters, array $opt = []): array
{
    $type = $opt['type'] ?? 'potong';
    if ($meters <= 0) return ['ok' => false, 'error' => 'Meter harus lebih dari 0'];

    $p = stock_product($productId);
    if (!$p) return ['ok' => false, 'error' => 'Produk tidak ditemukan'];
    $len = (float)($p['roll_length_m'] ?? 0);

    $total = stock_total_meters($productId);
    $enough = $total + 0.001 >= $meters;

    // Ambil roll aktif berurutan (yang sudah terbuka dulu, lalu paling tua)
    $st = db()->prepare(
        "SELECT * FROM stock_rolls
          WHERE product_id = ? AND status = 'aktif' AND used_m < length_m - 0.001
          ORDER BY (used_m <= 0.001) ASC, roll_no ASC"
    );
    $st->execute([$productId]);
    $rolls = $st->fetchAll();

    /* ===== PEMILIHAN ROLL (perbaikan 12 Okt 2026) =====================
     * Tiga kemungkinan, berurutan:
     *   1. Pemanggil sudah menentukan roll (`roll_id`) — dipakai oleh
     *      stock_consume_order_item() yang meternya memang sudah dihitung
     *      dari lebar roll tersebut (fix 1c). Roll yang sama WAJIB dipakai
     *      supaya angka meter & roll yang tercatat tidak berbeda.
     *   2. Pemanggil menyebut LEBAR MINIMUM (`min_width`) — roll yang lebih
     *      sempit DILARANG dipakai (fix 1a). Kalau tidak ada yang muat,
     *      LAPORKAN, jangan potong dari roll yang salah.
     *   3. Tanpa syarat apa pun → perilaku lama (roll terbuka lebih dulu,
     *      lalu yang paling tua) supaya laporan bahan rusak tidak berubah.
     * ================================================================= */
    $rollId  = null;
    $rollRow = null;

    if (!empty($opt['roll_id'])) {
        foreach ($rolls as $cand) {
            if ((int)$cand['id'] === (int)$opt['roll_id']) {
                $rollId  = (int)$cand['id'];
                $rollRow = $cand;
                break;
            }
        }
    }

    $minWidth = (float)($opt['min_width'] ?? 0);
    if ($rollId === null && $minWidth > 0) {
        $pick = stock_pick_roll($productId, $minWidth, $meters);
        if (!$pick) {
            $ada = [];
            foreach (stock_roll_summary_by_width($productId) as $s) {
                $ada[] = rtrim(rtrim(number_format((float)$s['width'], 2, ',', '.'), '0'), ',') . 'm';
            }
            $fmt = fn(float $v): string => rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
            return [
                'ok'         => false,
                'error'      => 'Tidak ada roll selebar ' . $fmt($minWidth) . ' m untuk memotong '
                                . $fmt($meters) . ' m'
                                . ($ada ? '. Lebar yang tersedia: ' . implode(', ', $ada) : '. Belum ada roll aktif.')
                                . ' — siapkan roll / slit dari roll lebar, atau sambung panel.',
                'need_width' => round($minWidth, 2),
                'available'  => $total,
            ];
        }
        $rollId  = (int)$pick['id'];
        $rollRow = $pick;
    }

    if ($rollId === null && $rolls) {
        // Pakai roll ini bila cukup, atau roll terakhir yang tersedia
        foreach ($rolls as $cand) {
            $s = (float)$cand['length_m'] - (float)$cand['used_m'];
            if ($s + 0.001 >= $meters) { $rollId = (int)$cand['id']; $rollRow = $cand; break; }
        }
        if ($rollId === null) { $rollId = (int)$rolls[0]['id']; $rollRow = $rolls[0]; }
    }

    /* Jejak audit: catat roll yang dipakai (lebar + nomor) pada catatan,
     * supaya saat opname pemilik bisa melihat bahan ini diambil dari roll mana.
     * Hanya ditambahkan bila roll dipilih OTOMATIS (1a) — bukan saat pemanggil
     * menyebut roll-nya sendiri (yang catatannya sudah lengkap). */
    $note = (string)($opt['note'] ?? '');
    if ($rollRow !== null && $minWidth > 0) {
        $tag = ' · roll #' . (int)$rollRow['roll_no'] . ' '
             . rtrim(rtrim(number_format((float)$rollRow['width_m'], 2, ',', '.'), '0'), ',') . 'm';
        if (mb_strlen($note . $tag, 'UTF-8') <= 250) $note .= $tag;
    }

    $res = stock_ledger_add($productId, $type, $meters, [
        'reason'   => $opt['reason'] ?? ($type === 'potong' ? 'produksi' : 'rusak'),
        'note'     => $note !== '' ? $note : null,
        'photo'    => $opt['photo'] ?? null,
        'ref_type' => $opt['ref_type'] ?? 'manual',
        'ref_id'   => $opt['ref_id'] ?? null,
        'roll_id'  => $rollId,
        /* 🔴 HPP per METER harus dihitung dari LEBAR ROLL YANG DIPAKAI
         * (perbaikan 12 Okt 2026 — lihat stock_cost_per_meter_for_roll()).
         * Dulu selalu stock_cost_per_meter() = harga roll lebar penuh 3,2 m,
         * sehingga pekerjaan dari strip 1,1 m dibebani ~2,9× terlalu mahal
         * dan laba yang dilaporkan jadi terlalu kecil (understate). */
        'unit_cost'=> $opt['unit_cost'] ?? (
            ($rollRow && (float)($rollRow['width_m'] ?? 0) > 0)
                ? stock_cost_per_meter_for_roll($p, (float)$rollRow['width_m'])
                : stock_cost_per_meter($p)
        ),
    ]);

    // Pemakaian melebihi stok → catat jejak audit (stok tetap dikurangi apa adanya)
    if (!empty($res['ok']) && !$enough) {
        stock_log_shortage($productId, $meters, $total, (string)($opt['note'] ?? ''));
    }

    return $res + [
        'short'      => !$enough,
        'available'  => $total,
        'roll_id'    => $rollId,
        'roll_no'    => $rollRow ? (int)$rollRow['roll_no'] : null,
        'roll_width' => $rollRow ? round((float)$rollRow['width_m'], 2) : null,
    ];
}

/**
 * Catat pemakaian bahan untuk 1 item order.
 * Dipanggil saat order disimpan/dicetak.
 *
 * @param int    $orderItemId  order_items.id (untuk penelusuran & pembatalan)
 * @param string $productName  nama item (dipakai mencocokkan produk bahan)
 * @param float  $qty          jumlah
 * @param float  $widthM       lebar (m)
 * @param float  $heightM      tinggi (m)
 */
/**
 * Catat pemakaian bahan untuk 1 item order — OTOMATIS memilih jalur:
 *   - bahan ROLL bermeter (spanduk, film) → dihitung dari ukuran, dipotong
 *     per METER (FIFO per roll)
 *   - bahan NON-ROLL (x-banner per pcs, tinta per botol) → dikurangi per SATUAN
 * Mengembalikan null bila item bukan bahan yang dilacak (mis. jasa sablon).
 *
 * @param string|null $sizeHint ukuran mentah bila ukuran tidak tersimpan (data lama)
 */
function stock_consume_order_item(int $orderItemId, string $productName, float $qty,
                                  float $widthM, float $heightM, int $orderId = 0,
                                  ?string $sizeHint = null): ?array
{
    $p = stock_match_product($productName);
    if (!$p) return null;

    $pid  = (int)$p['id'];
    $unit = (string)($p['unit'] ?? 'pcs');

    /* ===== BAHAN NON-ROLL: satuan umum (pcs / botol / jergen / lembar) ===== */
    if (!in_array($unit, ['roll', 'meter'], true)) {
        // track_stock wajib aktif supaya kita tidak asal memotong stok jasa
        if ((int)($p['track_stock'] ?? 0) !== 1) return null;
        if ($qty <= 0) return null;

        $res = stock_consume_qty($pid, $qty, 'produksi', [
            'note'     => $productName . ' (' . rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',') . ' ' . $unit . ')',
            'ref_type' => 'order',
            'ref_id'   => $orderItemId > 0 ? $orderItemId : ($orderId ?: null),
        ]);
        if ($res && !empty($res['ok'])) {
            $res['product_id']  = $pid;
            $res['product']     = $p['category'] . ' ' . $p['variant'];
            $res['meters_used'] = 0;
            $res['qty_used']    = $qty;
        }
        return $res;
    }

    /* ===== BAHAN ROLL: perlu ukuran (meter linier) ===== */
    $useW = $widthM; $useH = $heightM;
    if ($useW <= 0 && $useH <= 0 && $sizeHint !== null && $sizeHint !== '') {
        $sz = stock_parse_size_from_name($sizeHint);
        if ($sz) { $useW = $sz['w']; $useH = $sz['h']; }
    }
    if ($useW <= 0 && $useH <= 0) return null;

    /* ===== PILIH ROLL DULU, BARU HITUNG METER (fix 1a + 1c) ==========
     * Keluhan pemilik 12 Okt 2026:
     *   "jika cetak 3x5 masa pakai bahan 2 kan ga mungkin, yang paling
     *    mendekati 3.2"
     * Dulu meter dihitung dari LEBAR PRODUK (3,2 m) lalu dibebankan ke roll
     * apa pun yang tersedia — termasuk roll 1,1 m. Sekarang roll dipilih
     * lebih dulu (lebar paling mendekati kebutuhan), lalu meter dihitung
     * dari lebar roll itu.
     * Bila produk BELUM punya data roll → hitungan lama (lebar produk). */
    $pick   = stock_pick_roll_for_item($pid, $useW, $useH, $qty);
    $rollW  = $pick ? (float)$pick['width'] : (float)($p['roll_width_m'] ?? 0);
    $lebarProduk = $rollW;
    $perCut = $pick ? (float)$pick['per_cut'] : stock_meters_for_size($useW, $useH, $rollW);
    if ($perCut === null) return null; // ukuran tidak muat di lebar roll

    $totalMeters = round($perCut * max(1, $qty), 3);
    if ($totalMeters <= 0) return null;

    $catatan = $productName . ' (' . $qty . 'x)';
    if ($pick && (int)$pick['panels'] > 1) {
        $catatan .= ' · disambung ' . (int)$pick['panels'] . ' panel';
    }
    if ($pick && empty($pick['cukup'])) {
        $catatan .= ' · sisa roll kurang';
    }

    $res = stock_consume($pid, $totalMeters, [
        'type'      => 'potong',
        'reason'    => 'produksi',
        'note'      => $catatan,
        'ref_type'  => 'order',
        'ref_id'    => $orderItemId > 0 ? $orderItemId : ($orderId ?: null),
        'roll_id'   => $pick['roll_id']   ?? null,
        'min_width' => $pick['min_width'] ?? 0,
    ]);
    if ($res['ok']) {
        $res['product_id']  = $pid;
        $res['product']     = $p['category'] . ' ' . $p['variant'];
        $res['per_cut']     = $perCut;
        $res['meters_used'] = $totalMeters;
        $res['roll_no']     = $pick['roll_no'] ?? null;
        $res['roll_width']  = $lebarProduk;
    }
    return $res;
}

/**
 * Batalkan pemakaian bahan untuk sebuah order (dipakai saat order di-EDIT
 * atau DIBATALKAN). Mengembalikan meter ke roll asal & mencatat koreksi.
 */
function stock_reverse_order(int $orderId, string $note = 'order diubah/dibatalkan'): int
{
    if ($orderId <= 0) return 0;

    $st = db()->prepare(
        "SELECT sm.* FROM stock_movements sm
          WHERE sm.ref_type = 'order' AND sm.ref_id IN (
                SELECT oi.id FROM order_items oi WHERE oi.order_id = ?
          )"
    );
    $st->execute([$orderId]);
    $rows = $st->fetchAll();
    if (!$rows) {
        // Ref bisa tersimpan sebagai order_id (fallback) — coba langsung
        $st = db()->prepare("SELECT * FROM stock_movements WHERE ref_type='order' AND ref_id = ?");
        $st->execute([$orderId]);
        $rows = $st->fetchAll();
    }

    $n = 0;
    foreach ($rows as $m) {
        $meters = (float)$m['meters'];      // potong = negatif
        if ($meters >= 0) continue;         // hanya membalik pemakaian

        /* BAHAN BUKAN-ROLL (mis. tiang x-banner, satuan pcs).
         *
         * BUG NYATA (2026-10-09): pembatalan/penghapusan order x-banner TIDAK
         * mengembalikan stok tiang. Sebabnya `stock_ledger_add()` dipanggil
         * tanpa `unit`/`qty`, padahal untuk bahan non-roll saldo sesungguhnya
         * dibaca dari `product_prices.stock_qty` — bukan dari buku besar meter.
         * Akibatnya buku besar mencatat pengembalian, tetapi angka stok yang
         * dilihat operator TIDAK berubah (terbukti: jual 2 pcs → stok 86→84,
         * lalu order dihapus → stok tetap 84, tidak kembali 86).
         *
         * Perbaikan: untuk satuan non-roll, pengembalian dilakukan dengan
         * `stock_receive_qty()` (menambah stock_qty + buku besar sekaligus).
         * Untuk roll tetap lewat buku besar meter seperti semula. */
        $p = stock_product((int)$m['product_id']);
        $unitProd = (string)($p['unit'] ?? '');
        $isRollProd = in_array($unitProd, ['roll', 'meter'], true);

        if ($p && !$isRollProd) {
            /* Kembalikan sebesar pemakaian aslinya. `qty` di buku besar
               tersimpan negatif untuk pemakaian, jadi diambil nilai absolutnya.
               Kalau kolom qty kosong (baris lama), pakai `meters` sebagai
               perkiraan karena pada satuan umum keduanya identik. */
            $qtyBalik = abs((float)($m['qty'] ?? 0));
            if ($qtyBalik <= 0) $qtyBalik = abs($meters);
            if ($qtyBalik <= 0) continue;

            $res = stock_receive_qty((int)$m['product_id'], $qtyBalik, 0, [
                'reason'    => 'batal_order',
                'note'      => $note,
                'ref_type'  => 'order',
                'ref_id'    => (int)($m['ref_id'] ?? $orderId),
                'unit_cost' => $m['unit_cost'] !== null ? (float)$m['unit_cost'] : null,
            ]);
            if (!empty($res['ok'])) $n++;
            continue;
        }

        $res = stock_ledger_add((int)$m['product_id'], 'koreksi', abs($meters), [
            'reason'   => 'batal_order',
            'note'     => $note,
            'ref_type' => 'order',
            'ref_id'   => (int)($m['ref_id'] ?? $orderId),
            'roll_id'  => $m['roll_id'] ? (int)$m['roll_id'] : null,
            'unit_cost'=> $m['unit_cost'] !== null ? (float)$m['unit_cost'] : null,
        ]);
        if ($res['ok']) $n++;
    }
    return $n;
}

/**
 * Catat pemakaian bahan NON-ROLL (tinta, pcs, lembar).
 * Dikurangi dari product_prices.stock_qty + dicatat di buku besar.
 *
 * @param int    $productId
 * @param float  $qty        jumlah dalam satuan produk (mis. 1 botol)
 * @param string $reason     sebab singkat
 * @param array  $opt        note, photo, ref_type, ref_id, unit_cost
 * @param string $type       potong|rusak|koreksi
 */
function stock_consume_qty(int $productId, float $qty, string $reason = 'produksi',
                           array $opt = [], string $type = 'potong'): array
{
    if ($qty <= 0) return ['ok' => false, 'error' => 'Jumlah harus lebih dari 0'];
    $p = stock_product($productId);
    if (!$p) return ['ok' => false, 'error' => 'Produk tidak ditemukan'];

    $unit   = (string)($p['unit'] ?? 'pcs');
    $before = (float)($p['stock_qty'] ?? 0);
    $after  = $before - $qty;

    // Biaya dari harga beli per satuan (kalau ada)
    $unitCost = isset($opt['unit_cost']) ? (float)$opt['unit_cost']
        : (float)($p['cost_per_unit'] ?? 0);

    $res = stock_ledger_add($productId, $type, -$qty, [
        'reason'    => $reason,
        'note'      => $opt['note'] ?? null,
        'photo'     => $opt['photo'] ?? null,
        'ref_type'  => $opt['ref_type'] ?? 'manual',
        'ref_id'    => $opt['ref_id'] ?? null,
        'unit'      => $unit,
        'qty'       => -$qty,
        'unit_cost' => $unitCost > 0 ? $unitCost : null,
        // Satuan umum → JANGAN sentuh cache roll/meter
        'skip_cache'=> true,
    ]);

    if ($res['ok']) {
        db()->prepare("UPDATE product_prices SET stock_qty = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$after, $productId]);
        $res['qty_before'] = $before;
        $res['qty_after']  = $after;
        $res['short']      = $after < -0.001;
        $res['unit']       = $unit;

        // Pemakaian melebihi stok → biarkan MINUS (jujur) + catat jejak audit
        if ($res['short']) {
            stock_log_shortage($productId, $qty, $before,
                ($opt['note'] ?? '') . ' [' . rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',') . ' ' . $unit . ']');
        }
    }
    return $res;
}

/** Tambah saldo bahan NON-ROLL (pembelian tinta, x-banner, dll). */
function stock_receive_qty(int $productId, float $qty, float $costPerUnit = 0, array $opt = []): array
{
    if ($qty <= 0) return ['ok' => false, 'error' => 'Jumlah harus lebih dari 0'];
    $p = stock_product($productId);
    if (!$p) return ['ok' => false, 'error' => 'Produk tidak ditemukan'];

    $unit  = (string)($p['unit'] ?? 'pcs');
    $after = (float)($p['stock_qty'] ?? 0) + $qty;

    if ($costPerUnit > 0) {
        db()->prepare("UPDATE product_prices SET cost_per_unit = ?, track_stock = 1, updated_at = NOW() WHERE id = ?")
            ->execute([$costPerUnit, $productId]);
    }

    $res = stock_ledger_add($productId, (string)($opt['type'] ?? 'masuk'), $qty, [
        'reason'    => $opt['reason'] ?? 'beli',
        'note'      => $opt['note'] ?? null,
        'ref_type'  => $opt['ref_type'] ?? 'manual',
        'ref_id'    => $opt['ref_id'] ?? null,
        'unit'      => $unit,
        'qty'       => $qty,
        'unit_cost' => $costPerUnit > 0 ? $costPerUnit : ((float)($p['cost_per_unit'] ?? 0) ?: null),
        'skip_cache'=> true,
    ]);

    if ($res['ok']) {
        db()->prepare("UPDATE product_prices SET stock_qty = ?, track_stock = 1, updated_at = NOW() WHERE id = ?")
            ->execute([$after, $productId]);
        $res['qty_after'] = $after;
        $res['unit']      = $unit;
        $res['cost_total']= round($qty * ($costPerUnit > 0 ? $costPerUnit : (float)($p['cost_per_unit'] ?? 0)), 2);
    }
    return $res;
}

/**
 * Tebak ukuran (lebar x tinggi, meter) dari NAMA PRODUK.
 * =====================================================================
 * Dipakai HANYA untuk data LAMA yang ukurannya tidak tersimpan (order
 * sebelum 2026-10-09). Order BARU memakai kolom width_m/height_m.
 *
 * Aturan: ambil SEMUA pola "WxH", buang yang tidak masuk akal
 * (angka > 30 m — mis. "200 x 100" = cm, atau "1500x458" = nomor),
 * lalu pilih yang PALING AKHIR (paling dekat dengan ukuran sebenarnya).
 * Contoh: "Spanduk 200 x 100 kdkdkd 2x1" → 2 x 1 m (BUKAN 200 x 100).
 * Mengembalikan null bila tidak ada pola yang wajar.
 */
function stock_parse_size_from_name(string $name): ?array
{
    if ($name === '') return null;
    if (!preg_match_all('/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)/u', $name, $mm, PREG_SET_ORDER)) {
        return null;
    }
    $pilih = null;
    foreach ($mm as $m) {
        $a = (float)str_replace(',', '.', $m[1]);
        $b = (float)str_replace(',', '.', $m[2]);
        if ($a > 0 && $b > 0 && $a <= 30 && $b <= 30) {
            $pilih = ['w' => $a, 'h' => $b, 'raw' => trim($m[0])];
        }
    }
    return $pilih;
}

/**
 * Estimasi HPP untuk order LAMA yang belum tercatat di kartu stok
 * (order sebelum 2026-10-09 — ukuran belum tersimpan saat itu).
 * =====================================================================
 * Dipakai agar laporan laba bulan berjalan tidak menyesatkan (margin
 * 100%). Dihitung dari ukuran yang dibaca dari nama produk × biaya/m.
 * Hasilnya SELALU ditandai sebagai ESTIMASI di laporan, bukan angka pasti.
 */
function stock_hpp_estimasi_period(string $start, string $end): array
{
    $hari = function_exists('stock_parse_size_from_name') ? true : false;
    if (!$hari) return ['hpp' => 0.0, 'meter' => 0.0, 'n' => 0, 'n_gagal' => 0];

    /* ------------------------------------------------------------------
     * FIX 2026-10-09 — ESTIMASI MENGHITUNG ULANG ORDER YANG SUDAH MASUK
     * KARTU STOK (HPP jadi dua kali, laba tampak kecil palsu)
     * ------------------------------------------------------------------
     * `stock_sync_usage()` menulis pemakaian NYATA ke `stock_movements`
     * (reason='sync_pemakaian') tetapi TIDAK mengisi
     * `order_items.stock_meters_used`. Akibatnya syarat
     * "stock_meters_used IS NULL" di bawah tetap menganggap order itu
     * belum dihitung, padahal HPP-nya SUDAH masuk lewat
     * `stock_hpp_period()`.
     *
     * Contoh nyata (Okt 2026):
     *   kartu stok (nyata)  : 123,39 m → Rp 2.679.786
     *   estimasi (dobel)    : 181,32 m → Rp 3.771.456
     *   HPP dilaporkan      : Rp 6.451.242  ← dilebihkan Rp 3.771.456
     *
     * Solusi: kalau suatu PRODUK pada TANGGAL tertentu sudah punya
     * pergerakan 'potong' hasil sinkron/produksi, order di tanggal itu
     * DILEWATI (sudah terhitung nyata & lebih akurat — memakai NESTING
     * sehingga meter yang dipakai justru lebih hemat dari perkiraan).
     * ------------------------------------------------------------------ */
    $adaSync = [];
    $qs = db()->prepare(
        "SELECT DISTINCT product_id, DATE(created_at) AS d
           FROM stock_movements
          WHERE type = 'potong'
            AND reason IN ('sync_pemakaian', 'produksi', 'pemakaian_historis')
            AND DATE(created_at) BETWEEN ? AND ?"
    );
    $qs->execute([$start, $end]);
    foreach ($qs->fetchAll() as $r) {
        $adaSync[(int)$r['product_id'] . '|' . $r['d']] = true;
    }

    $st = db()->prepare(
        "SELECT oi.id, oi.product_name, oi.qty, DATE(o.order_date) AS tgl
           FROM order_items oi
           JOIN orders o ON o.id = oi.order_id
          WHERE o.status <> 'batal'
            AND o.order_date BETWEEN ? AND ?
            AND (oi.stock_meters_used IS NULL OR oi.stock_meters_used = 0)"
    );
    $st->execute([$start, $end]);

    $hpp = 0.0; $meter = 0.0; $n = 0; $nGagal = 0; $nSudah = 0;
    foreach ($st->fetchAll() as $it) {
        $size = stock_parse_size_from_name((string)$it['product_name']);
        if (!$size) { $nGagal++; continue; }

        $p = stock_match_product((string)$it['product_name']);
        if (!$p) { $nGagal++; continue; }

        // Sudah masuk kartu stok? → jangan dihitung dua kali
        if (isset($adaSync[(int)$p['id'] . '|' . $it['tgl']])) { $nSudah++; continue; }

        $rollW  = (float)($p['roll_width_m'] ?? 0);
        $perCut = stock_meters_for_size((float)$size['w'], (float)$size['h'], $rollW);
        if ($perCut === null) { $nGagal++; continue; }

        $cpm    = stock_cost_per_meter($p);
        if ($cpm <= 0) { $nGagal++; continue; }

        $m    = $perCut * max(1, (float)$it['qty']);
        $meter += $m;
        $hpp   += $m * $cpm;
        $n++;
    }
    return ['hpp' => round($hpp, 2), 'meter' => round($meter, 2), 'n' => $n,
            'n_gagal' => $nGagal, 'n_sudah_tercatat' => $nSudah];
}

/**
 * Baris "JEJAK AUDIT" saat pemakaian bahan MELEBIHI stok tersedia.
 * =====================================================================
 * Pemakaian tetap DICATAT (stok tidak boleh "diam-diam" cukup padahal
 * tidak ada bahannya), tapi diberi penanda `PEMAKAIAN-MELEBIH` supaya
 * kelihatan di buku besar & laporan → admin tidak bisa menghabiskan
 * bahan tanpa ketahuan.
 */
function stock_log_shortage(int $productId, float $meters, float $available, string $note = ''): void
{
    if (function_exists('writeLog')) {
        writeLog("STOK BAHAN KURANG: produk #$productId butuh " . round($meters, 2)
            . " m, tersedia " . round($available, 2) . " m. " . $note, 'WARNING');
    }
    $kekurangan = round($meters - $available, 3);
    $sudah = db()->prepare("SELECT id FROM stock_movements WHERE product_id=? AND reason='PEMAKAIAN-MELEBIH' AND note=? LIMIT 1");
    $sudah->execute([$productId, mb_substr($note, 0, 250)]);
    if ($sudah->fetchColumn()) return;

    stock_ledger_add($productId, 'opname', 0, [
        'reason'   => 'PEMAKAIAN-MELEBIH',
        'note'     => 'Butuh ' . round($meters, 2) . ' m, tersedia ' . round($available, 2)
                      . ' m (kurang ' . $kekurangan . ' m). ' . $note,
        'ref_type' => 'order',
        'skip_cache' => true,
    ]);
}

/**
 * Saldo semua bahan untuk ditampilkan di FORM ORDER.
 * Hanya produk yang `track_stock = 1` (bahan yang benar-benar dilacak).
 * Label dibuat dari kata KUNCI berbentuk 'x-banner'/'sablon'/'spanduk' agar
 * JS mudah mencocokkan tiap baris item ke bahan yang tepat.
 *
 * TINTA disembunyikan secara default (permintaan owner 2026-10-09): tinta
 * bukan barang yang dipesan pelanggan per order — dikonsumsi diam-diam saat
 * mencetak. Yang penting tampil: bahan utama (flexy, PET film, x-banner).
 * Bisa dimunculkan lagi dengan setting `stock_orderform_show_ink` = '1'.
 */
function stock_orderform_list(): array
{
    if (getSetting('stock_show_in_orderform', '1') !== '1') return [];

    $showInk = getSetting('stock_orderform_show_ink', '0') === '1';

    $rows = db()->query("SELECT * FROM product_prices WHERE track_stock = 1 ORDER BY category, variant")->fetchAll();

    /* Tanggal stok terakhir masuk per produk — PENTING supaya admin tahu
       stok ini diinput kapan (pelajaran 2026-10-09: tanggal stok menentukan
       HPP masuk ke bulan yang benar). */
    $tglMasuk = [];
    foreach (db()->query("SELECT product_id, MAX(created_at) AS tgl FROM stock_movements WHERE type='masuk' GROUP BY product_id")->fetchAll() as $t) {
        $tglMasuk[(int)$t['product_id']] = (string)$t['tgl'];
    }
    // Bahan roll: pakai tanggal terima roll paling awal yang masih AKTIF
    $tglRoll = [];
    foreach (db()->query("SELECT product_id, MIN(received_at) AS tgl FROM stock_rolls WHERE status='aktif' AND received_at IS NOT NULL GROUP BY product_id")->fetchAll() as $t) {
        $tglRoll[(int)$t['product_id']] = (string)$t['tgl'];
    }

    $out = [];
    foreach ($rows as $r) {
        // Lewati TINTA (kecuali diminta tampil)
        if (!$showInk && mb_stripos((string)$r['category'], 'tinta') !== false) continue;

        $pid = (int)$r['id'];
        $u = stock_unit_value($pid);
        // Sisa meter (hanya untuk bahan roll — dipakai prediksi pemakaian)
        $meters = $u['is_roll'] ? stock_total_meters($pid) : 0.0;

        // Tanggal stok: prioritas tanggal terima roll (paling relevan), fallback movement masuk
        $tgl = $tglRoll[$pid] ?? ($tglMasuk[$pid] ?? null);
        $tglTampil = ''; $umur = null;
        if ($tgl) {
            $ts = strtotime($tgl);
            if ($ts) {
                $tglTampil = date('d/m/Y', $ts);
                $umur = (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $ts))) / 86400);
            }
        }

        $out[] = [
            'product_id' => $pid,
            'label'      => $r['category'] . ' ' . $r['variant'],
            'key'        => stock_norm((string)$r['category']),
            'key_full'   => stock_norm($r['category'] . $r['variant']),
            'unit'       => (string)$u['unit'],
            'qty'        => (float)$u['qty'],
            'meters'     => (float)$meters,
            'unit_cost'  => (float)$u['unit_cost'],
            'is_roll'    => (bool)$u['is_roll'],
            'roll_len'   => (float)($r['roll_length_m'] ?? 0),
            'roll_width' => (float)($r['roll_width_m'] ?? 0),
            'tgl'        => $tglTampil,
            'umur_hari'  => $umur,
        ];
    }
    return $out;
}

/**
 * Pemetaan urutan kategori item form → kata kunci bahan.
 * (kategori pada form: spanduk, sablon, x-banner, sticker, lainnya)
 */
function stock_orderform_keymap(): array
{
    return [
        'spanduk'    => ['spanduk'],
        'sablon'     => ['petfilmdtf', 'tintadtf'],
        'x-banner'   => ['xbanner'],
        'xbanner'    => ['xbanner'],
    ];
}

/**
 * Sisa bahan untuk satu kategori item (dipakai form order).
 * Mengembalikan daftar baris bahan yang cocok (bisa lebih dari satu).
 */
function stock_orderform_for_category(string $category): array
{
    $map = stock_orderform_keymap();
    $keys = $map[stock_norm($category)] ?? [];
    if (!$keys) return [];
    $all = stock_orderform_list();
    $hit = [];
    foreach ($all as $s) {
        foreach ($keys as $k) {
            if (strpos($s['key'], $k) !== false || strpos($s['key_full'], $k) !== false) { $hit[] = $s; break; }
        }
    }
    return $hit;
}

/**
 * Potong bahan untuk order SABLON (DTF).
 * =====================================================================
 * Bahan sablon bukan "jasa per baju" saja — jelas terpakai berapa cm/m:
 *   1. FILM  : dari ukuran desain (cm) bila diisi → akurat per cm.
 *              Bila ukuran kosong → dari jumlah lembar = baju ÷ isi-per-lembar.
 *   2. TINTA : dari ml per lembar (setting). Bila 0 → TIDAK dipotong
 *              (sengaja: jangan menebak pemakaian tinta).
 *
 * @param int    $orderItemId
 * @param float  $baju        jumlah baju
 * @param int    $perLembar   isi baju per lembar film (orders.dtf_per_lembar)
 * @param float  $panjangCm   panjang desain (cm), 0 bila tidak diisi
 * @param float  $lebarCm     lebar desain (cm), 0 bila tidak diisi
 */
function stock_consume_sablon(int $orderItemId, float $baju, int $perLembar,
                              float $panjangCm, float $lebarCm, int $orderId = 0): array
{
    $hasil = ['film' => null, 'tinta' => null, 'lembar' => 0, 'film_m' => 0, 'tinta_ml' => 0];

    /* --- 1. FILM PET --- */
    $pet = db()->query("SELECT * FROM product_prices WHERE category = 'PET Film DTF' LIMIT 1")->fetch();
    if (!$pet) return $hasil;
    $petId  = (int)$pet['id'];
    $rollW  = (float)($pet['roll_width_m'] ?? 0);

    $filmM = 0.0;
    $lembar = 0;
    $pakaiUkuran = ($panjangCm > 0 || $lebarCm > 0);

    if ($pakaiUkuran) {
        // Ukuran desain diketahui (cm) → hitung per potong
        $perCut = stock_meters_for_size($panjangCm / 100, $lebarCm / 100, $rollW);
        if ($perCut === null) $perCut = max($panjangCm, $lebarCm) / 100;
        $filmM  = round($perCut * max(1, $baju), 3);
        $lembar = 0; // tidak relevan saat pakai ukuran
    } else {
        // Ukuran tidak diisi → pakai jumlah lembar film
        $filmLenCm = (float)(getSetting('dtf_film_length_cm', '45') ?: 45);
        if ($perLembar <= 0) return $hasil;   // tidak bisa dihitung, jangan menebak
        $lembar = (int)ceil($baju / $perLembar);
        $filmM  = round($lembar * ($filmLenCm / 100), 3);
    }

    if ($filmM > 0) {
        $hasil['film'] = stock_consume($petId, $filmM, [
            'type'     => 'potong',
            'reason'   => 'produksi',
            'note'     => 'Film DTF ' . ($pakaiUkuran
                            ? rtrim(rtrim(number_format($panjangCm,1,',','.'),'0'),',') . 'x' . rtrim(rtrim(number_format($lebarCm,1,',','.'),'0'),',') . ' cm x ' . $baju . ' baju'
                            : $lembar . ' lembar film (' . $baju . ' baju)'),
            'ref_type' => 'order',
            'ref_id'   => $orderItemId > 0 ? $orderItemId : ($orderId ?: null),
        ]);
        $hasil['film_m'] = $filmM;
        $hasil['lembar'] = $lembar;
    }

    /* --- 2. TINTA DTF (hanya bila ml/lembar sudah diatur) --- */
    $mlPerLembar = (float)getSetting('dtf_tinta_ml_per_lembar', '0');
    if ($mlPerLembar > 0) {
        $lembarTinta = $lembar > 0 ? $lembar : (int)ceil($baju / max(1, $perLembar));
        if ($lembarTinta > 0) {
            $ml = $lembarTinta * $mlPerLembar;
            $hasil['tinta_ml'] = $ml;
            // Bagi rata ke tiap warna yang ada stoknya (botol 1 L = 1000 ml)
            $warna = db()->query("SELECT id, variant FROM product_prices WHERE category = 'Tinta DTF (Sablon Kaos)' AND track_stock = 1")->fetchAll();
            if ($warna) {
                $perWarna = ($ml / count($warna)) / 1000;   // liter → botol
                foreach ($warna as $w) {
                    $r = stock_consume_qty((int)$w['id'], $perWarna, 'produksi', [
                        'note'     => 'Tinta DTF ' . $w['variant'] . ' ' . round($ml / count($warna), 1) . ' ml',
                        'ref_type' => 'order',
                        'ref_id'   => $orderItemId > 0 ? $orderItemId : ($orderId ?: null),
                    ], 'potong');
                    if (!empty($r['ok'])) $hasil['tinta'] = $r;
                }
            }
        }
    }

    return $hasil;
}

/**
 * TARIF PEMAKAIAN TINTA per satuan produksi — diukur, bukan dikarang.
 * =====================================================================
 * KENAPA DIBUAT (permintaan pemilik usaha 2026-10-09):
 *   "di potong otomatis gpp asal hitungnya benar karena ada nota lama ka
 *    rentang berapa lama berapa cetakan order sampai tinta itu habis
 *    (biasanya fluktuatif) sehingga saya binggung nentuin harganya"
 *
 * Pemilik usaha BENAR: pemakaian tinta per cetak tidak bisa ditebak (desain
 * penuh warna jauh lebih boros daripada yang sedikit warna). Karena itu
 * JANGAN menebak — UKUR dari jarak restock ke restock:
 *
 *     tarif = Rp tinta yang dibeli ÷ produksi pada rentang itu
 *
 * Contoh nyata dari data nota Gading Murni:
 *   Tinta Spanduk : Rp 2.480.000 ÷ 1.214 m²  = Rp 2.043 / m²
 *   Tinta DTF     : Rp 1.805.000 ÷   157 kaos = Rp 11.497 / kaos
 *
 * Sumber tarif diambil berurutan (yang paling bisa dipercaya dulu):
 *   1. setting manual `tarif_tinta_spanduk` / `tarif_tinta_dtf`
 *      (owner bisa memaksa angka sendiri — lihat penjelasan di bawah)
 *   2. hasil ukur `stock_ink_restock_analysis()` dari periode LENGKAP
 *   3. tabel setting bawaan (perkiraan konservatif) bila belum ada data
 *
 * MENGAPA ADA PILIHAN MANUAL:
 *   Kadang tarif hasil ukur kelihatan aneh (mis. sampel produksi cuma 14 kaos
 *   → Rp 50.000/kaos). Owner boleh menimpanya lewat Pengaturan tanpa
 *   mengubah kode.
 *
 * @param  string $kategori 'dtf' (per kaos) atau 'spanduk' (per m²)
 * @return float  rupiah per satuan produksi; 0 = jangan dipotong
 */
function stock_ink_rate(string $kategori): float
{
    /* ------------------------------------------------------------------
     * SAKLAR UTAMA — potong tinta otomatis MATI secara bawaan.
     * ------------------------------------------------------------------
     * Keputusan pemilik usaha (2026-10-09): "tunggu opname tinta dulu".
     *
     * ALASANNYA KUAT — tarif hasil ukur belum bisa dipercaya sebelum ada
     * bukti tinta benar-benar habis:
     *   - Semua botol/jergen tinta stoknya MASIH PENUH (1 unit, belum
     *     berkurang sekali pun) → tinta BELUM habis.
     *   - Metode "Rp tinta ÷ produksi" mengandaikan tinta habis di antara
     *     dua restock. Bila penutupnya cuma SALDO AWAL (input manual),
     *     periodenya palsu dan tarifnya jadi terlalu tinggi.
     *
     * Bukti yang diperlukan (salah satu):
     *   1. OPNAME tinta — petugas menghitung sisa tinta; selisihnya = pemakaian
     *   2. RESTOCK NYATA berikutnya — tinta dibeli karena memang habis
     *
     * Setelah bukti itu ada, nyalakan dengan setting
     *   `tinta_potong_otomatis` = '1'
     * di halaman Pengaturan. Tanpa itu, tinta TIDAK dipotong sama sekali.
     * ---------------------------------------------------------------- */
    if (getSetting('tinta_potong_otomatis', '0') !== '1') return 0.0;

    $kunciManual = $kategori === 'dtf' ? 'tarif_tinta_dtf' : 'tarif_tinta_spanduk';

    // 1. Setting manual (owner menimpa)
    $manual = (float)str_replace(',', '.', (string)getSetting($kunciManual, '0'));
    if ($manual > 0) return round($manual, 2);

    // 2. Hasil ukur dari riwayat restock
    static $cache = [];
    if (!isset($cache[$kategori])) {
        $cache[$kategori] = function_exists('stock_ink_restock_analysis')
            ? (stock_ink_restock_analysis($kategori, 24)['tarif_agregat'] ?? null)
            : null;
    }
    if ($cache[$kategori] !== null && (float)$cache[$kategori] > 0) {
        $ukur = (float)$cache[$kategori];

        /* ------------------------------------------------------------------
         * PENGAMAN SAMPEL KECIL — 2026-10-09
         * ------------------------------------------------------------------
         * Tarif hasil ukur gampang menyesatkan bila data produksinya sedikit.
         * Contoh nyata: periode 02 Jun–08 Sep hanya 14 kaos → Rp 50.000/kaos,
         * padahal periode berikutnya 157 kaos → Rp 11.497/kaos. Beda 4×!
         *
         * Karena itu hasil ukur DIBATASI pada batas atas (plafon) yang wajar.
         * Plafon diambil dari setting `tarif_tinta_<x>_maks`; bila kosong
         * dipakai nilai bawaan yang konservatif.
         *
         * Prinsipnya: lebih baik sedikit KURANG potong daripada memotong
         * terlalu banyak. Bila tarif dipakai terlalu tinggi, laba tampak
         * rugi; owner bisa memaksa angka sendiri lewat Pengaturan.
         * ---------------------------------------------------------------- */
        $kunciMaks = $kategori === 'dtf' ? 'tarif_tinta_dtf_maks' : 'tarif_tinta_spanduk_maks';
        $maksBawaan = $kategori === 'dtf' ? 12000 : 3500;   // rupiah per kaos / per m²
        $maks = (float)str_replace(',', '.', (string)getSetting($kunciMaks, (string)$maksBawaan));
        if ($maks <= 0) $maks = $maksBawaan;

        return round(min($ukur, $maks), 2);
    }

    /* 3. Cadangan terakhir: 0 = TIDAK dipotong.
     * Sengaja 0, bukan angka karangan. Lebih baik HPP kurang sedikit tetapi
     * AKURAT basisnya, daripada memotong tinta dengan angka yang diarang —
     * itu justru merusak laporan dan pemilik usaha tidak bisa memercayainya. */
    return 0.0;
}

/**
 * Potong TINTA untuk sebuah order item, memakai tarif hasil ukur.
 *
 * Dipisah dari `stock_consume_order_item()` supaya berlaku untuk SEMUA jalur
 * produksi (spanduk, x-banner, sablon DTF) tanpa menduplikasi rumus.
 *
 * Cara hitung:
 *   - spanduk  → luas = lebar × tinggi × qty (meter), lalu × Rp/m²
 *   - sablon   → jumlah kaos × Rp/kaos
 * Pemakaian dibagi rata ke tiap warna yang punya stok (botol/jergen),
 * meniru kenyataan bahwa cetak warna memakai semua warna sekaligus.
 *
 * TIDAK menebak: bila ukuran/qty tidak diketahui atau tarif masih 0,
 * fungsi ini tidak melakukan apa pun dan mengembalikan null.
 *
 * @param string $kategori 'dtf' (per kaos) atau 'spanduk' (per m²)
 * @param int    $orderItemId
 * @param float  $qty   jumlah pesanan (kaos untuk DTF)
 * @param float  $w     lebar (meter) — untuk spanduk
 * @param float  $h     tinggi (meter) — untuk spanduk
 * @param int    $orderId
 */
function stock_consume_ink(string $kategori, int $orderItemId, float $qty,
                           float $w = 0, float $h = 0, int $orderId = 0): ?array
{
    $tarif = stock_ink_rate($kategori);
    if ($tarif <= 0 || $qty <= 0) return null;

    /* Hitung satuan produksi */
    if ($kategori === 'dtf') {
        $satuan = max(0, round($qty, 2));                 // kaos
        $label  = $satuan . ' kaos';
    } else {
        if ($w <= 0 || $h <= 0) return null;              // tanpa ukuran: jangan menebak
        $satuan = round($w * $h * max(1, $qty), 4);       // m²
        $label  = rtrim(rtrim(number_format($w, 2, ',', '.'), '0'), ',') . '×'
                . rtrim(rtrim(number_format($h, 2, ',', '.'), '0'), ',') . ' m × ' . $qty;
    }
    if ($satuan <= 0) return null;

    $nilai = round($satuan * $tarif, 2);
    if ($nilai <= 0) return null;

    /* Cari warna tinta yang relevan & punya stok */
    $like = $kategori === 'dtf' ? 'Tinta DTF (Sablon Kaos)' : 'Tinta Spanduk (Banner)';
    $warna = db()->query(
        "SELECT id, variant, unit FROM product_prices
          WHERE category = " . db()->quote($like) . " AND track_stock = 1"
    )->fetchAll();
    if (!$warna) return null;

    $perWarna = $nilai / count($warna);                   // rupiah per warna
    $hasil = ['ok' => true, 'nilai' => $nilai, 'satuan' => $satuan,
              'tarif' => $tarif, 'warna' => 0, 'kategori' => $kategori];

    foreach ($warna as $c) {
        $pid   = (int)$c['id'];
        $unit  = (string)$c['unit'];
        $p     = stock_product($pid);
        $harga = (float)($p['cost_per_unit'] ?? 0);
        if ($harga <= 0) continue;

        /* Ubah nilai rupiah → jumlah satuan (botol/jergen) */
        $qtyPakai = $perWarna / $harga;
        if ($qtyPakai <= 0) continue;

        $r = stock_consume_qty($pid, $qtyPakai, 'produksi', [
            'note'     => 'Tinta ' . $c['variant'] . ' untuk ' . $label
                        . ' (' . number_format($perWarna, 0, ',', '.') . ' Rp)',
            'ref_type' => 'order',
            'ref_id'   => $orderItemId > 0 ? $orderItemId : ($orderId ?: null),
        ], 'potong');
        if (!empty($r['ok'])) $hasil['warna']++;
    }

    return $hasil['warna'] > 0 ? $hasil : null;
}

/**
 * Hitung produksi (banyaknya hasil cetak) dalam rentang tanggal.
 *   - 'sablon'  → PCS (kaos)
 *   - 'spanduk' → M²  (luas banner)
 * Dipakai untuk menaksir pemakaian tinta: tinta dipakai per SATUAN PRODUKSI,
 * bukan per order — jadi tarifnya dihitung dari produksi nyata antar restock.
 */
function stock_production_in_period(string $kategori, string $start, string $end): float
{
    if ($kategori === 'sablon') {
        $st = db()->prepare(
            "SELECT COALESCE(SUM(oi.qty), 0)
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE o.status <> 'batal'
                AND o.order_date BETWEEN ? AND ?
                AND (oi.product_name LIKE '%sablon%' OR oi.product_name LIKE '%baju%'
                     OR oi.product_name LIKE '%kaos%' OR oi.product_name LIKE '%dtf%'
                     OR o.is_dtf = 1)"
        );
        $st->execute([$start, $end]);
        return round((float)$st->fetchColumn(), 2);
    }

    // spanduk / baliho → luas m²
    $st = db()->prepare(
        "SELECT oi.product_name, oi.qty, oi.width_m, oi.height_m
           FROM order_items oi JOIN orders o ON o.id = oi.order_id
          WHERE o.status <> 'batal'
            AND o.order_date BETWEEN ? AND ?
            AND (oi.product_name LIKE '%spanduk%' OR oi.product_name LIKE '%baliho%')"
    );
    $st->execute([$start, $end]);
    $m2 = 0.0;
    foreach ($st->fetchAll() as $r) {
        $w = (float)($r['width_m'] ?? 0);
        $h = (float)($r['height_m'] ?? 0);
        if ($w <= 0 || $h <= 0) {                       // data lama: tebak dari nama
            $sz = stock_parse_size_from_name((string)$r['product_name']);
            if ($sz) { $w = $sz['w']; $h = $sz['h']; }
        }
        if ($w > 0 && $h > 0) $m2 += ($w * $h) * max(1, (float)$r['qty']);
    }
    return round($m2, 2);
}

/**
 * ANALISA PEMAKAIAN TINTA DARI RESTOCK KE RESTOCK (usulan owner 2026-10-09)
 * =====================================================================
 * Masalah: pemakaian tinta tidak bisa ditebak per cetak (desain full-warna
 * vs sedikit warna beda jauh; A3+ penuh jauh lebih boros).
 *
 * Solusi: jangan tebak — UKUR. Setiap kali restock tinta, sistem tahu:
 *   - berapa nilai tinta yang masuk
 *   - berapa banyak produksi (pcs kaos / m² banner) SEJAK restock sebelumnya
 * → Rp tinta ÷ produksi = tarif HPP nyata (yang lama makin akurat).
 *
 * Fungsi ini mengembalikan daftar periode antar-restock + tarif hasilnya.
 */
function stock_ink_restock_analysis(string $kategori, int $months = 12): array
{
    /* ------------------------------------------------------------------
     * SUMBER PEMBELIAN TINTA = DUA TEMPAT (penting!)
     * ------------------------------------------------------------------
     * `stock_movements` HANYA berisi pembelian yang benar-benar menambah stok
     * (mode "Tambah Stok" di halaman Nota Supplier). Nota Jan–Sep disimpan
     * dalam mode "Arsip + Harga" sehingga TIDAK masuk ke stock_movements —
     * padahal uangnya tetap keluar.
     *
     * Kalau hanya membaca stock_movements, hasilnya Rp 0 dan tarif HPP tidak
     * bisa dihitung (ini yang terjadi sebelum perbaikan 2026-10-09).
     *
     * Karena itu pembelian digabung dari:
     *   1. `stock_movements` (type='masuk')            → nota bulan berjalan
     *   2. `supplier_nota_archive_item` + header nota  → nota yang diarsipkan
     * Keduanya dijumlahkan per TANGGAL, lalu tanggal unik itulah yang menjadi
     * titik restock. Tanggal yang sama tidak dihitung dua kali (restock
     * 4 warna sekaligus memang satu kejadian).
     * ---------------------------------------------------------------- */
    $like = $kategori === 'dtf' ? '%Tinta DTF%' : '%Tinta Spanduk%';
    $sejak = date('Y-m-d', strtotime('-' . max(1, $months) . ' month'));

    $titik = [];   // tanggal => ['rp'=>, 'unit'=>, 'unit_label'=>]

    $tambah = static function (array &$titik, string $tgl, float $rp, float $unit, string $label): void {
        if ($tgl === '' || $tgl === '0000-00-00') return;
        if (!isset($titik[$tgl])) $titik[$tgl] = ['tanggal' => $tgl, 'rp' => 0.0, 'unit' => 0.0, 'unit_label' => $label];
        $titik[$tgl]['rp']   += $rp;
        $titik[$tgl]['unit'] += $unit;
        if ($label !== '') $titik[$tgl]['unit_label'] = $label;
    };

    // ---- 1. Pembelian yang masuk stok (nota bulan berjalan) ----
    try {
        $st = db()->prepare(
            "SELECT DATE(sm.created_at) AS tgl, sm.qty, sm.unit, sm.total_cost
               FROM stock_movements sm JOIN product_prices pp ON pp.id = sm.product_id
              WHERE sm.type = 'masuk' AND pp.category LIKE ?
                AND DATE(sm.created_at) >= ?
              ORDER BY sm.id ASC"
        );
        $st->execute([$like, $sejak]);
        foreach ($st->fetchAll() as $r) {
            $tambah($titik, (string)$r['tgl'], (float)($r['total_cost'] ?? 0),
                    (float)($r['qty'] ?? 0), (string)($r['unit'] ?? ''));
        }
    } catch (Throwable $e) { /* lanjut ke arsip */ }

    // ---- 2. Pembelian dari ARSIP nota (Jan–Sep: hanya harga, stok tidak diubah) ----
    try {
        $st2 = db()->prepare(
            "SELECT a.nota_date AS tgl, i.qty, i.satuan, (i.qty * i.harga_satuan) AS rp
               FROM supplier_nota_archive_item i
               JOIN supplier_nota_archive a ON a.id = i.archive_id
               JOIN product_prices pp ON pp.id = i.product_id
              WHERE pp.category LIKE ?
                AND a.nota_date >= ?
              ORDER BY a.nota_date ASC, i.id ASC"
        );
        $st2->execute([$like, $sejak]);
        foreach ($st2->fetchAll() as $r) {
            $tambah($titik, (string)$r['tgl'], (float)($r['rp'] ?? 0),
                    (float)($r['qty'] ?? 0), (string)($r['satuan'] ?? ''));
        }
    } catch (Throwable $e) { /* biarkan kosong */ }

    // Urutkan menurut tanggal lalu buang tanggal kembar (sudah digabung di atas)
    ksort($titik);
    $titik = array_values($titik);

    // Produksi sekarang (dipakai untuk periode yang masih berjalan)
    $hariIni = date('Y-m-d');

    /* ------------------------------------------------------------------
     * satuan produksi: tinta DTF dihitung dari KAOS (pcs), tinta spanduk
     * dari LUAS (m²). `stock_production_in_period()` mengenali kategori
     * 'sablon' untuk pcs — jadi 'dtf' HARUS dipetakan ke 'sablon'.
     *
     * BUG yang diperbaiki 2026-10-09: dulu 'dtf' diteruskan apa adanya,
     * tidak cocok dengan pemeriksaan `=== 'sablon'` di dalam fungsi itu,
     * sehingga jatuh ke cabang m² dan menghitung LUAS SPANDUK sebagai
     * "produksi kaos". Angkanya jadi desimal (mis. 859,23) dan tarif per
     * kaos salah total.
     * ---------------------------------------------------------------- */
    $katProd = $kategori === 'dtf' ? 'sablon' : 'spanduk';

    $periode = [];
    for ($i = 0; $i < count($titik); $i++) {
        $a = $titik[$i];
        $b = $titik[$i + 1] ?? null;
        // Periode: dari restock A sampai restock B (atau sampai hari ini)
        $mulai  = $a['tanggal'];
        $akhir  = $b ? date('Y-m-d', strtotime($b['tanggal'] . ' -1 day')) : $hariIni;
        if ($akhir < $mulai) continue;

        $produksi = stock_production_in_period($katProd, $mulai, $akhir);
        $selesai  = ($b !== null);   // periode lengkap = ada restock penutup

        $periode[] = [
            'dari'        => $mulai,
            'sampai'      => $akhir,
            'rp_tinta'    => round($a['rp'], 2),        // nilai tinta yang "habis" di periode ini
            'unit_tinta'  => round($a['unit'], 2),
            'unit_label'  => $a['unit_label'],
            'produksi'    => $produksi,
            'tarif'       => $produksi > 0 ? round($a['rp'] / $produksi, 2) : null,
            'lengkap'     => $selesai,
        ];
    }

    // Tarif agregat dari periode LENGKAP saja (paling bisa dipercaya)
    $rp = 0.0; $prod = 0.0; $n = 0;
    foreach ($periode as $p) {
        if ($p['lengkap'] && $p['tarif'] !== null && (float)$p['produksi'] > 0) {
            $rp += (float)$p['rp_tinta']; $prod += (float)$p['produksi']; $n++;
        }
    }

    return [
        'kategori'      => $kategori,
        'satuan'        => $kategori === 'dtf' ? 'pcs (kaos)' : 'm² (banner)',
        'jumlah_restock'=> count($titik),
        'periode'       => $periode,
        'tarif_agregat' => ($prod > 0 && $n > 0) ? round($rp / $prod, 2) : null,
        'rp_terukur'    => round($rp, 2),
        'produksi_ukur' => round($prod, 2),
        'periode_ukur'  => $n,
        'cukup_data'    => $n >= 1,
    ];
}

/**
 * HITUNG PEMAKAIAN BAHAN CARA "GABUNG MENYAMPING" (NESTING)
 * =====================================================================
 * Kenyataan di bengkel: operator TIDAK memotong banner satu per satu.
 * Beberapa banner disusun BERDAMPINGAN pada lebar roll (mis. roll 3,2 m
 * bisa memuat banner 1,5 m + 1 m + 0,7 m = 3,2 m), lalu dipotong sekaligus
 * sepanjang tinggi terbesar dalam kelompok itu.
 *
 * Contoh nyata (14 Sep 2026): cara satu-satu = 31,5 m, cara nesting = 13,9 m.
 * Ini membuat stok & HPP jauh lebih akurat (mendekati kenyataan bengkel).
 *
 * @param array $items  daftar ['w'=>lebar(m), 'h'=>tinggi(m)]
 * @param float $rollW  lebar roll (m)
 * @return float        meter linier roll yang terpakai
 */
function stock_nesting_meters(array $items, float $rollW): float
{
    if (!$items) return 0.0;
    if ($rollW <= 0) {
        // tanpa info lebar roll → fallback satu-satu
        $t = 0.0;
        foreach ($items as $it) $t += max((float)($it['w'] ?? 0), (float)($it['h'] ?? 0));
        return round($t, 3);
    }

    // Siapkan potongan. ATURAN ORIENTASI (penting):
    //   - Banner dicetak dengan LEBAR melintang roll, PANJANG = meter roll.
    //   - Jadi jangan diputar kalau lebarnya sudah muat di roll.
    //   - Putar HANYA kalau lebar > lebar roll (supaya bisa dicetak).
    $cuts = [];
    foreach ($items as $it) {
        $w = (float)($it['w'] ?? 0);
        $h = (float)($it['h'] ?? 0);
        if ($w <= 0 && $h <= 0) continue;
        if ($w <= 0) $w = $h;
        if ($h <= 0) $h = $w;

        if ($w > $rollW + 0.0001 && $h <= $rollW + 0.0001) {
            // lebar tak muat, tinggi muat → putar supaya muat
            [$w, $h] = [$h, $w];
        }
        $cuts[] = ['w' => $w, 'h' => $h];
    }
    if (!$cuts) return 0.0;

    // First-Fit Decreasing: yang paling lebar diletakkan lebih dulu
    usort($cuts, function ($a, $b) {
        if (abs($b['w'] - $a['w']) > 0.0001) return $b['w'] <=> $a['w'];
        return $b['h'] <=> $a['h'];
    });

    // Susun per "baris": tiap baris = satu panjang potong dari roll
    $baris = [];
    foreach ($cuts as $c) {
        if ($c['w'] > $rollW + 0.0001) {
            // tidak muat (lebih lebar dari roll) → potong memanjang, baris sendiri
            $baris[] = ['sisa' => 0.0, 'h' => max($c['w'], $c['h'])];
            continue;
        }
        $placed = false;
        foreach ($baris as &$b) {
            if ($b['sisa'] >= $c['w'] - 0.0001) {
                $b['sisa'] -= $c['w'];
                $b['h'] = max($b['h'], $c['h']);
                $placed = true;
                break;
            }
        }
        unset($b);
        if (!$placed) $baris[] = ['sisa' => $rollW - $c['w'], 'h' => $c['h']];
    }

    $total = 0.0;
    foreach ($baris as $b) $total += $b['h'];
    return round($total, 3);
}

/** Mode hitung: 'nesting' (gabung menyamping) atau 'linear'/'smart' (satu-satu). */
function stock_nest_mode(): bool
{
    return getSetting('stock_nest_mode', '1') === '1';
}

/**
 * Pemakaian bahan untuk SATU item order.
 * Kalau mode nesting aktif dan ada data batch harian, fungsi ini tetap
 * mengembalikan perkiraan per-item (dipakai bila order diproses sendirian).
 * Untuk akurasi penuh, gunakan stock_nesting_meters() per batch/hari.
 */
function stock_meters_for_item(float $w, float $h, float $rollW): ?float
{
    if (stock_nest_mode() && $rollW > 0) {
        return stock_nesting_meters([['w' => $w, 'h' => $h]], $rollW);
    }
    return stock_meters_for_size($w, $h, $rollW);
}

/**
 * Pilih roll TERBAIK untuk sekelompok potongan (pusat logika hemat).
 * =====================================================================
 * TEMUAN 2026-10-09: bengkel punya roll dengan LEBAR BERBEDA
 *   (110 cm, 160 cm, 210 cm, 320 cm).
 * Operator memilih roll SESUAI LEBAR BANNER — banner 1,5 m dipotong dari
 * roll 1,6 m (bukan 3,2 m) sehingga jauh lebih hemat.
 *
 * Fungsi ini mengelompokkan potongan ke roll yang paling "pas":
 *   - setiap roll dipakai kalau SEMUA potongan muat di lebarnya
 *   - skor = sisa lebar terkecil setelah susun mendampingi (makin rapat makin baik)
 *   - dirata-ratakan per meter supaya adil
 *
 * @param array $items  [ ['w'=>lebar,'h'=>tinggi], ... ]
 * @param array $rolls  [ ['id'=>.., 'w'=>lebar_roll, 'sisa'=>sisa_meter], ... ]
 * @return array [ ['roll_id'=>.., 'meters'=>.., 'n'=>jumlah_potong], ... ]
 */
function stock_choice_rolls(array $items, array $rolls): array
{
    if (!$items || !$rolls) return [];

    $kandidat = [];
    foreach ($rolls as $rl) {
        $rw   = (float)($rl['w'] ?? 0);
        $sisa = (float)($rl['sisa'] ?? 0);
        if ($rw <= 0 || $sisa <= 0.001) continue;

        $muat = []; $buang = 0;
        foreach ($items as $it) {
            $w = (float)($it['w'] ?? 0);
            $h = (float)($it['h'] ?? 0);
            if ($w <= 0 && $h <= 0) continue;
            if ($w <= 0) $w = $h;
            if ($h <= 0) $h = $w;
            // putar bila perlu supaya muat
            if ($w > $rw + 0.0001 && $h <= $rw + 0.0001) { [$w, $h] = [$h, $w]; }
            if ($w > $rw + 0.0001) { $buang++; continue; }   // tak muat di roll ini
            $muat[] = ['w' => $w, 'h' => $h];
        }
        if (!$muat) continue;

        $meters = stock_nesting_meters($muat, $rw);

        // efisiensi: berapa m² hasil per meter roll (makin tinggi makin baik)
        $luas = 0.0;
        foreach ($muat as $m) $luas += $m['w'] * $m['h'];
        $efisiensi = $meters > 0 ? $luas / $meters : 0;

        $kandidat[] = [
            'roll_id'   => (int)$rl['id'],
            'w'         => $rw,
            'sisa'      => $sisa,
            'meters'    => $meters,
            'n'         => count($muat),
            'dibuang'   => $buang,
            'luas'      => $luas,
            'efisiensi' => $efisiensi,
        ];
    }
    if (!$kandidat) return [];

    // Urutkan: efisiensi tertinggi, lalu potongan terbanyak, lalu roll paling sempit
    usort($kandidat, function ($a, $b) {
        if (abs($b['efisiensi'] - $a['efisiensi']) > 0.01) return $b['efisiensi'] <=> $a['efisiensi'];
        if ($a['n'] !== $b['n']) return $b['n'] <=> $a['n'];
        return $a['w'] <=> $b['w'];
    });

    return $kandidat;
}

/**
 * SINKRON HARGA MODAL DARI NOTA SUPPLIER — sumber harga = SUPPLIER, bukan manual.
 * =====================================================================
 * PERMINTAAN PEMILIK USAHA (2026-10-09):
 *   "ikut aja harga nota terakhir kan di update saat tinta atau bahan habis
 *    berarti harganya naik turun itu dari supplier bukan aku yang ubah ubah
 *    harusnya mengikuti dari supplier"
 *
 * Latar masalahnya: nota Jan–Sep disimpan dengan mode **Arsip + Harga**, yang
 * hanya mencatat riwayat dan TIDAK mengisi harga modal produk. Akibatnya
 * harga modal di sistem tidak pernah mengikuti supplier. Bukti sebelum
 * perbaikan — 11 bahan berbeda dari nota terakhir:
 *   Tinta DTF   : nota Rp 355.000 vs modal Rp 400.000  (beda Rp 45.000)
 *   Tinta Spanduk: nota Rp 620.000 vs modal Rp 650.000  (beda Rp 30.000)
 *   Eyelet, PET Powder, Cleaner Spanduk, Textile Sublim, Water Based:
 *                 nota ADA harganya, tapi modal Rp 0 (belum pernah terisi)
 *
 * Karena itu fungsi ini menjadikan **NOTA TERAKHIR sebagai penentu harga
 * modal**. Setiap kali harga supplier berubah (naik/turun), catat saja
 * notanya — harga modal ikut sendiri. Owner tidak perlu mengubah manual.
 *
 * YANG DIPAKAI SEBAGAI SUMBER (berurutan):
 *   1. `supplier_nota_archive_item` — nota yang diarsipkan (Jan–Sep)
 *   2. `stock_movements` (type='masuk') — pembelian yang masuk stok
 * Keduanya diurutkan menurut TANGGAL NOTA (bukan tanggal input), lalu diambil
 * yang PALING BARU. Tanggal nota dipakai supaya urutannya mencerminkan
 * kenyataan supplier, bukan kapan petugas mengunggah.
 *
 * BIAYA KIRIM: bila header nota punya `biaya_kirim`, ongkir itu ikut
 * ditambahkan ke harga modal secara proporsional (lihat
 * nota_supplier_bagi_kirim) supaya modal = uang yang benar-benar keluar.
 *
 * @param  int  $productId  produk yang disinkronkan (0 = semua bahan)
 * @param  bool $kering     true = hanya hitung, jangan ubah database
 * @return array{diperiksa:int,diubah:int,rincian:array}
 */
function stock_sinkron_harga_nota(int $productId = 0, bool $kering = false): array
{
    $rincian = []; $diubah = 0;

    $sql = "SELECT id, category, variant, unit, cost_per_roll, cost_per_unit,
                   roll_length_m, roll_width_m
              FROM product_prices
             WHERE (track_stock = 1 OR unit = 'roll')" . ($productId > 0 ? " AND id = " . (int)$productId : "") . "
             ORDER BY id";
    $produk = db()->query($sql)->fetchAll();

    foreach ($produk as $p) {
        $pid  = (int)$p['id'];
        $isRoll = in_array((string)$p['unit'], ['roll', 'meter'], true);
        $modalLama = $isRoll ? (float)($p['cost_per_roll'] ?? 0) : (float)($p['cost_per_unit'] ?? 0);

        /* ------------------------------------------------------------------
         * AMBIL HARGA NOTA TERAKHIR
         * ------------------------------------------------------------------
         * Diambil dari DUA sumber lalu dipilih yang tanggalnya paling baru.
         * `supplier_nota_archive_item.harga_satuan` bisa bernilai 0 bila OCR
         * gagal membaca kolom harga — baris seperti itu DILEWATI, karena
         * menimpa harga modal dengan 0 justru merusak HPP.
         * ---------------------------------------------------------------- */
        $kandidat = [];

        // (1) Dari arsip nota — pakai TANGGAL NOTA
        try {
            $st = db()->prepare(
                "SELECT a.nota_date AS tgl, i.harga_satuan AS harga, i.qty, i.satuan,
                        a.biaya_kirim, a.id AS arsip_id
                   FROM supplier_nota_archive_item i
                   JOIN supplier_nota_archive a ON a.id = i.archive_id
                  WHERE i.product_id = ? AND i.harga_satuan > 0
                  ORDER BY a.nota_date DESC, i.id DESC
                  LIMIT 1"
            );
            $st->execute([$pid]);
            $r = $st->fetch();
            if ($r) {
                $kandidat[] = [
                    'tgl'   => (string)$r['tgl'],
                    'harga' => (float)$r['harga'],
                    'sumber'=> 'arsip nota',
                    'kirim' => (float)$r['biaya_kirim'],
                    'arsip_id' => (int)$r['arsip_id'],
                    'qty'   => (float)($r['qty'] ?? 1),
                    'satuan'=> (string)$r['satuan'],
                ];
            }
        } catch (Throwable $e) { /* lanjut ke sumber kedua */ }

        // (2) Dari pembelian yang masuk stok — HANYA dipakai bila produk ini
        //     belum punya nota sama sekali.
        //
        // ⚠️ KENAPA NOTA DIPRIORITASKAN (keputusan pemilik usaha 2026-10-09):
        //   "ikut aja harga nota terakhir … harusnya mengikuti dari supplier"
        //
        //   Entri `stock_movements` hasil setup awal (mis. tinta Rp 650.000
        //   pada 9 Okt) BUKAN nota supplier — itu angka yang diketik manual
        //   saat sistem mulai dipakai. Kalau tanggalnya lebih baru, ia akan
        //   menang dan menutupi harga nota asli (Rp 620.000), sehingga harga
        //   TIDAK mengikuti supplier — persis yang tidak diinginkan pemilik.
        //
        //   Karena itu: bila nota sudah ada, nota yang menentukan. Sumber ini
        //   hanya jadi cadangan kalau bahan belum pernah punya nota.
        //
        // ⚠️ DUA JEBAKAN YANG WAJIB DIHINDARI (ditemukan lewat uji kering):
        //   a) `reason = 'saldo_awal'` BUKAN pembelian (contoh #18 x-banner:
        //      saldo awal Rp 25.000 lebih baru dari nota Rp 27.000 sebenarnya).
        //   b) Untuk bahan ROLL, `unit_cost` = harga per METER (Rp 21.428/m),
        //      BUKAN per roll (Rp 1.456.000). Menimpakannya ke `cost_per_roll`
        //      membuat modal spanduk anjlok jadi 1/68 → HPP & laba hancur.
        //      Nilai per meter dikembalikan ke per roll di bawah.
        try {
            $adaNota = false;
            try {
                $stC = db()->prepare("SELECT COUNT(*) FROM supplier_nota_archive_item WHERE product_id = ? AND harga_satuan > 0");
                $stC->execute([$pid]);
                $adaNota = (int)$stC->fetchColumn() > 0;
            } catch (Throwable $e) { /* anggap belum ada */ }

            if (!$adaNota) {
                $st2 = db()->prepare(
                    "SELECT DATE(created_at) AS tgl, unit_cost AS harga, unit AS satuan,
                            qty, COALESCE(reason,'') AS reason
                       FROM stock_movements
                      WHERE product_id = ? AND type = 'masuk' AND unit_cost > 0
                        AND COALESCE(reason,'') <> 'saldo_awal'
                      ORDER BY created_at DESC, id DESC
                      LIMIT 1"
                );
                $st2->execute([$pid]);
                $r2 = $st2->fetch();
                if ($r2) {
                    $h2 = (float)$r2['harga'];
                    /* Konversi ke satuan produk: bila produk memakai roll tetapi
                     * nilai ini tersimpan per meter, kalikan panjang roll. */
                    if ($isRoll) {
                        $len = (float)($p['roll_length_m'] ?? 0);
                        if ($len > 0 && $h2 < $len * 1000) $h2 = round($h2 * $len, 2);
                    }
                    $kandidat[] = [
                        'tgl'   => (string)$r2['tgl'],
                        'harga' => $h2,
                        'sumber'=> 'pembelian masuk stok',
                        'kirim' => 0.0,
                        'arsip_id' => 0,
                        'qty'   => (float)($r2['qty'] ?? 1),
                        'satuan'=> (string)$r2['satuan'],
                    ];
                }
            }
        } catch (Throwable $e) { /* biarkan kosong */ }

        if (!$kandidat) continue;

        // Pilih yang TANGGALNYA paling baru
        usort($kandidat, static fn($a, $b) => strcmp($b['tgl'], $a['tgl']));
        $pakai = $kandidat[0];

        /* SATUAN HARUS COCOK. Nota menulis harga per satuan yang dibeli
         * (BOTOL / JERIGEN / ROLL / PACK). Bila produk sistem memakai satuan
         * BERBEDA, harga tidak boleh dipindahkan mentah-mentah. */
        $satuanNota = barang_satuan_normal($kandidat[0]['satuan'] ?? '');
        $satuanProd = (string)$p['unit'];
        if ($satuanNota !== '' && $satuanNota !== $satuanProd
            && !($isRoll && in_array($satuanNota, ['roll', 'meter'], true))) {
            continue;
        }

        $hargaBaru = $pakai['harga'];

        /* ONGKIR — ditambahkan secara PROPORSIONAL, sama seperti saat menyimpan
         * nota (nota_supplier_bagi_kirim). Bila nota punya biaya kirim, ongkir
         * itu harus tercermin di harga modal; kalau tidak, modal lebih rendah
         * dari uang yang benar-benar keluar.
         *
         * Porsi kirim = ongkir × (nilai barang ini ÷ nilai SELURUH barang di
         * nota itu), lalu dibagi jumlah satuan yang dibeli.
         *
         * CATATAN: dihitung ulang dari data nota, bukan disimpan per baris,
         * supaya tetap benar walau urutan penyimpanan berubah. */
        if ($pakai['kirim'] > 0 && !empty($pakai['arsip_id'])) {
            try {
                $stN = db()->prepare(
                    "SELECT COALESCE(SUM(qty * harga_satuan), 0) AS total,
                            COALESCE(SUM(CASE WHEN product_id = ? THEN qty * harga_satuan ELSE 0 END), 0) AS milik
                       FROM supplier_nota_archive_item WHERE archive_id = ?"
                );
                $stN->execute([$pid, (int)$pakai['arsip_id']]);
                $n = $stN->fetch();
                $totalNota = (float)($n['total'] ?? 0);
                $milik     = (float)($n['milik'] ?? 0);
                if ($totalNota > 0 && $milik > 0) {
                    $porsi = $pakai['kirim'] * ($milik / $totalNota);
                    $hargaBaru = round($hargaBaru + ($porsi / max(1.0, (float)($pakai['qty'] ?? 1))), 2);
                }
            } catch (Throwable $e) { /* tanpa ongkir lebih baik daripada salah */ }
        }

        if (abs($hargaBaru - $modalLama) < 0.01) continue;   // sudah sama

        $rincian[] = [
            'id'      => $pid,
            'nama'    => (string)$p['category'] . ' ' . (string)$p['variant'],
            'lama'    => $modalLama,
            'baru'    => $hargaBaru,
            'tgl'     => $pakai['tgl'],
            'sumber'  => $pakai['sumber'],
        ];

        if (!$kering) {
            try {
                if ($isRoll) {
                    db()->prepare("UPDATE product_prices SET cost_per_roll = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$hargaBaru, $pid]);
                } else {
                    db()->prepare("UPDATE product_prices SET cost_per_unit = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$hargaBaru, $pid]);
                }
                $diubah++;
            } catch (Throwable $e) { /* diamkan */ }
        }
    }

    return ['diperiksa' => count($produk), 'diubah' => $diubah, 'rincian' => $rincian];
}

/**
 * SINKRON PEMAKAIAN BAHAN — hitung ulang otomatis dari data order
 * =====================================================================
 * Menggantikan hitung manual. Cara kerja:
 *   1. Ambil item order yang memakai produk bahan ini, dalam rentang tanggal.
 *   2. Kelompokkan PER HARI, hitung pakai cara NESTING (gabung menyamping)
 *      supaya sesuai kenyataan bengkel.
 *   3. Hapus pemakaian lama produk ini, lalu tulis ulang dengan tanggal =
 *      tanggal order (HPP masuk bulan yang benar).
 *   4. Roll dipakai berurutan: yang paling tua / paling dulu diterima.
 *
 * Karena selalu dihitung ulang dari SUMBER (order), hasilnya SELALU konsisten —
 * tidak mungkin menyimpan angka lama yang salah.
 *
 * @param bool $dryRun true = hanya hitung, tidak menulis apa pun
 */
function stock_sync_usage(int $productId, string $fromTgl, string $toTgl = '',
                          float $rollW = 0, bool $dryRun = false): array
{
    $p = stock_product($productId);
    if (!$p) return ['ok' => false, 'error' => 'Produk tidak ditemukan'];
    $toTgl  = $toTgl !== '' ? $toTgl : date('Y-m-d');
    if ($rollW <= 0) $rollW = (float)($p['roll_width_m'] ?? 0);

    $st = db()->prepare(
        "SELECT oi.id AS item_id, o.id AS order_id, o.order_number, o.order_date,
                oi.product_name, oi.qty, oi.width_m, oi.height_m
           FROM order_items oi JOIN orders o ON o.id = oi.order_id
          WHERE o.status <> 'batal' AND o.order_date BETWEEN ? AND ?
          ORDER BY o.order_date, oi.id"
    );
    $st->execute([$fromTgl, $toTgl]);

    $perHari = []; $dilewati = 0;
    foreach ($st->fetchAll() as $r) {
        $mp = stock_match_product((string)$r['product_name']);
        if (!$mp || (int)$mp['id'] !== $productId) continue;

        $w = (float)($r['width_m'] ?? 0);
        $h = (float)($r['height_m'] ?? 0);
        if ($w <= 0 && $h <= 0) {
            $sz = stock_parse_size_from_name((string)$r['product_name']);
            if ($sz) { $w = $sz['w']; $h = $sz['h']; }
        }
        if ($w <= 0 && $h <= 0) { $dilewati++; continue; }

        $d = substr((string)$r['order_date'], 0, 10);
        for ($i = 0, $q = max(1, (int)$r['qty']); $i < $q; $i++) {
            $perHari[$d][] = ['w' => $w, 'h' => $h, 'order_no' => (string)$r['order_number']];
        }
    }

    $hari = []; $totalM = 0.0;
    foreach ($perHari as $d => $items) {
        $m = stock_nesting_meters($items, $rollW);
        $hari[$d] = ['m' => $m, 'n' => count($items)];
        $totalM += $m;
    }
    ksort($hari);

    $hasil = ['ok' => true, 'hari' => $hari, 'total_m' => round($totalM, 3),
              'dilewati' => $dilewati, 'dari' => $fromTgl, 'sampai' => $toTgl,
              'roll_w' => $rollW, 'dry_run' => $dryRun];
    if ($dryRun) return $hasil;

    db()->beginTransaction();
    try {
        db()->prepare("DELETE FROM stock_movements
                        WHERE product_id = ? AND reason IN ('produksi','pemakaian_historis','sync_pemakaian')")
            ->execute([$productId]);
        db()->prepare("UPDATE stock_rolls SET used_m = 0, status = 'aktif'
                        WHERE product_id = ? AND status <> 'rusak'")->execute([$productId]);

        $rollLen = max(0.01, (float)($p['roll_length_m'] ?? 70));
        $baris = 0;
        foreach ($hari as $d => $h) {
            if ($h['m'] <= 0) continue;
            $rl = db()->prepare("SELECT id FROM stock_rolls
                                  WHERE product_id = ? AND used_m < length_m - 0.001
                                  ORDER BY (received_at IS NULL), received_at ASC, roll_no ASC LIMIT 1");
            $rl->execute([$productId]);
            $rollId = $rl->fetchColumn();

            $res = stock_ledger_add($productId, 'potong', -$h['m'], [
                'reason'    => 'sync_pemakaian',
                'note'      => 'Sinkron otomatis: ' . $h['n'] . ' potong pada ' . date('d/m/Y', strtotime($d)),
                'ref_type'  => 'sync',
                'roll_id'   => $rollId ? (int)$rollId : null,
                'unit'      => 'roll',
                'qty'       => -round($h['m'] / $rollLen, 4),
                'unit_cost' => stock_cost_per_meter($p),
                'skip_cache'=> true,
            ]);
            if (!empty($res['ok'])) {
                db()->prepare("UPDATE stock_movements SET created_at = ? WHERE id = ?")
                    ->execute([$d . ' 12:00:00', (int)$res['movement_id']]);
                $baris++;
            }
        }
        // roll yang sudah terpakai penuh -> tandai habis
        db()->prepare("UPDATE stock_rolls SET status='habis'
                        WHERE product_id = ? AND used_m >= length_m - 0.001 AND status='aktif'")
            ->execute([$productId]);
        stock_recalc_cache($productId);
        db()->commit();
        $hasil['baris'] = $baris;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $hasil['stok_akhir'] = stock_total_meters($productId);
    return $hasil;
}

/** Ambil ringkasan saldo 1 produk (untuk tampilan & alert). */
function stock_status(int $productId): array
{
    $p = stock_product($productId);
    if (!$p) return [];
    $len = (float)($p['roll_length_m'] ?? 0);

    $st = db()->prepare(
        "SELECT COUNT(*) AS rolls_aktif,
                COALESCE(SUM(GREATEST(length_m - used_m,0)),0) AS sisa_meter
           FROM stock_rolls WHERE product_id = ? AND status = 'aktif'"
    );
    $st->execute([$productId]);
    $r = $st->fetch() ?: ['rolls_aktif' => 0, 'sisa_meter' => 0];

    $stU = db()->prepare("SELECT COUNT(*) FROM stock_rolls WHERE product_id = ? AND status='aktif' AND used_m <= 0.001");
    $stU->execute([$productId]);
    $utuh = (int)$stU->fetchColumn();

    $sisaMeter = (float)$r['sisa_meter'];
    $cpm = stock_cost_per_meter($p);

    /* Nilai pakai LUAS (m²), bukan meter — lihat catatan panjang di
     * stock_cost_per_m2(). Penting begitu ada strip hasil guntingan. */
    $cpm2 = stock_cost_per_m2($p);
    $area = stock_roll_area_m2($productId);
    $nilai = ($cpm2 > 0 && $area > 0) ? $area * $cpm2 : $sisaMeter * $cpm;

    return [
        'product_id'   => $productId,
        'name'         => function_exists('stock_nama_tampil')
                            ? stock_nama_tampil($p)
                            : (ucfirst((string)$p['category']) . ' ' . (string)$p['variant']),
        'rolls_total'  => (int)$r['rolls_aktif'],
        'rolls_utuh'   => $utuh,
        'roll_width_m' => (float)($p['roll_width_m'] ?? 0),
        'roll_length_m'=> $len,
        'sisa_meter'   => round($sisaMeter, 2),
        'sisa_m2'      => $area,
        'nilai_modal'  => round($nilai, 2),
        'cost_per_meter' => $cpm,
        'cost_per_m2'  => $cpm2,
    ];
}

/** Ringkasan semua produk bahan (untuk halaman kartu stok & dashboard). */
function stock_status_all(): array
{
    $rows = db()->query("SELECT * FROM product_prices WHERE track_stock = 1 OR unit = 'roll' ORDER BY category, variant")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $s = stock_status((int)$r['id']);
        if ($s) $out[] = $s;
    }
    return $out;
}

/**
 * Nilai modal saldo dalam SATUAN ASLI produk (untuk semua jenis satuan).
 * Dipakai halaman Kartu Stok agar tinta (jergen/botol) & pcs ikut terhitung.
 */
/* =====================================================================
 * HARGA PER LUAS (m²) — untuk bahan yang DIGUNTING MEMANJANG
 * =====================================================================
 * KENAPA INI PERLU (bukti nyata 12 Okt 2026)
 * Pemilik menggunting roll 3,2 m memanjang menjadi strip 2,1 m / 1,6 m / 1,1 m.
 * Panjangnya tetap 70 m, jadi:
 *     roll 3,2 m × 70 m = 224 m²  →  2,1×70 + 1,1×70 = 147 + 77 = 224 m² ✓
 * Bahannya TIDAK berubah, tapi JUMLAH METER-nya berlipat:
 *     70 m  →  140 m  pada satu roll yang sama.
 *
 * Karena itu "meter" TIDAK BOLEH dipakai untuk menghitung nilai begitu ada
 * strip: satu meter dari strip 1,1 m hanya berisi 1,1 m² bahan, sedangkan satu
 * meter roll 3,2 m berisi 3,2 m² — tiga kali lebih banyak.
 *
 * Akibat nyata kalau tetap pakai meter: strip 1,1 m dihargai seolah roll penuh,
 * sehingga nilai persediaan tampak naik palsu (terukur: Rp 12,28 juta →
 * Rp 15,22 juta hanya karena penggantian susunan roll yang sama).
 * ===================================================================== */

/**
 * Harga modal per LUAS (m²) bahan roll.
 * = cost_per_roll ÷ (panjang roll × lebar roll)
 * Contoh Flexy: 1.456.000 ÷ (70 × 3,2) = **Rp 6.500 / m²**
 */
function stock_cost_per_m2(array $p): float
{
    $perRoll = (float)($p['cost_per_roll'] ?? 0);
    $len     = (float)($p['roll_length_m'] ?? 0);
    $wide    = (float)($p['roll_width_m'] ?? 0);
    if ($perRoll > 0 && $len > 0 && $wide > 0) {
        return round($perRoll / ($len * $wide), 2);
    }
    $cpm = stock_cost_per_meter($p);
    return ($cpm > 0 && $wide > 0) ? round($cpm / $wide, 2) : 0.0;
}

/**
 * Luas (m²) sisa SELURUH roll aktif sebuah produk.
 * Dijumlah per roll memakai LEBAR MASING-MASING, jadi strip hasil guntingan
 * otomatis terhitung lebih kecil daripada roll lebar penuh.
 */
function stock_roll_area_m2(int $productId): float
{
    try {
        $st = db()->prepare(
            "SELECT COALESCE(SUM(GREATEST(length_m - used_m, 0) * width_m), 0)
               FROM stock_rolls WHERE product_id = ? AND status = 'aktif'");
        $st->execute([$productId]);
        return round((float)$st->fetchColumn(), 3);
    } catch (Throwable $e) {
        return 0.0;
    }
}

function stock_unit_value(int $productId): array
{
    $p = stock_product($productId);
    if (!$p) return ['qty' => 0.0, 'unit' => '', 'unit_cost' => 0.0, 'value' => 0.0];

    $b    = stock_balance_unit($productId);
    $unit = $b['unit'];
    $isRoll = in_array($unit, ['roll', 'meter'], true);

    if ($isRoll) {
        // Hitung dari harga per ROLL (presisi penuh), bukan cost_per_meter yang
        // sudah dibulatkan — supaya 8 × Rp 1.500.000 = Rp 12.000.000 pasti tepat.
        $perRoll = (float)($p['cost_per_roll'] ?? 0);
        $len     = (float)($p['roll_length_m'] ?? 0);
        $perMeter = $perRoll > 0 && $len > 0 ? $perRoll / $len : stock_cost_per_meter($p);
        $meters  = (float)($b['meters'] ?? 0);

        /* ===== NILAI = LUAS × harga per m²  (diperbaiki 12 Okt 2026) =====
         * Dulu `meters × perMeter`. Itu masih benar HANYA selama semua roll
         * selebar produk. Begitu ada strip hasil guntingan, satu meter strip
         * berisi bahan jauh lebih sedikit → nilai jadi melonjak palsu.
         * Kalau luas tidak tersedia (mis. roll belum dibuat), jatuh kembali ke
         * cara meter supaya tidak kehilangan nilai sama sekali. */
        $perM2 = stock_cost_per_m2($p);
        $area  = stock_roll_area_m2($productId);
        $nilai = ($perM2 > 0 && $area > 0) ? $area * $perM2 : $meters * $perMeter;

        return [
            'qty'       => $b['qty'],
            'unit'      => $unit,
            'unit_cost' => round($perRoll > 0 ? $perRoll : $perMeter * $len, 2),
            'cost_meter'=> round($perMeter, 2),
            'cost_m2'   => $perM2,
            'area_m2'   => $area,
            'value'     => round($nilai, 2),
            'is_roll'   => true,
        ];
    }

    $cost = (float)($p['cost_per_unit'] ?? 0);
    return [
        'qty'       => $b['qty'],
        'unit'      => $unit,
        'unit_cost' => round($cost, 2),
        'cost_meter'=> 0.0,
        'value'     => round($b['qty'] * $cost, 2),
        'is_roll'   => false,
    ];
}

/** Nilai total SELURUH persediaan (roll + satuan umum) — aset bahan. */
function stock_inventory_value_all(): float
{
    $total = 0.0;
    $rows = db()->query("SELECT id FROM product_prices WHERE track_stock = 1 OR unit = 'roll'")->fetchAll();
    foreach ($rows as $r) {
        $v = stock_unit_value((int)$r['id']);
        $total += (float)$v['value'];
    }
    return round($total, 2);
}

/** Pemakaian & kerusakan dalam periode (untuk laporan keuangan). */
function stock_usage_totals(string $start, string $end): array
{
    $st = db()->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type='potong' THEN ABS(meters) ELSE 0 END),0) AS meter_potong,
            COALESCE(SUM(CASE WHEN type='potong' THEN ABS(COALESCE(total_cost,0)) ELSE 0 END),0) AS biaya_potong,
            COALESCE(SUM(CASE WHEN type='rusak'  THEN ABS(meters) ELSE 0 END),0) AS meter_rusak,
            COALESCE(SUM(CASE WHEN type='rusak'  THEN ABS(COALESCE(total_cost,0)) ELSE 0 END),0) AS biaya_rusak,
            COALESCE(SUM(CASE WHEN type='masuk'  THEN meters ELSE 0 END),0) AS meter_masuk,
            COALESCE(SUM(CASE WHEN type='masuk'  THEN COALESCE(total_cost,0) ELSE 0 END),0) AS biaya_masuk
           FROM stock_movements
          WHERE DATE(created_at) BETWEEN ? AND ?"
    );
    $st->execute([$start, $end]);
    $r = $st->fetch() ?: [];
    return [
        'meter_potong' => round((float)($r['meter_potong'] ?? 0), 2),
        'biaya_potong' => round((float)($r['biaya_potong'] ?? 0), 2),
        'meter_rusak'  => round((float)($r['meter_rusak'] ?? 0), 2),
        'biaya_rusak'  => round((float)($r['biaya_rusak'] ?? 0), 2),
        'meter_masuk'  => round((float)($r['meter_masuk'] ?? 0), 2),
        'biaya_masuk'  => round((float)($r['biaya_masuk'] ?? 0), 2),
    ];
}

/** Nilai stok bahan saat ini (aset) — total seluruh produk. */
function stock_inventory_value(): float
{
    $total = 0.0;
    foreach (stock_status_all() as $s) $total += (float)$s['nilai_modal'];
    return round($total, 2);
}

/**
 * HPP (Harga Pokok Penjualan) bahan untuk periode tertentu.
 * = nilai bahan yang BENAR-BENAR TERPAKAI produksi (potong) + yang RUSAK.
 * Dipakai laporan keuangan supaya laba tidak lagi "los".
 */
function stock_hpp_period(string $start, string $end): array
{
    $st = db()->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type='potong' THEN ABS(COALESCE(total_cost,0)) ELSE 0 END),0) AS hpp,
            COALESCE(SUM(CASE WHEN type='potong' THEN ABS(meters) ELSE 0 END),0)                AS meter,
            COALESCE(SUM(CASE WHEN type='rusak'  THEN ABS(COALESCE(total_cost,0)) ELSE 0 END),0) AS rugi,
            COALESCE(SUM(CASE WHEN type='rusak'  THEN ABS(meters) ELSE 0 END),0)                AS meter_rusak,
            COALESCE(SUM(CASE WHEN type='masuk'  THEN COALESCE(total_cost,0) ELSE 0 END),0)     AS beli,
            COUNT(CASE WHEN type='potong' THEN 1 END)                                           AS n_potong
           FROM stock_movements
          WHERE DATE(created_at) BETWEEN ? AND ?"
    );
    $st->execute([$start, $end]);
    $r = $st->fetch() ?: [];
    return [
        'hpp'         => round((float)($r['hpp'] ?? 0), 2),
        'meter'       => round((float)($r['meter'] ?? 0), 2),
        'rugi_rusak'  => round((float)($r['rugi'] ?? 0), 2),
        'meter_rusak' => round((float)($r['meter_rusak'] ?? 0), 2),
        'beli'        => round((float)($r['beli'] ?? 0), 2),
        'n_potong'    => (int)($r['n_potong'] ?? 0),
    ];
}

/* =====================================================================
 * HITUNG ULANG HPP LAMA — KOREKSI LEBAR ROLL (12 Okt 2026)
 * =====================================================================
 * Kenapa perlu: sampai 12 Okt 2026, HPP disimpan memakai `stock_cost_per_meter()`
 * = Rp 20.800/m untuk SEMUA lebar roll (harga roll 3,2 m penuh). Pekerjaan yang
 * dipotong dari strip 1,1 / 1,6 / 2,1 m jadi dibebani terlalu mahal, sehingga
 * laba bulan-bulan lalu dilaporkan lebih kecil dari kenyataan.
 *
 * Fungsi ini menulis ulang `unit_cost` & `total_cost` untuk pergerakan
 * `potong` dan `koreksi` memakai LEBAR ROLL yang benar-benar dipakai.
 *
 * ⚠️ TIGA PENGAMAN:
 *   1. DEFAULT DRY-RUN. Tidak menulis apa pun kecuali `['apply' => true]`.
 *   2. ANTI-GANDA. Rumus memakai patokan `unit_cost lama ÷ lebar produk`.
 *      Menjalankannya dua kali akan merusak angka (unit_cost sudah berubah),
 *      jadi hasilnya ditandai lewat setting `stock_hpp_lebar_fix` dan
 *      dijalankan ulang hanya bila `['paksa' => true]`.
 *   3. JEJAK. Sebelum menulis, seluruh baris yang diubah disimpan ke
 *      `logs/hpp_lebar_backup_<waktu>.json` supaya bisa dikembalikan.
 *
 * Baris yang TIDAK bisa dikoreksi: roll-nya sudah dihapus (tidak ada di
 * stock_rolls). Untuk data 14 Sep–9 Okt itu memang TIDAK PERLU dikoreksi —
 * saat itu semua bahan masih roll 3,2 m (guntingan baru mulai 9 Okt), jadi
 * harganya sudah benar.
 * ===================================================================== */
function stock_hpp_recompute_width(array $opt = []): array
{
    $apply = !empty($opt['apply']);
    $paksa = !empty($opt['paksa']);

    try {
        $sudah = (string)(getSetting('stock_hpp_lebar_fix', '') ?? '');
        if ($sudah !== '' && !$paksa) {
            return ['ok' => true, 'sudah' => true, 'perubahan' => [], 'ringkas' => [],
                    'error' => '', 'pesan' => 'Sudah pernah dijalankan pada ' . $sudah];
        }

        /* Patokan lebar produk per produk roll. */
        $peta = [];
        foreach (db()->query("SELECT id, roll_width_m, roll_length_m, cost_per_roll, unit
                                FROM product_prices
                               WHERE unit IN ('roll','meter') AND roll_width_m > 0") as $p) {
            $peta[(int)$p['id']] = [
                'w'   => (float)$p['roll_width_m'],
                'len' => (float)$p['roll_length_m'],
                'roll'=> (float)$p['cost_per_roll'],
            ];
        }
        if (!$peta) {
            return ['ok' => false, 'perubahan' => [], 'ringkas' => [],
                    'error' => 'Tidak ada produk roll dengan lebar terisi'];
        }

        $sql = "SELECT m.id, m.product_id, DATE(m.created_at) AS tgl, m.type,
                       ROUND(m.meters,3) AS meters, m.unit_cost,
                       ROUND(COALESCE(m.total_cost,0),2) AS total_lama,
                       m.roll_id, r.width_m AS roll_w
                  FROM stock_movements m
                  JOIN stock_rolls r ON r.id = m.roll_id
                 WHERE m.type IN ('potong','koreksi')
                   AND m.roll_id IS NOT NULL
                   AND m.unit_cost > 0
                   AND m.product_id IN (" . implode(',', array_map('intval', array_keys($peta))) . ")
                 ORDER BY m.id";
        $rows = db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return ['ok' => false, 'perubahan' => [], 'ringkas' => [], 'error' => $e->getMessage()];
    }

    $perubahan = [];
    $bulan = [];
    $lamaT = 0.0; $baruT = 0.0;
    foreach ($rows as $x) {
        $pid  = (int)$x['product_id'];
        $wProd = (float)($peta[$pid]['w'] ?? 0);
        $rl   = (float)($x['roll_w'] ?? 0);
        $uc   = (float)$x['unit_cost'];
        if ($wProd <= 0 || $rl <= 0 || $uc <= 0) continue;

        /* --- PENGAMAN 12 Okt 2026: JANGAN KOREKSI DUA KALI ---
         * Patokan di bawah (`uc ÷ lebar produk`) HANYA sah untuk baris yang
         * masih memakai harga lebar penuh. Kalau baris SUDAH benar (mis. dari
         * koreksi sebelumnya, atau dibuat sesudah `stock_cost_per_meter_for_roll`
         * dipasang), patokan itu justru menghasilkan angka palsu:
         *   sudah benar : uc = 7.150 (= 1,1 × 6.500)
         *   patokan lama: 7.150 ÷ 3,2 = 2.234 → 1,1 × 2.234 = 2.458  ← SALAH
         * Terbukti saat memeriksa 12 Okt: menjalankan ulang TANPA pengaman ini
         * akan merusak 47 baris yang sudah benar.
         * CATATAN: kunci array $peta memakai nama pendek (w/len/roll), BUKAN
         * nama kolom — jadi harus dipetakan dulu sebelum dipakai. */
        $hargaKini = 0.0;
        if ($wProd > 0 && (float)($peta[$pid]['roll'] ?? 0) > 0 && (float)($peta[$pid]['len'] ?? 0) > 0) {
            $hargaKini = round((float)$peta[$pid]['roll'] / ((float)$peta[$pid]['len'] * $wProd), 2);
        }
        if ($hargaKini > 0 && abs($rl * $hargaKini - $uc) < 0.5) continue;   // sudah benar

        // Patokan harga/m2 DARI HARGA YANG BERLAKU SAAT ITU (bukan harga kini)
        // supaya koreksi ini MURNI soal lebar, bukan soal perubahan harga.
        $hargaM2 = $uc / $wProd;
        $ucBaru  = round($rl * $hargaM2, 2);
        if (abs($ucBaru - $uc) < 0.01) continue;      // sudah benar

        $mt    = (float)$x['meters'];
        $totBaru = round($mt * $ucBaru, 2);
        $b = substr((string)$x['tgl'], 0, 7);
        $bulan[$b]['lama'] = ($bulan[$b]['lama'] ?? 0) + (float)$x['total_lama'];
        $bulan[$b]['baru'] = ($bulan[$b]['baru'] ?? 0) + $totBaru;
        $bulan[$b]['n']    = ($bulan[$b]['n'] ?? 0) + 1;
        $lamaT += (float)$x['total_lama'];
        $baruT += $totBaru;

        $perubahan[] = [
            'id'         => (int)$x['id'],
            'product_id' => $pid,
            'tgl'        => (string)$x['tgl'],
            'meters'     => $mt,
            'roll_w'     => $rl,
            'unit_lama'  => $uc,
            'unit_baru'  => $ucBaru,
            'total_lama' => (float)$x['total_lama'],
            'total_baru' => $totBaru,
        ];
    }

    ksort($bulan);
    $ringkas = [
        'n'        => count($perubahan),
        'lama'     => round($lamaT, 2),
        'baru'     => round($baruT, 2),
        'beda'     => round($baruT - $lamaT, 2),
        'bulan'    => $bulan,
        'dilewati' => count($rows) - count($perubahan),
    ];

    if (!$perubahan) {
        return ['ok' => true, 'perubahan' => [], 'ringkas' => $ringkas, 'error' => '',
                'pesan' => 'Tidak ada baris yang perlu dikoreksi'];
    }
    if (!$apply) {
        return ['ok' => true, 'perubahan' => $perubahan, 'ringkas' => $ringkas,
                'error' => '', 'pesan' => 'Pratinjau saja — belum ada yang diubah'];
    }

    /* ---- TULIS ---- */
    $berkas = '';
    try {
        $dir = '/var/www/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $berkas = $dir . '/hpp_lebar_backup_' . date('Ymd_His') . '.json';
        @file_put_contents($berkas, json_encode($perubahan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) { $berkas = ''; }

    try {
        if (!db()->inTransaction()) db()->beginTransaction();
        $st = db()->prepare("UPDATE stock_movements
                                SET unit_cost = ?, total_cost = ?
                              WHERE id = ? AND type IN ('potong','koreksi')");
        foreach ($perubahan as $c) {
            $st->execute([$c['unit_baru'], $c['total_baru'], $c['id']]);
        }
        if (db()->inTransaction()) db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'perubahan' => $perubahan, 'ringkas' => $ringkas,
                'error' => 'Gagal menyimpan: ' . $e->getMessage()];
    }

    setSetting('stock_hpp_lebar_fix', date('Y-m-d H:i:s'));
    if (function_exists('writeLog')) {
        writeLog('HPP: koreksi lebar roll — ' . count($perubahan) . ' baris, selisih Rp '
                 . number_format($baruT - $lamaT, 0, ',', '.') . ($berkas ? " · backup $berkas" : ''), 'INFO');
    }

    return ['ok' => true, 'perubahan' => $perubahan, 'ringkas' => $ringkas, 'error' => '',
            'backup' => $berkas, 'pesan' => 'Selesai — ' . count($perubahan) . ' baris diperbaiki'];
}

/* =====================================================================
 * PENERIMAAN ROLL BERKELOMPOK (LEBAR BERBEDA / HASIL SLIT) — 2026-10-09
 * =====================================================================
 * Kenapa perlu:
 *   Bahan spanduk datang sebagai roll lebar 320 cm, lalu DIPOTONG MEMANJANG
 *   (slit) menjadi beberapa lebar supaya tidak terbuang saat mencetak ukuran
 *   kecil — mis. 320cm → 210cm, 160cm, 110cm.
 *   Nota supplier mencatat "6 roll 3.2m x 70m", tetapi yang benar-benar
 *   disimpan di gudang adalah roll-roll hasil potongan dengan lebar berbeda.
 *   Tanpa fungsi ini, stok fisik TIDAK PERNAH cocok dengan sistem.
 * ===================================================================== */

/**
 * Apakah penerimaan bahan boleh membuat baris Pengeluaran kategori "Bahan"?
 *
 * ---------------------------------------------------------------------
 * FIX 2026-10-09 — PEMBELIAN BAHAN DIHITUNG DUA KALI (laba jadi rugi palsu)
 * ---------------------------------------------------------------------
 * MASALAH: setiap "Terima Stok" / "Nota Supplier" membuat baris `expenses`
 * kategori 'Bahan'. Padahal pemakaian bahan SUDAH dihitung sebagai HPP dari
 * kartu stok (stock_hpp_period). Di laporan laba keduanya dikurangkan:
 *
 *     Laba = Pendapatan − HPP(pemakaian) − Pengeluaran(termasuk 'Bahan')
 *
 * Contoh nyata (Okt 2026) saat nota supplier Rp 9.951.000 diunggah:
 *     laba sebelum : Rp  2.119.402
 *     laba sesudah : Rp −7.831.598    ← pembelian dikurangkan DUA KALI
 *
 * DEFAULT sekarang = 0 (JANGAN buat expenses). Jumlah pembelian TIDAK hilang:
 * tetap tersimpan di `stock_movements` (type='masuk'), dan halaman Keuangan
 * sudah menampilkannya sebagai "Beli bahan" ($hpp['beli']).
 * Owner bisa menyalakannya lagi bila memang ingin mencatat pembelian sebagai
 * pengeluaran kas terpisah.
 */
function stock_buat_expenses_bahan(): bool
{
    return (string)getSetting('stock_purchase_expense', '0') === '1';
}

/**
 * Terima BEBERAPA kelompok roll dengan LEBAR BERBEDA dalam sekali proses.
 *
 * @param array $groups [['count'=>N,'width'=>W,'cost'=>C], …]
 *                      cost = harga PER ROLL (0 → pakai harga produk)
 * @return array{ok:bool,rolls:int,meters:float,cost_total:float,rincian?:array,error:string}
 */
function stock_receive_roll_groups(int $productId, array $groups, float $defaultLength, array $opt = []): array
{
    $p = stock_product($productId);
    if (!$p) return ['ok' => false, 'error' => 'Produk tidak ditemukan', 'rolls' => 0, 'meters' => 0.0, 'cost_total' => 0.0];

    $bersih = [];
    foreach ($groups as $g) {
        $n = (int)round((float)($g['count'] ?? 0));
        $w = round((float)($g['width'] ?? 0), 2);
        $c = (float)($g['cost'] ?? 0);
        if ($n > 0 && $w > 0) $bersih[] = ['count' => $n, 'width' => $w, 'cost' => $c];
    }
    if (!$bersih) {
        return ['ok' => false, 'error' => 'Isi minimal satu lebar roll dengan jumlah lebih dari 0', 'rolls' => 0, 'meters' => 0.0, 'cost_total' => 0.0];
    }

    $len = $defaultLength > 0 ? $defaultLength : (float)($p['roll_length_m'] ?? 0);
    if ($len <= 0) {
        return ['ok' => false, 'error' => 'Panjang roll belum diisi (atur di menu Produk)', 'rolls' => 0, 'meters' => 0.0, 'cost_total' => 0.0];
    }

    $tanggal = (string)($opt['received_at'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) $tanggal = date('Y-m-d');
    $catatan = (string)($opt['note'] ?? '');
    $alasan  = (string)($opt['reason'] ?? 'beli');

    $totalRoll = 0; $totalMeter = 0.0; $totalCost = 0.0; $rincian = [];

    try {
        foreach ($bersih as $g) {
            $hargaRoll = $g['cost'] > 0 ? $g['cost'] : (float)($p['cost_per_roll'] ?? 0);

            // 1) Baris roll fisik dengan lebar & panjang sesuai kenyataan
            $nomorMulai = (int)db()->query("SELECT COALESCE(MAX(roll_no),0) FROM stock_rolls WHERE product_id = " . (int)$productId)->fetchColumn();
            for ($i = 1; $i <= $g['count']; $i++) {
                $nomorMulai++;
                db()->prepare(
                    "INSERT INTO stock_rolls (product_id, roll_no, label, width_m, length_m, used_m, status, received_at, note)
                     VALUES (?,?,?,?,?,0,'aktif',?,?)"
                )->execute([
                    $productId, $nomorMulai,
                    'lebar ' . rtrim(rtrim(number_format($g['width'], 2, ',', '.'), '0'), ',') . 'm',
                    $g['width'], $len, $tanggal,
                    $catatan !== '' ? mb_substr($catatan, 0, 250) : null,
                ]);
            }

            // 2) Catatan buku besar (satu baris per kelompok lebar)
            $meterGrup = $g['count'] * $len;
            $biayaGrup = $g['count'] * $hargaRoll;
            $catGrup = 'terima ' . $g['count'] . ' roll lebar '
                . rtrim(rtrim(number_format($g['width'], 2, ',', '.'), '0'), ',') . 'm'
                . ($catatan !== '' ? ' — ' . $catatan : '');

            stock_ledger_add($productId, 'masuk', $meterGrup, [
                'reason'     => $alasan,
                'note'       => $catGrup,
                'ref_type'   => 'terima_grup',
                'unit'       => 'roll',
                'unit_cost'  => $hargaRoll > 0 ? $hargaRoll : null,
                'skip_cache' => true,
            ]);

            $totalRoll  += $g['count'];
            $totalMeter += $meterGrup;
            $totalCost  += $biayaGrup;
            $rincian[]  = $g['count'] . ' roll ' . rtrim(rtrim(number_format($g['width'], 2, ',', '.'), '0'), ',') . 'm';
        }

        // 3) Panjang roll + lebar MAKSIMUM di produk (dipakai perhitungan nesting)
        $lebarMaks = max(array_column($bersih, 'width'));
        db()->prepare("UPDATE product_prices SET roll_width_m = ?, roll_length_m = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$lebarMaks, $len, $productId]);

        if (function_exists('stock_recalc_cache')) stock_recalc_cache($productId);

        // 4) Pengeluaran bahan — hanya bila owner menyalakannya
        $buatExpense = stock_buat_expenses_bahan();
        if ($totalCost > 0 && $buatExpense) {
            db()->prepare("INSERT INTO expenses (amount, category, expense_date, note, created_by) VALUES (?,?,?,?,?)")
                ->execute([
                    $totalCost, 'Bahan', $tanggal,
                    'Beli ' . implode(' + ', $rincian) . ' ' . ($p['category'] ?? '') . ' ' . ($p['variant'] ?? '')
                        . ($catatan !== '' ? ' — ' . $catatan : ''),
                    (int)($opt['created_by'] ?? ($_SESSION['user_id'] ?? 0)) ?: null,
                ]);
        }

        return [
            'ok' => true, 'rolls' => $totalRoll, 'meters' => round($totalMeter, 2),
            'cost_total' => round($totalCost, 2), 'rincian' => $rincian,
            'expense_dibuat' => ($totalCost > 0 && $buatExpense),
            'error' => '',
        ];
    } catch (Throwable $e) {
        if (function_exists('writeLog')) writeLog('Gagal terima roll berkelompok: ' . $e->getMessage(), 'ERROR');
        return ['ok' => false, 'error' => $e->getMessage(), 'rolls' => 0, 'meters' => 0.0, 'cost_total' => 0.0];
    }
}

/**
 * Ringkasan stok per LEBAR untuk produk roll.
 *
 * Dipakai kartu stok & opname bertingkat, supaya petugas tahu berapa roll dan
 * berapa meter tersisa pada TIAP lebar (hasil potongan memanjang / slit).
 *
 * @return array<int,array{width:float,rolls:int,meters:float,utuh:int,terbuka:int}>
 */
function stock_roll_summary_by_width(int $productId): array
{
    $rows = db()->query(
        "SELECT width_m, COUNT(*) AS rolls,
                COALESCE(SUM(GREATEST(length_m - used_m, 0)), 0) AS meters,
                SUM(CASE WHEN used_m <= 0.001 THEN 1 ELSE 0 END) AS utuh,
                SUM(CASE WHEN used_m >  0.001 THEN 1 ELSE 0 END) AS terbuka
           FROM stock_rolls
          WHERE product_id = " . (int)$productId . " AND status = 'aktif'
          GROUP BY width_m ORDER BY width_m DESC"
    )->fetchAll();

    $keluar = [];
    foreach ($rows as $r) {
        $keluar[] = [
            'width'   => round((float)$r['width_m'], 2),
            'rolls'   => (int)$r['rolls'],
            'meters'  => round((float)$r['meters'], 2),
            'utuh'    => (int)$r['utuh'],
            'terbuka' => (int)$r['terbuka'],
        ];
    }
    return $keluar;
}

/**
 * Setel ULANG stok roll agar SESUAI kenyataan fisik (per lebar).
 *
 * Dipakai saat membenahi selisih stok: petugas memasukkan sisa nyata per lebar
 * (dari hitungan fisik), lalu fungsi ini menyusun ulang baris `stock_rolls`
 * supaya total per lebar sama dengan kenyataan, dan mencatat selisihnya ke
 * buku besar sebagai 'opname' (ada jejak audit, bukan perubahan diam-diam).
 *
 * ATURAN PENYUSUNAN:
 *   - Tiap lebar dibuatkan roll UTUH sebanyak hasil bagi (meter ÷ panjang roll).
 *   - Sisanya (< 1 roll) dijadikan SATU roll terbuka (used_m = panjang − sisa).
 *   - Roll yang ada sekarang dihapus & diganti, supaya tidak ada duplikat.
 *
 * @param array $perWidth Dua bentuk yang diterima:
 *                       a) daftar: [['lebar'=>3.2,'meter'=>560.2], ...] ← dianjurkan
 *                       b) peta  : ['3.20'=>560.2, '2.10'=>0.2, ...]  ← kunci WAJIB teks
 * @return array{ok:bool,laporan:array,error:string}
 */
function stock_set_rolls_by_width(int $productId, array $perWidth, array $opt = []): array
{
    $p = stock_product($productId);
    if (!$p) return ['ok' => false, 'laporan' => [], 'error' => 'Produk tidak ditemukan'];

    $len = (float)($p['roll_length_m'] ?? 0);
    if ($len <= 0) return ['ok' => false, 'laporan' => [], 'error' => 'Panjang roll belum diisi'];

    /* ===== NORMALISASI + PENGAMAN FORMAT — 12 Okt 2026 =====
     * JEBAKAN NYATA: kunci array desimal di PHP DIPAKSA menjadi bilangan bulat.
     *   ['3.20'=>…, '2.10'=>…, '1.60'=>…, '1.10'=>…]
     * menjadi kunci 3, 2, 1, 1 → **1,60 dan 1,10 SALING MENIMPA** (yang terakhir
     * menang), lalu lebar 3,2 ikut rusak jadi 3,0.
     * Terbukti 12 Okt 2026: uji opname gagal dengan
     *   "Duplicate entry '29-1' for key 'stock_rolls.uq_product_roll'"
     * karena semua lebar jadi tidak dikenali sehingga fungsi mencoba MEMBUAT
     * roll baru dengan nomor yang sudah terpakai.
     *
     * Karena itu bentuk peta dengan kunci BILANGAN ditolak terang-terangan —
     * lebih baik gagal jelas daripada diam-diam memasang lebar yang salah.
     * Handler di halaman stok sudah memakai kunci teks, jadi tidak terpengaruh. */
    $bersih = [];
    foreach ($perWidth as $k => $v) {
        if (is_array($v)) {                       // bentuk daftar (dianjurkan)
            $w = round((float)($v['lebar'] ?? 0), 2);
            $m = round((float)($v['meter'] ?? 0), 2);
        } elseif (is_string($k)) {                // bentuk peta dengan kunci teks
            $w = round((float)$k, 2);
            $m = round((float)$v, 2);
        } else {                                  // kunci integer → info lebar rusak
            return ['ok' => false, 'laporan' => [],
                    'error' => 'Format lebar tidak sah: kunci array desimal dipaksa PHP menjadi '
                             . 'bilangan bulat sehingga 1,60 dan 1,10 bertabrakan. '
                             . 'Kirim sebagai daftar [lebar, meter] atau pakai kunci teks (mis. "3.20").'];
        }
        if ($w <= 0 || $m < 0) continue;
        $bersih[number_format($w, 2, '.', '')] = ['lebar' => $w, 'meter' => $m];
    }
    if (!$bersih) return ['ok' => false, 'laporan' => [], 'error' => 'Tidak ada lebar yang sah diisi'];

    $tanggal = (string)($opt['received_at'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) $tanggal = date('Y-m-d');
    $catatan = (string)($opt['note'] ?? 'pembenahan stok fisik (slit)');

    // Meter sistem SEBELUM diubah (untuk hitung selisih)
    $sistemLama = stock_total_meters($productId);

    $rencana = [];
    $totalBaru = 0.0;
    $sebelumPerLebar = [];
    foreach (stock_roll_summary_by_width($productId) as $s) {
        // Kunci HARUS string: PHP memaksa kunci array desimal menjadi bilangan
        // bulat, sehingga 3.2 → 3 dan 1.6 → 1 (tabrakan dengan 1.1!). Akibatnya
        // lebar justru rusak dan total meter jadi salah.
        $sebelumPerLebar[number_format($s['width'], 2, '.', '')] = $s['meters'];
    }

    foreach ($bersih as $kunci => $x) {
        $w     = (float)$x['lebar'];
        $meter = (float)$x['meter'];
        $utuh  = $meter >= $len ? (int)floor($meter / $len) : 0;
        $sisa  = round($meter - ($utuh * $len), 2);
        $rencana[$kunci] = ['lebar' => $w, 'utuh' => $utuh, 'sisa' => $sisa, 'meter' => $meter];
        $totalBaru += $meter;
    }
    if (!$rencana) return ['ok' => false, 'laporan' => [], 'error' => 'Tidak ada lebar yang diisi'];

    $laporan = [];
    try {
        if (!db()->inTransaction()) db()->beginTransaction();

        /* ===== PERBAIKAN 12 Okt 2026: JANGAN HAPUS ROLL =====
         *
         * Versi lama memakai `DELETE FROM stock_rolls WHERE product_id = ?`.
         * Akibat nyata yang terukur: setiap kali opname disimpan, SEMUA id roll
         * hilang sehingga jejak di `stock_movements.roll_id` menjadi YATIM
         * (terukur: 30 baris, 381,79 m, roll 33–39). Panel "Bahan vs Mesin
         * Cetak" lalu kehilangan lebar roll dan jatuh ke lebar PRODUK (3,2 m) →
         * lima order melar +8,6 m² sehingga pemilik melihat "selisih 4,5" yang
         * sama sekali tidak nyata.
         *
         * Sekarang roll DISESUAIKAN, bukan diganti:
         *   • roll yang masih dipakai → meter-nya disetel, ID TETAP (jejak aman)
         *   • roll yang sudah tidak perlu → status 'arsip', ID TETAP (masih bisa
         *     ditelusuri & dibalikkan kalau ada koreksi)
         * Nomor roll dirapikan ulang supaya tampilan tetap berurutan. */
        /* ---- 1) PARKIR nomor roll dulu ----
         * `roll_no` unik per produk (uq_product_roll), jadi menomori ulang bisa
         * bertabrakan dengan roll yang BELUM tersentuh — khususnya roll yang
         * akan diarsipkan (pengarsipan baru dijalankan di akhir).
         * Terbukti 12 Okt 2026: opname bagi-ulang gagal dengan
         *   "Duplicate entry '29-9' for key 'stock_rolls.uq_product_roll'"
         * karena roll 97 masih memakai 9 saat roll 98 diberi nomor 9.
         * Semua nomor aktif digeser ke atas dulu agar ruang nomor 1..N bebas. */
        db()->prepare("UPDATE stock_rolls SET roll_no = 10000 + id
                        WHERE product_id = ?")->execute([$productId]);

        /* Kandidat: roll AKTIF dulu, baru yang ARSIP — supaya roll yang pernah
         * diarsipkan bisa DIPAKAI ULANG bila dibutuhkan lagi. Ini menjaga jumlah
         * id tetap sedikit: opname-batal-opname tidak menumpuk roll baru terus.
         * Aman karena meter setiap roll disetel ulang dari hasil hitung fisik. */
        $ada = db()->prepare(
            "SELECT id, width_m FROM stock_rolls
              WHERE product_id = ?
              ORDER BY (status = 'aktif') DESC, (used_m <= 0.001) DESC, id");
        $ada->execute([$productId]);
        $perLebar = [];
        foreach ($ada->fetchAll() as $x) {
            $perLebar[number_format((float)$x['width_m'], 2, '.', '')][] = $x;
        }

        $nomor      = 0;
        $lebarMaks  = 0.0;
        $aktif      = 0;
        $arsipIds   = [];

        foreach ($rencana as $r) {
            $w = (float)$r['lebar'];
            if ($w > $lebarMaks) $lebarMaks = $w;
            $labelLebar = 'lebar ' . rtrim(rtrim(number_format($w, 2, ',', '.'), '0'), ',') . 'm';
            $kunci      = number_format($w, 2, '.', '');
            $kandidat   = $perLebar[$kunci] ?? [];
            unset($perLebar[$kunci]);            // lebar ini sudah ditangani

            $butuhUtuh = (int)$r['utuh'];
            $punyaSisa = ($r['sisa'] > 0.01);
            $ci        = 0;

            /* ---- roll UTUH: pakai ulang bila ada, buat baru bila kurang ---- */
            for ($i = 1; $i <= $butuhUtuh; $i++) {
                $nomor++;
                if (isset($kandidat[$ci])) {
                    $id = (int)$kandidat[$ci]['id']; $ci++;
                    db()->prepare(
                        "UPDATE stock_rolls SET roll_no=?, label=?, width_m=?, length_m=?,
                                used_m=0, status='aktif', received_at=?, note=?, updated_at=NOW()
                          WHERE id=?"
                    )->execute([$nomor, $labelLebar, $w, $len, $tanggal, $catatan, $id]);
                } else {
                    db()->prepare(
                        "INSERT INTO stock_rolls (product_id, roll_no, label, width_m, length_m, used_m, status, received_at, note)
                         VALUES (?,?,?,?,?,0,'aktif',?,?)"
                    )->execute([$productId, $nomor, $labelLebar, $w, $len, $tanggal, $catatan]);
                }
                $aktif++;
            }

            /* ---- satu roll TERBUKA untuk sisanya ---- */
            if ($punyaSisa) {
                $nomor++;
                $used = round($len - $r['sisa'], 3);
                if (isset($kandidat[$ci])) {
                    $id = (int)$kandidat[$ci]['id']; $ci++;
                    db()->prepare(
                        "UPDATE stock_rolls SET roll_no=?, label=?, width_m=?, length_m=?,
                                used_m=?, status='aktif', received_at=?, note=?, updated_at=NOW()
                          WHERE id=?"
                    )->execute([$nomor, $labelLebar . ' (sisa)', $w, $len, $used, $tanggal, $catatan, $id]);
                } else {
                    db()->prepare(
                        "INSERT INTO stock_rolls (product_id, roll_no, label, width_m, length_m, used_m, status, received_at, note)
                         VALUES (?,?,?,?,?,?,'aktif',?,?)"
                    )->execute([$productId, $nomor, $labelLebar . ' (sisa)', $w, $len, $used, $tanggal, $catatan]);
                }
                $aktif++;
            }

            /* ---- kandidat lebar ini yang tidak terpakai → arsipkan ---- */
            for (; $ci < count($kandidat); $ci++) $arsipIds[] = (int)$kandidat[$ci]['id'];

            $lama = (float)($sebelumPerLebar[$kunci] ?? 0);
            $laporan[] = [
                'width' => $w,
                'lama'  => round($lama, 2),
                'baru'  => $r['meter'],
                'beda'  => round($r['meter'] - $lama, 2),
                'utuh'  => $r['utuh'],
                'sisa'  => $r['sisa'],
            ];
        }

        /* ---- lebar yang tidak lagi diisi → ARSIP, jangan dihapus ---- */
        foreach ($perLebar as $kandidat) {
            foreach ($kandidat as $x) $arsipIds[] = (int)$x['id'];
        }
        if ($arsipIds) {
            $ids = implode(',', array_map('intval', $arsipIds));
            /* Nomor roll yang diarsipkan DIGESER ke atas (1000 + id) supaya tidak
             * bentrok dengan penomoran baru. Tanpa ini, opname berikutnya yang
             * butuh roll lebih banyak akan gagal dengan
             * "Duplicate entry '29-9' for key stock_rolls.uq_product_roll"
             * karena kolom roll_no unik per produk. */
            db()->exec("UPDATE stock_rolls
                           SET status  = 'arsip',
                               roll_no = 1000 + id,
                               note    = CONCAT(COALESCE(note, ''), ' · diarsipkan opname " . date('d/m/Y') . "'),
                               updated_at = NOW()
                         WHERE id IN ($ids)");
        }

        // Lebar maksimum → dipakai perhitungan nesting
        db()->prepare("UPDATE product_prices SET roll_width_m = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$lebarMaks, $productId]);

        if (function_exists('stock_recalc_cache')) stock_recalc_cache($productId);

        // Catat SELISIH ke buku besar (jejak audit)
        $selisih = round($totalBaru - $sistemLama, 3);
        if (abs($selisih) >= 0.01) {
            stock_ledger_add($productId, 'opname', $selisih, [
                'reason'    => 'penyesuaian_fisik',
                'note'      => $catatan . ' — sistem ' . $sistemLama . ' m, fisik ' . $totalBaru . ' m (beda ' . $selisih . ' m)',
                'ref_type'  => 'opname_slit',
                'unit'      => 'roll',
                'skip_cache'=> true,
            ]);
        }

        if (db()->inTransaction()) db()->commit();
        return ['ok' => true, 'laporan' => $laporan, 'total_lama' => $sistemLama,
                'total_baru' => round($totalBaru, 2), 'selisih' => $selisih, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        if (function_exists('writeLog')) writeLog('Gagal setel roll per lebar: ' . $e->getMessage(), 'ERROR');
        return ['ok' => false, 'laporan' => [], 'error' => $e->getMessage()];
    }
}

/* =====================================================================
 * PEMERIKSA KESEHATAN PEMOTONGAN BAHAN — 2026-10-09
 * =====================================================================
 * KENAPA INI DIBUAT
 * ---------------------------------------------------------------------
 * Pemilik: "jujur kalau di sistem aja ga kebaca sudah susah, ini ada cara
 * ga antisipasi biar kedepan ga terjadi gini lagi"
 *
 * MASALAH NYATA yang terjadi: 79 dari 97 order x-banner TIDAK dipotong
 * stoknya, dan tidak ada satu pun peringatan. Baru ketahuan setelah
 * penghitungan fisik manual — itu sudah terlambat & menyakitkan.
 *
 * PENYEBAB yang berulang:
 *   a) operator menulis nama item dengan kata lain ("Spanduk X Banner ...")
 *      → deteksi bahan tidak mengenali
 *   b) produk bahan yang cocok TIDAK ADA atau stoknya 0
 *   c) kode pemotongan tidak pernah dipanggil untuk kategori itu
 *
 * Semua penyebab itu MEMPUNYAI GEJALA YANG BISA DILIHAT:
 *   → ada order yang seharusnya memotong bahan, tapi TIDAK ADA jejak
 *     pemotongan di `stock_movements`
 *
 * Fungsi ini mencari gejala itu dan MELAPORKANNYA, supaya ketahuan dalam
 * HITUNGAN JAM — bukan setelah hitung fisik berbulan-bulan kemudian.
 * ===================================================================== */

/**
 * Cari order yang SEHARUSNYA memotong bahan, tetapi TIDAK ADA jejaknya.
 *
 * Dijalankan dari halaman Kartu Stok supaya pemilik/admin melihat
 * peringatan begitu membuka halaman — tidak perlu menunggu audit manual.
 *
 * @param int $hari Periksa order berapa hari terakhir.
 * @return array Daftar temuan, masing-masing: tgl, order, item, qty, sebab.
 */
function stock_audit_pemotongan(int $hari = 14): array
{
    $temuan = [];

    /* Sejak kapan pemotongan bahan otomatis benar-benar berjalan?
     * Diambil dari movement `sync_pemakaian` PERTAMA di database, bukan
     * ditulis tetap di kode — supaya kalau sistem dimigrasi/di-restore,
     * batas ini ikut menyesuaikan sendiri dan tidak menimbulkan tuduhan
     * palsu pada periode yang memang belum tercatat. */
    $eraPotongAwal = null;
    try {
        $e0 = db()->query("SELECT MIN(DATE(created_at)) FROM stock_movements
                            WHERE type='potong' AND reason='sync_pemakaian'")->fetchColumn();
        if ($e0) $eraPotongAwal = (string)$e0;
    } catch (Throwable $e) {
        $eraPotongAwal = null;
    }

    /* Kata kunci yang MENANDAKAN sebuah item memakai bahan ber-stok.
     * Daftar ini sengaja LUAS & memuat variasi penulisan operator —
     * justru variasi itulah sumber masalahnya. */
    $polaBahan = [
        'xbanner'   => ['x banner', 'spanduk x', 'xbanner', 'spanduk bann'],
        'spanduk'   => ['spanduk', 'banner', 'baliho'],
        'sablon'    => ['sablon', 'kaos', 'baju', 'shirt', 'dtf', 'jersey'],
    ];

    try {
        $st = db()->prepare(
            "SELECT oi.id AS item_id, oi.product_name, oi.qty, o.order_number,
                    DATE(o.order_date) AS tgl, o.status, oi.tanpa_kaki
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
              WHERE o.status <> 'batal'
                AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              ORDER BY o.order_date DESC, oi.id DESC"
        );
        $st->execute([max(1, $hari)]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    foreach ($rows as $r) {
        $nama = mb_strtolower((string)$r['product_name']);
        $qty  = (float)$r['qty'];
        if ($qty <= 0) continue;

        // Apakah item ini seharusnya memakai bahan?
        $jenis = '';
        foreach ($polaBahan as $j => $kata) {
            foreach ($kata as $k) {
                if (strpos($nama, $k) !== false) { $jenis = $j; break 2; }
            }
        }
        if ($jenis === '') continue;

        /* TANPA KAKI (X/Y Banner) — MEMANG TIDAK ADA STOK TIANG YANG DIPOTONG.
         *
         * Pelanggan seperti adyt manek membeli CETAKAN saja karena sudah punya
         * tiang sendiri. Kalau baris begini tetap dilaporkan sebagai "x-banner
         * tidak dipotong", laporan audit justru penuh tuduhan palsu — dan
         * pemilik jadi tidak percaya pada laporan yang seharusnya membantunya.
         *
         * Yang WAJIB tetap terjadi walau tanpa kaki: bahan spanduk/flexy tetap
         * terpotong karena cetakannya tetap dibuat. Kalau bahan itu juga tidak
         * terpotong, itu masalah NYATA dan tetap harus dilaporkan. */
        $tanpaKaki = (int)($r['tanpa_kaki'] ?? 0) === 1;
        if ($tanpaKaki && $jenis === 'xbanner') {
            $adaBahan = false;
            try {
                $cb = db()->prepare(
                    "SELECT COUNT(*) FROM stock_movements
                      WHERE type = 'potong' AND reason IN ('sync_pemakaian','produksi','pemakaian_historis')
                        AND DATE(created_at) = ?"
                );
                $cb->execute([(string)$r['tgl']]);
                $adaBahan = ((int)$cb->fetchColumn()) > 0;
            } catch (Throwable $e) {
                $adaBahan = true; // ragu → jangan tuduh
            }
            if ($adaBahan) continue;   // sah: cetakan saja, bahan tetap dipotong
            /* Kalau bahan TIDAK terpotong hari itu, JANGAN langsung lolos —
             * biarkan pemeriksaan bahan roll di bawah yang memutuskan, karena
             * di sana ada batas era pra-sinkronisasi. Jadi tidak ada `continue`. */
        }

        // Apakah ada jejak pemotongan untuk item ini?
        try {
            $cq = db()->prepare("SELECT COUNT(*) FROM stock_movements
                                  WHERE ref_type = 'order' AND ref_id = ?
                                    AND type = 'potong'");
            $cq->execute([(int)$r['item_id']]);
            $ada = (int)$cq->fetchColumn();
        } catch (Throwable $e) {
            $ada = 0;
        }

        /* BAHAN ROLL DIPOTONG SECARA BATCH, BUKAN PER ORDER.
         *
         * Spanduk/flexy dipotong lewat `sync_pemakaian` (satu baris per hari,
         * menghitung SEMUA order hari itu sekaligus) — jadi `ref_id` per order
         * memang TIDAK ADA. Kalau hanya memeriksa `ref_id`, semua spanduk akan
         * tampak "tidak dipotong" → ratusan temuan PALSU yang menenggelamkan
         * masalah sebenarnya.
         *
         * Karena itu: bila bahan cocok adalah ROLL, cukup periksa apakah ADA
         * pemotongan apa pun untuk produk itu pada TANGGAL yang sama. */
        if ($ada === 0) {
            try {
                $pCek = function_exists('stock_match_product')
                    ? stock_match_product((string)$r['product_name']) : null;
                if ($pCek && in_array((string)($pCek['unit'] ?? ''), ['roll', 'meter'], true)) {
                    /* ERA PRA-SINKRONISASI — bahan ROLL saja.
                     *
                     * Pemotongan bahan roll otomatis baru berjalan sejak
                     * `sync_pemakaian` PERTAMA di database (terpantau
                     * 2026-09-14, tapi dibaca otomatis agar ikut menyesuaikan
                     * bila data di-restore/dimigrasi). Order SEBELUM tanggal
                     * itu memang tidak punya jejak potong — itu keterbatasan
                     * sistem lama, bukan bug yang bisa dikerjakan sekarang.
                     *
                     * Kalau tidak dibatasi, ratusan spanduk lama dilaporkan
                     * "⚠ TIDAK DIPOTONG" sehingga temuan yang SUNGGUH gawat
                     * justru tenggelam dan laporan kehilangan kepercayaan.
                     * Batas ini HANYA untuk bahan roll; bahan pcs (x-banner,
                     * kartu, dll) tetap selalu diperiksa. */
                    if ($eraPotongAwal !== null && (string)$r['tgl'] < $eraPotongAwal) {
                        continue;
                    }
                    $cq2 = db()->prepare(
                        "SELECT COUNT(*) FROM stock_movements
                          WHERE product_id = ?
                            AND type = 'potong'
                            AND DATE(created_at) = ?
                            AND reason IN ('sync_pemakaian','produksi','pemakaian_historis')"
                    );
                    $cq2->execute([(int)$pCek['id'], (string)$r['tgl']]);
                    $ada = (int)$cq2->fetchColumn();
                }
            } catch (Throwable $e) {
                // abaikan — kalau gagal, jatuh ke pemeriksaan per-item
            }
        }

        if ($ada > 0) continue;

        /* Belum dipotong. Tentukan SEBABNYA supaya pemilik tahu harus apa. */
        $sebab = 'Belum ada pemotongan tercatat';
        $p = function_exists('stock_match_product') ? stock_match_product((string)$r['product_name']) : null;
        if (!$p) {
            $sebab = 'Bahan tidak dikenali dari nama item';
        } elseif ((int)($p['track_stock'] ?? 0) !== 1) {
            /* Bahan ketemu tetapi TIDAK dilacak stoknya.
             * Ini BUKAN kesalahan — memang tidak semua bahan dilacak.
             * Dilewati supaya laporan tidak penuh kebisingan. */
            continue;
        } else {
            /* Stok bahan ada? Roll memakai stok meter (bukan stock_qty),
             * jadi WAJIB dicek lewat stock_total_meters() — kalau memakai
             * stock_qty mentah, semua bahan roll akan tampak "stok 0" dan
             * laporan jadi penuh temuan palsu. */
            $unit  = (string)($p['unit'] ?? '');
            $adalahRoll = in_array($unit, ['roll', 'meter'], true);
            $stokAda = $adalahRoll
                ? (function_exists('stock_total_meters') && stock_total_meters((int)$p['id']) > 0.001)
                : ((float)($p['stock_qty'] ?? 0) > 0.001);

            if (!$stokAda) {
                $sebab = 'Stok bahan "' . $p['category'] . ' ' . $p['variant'] . '" sedang 0 (perlu terima stok)';
            } else {
                // Bahan ada & stok ada → kode pemotongan tidak jalan. INI YANG GAWAT.
                $sebab = '⚠ TIDAK DIPOTONG padahal stok tersedia — "' . $p['category'] . ' ' . $p['variant'] . '"';
            }
        }

        $temuan[] = [
            'tgl'    => (string)$r['tgl'],
            'order'  => (string)$r['order_number'],
            'item'   => (string)$r['product_name'],
            'qty'    => $qty,
            'jenis'  => $jenis,
            'status' => (string)$r['status'],
            'sebab'  => $sebab,
        ];
    }

    return $temuan;
}

/**
 * Ringkasan singkat untuk badge/peringatan di halaman.
 *
 * Memisahkan temuan menjadi DUA golongan supaya peringatan tetap berguna:
 *   - GAWAT  : bahan TERSEDIA tetapi tidak dipotong → ada kerusakan logika
 *              di sistem. Ini yang harus segera diperbaiki.
 *   - WAJAR  : bahan belum ada / stok sedang 0 → operator perlu terima stok
 *              dulu. Bukan bug, hanya belum bisa dipotong.
 *
 * Tanpa pemisahan ini, laporan penuh oleh item yang memang belum bisa
 * dipotong (mis. semua spanduk saat stok roll kosong) sehingga masalah
 * yang SEBENARNYA jadi tenggelam.
 *
 * @return array{n:int,gawat:int,wajar:int,sebab:array<string,int>,daftar:array}
 */
function stock_audit_ringkas(int $hari = 14): array
{
    $t = stock_audit_pemotongan($hari);
    $sebab = []; $gawat = 0; $wajar = 0;
    foreach ($t as $x) {
        $k = $x['sebab'];
        $sebab[$k] = ($sebab[$k] ?? 0) + 1;
        if (strpos($k, '⚠') === 0) $gawat++;
        else                       $wajar++;
    }
    arsort($sebab);
    return [
        'n'     => count($t),
        'gawat' => $gawat,
        'wajar' => $wajar,
        'sebab' => $sebab,
        'daftar'=> $t,
    ];
}

/* =====================================================================
 * PINJAMAN BAHAN (kaki x-banner dll) — 2026-10-09
 * =====================================================================
 * PERMINTAAN PEMILIK:
 *   "kadang ada yang pinjam kaki banner dan biasanya di ganti lagi apakah
 *    bisa kita buat fitur gt ? contoh pelanggan nikson nakama kadang
 *    minjam 1 atau 2 pcs x banner gt"
 *
 * ATURAN YANG DIPEGANG:
 *   1. Pinjaman = barang KELUAR gudang → stok BERKURANG saat dipinjam dan
 *      BERTAMBAH lagi saat dikembalikan. Ini penting supaya angka stok di
 *      layar selalu mencerminkan barang yang BENAR-BENAR ada di gudang.
 *   2. Pinjaman BUKAN pemakaian produksi → memakai type 'pinjam'/'kembali',
 *      bukan 'potong', supaya HPP & laba TIDAK ikut terpengaruh.
 *   3. Pengembalian boleh SEBAGIAN (pinjam 2 pcs, kembali 1 pcs dulu).
 *   4. Sisa yang masih di pelanggan harus gampang dilihat & bisa ditagih.
 * ===================================================================== */

/** Jenis bahan yang WAJAR dipinjamkan (kaki/tiang banner). */
function stock_loan_kategori_pinjam(): array
{
    /* Hanya bahan ber-kategori x-banner (produk "X 60x160 cm" & "Y 60x160 cm").
     * Bahan produksi lain (tinta, roll flexy) tidak lazim dipinjamkan, jadi
     * tidak diikutkan demi menjaga daftar tetap relevan. */
    return ['x-banner', 'xbanner', 'x banner', 'y-banner', 'ybanner', 'y banner'];
}

/**
 * Daftar bahan yang boleh dipinjamkan (untuk dropdown).
 */
function stock_loan_produk(): array
{
    try {
        return db()->query(
            "SELECT id, category, variant, unit, stock_qty
               FROM product_prices
              WHERE track_stock = 1
                AND LOWER(REPLACE(category,'-',' ')) LIKE '%banner%'
                AND LOWER(category) NOT LIKE '%tinta%'
           ORDER BY category, variant"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Catat PINJAMAN BARU. Stok langsung berkurang.
 *
 * @return array{ok:bool,loan_id?:int,error?:string}
 */
function stock_loan_create(int $productId, int $customerId, float $qty, array $opt = []): array
{
    if ($productId <= 0) return ['ok' => false, 'error' => 'Bahan belum dipilih'];
    if ($customerId <= 0) return ['ok' => false, 'error' => 'Pelanggan belum dipilih'];
    if ($qty <= 0)        return ['ok' => false, 'error' => 'Jumlah pinjam harus lebih dari 0'];
    $p = stock_product($productId);
    if (!$p) return ['ok' => false, 'error' => 'Bahan tidak ditemukan'];

    $c = db()->prepare("SELECT id, name, phone FROM customers WHERE id = ?");
    $c->execute([$customerId]);
    $cust = $c->fetch();
    if (!$cust) return ['ok' => false, 'error' => 'Pelanggan tidak ditemukan'];

    $unit = (string)($p['unit'] ?? 'pcs');
    $isRoll = in_array($unit, ['roll', 'meter'], true);

    $tglPinjam = (string)($opt['tgl_pinjam'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglPinjam)) $tglPinjam = date('Y-m-d');
    $tglTarget = (string)($opt['tgl_target'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglTarget)) $tglTarget = '';

    // Untuk bahan roll, "qty" berarti meter (dipinjam per meter, bukan per roll utuh)
    $meters = $isRoll ? $qty : 0.0;
    $qtySimpan = $isRoll ? 0.0 : $qty;

    /* PENTING — JANGAN buka transaksi sendiri tanpa memeriksa.
     *
     * BUG NYATA (2026-10-09): fungsi ini dipanggil dari `order_form.php` yang
     * SUDAH berada di dalam transaksi. Memanggil `beginTransaction()` lagi
     * membuat transaksi LUAR rusak, dan pesan yang muncul di log adalah
     * "There is no active transaction" saat order hendak di-commit —
     * AKIBATNYA ORDER GAGAL TERSIMPAN sama sekali, bukan cuma pinjamannya.
     * Terbukti saat uji: simpan order dengan centang "Pinjam kaki" → order
     * tidak masuk database.
     *
     * Pola yang benar sudah dipakai `stock_ledger_add()`: hanya kelola
     * transaksi kalau kita yang memulainya ($ownTx). Kalau sudah ada
     * transaksi berjalan, IKUTI saja — biar pemanggil yang menentukan
     * jadi/gagalnya. Ini juga membuat pinjaman batal bersama order bila
     * order gagal disimpan (atomik), yang memang perilaku yang diinginkan. */
    $ownTx = false;
    try {
        if (!db()->inTransaction()) {
            db()->beginTransaction();
            $ownTx = true;
        }

        db()->prepare(
            "INSERT INTO stock_loans
               (product_id, product_name, customer_id, customer_name, customer_phone,
                qty, meters, unit, tgl_pinjam, tgl_target, status, order_id, order_number,
                ref_order_item_id, note, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,'dipinjam',?,?,?,?,?)"
        )->execute([
            $productId,
            (string)$p['category'] . ' ' . (string)$p['variant'],
            $customerId,
            (string)$cust['name'],
            (string)$cust['phone'],
            $qtySimpan,
            $meters,
            $unit,
            $tglPinjam,
            $tglTarget !== '' ? $tglTarget : null,
            isset($opt['order_id']) && (int)$opt['order_id'] > 0 ? (int)$opt['order_id'] : null,
            (string)($opt['order_number'] ?? ''),
            isset($opt['ref_order_item_id']) && (int)$opt['ref_order_item_id'] > 0
                ? (int)$opt['ref_order_item_id'] : null,
            mb_substr((string)($opt['note'] ?? ''), 0, 250),
            (int)($opt['created_by'] ?? ($_SESSION['user_id'] ?? 0)) ?: null,
        ]);
        $loanId = (int)db()->lastInsertId();

        /* KURANGI STOK — inilah bukti fisik barang keluar.
         * Memakai `stock_consume_qty()` untuk bahan satuan (pcs) dan buku
         * besar meter untuk bahan roll, supaya saldo cache (stock_qty /
         * stock_rolls) ikut benar — bukan hanya buku besarnya. */
        if ($isRoll) {
            $res = stock_ledger_add($productId, 'pinjam', $meters, [
                'reason'   => 'pinjam',
                'note'     => 'Dipinjam ' . ($cust['name'] ?? '') . ' — pinjaman #' . $loanId,
                'ref_type' => 'pinjam',
                'ref_id'   => $loanId,
            ]);
        } else {
            $res = stock_consume_qty($productId, $qty, 'pinjam', [
                'note'     => 'Dipinjam ' . ($cust['name'] ?? '') . ' — pinjaman #' . $loanId,
                'ref_type' => 'pinjam',
                'ref_id'   => $loanId,
            ], 'pinjam');
        }

        if (empty($res['ok'])) {
            if ($ownTx && db()->inTransaction()) db()->rollBack();
            return ['ok' => false, 'error' => 'Gagal memotong stok: ' . ($res['error'] ?? 'tidak diketahui')];
        }

        if ($ownTx) db()->commit();
        return ['ok' => true, 'loan_id' => $loanId];
    } catch (Throwable $e) {
        /* Rollback HANYA bila kita yang memulai transaksi. Kalau kita menumpang
         * transaksi pemanggil, jangan di-rollback — biarkan pemanggil (mis.
         * penyimpanan order) yang memutuskan, karena dia punya konteks lengkap
         * tentang apa lagi yang sudah dikerjakan dalam transaksi itu. */
        if ($ownTx && db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * KEMBALIKAN sebagian atau seluruh pinjaman. Stok bertambah lagi.
 *
 * @param float $qty jumlah yang dikembalikan (<= sisa)
 * @return array{ok:bool,sisa?:float,status?:string,error?:string}
 */
function stock_loan_return(int $loanId, float $qty, array $opt = []): array
{
    if ($loanId <= 0) return ['ok' => false, 'error' => 'Pinjaman tidak ditemukan'];
    if ($qty <= 0)    return ['ok' => false, 'error' => 'Jumlah kembali harus lebih dari 0'];

    $st = db()->prepare("SELECT * FROM stock_loans WHERE id = ?");
    $st->execute([$loanId]);
    $l = $st->fetch();
    if (!$l) return ['ok' => false, 'error' => 'Pinjaman tidak ditemukan'];
    if ($l['status'] === 'kembali') return ['ok' => false, 'error' => 'Pinjaman ini sudah lunas dikembalikan'];

    $unit   = (string)$l['unit'];
    $isRoll = in_array($unit, ['roll', 'meter'], true);
    $total  = $isRoll ? (float)$l['meters']        : (float)$l['qty'];
    $balik  = $isRoll ? (float)$l['meters_kembali'] : (float)$l['qty_kembali'];
    $sisa   = round($total - $balik, 3);

    if ($qty > $sisa + 0.001) {
        return ['ok' => false, 'error' => 'Jumlah kembali melebihi sisa pinjaman (' .
            rtrim(rtrim(number_format($sisa, 2, ',', '.'), '0'), ',') . ' ' . $unit . ')'];
    }

    /* Sama seperti stock_loan_create(): jangan buka transaksi baru kalau sudah
     * ada yang berjalan (mis. dipanggil saat membatalkan pinjaman dari dalam
     * transaksi penyimpanan order). Lihat penjelasan lengkap di sana. */
    $ownTx = false;
    try {
        if (!db()->inTransaction()) {
            db()->beginTransaction();
            $ownTx = true;
        }

        /* TAMBAH STOK kembali. Dialah bukti fisik barang masuk lagi ke gudang. */
        if ($isRoll) {
            $res = stock_ledger_add((int)$l['product_id'], 'kembali', $qty, [
                'reason'   => 'kembali',
                'note'     => 'Dikembalikan ' . (string)$l['customer_name'] . ' — pinjaman #' . $loanId,
                'ref_type' => 'pinjam',
                'ref_id'   => $loanId,
            ]);
        } else {
            $res = stock_receive_qty((int)$l['product_id'], $qty, 0, [
                'type'     => 'kembali',
                'reason'   => 'kembali',
                'note'     => 'Dikembalikan ' . (string)$l['customer_name'] . ' — pinjaman #' . $loanId,
                'ref_type' => 'pinjam',
                'ref_id'   => $loanId,
            ]);
        }

        if (empty($res['ok'])) {
            db()->rollBack();
            return ['ok' => false, 'error' => 'Gagal menambah stok: ' . ($res['error'] ?? 'tidak diketahui')];
        }

        $balikBaru = round($balik + $qty, 3);
        $sisaBaru  = round($total - $balikBaru, 3);
        // Status: 'kembali' bila lunas, 'sebagian' bila masih ada sisa di pelanggan
        $statusBaru = $sisaBaru <= 0.001 ? 'kembali' : 'sebagian';

        if ($isRoll) {
            db()->prepare("UPDATE stock_loans
                              SET meters_kembali = ?, status = ?, tgl_kembali = ?, kembali_by = ?
                            WHERE id = ?")
                ->execute([$balikBaru, $statusBaru, date('Y-m-d'),
                    (int)($opt['kembali_by'] ?? ($_SESSION['user_id'] ?? 0)) ?: null, $loanId]);
        } else {
            db()->prepare("UPDATE stock_loans
                              SET qty_kembali = ?, status = ?, tgl_kembali = ?, kembali_by = ?
                            WHERE id = ?")
                ->execute([$balikBaru, $statusBaru, date('Y-m-d'),
                    (int)($opt['kembali_by'] ?? ($_SESSION['user_id'] ?? 0)) ?: null, $loanId]);
        }

        if ($ownTx) db()->commit();
        return ['ok' => true, 'sisa' => max(0, $sisaBaru), 'status' => $statusBaru];
    } catch (Throwable $e) {
        if ($ownTx && db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Sisa yang masih dipegang pelanggan untuk satu pinjaman. */
function stock_loan_sisa(array $l): float
{
    $isRoll = in_array((string)$l['unit'], ['roll', 'meter'], true);
    $total  = $isRoll ? (float)$l['meters']        : (float)$l['qty'];
    $balik  = $isRoll ? (float)$l['meters_kembali'] : (float)$l['qty_kembali'];
    return round(max(0, $total - $balik), 3);
}

/**
 * Daftar pinjaman dengan filter. Dipakai halaman stok & dashboard.
 *
 * @param string $status '' = semua | dipinjam | sebagian | kembali | hilang | 'aktif'
 */
function stock_loan_daftar(string $status = 'aktif', int $limit = 200, int $customerId = 0): array
{
    $w = []; $pr = [];
    if ($status === 'aktif') {
        $w[] = "status IN ('dipinjam','sebagian')";
    } elseif ($status !== '') {
        $w[] = "status = ?"; $pr[] = $status;
    }
    if ($customerId > 0) { $w[] = "customer_id = ?"; $pr[] = $customerId; }

    $sql = "SELECT * FROM stock_loans";
    if ($w) $sql .= " WHERE " . implode(' AND ', $w);
    $sql .= " ORDER BY (status IN ('dipinjam','sebagian')) DESC, tgl_pinjam DESC, id DESC LIMIT " . max(1, min(1000, $limit));

    try {
        $st = db()->prepare($sql);
        $st->execute($pr);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Ringkasan pinjaman untuk badge/panel: berapa pinjaman aktif & berapa unit
 * yang masih beredar di pelanggan.
 */
function stock_loan_ringkas(): array
{
    try {
        $r = db()->query(
            "SELECT
                COUNT(*) AS n_aktif,
                COALESCE(SUM(CASE WHEN unit IN ('roll','meter')
                                  THEN GREATEST(meters - meters_kembali, 0)
                                  ELSE GREATEST(qty - qty_kembali, 0) END), 0) AS sisa_unit,
                SUM(CASE WHEN tgl_target IS NOT NULL AND tgl_target < CURDATE()
                          AND status IN ('dipinjam','sebagian') THEN 1 ELSE 0 END) AS n_telat
               FROM stock_loans
              WHERE status IN ('dipinjam','sebagian')"
        )->fetch();
        return [
            'n_aktif'   => (int)($r['n_aktif'] ?? 0),
            'sisa_unit' => round((float)($r['sisa_unit'] ?? 0), 2),
            'n_telat'   => (int)($r['n_telat'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['n_aktif' => 0, 'sisa_unit' => 0.0, 'n_telat' => 0];
    }
}

/**
 * Pinjaman yang PERLU DITAGIH (untuk pengingat WhatsApp).
 *
 * Supaya pelanggan tidak dispam, satu pinjaman hanya ditagih:
 *   - setelah lewat `tgl_target` (kalau diisi), atau setelah $minHari hari
 *     bila tidak ada target;
 *   - dengan jeda minimal $jedaHari sejak pengingat TERAKHIR.
 */
function stock_loan_perlu_ingat(int $minHari = 7, int $jedaHari = 3, int $limit = 20): array
{
    $minHari  = max(1, $minHari);
    $jedaHari = max(1, $jedaHari);

    /* Pinjaman yang belum kembali & belum ditagih dalam $jedaHari terakhir.
     * Yang paling lama dipinjam didahulukan supaya ditangani lebih dulu. */
    try {
        $st = db()->prepare(
            "SELECT * FROM stock_loans
              WHERE status IN ('dipinjam','sebagian')
                AND (wa_reminder_at IS NULL OR wa_reminder_at <= DATE_SUB(NOW(), INTERVAL ? DAY))
                AND (
                     (tgl_target IS NOT NULL AND tgl_target <= CURDATE())
                     OR (tgl_target IS NULL AND tgl_pinjam <= DATE_SUB(CURDATE(), INTERVAL ? DAY))
                    )
           ORDER BY (tgl_target IS NOT NULL) DESC, tgl_pinjam ASC
              LIMIT " . max(1, min(200, $limit))
        );
        $st->execute([$jedaHari, $minHari]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Tandai bahwa pengingat sudah dikirim (supaya tidak dispam berulang). */
function stock_loan_tandai_ingat(int $loanId): void
{
    try {
        db()->prepare("UPDATE stock_loans
                          SET wa_reminder_at = NOW(), wa_reminder_n = wa_reminder_n + 1
                        WHERE id = ?")->execute([$loanId]);
    } catch (Throwable $e) { /* abaikan */ }
}

/**
 * NOTIFIKASI PINJAMAN — ke pelanggan DAN ke tim internal.
 * =====================================================================
 * PEMILIK: "pinjam tanggal berapa notif ke owner ke group cg crew file juga
 *           biar jelas kemudian notif juga ke pelanggan yang pinjam kalau
 *           dia pinjam kaki banner gt"
 *
 * DUA ARAH, dan keduanya punya alasan sendiri:
 *
 *   1. KE PELANGGAN (opsional, $kePelanggan)
 *      Pelanggan harus tahu bahwa tiang yang dibawa itu PINJAMAN — berapa,
 *      dan kapan harus kembali. Tanpa pemberitahuan, dia bisa mengira
 *      tiangnya ikut terbeli, lalu tidak pernah mengembalikannya. Pesannya
 *      ditulis ramah, bukan menagih, karena ini soal kepercayaan.
 *
 *   2. KE OWNER + GRUP "CG Crew File" ($keInternal)
 *      Memakai `sendInternalNotice()` yang sudah mengirim ke nomor owner
 *      (`monitor_notif_phones`) DAN grup kerja (`monitor_notif_groups` =
 *      "CG Crew File"). Tujuannya supaya SELURUH TIM tahu ada tiang di luar
 *      gudang — kalau pelanggan menelpon atau datang, siapa pun yang
 *      melayani sudah paham konteksnya tanpa harus bertanya dulu.
 *
 * Dipisah jadi fungsi sendiri supaya bisa dipakai dari mana saja: form order
 * (saat pinjam dibuat) maupun cron (saat mengingatkan pengembalian).
 *
 * @param int  $loanId         pinjaman yang diberitakan
 * @param array $opt           'alasan' => teks tambahan (mis. "sudah lewat tenggat")
 * @param bool $kePelanggan    kirim WA ke pelanggan
 * @param bool $keInternal     kirim ke owner + grup CG Crew File
 * @return array{pelanggan:bool,internal:int}
 */
function stock_loan_kirim_notif(int $loanId, array $opt = [], bool $kePelanggan = true, bool $keInternal = true): array
{
    $hasil = ['pelanggan' => false, 'internal' => 0];
    if ($loanId <= 0) return $hasil;

    try {
        $st = db()->prepare("SELECT * FROM stock_loans WHERE id = ?");
        $st->execute([$loanId]);
        $l = $st->fetch();
    } catch (Throwable $e) {
        return $hasil;
    }
    if (!$l) return $hasil;

    $sisa    = stock_loan_sisa($l);
    if ($sisa <= 0.001) return $hasil;      // sudah lunas — tidak perlu diberitakan
    $unit    = (string)$l['unit'];
    $fmtSisa = rtrim(rtrim(number_format($sisa, 2, ',', '.'), '0'), ',');
    $tglPinjam = !empty($l['tgl_pinjam']) ? date('d/m/Y', strtotime((string)$l['tgl_pinjam'])) : '-';
    $tglTarget = !empty($l['tgl_target']) ? date('d/m/Y', strtotime((string)$l['tgl_target'])) : '';
    $alasan    = trim((string)($opt['alasan'] ?? ''));

    /* ---------- 1) KE PELANGGAN ---------- */
    if ($kePelanggan) {
        $phone = trim((string)($l['customer_phone'] ?? ''));
        if ($phone !== '') {
            /* Nada pesannya SENGAJA ramah dan memberi jalan keluar.
             * Pinjaman tiang itu soal kepercayaan — pesan yang terdengar
             * menuduh justru merusak hubungan dengan pelanggan baik. */
            $p  = "Halo Kak " . trim((string)$l['customer_name']) . " 🙏\n\n";
            $p .= "Terima kasih sudah order di *Creative Geek*.\n\n";
            $p .= "Kami catat Kakak membawa *" . $fmtSisa . " " . $unit . "* ";
            $p .= $l['product_name'] . " sebagai *PINJAMAN* (bukan pembelian), ";
            $p .= "sejak " . $tglPinjam . ".\n\n";
            if ($tglTarget !== '') {
                $p .= "Rencana dikembalikan: *" . $tglTarget . "*\n\n";
            }
            $p .= "Kalau sudah selesai dipakai, boleh dibawa kembali ke toko ya Kak. ";
            $p .= "Kalau memang mau dibeli sekalian, tinggal kabari kami 🙂\n\n";
            $p .= "Terima kasih banyak 🙏\n";
            $p .= "*Creative Geek*";
            if ($alasan !== '') $p .= "\n\n_" . $alasan . "_";

            try {
                $hasil['pelanggan'] = (bool)sendWhatsAppNotification($phone, $p);
            } catch (Throwable $e) {
                writeLog('Notif pinjaman ke pelanggan gagal (#' . $loanId . '): ' . $e->getMessage(), 'ERROR');
            }
        }
    }

    /* ---------- 2) KE OWNER + GRUP CG CREW FILE ---------- */
    if ($keInternal && function_exists('sendInternalNotice')) {
        $i  = "🦶 *PINJAMAN TIANG BANNER*\n\n";
        $i .= "Pelanggan : *" . trim((string)$l['customer_name']) . "*";
        if (!empty($l['customer_phone'])) $i .= " (" . $l['customer_phone'] . ")";
        $i .= "\n";
        $i .= "Barang    : " . $l['product_name'] . "\n";
        $i .= "Jumlah    : *" . $fmtSisa . " " . $unit . "*\n";
        $i .= "Dipinjam  : " . $tglPinjam . "\n";
        $i .= "Kembali   : " . ($tglTarget !== '' ? "*" . $tglTarget . "*" : "(belum ditentukan)") . "\n";
        if (!empty($l['order_number'])) $i .= "Order     : " . $l['order_number'] . "\n";
        $i .= "Pinjaman  : #" . (int)$l['id'] . "\n\n";
        $i .= "_Stok tiang sudah dikurangi, tetapi TIDAK dihitung sebagai biaya ";
        $i .= "karena akan dikembalikan._";
        if ($alasan !== '') $i .= "\n\n" . $alasan;

        try {
            $hasil['internal'] = (int)sendInternalNotice($i);
        } catch (Throwable $e) {
            writeLog('Notif pinjaman ke internal gagal (#' . $loanId . '): ' . $e->getMessage(), 'ERROR');
        }
    }

    return $hasil;
}

/**
 * BATALKAN pinjaman milik sebuah order (dipakai saat order DIEDIT/diHAPUS).
 * =====================================================================
 * KENAPA PERLU — PINJAMAN BUKAN PEMAKAIAN, JADI JANGAN DIANGGAP HPP:
 *   Saat order dihapus, seluruh pemakaian bahannya dikembalikan oleh
 *   `stock_reverse_order()`. Fungsi itu HANYA membalik `type='potong'`,
 *   sedangkan pinjaman memakai `type='pinjam'` — jadi pinjaman TIDAK ikut
 *   dibalik. Tanpa fungsi ini, menghapus order akan meninggalkan tiang
 *   "hilang" dari stok padahal ordernya dibatalkan.
 *
 * YANG DILAKUKAN:
 *   1. Sisa yang belum kembali → stok DITAMBAH lagi (tiang dianggap tidak
 *      jadi dipinjam).
 *   2. Yang SUDAH kembali sebelumnya tidak diapa-apakan — barangnya memang
 *      sudah balik, jadi stoknya sudah benar.
 *   3. Baris pinjaman DIHAPUS supaya tidak menumpuk sebagai tagihan palsu
 *      (pelanggan tidak mungkin mengembalikan order yang sudah dibatalkan).
 *   4. Jejak pergerakan stoknya TETAP ada (buku besar tidak dihapus) —
 *      anti-kecurangan: perubahan stok selalu bisa ditelusuri.
 *
 * @param int $orderId  order yang pinjamannya dibatalkan
 * @param int $hanyaItemId  bila > 0, batasi ke satu baris order saja
 * @return int jumlah pinjaman yang dibatalkan
 */
function stock_loan_batal_order(int $orderId, int $hanyaItemId = 0, string $note = ''): int
{
    if ($orderId <= 0) return 0;

    try {
        $w = "order_id = ?"; $pr = [$orderId];
        if ($hanyaItemId > 0) {
            /* Saat order DIEDIT, hanya baris yang benar-benar dihapus/diganti
             * yang pinjamannya dibatalkan — bukan semua pinjaman order itu. */
            $w .= " AND ref_order_item_id = ?"; $pr[] = $hanyaItemId;
        }
        $st = db()->prepare("SELECT * FROM stock_loans WHERE $w AND status IN ('dipinjam','sebagian')");
        $st->execute($pr);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return 0;
    }

    $n = 0;
    foreach ($rows as $l) {
        $sisa = stock_loan_sisa($l);
        if ($sisa > 0.001) {
            /* Kembalikan sisa ke stok. Memakai stock_loan_return supaya
             * aturan pemulihannya sama persis dengan pengembalian biasa
             * (non-roll → stock_qty, roll → buku besar meter). */
            $r = stock_loan_return((int)$l['id'], $sisa, [
                'kembali_by' => (int)($_SESSION['user_id'] ?? 0),
            ]);
            if (empty($r['ok'])) continue;
        }
        db()->prepare("DELETE FROM stock_loans WHERE id = ?")->execute([(int)$l['id']]);
        writeLog('Pinjaman #' . $l['id'] . ' dibatalkan karena order #' . $orderId
            . ($note !== '' ? " ($note)" : '') . ' — sisa ' . $sisa . ' dikembalikan ke stok', 'INFO');
        $n++;
    }
    return $n;
}

/* =====================================================================
 * TAHAP 2 — VERIFIKASI OTOMATIS: bahan menurut SISTEM vs MESIN CETAK
 * =====================================================================
 * Latar (keluhan pemilik, 12 Okt 2026):
 *   "kadang ada cetakan rusak dan operator ga ngasih tau ke owner alhasil
 *    bahanya jadi miss/rugi"
 *
 * Sampai sekarang TIDAK ADA cara memeriksa apakah bahan yang tercatat
 * benar-benar sama dengan bahan yang keluar dari roll. Modul ini menutup
 * celah itu: angka dari MESIN (RYPC) dijadikan pembanding independen —
 * bukan dari operator, bukan dari sistem kita sendiri.
 *
 * Satuan pembanding = M², bukan meter. Alasannya penting:
 *   1 meter dari roll 1,1 m hanya 1,1 m² bahan, sedangkan 1 meter dari roll
 *   3,2 m berarti 3,2 m². Membandingkan meter antar lebar = membandingkan
 *   dua satuan berbeda. Luas (m²) itu satuan yang adil.
 *
 * HANYA MEMBACA — modul ini tidak pernah mengubah stok. Perbaikan data
 * dilakukan oleh modul koreksi tersendiri setelah pemilik menilai hasilnya.
 * ===================================================================== */

/** Ambang selisih (m²) sebelum sebuah job dianggap menyimpang. */
function stock_verify_ambang(): float
{
    $v = (float)str_replace(',', '.', (string)getSetting('stock_verify_ambang_m2', '0'));
    return $v > 0 ? $v : 1.0;
}

/** Tanggal mulai verifikasi otomatis (diisi saat opname fisik selesai). */
function stock_verify_mulai(): string
{
    $d = (string)getSetting('stock_verify_dari', '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $d = '';

    /* ERA POTONG PER ORDER — diambil dari data, bukan ditulis tetap di kode.
     * Jejak pemotongan yang bisa dibandingkan PER ORDER (`ref_type='order'`)
     * baru mantap sejak 9 Okt 2026; sebelum itu bahan dipotong BATCH harian
     * (`ref_type='sync'`) sehingga tidak bisa disandingkan dengan job mesin.
     * Diambil otomatis supaya kalau sistem dipulihkan/dimigrasi batas ini ikut
     * menyesuaikan dan tidak memunculkan temuan palsu pada periode lama. */
    $era = stock_verify_era();
    if ($d === '') return $era ?: date('Y-m-d', strtotime('-7 days'));
    if ($era !== null && $d < $era) return $era;   // jangan lebih awal dari era
    return $d;
}

/** Tanggal mulai ERA potong per order (null bila belum ada).
 *
 * Bukan sekadar MIN(created_at): pada 5–8 Okt masih ada 1–4 gerakan "nyasar"
 * (uji manual) padahal hari itu potong bahan masih BATCH sehingga hanya 1–3 dari
 * ~15 job yang punya jejak. Kalau dipakai MIN, panel verifikasi akan memunculkan
 * puluhan "tanpa catatan" PALSU — dan laporan yang penuh tuduhan palsu tidak akan
 * dipercaya lagi.
 *
 * Aturan: hari pertama yang punya **sedikitnya 5 gerakan** potong per order —
 * artinya pemotongan per order benar-benar sudah jadi kebiasaan hari itu.
 * (Data 12 Okt 2026: 5 Okt=4, 6 Okt=1, 7 Okt=2, 8 Okt=2, **9 Okt=42** → era 9 Okt.) */
function stock_verify_era(): ?string
{
    static $cache = false;
    if ($cache !== false) return $cache;

    $cache = null;
    try {
        $e = db()->query("SELECT MIN(d) FROM (
                            SELECT DATE(created_at) AS d, COUNT(*) AS n
                              FROM stock_movements
                             WHERE type = 'potong' AND ref_type = 'order'
                             GROUP BY DATE(created_at)
                            HAVING n >= 5
                          ) t")->fetchColumn();
        if ($e) $cache = (string)$e;
    } catch (Throwable $ex) {
        $cache = null;
    }
    return $cache;
}

/**
 * Lebar roll tersempit yang MASIH MEMUAT lebar kanvas — dipakai verifikasi.
 *
 * Ini mencerminkan apa yang benar-benar dilakukan operator di lantai: cari roll
 * dengan angka terdekat yang masih cukup. Roll yang lebih lebar dari ini berarti
 * ada bahan yang terbuang percuma, dan itulah yang perlu kelihatan di panel.
 *
 * @param int   $productId  Produk bahan (dari order_items.stock_product_id)
 * @param float $butuhLebar Lebar kanvas (arah melintang roll)
 * @param array $peta       [product_id => [lebar, ...]]
 * @return float Lebar roll pilihan; 0.0 bila datanya tidak ada.
 */
function stock_verify_lebar_fisik(int $productId, float $butuhLebar, array $peta = []): float
{
    $arr = $peta[$productId] ?? [];
    if (!$arr) return 0.0;
    sort($arr);
    foreach ($arr as $w) {
        if ((float)$w + 0.005 >= $butuhLebar) return (float)$w;
    }
    return (float)end($arr);   // tidak ada yang memuat → pakai yang terlebar
}

/**
 * Bandingkan pemakaian bahan menurut sistem (buku besar) dengan ukuran yang
 * benar-benar dicetak mesin, per job (satu file = satu kali jalan).
 *
 * @param string $dari    tanggal awal (Y-m-d)
 * @param string $sampai  tanggal akhir (Y-m-d), kosong = hari ini
 * @param array  $opt     ['order_no' => 'CG...'] untuk memeriksa SATU order saja
 * @return array{ok:bool, ringkas:array, baris:array, error:string}
 */
function stock_verify_prints(string $dari, string $sampai = '', array $opt = []): array
{
    $sampai = $sampai !== '' ? $sampai : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari))    $dari   = date('Y-m-d', strtotime('-7 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai))  $sampai = date('Y-m-d');
    $ambang = stock_verify_ambang();

    /* ===== TOLERANSI PEMBANDING METER (satuan STOK) — 12 Okt 2026 =====
     * Stok bahan disimpan dalam METER, jadi pertanyaan "apakah kita memotong
     * sesuai yang dicetak?" harus dijawab dalam METER — bukan m².
     *
     * Sebelumnya panel membandingkan m² dengan dua definisi berbeda (mesin pakai
     * w×h kanvas; catatan pakai meter × lebar roll) sehingga muncul "selisih 4,5"
     * yang tidak nyata dan membuat pemilik curiga sistemnya rusak:
     *   "masa system baru jalan 3 harian sudah selisih 4 meter kan aneh"
     *
     * Toleransi 0,5 m cukup lapang: hitungan kita memang menambah ±0,1 m untuk
     * pinggir potong pada tiap sisi. Bisa ditimpa lewat $opt['ambang_m']. */
    $ambangM = (float)($opt['ambang_m'] ?? 0.5);
    if ($ambangM <= 0) $ambangM = 0.5;

    try {
        /* Job dikumpulkan per (printer, file_name) — BUKAN per baris.
         * Satu job tercatat ulang tiap heartbeat (rata-rata 17 baris!), jadi
         * menghitung per baris akan melipatgandakan angkanya. Baris id
         * TERKECIL dipakai sebagai kunci agar stabil. */
        $sql = "SELECT MIN(j.id) AS job_id, j.printer_id, p.name AS printer_name,
                       j.file_name,
                       MAX(j.width_m)      AS w,
                       MAX(j.height_m)     AS h,
                       MAX(j.printed_pages) AS printed,
                       MAX(j.total_pages)   AS total,
                       MIN(j.start_at)      AS mulai,
                       MAX(j.end_at)        AS selesai,
                       GROUP_CONCAT(DISTINCT j.status)        AS statuses,
                       GROUP_CONCAT(DISTINCT j.order_number)  AS orders_col
                  FROM printer_jobs j
                  JOIN printers p ON p.id = j.printer_id
                 WHERE p.type = 'uv'
                   AND j.width_m > 0 AND j.height_m > 0
                   AND DATE(j.start_at) BETWEEN ? AND ?
                 GROUP BY j.printer_id, j.file_name
                 ORDER BY MIN(j.start_at) DESC";
        $st = db()->prepare($sql);
        $st->execute([$dari, $sampai]);
        $jobs = $st->fetchAll();
    } catch (Throwable $e) {
        return ['ok' => false, 'ringkas' => [], 'baris' => [], 'error' => $e->getMessage()];
    }

    $cariOrder = trim((string)($opt['order_no'] ?? ''));
    $era = stock_verify_era();

    /* Peta lebar roll yang PERNAH ada per produk. Dipakai untuk menaksir lebar
     * roll yang harus dipakai sebuah job (roll tersempit yang masih memuat).
     * Diambil dari riwayat `stock_rolls` supaya lebar yang sudah tidak dipakai
     * pun tetap terhitung — kalau tidak, roll yang baru dihapus akan mengubah
     * hasil verifikasi job lama secara mundur. */
    $lebarProduk = [];
    try {
        foreach (db()->query("SELECT DISTINCT product_id, width_m FROM stock_rolls WHERE width_m > 0") as $x) {
            $lebarProduk[(int)$x['product_id']][] = (float)$x['width_m'];
        }
    } catch (Throwable $e) { /* biarkan kosong — verifikasi tetap jalan */ }

    $ringkas = ['job' => 0, 'cocok' => 0, 'beda' => 0, 'tanpa_catatan' => 0, 'luar' => 0,
                'pra_era' => 0, 'terputus' => 0, 'selisih_m2' => 0.0, 'mesin_m2' => 0.0,
                'sistem_m2' => 0.0, 'bahan_m2' => 0.0, 'feed_m' => 0.0, 'catat_m' => 0.0,
                'selisih_m' => 0.0, 'boros_m2' => 0.0, 'ambang_m' => 0.5,
                'n_yatim' => 0, 'meter_yatim' => 0.0, 'n_job_yatim' => 0,
                'n_hapus' => 0, 'n_tanpa' => 0, 'meter_hapus' => 0.0, 'meter_tanpa' => 0.0,
                'ambang' => $ambang, 'dari' => $dari,
                'sampai' => $sampai, 'era' => $era];
    $baris = [];

    foreach ($jobs as $j) {
        /* Nomor order: kolom `order_number` HANYA memuat order pertama, jadi
         * daftar lengkap diambil dari `file_name` (mis. "CG1,CG2.prn"). */
        $teks = (string)$j['file_name'] . ',' . (string)$j['orders_col'];
        preg_match_all('/CG\d{10}/', $teks, $mm);
        $orders = array_values(array_unique($mm[0] ?? []));
        if (!$orders) continue;
        if ($cariOrder !== '' && !in_array($cariOrder, $orders, true)) continue;

        $w = (float)$j['w'];
        $h = (float)$j['h'];

        /* Halaman tercetak = berapa kali keluar. Kalau mesin tidak melaporkan,
         * anggap 1 supaya tidak menggelembungkan angka. */
        $lembar = (int)($j['printed'] ?? 0);
        if ($lembar <= 0) $lembar = 1;

        $mesinM2 = round($w * $h * $lembar, 3);

        /* ===== SISI SISTEM: buku besar, dikonversi ke m² =====
         * ⚠️ `ABS(m.meters)` WAJIB: pergerakan `potong` disimpan sebagai angka
         * NEGATIF (mis. −1,300). Tanpa ABS, jumlahnya jadi negatif dan
         * selisihnya menyesatkan (uji pertama: "sistem −69,98 m²").
         * Lebar roll diambil dari roll yang BENAR-BENAR dipakai (m.roll_id);
         * kalau tidak ada, pakai lebar produk sebagai cadangan. */
        $in  = implode(',', array_fill(0, count($orders), '?'));
        /* SISI SISTEM (buku besar) — DIPERBAIKI 12 Okt 2026.
         *
         * ⚠️ `ABS(m.meters)` WAJIB: 'potong' disimpan sebagai angka NEGATIF.
         *
         * ⚠️ JANGAN memakai `COALESCE(r.width_m, pp.roll_width_m)`. Fallback ke
         * LEBAR PRODUK itu yang membuat panel "bengkak": saat roll sudah dihapus
         * (r.id IS NULL) angka jatuh ke 3,2 m sehingga luasnya melar ~3×.
         * Bukti nyata 12 Okt 2026 — 5 order melar +8,6 m² palsu:
         *   CG0910260491 1,0 m → 3,20 m² · CG0910267409 1,0 m → 3,20 m²
         *   CG0910268138 1,1 m → 3,52 m² · CG0910264989 1,3 m → 4,16 m²
         *   CG0910264829 1,1 m → 3,52 m²
         * Baris yatim sekarang dihitung TERPISAH dan dilaporkan sebagai masalah
         * DATA (bukan dilebur diam-diam ke angka besar). */
        $sql2 = "SELECT COALESCE(SUM(ABS(m.meters) * COALESCE(r.width_m, 0)), 0) AS area_roll,
                        GROUP_CONCAT(DISTINCT r.width_m ORDER BY r.width_m) AS roll_widths,
                        COALESCE(SUM(ABS(m.meters)), 0) AS meter_total,
                        COALESCE(SUM(CASE WHEN m.roll_id IS NULL THEN ABS(m.meters) ELSE 0 END), 0) AS meter_tanpa,
                        COALESCE(SUM(CASE WHEN m.roll_id IS NOT NULL AND r.id IS NULL THEN ABS(m.meters) ELSE 0 END), 0) AS meter_hapus,
                        COUNT(*) AS n,
                        SUM(CASE WHEN m.roll_id IS NULL THEN 1 ELSE 0 END) AS n_tanpa,
                        SUM(CASE WHEN m.roll_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END) AS n_hapus,
                        MAX(oi.stock_product_id) AS pid_cat
                   FROM orders o
                   JOIN order_items oi ON oi.order_id = o.id
                   JOIN stock_movements m ON m.ref_type = 'order' AND m.ref_id = oi.id
                                         AND m.type = 'potong'
                   JOIN product_prices pp ON pp.id = m.product_id
                                         AND pp.unit IN ('roll','meter')
                   LEFT JOIN stock_rolls r  ON r.id = m.roll_id
                  WHERE o.order_number IN ($in)";
        $st2 = db()->prepare($sql2);
        $st2->execute($orders);
        $r2 = $st2->fetch() ?: [];

        /* Koreksi otomatis 1b membatalkan potongan order lama lalu membuat
         * potongan pengganti dengan ref_type='rypc_job', ref_id=job_id.
         * Jangan bandingkan mesin dengan catatan lama setelah koreksi: itu
         * membuat angka 17/26 tetap muncul meskipun stok sudah dibetulkan.
         * Untuk job berkoreksi, pakai ledger pengganti sebagai sumber benar. */
        $jobId = (int)($j['job_id'] ?? 0);
        if ($jobId > 0) {
            $stFix = db()->prepare(
                "SELECT COALESCE(SUM(ABS(m.meters) * COALESCE(r.width_m, 0)), 0) AS area_roll,
                    GROUP_CONCAT(DISTINCT r.width_m ORDER BY r.width_m) AS roll_widths,
                        COALESCE(SUM(ABS(m.meters)), 0) AS meter_total,
                        COALESCE(SUM(CASE WHEN m.roll_id IS NULL THEN ABS(m.meters) ELSE 0 END), 0) AS meter_tanpa,
                        COALESCE(SUM(CASE WHEN m.roll_id IS NOT NULL AND r.id IS NULL THEN ABS(m.meters) ELSE 0 END), 0) AS meter_hapus,
                        COUNT(*) AS n,
                        SUM(CASE WHEN m.roll_id IS NULL THEN 1 ELSE 0 END) AS n_tanpa,
                        SUM(CASE WHEN m.roll_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END) AS n_hapus,
                        MAX(m.product_id) AS pid_cat
                   FROM stock_movements m
                   JOIN product_prices pp ON pp.id = m.product_id
                                         AND pp.unit IN ('roll','meter')
                   LEFT JOIN stock_rolls r ON r.id = m.roll_id
                  WHERE m.ref_type = 'rypc_job' AND m.ref_id = ? AND m.type = 'potong'"
            );
            $stFix->execute([$jobId]);
            $rFix = $stFix->fetch() ?: [];
            if ((int)($rFix['n'] ?? 0) > 0) {
                $r2 = $rFix;
            }
        }

        /* Apakah order-order ini memang ada di sistem? Job yang dicetak untuk
         * pelanggan luar / tidak lewat form order TIDAK BISA dibandingkan —
         * jangan dihitung sebagai "tanpa catatan", karena itu bukan temuan. */
        $st3 = db()->prepare("SELECT COUNT(*) FROM orders WHERE order_number IN ($in)");
        $st3->execute($orders);
        $adaOrder = (int)$st3->fetchColumn();

        $catatM     = round((float)($r2['meter_total'] ?? 0), 3);
        /* DUA hal BERBEDA yang dulu dijadikan satu (membingungkan pemilik):
         *   'tanpa roll'  = saat dipotong memang tidak dipasang roll (roll_id NULL)
         *                   → tidak ada yang hilang, memang sejak awal kosong
         *   'roll dihapus' = jejaknya menunjuk roll yang sudah tidak ada lagi
         *                   → inilah yang rusak, penyebabnya DELETE pada opname lama
         */
        $catatTanpa = round((float)($r2['meter_tanpa'] ?? 0), 3);
        $catatHapus = round((float)($r2['meter_hapus'] ?? 0), 3);
        $catatYatim = round($catatTanpa + $catatHapus, 3);
        $nJejak     = (int)($r2['n'] ?? 0);
        $nTanpa     = (int)($r2['n_tanpa'] ?? 0);
        $nHapus     = (int)($r2['n_hapus'] ?? 0);
        $nYatim     = $nTanpa + $nHapus;
        $pidCat     = (int)($r2['pid_cat'] ?? 0);

        /* ===== DUA LEBAR ROLL YANG HARUS DIBANDINGKAN =====
         *
         * `rollFisik` = lebar roll tersempit yang MASIH MEMUAT lebar kanvas (w).
         *   Inilah yang benar-benar harus dipakai di lantai — persis kebiasaan
         *   operator: "pindah roll, cari angka terdekat". Roll yang lebih lebar
         *   dari ini berarti lebihan bahan yang terbuang percuma.
         *
         * `rollCatat` = lebar roll menurut CATATAN (luas ÷ meter).
         *   Kalau catatan memakai roll 3,2 m untuk kanvas 2 m, di sini kelihatan.
         *
         * Dulu hanya satu angka (w×h) yang dipakai, sehingga kolom "mesin" dan
         * "catatan" sebenarnya mengukur dua hal BERBEDA — itulah sumber kesan
         * "selisih 4,5" yang membingungkan pemilik. */
        $rollFisik = stock_verify_lebar_fisik($pidCat, $w, $lebarProduk);
        $meterAdaRoll = max(0.0, round($catatM - $catatYatim, 3));
        $rollCatat = $meterAdaRoll > 0.001
            ? round((float)$r2['area_roll'] / $meterAdaRoll, 3)
            : 0.0;

        $feedM    = round($h * $lembar, 3);   // meter yang BENAR-BENAR lewat mesin
        $bahanM2  = $rollFisik > 0 ? round($feedM * $rollFisik, 3) : 0.0;

        /* Bahan menurut catatan. Baris yatim (roll terhapus) dihitung memakai
         * lebar FISIK — bukan lebar produk — sehingga tidak lagi melar. */
        $sistemM2 = round(
            $meterAdaRoll * ($rollCatat > 0 ? $rollCatat : $rollFisik)
            + $catatYatim * $rollFisik, 3);
        $selisih  = round($sistemM2 - $bahanM2, 3);

        /* ===== PEMBANDING YANG SAH: METER vs METER =====
         * `feedM` = meter jalan yang benar-benar lewat mesin (h × lembar).
         * `catatM` = meter yang kita potong dari stok.
         * Dua-duanya METER dari roll yang sama → langsung bisa dibandingkan,
         * tanpa perlu tahu lebar rollnya. Inilah pemeriksaan utamanya. */
        $selisihM = round($catatM - $feedM, 3);

        /* Info TAMBAHAN (bukan selisih): bahan yang TERBUANG karena catatan
         * memakai roll lebih lebar daripada yang perlu. Contoh nyata: spanduk
         * 4x2 dicatat dari roll 3,2 m padahal cukup roll 2,1 m → 4,01 × 1,1 =
         * 4,4 m² bahan terbuang. Ini soal PEMBOROSAN, bukan salah catat, jadi
         * dipisahkan supaya angkanya tidak tercampur. */
        $borosM2 = ($rollCatat > 0 && $rollCatat > $rollFisik && $rollFisik > 0)
            ? round($feedM * ($rollCatat - $rollFisik), 3)
            : 0.0;

        /* ===== CERAKAN TERPUTUS =====
         * ⚠️ JANGAN memakai "ada baris gagal" saja: 67% baris `gagal` RYPC
         * ternyata ARTEFAK 90 detik (akhir job N = awal job N+1), dan satu job
         * muncul rata-rata 17 baris — jadi hampir semua job akan tampak gaga
         * padahal selesai (uji pertama: 147 "terputus" dari 379 job, jelas
         * berlebihan). Yang dipakai: SEMUA baris gagal (tidak ada yang selesai),
         * atau mesin berhenti sebelum halaman terakhir. */
        $statuses = (string)($j['statuses'] ?? '');
        $semuaGagal = $statuses !== ''
                   && strpos($statuses, 'gagal') !== false
                   && strpos($statuses, 'selesai') === false
                   && strpos($statuses, 'printing') === false;
        $total    = (int)($j['total'] ?? 0);
        $terputus = $semuaGagal || ($total > 0 && $lembar < $total);

        $jns = 'cocok';
        if ($era !== null && substr((string)$j['mulai'], 0, 10) < $era) $jns = 'pra_era';
        elseif ($adaOrder === 0)              $jns = 'luar';
        elseif ($nJejak === 0)               $jns = 'tanpa_catatan';
        elseif (abs($selisihM) > $ambangM)   $jns = 'beda';   // ← METER, bukan m²

        $ringkas['job']++;
        $ringkas[$jns] = ($ringkas[$jns] ?? 0) + 1;
        if ($terputus) $ringkas['terputus']++;
        if ($jns !== 'luar' && $jns !== 'pra_era') {
            $ringkas['mesin_m2']  = round($ringkas['mesin_m2'] + $mesinM2, 3);
            $ringkas['bahan_m2']  = round($ringkas['bahan_m2'] + $bahanM2, 3);
            $ringkas['sistem_m2'] = round($ringkas['sistem_m2'] + $sistemM2, 3);
            $ringkas['feed_m']    = round($ringkas['feed_m'] + $feedM, 3);
            $ringkas['catat_m']   = round($ringkas['catat_m'] + $catatM, 3);
            $ringkas['selisih_m'] = round($ringkas['selisih_m'] + $selisihM, 3);
            $ringkas['boros_m2']  = round($ringkas['boros_m2'] + $borosM2, 3);
            $ringkas['n_yatim']     = $ringkas['n_yatim'] + $nYatim;
            $ringkas['n_hapus']     = ($ringkas['n_hapus'] ?? 0) + $nHapus;
            $ringkas['n_tanpa']     = ($ringkas['n_tanpa'] ?? 0) + $nTanpa;
            $ringkas['meter_hapus'] = round(($ringkas['meter_hapus'] ?? 0) + $catatHapus, 3);
            $ringkas['meter_tanpa'] = round(($ringkas['meter_tanpa'] ?? 0) + $catatTanpa, 3);
            $ringkas['meter_yatim'] = round($ringkas['meter_yatim'] + $catatYatim, 3);
            if ($nYatim > 0) $ringkas['n_job_yatim']++;
        }

        $baris[] = [
            'job_id'    => (int)$j['job_id'],
            'file'      => (string)$j['file_name'],
            'printer'   => (string)$j['printer_name'],
            'mulai'     => (string)$j['mulai'],
            'orders'    => $orders,
            'lebar'     => round($w, 2),
            'panjang'   => round($h, 2),
            'lembar'    => $lembar,
            'total_pages' => $total,
            'mesin_m2'  => $mesinM2,      // luas KANVAS (w×h) — untuk koreksi 1b
            'bahan_m2'  => $bahanM2,      // bahan terpakai (meter jalan × lebar roll)
            'sistem_m2' => $sistemM2,     // bahan menurut catatan kita
            'selisih_m2' => $selisih,     // beda LUAS (info, bukan penentu)
            'selisih_m'  => $selisihM,    // ← PENENTU: meter catatan − meter mesin
            'boros_m2'   => $borosM2,     // bahan terbuang karena roll terlalu lebar
            /* --- kolom PEMERIKSA: supaya selisih bisa ditelusuri sendiri --- */
            'feed_m'     => $feedM,       // meter yang benar-benar lewat mesin
            'catat_m'    => $catatM,      // meter yang dicatat di buku besar
            'roll_fisik' => round($rollFisik, 2),   // lebar roll yang SEHARUSNYA
            'roll_catat' => round($rollCatat, 2),   // lebar roll menurut catatan
            'roll_catat_lebar' => (string)($r2['roll_widths'] ?? ''), // lebar nyata dari baris ledger, bukan rerata
            'jejak'      => $nJejak,
            'n_yatim'    => $nYatim,      // total tanpa roll + roll terhapus
            'n_hapus'    => $nHapus,      // jejak menunjuk roll yang sudah DIHAPUS (rusak)
            'n_tanpa'    => $nTanpa,      // saat potong memang tidak dipasang roll (normal)
            'meter_yatim'=> $catatYatim,
            'meter_hapus'=> $catatHapus,
            'meter_tanpa'=> $catatTanpa,
            'jenis'      => $jns,
            'terputus'   => $terputus,
        ];
    }

    $ringkas['selisih_m2']  = round($ringkas['sistem_m2'] - $ringkas['bahan_m2'], 3);
    $ringkas['selisih_m']   = round($ringkas['catat_m'] - $ringkas['feed_m'], 3);
    $ringkas['ambang_m']    = $ambangM;
    return ['ok' => true, 'ringkas' => $ringkas, 'baris' => $baris, 'error' => ''];
}

/** Ringkasan saja (untuk kartu/peringatan) — memakai periode sejak verifikasi mulai. */
function stock_verify_ringkas(int $hari = 0): array
{
    $dari = $hari > 0 ? date('Y-m-d', strtotime('-' . $hari . ' days')) : stock_verify_mulai();
    $h = stock_verify_prints($dari, date('Y-m-d'));
    return $h['ok'] ? $h['ringkas'] : ['job' => 0, 'cocok' => 0, 'beda' => 0,
        'tanpa_catatan' => 0, 'luar' => 0, 'pra_era' => 0, 'terputus' => 0,
        'selisih_m2' => 0.0, 'mesin_m2' => 0.0, 'sistem_m2' => 0.0,
        'bahan_m2' => 0.0, 'feed_m' => 0.0, 'catat_m' => 0.0,
        'selisih_m' => 0.0, 'boros_m2' => 0.0, 'ambang_m' => 0.5,
        'n_yatim' => 0, 'meter_yatim' => 0.0, 'n_job_yatim' => 0,
        'n_hapus' => 0, 'n_tanpa' => 0, 'meter_hapus' => 0.0, 'meter_tanpa' => 0.0,
        'ambang' => stock_verify_ambang(), 'dari' => $dari,
        'sampai' => date('Y-m-d'), 'era' => stock_verify_era()];
}

/* =====================================================================
 * KOREKSI OTOMATIS DARI UKURAN CETAK MESIN (Tahap 1b)
 * =====================================================================
 * Latar (bukti nyata 12 Okt 2026):
 *   Job CG0810260501 + CG0810264012 → kotak mesin 2,06 × 1,21 (1,21 m jalan),
 *   tetapi catatan kita memotong 1,1 + 1,1 = **2,2 m** → **hemat 0,99 m** yang
 *   tidak pernah dikembalikan ke stok.
 *
 * KEPUTUSAN PEMILIK:
 *   - gabungan dikenali **OTOMATIS** dari data RYPC (`file_name` berisi >1 nomor
 *     order) — "bukannya di aplikasi rypc kebaca ya … daripada manual?"
 *   - bila roll yang dipakai berbeda, koreksi harus **MEMINDAHKAN roll**
 *     ke lebar TERDEKAT dengan kotak mesin — "biasanya kita pindah roll sih
 *     cari angka terdekat gt".
 *
 * DUA aturan pengaman yang WAJIB:
 *   1. ANTI-GANDA. Satu job rata-rata tercatat **17,5 baris** di printer_jobs.
 *      Kunci koreksi = job_id (baris id TERKECIL) + ref_type='rypc_job'.
 *      Tanpa ini, koreksi berjalan berulang dan stok bisa rusak ~17×.
 *   2. HANYA BILA DIMINTA. Default function ini **dry-run** (tidak menulis).
 *      Penulisan harus lewat `['apply' => true]` yang dipanggil dari tombol/
 *      cron yang memang sudah dinyalakan.
 * ===================================================================== */

/**
 * Rincian catatan pemotongan sebuah job (per order + per baris buku besar).
 * Dipakai oleh `stock_correct_prints()`.
 */
function stock_job_ledger_rows(array $orders, string $dari): array
{
    $orders = array_values(array_filter(array_map('strval', $orders)));
    if (!$orders) return [];
    $in = implode(',', array_fill(0, count($orders), '?'));

    $sql = "SELECT o.order_number, oi.id AS item_id, oi.stock_product_id,
                   m.id AS movement_id, m.product_id, m.meters, m.roll_id,
                   r.id AS roll_row_id, r.product_id AS roll_product,
                   COALESCE(r.width_m, pp.roll_width_m) AS roll_w
              FROM orders o
              JOIN order_items oi ON oi.order_id = o.id
              JOIN stock_movements m ON m.ref_type = 'order' AND m.ref_id = oi.id
                                    AND m.type = 'potong'
              LEFT JOIN stock_rolls r  ON r.id = m.roll_id
              LEFT JOIN product_prices pp ON pp.id = m.product_id
             WHERE o.order_number IN ($in)";
    try {
        $st = db()->prepare($sql);
        $st->execute($orders);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Perbaiki stok berdasarkan ukuran cetak mesin untuk rentang tanggal tertentu.
 *
 * @param string $dari  Y-m-d
 * @param string $sampai Y-m-d (kosong = hari ini)
 * @param array  $opt   ['apply'=>bool (default false = dry-run), 'max_jobs'=>int]
 * @return array{ok:bool, baris:array, ringkas:array, error:string}
 */
function stock_correct_prints(string $dari, string $sampai = '', array $opt = []): array
{
    $apply   = !empty($opt['apply']);
    $maxJobs = max(1, (int)($opt['max_jobs'] ?? 50));
    $ambang  = stock_verify_ambang();

    $hasil = function_exists('stock_verify_prints')
        ? stock_verify_prints($dari, $sampai)
        : ['ok' => false, 'baris' => [], 'ringkas' => []];
    if (empty($hasil['ok'])) {
        return ['ok' => false, 'baris' => [], 'ringkas' => [],
                'error' => $hasil['error'] ?? 'Verifikasi tidak tersedia'];
    }

    $baris = [];
    $nDiperbaiki = 0; $nDilewati = 0; $mDikembalikan = 0.0;

    foreach ($hasil['baris'] as $b) {
        if (count($baris) >= $maxJobs) break;

        $jobId  = (int)($b['job_id'] ?? 0);
        $orders = array_values((array)($b['orders'] ?? []));
        if ($jobId <= 0 || !$orders) continue;
        if (($b['jenis'] ?? '') === 'pra_era') continue;   // di luar periode terpercaya
        if (($b['jenis'] ?? '') === 'luar')     continue;   // order tidak ada di sistem
        if (($b['jenis'] ?? '') === 'cocok')    continue;   // sudah benar

        /* ---- ANTI-GANDA: sudah pernah dikoreksi? ---- */
        $ada = (int)db()->query(
            "SELECT COUNT(*) FROM stock_movements
              WHERE ref_type = 'rypc_job' AND ref_id = " . $jobId
        )->fetchColumn();
        if ($ada > 0) {
            $baris[] = ['job_id' => $jobId, 'orders' => $orders, 'status' => 'sudah',
                        'pesan' => 'sudah pernah dikoreksi'];
            continue;
        }

        $w        = (float)($b['lebar'] ?? 0);
        $h        = (float)($b['panjang'] ?? 0);
        $lembar   = max(1, (int)($b['lembar'] ?? 1));
        $mesinM2  = (float)($b['mesin_m2'] ?? 0);
        if ($w <= 0 || $h <= 0 || $mesinM2 <= 0) continue;

        $rows = stock_job_ledger_rows($orders, $dari);
        if (!$rows) {
            $baris[] = ['job_id' => $jobId, 'orders' => $orders, 'status' => 'lewat',
                        'pesan' => 'tidak ada catatan potong untuk dibetulkan'];
            $nDilewati++;
            continue;
        }

        $perOrder = [];
        $nTanpaRoll = 0;   // roll_id kosong  → saat dipotong TIDAK mengurangi roll
        $nRollHilang = 0;  // roll sudah dihapus dari stock_rolls
        $nRollAsing = 0;   // roll milik produk lain (data silang — berbahaya)
        foreach ($rows as $r) {
            if (empty($r['roll_id'])) { $nTanpaRoll++; continue; }
            if (empty($r['roll_row_id'])) { $nRollHilang++; continue; }
            if ((int)$r['roll_product'] !== (int)$r['product_id']) { $nRollAsing++; continue; }
            $on = (string)$r['order_number'];
            $perOrder[$on][] = $r;
        }

        /* Bila ADA baris yang tidak bisa dibalikkan dengan pasti, SELURUH job
         * dilewati — bukan sebagian. Alasannya: koreksi bekerja dengan
         * membatalkan catatan lama lalu memotong ulang; bila sebagian catatan
         * lama tidak bisa dibatalkan, hasil akhirnya justru memotong LEBIH
         * banyak dari seharusnya (inilah sumber selisih 5,5 m pada uji pertama).
         *
         * TEMUAN NYATA 12 Okt 2026: ada 30 baris buku besar yang menunjuk roll
         * yang SUDAH DIHAPUS (roll_id 33–39 — sisa roll lama sebelum dirapikan).
         * Saat roll dihapus, sisa meter-nya ikut hilang sehingga pembalikan ke
         * roll itu tidak mungkin dilakukan. Job semacam ini diserahkan ke opname
         * fisik pemilik, bukan ditebak sistem. */
        if ($nTanpaRoll > 0 || $nRollHilang > 0 || $nRollAsing > 0) {
            $sebab = [];
            if ($nRollHilang > 0) $sebab[] = $nRollHilang . ' catatan menunjuk roll yang sudah dihapus';
            if ($nTanpaRoll > 0)  $sebab[] = $nTanpaRoll . ' catatan tanpa roll';
            if ($nRollAsing > 0)  $sebab[] = $nRollAsing . ' catatan menunjuk roll produk lain';
            $baris[] = ['job_id' => $jobId, 'orders' => $orders, 'status' => 'lewat',
                        'pesan' => 'tidak bisa dibetulkan otomatis: ' . implode(', ', $sebab)];
            $nDilewati++;
            continue;
        }
        if (!$perOrder) {
            $baris[] = ['job_id' => $jobId, 'orders' => $orders, 'status' => 'lewat',
                        'pesan' => 'catatan lama tidak menunjuk roll fisik'];
            $nDilewati++;
            continue;
        }

        /* ---- Luas TERCATAT (dasar pembagian proporsional) ---- */
        $catatM2 = 0.0;
        foreach ($perOrder as $rr) {
            foreach ($rr as $r) {
                $rw = (float)($r['roll_w'] ?? 0);
                if ($rw <= 0) continue;
                $catatM2 += abs((float)$r['meters']) * $rw;
            }
        }

        /* Job tanpa catatan sama sekali: hanya aman bila ordonya SATU (tidak perlu
         * membagi) — kalau gabungan, pembagiannya tidak bisa diketahui, jadi
         * lebih baik dilewatkan daripada menebak. */
        if ($catatM2 <= 0 && count($perOrder) > 1) {
            $baris[] = ['job_id' => $jobId, 'orders' => $orders, 'status' => 'lewat',
                        'pesan' => 'gabungan tanpa catatan — pembagian tidak bisa dipastikan'];
            $nDilewati++;
            continue;
        }

        /* ---- Roll yang BENAR: lebar TERDEKAT dengan kotak mesin ----
         * KOREKSI PENTING (12 Okt 2026). Kotak mesin dari RYPC sudah memuat
         * SISI-nya: `width_m` = arah LEBAR roll, `height_m` = arah JALAN roll.
         * Jadi:
         *   - roll yang dibutuhkan = roll tersempit yang ≥ width_m
         *     (persis "cari angka terdekat" yang dilakukan operator), dan
         *   - meter yang habis = **height_m × jumlah lembar**.
         *
         * Sebelumnya saya memakai `luas ÷ lebar roll` — itu SALAH untuk stok:
         * memotong lembar 0,91×1,21 dari roll 1,1 m menghabiskan **1,21 m**
         * jalan, bukan 1,00 m. Angka "luas ÷ lebar" hanya berlaku bila lembar
         * memenuhi lebar roll, dan di panel ini justru lembar yang sempit yang
         * sering dibahas. Terlihat di pratinjau pertama: job 14304 dihitung
         * 1,00 m padahal nyatanya 1,21 m.
         *
         * Margin pinggir (stock_margin_cm) TIDAK ditambahkan lagi di sini —
         * kotak mesin sudah memuat ukuran tercetak; menambahkannya akan
         * dihitung dua kali dan bisa memaksa "putar" yang tidak pernah terjadi
         * (contoh: 2,01 + 0,1 = 2,11 > roll 2,1 m padahal operator memotong
         * 2,01 m dengan lebar roll 2,1 m). */
        $pid = (int)($rows[0]['product_id'] ?? 0);

        /* Total meter jalan untuk job ini (satu file = satu kali jalan).
         * Bila mesin melaporkan lebih dari satu lembar, jalan bertambah
         * sebanyak itu (satu lembar = satu kali jalan pada mesin UV datar). */
        $feedTotal = round($h * $lembar, 3);

        /* BUG NYATA yang ditemukan saat uji (12 Okt 2026): memilih roll HANYA
         * dari lebarnya membuat roll 2,1 m yang sisanya tinggal 0,2 m ikut
         * terpilih untuk potongan 1,21 m → rolls.used_m melampaui panjang roll,
         * roll ditandai 'habis', dan angka stok jadi tidak seimbang
         * (terukur: perubahan −1,06 m padahal seharusnya +3,09 m).
         * Sekarang memakai `stock_pick_roll()` yang sama dengan pemotongan
         * normal: lebar harus cukup DAN sisa meter diutamakan. */
        $pick = stock_pick_roll($pid, $w, $feedTotal);
        $rollBaruId  = $pick ? (int)$pick['id'] : null;
        $rollBaruW   = $pick ? round((float)$pick['lebar'], 2) : round($w, 2);
        $rollKurang  = $pick ? empty($pick['cukup']) : true;

        /* ---- Target per order: dibagi proporsional terhadap luas tercatat ----
         * Untuk order TUNGGAL otomatis mendapat 100%. Untuk job GABUNGAN,
         * masing-masing order tidak menyimpan ukurannya sendiri di data mesin,
         * jadi bagian teradil adalah sebanding dengan luas yang tercatat. */
        $rencana = [];
        foreach ($perOrder as $on => $rr) {
            $areaOrder = 0.0; $metersLama = 0.0;
            foreach ($rr as $r) {
                $rw = (float)($r['roll_w'] ?? 0);
                if ($rw <= 0) continue;
                $areaOrder   += abs((float)$r['meters']) * $rw;
                $metersLama  += abs((float)$r['meters']);
            }
            $porsi = $catatM2 > 0
                   ? ($areaOrder / $catatM2)
                   : (1.0 / max(1, count($perOrder)));

            $targetM = round($feedTotal * $porsi, 3);
            $delta   = round($targetM - $metersLama, 3);

            $rencana[] = [
                'order'     => $on,
                'rows'      => $rr,
                'lama_m'    => round($metersLama, 3),
                'target_m'  => $targetM,
                'delta_m'   => $delta,
                'area_lama' => round($areaOrder, 3),
                'area_baru' => round($rollBaruW * $targetM, 3),
            ];
        }

        $totalLama = array_sum(array_column($rencana, 'lama_m'));
        $totalBaru = array_sum(array_column($rencana, 'target_m'));
        $hemat     = round($totalLama - $totalBaru, 3);

        $item = [
            'job_id'     => $jobId,
            'orders'     => $orders,
            'file'       => (string)($b['file'] ?? ''),
            'mulai'      => (string)($b['mulai'] ?? ''),
            'kotak'      => round($w, 2) . '×' . round($h, 2),
            'lembar'     => $lembar,
            'mesin_m2'   => round($mesinM2, 3),
            'catat_m2'   => round($catatM2, 3),
            'roll_lama'  => (float)($rows[0]['roll_w'] ?? 0),
            'roll_baru'  => $rollBaruW,
            'lama_m'     => round($totalLama, 3),
            'target_m'   => round($totalBaru, 3),
            'hemat_m'    => $hemat,
            'tanpa_roll' => $nTanpaRoll,
            'roll_kurang'=> $rollKurang,
            'rinci'      => array_map(static function ($r) {
                return ['order' => $r['order'], 'lama_m' => $r['lama_m'],
                        'target_m' => $r['target_m'], 'delta_m' => $r['delta_m'],
                        'area_lama' => $r['area_lama'], 'area_baru' => $r['area_baru']];
            }, $rencana),
            'status'     => 'perlu',
            'pesan'      => '',
        ];

        /* ---- Terapkan (hanya bila apply) ---- */
        if ($apply) {
            $okSemua = true;
            foreach ($rencana as $rp) {
                /* (a) Kembalikan potongan LAMA ke roll asalnya */
                foreach ($rp['rows'] as $r) {
                    $mm = abs((float)$r['meters']);
                    if ($mm <= 0) continue;
                    $rr = stock_ledger_add((int)$r['product_id'], 'koreksi', $mm, [
                        'reason'   => 'koreksi_gabungan',
                        'note'     => 'Koreksi job ' . $jobId . ' — batalkan catatan lama '
                                      . $rp['order'] . ' (' . rtrim(rtrim(number_format($mm, 3, ',', '.'), '0'), ',') . ' m)',
                        'ref_type' => 'rypc_job',
                        'ref_id'   => $jobId,
                        'roll_id'  => $r['roll_id'] ? (int)$r['roll_id'] : null,
                        'unit_cost'=> null,
                    ]);
                    if (empty($rr['ok'])) $okSemua = false;
                }
                /* (b) Potong ULANG sesuai kenyataan mesin, dari roll yang benar */
                if ($rp['target_m'] > 0) {
                    $rr = stock_ledger_add((int)$pid, 'potong', -$rp['target_m'], [
                        'reason'   => 'koreksi_gabungan',
                        'note'     => 'Koreksi job ' . $jobId . ' — potong ulang menurut mesin '
                                      . $rp['order'] . ' (' . rtrim(rtrim(number_format($rp['target_m'], 3, ',', '.'), '0'), ',')
                                      . ' m di roll ' . rtrim(rtrim(number_format($rollBaruW, 2, ',', '.'), '0'), ',') . ' m)',
                        'ref_type' => 'rypc_job',
                        'ref_id'   => $jobId,
                        'roll_id'  => $rollBaruId,
                        'unit_cost'=> null,
                    ]);
                    if (empty($rr['ok'])) $okSemua = false;
                }
            }
            $item['status'] = $okSemua ? 'diperbaiki' : 'gagal';
            $item['pesan']  = $okSemua ? 'stok disesuaikan' : 'sebagian gagal — periksa buku besar';
            if ($okSemua) { $nDiperbaiki++; $mDikembalikan += $hemat; }
        } else {
            $nDilewati++;
            $item['pesan'] = 'pratinjau (belum diubah)';
        }

        $baris[] = $item;
    }

    return [
        'ok'      => true,
        'baris'   => $baris,
        'ringkas' => ['apply' => $apply, 'diperbaiki' => $nDiperbaiki,
                      'dilewati' => $nDilewati, 'hemat_m' => round($mDikembalikan, 3),
                      'ambang' => $ambang, 'dari' => $dari],
        'error'   => '',
    ];
}

} // end guard