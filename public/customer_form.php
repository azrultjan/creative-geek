<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../config/database.php';
require_once '../includes/functions.php';

$id = $_GET['id'] ?? 0;
$is_edit = $id > 0;
$customer = null;

if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    if (!$customer) die('Pelanggan tidak ditemukan.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $address = $_POST['address'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $email = $_POST['email'] ?? '';
    $customer_notes = $_POST['customer_notes'] ?? '';

    // Pastikan nomor diawali 62
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (!preg_match('/^62/', $phone)) {
        $phone = '62' . $phone;
    }

    if ($is_edit) {
        $stmt = $pdo->prepare("UPDATE customers SET name=?, address=?, phone=?, email=?, customer_notes=? WHERE id=?");
        $stmt->execute([$name, $address, $phone, $email, $customer_notes, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO customers (name, address, phone, email, customer_notes) VALUES (?,?,?,?,?)");
        $stmt->execute([$name, $address, $phone, $email, $customer_notes]);
    }

    header('Location: customers.php?msg=updated');
    exit;
}

// Untuk menampilkan nomor tanpa 62 di form edit
$display_phone = '';
if ($is_edit && $customer['phone']) {
    $display_phone = preg_replace('/^62/', '', $customer['phone']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_edit ? 'Edit' : 'Tambah' ?> Pelanggan - Creative Geek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #0a0a0a; color: white; }
        .card-dark { background: #151515; border: 1px solid #2a2a2a; border-radius: 12px; }
        .text-gold { color: #F5A623; }
        .bg-gold { background: #F5A623; }
        .hover-gold:hover { background: #e09515; }
        input, select, textarea {
            background: #0a0a0a;
            border: 1px solid #2a2a2a;
            color: #ffffff !important;
            padding: 8px 12px;
            border-radius: 8px;
            width: 100%;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #F5A623;
        }
        input::placeholder, textarea::placeholder {
            color: #666;
        }
        .prepend-phone {
            display: flex;
            align-items: center;
        }
        .prepend-phone span {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-right: none;
            color: #aaa;
            padding: 8px 12px;
            border-radius: 8px 0 0 8px;
        }
        .prepend-phone input {
            border-radius: 0 8px 8px 0;
            flex: 1;
        }
    </style>
</head>
<body>
    <nav class="bg-[#151515] border-b border-[#222] p-4 flex justify-between items-center">
        <span class="font-bold text-xl text-gold"> CREATIVE GEEK</span>
        <div>
            <a href="dashboard.php" class="mr-4 text-gold hover:underline">Dashboard</a>
            <a href="customers.php" class="mr-4 text-gold hover:underline">Pelanggan</a>
            <a href="logout.php" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">Logout</a>
        </div>
    </nav>

    <div class="container mx-auto p-6 max-w-3xl">
        <h1 class="text-2xl font-bold text-gold mb-6"><?= $is_edit ? '✏️ Edit' : '➕ Tambah' ?> Pelanggan</h1>

        <form method="POST" class="card-dark p-6">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300">Nama Pelanggan *</label>
                <input type="text" name="name" required value="<?= $is_edit ? htmlspecialchars($customer['name']) : '' ?>">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300">Alamat</label>
                <textarea name="address" rows="2"><?= $is_edit ? htmlspecialchars($customer['address']) : '' ?></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-300">Nomor Telp/WhatsApp</label>
                    <div class="prepend-phone">
                        <span>+62</span>
                        <input type="tel" name="phone" placeholder="81234567890" value="<?= $display_phone ?>">
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Cukup ketik angka setelah 62 (contoh: 81234567890)</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300">Email</label>
                    <input type="email" name="email" value="<?= $is_edit ? htmlspecialchars($customer['email']) : '' ?>">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300">Catatan Pelanggan</label>
                <input type="text" name="customer_notes" placeholder="Misal: VIP, pelanggan tetap, dll" value="<?= $is_edit ? htmlspecialchars($customer['customer_notes']) : '' ?>">
            </div>

            <button type="submit" class="bg-gold text-black px-6 py-2 rounded font-bold hover:bg-[#e09515] transition">Simpan Pelanggan</button>
            <a href="customers.php" class="text-gray-400 ml-3">Batal</a>
        </form>
    </div>
</body>
</html>
