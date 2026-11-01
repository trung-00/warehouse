<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// Phân quyền: Chỉ Quản lý kho (Bộ phận quản lý kho) mới được thực hiện chức năng này
if (!isManager()) {
    header('Location: /warehouse/pages/dashboard.php');
    exit;
}

$user = getCurrentUser();
$initials = mb_strtoupper(mb_substr($user['name'] ?: 'QL', 0, 1));
$unreadCount = getUnreadNotificationCount($user['role'], $user['id']);
$db = getDB();

// Khởi tạo CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// -------------------------------------------------------------------------
// XỬ LÝ POST: GỬI PHÊ DUYỆT GIẢI TRÌNH CHÊNH LỆCH (AJAX HOẶC FORM POST)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_explanation') {
        header('Content-Type: application/json; charset=utf-8');

        $csrfToken = $_POST['csrf_token'] ?? '';
        if ($csrfToken !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'message' => 'Phiên làm việc không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang.']);
            exit;
        }

        $maBienBan = trim($_POST['ma_bien_ban'] ?? '');
        $explanations = $_POST['explanations'] ?? []; // mảng [detail_id => ['reason_code' => ..., 'content' => ...]]

        if (empty($maBienBan)) {
            echo json_encode(['success' => false, 'message' => 'Mã biên bản kiểm kê không hợp lệ.']);
            exit;
        }

        try {
            $db->beginTransaction();

            // 1. Kiểm tra biên bản tồn tại và đang ở trạng thái 'Có chênh lệch'
            $stmtBB = $db->prepare("
                SELECT b.*, w.name AS TenKho 
                FROM BIENBAN_KIEMKE b
                JOIN warehouses w ON b.MaKho = w.id
                WHERE b.MaBienBanKiemKe = ? 
                FOR UPDATE
            ");
            $stmtBB->execute([$maBienBan]);
            $bienBan = $stmtBB->fetch();

            if (!$bienBan) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Không tìm thấy biên bản kiểm kê yêu cầu.']);
                exit;
            }

            if ($bienBan['TrangThai'] !== 'Có chênh lệch') {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Biên bản này không ở trạng thái "Có chênh lệch" (Trạng thái hiện tại: ' . $bienBan['TrangThai'] . ').']);
                exit;
            }

            // 2. Lấy các dòng hàng hóa chênh lệch cần giải trình
            $stmtRows = $db->prepare("
                SELECT id, MatHang, TenHang, TenLo, ChenhLech 
                FROM CHITIET_BIENBAN_KIEMKE 
                WHERE MaBienBanKiemKe = ? AND ChenhLech != 0
            ");
            $stmtRows->execute([$maBienBan]);
            $diffRows = $stmtRows->fetchAll();

            if (empty($diffRows)) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Biên bản không có dòng hàng hóa nào bị chênh lệch.']);
                exit;
            }

            // 3. Kiểm tra toàn bộ các dòng chênh lệch đều phải được giải trình
            foreach ($diffRows as $row) {
                $rowId = $row['id'];
                if (!isset($explanations[$rowId])) {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Vui lòng giải trình toàn bộ các dòng hàng hóa chênh lệch trước khi gửi phê duyệt.']);
                    exit;
                }

                $reasonCode = trim($explanations[$rowId]['reason_code'] ?? '');
                $content    = trim($explanations[$rowId]['content'] ?? '');

                if (empty($reasonCode) || empty($content)) {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Vui lòng chọn nguyên nhân và nhập nội dung giải trình cho tất cả các dòng chênh lệch.']);
                    exit;
                }
            }

            // Lấy danh mục nguyên nhân để map tên
            $stmtReasons = $db->query("SELECT MaNguyenNhan, TenNguyenNhan FROM DANHMUC_NGUYENNHAN");
            $reasonMap = [];
            while ($r = $stmtReasons->fetch()) {
                $reasonMap[$r['MaNguyenNhan']] = $r['TenNguyenNhan'];
            }

            // 4. Cập nhật giải trình cho từng dòng chi tiết
            $stmtUpdateCT = $db->prepare("
                UPDATE CHITIET_BIENBAN_KIEMKE 
                SET MaNguyenNhan = ?, NoiDungGiaiTrinh = ? 
                WHERE id = ? AND MaBienBanKiemKe = ?
            ");

            $combinedExplanations = [];
            foreach ($diffRows as $row) {
                $rowId = $row['id'];
                $rCode = $explanations[$rowId]['reason_code'];
                $rContent = $explanations[$rowId]['content'];
                $stmtUpdateCT->execute([$rCode, $rContent, $rowId, $maBienBan]);

                $reasonName = $reasonMap[$rCode] ?? $rCode;
                $combinedExplanations[] = "Lô {$row['TenLo']} ({$row['TenHang']}): [{$reasonName}] {$rContent}";
            }

            // 5. Đồng bộ vào bảng NGUYENNHAN_CHENHLECH để Ban Giám Đốc xem được
            $stmtDelNN = $db->prepare("DELETE FROM NGUYENNHAN_CHENHLECH WHERE MaBienBanKiemKe = ?");
            $stmtDelNN->execute([$maBienBan]);

            $stmtInsNN = $db->prepare("
                INSERT INTO NGUYENNHAN_CHENHLECH (MaNguyenNhan, MaBienBanKiemKe, TenNguyenNhan, MoTaNguyenNhan)
                VALUES (?, ?, ?, ?)
            ");
            $nnCode = 'NN-' . substr($maBienBan, -3);
            $fullExplanationText = implode(";\n", $combinedExplanations);
            $stmtInsNN->execute([
                $nnCode,
                $maBienBan,
                'Giải trình chi tiết từ Quản lý kho',
                $fullExplanationText
            ]);

            // 6. Cập nhật trạng thái biên bản thành "Chờ phê duyệt"
            $stmtUpdateBB = $db->prepare("
                UPDATE BIENBAN_KIEMKE 
                SET TrangThai = 'Chờ phê duyệt' 
                WHERE MaBienBanKiemKe = ?
            ");
            $stmtUpdateBB->execute([$maBienBan]);

            // 7. Gửi thông báo đến Ban Giám Đốc (recipient_role = 'director')
            $notifTitle = "Biên bản kiểm kê cần phê duyệt: {$maBienBan}";
            $notifMsg   = "Quản lý kho ({$user['name']}) đã hoàn tất giải trình chênh lệch cho biên bản {$maBienBan} và gửi lên Ban Giám Đốc phê duyệt.";
            $stmtNotif  = $db->prepare("
                INSERT INTO notifications (recipient_role, recipient_id, incident_id, title, message)
                VALUES ('director', NULL, NULL, ?, ?)
            ");
            $stmtNotif->execute([$notifTitle, $notifMsg]);

            // Commit transaction
            $db->commit();

            echo json_encode([
                'success' => true,
                'ma_bien_ban' => $maBienBan,
                'message' => 'GỬI PHÊ DUYỆT THÀNH CÔNG!'
            ]);
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống khi lưu giải trình: ' . $e->getMessage()]);
            exit;
        }
    }
}

// -------------------------------------------------------------------------
// ROUTING MÀN HÌNH:
// Trang 1: Danh sách biên bản cần xử lý (khi không có $_GET['id'])
// Trang 2: Chi tiết biên bản kiểm kê & Giải trình từng dòng (khi có $_GET['id'])
// -------------------------------------------------------------------------
$viewId = trim($_GET['id'] ?? '');

$currentAudit = null;
$diffItems = [];
$reasonList = [];

if (!empty($viewId)) {
    // ---------------------------------------------------------------------
    // TRANG 2: CHI TIẾT BIÊN BẢN CẦN XỬ LÝ
    // ---------------------------------------------------------------------
    $stmtAudit = $db->prepare("
        SELECT b.*, w.name AS TenKho 
        FROM BIENBAN_KIEMKE b
        JOIN warehouses w ON b.MaKho = w.id
        WHERE b.MaBienBanKiemKe = ?
    ");
    $stmtAudit->execute([$viewId]);
    $currentAudit = $stmtAudit->fetch();

    if (!$currentAudit) {
        $_SESSION['flash_error'] = 'Không tìm thấy biên bản kiểm kê yêu cầu.';
        header('Location: /warehouse/pages/inventory/explain.php');
        exit;
    }

    if ($currentAudit['TrangThai'] !== 'Có chênh lệch') {
        $_SESSION['flash_error'] = 'Biên bản ' . htmlspecialchars($viewId) . ' không ở trạng thái "Có chênh lệch" (Trạng thái: ' . $currentAudit['TrangThai'] . ').';
        header('Location: /warehouse/pages/inventory/explain.php');
        exit;
    }

    // Chỉ lấy các dòng hàng hóa CÓ CHÊNH LỆCH (ChenhLech != 0)
    $stmtItems = $db->prepare("
        SELECT id, MatHang, TenHang, TenLo, DonViTinh, TonHeThong, ThucTe, ChenhLech, MaNguyenNhan, NoiDungGiaiTrinh 
        FROM CHITIET_BIENBAN_KIEMKE 
        WHERE MaBienBanKiemKe = ? AND ChenhLech != 0
        ORDER BY id ASC
    ");
    $stmtItems->execute([$viewId]);
    $diffItems = $stmtItems->fetchAll();

    // Lấy danh mục nguyên nhân
    $stmtReasons = $db->query("SELECT * FROM DANHMUC_NGUYENNHAN ORDER BY MaNguyenNhan ASC");
    $reasonList = $stmtReasons->fetchAll();

} else {
    // ---------------------------------------------------------------------
    // TRANG 1: DANH SÁCH BIÊN BẢN CẦN XỬ LÝ (Có chênh lệch / Chưa giải trình)
    // ---------------------------------------------------------------------
    $searchKey  = trim($_GET['search'] ?? '');
    $filterKho  = trim($_GET['filter_kho'] ?? '');
    $filterDate = trim($_GET['filter_date'] ?? '');

    $whereClauses = ["b.TrangThai = 'Có chênh lệch'"];
    $params = [];

    if (!empty($searchKey)) {
        $whereClauses[] = "(b.MaBienBanKiemKe LIKE ? OR b.TenKeHoachKiemKe LIKE ?)";
        $params[] = "%$searchKey%";
        $params[] = "%$searchKey%";
    }
    if (!empty($filterKho)) {
        $whereClauses[] = "b.MaKho = ?";
        $params[] = $filterKho;
    }
    if (!empty($filterDate)) {
        $whereClauses[] = "DATE(b.NgayLap) = ?";
        $params[] = $filterDate;
    }

    $sql = "
        SELECT b.MaBienBanKiemKe, b.TenKeHoachKiemKe, b.MaKho, w.name AS TenKho, b.NgayLap, b.TrangThai,
               SUM(CASE WHEN ct.ChenhLech != 0 THEN 1 ELSE 0 END) AS SoDongChenhLech
        FROM BIENBAN_KIEMKE b
        JOIN warehouses w ON b.MaKho = w.id
        LEFT JOIN CHITIET_BIENBAN_KIEMKE ct ON b.MaBienBanKiemKe = ct.MaBienBanKiemKe
        WHERE " . implode(" AND ", $whereClauses) . "
        GROUP BY b.MaBienBanKiemKe
        ORDER BY b.NgayLap DESC, b.MaBienBanKiemKe ASC
    ";

    $stmtList = $db->prepare($sql);
    $stmtList->execute($params);
    $auditList = $stmtList->fetchAll();

    // Lấy danh sách kho cho bộ lọc
    $warehousesList = $db->query("SELECT id, name FROM warehouses ORDER BY name ASC")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $currentAudit ? 'Chi tiết biên bản: ' . htmlspecialchars($currentAudit['MaBienBanKiemKe']) : 'Giải trình chênh lệch kiểm kê' ?> — Quản Lý Kho</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/warehouse/assets/css/dashboard.css">
  <style>
    .selected-row {
      background-color: #EFF6FF !important;
    }
    .hover-row:hover {
      background-color: #F8FAFC;
    }
    .diff-badge-neg {
      color: #DC2626;
      background: #FEE2E2;
      border: 1px solid #FECACA;
      padding: 3px 8px;
      border-radius: 4px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .diff-badge-pos {
      color: #16A34A;
      background: #DCFCE7;
      border: 1px solid #BBF7D0;
      padding: 3px 8px;
      border-radius: 4px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 4px;
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

    <div class="nav-section">Quản lý kho bãi</div>
    <a href="/warehouse/pages/shelves/index.php" class="nav-item">
      <i class="fa-solid fa-cubes-stacked"></i>
      <span>Quản lý kệ kho</span>
    </a>

    <div class="nav-section">Kiểm kê kho</div>
    <a href="/warehouse/pages/inventory/explain.php" class="nav-item active">
      <i class="fa-solid fa-scale-unbalanced-flip"></i>
      <span>Giải trình chênh lệch</span>
    </a>

    <div class="nav-section">Sự cố kho</div>
    <a href="/warehouse/pages/incidents/handle.php" class="nav-item">
      <i class="fa-solid fa-screwdriver-wrench"></i>
      <span>Xử lý sự cố</span>
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
      <span class="breadcrumb-label">Giải trình chênh lệch kiểm kê</span>
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

    <?php if (isset($_SESSION['flash_error'])): ?>
      <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-800 rounded text-xs flex items-center gap-2">
        <i class="fa-solid fa-triangle-exclamation text-red-600"></i>
        <span><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
      </div>
      <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!$currentAudit): ?>
      <!-- ═══════════════════════════════════════════════════════════════
           TRANG 1: DANH SÁCH BIÊN BẢN CẦN XỬ LÝ
           ═══════════════════════════════════════════════════════════════ -->
      <section class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl font-bold text-gray-900 uppercase tracking-wide">GIẢI TRÌNH CHÊNH LỆCH KIỂM KÊ</h1>
          <p class="text-xs text-gray-500 mt-1">Danh sách biên bản kiểm kê có chênh lệch số lượng cần Quản lý kho giải trình nguyên nhân trước khi gửi Ban Giám Đốc phê duyệt.</p>
        </div>
      </section>

      <!-- Card DANH SÁCH BIÊN BẢN CẦN XỬ LÝ -->
      <div class="table-card">
        <div class="table-card-header">
          <div class="table-card-title text-sm uppercase">
            <i class="fa-solid fa-list-check" style="color: #B91C1C;"></i>
            DANH SÁCH BIÊN BẢN CẦN XỬ LÝ
          </div>
          <div>
            <span class="px-2.5 py-1 bg-amber-50 text-amber-800 rounded text-xs font-bold border border-amber-200">
              <i class="fa-solid fa-hourglass-half text-[10px] mr-1"></i>
              <?= count($auditList) ?> biên bản cần giải trình
            </span>
          </div>
        </div>

        <!-- Thanh tìm kiếm & Bộ lọc -->
        <div class="p-4 bg-gray-50 border-b border-gray-200">
          <form method="GET" action="/warehouse/pages/inventory/explain.php" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 items-end text-xs">
            <!-- Ô tìm kiếm từ khóa -->
            <div>
              <label class="block font-bold text-gray-700 mb-1">Mã biên bản:</label>
              <div class="relative">
                <input type="text" 
                       name="search" 
                       value="<?= htmlspecialchars($searchKey ?? '') ?>" 
                       placeholder="Tìm kiếm..." 
                       class="form-control pl-8 w-full text-xs" />
                <i class="fa-solid fa-magnifying-glass absolute left-2.5 top-2.5 text-gray-400 text-xs"></i>
              </div>
            </div>

            <!-- Bộ lọc Kho -->
            <div>
              <label class="block font-bold text-gray-700 mb-1">Kho kiểm kê:</label>
              <select name="filter_kho" class="form-control w-full text-xs">
                <option value="">Tất cả kho</option>
                <?php foreach ($warehousesList as $wh): ?>
                  <option value="<?= htmlspecialchars($wh['id']) ?>" <?= (isset($filterKho) && $filterKho === $wh['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($wh['name']) ?> (<?= htmlspecialchars($wh['id']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Bộ lọc Ngày -->
            <div>
              <label class="block font-bold text-gray-700 mb-1">Ngày kiểm kê:</label>
              <input type="date" 
                     name="filter_date" 
                     value="<?= htmlspecialchars($filterDate ?? '') ?>" 
                     class="form-control w-full text-xs" />
            </div>

            <!-- Nút thao tác lọc -->
            <div class="flex items-center gap-2">
              <button type="submit" class="btn-primary" style="padding: 8px 16px; font-size: 12px; margin-top: 18px;">
                <i class="fa-solid fa-filter mr-1"></i> Lọc
              </button>
              <?php if (!empty($searchKey) || !empty($filterKho) || !empty($filterDate)): ?>
                <a href="/warehouse/pages/inventory/explain.php" class="btn-secondary" style="padding: 8px 12px; margin-top: 18px;" title="Xóa bộ lọc">
                  <i class="fa-solid fa-rotate-left"></i>
                </a>
              <?php endif; ?>
            </div>
          </form>
        </div>

        <!-- Bảng dữ liệu Data Table -->
        <div class="overflow-x-auto">
          <table class="custom-table">
            <thead>
              <tr>
                <th style="width: 50px;">STT</th>
                <th style="width: 140px;">Mã biên bản</th>
                <th>Kho kiểm kê</th>
                <th style="width: 160px;">Ngày kiểm kê</th>
                <th style="width: 220px;">Trạng thái</th>
                <th style="width: 160px; text-align: right;">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($auditList)): ?>
                <tr>
                  <td colspan="6" class="text-center py-10 text-gray-500 italic">
                    <i class="fa-solid fa-clipboard-check text-3xl text-gray-300 block mb-2"></i>
                    Không có biên bản nào cần giải trình
                  </td>
                </tr>
              <?php else: ?>
                <?php $stt = 1; foreach ($auditList as $row): ?>
                  <tr class="hover-row">
                    <td><?= $stt++ ?></td>
                    <td class="font-mono font-bold text-gray-900">
                      <?= htmlspecialchars($row['MaBienBanKiemKe']) ?>
                    </td>
                    <td>
                      <span class="inline-flex items-center gap-1.5 text-gray-800">
                        <i class="fa-solid fa-warehouse text-gray-400 text-xs"></i>
                        <?= htmlspecialchars($row['TenKho']) ?>
                      </span>
                    </td>
                    <td class="text-gray-700">
                      <?= date('d/m/Y', strtotime($row['NgayLap'])) ?>
                    </td>
                    <td>
                      <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-amber-50 text-amber-800 border border-amber-300 rounded text-xs font-bold">
                        <i class="fa-solid fa-triangle-exclamation text-amber-600 text-[11px]"></i>
                        Có chênh lệch / Chưa giải trình
                      </span>
                    </td>
                    <td class="text-right">
                      <a href="/warehouse/pages/inventory/explain.php?id=<?= urlencode($row['MaBienBanKiemKe']) ?>" 
                         class="btn-primary" 
                         style="padding: 6px 14px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; background-color: #1D4ED8; border-color: #1D4ED8;">
                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                        <span>Bắt đầu giải trình</span>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="p-3 bg-gray-50 border-t border-gray-200 text-xs text-gray-500 flex justify-between items-center">
          <span>Hiển thị <strong><?= count($auditList) ?></strong> biên bản cần xử lý</span>
          <span class="text-[11px] text-gray-400">Trang 1 / 1</span>
        </div>
      </div>

    <?php else: ?>
      <!-- ═══════════════════════════════════════════════════════════════
           TRANG 2: CHI TIẾT BIÊN BẢN KIỂM KÊ: <MÃ BIÊN BẢN>
           ═══════════════════════════════════════════════════════════════ -->
      <section class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl font-bold text-gray-900 uppercase tracking-wide">
            CHI TIẾT BIÊN BẢN KIỂM KÊ: <?= htmlspecialchars($currentAudit['MaBienBanKiemKe']) ?>
          </h1>
          <p class="text-xs text-gray-500 mt-1">Vui lòng giải trình nguyên nhân cho từng dòng hàng hóa có phát sinh chênh lệch trước khi gửi Ban Giám Đốc phê duyệt.</p>
        </div>
        <div>
          <button type="button" onclick="openCancelModal()" class="btn-secondary inline-flex items-center gap-1.5 text-xs font-bold">
            <i class="fa-solid fa-arrow-left"></i>
            Quay lại danh sách
          </button>
        </div>
      </section>

      <!-- Card CHI TIẾT BIÊN BẢN KIỂM KÊ: <Mã biên bản> -->
      <div class="table-card mb-6">
        <div class="table-card-header bg-gray-50">
          <div class="table-card-title text-sm uppercase">
            <i class="fa-solid fa-file-invoice text-red-700"></i>
            CHI TIẾT BIÊN BẢN KIỂM KÊ: <?= htmlspecialchars($currentAudit['MaBienBanKiemKe']) ?>
          </div>
          <div>
            <span class="px-2.5 py-1 bg-amber-50 text-amber-800 rounded text-xs font-bold border border-amber-300">
              Có chênh lệch / Chưa giải trình
            </span>
          </div>
        </div>

        <!-- 1. Khối Thông tin chung (chỉ đọc) -->
        <div class="p-5 border-b border-gray-200 bg-white">
          <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs">
            <div>
              <label class="block font-bold text-gray-600 mb-1">Kho:</label>
              <select disabled class="form-control w-full bg-gray-100 text-gray-800 cursor-not-allowed font-medium">
                <option selected><?= htmlspecialchars($currentAudit['TenKho']) ?></option>
              </select>
            </div>
            <div>
              <label class="block font-bold text-gray-600 mb-1">Ngày:</label>
              <input type="date" disabled class="form-control w-full bg-gray-100 text-gray-800 cursor-not-allowed font-medium" 
                     value="<?= date('Y-m-d', strtotime($currentAudit['NgayLap'])) ?>" />
            </div>
            <div>
              <label class="block font-bold text-gray-600 mb-1">Kế hoạch kiểm kê:</label>
              <div class="p-2 bg-gray-50 border border-gray-200 rounded text-gray-800 truncate" title="<?= htmlspecialchars($currentAudit['TenKeHoachKiemKe']) ?>">
                <?= htmlspecialchars($currentAudit['TenKeHoachKiemKe']) ?>
              </div>
            </div>
            <div>
              <label class="block font-bold text-gray-600 mb-1">Người lập biên bản:</label>
              <div class="p-2 bg-gray-50 border border-gray-200 rounded text-gray-800">
                <?= htmlspecialchars($currentAudit['NguoiLap']) ?>
              </div>
            </div>
          </div>
        </div>

        <!-- 2. Bảng BIÊN BẢN CẦN XỬ LÝ (chỉ các dòng có chênh lệch) -->
        <div class="p-4 bg-blue-50/50 border-b border-blue-100 flex items-center justify-between">
          <div class="text-xs font-bold text-blue-900 uppercase flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation text-amber-500"></i>
            BIÊN BẢN CẦN XỬ LÝ (Chỉ liệt kê các dòng hàng hóa có chênh lệch)
          </div>
          <span class="text-xs text-gray-600">
            Tổng cộng: <strong><?= count($diffItems) ?></strong> dòng chênh lệch
          </span>
        </div>

        <div class="overflow-x-auto">
          <table class="custom-table" id="tableDiffRows">
            <thead>
              <tr>
                <th style="width: 45px;">STT</th>
                <th style="width: 100px;">Mã hàng</th>
                <th>Tên hàng</th>
                <th style="width: 100px;">Số lô</th>
                <th style="width: 100px; text-align: right;">Tồn hệ thống</th>
                <th style="width: 90px; text-align: right;">Thực tế</th>
                <th style="width: 120px; text-align: right;">Chênh lệch</th>
                <th style="width: 220px;">Nguyên nhân</th>
                <th>Nội dung giải trình</th>
                <th style="width: 140px; text-align: center;">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <?php $idx = 1; foreach ($diffItems as $row): 
                $cl = (int)$row['ChenhLech'];
              ?>
                <tr id="row_<?= $row['id'] ?>" class="hover-row transition-colors" data-id="<?= $row['id'] ?>">
                  <td><?= $idx++ ?></td>
                  <td class="font-mono font-bold text-gray-900"><?= htmlspecialchars($row['MatHang']) ?></td>
                  <td class="font-medium text-gray-800"><?= htmlspecialchars($row['TenHang']) ?></td>
                  <td>
                    <span class="px-2 py-0.5 bg-gray-100 rounded text-xs font-mono font-bold text-gray-700 border border-gray-200">
                      <?= htmlspecialchars($row['TenLo']) ?>
                    </span>
                  </td>
                  <td class="text-right font-bold text-gray-800"><?= number_format($row['TonHeThong']) ?></td>
                  <td class="text-right font-bold text-gray-900"><?= number_format($row['ThucTe']) ?></td>
                  <td class="text-right">
                    <?php if ($cl < 0): ?>
                      <span class="diff-badge-neg">
                        <i class="fa-solid fa-arrow-down text-[10px]"></i>
                        <?= number_format($cl) ?>
                      </span>
                    <?php else: ?>
                      <span class="diff-badge-pos">
                        <i class="fa-solid fa-arrow-up text-[10px]"></i>
                        +<?= number_format($cl) ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td id="cell_reason_<?= $row['id'] ?>" class="text-gray-500 italic text-xs">
                    <span class="text-gray-400">— Chưa giải trình —</span>
                  </td>
                  <td id="cell_content_<?= $row['id'] ?>" class="text-gray-500 italic text-xs">
                    <span class="text-gray-400">—</span>
                  </td>
                  <td class="text-center" id="cell_action_<?= $row['id'] ?>">
                    <button type="button" 
                            onclick="openPopover(<?= $row['id'] ?>, '<?= htmlspecialchars($row['TenLo'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['TenHang'], ENT_QUOTES) ?>')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded text-xs font-bold transition shadow-sm">
                      <i class="fa-solid fa-pen-to-square"></i>
                      <span>Giải trình</span>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- 3. Khối nút thao tác cuối trang -->
        <div class="p-5 bg-gray-50 border-t border-gray-200 flex flex-col sm:flex-row items-center justify-between gap-3">
          <div class="text-xs text-gray-500">
            <i class="fa-solid fa-shield-halved text-blue-600 mr-1"></i>
            Dữ liệu giải trình sẽ được lưu tạm tại màn hình cho đến khi bạn bấm <strong>GỬI PHÊ DUYỆT</strong>.
          </div>
          <div class="flex items-center gap-3">
            <button type="button" onclick="openCancelModal()" class="btn-secondary font-bold" style="padding: 10px 22px; font-size: 13px;">
              HỦY BỎ
            </button>
            <button type="button" id="btnSubmitApproval" onclick="submitApproval()" class="btn-primary font-bold" 
                    style="padding: 10px 26px; font-size: 13px; background-color: #2563EB; border-color: #2563EB;">
              <i class="fa-solid fa-paper-plane mr-1.5"></i>
              GỬI PHÊ DUYỆT
            </button>
          </div>
        </div>
      </div>

      <!-- POPOVER: GIẢI TRÌNH TỪNG DÒNG -->
      <div id="explainPopover" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="bg-white rounded-lg shadow-2xl border border-gray-200 w-full max-w-md overflow-hidden animate-in fade-in zoom-in-95 duration-150" onclick="event.stopPropagation()">
          <div class="px-5 py-3.5 bg-blue-600 text-white flex items-center justify-between">
            <div class="font-bold text-sm flex items-center gap-2">
              <i class="fa-solid fa-pen-to-square"></i>
              <span id="popoverTitle">Giải trình chênh lệch</span>
            </div>
            <button type="button" onclick="closePopover()" class="text-white/80 hover:text-white text-base">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>

          <div class="p-5 space-y-4 text-xs">
            <!-- Nhãn Nguyên nhân: Select Option -->
            <div>
              <label for="popoverReason" class="block font-bold text-gray-700 mb-1">
                Nguyên nhân <span class="text-red-600">*</span>:
              </label>
              <select id="popoverReason" class="form-control w-full text-xs" onchange="clearPopoverError()">
                <option value="">-- Chọn nguyên nhân chênh lệch --</option>
                <?php foreach ($reasonList as $rn): ?>
                  <option value="<?= htmlspecialchars($rn['MaNguyenNhan']) ?>" data-name="<?= htmlspecialchars($rn['TenNguyenNhan']) ?>">
                    <?= htmlspecialchars($rn['TenNguyenNhan']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Nhãn Nội dung giải trình: Textarea -->
            <div>
              <label for="popoverContent" class="block font-bold text-gray-700 mb-1">
                Nội dung giải trình <span class="text-red-600">*</span>:
              </label>
              <textarea id="popoverContent" 
                        rows="3" 
                        maxlength="1000" 
                        class="form-control w-full text-xs" 
                        placeholder="Nhập nội dung giải trình chi tiết từ Quản lý kho..."
                        oninput="clearPopoverError()"></textarea>
            </div>

            <!-- Báo lỗi thiếu thông tin giải trình (Luồng thay thế 6.1) -->
            <div id="popoverError" class="hidden p-2.5 bg-red-50 border border-red-300 text-red-700 rounded text-xs flex items-center gap-1.5 font-medium">
              <i class="fa-solid fa-circle-exclamation text-red-600"></i>
              <span id="popoverErrorText">Vui lòng chọn nguyên nhân và nhập nội dung giải trình</span>
            </div>
          </div>

          <div class="px-5 py-3 bg-gray-50 border-t border-gray-200 flex justify-end gap-2">
            <button type="button" onclick="closePopover()" class="btn-secondary text-xs">
              Đóng
            </button>
            <button type="button" onclick="confirmRowExplanation()" class="btn-primary text-xs" style="background-color: #2563EB; border-color: #2563EB; padding: 7px 16px;">
              <i class="fa-solid fa-check mr-1"></i>
              Xác nhận
            </button>
          </div>
        </div>
      </div>

      <!-- MODAL POPUP: XÁC NHẬN HỦY BỎ THAO TÁC (Luồng ngoại lệ 7.1) -->
      <div id="cancelModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="bg-white rounded-lg shadow-2xl border border-gray-200 w-full max-w-md overflow-hidden text-center p-6 space-y-4">
          <div class="w-14 h-14 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto text-2xl">
            <i class="fa-solid fa-triangle-exclamation"></i>
          </div>

          <h3 class="text-base font-bold text-gray-900 uppercase">
            XÁC NHẬN HỦY BỎ THAO TÁC
          </h3>

          <p class="text-xs text-gray-600 leading-relaxed">
            Mọi dữ liệu giải trình tạm thời bạn vừa nhập cho biên bản <strong><?= htmlspecialchars($currentAudit['MaBienBanKiemKe']) ?></strong> sẽ bị mất và biên bản sẽ quay lại trạng thái ban đầu.
          </p>

          <div class="grid grid-cols-2 gap-3 pt-3">
            <button type="button" onclick="closeCancelModal()" class="btn-secondary w-full text-xs font-bold py-2.5">
              KHÔNG
            </button>
            <a href="/warehouse/pages/inventory/explain.php" class="btn-danger w-full text-xs font-bold py-2.5 text-center" style="background-color: #DC2626; color: white;">
              XÁC NHẬN HỦY
            </a>
          </div>
        </div>
      </div>

      <!-- MODAL POPUP: THÔNG BÁO KẾT QUẢ THÀNH CÔNG -->
      <div id="resultModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white rounded-xl shadow-2xl border border-gray-200 w-full max-w-md overflow-hidden text-center p-6 space-y-4 animate-in zoom-in-95 duration-200">
          <div class="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto text-3xl">
            <i class="fa-solid fa-circle-check"></i>
          </div>

          <h3 class="text-lg font-bold text-gray-900 uppercase">
            THÔNG BÁO KẾT QUẢ
          </h3>

          <div class="p-3 bg-green-50 rounded border border-green-200 text-green-900 font-bold text-sm">
            GỬI PHÊ DUYỆT THÀNH CÔNG!
          </div>

          <p class="text-xs text-gray-700 leading-relaxed">
            Giải trình chênh lệch kiểm kê của Biên bản: <strong class="text-gray-900"><?= htmlspecialchars($currentAudit['MaBienBanKiemKe']) ?></strong> đã được gửi cho Ban Giám Đốc phê duyệt.
          </p>

          <div class="text-xs text-gray-500 italic py-1 border-t border-b border-gray-100">
            Trạng thái biên bản đã cập nhật: <span class="font-bold text-amber-700">Chờ phê duyệt</span>
          </div>

          <div class="pt-2">
            <a href="/warehouse/pages/inventory/explain.php" class="btn-primary w-full text-center block font-bold py-2.5 text-xs uppercase tracking-wide" style="background-color: #2563EB; border-color: #2563EB;">
              QUAY LẠI DANH SÁCH BIÊN BẢN
            </a>
          </div>
        </div>
      </div>

      <script>
        // Dữ liệu tạm lưu giải trình ở Frontend
        const tempExplanations = {};
        let activeRowId = null;

        // Mở Popover giải trình cho 1 dòng
        function openPopover(rowId, lotCode, itemName) {
          activeRowId = rowId;
          document.getElementById('popoverTitle').textContent = `Giải trình: ${lotCode} (${itemName})`;

          // Reset hoặc nạp lại dữ liệu cũ nếu đã giải trình
          const sel = document.getElementById('popoverReason');
          const txt = document.getElementById('popoverContent');
          clearPopoverError();

          if (tempExplanations[rowId]) {
            sel.value = tempExplanations[rowId].reason_code;
            txt.value = tempExplanations[rowId].content;
          } else {
            sel.value = '';
            txt.value = '';
          }

          // Highlight dòng đang chọn
          document.querySelectorAll('#tableDiffRows tr').forEach(r => r.classList.remove('selected-row'));
          const targetRow = document.getElementById('row_' + rowId);
          if (targetRow) targetRow.classList.add('selected-row');

          document.getElementById('explainPopover').classList.remove('hidden');
          sel.focus();
        }

        function closePopover() {
          document.getElementById('explainPopover').classList.add('hidden');
          activeRowId = null;
        }

        function clearPopoverError() {
          const errBox = document.getElementById('popoverError');
          errBox.classList.add('hidden');
          document.getElementById('popoverReason').classList.remove('border-red-500', 'bg-red-50');
          document.getElementById('popoverContent').classList.remove('border-red-500', 'bg-red-50');
        }

        // Xác nhận giải trình từng dòng (Luồng chính & Luồng thay thế 6.1)
        function confirmRowExplanation() {
          if (!activeRowId) return;

          const sel = document.getElementById('popoverReason');
          const txt = document.getElementById('popoverContent');
          const reasonCode = sel.value.trim();
          const content = txt.value.trim();

          // Kiểm tra thiếu thông tin (Luồng 6.1)
          if (!reasonCode || !content) {
            const errBox = document.getElementById('popoverError');
            const errText = document.getElementById('popoverErrorText');
            errText.textContent = 'Vui lòng chọn nguyên nhân và nhập nội dung giải trình.';
            errBox.classList.remove('hidden');

            if (!reasonCode) sel.classList.add('border-red-500', 'bg-red-50');
            if (!content) txt.classList.add('border-red-500', 'bg-red-50');
            return;
          }

          const opt = sel.options[sel.selectedIndex];
          const reasonName = opt.getAttribute('data-name') || opt.text;

          // Lưu tạm vào object
          tempExplanations[activeRowId] = {
            reason_code: reasonCode,
            reason_name: reasonName,
            content: content
          };

          // Cập nhật UI của dòng trên bảng
          const cellReason = document.getElementById('cell_reason_' + activeRowId);
          const cellContent = document.getElementById('cell_content_' + activeRowId);
          const cellAction = document.getElementById('cell_action_' + activeRowId);
          const rowEl = document.getElementById('row_' + activeRowId);

          if (cellReason) {
            cellReason.innerHTML = `<span class="font-bold text-gray-800 text-xs">${escapeHtml(reasonName)}</span>`;
          }
          if (cellContent) {
            cellContent.innerHTML = `<span class="text-gray-700 text-xs">${escapeHtml(content)}</span>`;
          }
          if (cellAction) {
            cellAction.innerHTML = `
              <div class="inline-flex items-center gap-2">
                <span class="inline-flex items-center gap-1 text-green-700 font-bold text-xs">
                  <i class="fa-solid fa-circle-check text-green-600"></i>
                  Đã giải trình
                </span>
                <button type="button" 
                        onclick="openPopover(${activeRowId}, '${escapeHtml(rowEl.querySelector('td:nth-child(4)').textContent.trim())}', '${escapeHtml(rowEl.querySelector('td:nth-child(3)').textContent.trim())}')"
                        class="text-blue-600 hover:text-blue-800 hover:underline text-xs inline-flex items-center gap-0.5">
                  <i class="fa-solid fa-pen text-[10px]"></i>
                  Sửa
                </button>
              </div>
            `;
          }

          if (rowEl) {
            rowEl.classList.remove('border-red-400', 'bg-red-50');
          }

          closePopover();
        }

        // Bấm ra ngoài popover để đóng
        document.getElementById('explainPopover').addEventListener('click', (e) => {
          if (e.target === document.getElementById('explainPopover')) {
            closePopover();
          }
        });

        // Modal Hủy bỏ
        function openCancelModal() {
          document.getElementById('cancelModal').classList.remove('hidden');
        }
        function closeCancelModal() {
          document.getElementById('cancelModal').classList.add('hidden');
        }

        // Submit Gửi phê duyệt (Luồng 8.1 kiểm tra chưa giải trình hết)
        function submitApproval() {
          const rows = document.querySelectorAll('#tableDiffRows tbody tr[data-id]');
          let unhandledRow = null;

          rows.forEach(r => {
            const rowId = r.getAttribute('data-id');
            if (!tempExplanations[rowId] || !tempExplanations[rowId].reason_code || !tempExplanations[rowId].content) {
              r.classList.add('border-red-400', 'bg-red-50');
              if (!unhandledRow) unhandledRow = r;
            } else {
              r.classList.remove('border-red-400', 'bg-red-50');
            }
          });

          // Luồng thay thế 8.1
          if (unhandledRow) {
            alert('Vui lòng giải trình toàn bộ các dòng hàng hóa chênh lệch trước khi gửi phê duyệt.');
            unhandledRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
          }

          // Toàn bộ dòng đã giải trình -> gửi AJAX
          const btn = document.getElementById('btnSubmitApproval');
          btn.disabled = true;
          btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> ĐANG GỬI...';

          const formData = new FormData();
          formData.append('action', 'submit_explanation');
          formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
          formData.append('ma_bien_ban', '<?= htmlspecialchars($currentAudit['MaBienBanKiemKe']) ?>');

          for (let rowId in tempExplanations) {
            formData.append(`explanations[${rowId}][reason_code]`, tempExplanations[rowId].reason_code);
            formData.append(`explanations[${rowId}][content]`, tempExplanations[rowId].content);
          }

          fetch('/warehouse/pages/inventory/explain.php', {
            method: 'POST',
            body: formData
          })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              // Mở Modal Thông báo kết quả
              document.getElementById('resultModal').classList.remove('hidden');
            } else {
              alert('Lỗi: ' + (data.message || 'Không thể lưu giải trình.'));
              btn.disabled = false;
              btn.innerHTML = '<i class="fa-solid fa-paper-plane mr-1.5"></i> GỬI PHÊ DUYỆT';
            }
          })
          .catch(err => {
            alert('Lỗi kết nối máy chủ: ' + err);
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane mr-1.5"></i> GỬI PHÊ DUYỆT';
          });
        }

        function escapeHtml(text) {
          if (!text) return '';
          return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        }
      </script>

    <?php endif; ?>

  </main>

  <footer class="dashboard-footer">
    © 2026 Hệ Thống Quản Lý Chuỗi Kho &nbsp;·&nbsp; Module Giải Trình Chênh Lệch Kiểm Kê
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
</script>
</body>
</html>

