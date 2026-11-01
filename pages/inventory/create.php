<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// Phân quyền: Chỉ tài khoản thuộc Bộ phận kiểm kê được truy cập chức năng
if (!isStocktaking()) {
    header('Location: /warehouse/pages/dashboard.php');
    exit;
}

$user = getCurrentUser();
$initials = mb_strtoupper(mb_substr($user['name'] ?: 'KK', 0, 1));
$unreadCount = 0;
$db = getDB();

// Khởi tạo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// -------------------------------------------------------------------------
// XỬ LÝ POST: XÁC NHẬN LẬP BIÊN BẢN KIỂM KÊ
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_audit') {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if ($csrfToken !== $_SESSION['csrf_token']) {
            $_SESSION['flash_error'] = 'Phiên làm việc đã hết hạn hoặc không hợp lệ. Vui lòng thử lại.';
            header('Location: /warehouse/pages/inventory/create.php');
            exit;
        }

        $planId = trim($_POST['plan_id'] ?? '');
        $note   = trim($_POST['ghi_chu'] ?? '');
        $qtys   = $_POST['qty'] ?? [];

        if (empty($planId)) {
            $_SESSION['flash_error'] = 'Mã kế hoạch kiểm kê không hợp lệ.';
            header('Location: /warehouse/pages/inventory/create.php');
            exit;
        }

        try {
            $db->beginTransaction();

            // 1. Kiểm tra kế hoạch kiểm kê và khóa dòng (chống trùng lặp)
            $stmtPlan = $db->prepare("
                SELECT k.*, w.name AS TenKho
                FROM KEHOACH_KIEMKE k
                JOIN warehouses w ON k.MaKho = w.id
                WHERE k.MaKeHoachKiemKe = ?
                FOR UPDATE
            ");
            $stmtPlan->execute([$planId]);
            $plan = $stmtPlan->fetch();

            if (!$plan) {
                $db->rollBack();
                $_SESSION['flash_error'] = 'Không tìm thấy kế hoạch kiểm kê yêu cầu.';
                header('Location: /warehouse/pages/inventory/create.php');
                exit;
            }

            if ($plan['TrangThai'] !== 'Chờ thực hiện') {
                $db->rollBack();
                $_SESSION['flash_error'] = 'Kế hoạch này đã được lập biên bản trước đó (Trạng thái: ' . $plan['TrangThai'] . ').';
                header('Location: /warehouse/pages/inventory/create.php');
                exit;
            }

            // 2. Lấy danh sách hàng hóa / lô hàng trong kế hoạch từ CSDL
            $stmtItems = $db->prepare("
                SELECT 
                    ct.MaHangHoa,
                    h.TenHangHoa,
                    ct.MaLo,
                    h.DonViTinh,
                    l.SoLuongTon AS TonHeThong
                FROM CHITIET_KEHOACH_KIEMKE ct
                JOIN HANGHOA h ON ct.MaHangHoa = h.MaHangHoa
                JOIN LOHANG l ON ct.MaLo = l.MaLo
                WHERE ct.MaKeHoachKiemKe = ?
                ORDER BY ct.id ASC
            ");
            $stmtItems->execute([$planId]);
            $dbItems = $stmtItems->fetchAll();

            if (empty($dbItems)) {
                $db->rollBack();
                $_SESSION['flash_error'] = 'Kế hoạch kiểm kê không có lô hàng nào cần kiểm kê.';
                header('Location: /warehouse/pages/inventory/create.php');
                exit;
            }

            // 3. Kiểm tra dữ liệu thực tế người dùng nhập
            $detailsToInsert = [];
            foreach ($dbItems as $item) {
                $maLo = $item['MaLo'];
                if (!isset($qtys[$maLo]) || $qtys[$maLo] === '' || !is_numeric($qtys[$maLo])) {
                    $db->rollBack();
                    $_SESSION['flash_error'] = "Vui lòng nhập đầy đủ số lượng thực tế cho tất cả lô hàng (Lô {$maLo} chưa nhập hợp lệ).";
                    header('Location: /warehouse/pages/inventory/create.php?plan_id=' . urlencode($planId));
                    exit;
                }

                $thucTe = (int)$qtys[$maLo];
                if ($thucTe < 0) {
                    $db->rollBack();
                    $_SESSION['flash_error'] = "Số lượng thực tế của lô {$maLo} không được là số âm.";
                    header('Location: /warehouse/pages/inventory/create.php?plan_id=' . urlencode($planId));
                    exit;
                }

                $tonHeThong = (int)$item['TonHeThong'];
                $chenhLech  = $thucTe - $tonHeThong;

                $detailsToInsert[] = [
                    'mat_hang'     => $item['MaHangHoa'],
                    'ten_hang'     => $item['TenHangHoa'],
                    'ten_lo'       => $item['MaLo'],
                    'don_vi_tinh'  => $item['DonViTinh'],
                    'ton_he_thong' => $tonHeThong,
                    'thuc_te'      => $thucTe,
                    'chenh_lech'   => $chenhLech
                ];
            }

            // 4. Sinh mã biên bản duy nhất: BBKK-2026-xxx
            $year = date('Y');
            $prefix = "BBKK-{$year}-";
            $stmtMax = $db->prepare("
                SELECT MaBienBanKiemKe 
                FROM BIENBAN_KIEMKE 
                WHERE MaBienBanKiemKe LIKE ? 
                ORDER BY MaBienBanKiemKe DESC 
                LIMIT 1 
                FOR UPDATE
            ");
            $stmtMax->execute([$prefix . '%']);
            $maxCode = $stmtMax->fetchColumn();

            $nextNum = 1;
            if ($maxCode) {
                $lastNum = (int)substr($maxCode, strlen($prefix));
                $nextNum = $lastNum + 1;
            }
            $newMaBienBan = $prefix . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

            // Người lập tự lấy theo tài khoản đang đăng nhập
            $nguoiLap = $user['name'] ?: $user['username'];
            $ngayLap  = date('Y-m-d H:i:s');

            // 5. Lưu biên bản vào BIENBAN_KIEMKE
            $stmtInsertBB = $db->prepare("
                INSERT INTO BIENBAN_KIEMKE (
                    MaBienBanKiemKe, MaKeHoachKiemKe, TenKeHoachKiemKe, PhamViKiemKe,
                    MaKho, NgayLap, NguoiLap, TrangThai, GhiChu
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Chờ phê duyệt', ?)
            ");
            $stmtInsertBB->execute([
                $newMaBienBan,
                $plan['MaKeHoachKiemKe'],
                $plan['TenKeHoachKiemKe'],
                $plan['PhamViKiemKe'],
                $plan['MaKho'],
                $ngayLap,
                $nguoiLap,
                $note ?: null
            ]);

            // 6. Lưu các dòng chi tiết vào CHITIET_BIENBAN_KIEMKE (lưu cả chênh lệch bằng 0)
            $stmtInsertCT = $db->prepare("
                INSERT INTO CHITIET_BIENBAN_KIEMKE (
                    MaBienBanKiemKe, MatHang, TenHang, TenLo, DonViTinh, TonHeThong, ThucTe, ChenhLech
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($detailsToInsert as $d) {
                $stmtInsertCT->execute([
                    $newMaBienBan,
                    $d['mat_hang'],
                    $d['ten_hang'],
                    $d['ten_lo'],
                    $d['don_vi_tinh'],
                    $d['ton_he_thong'],
                    $d['thuc_te'],
                    $d['chenh_lech']
                ]);
            }

            // 7. Cập nhật trạng thái kế hoạch kiểm kê sang 'Đã thực hiện'
            $stmtUpdatePlan = $db->prepare("
                UPDATE KEHOACH_KIEMKE 
                SET TrangThai = 'Đã thực hiện' 
                WHERE MaKeHoachKiemKe = ?
            ");
            $stmtUpdatePlan->execute([$planId]);

            // Commit transaction
            $db->commit();

            // Đổi mới token để chống gửi lại form
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

            // Chuyển hướng đến màn hình kết quả thành công
            header('Location: /warehouse/pages/inventory/create.php?success=1&id=' . urlencode($newMaBienBan));
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['flash_error'] = 'Lỗi hệ thống khi lưu biên bản: ' . $e->getMessage();
            header('Location: /warehouse/pages/inventory/create.php?plan_id=' . urlencode($planId));
            exit;
        }
    }
}

// -------------------------------------------------------------------------
// PHÂN TÍCH CHẾ ĐỘ HIỂN THỊ (VIEW ROUTING)
// -------------------------------------------------------------------------
$mode = 'list'; // 'list' (Màn hình 1), 'form' (Màn hình 2), 'success' (Màn hình 3)
$planIdParam  = trim($_GET['plan_id'] ?? '');
$isSuccess    = !empty($_GET['success']);
$reportId     = trim($_GET['id'] ?? '');

$createdReport = null;
$createdDetails = [];
$activePlan = null;
$planItems  = [];

if ($isSuccess && !empty($reportId)) {
    // -------------------------------------------------------------
    // MÀN HÌNH 3: KẾT QUẢ LẬP BIÊN BẢN THÀNH CÔNG
    // -------------------------------------------------------------
    $mode = 'success';
    $stmtRep = $db->prepare("
        SELECT b.*, w.name AS TenKho
        FROM BIENBAN_KIEMKE b
        LEFT JOIN warehouses w ON b.MaKho = w.id
        WHERE b.MaBienBanKiemKe = ?
    ");
    $stmtRep->execute([$reportId]);
    $createdReport = $stmtRep->fetch();

    if ($createdReport) {
        $stmtDet = $db->prepare("
            SELECT * FROM CHITIET_BIENBAN_KIEMKE 
            WHERE MaBienBanKiemKe = ? 
            ORDER BY id ASC
        ");
        $stmtDet->execute([$reportId]);
        $createdDetails = $stmtDet->fetchAll();
    } else {
        $mode = 'list';
    }
} elseif (!empty($planIdParam)) {
    // -------------------------------------------------------------
    // MÀN HÌNH 2: FORM LẬP BIÊN BẢN KIỂM KÊ CHO KẾ HOẠCH ĐÃ CHỌN
    // -------------------------------------------------------------
    $stmtP = $db->prepare("
        SELECT k.*, w.name AS TenKho
        FROM KEHOACH_KIEMKE k
        JOIN warehouses w ON k.MaKho = w.id
        WHERE k.MaKeHoachKiemKe = ?
    ");
    $stmtP->execute([$planIdParam]);
    $activePlan = $stmtP->fetch();

    if ($activePlan && $activePlan['TrangThai'] === 'Chờ thực hiện') {
        $mode = 'form';
        $stmtIt = $db->prepare("
            SELECT 
                ct.MaHangHoa,
                h.TenHangHoa,
                ct.MaLo,
                h.DonViTinh,
                l.SoLuongTon AS TonHeThong
            FROM CHITIET_KEHOACH_KIEMKE ct
            JOIN HANGHOA h ON ct.MaHangHoa = h.MaHangHoa
            JOIN LOHANG l ON ct.MaLo = l.MaLo
            WHERE ct.MaKeHoachKiemKe = ?
            ORDER BY ct.id ASC
        ");
        $stmtIt->execute([$planIdParam]);
        $planItems = $stmtIt->fetchAll();
    } else {
        $mode = 'list';
        if ($activePlan && $activePlan['TrangThai'] !== 'Chờ thực hiện') {
            $flash_error = 'Kế hoạch này đã được lập biên bản (Trạng thái: ' . $activePlan['TrangThai'] . ').';
        } else {
            $flash_error = 'Không tìm thấy kế hoạch kiểm kê yêu cầu.';
        }
    }
}

// -------------------------------------------------------------------------
// NẾU Ở MÀN HÌNH 1: LẤY DANH SÁCH KẾ HOẠCH CÓ TRẠNG THÁI 'Chờ thực hiện'
// -------------------------------------------------------------------------
$plansList = [];
if ($mode === 'list') {
    $stmtPlans = $db->query("
        SELECT k.MaKeHoachKiemKe, k.TenKeHoachKiemKe, w.name AS TenKho, k.PhamViKiemKe, k.TrangThai, k.MaKho
        FROM KEHOACH_KIEMKE k
        JOIN warehouses w ON k.MaKho = w.id
        WHERE k.TrangThai = 'Chờ thực hiện'
        ORDER BY k.NgayTao DESC, k.MaKeHoachKiemKe ASC
    ");
    $plansList = $stmtPlans->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lập biên bản kiểm kê — Quản Lý Kho</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/warehouse/assets/css/dashboard.css">
  <style>
    .diff-neg {
      color: #DC2626 !important;
      background: #FEE2E2;
      border: 1px solid #FECACA;
      padding: 2px 8px;
      border-radius: 4px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .diff-pos {
      color: #16A34A !important;
      background: #DCFCE7;
      border: 1px solid #BBF7D0;
      padding: 2px 8px;
      border-radius: 4px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .diff-zero {
      color: #6B7280 !important;
      font-weight: 600;
    }
  </style>
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

    <div class="nav-section">Kiểm kê kho</div>
    <a href="/warehouse/pages/inventory/create.php" class="nav-item active">
      <i class="fa-solid fa-clipboard-check"></i>
      <span>Lập biên bản kiểm kê</span>
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

  <!-- TOPBAR -->
  <header id="topbar">
    <button onclick="toggleSidebar()" class="lg:hidden p-1.5 rounded hover:bg-gray-100 text-gray-700 mr-1">
      <i class="fa-solid fa-bars text-base"></i>
    </button>

    <div class="flex items-center gap-1.5 text-sm flex-1 min-w-0">
      <a href="/warehouse/pages/dashboard.php" class="text-gray-400 hover:text-red-700">
        <i class="fa-solid fa-house text-xs"></i>
      </a>
      <span class="text-gray-400">/</span>
      <span class="text-gray-500">Kiểm kê</span>
      <span class="text-gray-400">/</span>
      <span class="breadcrumb-label">Lập biên bản kiểm kê</span>
    </div>

    <div class="flex items-center gap-3">
      <div class="hidden sm:flex badge-branch">
        <i class="fa-solid fa-location-dot text-xs" style="color: #B91C1C;"></i>
        <span><?= htmlspecialchars($user['warehouse_name']) ?></span>
      </div>

      <div class="relative" id="userDropWrap">
        <button class="user-pill" onclick="toggleDrop()">
          <div class="user-avatar"><?= $initials ?></div>
          <div class="hidden md:block text-left">
            <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
            <span class="<?= getRoleBadgeClass($user['role']) ?> text-[10px] py-0 px-1.5 leading-tight">
              <?= getRoleLabel($user['role']) ?>
            </span>
          </div>
          <i class="fa-solid fa-chevron-down text-gray-400 text-xs ml-1"></i>
        </button>

        <div id="userDrop" class="user-dropdown">
          <div class="user-drop-header">
            <div class="font-bold text-gray-900"><?= htmlspecialchars($user['name']) ?></div>
            <div class="text-xs text-gray-500 mt-0.5">@<?= htmlspecialchars($user['username']) ?></div>
          </div>
          <a href="/warehouse/auth/logout.php" class="user-drop-logout">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Đăng xuất</span>
          </a>
        </div>
      </div>
    </div>
  </header>

  <!-- NỘI DUNG CHÍNH -->
  <main class="dashboard-main">

    <!-- Flash Thông báo -->
    <?php if ($flash_success): ?>
      <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-800 rounded text-xs flex items-center gap-2">
        <i class="fa-solid fa-circle-check text-green-600 text-sm"></i>
        <span><?= htmlspecialchars($flash_success) ?></span>
      </div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
      <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-800 rounded text-xs flex items-center gap-2">
        <i class="fa-solid fa-triangle-exclamation text-red-600 text-sm"></i>
        <span><?= htmlspecialchars($flash_error) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($mode === 'list'): ?>
      <!-- ═══════════════════════════════════════════════════════════════
           MÀN HÌNH 1: DANH SÁCH KẾ HOẠCH KIỂM KÊ (CHỜ THỰC HIỆN)
           ═══════════════════════════════════════════════════════════════ -->
      <section class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl font-bold text-gray-900">Lập biên bản kiểm kê</h1>
          <p class="text-xs text-gray-500 mt-1">Chọn kế hoạch kiểm kê đang ở trạng thái Chờ thực hiện để tiến hành kiểm đếm và lập biên bản.</p>
        </div>
      </section>

      <div class="table-card">
        <div class="table-card-header">
          <div class="table-card-title">
            <i class="fa-solid fa-calendar-check" style="color: #B91C1C;"></i>
            Danh Sách Kế Hoạch Kiểm Kê
          </div>
          <div>
            <span class="status-badge status-processing">
              <i class="fa-solid fa-hourglass-half text-[10px] mr-1"></i>
              <?= count($plansList) ?> kế hoạch chờ thực hiện
            </span>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="custom-table">
            <thead>
              <tr>
                <th style="width: 130px;">Mã kế hoạch</th>
                <th>Tên kế hoạch</th>
                <th>Kho kiểm kê</th>
                <th>Phạm vi kiểm kê</th>
                <th style="width: 140px;">Trạng thái</th>
                <!-- Cột chứa nút chọn: để trống tiêu đề theo yêu cầu -->
                <th style="width: 160px; text-align: right;"></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($plansList)): ?>
                <tr>
                  <td colspan="6" class="text-center py-8 text-gray-500 italic">
                    <i class="fa-solid fa-clipboard-check text-2xl text-gray-300 block mb-2"></i>
                    Hiện không có kế hoạch kiểm kê nào đang chờ thực hiện.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($plansList as $p): ?>
                  <tr>
                    <td class="font-bold text-gray-900 font-mono">
                      <?= htmlspecialchars($p['MaKeHoachKiemKe']) ?>
                    </td>
                    <td class="font-bold text-gray-800">
                      <?= htmlspecialchars($p['TenKeHoachKiemKe']) ?>
                    </td>
                    <td>
                      <span class="inline-flex items-center gap-1.5 text-gray-800">
                        <i class="fa-solid fa-warehouse text-gray-400 text-xs"></i>
                        <?= htmlspecialchars($p['TenKho']) ?>
                      </span>
                    </td>
                    <td>
                      <span class="px-2 py-0.5 bg-red-50 text-red-700 rounded text-xs font-medium border border-red-200">
                        <?= htmlspecialchars($p['PhamViKiemKe']) ?>
                      </span>
                    </td>
                    <td>
                      <span class="status-badge status-waiting">
                        <i class="fa-solid fa-clock text-[10px] mr-1"></i>
                        <?= htmlspecialchars($p['TrangThai']) ?>
                      </span>
                    </td>
                    <td class="text-right">
                      <a href="/warehouse/pages/inventory/create.php?plan_id=<?= urlencode($p['MaKeHoachKiemKe']) ?>" 
                         class="btn-primary" 
                         style="padding: 6px 14px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px;">
                        <span>Chọn kế hoạch</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="p-3 bg-gray-50 border-t border-gray-200 text-xs text-gray-500">
          <i class="fa-solid fa-circle-info text-blue-600 mr-1"></i>
          Kế hoạch đã thực hiện sẽ không thể chọn để lập thêm biên bản. Dữ liệu được quản lý đồng bộ theo thời gian thực.
        </div>
      </div>

    <?php elseif ($mode === 'form'): ?>
      <!-- ═══════════════════════════════════════════════════════════════
           MÀN HÌNH 2: LẬP BIÊN BẢN KIỂM KÊ (NHẬP SỐ LƯỢNG THỰC TẾ)
           ═══════════════════════════════════════════════════════════════ -->
      <div class="mb-4">
        <a href="/warehouse/pages/inventory/create.php" class="btn-secondary inline-flex items-center gap-1.5 text-xs font-bold">
          <i class="fa-solid fa-arrow-left"></i>
          Quay lại danh sách
        </a>
      </div>

      <!-- Khối thông tin kế hoạch kiểm kê -->
      <section class="table-card mb-6">
        <div class="table-card-header">
          <div class="table-card-title">
            <i class="fa-solid fa-circle-info" style="color: #B91C1C;"></i>
            Thông Tin Kế Hoạch Kiểm Kê
          </div>
          <div>
            <span class="status-badge status-waiting">
              <i class="fa-solid fa-hourglass-half text-[10px] mr-1"></i>Trạng thái: Chờ thực hiện
            </span>
          </div>
        </div>

        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs bg-white">
          <div class="p-3 bg-gray-50 rounded border border-gray-200">
            <span class="text-gray-500 font-bold block mb-1">Mã kế hoạch:</span>
            <span class="text-base font-bold text-gray-900 font-mono"><?= htmlspecialchars($activePlan['MaKeHoachKiemKe']) ?></span>
          </div>
          <div class="p-3 bg-gray-50 rounded border border-gray-200 md:col-span-2">
            <span class="text-gray-500 font-bold block mb-1">Tên kế hoạch:</span>
            <span class="text-sm font-bold text-gray-900"><?= htmlspecialchars($activePlan['TenKeHoachKiemKe']) ?></span>
          </div>
          <div class="p-3 bg-gray-50 rounded border border-gray-200">
            <span class="text-gray-500 font-bold block mb-1">Kho kiểm kê:</span>
            <span class="text-sm font-bold text-gray-900"><?= htmlspecialchars($activePlan['TenKho']) ?> (<?= htmlspecialchars($activePlan['MaKho']) ?>)</span>
          </div>
          <div class="p-3 bg-gray-50 rounded border border-gray-200 md:col-span-4">
            <span class="text-gray-500 font-bold block mb-1">Phạm vi kiểm kê:</span>
            <span class="text-sm font-bold text-red-700"><?= htmlspecialchars($activePlan['PhamViKiemKe']) ?></span>
          </div>
        </div>
      </section>

      <!-- Form nhập kết quả kiểm kê thực tế -->
      <form method="POST" action="/warehouse/pages/inventory/create.php" id="auditForm" onsubmit="return validateBeforeSubmit();">
        <input type="hidden" name="action" value="submit_audit">
        <input type="hidden" name="plan_id" value="<?= htmlspecialchars($activePlan['MaKeHoachKiemKe']) ?>">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <section class="table-card mb-6">
          <div class="table-card-header">
            <div>
              <div class="table-card-title">
                <i class="fa-solid fa-clipboard-list text-red-700"></i>
                Nhập Kết Quả Kiểm Kê Thực Tế
              </div>
              <div class="text-xs text-gray-500 mt-0.5">
                Nhập đầy đủ số lượng thực tế của từng lô hàng. Giá trị 0 được chấp nhận là hợp lệ.
              </div>
            </div>
            <div>
              <span class="px-2.5 py-1 bg-gray-100 text-gray-700 rounded text-xs font-bold border border-gray-200">
                <?= count($planItems) ?> lô hàng
              </span>
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="custom-table">
              <thead>
                <tr>
                  <th style="width: 120px;">Mã hàng</th>
                  <th>Tên hàng hóa</th>
                  <th style="width: 120px;">Mã lô</th>
                  <th style="width: 80px;">ĐVT</th>
                  <th style="width: 130px; text-align: right;">Tồn hệ thống</th>
                  <th style="width: 160px; text-align: right;">Thực tế <span class="text-red-600">*</span></th>
                  <th style="width: 140px; text-align: right;">Chênh lệch</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($planItems as $idx => $it): ?>
                  <tr>
                    <td class="font-mono font-bold text-gray-900">
                      <?= htmlspecialchars($it['MaHangHoa']) ?>
                    </td>
                    <td class="font-medium text-gray-800">
                      <?= htmlspecialchars($it['TenHangHoa']) ?>
                    </td>
                    <td>
                      <span class="px-2 py-0.5 bg-gray-100 rounded text-xs font-mono font-bold text-gray-700 border border-gray-200">
                        <?= htmlspecialchars($it['MaLo']) ?>
                      </span>
                    </td>
                    <td class="text-gray-600">
                      <?= htmlspecialchars($it['DonViTinh']) ?>
                    </td>
                    <td class="text-right font-bold text-gray-900">
                      <?= number_format($it['TonHeThong']) ?>
                    </td>
                    <td class="text-right">
                      <input type="number" 
                             name="qty[<?= htmlspecialchars($it['MaLo']) ?>]" 
                             id="qty_<?= $idx ?>" 
                             data-system="<?= (int)$it['TonHeThong'] ?>"
                             min="0" 
                             step="1" 
                             required 
                             class="form-control text-right font-bold qty-input w-28 ml-auto" 
                             placeholder="Nhập SL" 
                             aria-label="Số lượng thực tế <?= htmlspecialchars($it['TenHangHoa']) ?>"
                             oninput="updateRowDiff(<?= $idx ?>)">
                    </td>
                    <td class="text-right">
                      <div id="diff_<?= $idx ?>" class="diff-zero">—</div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Khối ghi chú & thống kê chênh lệch -->
          <div class="p-5 border-t border-gray-200 bg-gray-50 space-y-4">
            <div>
              <label for="ghi_chu" class="block text-xs font-bold text-gray-800 mb-1">
                Ghi chú kiểm kê <span class="text-gray-500 font-normal">(không bắt buộc, tối đa 1.000 ký tự)</span>:
              </label>
              <textarea name="ghi_chu" 
                        id="ghi_chu" 
                        rows="3" 
                        maxlength="1000" 
                        class="form-control w-full text-xs" 
                        placeholder="Ví dụ: Đã kiểm đếm đầy đủ; lô NVL001 có một số bao bì bị hư hỏng..."></textarea>
              <p class="text-[11px] text-gray-500 mt-1">Ghi chú áp dụng chung cho toàn bộ biên bản kiểm kê.</p>
            </div>

            <!-- Thống kê chênh lệch tức thì -->
            <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-gray-200 text-xs">
              <span class="font-bold text-gray-700">Tổng kết kiểm kê:</span>
              <div class="px-3 py-1.5 bg-red-50 border border-red-200 rounded text-red-800">
                Thiếu hàng: <b id="statMissing" class="font-bold">0</b> lô
              </div>
              <div class="px-3 py-1.5 bg-gray-100 border border-gray-200 rounded text-gray-700">
                Khớp số lượng: <b id="statMatched" class="font-bold">0</b> lô
              </div>
              <div class="px-3 py-1.5 bg-green-50 border border-green-200 rounded text-green-800">
                Thừa hàng: <b id="statSurplus" class="font-bold">0</b> lô
              </div>
            </div>

            <!-- Cảnh báo lỗi validate JS -->
            <div id="validationAlert" class="hidden p-3 bg-red-50 border border-red-200 text-red-700 text-xs rounded flex items-center gap-2">
              <i class="fa-solid fa-triangle-exclamation text-red-600"></i>
              <span id="validationMsg">Vui lòng nhập đầy đủ số lượng thực tế là số nguyên không âm cho tất cả lô hàng (có thể nhập 0).</span>
            </div>

            <!-- Nút thao tác -->
            <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-200">
              <a href="/warehouse/pages/inventory/create.php" class="btn-secondary">
                Hủy bỏ / Quay lại
              </a>
              <button type="submit" id="btnSubmit" class="btn-primary" style="padding: 10px 20px; font-size: 13px;">
                <i class="fa-solid fa-check mr-1.5"></i>
                Xác nhận lập biên bản
              </button>
            </div>
          </div>
        </section>
      </form>

    <?php elseif ($mode === 'success' && $createdReport): ?>
      <!-- ═══════════════════════════════════════════════════════════════
           MÀN HÌNH 3: KẾT QUẢ XÁC NHẬN LẬP BIÊN BẢN THÀNH CÔNG
           ═══════════════════════════════════════════════════════════════ -->
      <div class="mb-6 p-4 bg-green-50 border-2 border-green-500 rounded-lg text-green-900">
        <div class="flex items-start gap-3">
          <i class="fa-solid fa-circle-check text-2xl text-green-600 mt-0.5"></i>
          <div>
            <h2 class="text-base font-bold text-green-900">✓ Lập biên bản kiểm kê thành công!</h2>
            <p class="text-xs text-green-800 mt-1">
              Biên bản đã được ghi nhận và chuyển sang trạng thái <strong>Chờ phê duyệt</strong>. Kế hoạch kiểm kê đã chuyển sang <strong>Đã thực hiện</strong>.
            </p>
          </div>
        </div>
      </div>

      <!-- Card thông tin biên bản vừa lập -->
      <section class="table-card mb-6">
        <div class="table-card-header">
          <div class="table-card-title">
            <i class="fa-solid fa-file-invoice text-red-700"></i>
            Thông Tin Biên Bản Vừa Lập
          </div>
          <div>
            <span class="status-badge status-waiting">
              <i class="fa-solid fa-hourglass-half text-[10px] mr-1"></i>Trạng thái: Chờ phê duyệt
            </span>
          </div>
        </div>

        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 text-xs bg-white">
          <div class="p-3 bg-gray-50 rounded border border-gray-200">
            <span class="text-gray-500 font-bold block mb-1">Mã biên bản:</span>
            <span class="text-lg font-bold text-red-700 font-mono"><?= htmlspecialchars($createdReport['MaBienBanKiemKe']) ?></span>
          </div>
          <div class="p-3 bg-gray-50 rounded border border-gray-200">
            <span class="text-gray-500 font-bold block mb-1">Mã kế hoạch:</span>
            <span class="text-sm font-bold text-gray-900 font-mono"><?= htmlspecialchars($createdReport['MaKeHoachKiemKe']) ?></span>
          </div>
          <div class="p-3 bg-gray-50 rounded border border-gray-200">
            <span class="text-gray-500 font-bold block mb-1">Người lập:</span>
            <span class="text-sm font-bold text-gray-900"><?= htmlspecialchars($createdReport['NguoiLap']) ?></span>
          </div>
          <div class="p-3 bg-gray-50 rounded border border-gray-200">
            <span class="text-gray-500 font-bold block mb-1">Ngày lập biên bản:</span>
            <span class="text-sm font-bold text-gray-800"><?= date('d/m/Y H:i:s', strtotime($createdReport['NgayLap'])) ?></span>
          </div>
          <div class="p-3 bg-gray-50 rounded border border-gray-200">
            <span class="text-gray-500 font-bold block mb-1">Trạng thái kế hoạch:</span>
            <span class="status-badge status-completed">
              <i class="fa-solid fa-circle-check text-[10px] mr-1"></i>Đã thực hiện
            </span>
          </div>
          <div class="p-3 bg-gray-50 rounded border border-gray-200">
            <span class="text-gray-500 font-bold block mb-1">Trạng thái biên bản:</span>
            <span class="status-badge status-waiting">
              <i class="fa-solid fa-clock text-[10px] mr-1"></i>Chờ phê duyệt
            </span>
          </div>
        </div>
      </section>

      <!-- Card Bảng chi tiết kết quả kiểm kê -->
      <section class="table-card mb-6">
        <div class="table-card-header">
          <div class="table-card-title">
            <i class="fa-solid fa-scale-unbalanced text-red-700"></i>
            Chi Tiết Kết Quả Kiểm Kê
          </div>
          <div class="text-xs text-gray-500">
            Chênh lệch = Thực tế − Tồn hệ thống
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="custom-table">
            <thead>
              <tr>
                <th>Mã hàng</th>
                <th>Hàng hóa</th>
                <th>Lô</th>
                <th>ĐVT</th>
                <th class="text-right">Tồn hệ thống</th>
                <th class="text-right">Thực tế</th>
                <th class="text-right">Chênh lệch</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($createdDetails as $d): 
                $cl = (int)$d['ChenhLech'];
              ?>
                <tr>
                  <td class="font-mono font-bold text-gray-900"><?= htmlspecialchars($d['MatHang']) ?></td>
                  <td class="font-medium text-gray-800"><?= htmlspecialchars($d['TenHang']) ?></td>
                  <td>
                    <span class="px-2 py-0.5 bg-gray-100 rounded text-xs font-mono font-bold text-gray-700 border border-gray-200">
                      <?= htmlspecialchars($d['TenLo']) ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($d['DonViTinh']) ?></td>
                  <td class="text-right font-bold text-gray-800"><?= number_format($d['TonHeThong']) ?></td>
                  <td class="text-right font-bold text-gray-900"><?= number_format($d['ThucTe']) ?></td>
                  <td class="text-right">
                    <?php if ($cl < 0): ?>
                      <span class="diff-neg">
                        <i class="fa-solid fa-arrow-down text-[10px]"></i>
                        <?= number_format($cl) ?> (Thiếu)
                      </span>
                    <?php elseif ($cl > 0): ?>
                      <span class="diff-pos">
                        <i class="fa-solid fa-arrow-up text-[10px]"></i>
                        +<?= number_format($cl) ?> (Thừa)
                      </span>
                    <?php else: ?>
                      <span class="diff-zero">0 (Khớp)</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if (!empty($createdReport['GhiChu'])): ?>
          <div class="p-4 bg-gray-50 border-t border-gray-200 text-xs">
            <span class="font-bold text-gray-700 block mb-1">GHI CHÚ KIỂM KÊ:</span>
            <p class="text-gray-800 italic bg-white p-3 rounded border border-gray-200 whitespace-pre-wrap"><?= htmlspecialchars($createdReport['GhiChu']) ?></p>
          </div>
        <?php endif; ?>

        <div class="p-4 bg-blue-50 border-t border-blue-200 text-xs text-blue-900 flex items-center justify-between flex-wrap gap-3">
          <div class="flex items-center gap-2">
            <i class="fa-solid fa-circle-info text-blue-600"></i>
            <span>Tồn kho hệ thống được giữ nguyên. Phê duyệt chênh lệch và điều chỉnh tồn kho thuộc chức năng của Ban Giám Đốc.</span>
          </div>
          <div>
            <a href="/warehouse/pages/inventory/create.php" class="btn-secondary font-bold">
              <i class="fa-solid fa-list mr-1.5"></i>
              Về danh sách kế hoạch
            </a>
          </div>
        </div>
      </section>

    <?php endif; ?>

  </main>

  <footer class="dashboard-footer">
    © 2026 Hệ Thống Quản Lý Chuỗi Kho &nbsp;·&nbsp; Module Lập Biên Bản Kiểm Kê
  </footer>
</div>

<script>
  // Quản lý dropdown user
  const drop = document.getElementById('userDrop');
  function toggleDrop() {
    drop.style.display = drop.style.display === 'none' || drop.style.display === '' ? 'block' : 'none';
  }
  document.addEventListener('click', (e) => {
    if (drop && !document.getElementById('userDropWrap').contains(e.target)) {
      drop.style.display = 'none';
    }
  });

  function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
  }
  function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('open');
  }

  // Realtime calculation for Form
  function updateRowDiff(idx) {
    const input = document.getElementById('qty_' + idx);
    const diffEl = document.getElementById('diff_' + idx);
    if (!input || !diffEl) return;

    input.classList.remove('border-red-500', 'bg-red-50');
    const alertBox = document.getElementById('validationAlert');
    if (alertBox) alertBox.classList.add('hidden');

    const valStr = input.value.trim();
    if (valStr === '' || isNaN(valStr) || Number(valStr) < 0 || !Number.isInteger(Number(valStr))) {
      diffEl.innerHTML = '—';
      diffEl.className = 'diff-zero';
    } else {
      const actual = parseInt(valStr, 10);
      const system = parseInt(input.getAttribute('data-system'), 10);
      const diff = actual - system;

      if (diff < 0) {
        diffEl.innerHTML = `<span class="diff-neg"><i class="fa-solid fa-arrow-down text-[10px]"></i> ${diff.toLocaleString('vi-VN')}</span>`;
      } else if (diff > 0) {
        diffEl.innerHTML = `<span class="diff-pos"><i class="fa-solid fa-arrow-up text-[10px]"></i> +${diff.toLocaleString('vi-VN')}</span>`;
      } else {
        diffEl.innerHTML = `<span class="diff-zero">0 (Khớp)</span>`;
      }
    }
    recalculateStats();
  }

  function recalculateStats() {
    const inputs = document.querySelectorAll('.qty-input');
    let missing = 0, matched = 0, surplus = 0;

    inputs.forEach(inp => {
      const valStr = inp.value.trim();
      if (valStr !== '' && !isNaN(valStr) && Number(valStr) >= 0) {
        const actual = parseInt(valStr, 10);
        const system = parseInt(inp.getAttribute('data-system'), 10);
        const diff = actual - system;
        if (diff < 0) missing++;
        else if (diff > 0) surplus++;
        else matched++;
      }
    });

    const elMissing = document.getElementById('statMissing');
    const elMatched = document.getElementById('statMatched');
    const elSurplus = document.getElementById('statSurplus');

    if (elMissing) elMissing.textContent = missing;
    if (elMatched) elMatched.textContent = matched;
    if (elSurplus) elSurplus.textContent = surplus;
  }

  function validateBeforeSubmit() {
    const inputs = document.querySelectorAll('.qty-input');
    const alertBox = document.getElementById('validationAlert');
    const msgEl = document.getElementById('validationMsg');
    let invalidInput = null;

    for (let inp of inputs) {
      const val = inp.value.trim();
      if (val === '' || isNaN(val) || Number(val) < 0 || !Number.isInteger(Number(val))) {
        inp.classList.add('border-red-500', 'bg-red-50');
        if (!invalidInput) invalidInput = inp;
      }
    }

    if (invalidInput) {
      if (alertBox && msgEl) {
        msgEl.textContent = 'Vui lòng nhập đầy đủ số lượng thực tế là số nguyên không âm cho tất cả lô hàng (có thể nhập 0).';
        alertBox.classList.remove('hidden');
      }
      invalidInput.focus();
      return false;
    }

    const btn = document.getElementById('btnSubmit');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Đang xử lý lưu...';
    }
    return true;
  }
</script>
</body>
</html>

