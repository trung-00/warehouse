<?php
/**
 * Helper functions cho phân hệ Lập phiếu xuất kho
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/init_export_db.php';

// Tự động kiểm tra tạo bảng nếu cần
checkAndInitExportTables();

/**
 * Đề xuất danh sách lô hàng theo FEFO (Hạn sử dụng gần nhất) và FIFO (Ngày nhập cũ nhất)
 * @param string $maKho Mã kho xuất
 * @param string $maHangHoa Mã hàng hóa
 * @param int $soLuongYeuCau Số lượng cần xuất
 * @return array
 */
function getSuggestedBatchesFEFO($maKho, $maHangHoa, $soLuongYeuCau) {
    $db = getDB();
    // Ưu tiên FEFO (HanSuDung ASC), sau đó FIFO (NgayNhap ASC)
    $stmt = $db->prepare("
        SELECT 
            l.MaLo,
            l.MaHangHoa,
            h.TenHangHoa,
            h.DonViTinh,
            l.NgayNhap,
            l.HanSuDung,
            l.SoLuongTon,
            l.MaKho,
            k.TenKho,
            COALESCE(ke.TenKe, 'Chưa xếp kệ') AS KeLuuTru
        FROM LOHANG l
        JOIN HANGHOA h ON l.MaHangHoa = h.MaHangHoa
        JOIN KHO k ON l.MaKho = k.MaKho
        LEFT JOIN KELUUTRU ke ON l.MaKe = ke.MaKe
        WHERE l.MaKho = ? AND l.MaHangHoa = ? AND l.SoLuongTon > 0
        ORDER BY l.HanSuDung ASC, l.NgayNhap ASC
    ");
    $stmt->execute([$maKho, $maHangHoa]);
    $batches = $stmt->fetchAll();

    $remainingNeeded = $soLuongYeuCau;
    $suggested = [];
    $totalAvailable = 0;

    foreach ($batches as $batch) {
        $totalAvailable += (int)$batch['SoLuongTon'];
        $suggestQty = 0;
        if ($remainingNeeded > 0) {
            $take = min((int)$batch['SoLuongTon'], $remainingNeeded);
            $suggestQty = $take;
            $remainingNeeded -= $take;
        }

        $batch['SoLuongDuKienXuat'] = $suggestQty;
        $suggested[] = $batch;
    }

    return [
        'batches' => $suggested,
        'totalAvailable' => $totalAvailable,
        'isEnough' => ($totalAvailable >= $soLuongYeuCau),
        'remainingShortage' => max(0, $soLuongYeuCau - $totalAvailable)
    ];
}

/**
 * Tự phát sinh mã phiếu xuất kho dạng chuỗi: PXK001, PXK002...
 */
function generateMaPhieuXuatKho() {
    $db = getDB();
    $stmt = $db->query("SELECT MaPhieu FROM PHIEUXUATKHO WHERE MaPhieu LIKE 'PXK%' ORDER BY MaPhieu DESC LIMIT 1");
    $lastId = $stmt->fetchColumn();
    $nextNum = 1;
    if ($lastId && preg_match('/PXK(\d+)/', $lastId, $matches)) {
        $nextNum = (int)$matches[1] + 1;
    }
    return 'PXK' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

