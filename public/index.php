<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Makassar');

require_once __DIR__ . '/../config/database.php';

$order_number = $_GET['order'] ?? '';
if (empty($order_number)) {
    die('No order number provided.');
}

// =========================================================
// HANDLE AJAX REQUEST
// =========================================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT o.status, o.progress, o.remaining_time, o.print_status, o.payment_status, o.updated_at
            FROM orders o
            WHERE o.order_number = ?
        ");
        $stmt->execute([$order_number]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'order' => [
                'status' => $order['status'],
                'progress' => (int)$order['progress'],
                'remaining_time' => $order['remaining_time'] ?? '00:00:00',
                'print_status' => $order['print_status'] ?? '',
                'payment_status' => $order['payment_status'],
                'updated_at' => $order['updated_at']
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================
// MAIN PAGE (non-AJAX)
// =========================================================

// ===== AMBIL DATA ORDER =====
$stmt = $pdo->prepare("
    SELECT o.*, 
           c.name as customer_name, c.address, c.phone, c.email,
           u1.username as creator_username, u1.role as creator_role,
           u2.username as updater_username, u2.role as updater_role
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    LEFT JOIN users u1 ON o.created_by = u1.id
    LEFT JOIN users u2 ON o.updated_by = u2.id
    WHERE o.order_number = ?
");
$stmt->execute([$order_number]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found.');
}

// Fallback
if (empty($order['creator_username'])) {
    $order['creator_username'] = 'System';
    $order['creator_role'] = '';
}
if (empty($order['updater_username'])) {
    $order['updater_username'] = $order['creator_username'];
    $order['updater_role'] = $order['creator_role'];
}

// ===== AMBIL ITEMS =====
$stmt_items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt_items->execute([$order['id']]);
$items = $stmt_items->fetchAll();

// ===== AMBIL SETTINGS =====
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$company_name = $settings['company_name'] ?? 'CREATIVE GEEK';
$address = $settings['address'] ?? 'Maubeli, Kefamenanu, NTT 85613';
$phone = $settings['phone'] ?? '0812-4628-4684';
$email = $settings['email'] ?? 'creativegeekhq@gmail.com';
$hours = $settings['hours'] ?? '08:00 - 17:00 WITA';
$whatsapp = $settings['whatsapp_number'] ?? '6281246284684';

// ===== STATUS PENGERJAAN =====
$statusStep = [
    'diterima'  => 0,
    'desain'    => 0,
    'cetak'     => 1,
    'finishing' => 2,
    'siap_ambil'=> 3
];
$currentStatus = $order['status'] ?? 'diterima';
$completedCount = $statusStep[$currentStatus] ?? 0;
$stepLabels = ['Order Masuk & Desain', 'Pencetakan & Finishing', 'Selesai'];

// ===== TIMEZONE =====
$wita = new DateTimeZone('Asia/Makassar');
$utc  = new DateTimeZone('UTC');

$orderDateStr = $order['order_date'] ?? null;
if (empty($orderDateStr) || $orderDateStr === '0000-00-00 00:00:00') {
    $orderDate = new DateTime('now', $wita);
} else {
    $orderDate = new DateTime($orderDateStr, $wita);
}

$createdTime = $order['created_at'] ?? null;
if (empty($createdTime) || $createdTime === '0000-00-00 00:00:00') {
    $createdAt = new DateTime('now', $wita);
} else {
    $createdAt = new DateTime($createdTime, $utc);
    $createdAt->setTimezone($wita);
}

$updateTime = $order['updated_at'] ?? $order['created_at'] ?? null;
if (empty($updateTime) || $updateTime === '0000-00-00 00:00:00') {
    $updatedAt = new DateTime('now', $wita);
} else {
    $updatedAt = new DateTime($updateTime, $utc);
    $updatedAt->setTimezone($wita);
}

// ===== BUILD STATUS DETAIL =====
$statusDetail = [];
for ($i = 0; $i < 3; $i++) {
    $isDone = ($i < $completedCount);
    $isActive = ($i == $completedCount && $completedCount < 3);
    $color = $isDone ? '#F5A623' : ($isActive ? '#E53935' : '#A6A6A6');
    $icon = $isDone ? 'fa-check-circle' : ($isActive ? 'fa-spinner fa-pulse' : 'fa-circle');
    $text = $isDone ? 'Selesai' : ($isActive ? 'Sedang dikerjakan' : 'Belum dikerjakan');

    $displayUser = '';
    $displayRole = '';
    $displayTime = null;
    $displayText = '';

    if ($i == 0) {
        if ($isDone) {
            $displayUser = htmlspecialchars($order['creator_username']);
            $displayRole = htmlspecialchars($order['creator_role']);
            $displayTime = $orderDate;
            $displayText = 'Diterima oleh';
        } elseif ($isActive) {
            $displayUser = htmlspecialchars($order['updater_username']);
            $displayRole = htmlspecialchars($order['updater_role']);
            $displayTime = $updatedAt;
            $displayText = 'Sedang dikerjakan oleh';
        }
    } else {
        if ($isDone || $isActive) {
            $displayUser = htmlspecialchars($order['updater_username']);
            $displayRole = htmlspecialchars($order['updater_role']);
            $displayTime = $updatedAt;
            $displayText = $isDone ? 'Dikerjakan oleh' : 'Sedang dikerjakan oleh';
        }
    }

    $statusDetail[] = [
        'label'        => $stepLabels[$i],
        'color'        => $color,
        'icon'         => $icon,
        'text'         => $text,
        'done'         => $isDone,
        'active'       => $isActive,
        'displayUser'  => $displayUser,
        'displayRole'  => $displayRole,
        'displayTime'  => $displayTime,
        'displayText'  => $displayText,
    ];
}

$showLastUpdate = ($completedCount < 3);

// ===== PROGRESS CETAK =====
$progress = (int)($order['progress'] ?? 0);
$remaining = $order['remaining_time'] ?? '00:00:00';
$printStatus = $order['print_status'] ?? '';
$showProgress = in_array($currentStatus, ['finishing', 'siap_ambil']);

// ===== TENTUKAN LABEL & ICON =====
$statusIcon = 'fa-spinner fa-pulse';
$statusLabel = 'Sedang dicetak';
$statusClass = 'text-gold';

if ($currentStatus === 'siap_ambil') {
    $statusIcon = 'fa-check-circle';
    $statusLabel = 'Selesai';
    $statusClass = 'text-green-400';
} elseif (!empty($printStatus)) {
    if ($printStatus === 'Print') {
        $statusIcon = 'fa-print';
        $statusLabel = 'Sedang dicetak';
        $statusClass = 'text-gold';
    } elseif ($printStatus === 'Pause') {
        $statusIcon = 'fa-pause-circle';
        $statusLabel = 'Cetak Ditunda';
        $statusClass = 'text-yellow-400';
    } elseif (in_array($printStatus, ['Stop', 'Abort', 'Cancelled'])) {
        $statusIcon = 'fa-ban';
        $statusLabel = 'Dibatalkan';
        $statusClass = 'text-red-500';
    } elseif (in_array($printStatus, ['Done', 'Idle'])) {
        $statusIcon = 'fa-check-circle';
        $statusLabel = 'Selesai';
        $statusClass = 'text-green-400';
    }
}

$countdownActive = ($showProgress && !in_array($printStatus, ['Pause', 'Stop', 'Abort', 'Cancelled']) && $remaining !== '00:00:00' && $remaining !== '');

// ===== OPEN GRAPH =====
$og_title = "Nota Digital - " . htmlspecialchars($order['order_number'] ?? '');
$og_description = "Detail pesanan " . htmlspecialchars($order['customer_name'] ?? '') . " - " . $company_name;
$og_image = "https://cloud.creativegeek-idn.com/logo.png";
$og_url = "https://cloud.creativegeek-idn.com/index?order=" . urlencode($order_number);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Nota Digital - <?= htmlspecialchars($order['order_number'] ?? '') ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <meta property="og:title" content="<?= $og_title ?>">
    <meta property="og:description" content="<?= $og_description ?>">
    <meta property="og:image" content="<?= $og_image ?>">
    <meta property="og:url" content="<?= $og_url ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($company_name) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta property="fb:app_id" content="1637544314455583">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #0a0a0a; display: flex; justify-content: center; padding: 30px 0; min-height: 100vh; }
        .card-dark { background: #151515; border: 1px solid #2a2a2a; border-radius: 16px; }
        .text-gold { color: #F5A623; }
        .bg-gold { background: #F5A623; }
        .border-gold { border-color: #F5A623; }
        .shadow-gold { box-shadow: 0 0 20px rgba(245, 166, 35, 0.15); }
        .bg-gold\/10 { background: rgba(245, 166, 35, 0.1); }
        .text-yellow-400 { color: #fbbf24; }
        .text-red-500 { color: #ef4444; }

        .progress-bar-transition {
            transition: width 0.8s ease-in-out;
        }

        /* ===== VERTICAL TIMELINE – SECOND LINE ONE STEP CLOSER ===== */
        .status-list-container {
            overflow: visible;
        }

        .status-item {
            display: flex;
            align-items: stretch;
            position: relative;
            padding-bottom: 24px;
            margin: 0;
        }
        .status-item:last-child {
            padding-bottom: 0;
        }

        .status-wrapper {
            align-self: stretch;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            min-width: 20px;
            overflow: visible;
        }

        /* Default line style (for first line, between step 1 and 2) */
        .status-line-vertical {
            position: absolute;
            top: 15px;
            left: 50%;
            transform: translateX(-50%);
            width: 2px;
            height: calc(100% + 24px); /* extends to bottom of padding, touches next dot */
            background-color: #FFFFFF;
            z-index: 1;
            border: none;
            margin: 0;
            padding: 0;
            display: block;
        }

        /* Special rule for the SECOND line – one step closer */
        .status-item:nth-child(2) .status-line-vertical {
            height: auto;
            top: 15px;
            bottom: -20px;  /* one step from -18px → -20px, almost touching */
        }

        /* Hide line on the last item (inline style also does this) */
        .status-item:last-child .status-line-vertical {
            display: none !important;
        }

        @media (max-width: 480px) {
            .card-dark { padding: 16px !important; }
            .text-xl { font-size: 1.1rem; }
            .status-item { padding-bottom: 20px; }
        }

        @media print {
            body { padding: 0; background: white; }
            .card-dark { background: white !important; border-color: #ddd !important; }
            .text-white { color: black !important; }
            .text-gray-500, .text-gray-400, .text-[#777] { color: #666 !important; }
            .bg-gold { background: #F5A623 !important; }
            .bg-green-600 { background: #22c55e !important; }
            .bg-red-600 { background: #ef4444 !important; }
            .border-gold { border-color: #F5A623 !important; }
        }
        .fa-spinner { animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="w-full max-w-[440px] card-dark p-5 md:p-6 shadow-2xl text-white mx-4">

    <!-- ===== HEADER ===== -->
    <div class="flex items-center justify-between border-b border-gold/30 pb-4">
        <div>
            <div class="text-lg md:text-xl font-bold text-gold tracking-tight">
                <i class="fas fa-receipt mr-2"></i>NOTA DIGITAL
            </div>
            <div class="text-[#777] text-[10px] md:text-[11px]">
                No. Pesanan: <b class="text-gold" id="orderNumberDisplay"><?= htmlspecialchars($order['order_number'] ?? '') ?></b>
                <span class="block"><?= $orderDate->format('d F Y, H:i') ?></span>
            </div>
        </div>
        <div class="bg-gold text-black font-bold px-3 py-1.5 rounded-lg text-xs md:text-sm shadow-lg shadow-gold/30">
            <?= htmlspecialchars($company_name) ?>
        </div>
    </div>

    <!-- ===== STATUS BAYAR ===== -->
    <div class="card-dark p-4 mt-5 border border-[#222]">
        <div class="flex items-center justify-between">
            <span class="font-medium text-sm"><i class="fas fa-credit-card mr-2 text-gold"></i>Status Bayar</span>
            <span id="paymentStatusBadge" class="<?= $order['payment_status'] === 'lunas' ? 'bg-green-600' : 'bg-red-600' ?> text-white px-4 py-1 rounded-full text-xs font-bold">
                <?php if ($order['payment_status'] === 'lunas'): ?>
                    <i class="fas fa-check-circle mr-1"></i> LUNAS
                <?php else: ?>
                    <i class="fas fa-times-circle mr-1"></i> BELUM BAYAR
                <?php endif; ?>
            </span>
        </div>
    </div>

    <!-- ===== STATUS PENGERJAAN + PROGRESS CETAK ===== -->
    <div class="card-dark p-4 md:p-5 mt-4 border border-[#222]">
        <div class="flex items-center justify-between mb-4">
            <div>
                <div class="font-semibold text-sm"><i class="fas fa-tasks mr-2 text-gold"></i>Status Pengerjaan</div>
                <?php if ($showLastUpdate): ?>
                    <div class="text-[#777] text-[10px]"><i class="far fa-clock mr-1"></i>Terakhir diupdate: <span id="lastUpdateTime"><?= $updatedAt->format('d F Y, H:i:s') ?></span></div>
                    <div class="text-[#777] text-[10px]"><i class="fa fa-print mr-1"></i>Proses Pencetakan Terupdate Realtime</div>
                <?php endif; ?>
            </div>
            <div class="text-[10px] text-gold bg-gold/10 px-3 py-1 rounded-full border border-gold/20 font-semibold">
                <span id="stepCounter"><?= min($completedCount + 1, 3) ?></span> / 3
            </div>
        </div>

        <div class="status-list-container">
            <?php foreach ($statusDetail as $index => $s): ?>
                <div class="status-item">
                    <!-- ICON -->
                    <div class="w-9 h-9 md:w-10 md:h-10 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0 mt-0.5" style="background: <?= $s['color'] ?>;">
                        <i class="fas <?= $s['icon'] ?>"></i>
                    </div>

                    <!-- KONTEN -->
                    <div class="flex-1 min-w-0 pb-1 ml-4">
                        <div class="font-semibold text-sm" style="color: <?= $s['color'] ?>;">
                            <?= htmlspecialchars($s['label']) ?>
                        </div>
                        <?php if (!empty($s['displayUser'])): ?>
                            <div class="text-[#777] text-xs">
                                <?= $s['displayText'] ?> <span class="text-white"><?= $s['displayUser'] ?></span>
                                <?php if (!empty($s['displayRole'])): ?>
                                    <span class="text-gray-500">(<?= $s['displayRole'] ?>)</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($s['displayTime']): ?>
                                <div class="text-[#777] text-xs">
                                    <i class="far fa-clock mr-1"></i> <?= $s['displayTime']->format('d F Y, H:i') ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-[#777] text-xs"><?= htmlspecialchars($s['text']) ?></div>
                        <?php endif; ?>

                        <!-- PROGRESS BAR DI STEP 2 -->
                        <?php if ($index == 1 && $showProgress): ?>
                            <div class="card-dark p-3 mt-3 border border-[#222]">
                                <div class="flex justify-between items-center">
                                    <span class="font-semibold text-xs"><i class="fas fa-clock mr-1 text-gold"></i>Sisa Waktu</span>
                                    <span class="text-gold font-bold text-sm" id="countdownDisplay">
                                        <?= htmlspecialchars($remaining) ?>
                                    </span>
                                </div>
                                <div class="mt-2 w-full bg-gray-700 rounded-full h-2.5">
                                    <div class="bg-gold h-2.5 rounded-full progress-bar-transition" id="progressBar" style="width: <?= min(100, $progress) ?>%;"></div>
                                </div>
                                <div class="text-xs text-gray-400 mt-1 flex justify-between items-center">
                                    <span>Progress: <span id="progressPercent"><?= $progress ?></span>%</span>
                                    <span id="printStatusLabel" class="<?= $statusClass ?>">
                                        <i class="fas <?= $statusIcon ?> mr-1"></i><?= $statusLabel ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- DOT + GARIS -->
                    <div class="status-wrapper">
                        <div class="h-[15px] w-[15px] rounded-full border-[3px] flex-shrink-0" style="border-color: <?= $s['color'] ?>; background: <?= $s['done'] ? $s['color'] : 'transparent' ?>; z-index: 2; position: relative;"></div>
                        <?php if ($index < count($statusDetail) - 1): ?>
                            <div class="status-line-vertical" style="background: #FFFFFF;"></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ===== DETAIL CUSTOMER & PESANAN ===== -->
    <div class="card-dark p-4 md:p-5 mt-4 border border-[#222]">
        <div class="flex items-start justify-between flex-wrap gap-2">
            <div>
                <div class="text-xs text-[#777] uppercase tracking-wider mb-1"><i class="fas fa-user mr-1 text-gold"></i>Customer</div>
                <div class="font-bold text-gold text-base md:text-lg" id="customerName"><?= htmlspecialchars($order['customer_name'] ?? '') ?></div>
                <?php if (!empty($order['address'])): ?>
                    <div class="text-sm text-[#ccc] mt-1 flex items-center gap-2"><i class="fas fa-map-marker-alt text-gold w-4 text-xs"></i> <?= htmlspecialchars($order['address']) ?></div>
                <?php endif; ?>
                <?php if (!empty($order['phone'])): ?>
                    <div class="text-sm text-[#ccc] flex items-center gap-2"><i class="fas fa-phone text-gold w-4 text-xs"></i> <?= htmlspecialchars($order['phone']) ?></div>
                <?php endif; ?>
                <?php if (!empty($order['email'])): ?>
                    <div class="text-sm text-[#ccc] flex items-center gap-2"><i class="fas fa-envelope text-gold w-4 text-xs"></i> <?= htmlspecialchars($order['email']) ?></div>
                <?php endif; ?>
            </div>
            <div class="bg-gold/10 border border-gold/30 rounded-lg px-3 py-1 text-[10px] text-gold whitespace-nowrap">
                #<?= htmlspecialchars($order['order_number']) ?>
            </div>
        </div>

        <hr class="border-[#222] my-4">

        <div class="text-xs text-gold font-semibold uppercase tracking-wider mb-3">
            <i class="fas fa-list-ul mr-1"></i>Rincian Pesanan
        </div>

        <?php foreach ($items as $item): ?>
            <div class="bg-black/30 rounded-lg p-3 mb-3 border border-[#222] hover:border-gold/30 transition">
                <div class="flex justify-between items-start gap-2">
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-white text-sm md:text-base break-words"><?= htmlspecialchars($item['product_name'] ?? '') ?></div>
                        <div class="text-xs text-[#777] mt-1">
                            <?= htmlspecialchars($item['qty'] ?? 0) ?> pcs x Rp<?= number_format($item['price_per_unit'] ?? 0, 0, ',', '.') ?>
                        </div>
                        <?php if (!empty($item['material']) && $item['material'] !== '-'): ?>
                            <div class="text-xs text-[#777]"><i class="fas fa-box mr-1"></i><?= htmlspecialchars($item['material']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-gold font-bold text-sm md:text-base whitespace-nowrap">Rp<?= number_format($item['total'] ?? 0, 0, ',', '.') ?></div>
                </div>
            </div>
        <?php endforeach; ?>

        <hr class="border-[#222] my-4">
        <div class="space-y-1 text-sm">
            <div class="flex justify-between text-[#777]">
                <span>Subtotal</span>
                <span>Rp<?= number_format($order['subtotal'] ?? 0, 0, ',', '.') ?></span>
            </div>
            <?php if (($order['discount'] ?? 0) > 0): ?>
                <div class="flex justify-between text-[#777]">
                    <span>Diskon</span>
                    <span class="text-red-400">- Rp<?= number_format($order['discount'] ?? 0, 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>
            <?php if (($order['design_fee'] ?? 0) > 0): ?>
                <div class="flex justify-between text-[#777]">
                    <span>Jasa Desain</span>
                    <span class="text-gold">+ Rp<?= number_format($order['design_fee'] ?? 0, 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>
            <div class="flex justify-between text-gold font-bold text-base border-t border-[#222] pt-2 mt-1">
                <span><i class="fas fa-coins mr-1"></i>Total</span>
                <span>Rp<?= number_format($order['grand_total'] ?? 0, 0, ',', '.') ?></span>
            </div>
        </div>

        <?php if (!empty($order['notes'])): ?>
            <hr class="border-[#222] my-4">
            <div class="text-xs text-[#777]">
                <span class="text-gold"><i class="fas fa-pencil-alt mr-1"></i>Catatan:</span> 
                <?= htmlspecialchars($order['notes']) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ===== SYARAT & KETENTUAN ===== -->
    <div class="card-dark p-4 md:p-5 mt-4 border border-[#222]">
        <div class="font-semibold text-gold text-sm"><i class="fas fa-file-contract mr-1"></i>Ketentuan Layanan</div>
        <div class="text-xs text-[#777] mt-2 space-y-1 leading-relaxed">
            <p><strong class="text-gold">Perhatian:</strong></p>
            <p>1. Ambil pesanan dengan menunjukkan nota ini.</p>
            <p>2. Revisi desain maksimal 2 kali.</p>
            <p>3. Klaim kerusakan cetak maksimal 2x24 jam.</p>
            <p>4. Pesanan wajib diambil maksimal 7 hari.</p>
            <p class="mt-2"><i class="fas fa-phone mr-1"></i> Kritik & saran: <span class="text-gold"><?= htmlspecialchars($phone) ?></span></p>
            <p class="text-gold mt-1"><i class="fas fa-heart mr-1"></i> Terima kasih sudah mempercayai <?= htmlspecialchars($company_name) ?>.</p>
        </div>
    </div>

    <!-- ===== FOOTER ===== -->
    <div class="text-center mt-6 border-t border-[#222] pt-5">
        <div class="font-bold text-xl md:text-2xl text-gold tracking-wide"><?= htmlspecialchars($company_name) ?></div>
        <div class="text-xs text-[#777] mt-1"><i class="fas fa-map-marker-alt mr-1"></i> <?= htmlspecialchars($address) ?></div>
        <div class="text-xs text-gold mt-1"><i class="far fa-clock mr-1"></i> <?= htmlspecialchars($hours) ?></div>
        <div class="text-xs text-[#777]"><i class="fas fa-phone mr-1"></i> <?= htmlspecialchars($phone) ?></div>
        <div class="text-xs text-[#777]"><i class="fas fa-envelope mr-1"></i> <?= htmlspecialchars($email) ?></div>
    </div>

    <!-- ===== TOMBOL WHATSAPP ===== -->
    <a href="https://wa.me/<?= htmlspecialchars($whatsapp) ?>?text=Halo%20<?= urlencode($company_name) ?>%2C%20saya%20mau%20tanya%20soal%20pesanan%20nomor%20<?= urlencode($order['order_number'] ?? '') ?>" 
       class="flex justify-center items-center gap-2 bg-gold text-black font-bold py-3 rounded-xl mt-5 hover:bg-[#e09515] transition shadow-lg shadow-gold/30 text-sm md:text-base">
        <i class="fab fa-whatsapp text-lg"></i> HUBUNGI KAMI
    </a>
    <div class="text-center text-[10px] text-[#444] mt-3">
        <?= htmlspecialchars($company_name) ?> • <?= htmlspecialchars($hours) ?>
    </div>
</div>

<!-- ========================================================= -->
<!-- ===== JAVASCRIPT: AJAX POLLING + COUNTDOWN (FIXED) ===== -->
<!-- ========================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    const orderNumber = '<?= $order_number ?>';
    const progressBar = document.getElementById('progressBar');
    const progressPercent = document.getElementById('progressPercent');
    const countdownDisplay = document.getElementById('countdownDisplay');
    const printStatusLabel = document.getElementById('printStatusLabel');
    const stepCounter = document.getElementById('stepCounter');
    const lastUpdateTime = document.getElementById('lastUpdateTime');
    const paymentBadge = document.getElementById('paymentStatusBadge');

    // ===== VARIABEL COUNTDOWN =====
    let countdownInterval = null;
    let lastRemaining = null;
    let lastPrintStatus = null;

    // ===== PARSE TIME =====
    function parseTime(str) {
        if (!str) return 0;
        const parts = str.split(':').map(Number);
        if (parts.length === 3) {
            return parts[0] * 3600 + parts[1] * 60 + parts[2];
        }
        return 0;
    }

    // ===== START COUNTDOWN (menggunakan timestamp) =====
    function startCountdown(remainingStr) {
        // Hentikan interval yang lama
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }

        let totalSeconds = parseTime(remainingStr);
        if (totalSeconds <= 0) {
            countdownDisplay.textContent = '00:00:00';
            return;
        }

        // Simpan waktu awal (dalam milidetik) dan total detik awal
        const startTime = Date.now();
        let remainingSecs = totalSeconds;

        countdownInterval = setInterval(function() {
            const elapsed = (Date.now() - startTime) / 1000;
            let remaining = Math.max(0, remainingSecs - elapsed);

            if (remaining <= 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                countdownDisplay.textContent = '00:00:00';
                return;
            }

            const h = String(Math.floor(remaining / 3600)).padStart(2, '0');
            const m = String(Math.floor((remaining % 3600) / 60)).padStart(2, '0');
            const s = String(Math.floor(remaining % 60)).padStart(2, '0');
            countdownDisplay.textContent = h + ':' + m + ':' + s;
        }, 1000);
    }

    function stopCountdown() {
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }

    // ===== AJAX POLLING =====
    function fetchLatestData() {
        fetch(window.location.pathname + '?order=' + encodeURIComponent(orderNumber) + '&ajax=1')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateUI(data);
                }
            })
            .catch(err => console.log('Polling error:', err));
    }

    // ===== UPDATE UI =====
    function updateUI(data) {
        const order = data.order;

        // 1. Progress bar
        if (progressBar) {
            const newProgress = order.progress || 0;
            progressBar.style.width = Math.min(100, newProgress) + '%';
            if (progressPercent) progressPercent.textContent = newProgress;
        }

        // 2. Sisa waktu & countdown
        const newRemaining = order.remaining_time || '00:00:00';
        const printStatus = order.print_status || '';
        const isPaused = ['Pause', 'Stop', 'Abort', 'Cancelled'].includes(printStatus);
        const isDone = ['Done', 'Idle'].includes(printStatus) || order.status === 'siap_ambil';

        // Deteksi perubahan (hanya untuk logika tambahan, tidak digunakan untuk restart)
        const remainingChanged = (newRemaining !== lastRemaining);
        const statusChanged = (printStatus !== lastPrintStatus);

        // Selalu sinkronkan ulang countdown dengan data terbaru
        if (isPaused) {
            stopCountdown();
            countdownDisplay.textContent = '⏸️ Ditunda';
        } else if (isDone) {
            stopCountdown();
            countdownDisplay.textContent = '00:00:00';
        } else {
            // Mulai ulang countdown dengan nilai baru (atau sama)
            startCountdown(newRemaining);
        }

        // Simpan nilai terakhir untuk referensi
        lastRemaining = newRemaining;
        lastPrintStatus = printStatus;

        // 3. Status label
        if (printStatusLabel) {
            let icon = 'fa-spinner fa-pulse';
            let label = 'Sedang dicetak';
            let cls = 'text-gold';

            if (order.status === 'siap_ambil') {
                icon = 'fa-check-circle';
                label = 'Selesai';
                cls = 'text-green-400';
            } else if (printStatus === 'Print') {
                icon = 'fa-print';
                label = 'Sedang dicetak';
                cls = 'text-gold';
            } else if (printStatus === 'Pause') {
                icon = 'fa-pause-circle';
                label = 'Cetak Ditunda';
                cls = 'text-yellow-400';
            } else if (['Stop', 'Abort', 'Cancelled'].includes(printStatus)) {
                icon = 'fa-ban';
                label = 'Dibatalkan';
                cls = 'text-red-500';
            } else if (['Done', 'Idle'].includes(printStatus) || order.status === 'siap_ambil') {
                icon = 'fa-check-circle';
                label = 'Selesai';
                cls = 'text-green-400';
            }

            printStatusLabel.innerHTML = `<i class="fas ${icon} mr-1"></i>${label}`;
            printStatusLabel.className = cls;
        }

        // 4. Step counter
        if (stepCounter) {
            const statusStep = {
                'diterima': 0,
                'desain': 0,
                'cetak': 1,
                'finishing': 2,
                'siap_ambil': 3
            };
            const completed = statusStep[order.status] || 0;
            stepCounter.textContent = Math.min(completed + 1, 3);
        }

        // 5. Payment status
        if (paymentBadge && order.payment_status) {
            if (order.payment_status === 'lunas') {
                paymentBadge.className = 'bg-green-600 text-white px-4 py-1 rounded-full text-xs font-bold';
                paymentBadge.innerHTML = '<i class="fas fa-check-circle mr-1"></i> LUNAS';
            } else {
                paymentBadge.className = 'bg-red-600 text-white px-4 py-1 rounded-full text-xs font-bold';
                paymentBadge.innerHTML = '<i class="fas fa-times-circle mr-1"></i> BELUM BAYAR';
            }
        }

        // 6. Last update time (waktu sekarang)
        if (lastUpdateTime) {
            const now = new Date();
            const dateStr = now.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
            const timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            lastUpdateTime.textContent = dateStr + ', ' + timeStr;
        }
    }

    // ===== INISIALISASI =====
    <?php if ($countdownActive): ?>
        // Mulai countdown dengan nilai dari server
        const initialRemaining = '<?= $remaining ?>';
        startCountdown(initialRemaining);
        lastRemaining = initialRemaining;
        lastPrintStatus = '<?= $printStatus ?>';
    <?php elseif ($showProgress && in_array($printStatus, ['Pause', 'Stop', 'Abort', 'Cancelled'])): ?>
        // Tampilkan pesan pause/dibatalkan
        if (countdownDisplay) {
            let label = '⏸️ Ditunda';
            if ('<?= $printStatus ?>' === 'Stop' || '<?= $printStatus ?>' === 'Abort' || '<?= $printStatus ?>' === 'Cancelled') {
                label = 'Dibatalkan';
            }
            countdownDisplay.textContent = label;
        }
        lastRemaining = '<?= $remaining ?>';
        lastPrintStatus = '<?= $printStatus ?>';
    <?php else: ?>
        // Tidak aktif, set nilai awal (tidak jalan)
        lastRemaining = '<?= $remaining ?>';
        lastPrintStatus = '<?= $printStatus ?>';
    <?php endif; ?>

    // Polling setiap 5 detik
    setInterval(fetchLatestData, 5000);
    // Panggil pertama kali setelah 1 detik
    setTimeout(fetchLatestData, 1000);
});
</script>

</body>
</html>