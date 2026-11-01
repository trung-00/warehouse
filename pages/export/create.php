<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/export_helper.php';
requireLogin();

if (!isStaff()) {
    header('Location: /warehouse/pages/dashboard.php');
    exit;
}

$user = getCurrentUser();
$initials = mb_strtoupper(mb_substr($user['name'], 0, 1));
$maKhoNhanVien = $user['warehouse_id'] ?? 'KTP01';

$db = getDB();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$maPhieuYeuCau = trim($_GET['id'] ?? '');

if (empty($maPhieuYeuCau)) {
    header('Location: /warehouse/pages/export/index.php');
    exit;
}

// Lấy thông tin phiếu yêu cầu
$stmtYc = $db->prepare("
    SELECT p.*, k.TenKho, px.MaPhieu AS DaCoPhieuXuat
    FROM PHIEUYEUCAUKHO p
    JOIN KHO k ON p.MaKho = k.MaKho
    LEFT JOIN PHIEUXUATKHO px ON p.MaPhieuYeuCau = px.MaPhieuYeuCau
    WHERE p.MaPhieuYeuCau = ? AND p.MaKho = ?
");
$stmtYc->execute([$maPhieuYeuCau, $maKhoNhanVien]);
$yeuCau = $stmtYc->fetch();

if (!$yeuCau) {
    $_SESSION['flash_error'] = 'Không tìm thấy phiếu yêu cầu xuất kho hoặc bạn không có quyền truy cập kho này.';
    header('Location: /warehouse/pages/export/index.php');
    exit;
}

// BẢO VỆ CHẶT CHẼ: Nhân viên kho CHỈ được phép lập phiếu xuất kho đối với yêu cầu có trạng thái "Đã duyệt"
// Yêu cầu "Từ chối" hoặc "Chờ duyệt" hoặc đã lập phiếu tuyệt đối không được phép
if ($yeuCau['TrangThaiYeuCau'] === 'Từ chối') {
    $_SESSION['flash_error'] = 'Yêu cầu xuất kho này đã bị TỪ CHỐI, tuyệt đối không được phép lập phiếu xuất kho!';
    header('Location: /warehouse/pages/export/index.php');
    exit;
}

if ($yeuCau['TrangThaiYeuCau'] !== 'Đã duyệt') {
    $_SESSION['flash_error'] = 'Yêu cầu xuất kho đang ở trạng thái "' . $yeuCau['TrangThaiYeuCau'] . '". Chỉ các yêu cầu có trạng thái "Đã duyệt" mới được phép lập phiếu xuất kho!';
    header('Location: /warehouse/pages/export/index.php');
    exit;
}

if (!empty($yeuCau['DaCoPhieuXuat'])) {
    $_SESSION['flash_error'] = 'Yêu cầu xuất kho này đã được lập phiếu xuất (' . $yeuCau['DaCoPhieuXuat'] . ') trước đó.';
    header('Location: /warehouse/pages/export/index.php');
    exit;
}

// Lấy chi tiết hàng hóa yêu cầu xuất
$stmtCt = $db->prepare("
    SELECT 
        ct.MaPhieuYeuCau,
        ct.MaHangHoa,
        ct.SoLuongYeuCau,
        h.TenHangHoa,
        h.DonViTinh,
        h.LoaiHang,
        COALESCE(tk.SoLuongTon, 0) AS SoLuongTonKho
    FROM CHITIETPHIEUYEUCAUKHO ct
    JOIN HANGHOA h ON ct.MaHangHoa = h.MaHangHoa
    LEFT JOIN TONKHO tk ON (tk.MaKho = ? AND tk.MaHangHoa = ct.MaHangHoa)
    WHERE ct.MaPhieuYeuCau = ?
");
$stmtCt->execute([$maKhoNhanVien, $maPhieuYeuCau]);
$chiTietList = $stmtCt->fetchAll();

// Tính toán các lô hàng đề xuất theo nguyên tắc FEFO/FIFO cho từng mặt hàng
$suggestionsByItem = [];
$canExportAll = true;

foreach ($chiTietList as $item) {
    $res = getSuggestedBatchesFEFO($maKhoNhanVien, $item['MaHangHoa'], (int)$item['SoLuongYeuCau']);
    $suggestionsByItem[$item['MaHangHoa']] = $res;
    if (!$res['isEnough']) {
        $canExportAll = false;
    }
}

// Xử lý POST Xác nhận Lập phiếu xuất kho
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'confirm_export') {
        // Kiểm tra chặt chẽ: chỉ trạng thái "Đã duyệt" và chưa lập phiếu mới được phép
        if ($yeuCau['TrangThaiYeuCau'] === 'Từ chối') {
            $_SESSION['flash_error'] = 'Yêu cầu xuất kho này đã bị TỪ CHỐI, tuyệt đối không được phép lập phiếu xuất kho!';
            header('Location: /warehouse/pages/export/index.php');
            exit;
        }

        if ($yeuCau['TrangThaiYeuCau'] !== 'Đã duyệt') {
            $_SESSION['flash_error'] = 'Chỉ các yêu cầu xuất kho có trạng thái "Đã duyệt" mới được phép lập phiếu!';
            header('Location: /warehouse/pages/export/index.php');
            exit;
        }

        if (!empty($yeuCau['DaCoPhieuXuat'])) {
            $_SESSION['flash_error'] = 'Yêu cầu xuất kho này đã được lập phiếu xuất trước đó.';
            header('Location: /warehouse/pages/export/index.php');
            exit;
        }

        // BẮT ĐẦU TRANSACTION ĐỂ ĐỒNG BỘ TUYỆT ĐỐI
        try {
            $db->beginTransaction();

            // Kiểm tra lại tồn kho thời gian thực
            $isStillEnough = true;
            $itemsAllocation = []; // Lưu các lô và số lượng xuất thực tế

            foreach ($chiTietList as $item) {
                $maHangHoa = $item['MaHangHoa'];
                $soLuongYeuCau = (int)$item['SoLuongYeuCau'];

                // Khóa các dòng LOHANG để tránh tranh chấp (SELECT FOR UPDATE)
                $stmtLock = $db->prepare("
                    SELECT MaLo, SoLuongTon, HanSuDung, NgayNhap 
                    FROM LOHANG 
                    WHERE MaKho = ? AND MaHangHoa = ? AND SoLuongTon > 0 
                    ORDER BY HanSuDung ASC, NgayNhap ASC
                    FOR UPDATE
                ");
                $stmtLock->execute([$maKhoNhanVien, $maHangHoa]);
                $freshBatches = $stmtLock->fetchAll();

                $totalStock = 0;
                $rem = $soLuongYeuCau;
                $batchAllocations = [];

                foreach ($freshBatches as $fb) {
                    $totalStock += (int)$fb['SoLuongTon'];
                    if ($rem > 0) {
                        $take = min((int)$fb['SoLuongTon'], $rem);
                        $batchAllocations[] = [
                            'MaLo' => $fb['MaLo'],
                            'SoLuongXuat' => $take,
                            'TonCu' => (int)$fb['SoLuongTon']
                        ];
                        $rem -= $take;
                    }
                }

                if ($totalStock < $soLuongYeuCau || $rem > 0) {
                    $isStillEnough = false;
                    break;
                }

                $itemsAllocation[$maHangHoa] = $batchAllocations;
            }

            // Nếu không đủ số lượng hàng hóa để xuất kho:
            if (!$isStillEnough) {
                $db->rollBack();
                $_SESSION['flash_error'] = 'Không đủ số lượng hàng hóa để xuất kho.';
                header('Location: /warehouse/pages/export/create.php?id=' . urlencode($maPhieuYeuCau));
                exit;
            }

            // ĐỦ SỐ LƯỢNG:
            // 1. Tạo phiếu xuất kho mới (Mã phiếu tự phát sinh)
            $maPhieuMoi = generateMaPhieuXuatKho();
            $ngayLapHienTai = date('Y-m-d H:i:s');
            $ngayXuatHienTai = date('Y-m-d');
            $nguoiLap = $user['name'] . ' (' . $user['id'] . ')';
            $loaiPhieu = 'Xuất kho bán hàng';
            $trangThaiPhieu = 'Đã xuất';

            // 2. Lưu thông tin phiếu xuất kho
            $stmtInsertPX = $db->prepare("
                INSERT INTO PHIEUXUATKHO (
                    MaPhieu, LoaiPhieu, NgayLap, TrangThaiPhieu, NguoiLap, MaKho, NgayXuat, MaPhieuYeuCau, GhiChu
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ghiChu = "Xuất kho tự động theo đề xuất FEFO/FIFO cho yêu cầu " . $maPhieuYeuCau;
            $stmtInsertPX->execute([
                $maPhieuMoi, $loaiPhieu, $ngayLapHienTai, $trangThaiPhieu, $nguoiLap, $maKhoNhanVien, $ngayXuatHienTai, $maPhieuYeuCau, $ghiChu
            ]);

            // 3, 4, 5: Lưu chi tiết phiếu xuất kho, cập nhật số lượng tồn từng lô và tồn kho tổng
            $stmtInsertCTPX = $db->prepare("
                INSERT INTO CHITIETPHIEUXUATKHO (MaPhieu, MaHangHoa, MaLo, SoLuongXuat)
                VALUES (?, ?, ?, ?)
            ");

            $stmtUpdateLoHang = $db->prepare("
                UPDATE LOHANG 
                SET SoLuongTon = SoLuongTon - ? 
                WHERE MaLo = ? AND MaKho = ?
            ");

            $stmtUpdateTonKho = $db->prepare("
                UPDATE TONKHO 
                SET SoLuongTon = SoLuongTon - ? 
                WHERE MaKho = ? AND MaHangHoa = ?
            ");

            foreach ($itemsAllocation as $maHangHoa => $allocs) {
                $totalXuatHangHoa = 0;
                foreach ($allocs as $al) {
                    $stmtInsertCTPX->execute([$maPhieuMoi, $maHangHoa, $al['MaLo'], $al['SoLuongXuat']]);
                    $stmtUpdateLoHang->execute([$al['SoLuongXuat'], $al['MaLo'], $maKhoNhanVien]);
                    $totalXuatHangHoa += $al['SoLuongXuat'];
                }
                $stmtUpdateTonKho->execute([$totalXuatHangHoa, $maKhoNhanVien, $maHangHoa]);
            }

            // 6. Cập nhật trạng thái yêu cầu xuất kho thành "Đã duyệt"
            $stmtUpdateYc = $db->prepare("
                UPDATE PHIEUYEUCAUKHO 
                SET TrangThaiYeuCau = 'Đã duyệt' 
                WHERE MaPhieuYeuCau = ?
            ");
            $stmtUpdateYc->execute([$maPhieuYeuCau]);

            $db->commit();

            // 7 & 8: Thông báo thành công và hiển thị mã phiếu xuất kho vừa tạo
            $_SESSION['flash_success'] = "Lập phiếu xuất kho thành công! Mã phiếu xuất kho vừa được tạo: <strong>{$maPhieuMoi}</strong>";
            header('Location: /warehouse/pages/export/index.php');
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['flash_error'] = 'Lỗi hệ thống khi lưu phiếu xuất kho: ' . $e->getMessage();
            header('Location: /warehouse/pages/export/create.php?id=' . urlencode($maPhieuYeuCau));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Chi tiết yêu cầu & Lập phiếu xuất kho — Quản Lý Kho</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/warehouse/assets/css/dashboard.css">
</head>
<body>

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside id="sidebar">
  <div class="sidebar-brand">
    <img src="/warehouse/assets/images/logo-icon.svg" alt="Logo" class="w-8 h-8 flex-shrink-0" />
    <div class="min-w-0">
      <div class="sidebar-title">Quản Lý Kho</div>
      <div class="sidebar-subtitle">Hệ thống chuỗi kho</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Tổng quan</div>
    <a href="/warehouse/pages/dashboard.php" class="nav-item">
      <i class="fa-solid fa-house"></i>
      <span>Trang chủ</span>
    </a>

    <div class="nav-section">Nghiệp vụ xuất kho</div>
    <a href="/warehouse/pages/export/index.php" class="nav-item active">
      <i class="fa-solid fa-file-invoice"></i>
      <span>Lập phiếu xuất kho</span>
    </a>

    <div class="nav-section">Sự cố kho</div>
    <a href="/warehouse/pages/incidents/report.php" class="nav-item">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <span>Báo cáo sự cố</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <a href="/warehouse/auth/logout.php" class="nav-item sidebar-logout-btn">
      <i class="fa-solid fa-arrow-right-from-bracket"></i>
      <span>Đăng xuất</span>
    </a>
  </div>
</aside>

<div id="overlay" onclick="closeSidebar()"></div>

<!-- ═══════════════ MAIN WRAPPER ═══════════════ -->
<div id="main-wrap">

  <!-- Topbar -->
  <header id="topbar">
    <button onclick="toggleSidebar()" class="lg:hidden p-1.5 rounded hover:bg-gray-100 text-gray-700 mr-1">
      <i class="fa-solid fa-bars text-base"></i>
    </button>

    <div class="flex items-center gap-1.5 text-sm flex-1 min-w-0">
      <a href="/warehouse/pages/dashboard.php" class="text-gray-400 hover:text-red-700">
        <i class="fa-solid fa-house text-xs"></i>
      </a>
      <span class="text-gray-400">/</span>
      <a href="/warehouse/pages/export/index.php" class="text-gray-400 hover:text-red-700">
        Yêu cầu xuất kho
      </a>
      <span class="text-gray-400">/</span>
      <span class="breadcrumb-label">Lập phiếu xuất</span>
    </div>

    <div class="flex items-center gap-3">
      <div class="hidden sm:flex badge-branch">
        <i class="fa-solid fa-location-dot text-xs" style="color: #B91C1C;"></i>
        <span><?= htmlspecialchars($user['warehouse_name']) ?></span>
      </div>

      <!-- User Dropdown -->
      <div class="relative" id="userDropWrap">
        <button id="userDropBtn" onclick="toggleDrop()" class="flex items-center gap-2 pl-1 pr-3 py-1 rounded border border-transparent hover:border-gray-300">
          <div class="kv-avatar w-7 h-7 text-xs flex-shrink-0"><?= $initials ?></div>
          <div class="hidden sm:block text-left leading-tight">
            <p class="text-xs font-bold text-gray-800 whitespace-nowrap"><?= htmlspecialchars($user['name']) ?></p>
          </div>
          <i class="fa-solid fa-chevron-down text-gray-500 hidden sm:block text-[10px]"></i>
        </button>

        <div id="userDrop">
          <div class="user-drop-header">
            <div class="flex items-center gap-2.5">
              <div class="kv-avatar w-9 h-9 text-sm flex-shrink-0"><?= $initials ?></div>
              <div class="min-w-0">
                <p class="text-sm font-bold text-gray-900 truncate"><?= htmlspecialchars($user['name']) ?></p>
                <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($user['username']) ?></p>
                <span class="<?= getRoleBadgeClass($user['role']) ?> inline-block mt-1">
                  <?= getRoleLabel($user['role']) ?>
                </span>
              </div>
            </div>
          </div>
          <div class="user-drop-body">
            <a href="/warehouse/auth/logout.php" class="user-drop-logout">
              <i class="fa-solid fa-arrow-right-from-bracket text-xs w-4 text-center"></i>
              <span>Đăng xuất</span>
            </a>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- Main Content -->
  <main class="dashboard-main dashboard-main-wide">

    <!-- Flash message -->
    <?php if ($flash_error): ?>
      <div class="alert-box alert-danger">
        <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-shrink-0"></i>
        <span><?= htmlspecialchars($flash_error) ?></span>
      </div>
    <?php endif; ?>

    <!-- Action Header -->
    <section class="action-box">
      <div>
        <h3 class="action-title">
          <i class="fa-solid fa-file-invoice" style="color: #B91C1C;"></i>
          Chi Tiết Yêu Cầu Xuất Kho & Kiểm Tra Tồn Kho
        </h3>
        <p class="action-desc">
          Thông tin chi tiết yêu cầu xuất kho và danh sách các lô hàng xuất kho.
        </p>
      </div>
      <div>
        <a href="/warehouse/pages/export/index.php" class="btn-secondary inline-flex items-center gap-1.5">
          <i class="fa-solid fa-arrow-left text-xs"></i>
          Quay lại danh sách
        </a>
      </div>
    </section>

    <!-- THÔNG TIN YÊU CẦU XUẤT KHO -->
    <section class="table-card mb-6">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa-solid fa-circle-info" style="color: #B91C1C;"></i>
          Thông Tin Phiếu Yêu Cầu Xuất Kho
        </div>
        <div>
          <?php if ($yeuCau['TrangThaiYeuCau'] === 'Chờ duyệt'): ?>
            <span class="status-badge status-processing">
              <i class="fa-solid fa-hourglass-half text-[10px] mr-1"></i>Trạng thái: Chờ duyệt
            </span>
          <?php elseif ($yeuCau['TrangThaiYeuCau'] === 'Đã duyệt'): ?>
            <span class="status-badge status-completed">
              <i class="fa-solid fa-circle-check text-[10px] mr-1"></i>Trạng thái: Đã duyệt
            </span>
          <?php else: ?>
            <span class="status-badge status-waiting" style="color: #991B1B; background: #FEE2E2; border-color: #B91C1C;">
              <i class="fa-solid fa-circle-xmark text-[10px] mr-1"></i>Trạng thái: Từ chối
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div class="p-5 grid grid-cols-1 md:grid-cols-4 gap-4 text-xs bg-white">
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Mã phiếu yêu cầu:</span>
          <span class="text-base font-bold text-gray-900"><?= htmlspecialchars($yeuCau['MaPhieuYeuCau']) ?></span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Ngày yêu cầu:</span>
          <span class="text-sm font-bold text-gray-800"><?= date('d/m/Y H:i', strtotime($yeuCau['NgayYeuCau'])) ?></span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Bộ phận gửi yêu cầu:</span>
          <span class="text-sm font-bold text-gray-800"><?= htmlspecialchars($yeuCau['BoPhanGui']) ?></span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Kho xuất:</span>
          <span class="text-sm font-bold text-red-700"><?= htmlspecialchars($yeuCau['TenKho']) ?> (<?= htmlspecialchars($yeuCau['MaKho']) ?>)</span>
        </div>
      </div>
    </section>

    <!-- DANH SÁCH HÀNG HÓA YÊU CẦU & ĐỀ XUẤT LÔ HÀNG FEFO/FIFO -->
    <?php foreach ($chiTietList as $idx => $item): 
      $sug = $suggestionsByItem[$item['MaHangHoa']] ?? null;
      $batches = $sug['batches'] ?? [];
      $isEnough = $sug['isEnough'] ?? false;
      $totalAvailable = $sug['totalAvailable'] ?? 0;
      $soLuongYeuCau = (int)$item['SoLuongYeuCau'];
    ?>
      <section class="table-card mb-6">
        <div class="table-card-header bg-gray-50">
          <div class="table-card-title text-sm">
            <span class="w-6 h-6 rounded-full bg-red-700 text-white flex items-center justify-center text-xs font-bold mr-1">
              <?= $idx + 1 ?>
            </span>
            Hàng hóa: <strong class="text-gray-900 ml-1"><?= htmlspecialchars($item['TenHangHoa']) ?></strong>
            <span class="text-gray-500 text-xs ml-2">(Mã: <?= htmlspecialchars($item['MaHangHoa']) ?> - ĐVT: <?= htmlspecialchars($item['DonViTinh']) ?>)</span>
          </div>

          <div class="flex items-center gap-3 text-xs">
            <div class="px-2.5 py-1 bg-white border border-gray-300 rounded">
              Số lượng yêu cầu: <strong class="text-red-700"><?= number_format($soLuongYeuCau) ?></strong> <?= htmlspecialchars($item['DonViTinh']) ?>
            </div>
            <div class="px-2.5 py-1 bg-white border border-gray-300 rounded">
              Tổng tồn kho khả dụng: <strong class="text-gray-900"><?= number_format($totalAvailable) ?></strong> <?= htmlspecialchars($item['DonViTinh']) ?>
            </div>
            <div>
              <?php if ($isEnough): ?>
                <span class="status-badge status-completed">
                  <i class="fa-solid fa-circle-check text-[10px] mr-1"></i>Đủ số lượng xuất
                </span>
              <?php else: ?>
                <span class="status-badge status-waiting" style="color: #991B1B; background: #FEE2E2; border-color: #B91C1C;">
                  <i class="fa-solid fa-triangle-exclamation text-[10px] mr-1"></i>Thiếu <?= number_format($soLuongYeuCau - $totalAvailable) ?> <?= htmlspecialchars($item['DonViTinh']) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="p-3 bg-gray-50 border-b border-gray-200 text-xs text-gray-700 flex items-center justify-between">
          <span class="font-bold text-gray-800">
            <i class="fa-solid fa-boxes-stacked text-red-700 mr-1.5"></i>
            Danh sách lô hàng xuất kho
          </span>
          <span class="text-gray-500 italic">Số lô có thể xuất: <?= count($batches) ?></span>
        </div>

        <div class="overflow-x-auto">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Ưu tiên</th>
                <th>Mã lô</th>
                <th>Mã hàng hóa</th>
                <th>Tên hàng hóa</th>
                <th>Ngày nhập kho</th>
                <th>Hạn sử dụng</th>
                <th>Kệ lưu trữ</th>
                <th>Tên kho</th>
                <th class="text-right">Số lượng tồn</th>
                <th class="text-right bg-amber-50 text-amber-900 font-bold" style="min-width: 150px;">Số lượng dự kiến xuất</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($batches)): ?>
                <tr>
                  <td colspan="10" class="text-center py-5 text-red-600 font-bold">
                    Không có lô hàng nào còn tồn trong kho cho mặt hàng này!
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($batches as $bIdx => $b): ?>
                  <tr class="<?= $b['SoLuongDuKienXuat'] > 0 ? 'bg-amber-50/40' : '' ?>">
                    <td class="text-center">
                      <span class="inline-block w-5 h-5 rounded-full text-[11px] font-bold <?= $b['SoLuongDuKienXuat'] > 0 ? 'bg-red-700 text-white' : 'bg-gray-200 text-gray-600' ?>">
                        <?= $bIdx + 1 ?>
                      </span>
                    </td>
                    <td class="font-bold text-gray-900"><?= htmlspecialchars($b['MaLo']) ?></td>
                    <td class="text-gray-600"><?= htmlspecialchars($b['MaHangHoa']) ?></td>
                    <td class="font-medium text-gray-800"><?= htmlspecialchars($b['TenHangHoa']) ?></td>
                    <td class="text-gray-700 whitespace-nowrap"><?= date('d/m/Y H:i', strtotime($b['NgayNhap'])) ?></td>
                    <td class="whitespace-nowrap font-bold text-gray-900">
                      <?= date('d/m/Y', strtotime($b['HanSuDung'])) ?>
                      <?php 
                        $daysLeft = (strtotime($b['HanSuDung']) - time()) / (60*60*24);
                        if ($daysLeft <= 90): 
                      ?>
                        <span class="text-[10px] text-amber-700 font-normal ml-1">(HSD gần)</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-gray-800 font-medium"><?= htmlspecialchars($b['KeLuuTru']) ?></td>
                    <td class="text-gray-700"><?= htmlspecialchars($b['TenKho']) ?></td>
                    <td class="text-right font-bold text-gray-800">
                      <?= number_format($b['SoLuongTon']) ?>
                    </td>
                    <td class="text-right font-bold text-red-700 bg-amber-50">
                      <?php if ($b['SoLuongDuKienXuat'] > 0): ?>
                        <span class="inline-flex items-center gap-1 text-red-700 font-bold text-sm">
                          <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
                          <?= number_format($b['SoLuongDuKienXuat']) ?> <?= htmlspecialchars($b['DonViTinh']) ?>
                        </span>
                      <?php else: ?>
                        <span class="text-gray-400 font-normal">0</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>

    <!-- KHUNG THÔNG TIN PHIẾU XUẤT DỰ KIẾN & THAO TÁC XÁC NHẬN / HỦY -->
    <section class="table-card p-6 bg-white border border-gray-200 mb-8">
      <div class="flex items-center justify-between border-b pb-4 mb-4">
        <div>
          <h4 class="text-base font-bold text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-clipboard-check text-red-700"></i>
            Thông Tin Phiếu Xuất Kho Dự Kiến Phát Sinh
          </h4>
          <p class="text-xs text-gray-500 mt-0.5">Các thông tin sẽ tự động được hệ thống ghi nhận khi xác nhận lập phiếu.</p>
        </div>
        <div>
          <?php if ($canExportAll): ?>
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-green-100 text-green-800 rounded font-bold text-xs border border-green-300">
              <i class="fa-solid fa-check"></i> Đủ điều kiện lập phiếu xuất kho
            </span>
          <?php else: ?>
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-red-100 text-red-800 rounded font-bold text-xs border border-red-300">
              <i class="fa-solid fa-xmark"></i> Không đủ tồn kho để xuất
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-xs mb-6">
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Mã phiếu xuất kho:</span>
          <span class="text-sm font-bold text-red-700"><?= generateMaPhieuXuatKho() ?></span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Ngày lập & Ngày xuất:</span>
          <span class="text-sm font-bold text-gray-900"><?= date('d/m/Y H:i') ?></span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Người lập phiếu:</span>
          <span class="text-sm font-bold text-gray-900"><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['id']) ?>)</span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Kho xuất hàng:</span>
          <span class="text-sm font-bold text-gray-900"><?= htmlspecialchars($yeuCau['TenKho']) ?> (<?= htmlspecialchars($yeuCau['MaKho']) ?>)</span>
        </div>
      </div>

      <!-- CÁC NÚT THAO TÁC: XÁC NHẬN / HỦY -->
      <div class="flex items-center justify-end gap-3 pt-3 border-t">
        <!-- Nút Hủy (Mở Modal xác nhận hủy) -->
        <button type="button" onclick="openCancelModal()" class="btn-secondary">
          <i class="fa-solid fa-xmark mr-1"></i>
          Hủy
        </button>

        <!-- Nút Xác nhận lập phiếu xuất kho -->
        <?php if ($canExportAll && $yeuCau['TrangThaiYeuCau'] === 'Đã duyệt' && empty($yeuCau['DaCoPhieuXuat'])): ?>
          <button type="button" onclick="openConfirmExportModal()" class="btn-primary">
            <i class="fa-solid fa-check text-xs mr-1"></i>
            Lập phiếu xuất kho
          </button>
        <?php elseif (!empty($yeuCau['DaCoPhieuXuat'])): ?>
          <button type="button" class="btn-secondary opacity-70 cursor-not-allowed">
            <i class="fa-solid fa-check-double text-xs mr-1"></i>
            Đã lập phiếu: <?= htmlspecialchars($yeuCau['DaCoPhieuXuat']) ?>
          </button>
        <?php else: ?>
          <button type="button" class="btn-primary opacity-50 cursor-not-allowed" title="Chỉ yêu cầu có trạng thái Đã duyệt và đủ tồn kho mới được phép lập phiếu" onclick="alert('Không đủ điều kiện lập phiếu xuất kho!');">
            <i class="fa-solid fa-ban text-xs mr-1"></i>
            Không đủ điều kiện lập phiếu
          </button>
        <?php endif; ?>
      </div>
    </section>

  </main>

  <footer class="dashboard-footer">
    © 2026 Hệ Thống Quản Lý Chuỗi Kho &nbsp;·&nbsp;
    <a href="#">Hỗ trợ kỹ thuật</a>
  </footer>
</div>

<!-- ═══════════════ MODAL XÁC NHẬN LẬP PHIẾU XUẤT KHO ═══════════════ -->
<div id="modalConfirmExport" class="modal-overlay">
  <div class="modal-box" style="max-width: 500px;">
    <div class="modal-box-header">
      <div class="modal-box-title text-red-700">
        <i class="fa-solid fa-clipboard-question"></i>
        Xác Nhận Lập Phiếu Xuất Kho
      </div>
      <button type="button" class="close-btn" onclick="closeConfirmExportModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="modal-box-body space-y-3 text-xs text-gray-700">
      <p class="text-sm font-bold text-gray-900">
        Bạn có chắc chắn muốn lập phiếu xuất kho cho yêu cầu này không?
      </p>

      <div class="p-3 bg-gray-50 border border-gray-200 rounded space-y-1">
        <div><strong class="text-gray-600">Mã yêu cầu:</strong> <span class="font-bold text-gray-900"><?= htmlspecialchars($maPhieuYeuCau) ?></span></div>
        <div><strong class="text-gray-600">Bộ phận gửi:</strong> <?= htmlspecialchars($yeuCau['BoPhanGui']) ?></div>
        <div><strong class="text-gray-600">Kho xuất:</strong> <?= htmlspecialchars($yeuCau['TenKho']) ?></div>
      </div>

      <div class="p-3 bg-red-50 border border-red-200 rounded text-red-800 space-y-1">
        <div>• <strong>Đồng ý / Xác nhận:</strong> Hệ thống thực hiện tạo phiếu, trừ tồn kho và cập nhật trạng thái.</div>
        <div>• <strong>Hủy:</strong> Đóng hộp thoại, giữ nguyên trạng thái và không làm thay đổi dữ liệu trong database.</div>
      </div>
    </div>

    <div class="modal-box-footer">
      <button type="button" class="btn-secondary" onclick="closeConfirmExportModal()">
        Hủy
      </button>
      <form method="POST" action="/warehouse/pages/export/create.php?id=<?= urlencode($maPhieuYeuCau) ?>" class="inline-block m-0">
        <input type="hidden" name="action" value="confirm_export">
        <button type="submit" class="btn-primary">
          <i class="fa-solid fa-check text-xs mr-1"></i>
          Đồng ý / Xác nhận
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ═══════════════ MODAL XÁC NHẬN HỦY ═══════════════ -->
<div id="modalCancel" class="modal-overlay">
  <div class="modal-box" style="max-width: 480px;">
    <div class="modal-box-header">
      <div class="modal-box-title text-red-700">
        <i class="fa-solid fa-circle-question"></i>
        Xác Nhận Hủy Lập Phiếu
      </div>
      <button type="button" class="close-btn" onclick="closeCancelModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="modal-box-body space-y-3 text-xs text-gray-700">
      <p>Bạn có chắc chắn muốn hủy bỏ thao tác lập phiếu xuất kho cho yêu cầu <strong><?= htmlspecialchars($maPhieuYeuCau) ?></strong>?</p>
      <div class="p-3 bg-amber-50 border border-amber-200 rounded text-amber-900 space-y-1">
        <div>• Hệ thống sẽ hủy thao tác lập phiếu.</div>
        <div>• Không lưu phiếu xuất kho.</div>
        <div>• Không thay đổi số lượng tồn kho.</div>
      </div>
    </div>

    <div class="modal-box-footer">
      <button type="button" class="btn-secondary" onclick="closeCancelModal()">Đóng</button>
      <a href="/warehouse/pages/export/index.php" class="btn-primary" style="background: #1F2937; border-color: #1F2937;">
        <i class="fa-solid fa-check text-xs mr-1"></i>
        Xác nhận hủy
      </a>
    </div>
  </div>
</div>

<script>
  const drop = document.getElementById('userDrop');
  function toggleDrop() {
    drop.style.display = drop.style.display === 'none' || drop.style.display === '' ? 'block' : 'none';
  }
  document.addEventListener('click', (e) => {
    if (!document.getElementById('userDropWrap').contains(e.target)) {
      drop.style.display = 'none';
    }
  });
  function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('overlay');
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
  }
  function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').style.display = 'none';
  }

  function openConfirmExportModal() {
    document.getElementById('modalConfirmExport').classList.add('active');
  }
  function closeConfirmExportModal() {
    document.getElementById('modalConfirmExport').classList.remove('active');
  }

  function openCancelModal() {
    document.getElementById('modalCancel').classList.add('active');
  }
  function closeCancelModal() {
    document.getElementById('modalCancel').classList.remove('active');
  }
</script>
</body>
</html>

