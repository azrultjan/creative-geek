<?php
if (!isset($item)) {
    $item = null;
}

$product_name = $item ? $item['product_name'] : '';
$qty = $item ? (int) $item['qty'] : 1;
$price = $item ? (int) $item['price_per_unit'] : '';
$total = $item ? (int) $item['total'] : 0;
$material = $item ? ($item['material'] ?? '-') : '-';

$category = 'lainnya';
$product_lower = strtolower($product_name);
if (strpos($product_lower, 'spanduk') !== false || strpos($product_lower, 'banner') !== false) {
    $category = 'spanduk';
} elseif (strpos($product_lower, 'sticker') !== false) {
    $category = 'sticker';
} elseif (strpos($product_lower, 'sablon') !== false) {
    $category = 'sablon';
}

$lebar = '';
$tinggi = '';
if ($category === 'spanduk') {
    if (preg_match('/(\d+\.?\d*)\s*x\s*(\d+\.?\d*)/', $product_name, $matches)) {
        $lebar = $matches[1];
        $tinggi = $matches[2];
    }
}

$sticker_type = 'label';
if ($category === 'sticker' && strpos($product_name, 'Decal') !== false) {
    $sticker_type = 'decal';
}
$sticker_size = 'A4';
if ($category === 'sticker' && strpos($product_name, 'A3') !== false) {
    $sticker_size = 'A3';
}
$decal_panjang = '';
$decal_lebar = '';
if ($category === 'sticker' && $sticker_type === 'decal') {
    if (preg_match('/(\d+)x(\d+)/', $product_name, $matches)) {
        $decal_panjang = $matches[1];
        $decal_lebar = $matches[2];
    }
}
?>
<div class="item-row border border-gray-700 rounded-lg p-3 mb-3 bg-gray-900">
    <div class="grid grid-cols-12 gap-2 items-start">
        <div class="col-span-2">
            <label class="block text-xs text-gray-400 mb-1">Kategori</label>
            <select class="product-category w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                <option value="spanduk" <?= $category === 'spanduk' ? 'selected' : '' ?>>Spanduk</option>
                <option value="sticker" <?= $category === 'sticker' ? 'selected' : '' ?>>Sticker</option>
                <option value="sablon" <?= $category === 'sablon' ? 'selected' : '' ?>>Sablon</option>
                <option value="lainnya" <?= $category === 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
            </select>
        </div>

        <div class="spanduk-field dynamic-field col-span-9 grid grid-cols-3 gap-2">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Nama Spanduk</label>
                <input type="text" class="spanduk-nama price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="Nama Spanduk" value="<?= htmlspecialchars($product_name) ?>">
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Lebar (m)</label>
                <input type="text" class="spanduk-lebar price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white angka-only" placeholder="2" value="<?= htmlspecialchars($lebar) ?>">
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Tinggi (m)</label>
                <input type="text" class="spanduk-tinggi price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white angka-only" placeholder="1" value="<?= htmlspecialchars($tinggi) ?>">
            </div>
        </div>

        <div class="sticker-field dynamic-field col-span-9 grid grid-cols-3 gap-2">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Jenis Sticker</label>
                <select class="sticker-type price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                    <option value="label" <?= $sticker_type === 'label' ? 'selected' : '' ?>>Label</option>
                    <option value="decal" <?= $sticker_type === 'decal' ? 'selected' : '' ?>>Decal</option>
                </select>
            </div>
            <div class="sticker-label-field dynamic-field col-span-2">
                <label class="block text-xs text-gray-400 mb-1">Ukuran Label</label>
                <select class="sticker-label-size price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                    <option value="A4" <?= $sticker_size === 'A4' ? 'selected' : '' ?>>A4</option>
                    <option value="A3" <?= $sticker_size === 'A3' ? 'selected' : '' ?>>A3</option>
                </select>
            </div>
            <div class="sticker-decal-field dynamic-field col-span-2 grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Panjang (cm)</label>
                    <input type="number" class="decal-panjang price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="10" value="<?= htmlspecialchars($decal_panjang) ?>">
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Lebar (cm)</label>
                    <input type="number" class="decal-lebar price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="10" value="<?= htmlspecialchars($decal_lebar) ?>">
                </div>
            </div>
        </div>

        <div class="sablon-field dynamic-field col-span-9">
            <label class="block text-xs text-gray-400 mb-1">Ukuran Sablon</label>
            <select class="sablon-size price-trigger w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white">
                <option value="A3" <?= $category === 'sablon' ? 'selected' : '' ?>>A3</option>
            </select>
        </div>

        <div class="manual-field dynamic-field col-span-9">
            <p class="text-xs text-gray-500 italic">Isi nama produk & material di baris bawah</p>
        </div>

        <div class="col-span-1 flex justify-end items-start">
            <button type="button" class="remove-item text-red-500 hover:text-red-300 text-xl font-bold leading-none mt-1">✖</button>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-2 items-end mt-2">
        <div class="col-span-4">
            <label class="block text-xs text-gray-400 mb-1">Nama Produk</label>
            <input type="text" class="product-name-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="<?= htmlspecialchars($product_name) ?>">
        </div>
        <div class="col-span-2">
            <label class="block text-xs text-gray-400 mb-1">Qty</label>
            <input type="number" class="qty-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" value="<?= $qty ?>" min="1">
        </div>
        <div class="col-span-2">
            <label class="block text-xs text-gray-400 mb-1">Harga (Rp)</label>
            <input type="number" class="price-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" step="1000" value="<?= $price ?>">
        </div>
        <div class="col-span-2">
            <label class="block text-xs text-gray-400 mb-1">Total</label>
            <input type="number" class="item-total w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" readonly value="<?= $total ?>">
        </div>
        <div class="col-span-2"></div>
    </div>

    <div class="mt-2">
        <label class="block text-xs text-gray-400 mb-1">Material / Catatan</label>
        <input type="text" class="material-input w-full bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white" placeholder="Material atau catatan tambahan" value="<?= htmlspecialchars($material) ?>">
    </div>
</div>
