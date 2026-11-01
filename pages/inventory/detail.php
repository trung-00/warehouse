<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// Phân quyền: Kiểm tra quyền hạn tài khoản (phải thuộc vai trò Ban Giám Đốc)
if (!isDirector()) {
    header('Location: /warehouse/pages/dashboard.php');
    exit;
}

$user = getCurrentUser();
$initials = mb_strtoupper(mb_substr($user['name'], 0, 1));
$db = getDB();

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$maBienBan = trim($_GET['id'] ?? '');

if (empty($maBienBan)) {
    header('Location: /warehouse/pages/inventory/index.php');
    exit;
}

// Lấy thông tin chung biên bản
$stmt = $db->prepare("
    SELECT b.*, w.name AS TenKho
    FROM BIENBAN_KIEMKE b
    LEFT JOIN warehouses w ON b.MaKho = w.id
    WHERE b.MaBienBanKiemKe = ?
");
$stmt->execute([$maBienBan]);
$bienBan = $stmt->fetch();

if (!$bienBan) {
    $_SESSION['flash_error'] = 'Không tìm thấy biên bản kiểm kê yêu cầu.';
    header('Location: /warehouse/pages/inventory/index.php');
    exit;
}

// -------------------------------------------------------------
// XỬ LÝ POST: PHÊ DUYỆT HOẶC TỪ CHỐI
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. PHÊ DUYỆT THÀNH CÔNG
    if ($action === 'approve_audit') {
        if ($bienBan['TrangThai'] !== 'Chờ phê duyệt') {
            $_SESSION['flash_error'] = 'Biên bản này không ở trạng thái "Chờ phê duyệt".';
            header('Location: /warehouse/pages/inventory/detail.php?id=' . urlencode($maBienBan));
            exit;
        }

        $yKienChiDao = trim($_POST['yKienChiDao'] ?? '');
        $nguoiDuyet = $user['name'] . ' (' . $user['id'] . ')';
        $thoiGianDuyet = date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();

            // Lấy danh sách hàng hóa trong chi tiết biên bản để cập nhật tồn kho
            $stmtDetails = $db->prepare("SELECT * FROM CHITIET_BIENBAN_KIEMKE WHERE MaBienBanKiemKe = ?");
            $stmtDetails->execute([$maBienBan]);
            $items = $stmtDetails->fetchAll();

            $maKho = $bienBan['MaKho'];

            // Cập nhật lại số lượng tồn kho chính thức trong bảng Tồn kho (TONKHO) theo số liệu Thực tế
            // Đồng thời cập nhật số lượng tồn của Lô hàng (LOHANG)
            $stmtUpdateTonKho = $db->prepare("
                UPDATE TONKHO 
                SET SoLuongTon = ? 
                WHERE MaKho = ? AND MaHangHoa = ?
            ");

            $stmtUpdateLoHang = $db->prepare("
                UPDATE LOHANG 
                SET SoLuongTon = ? 
                WHERE MaLo = ? AND MaKho = ? AND MaHangHoa = ?
            ");

            foreach ($items as $it) {
                // Cập nhật tồn lô hàng tương ứng theo số thực tế
                $stmtUpdateLoHang->execute([$it['ThucTe'], $it['TenLo'], $maKho, $it['MatHang']]);

                // Tính lại tổng tồn kho của mặt hàng này tại kho từ tất cả các lô
                $stmtSum = $db->prepare("SELECT COALESCE(SUM(SoLuongTon), 0) FROM LOHANG WHERE MaKho = ? AND MaHangHoa = ?");
                $stmtSum->execute([$maKho, $it['MatHang']]);
                $tongTonMoi = (int)$stmtSum->fetchColumn();

                $stmtUpdateTonKho->execute([$tongTonMoi, $maKho, $it['MatHang']]);
            }

            // Cập nhật trạng thái biên bản thành "Đã phê duyệt"
            $stmtApprove = $db->prepare("
                UPDATE BIENBAN_KIEMKE 
                SET TrangThai = 'Đã phê duyệt',
                    NguoiDuyet = ?,
                    ThoiGianDuyet = ?,
                    YKienChiDao = ?
                WHERE MaBienBanKiemKe = ?
            ");
            $stmtApprove->execute([$nguoiDuyet, $thoiGianDuyet, $yKienChiDao, $maBienBan]);

            // Gửi thông báo đến Bộ phận quản lý kho
            addNotification(
                'manager',
                null,
                $maBienBan,
                "Biên bản kiểm kê đã được duyệt [$maBienBan]",
                "Ban Giám Đốc ({$user['name']}) đã phê duyệt biên bản kiểm kê {$maBienBan}. Số liệu tồn kho đã được điều chỉnh theo thực tế. Ý kiến chỉ đạo: " . ($yKienChiDao ?: 'Không có')
            );

            $db->commit();
            $_SESSION['flash_success'] = "Phê duyệt chênh lệch kiểm kê thành công!";
            header('Location: /warehouse/pages/inventory/detail.php?id=' . urlencode($maBienBan));
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['flash_error'] = 'Lỗi hệ thống khi phê duyệt: ' . $e->getMessage();
            header('Location: /warehouse/pages/inventory/detail.php?id=' . urlencode($maBienBan));
            exit;
        }
    }

    // 2. TỪ CHỐI PHÊ DUYỆT
    if ($action === 'reject_audit') {
        if ($bienBan['TrangThai'] !== 'Chờ phê duyệt') {
            $_SESSION['flash_error'] = 'Biên bản này không ở trạng thái "Chờ phê duyệt".';
            header('Location: /warehouse/pages/inventory/detail.php?id=' . urlencode($maBienBan));
            exit;
        }

        $lyDoChon       = trim($_POST['lyDoTuChoi'] ?? '');
        $noiDungKhac    = trim($_POST['noiDungLyDoKhac'] ?? '');
        $yKienChiDao    = trim($_POST['yKienChiDaoReject'] ?? '');
        $nguoiDuyet     = $user['name'] . ' (' . $user['id'] . ')';
        $thoiGianDuyet  = date('Y-m-d H:i:s');

        // Bắt buộc chọn ít nhất 01 lý do từ chối (nếu chọn "Lý do khác" thì không được để trống ô nhập nội dung)
        if (empty($lyDoChon)) {
            $_SESSION['flash_error'] = 'Bắt buộc chọn ít nhất 01 lý do từ chối.';
            header('Location: /warehouse/pages/inventory/detail.php?id=' . urlencode($maBienBan));
            exit;
        }

        if ($lyDoChon === 'Lý do khác' && empty($noiDungKhac)) {
            $_SESSION['flash_error'] = 'Khi chọn "Lý do khác", vui lòng nhập nội dung chi tiết lý do từ chối.';
            header('Location: /warehouse/pages/inventory/detail.php?id=' . urlencode($maBienBan));
            exit;
        }

        try {
            // Giữ nguyên số liệu tồn kho cũ trên hệ thống
            // Cập nhật trạng thái biên bản thành "Từ chối"
            $stmtReject = $db->prepare("
                UPDATE BIENBAN_KIEMKE 
                SET TrangThai = 'Từ chối',
                    NguoiDuyet = ?,
                    ThoiGianDuyet = ?,
                    YKienChiDao = ?,
                    LyDoTuChoi = ?,
                    NoiDungLyDoKhac = ?
                WHERE MaBienBanKiemKe = ?
            ");
            $stmtReject->execute([$nguoiDuyet, $thoiGianDuyet, $yKienChiDao, $lyDoChon, $noiDungKhac, $maBienBan]);

            $lyDoHienThi = ($lyDoChon === 'Lý do khác') ? $noiDungKhac : $lyDoChon;

            // Gửi thông báo phản hồi cho Bộ phận kiểm kê / Quản lý kho
            addNotification(
                'manager',
                null,
                $maBienBan,
                "Biên bản kiểm kê bị Từ chối [$maBienBan]",
                "Ban Giám Đốc ({$user['name']}) đã TỪ CHỐI biên bản kiểm kê {$maBienBan}. Lý do: {$lyDoHienThi}. Giữ nguyên số liệu tồn kho hiện tại."
            );

            $_SESSION['flash_success'] = "Đã từ chối phê duyệt biên bản kiểm kê. Số liệu tồn kho cũ được giữ nguyên!";
            header('Location: /warehouse/pages/inventory/detail.php?id=' . urlencode($maBienBan));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Lỗi hệ thống khi từ chối: ' . $e->getMessage();
            header('Location: /warehouse/pages/inventory/detail.php?id=' . urlencode($maBienBan));
            exit;
        }
    }
}

// Lấy danh sách hàng hóa chênh lệch
$stmtItems = $db->prepare("
    SELECT * FROM CHITIET_BIENBAN_KIEMKE 
    WHERE MaBienBanKiemKe = ?
    ORDER BY id ASC
");
$stmtItems->execute([$maBienBan]);
$chiTietHangHoa = $stmtItems->fetchAll();

// Lấy nguyên nhân chênh lệch
$stmtNN = $db->prepare("
    SELECT * FROM NGUYENNHAN_CHENHLECH 
    WHERE MaBienBanKiemKe = ?
    ORDER BY MaNguyenNhan ASC
");
$stmtNN->execute([$maBienBan]);
$nguyenNhanList = $stmtNN->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Chi tiết biên bản kiểm kê — <?= htmlspecialchars($maBienBan) ?></title>
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

    <div class="nav-section">Kiểm kê & Phê duyệt</div>
    <a href="/warehouse/pages/inventory/index.php" class="nav-item active">
      <i class="fa-solid fa-clipboard-check"></i>
      <span>Phê duyệt kiểm kê</span>
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
      <a href="/warehouse/pages/inventory/index.php" class="text-gray-400 hover:text-red-700">
        Phê duyệt kiểm kê
      </a>
      <span class="text-gray-400">/</span>
      <span class="breadcrumb-label">Chi tiết biên bản</span>
    </div>

    <div class="flex items-center gap-3">
      <div class="hidden sm:flex badge-branch">
        <i class="fa-solid fa-crown text-xs" style="color: #4F46E5;"></i>
        <span>Ban Giám Đốc</span>
      </div>

      <!-- User Dropdown -->
      <div class="relative" id="userDropWrap">
        <button id="userDropBtn" onclick="toggleDrop()" class="flex items-center gap-2 pl-1 pr-3 py-1 rounded border border-transparent hover:border-gray-300">
          <div class="kv-avatar w-7 h-7 text-xs flex-shrink-0" style="background: #4F46E5;"><?= $initials ?></div>
          <div class="hidden sm:block text-left leading-tight">
            <p class="text-xs font-bold text-gray-800 whitespace-nowrap"><?= htmlspecialchars($user['name']) ?></p>
          </div>
          <i class="fa-solid fa-chevron-down text-gray-500 hidden sm:block text-[10px]"></i>
        </button>

        <div id="userDrop">
          <div class="user-drop-header">
            <div class="flex items-center gap-2.5">
              <div class="kv-avatar w-9 h-9 text-sm flex-shrink-0" style="background: #4F46E5;"><?= $initials ?></div>
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
    <?php if ($flash_success): ?>
      <div class="alert-box alert-success">
        <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0"></i>
        <span><?= htmlspecialchars($flash_success) ?></span>
      </div>
    <?php endif; ?>

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
          <i class="fa-solid fa-file-signature" style="color: #B91C1C;"></i>
          Chi Tiết Biên Bản Chênh Lệch Kiểm Kê
        </h3>
        <p class="action-desc">
          Xem xét số liệu chênh lệch, nội dung giải trình từ Quản lý kho và đưa ra quyết định phê duyệt hoặc từ chối.
        </p>
      </div>
      <div>
        <a href="/warehouse/pages/inventory/index.php" class="btn-secondary inline-flex items-center gap-1.5">
          <i class="fa-solid fa-arrow-left text-xs"></i>
          Quay lại danh sách
        </a>
      </div>
    </section>

    <!-- ═══════════════ KHỐI 1: THÔNG TIN CHUNG BIÊN BẢN ═══════════════ -->
    <section class="table-card mb-6">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa-solid fa-circle-info" style="color: #B91C1C;"></i>
          Khối Thông Tin Chung Biên Bản
        </div>
        <div>
          <?php if ($bienBan['TrangThai'] === 'Chờ phê duyệt'): ?>
            <span class="status-badge status-processing">
              <i class="fa-solid fa-hourglass-half text-[10px] mr-1"></i>Trạng thái: Chờ phê duyệt
            </span>
          <?php elseif ($bienBan['TrangThai'] === 'Đã phê duyệt'): ?>
            <span class="status-badge status-completed">
              <i class="fa-solid fa-circle-check text-[10px] mr-1"></i>Trạng thái: Đã phê duyệt
            </span>
          <?php else: ?>
            <span class="status-badge status-waiting" style="background: #FEE2E2; color: #991B1B; border-color: #B91C1C;">
              <i class="fa-solid fa-ban text-[10px] mr-1"></i>Trạng thái: Từ chối
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div class="p-5 grid grid-cols-1 md:grid-cols-4 gap-4 text-xs bg-white">
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Mã biên bản kiểm kê:</span>
          <span class="text-base font-bold text-gray-900"><?= htmlspecialchars($bienBan['MaBienBanKiemKe']) ?></span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Mã kế hoạch kiểm kê:</span>
          <span class="text-sm font-bold text-gray-900"><?= htmlspecialchars($bienBan['MaKeHoachKiemKe']) ?></span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200 md:col-span-2">
          <span class="text-gray-500 font-bold block mb-1">Tên kế hoạch kiểm kê:</span>
          <span class="text-sm font-bold text-gray-900"><?= htmlspecialchars($bienBan['TenKeHoachKiemKe']) ?></span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200 md:col-span-2">
          <span class="text-gray-500 font-bold block mb-1">Phạm vi kiểm kê:</span>
          <span class="text-sm font-bold text-red-700"><?= htmlspecialchars($bienBan['PhamViKiemKe']) ?></span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Kho kiểm kê:</span>
          <span class="text-sm font-bold text-gray-800"><?= htmlspecialchars($bienBan['TenKho']) ?> (<?= htmlspecialchars($bienBan['MaKho']) ?>)</span>
        </div>
        <div class="p-3 bg-gray-50 rounded border border-gray-200">
          <span class="text-gray-500 font-bold block mb-1">Ngày lập biên bản:</span>
          <span class="text-sm font-bold text-gray-800"><?= date('d/m/Y H:i', strtotime($bienBan['NgayLap'])) ?></span>
        </div>
      </div>

      <?php if (!empty($bienBan['NguoiDuyet'])): ?>
        <div class="p-4 bg-gray-50 border-t border-gray-200 text-xs grid grid-cols-1 md:grid-cols-3 gap-3">
          <div><strong class="text-gray-600">Người duyệt:</strong> <span class="font-bold text-gray-900"><?= htmlspecialchars($bienBan['NguoiDuyet']) ?></span></div>
          <div><strong class="text-gray-600">Thời gian duyệt:</strong> <?= date('d/m/Y H:i', strtotime($bienBan['ThoiGianDuyet'])) ?></div>
          <div>
            <strong class="text-gray-600">Ý kiến chỉ đạo:</strong> 
            <span class="italic text-gray-800"><?= htmlspecialchars($bienBan['YKienChiDao'] ?: 'Không có') ?></span>
          </div>
          <?php if ($bienBan['TrangThai'] === 'Từ chối'): ?>
            <div class="md:col-span-3 text-red-700 bg-red-50 p-2 rounded border border-red-200">
              <strong>Lý do từ chối:</strong> <?= htmlspecialchars($bienBan['LyDoTuChoi']) ?>
              <?php if (!empty($bienBan['NoiDungLyDoKhac'])): ?>
                — <em><?= htmlspecialchars($bienBan['NoiDungLyDoKhac']) ?></em>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- ═══════════════ KHỐI 2: BẢNG CHI TIẾT HÀNG HÓA CHÊNH LỆCH ═══════════════ -->
    <section class="table-card mb-6">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa-solid fa-scale-unbalanced text-red-700"></i>
          Chi Tiết Hàng Hóa Chênh Lệch Kiểm Kê
        </div>
        <div class="text-xs text-gray-500">
          Chênh lệch = Thực tế kiểm đếm - Tồn hệ thống
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="custom-table">
          <thead>
            <tr>
              <th>STT</th>
              <th>Mặt hàng (Mã hàng)</th>
              <th>Tên hàng</th>
              <th>Tên lô</th>
              <th>Đơn vị tính</th>
              <th class="text-right">Tồn hệ thống</th>
              <th class="text-right">Thực tế kiểm đếm</th>
              <th class="text-right font-bold" style="min-width: 130px;">Chênh lệch</th>
              <th>Đánh giá</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($chiTietHangHoa as $idx => $it): 
              $cl = (int)$it['ChenhLech'];
            ?>
              <tr>
                <td class="text-center font-bold text-gray-600"><?= $idx + 1 ?></td>
                <td class="font-bold text-gray-900"><?= htmlspecialchars($it['MatHang']) ?></td>
                <td class="font-medium text-gray-800"><?= htmlspecialchars($it['TenHang']) ?></td>
                <td class="font-mono text-xs text-gray-700"><?= htmlspecialchars($it['TenLo']) ?></td>
                <td class="text-gray-700"><?= htmlspecialchars($it['DonViTinh']) ?></td>
                <td class="text-right font-bold text-gray-800"><?= number_format($it['TonHeThong']) ?></td>
                <td class="text-right font-bold text-blue-800 bg-blue-50/50"><?= number_format($it['ThucTe']) ?></td>
                <td class="text-right font-bold text-sm">
                  <?php if ($cl < 0): ?>
                    <!-- Chênh lệch âm (thiếu) -> Highlight màu Đỏ (#B91C1C) -->
                    <span class="inline-flex items-center gap-1 text-red-700 bg-red-50 px-2 py-0.5 rounded border border-red-200">
                      <i class="fa-solid fa-arrow-trend-down text-xs"></i>
                      <?= number_format($cl) ?>
                    </span>
                  <?php elseif ($cl > 0): ?>
                    <!-- Chênh lệch dương (thừa) -> Highlight màu Xanh lá (#16A34A) -->
                    <span class="inline-flex items-center gap-1 text-green-700 bg-green-50 px-2 py-0.5 rounded border border-green-200">
                      <i class="fa-solid fa-arrow-trend-up text-xs"></i>
                      +<?= number_format($cl) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-gray-500 font-normal">0 (Khớp)</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($cl < 0): ?>
                    <span class="text-xs font-bold text-red-700">Hụt / Thiếu hàng</span>
                  <?php elseif ($cl > 0): ?>
                    <span class="text-xs font-bold text-green-700">Thừa hàng</span>
                  <?php else: ?>
                    <span class="text-xs text-gray-500 font-medium">Khớp sổ sách</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ═══════════════ KHỐI 3: KHỐI NGUYÊN NHÂN CHÊNH LỆCH ═══════════════ -->
    <section class="table-card mb-6">
      <div class="table-card-header bg-gray-50">
        <div class="table-card-title text-sm">
          <i class="fa-solid fa-triangle-exclamation text-amber-600"></i>
          Nguyên Nhân Chênh Lệch & Giải Trình Từ Quản Lý Kho
        </div>
      </div>

      <div class="p-5 space-y-3 bg-white">
        <?php if (empty($nguyenNhanList)): ?>
          <p class="text-xs text-gray-500 italic">Không có nội dung giải trình kèm theo.</p>
        <?php else: ?>
          <?php foreach ($nguyenNhanList as $nn): ?>
            <div class="p-3 bg-gray-50 border border-gray-200 rounded text-xs space-y-1">
              <div class="flex items-center justify-between">
                <span class="font-bold text-gray-900 flex items-center gap-1.5">
                  <span class="px-2 py-0.5 bg-gray-200 rounded text-[11px] font-mono text-gray-700"><?= htmlspecialchars($nn['MaNguyenNhan']) ?></span>
                  <?= htmlspecialchars($nn['TenNguyenNhan']) ?>
                </span>
              </div>
              <p class="text-gray-700 mt-1 leading-relaxed bg-white p-2.5 rounded border border-gray-100">
                <?= nl2br(htmlspecialchars($nn['MoTaNguyenNhan'])) ?>
              </p>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- ═══════════════ KHỐI 4: KHỐI TIẾP NHẬN CHỈ ĐẠO & THAO TÁC DUYỆT ═══════════════ -->
    <section class="table-card p-6 bg-white border border-gray-200 mb-8">
      <div class="border-b pb-3 mb-4">
        <h4 class="text-base font-bold text-gray-900 flex items-center gap-2">
          <i class="fa-solid fa-pen-nib text-indigo-700"></i>
          Tiếp Nhận Chỉ Đạo & Quyết Định Phê Duyệt (Ban Giám Đốc)
        </h4>
        <p class="text-xs text-gray-500 mt-0.5">Nhập ý kiến chỉ đạo xử lý trách nhiệm hoặc khắc phục chênh lệch kiểm kê.</p>
      </div>

      <form method="POST" id="formAuditAction" action="/warehouse/pages/inventory/detail.php?id=<?= urlencode($maBienBan) ?>">
        <input type="hidden" name="action" id="actionType" value="approve_audit">

        <!-- Ô nhập Ý kiến chỉ đạo (Textarea) -->
        <div class="form-group mb-5">
          <label class="form-label font-bold text-xs text-gray-800">
            Ý kiến chỉ đạo từ Ban Giám Đốc:
          </label>
          <textarea name="yKienChiDao" id="mainYKienChiDao" rows="3" class="form-control text-xs" 
                    placeholder="Nhập ý kiến chỉ đạo xử lý chênh lệch kiểm kê (ví dụ: Đồng ý điều chỉnh tồn kho theo thực tế, yêu cầu tổ kiểm đếm rút kinh nghiệm; hoặc làm rõ nguyên nhân hao hụt...)"
                    <?= $bienBan['TrangThai'] !== 'Chờ phê duyệt' ? 'disabled' : '' ?>><?= htmlspecialchars($bienBan['YKienChiDao'] ?? '') ?></textarea>
        </div>

        <!-- Các nút thao tác -->
        <div class="flex items-center justify-between pt-3 border-t">
          <!-- Nút Quay lại -->
          <a href="/warehouse/pages/inventory/index.php" class="btn-secondary inline-flex items-center gap-1.5">
            <i class="fa-solid fa-arrow-left text-xs"></i>
            Quay lại
          </a>

          <?php if ($bienBan['TrangThai'] === 'Chờ phê duyệt'): ?>
            <div class="flex items-center gap-3">
              <!-- Nút Từ chối (Danger/Secondary Button): Mở Popup/Modal chọn lý do từ chối -->
              <button type="button" onclick="openRejectModal()" class="btn-secondary text-red-700 hover:bg-red-50 border-red-300 font-bold inline-flex items-center gap-1.5">
                <i class="fa-solid fa-circle-xmark text-xs"></i>
                Từ chối
              </button>

              <!-- Nút Phê duyệt (Primary Button): Dùng khi đồng ý điều chỉnh số liệu tồn kho theo thực tế -->
              <button type="button" onclick="submitApprove()" class="btn-primary inline-flex items-center gap-1.5 font-bold" style="background: #16A34A; border-color: #16A34A;">
                <i class="fa-solid fa-circle-check text-xs"></i>
                Phê duyệt
              </button>
            </div>
          <?php else: ?>
            <div class="text-xs font-bold text-gray-500">
              Biên bản này đã được xử lý (<?= htmlspecialchars($bienBan['TrangThai']) ?>) lúc <?= date('d/m/Y H:i', strtotime($bienBan['ThoiGianDuyet'])) ?>.
            </div>
          <?php endif; ?>
        </div>
      </form>
    </section>

  </main>

  <footer class="dashboard-footer">
    © 2026 Hệ Thống Quản Lý Chuỗi Kho &nbsp;·&nbsp;
    <a href="#">Hỗ trợ kỹ thuật</a>
  </footer>
</div>

<!-- ═══════════════ MODAL POPUP: TỪ CHỐI PHÊ DUYỆT (LUỒNG THAY THẾ) ═══════════════ -->
<div id="modalReject" class="modal-overlay">
  <div class="modal-box" style="max-width: 550px;">
    <div class="modal-box-header">
      <div class="modal-box-title text-red-700">
        <i class="fa-solid fa-triangle-exclamation"></i>
        Từ Chối Phê Duyệt Chênh Lệch Kiểm Kê
      </div>
      <button type="button" class="close-btn" onclick="closeRejectModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="/warehouse/pages/inventory/detail.php?id=<?= urlencode($maBienBan) ?>">
      <input type="hidden" name="action" value="reject_audit">
      <input type="hidden" name="yKienChiDaoReject" id="hiddenYKienChiDao">

      <div class="modal-box-body space-y-4 text-xs text-gray-700">
        <p class="font-bold text-gray-900">
          Vui lòng chọn lý do từ chối biên bản kiểm kê <span class="text-red-700"><?= htmlspecialchars($maBienBan) ?></span>:
        </p>

        <!-- Danh sách Lý do từ chối (Radio buttons) -->
        <div class="space-y-2 p-3 bg-gray-50 rounded border border-gray-200">
          <label class="flex items-center gap-2 cursor-pointer font-medium p-1 rounded hover:bg-gray-100">
            <input type="radio" name="lyDoTuChoi" value="Giải trình nguyên nhân chưa rõ ràng" onchange="toggleOtherReason(this)" checked class="w-4 h-4 text-red-700" style="accent-color: #B91C1C;">
            <span>Lý do 1: Giải trình nguyên nhân chưa rõ ràng.</span>
          </label>

          <label class="flex items-center gap-2 cursor-pointer font-medium p-1 rounded hover:bg-gray-100">
            <input type="radio" name="lyDoTuChoi" value="Yêu cầu kiểm kê/đếm lại thực tế" onchange="toggleOtherReason(this)" class="w-4 h-4 text-red-700" style="accent-color: #B91C1C;">
            <span>Lý do 2: Yêu cầu kiểm kê/đếm lại thực tế.</span>
          </label>

          <label class="flex items-center gap-2 cursor-pointer font-medium p-1 rounded hover:bg-gray-100">
            <input type="radio" name="lyDoTuChoi" value="Cần làm rõ trách nhiệm bộ phận quản lý kho" onchange="toggleOtherReason(this)" class="w-4 h-4 text-red-700" style="accent-color: #B91C1C;">
            <span>Lý do 3: Cần làm rõ trách nhiệm bộ phận quản lý kho.</span>
          </label>

          <label class="flex items-center gap-2 cursor-pointer font-medium p-1 rounded hover:bg-gray-100">
            <input type="radio" name="lyDoTuChoi" value="Lý do khác" id="radioOther" onchange="toggleOtherReason(this)" class="w-4 h-4 text-red-700" style="accent-color: #B91C1C;">
            <span>Tùy chọn: "Lý do khác".</span>
          </label>
        </div>

        <!-- Ô nhập Nội dung lý do khác (Textarea) -->
        <div id="otherReasonBox" class="form-group hidden">
          <label class="form-label font-bold text-red-700">
            Nội dung lý do khác <span class="required">*</span>:
          </label>
          <textarea name="noiDungLyDoKhac" id="noiDungLyDoKhac" rows="3" class="form-control text-xs" 
                    placeholder="Nhập chi tiết lý do từ chối khác..."></textarea>
        </div>

        <div class="p-3 bg-red-50 border border-red-200 rounded text-red-800 text-[11px] space-y-1">
          <div>• Hệ thống sẽ cập nhật trạng thái biên bản thành <strong>"Từ chối"</strong>.</div>
          <div>• Giữ nguyên số liệu tồn kho cũ trên hệ thống, không thay đổi số liệu.</div>
          <div>• Tự động gửi thông báo phản hồi cho Bộ phận kiểm kê và Quản lý kho.</div>
        </div>
      </div>

      <div class="modal-box-footer">
        <!-- Nút Hủy: Đóng popup và quay lại giao diện xem chi tiết -->
        <button type="button" class="btn-secondary" onclick="closeRejectModal()">
          Hủy
        </button>

        <!-- Nút Xác nhận từ chối -->
        <button type="submit" class="btn-primary" style="background: #B91C1C; border-color: #B91C1C;">
          <i class="fa-solid fa-ban text-xs mr-1"></i>
          Xác nhận từ chối
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // Dropdown & Sidebar
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

  // Phê duyệt
  function submitApprove() {
    if (confirm('Ban Giám Đốc xác nhận phê duyệt biên bản chênh lệch kiểm kê này?\nSố lượng tồn kho chính thức sẽ được điều chỉnh theo số liệu Thực tế kiểm đếm.')) {
      document.getElementById('actionType').value = 'approve_audit';
      document.getElementById('formAuditAction').submit();
    }
  }

  // Modal Từ chối
  function openRejectModal() {
    document.getElementById('hiddenYKienChiDao').value = document.getElementById('mainYKienChiDao').value;
    document.getElementById('modalReject').classList.add('active');
  }
  function closeRejectModal() {
    document.getElementById('modalReject').classList.remove('active');
  }

  function toggleOtherReason(radio) {
    const box = document.getElementById('otherReasonBox');
    const textarea = document.getElementById('noiDungLyDoKhac');
    if (radio.value === 'Lý do khác') {
      box.classList.remove('hidden');
      textarea.required = true;
      textarea.focus();
    } else {
      box.classList.add('hidden');
      textarea.required = false;
      textarea.value = '';
    }
  }
</script>
</body>
</html>

