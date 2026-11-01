<?php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost:8080/warehouse/auth/login.php");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'username' => 'quanly',
    'password' => 'password123'
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, __DIR__ . '/test_cookie2.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, __DIR__ . '/test_cookie2.txt');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);

// 1. Kiểm tra Trang 1
curl_setopt($ch, CURLOPT_URL, "http://localhost:8080/warehouse/pages/inventory/explain.php");
curl_setopt($ch, CURLOPT_POST, 0);
$listHtml = curl_exec($ch);

echo "--- KIỂM TRA TRANG 1 ---\n";
for ($i = 1; $i <= 11; $i++) {
    $code = sprintf('BBKK-2023-10-26-%03d', $i);
    if (strpos($listHtml, $code) !== false) {
        echo "[PASS] Tìm thấy $code\n";
    } else {
        echo "[FAIL] Không tìm thấy $code\n";
    }
}
if (strpos($listHtml, '26/10/2023') !== false) {
    echo "[PASS] Tìm thấy ngày 26/10/2023\n";
} else {
    echo "[FAIL] Không tìm thấy ngày 26/10/2023\n";
}
if (strpos($listHtml, 'Kho Thành Phẩm 1') !== false) {
    echo "[PASS] Tìm thấy Kho Thành Phẩm 1\n";
} else {
    echo "[FAIL] Không tìm thấy Kho Thành Phẩm 1\n";
}

// 2. Kiểm tra Trang 2
curl_setopt($ch, CURLOPT_URL, "http://localhost:8080/warehouse/pages/inventory/explain.php?id=BBKK-2023-10-26-001");
$detailHtml = curl_exec($ch);

echo "\n--- KIỂM TRA TRANG 2 (BBKK-2023-10-26-001) ---\n";
$items = ['H-001', 'H-002', 'H-003', 'H-004', 'H-005', 'H-007', 'H-008', 'H-009'];
foreach ($items as $item) {
    if (strpos($detailHtml, $item) !== false) {
        echo "[PASS] Tìm thấy $item\n";
    } else {
        echo "[FAIL] Không tìm thấy $item\n";
    }
}
if (strpos($detailHtml, 'H-006') === false) {
    echo "[PASS] Đúng: Không có H-006\n";
} else {
    echo "[FAIL] Sai: Có H-006\n";
}
if (strpos($detailHtml, 'Nước Aquafina 500ml') !== false) {
    echo "[PASS] Tìm thấy tên hàng Nước Aquafina 500ml\n";
}
if (strpos($detailHtml, 'L01') !== false) {
    echo "[PASS] Tìm thấy số lô L01\n";
}
if (strpos($detailHtml, '-20') !== false) {
    echo "[PASS] Tìm thấy chênh lệch -20\n";
}

// Kiểm tra 3 nguyên nhân
echo "\n--- KIỂM TRA ĐÚNG 3 NGUYÊN NHÂN ---\n";
$hasHu = strpos($detailHtml, 'Hư hàng') !== false;
$hasHong = strpos($detailHtml, 'Hỏng hàng') !== false;
$hasThieu = strpos($detailHtml, 'Nhập thiếu hàng') !== false;
echo "Hư hàng: " . ($hasHu ? '[PASS]' : '[FAIL]') . "\n";
echo "Hỏng hàng: " . ($hasHong ? '[PASS]' : '[FAIL]') . "\n";
echo "Nhập thiếu hàng: " . ($hasThieu ? '[PASS]' : '[FAIL]') . "\n";

curl_close($ch);

