<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// Phân quyền: Quản lý kho là người làm chức năng này
if (!isManager()) {
    header('Location: /warehouse/pages/dashboard.php');
    exit;
}

$user = getCurrentUser();
$initials = mb_strtoupper(mb_substr($user['name'], 0, 1));
$db = getDB();

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// -------------------------------------------------------------
// XỬ LÝ ACTIONS (THÊM, CẬP NHẬT, XÓA KỆ)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. THÊM KỆ
    if ($action === 'create_shelf') {
        $maKe           = trim($_POST['maKe'] ?? '');
        $tenKe          = trim($_POST['tenKe'] ?? '');
        $maKho          = trim($_POST['maKho'] ?? '');
        $khuVuc         = trim($_POST['khuVuc'] ?? '');
        $viTri          = trim($_POST['viTri'] ?? '');
        $loaiKe         = trim($_POST['loaiKe'] ?? '');
        $sucChuaToiDa   = (float)($_POST['sucChuaToiDa'] ?? 0);
        $loaiHangPhuHop = trim($_POST['loaiHangPhuHop'] ?? '');
        $trangThai      = trim($_POST['trangThai'] ?? 'Hoạt động');
        $ghiChu         = trim($_POST['ghiChu'] ?? '');

        // Validation
        if (empty($maKe) || empty($tenKe) || empty($maKho) || empty($khuVuc) || empty($viTri) || empty($loaiKe) || empty($loaiHangPhuHop)) {
            $_SESSION['flash_error'] = 'Vui lòng điền đầy đủ các thông tin bắt buộc.';
        } elseif ($sucChuaToiDa <= 0) {
            $_SESSION['flash_error'] = 'Sức chứa tối đa phải lớn hơn 0.';
        } else {
            // Kiểm tra kho có tồn tại trong hệ thống không
            $stmtCheckKho = $db->prepare("SELECT id FROM warehouses WHERE id = ?");
            $stmtCheckKho->execute([$maKho]);
            if (!$stmtCheckKho->fetch()) {
                $_SESSION['flash_error'] = 'Chỉ được chọn kho tồn tại và đang hoạt động.';
            } else {
                // Kiểm tra trùng mã kệ
                $stmtCheckKe = $db->prepare("SELECT maKe FROM Ke WHERE maKe = ?");
                $stmtCheckKe->execute([$maKe]);
                if ($stmtCheckKe->fetch()) {
                    $_SESSION['flash_error'] = "Mã kệ '{$maKe}' đã tồn tại trong hệ thống. Vui lòng nhập mã kệ khác.";
                } else {
                    try {
                        // Sức chứa hiện tại mặc định bằng 0
                        $stmtInsert = $db->prepare("
                            INSERT INTO Ke (maKe, tenKe, maKho, khuVuc, viTri, loaiKe, sucChuaToiDa, sucChuaHienTai, loaiHangPhuHop, trangThai, ghiChu)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
                        ");
                        $stmtInsert->execute([
                            $maKe, $tenKe, $maKho, $khuVuc, $viTri, $loaiKe, $sucChuaToiDa, $loaiHangPhuHop, $trangThai, $ghiChu
                        ]);
                        $_SESSION['flash_success'] = "Thêm mới kệ '{$tenKe}' ({$maKe}) thành công!";
                    } catch (Exception $e) {
                        $_SESSION['flash_error'] = 'Lỗi hệ thống khi thêm kệ: ' . $e->getMessage();
                    }
                }
            }
        }
        header('Location: /warehouse/pages/shelves/index.php');
        exit;
    }

    // 2. CẬP NHẬT KỆ
    if ($action === 'update_shelf') {
        $maKe           = trim($_POST['maKe'] ?? '');
        $tenKe          = trim($_POST['tenKe'] ?? '');
        $maKho          = trim($_POST['maKho'] ?? '');
        $khuVuc         = trim($_POST['khuVuc'] ?? '');
        $viTri          = trim($_POST['viTri'] ?? '');
        $loaiKe         = trim($_POST['loaiKe'] ?? '');
        $sucChuaToiDa   = (float)($_POST['sucChuaToiDa'] ?? 0);
        $loaiHangPhuHop = trim($_POST['loaiHangPhuHop'] ?? '');
        $trangThai      = trim($_POST['trangThai'] ?? 'Hoạt động');
        $ghiChu         = trim($_POST['ghiChu'] ?? '');

        // Lấy thông tin kệ hiện tại
        $stmtCurrent = $db->prepare("SELECT * FROM Ke WHERE maKe = ?");
        $stmtCurrent->execute([$maKe]);
        $currentShelf = $stmtCurrent->fetch();

        if (!$currentShelf) {
            $_SESSION['flash_error'] = 'Không tìm thấy thông tin kệ cần cập nhật.';
        } elseif (empty($tenKe) || empty($maKho) || empty($khuVuc) || empty($viTri) || empty($loaiKe) || empty($loaiHangPhuHop)) {
            $_SESSION['flash_error'] = 'Vui lòng điền đầy đủ các thông tin bắt buộc.';
        } elseif ($sucChuaToiDa < (float)$currentShelf['sucChuaHienTai']) {
            $_SESSION['flash_error'] = "Sức chứa tối đa mới (" . number_format($sucChuaToiDa) . " kg) không được nhỏ hơn sức chứa hiện tại (" . number_format($currentShelf['sucChuaHienTai']) . " kg).";
        } elseif ($maKho !== $currentShelf['maKho'] && (float)$currentShelf['sucChuaHienTai'] > 0) {
            // Không cho phép chuyển kệ sang kho khác khi kệ đang chứa hàng hóa
            $_SESSION['flash_error'] = "Không cho phép chuyển kệ sang kho khác khi kệ đang chứa hàng hóa (" . number_format($currentShelf['sucChuaHienTai']) . " kg).";
        } else {
            try {
                // Không thay đổi mã kệ và sức chứa hiện tại
                $stmtUpdate = $db->prepare("
                    UPDATE Ke 
                    SET tenKe = ?, maKho = ?, khuVuc = ?, viTri = ?, loaiKe = ?, sucChuaToiDa = ?, loaiHangPhuHop = ?, trangThai = ?, ghiChu = ?
                    WHERE maKe = ?
                ");
                $stmtUpdate->execute([
                    $tenKe, $maKho, $khuVuc, $viTri, $loaiKe, $sucChuaToiDa, $loaiHangPhuHop, $trangThai, $ghiChu, $maKe
                ]);
                $_SESSION['flash_success'] = "Cập nhật thông tin kệ '{$maKe}' thành công!";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Lỗi hệ thống khi cập nhật: ' . $e->getMessage();
            }
        }
        header('Location: /warehouse/pages/shelves/index.php');
        exit;
    }

    // 3. XÓA KỆ / CHUYỂN NGỪNG SỬ DỤNG
    if ($action === 'delete_shelf') {
        $maKe = trim($_POST['maKe'] ?? '');

        $stmtCurrent = $db->prepare("SELECT * FROM Ke WHERE maKe = ?");
        $stmtCurrent->execute([$maKe]);
        $currentShelf = $stmtCurrent->fetch();

        if (!$currentShelf) {
            $_SESSION['flash_error'] = 'Không tìm thấy kệ cần xóa.';
        } elseif ((float)$currentShelf['sucChuaHienTai'] > 0) {
            // Nếu kệ còn hàng, hiển thị thông báo đúng quy định
            $_SESSION['flash_error'] = "Không thể xóa kệ vì kệ vẫn còn hàng hóa (" . number_format($currentShelf['sucChuaHienTai']) . " kg).";
        } else {
            try {
                // Ưu tiên chuyển trạng thái thành "Ngừng sử dụng" thay vì xóa dữ liệu vĩnh viễn
                $stmtDeactivate = $db->prepare("UPDATE Ke SET trangThai = 'Ngừng sử dụng' WHERE maKe = ?");
                $stmtDeactivate->execute([$maKe]);
                $_SESSION['flash_success'] = "Kệ '{$maKe}' đã được chuyển sang trạng thái 'Ngừng sử dụng' thành công.";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Lỗi hệ thống: ' . $e->getMessage();
            }
        }
        header('Location: /warehouse/pages/shelves/index.php');
        exit;
    }
}

// -------------------------------------------------------------
// LỌC VÀ TÌM KIẾM
// -------------------------------------------------------------
$searchKeyword = trim($_GET['search'] ?? '');
$filterKho     = trim($_GET['filter_kho'] ?? '');
$filterKhuVuc  = trim($_GET['filter_khuvuc'] ?? '');
$filterLoaiKe  = trim($_GET['filter_loaike'] ?? '');
$filterTrangThai = trim($_GET['filter_trangthai'] ?? '');

// Xây dựng câu truy vấn
$whereClauses = [];
$params = [];

if (!empty($searchKeyword)) {
    $whereClauses[] = "(k.maKe LIKE ? OR k.tenKe LIKE ?)";
    $params[] = "%$searchKeyword%";
    $params[] = "%$searchKeyword%";
}
if (!empty($filterKho)) {
    $whereClauses[] = "k.maKho = ?";
    $params[] = $filterKho;
}
if (!empty($filterKhuVuc)) {
    $whereClauses[] = "k.khuVuc = ?";
    $params[] = $filterKhuVuc;
}
if (!empty($filterLoaiKe)) {
    $whereClauses[] = "k.loaiKe = ?";
    $params[] = $filterLoaiKe;
}
if (!empty($filterTrangThai)) {
    $whereClauses[] = "k.trangThai = ?";
    $params[] = $filterTrangThai;
}

$sql = "
    SELECT k.*, w.name AS TenKho
    FROM Ke k
    LEFT JOIN warehouses w ON k.maKho = w.id
";
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}
$sql .= " ORDER BY k.maKe ASC";

$stmtShelves = $db->prepare($sql);
$stmtShelves->execute($params);
$shelvesList = $stmtShelves->fetchAll();

// Danh sách kho để đổ vào dropdown
$warehouses = $db->query("SELECT id, name FROM warehouses ORDER BY id ASC")->fetchAll();

// Danh sách khu vực & loại kệ distinct để đổ vào bộ lọc
$khuVucList = $db->query("SELECT DISTINCT khuVuc FROM Ke ORDER BY khuVuc ASC")->fetchAll(PDO::FETCH_COLUMN);
$loaiKeList = $db->query("SELECT DISTINCT loaiKe FROM Ke ORDER BY loaiKe ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Quản lý kệ kho — Quản Lý Kho</title>
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

    <!-- Quản lý kho có menu Quản lý kệ & Xử lý sự cố -->
    <div class="nav-section">Quản lý kho bãi</div>
    <a href="/warehouse/pages/shelves/index.php" class="nav-item active">
      <i class="fa-solid fa-cubes-stacked"></i>
      <span>Quản lý kệ kho</span>
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
      <span class="breadcrumb-label">Quản lý kệ kho</span>
    </div>

    <div class="flex items-center gap-3">
      <div class="hidden sm:flex badge-branch">
        <i class="fa-solid fa-location-dot text-xs" style="color: #B91C1C;"></i>
        <span>Toàn hệ thống (Quản lý)</span>
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

    <!-- Action Header: Tiêu đề & Nút Thêm kệ -->
    <section class="action-box">
      <div>
        <h3 class="action-title">
          <i class="fa-solid fa-cubes-stacked" style="color: #B91C1C;"></i>
          Quản Lý Danh Sách Kệ Lưu Trữ Hàng Hóa
        </h3>
        <p class="action-desc">
          Theo dõi sức chứa, vị trí, khu vực và trạng thái hoạt động của từng kệ trong chuỗi kho.
        </p>
      </div>
      <div>
        <button type="button" onclick="openCreateShelfModal()" class="btn-primary">
          <i class="fa-solid fa-plus text-xs"></i>
          Thêm kệ mới
        </button>
      </div>
    </section>

    <!-- ═══════════════ KHU VỰC TÌM KIẾM & BỘ LỌC ═══════════════ -->
    <section class="table-card p-4 mb-4 bg-white">
      <form method="GET" action="/warehouse/pages/shelves/index.php" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-6 gap-3 items-end text-xs">
        <!-- Tìm kiếm -->
        <div class="md:col-span-2">
          <label class="font-bold text-gray-700 block mb-1">Tìm kiếm mã / tên kệ:</label>
          <div class="relative">
            <input type="text" name="search" value="<?= htmlspecialchars($searchKeyword) ?>" class="form-control pl-8" placeholder="Nhập mã kệ hoặc tên kệ...">
            <i class="fa-solid fa-magnifying-glass absolute left-2.5 top-2.5 text-gray-400"></i>
          </div>
        </div>

        <!-- Lọc theo kho -->
        <div>
          <label class="font-bold text-gray-700 block mb-1">Kho hàng:</label>
          <select name="filter_kho" class="form-control">
            <option value="">-- Tất cả kho --</option>
            <?php foreach ($warehouses as $wh): ?>
              <option value="<?= htmlspecialchars($wh['id']) ?>" <?= $filterKho === $wh['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($wh['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Lọc theo khu vực -->
        <div>
          <label class="font-bold text-gray-700 block mb-1">Khu vực:</label>
          <select name="filter_khuvuc" class="form-control">
            <option value="">-- Tất cả khu vực --</option>
            <?php foreach ($khuVucList as $kv): ?>
              <option value="<?= htmlspecialchars($kv) ?>" <?= $filterKhuVuc === $kv ? 'selected' : '' ?>>
                Khu vực <?= htmlspecialchars($kv) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Lọc theo loại kệ -->
        <div>
          <label class="font-bold text-gray-700 block mb-1">Loại kệ:</label>
          <select name="filter_loaike" class="form-control">
            <option value="">-- Tất cả loại kệ --</option>
            <?php foreach ($loaiKeList as $lk): ?>
              <option value="<?= htmlspecialchars($lk) ?>" <?= $filterLoaiKe === $lk ? 'selected' : '' ?>>
                <?= htmlspecialchars($lk) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Lọc theo trạng thái & Nút Lọc -->
        <div class="flex items-center gap-1.5">
          <div class="flex-1">
            <label class="font-bold text-gray-700 block mb-1">Trạng thái:</label>
            <select name="filter_trangthai" class="form-control">
              <option value="">-- Tất cả --</option>
              <option value="Hoạt động" <?= $filterTrangThai === 'Hoạt động' ? 'selected' : '' ?>>Hoạt động</option>
              <option value="Ngừng sử dụng" <?= $filterTrangThai === 'Ngừng sử dụng' ? 'selected' : '' ?>>Ngừng sử dụng</option>
            </select>
          </div>
          <button type="submit" class="btn-primary" style="padding: 8px 12px; margin-top: 18px;" title="Áp dụng bộ lọc">
            <i class="fa-solid fa-filter text-xs"></i>
          </button>
          <?php if (!empty($searchKeyword) || !empty($filterKho) || !empty($filterKhuVuc) || !empty($filterLoaiKe) || !empty($filterTrangThai)): ?>
            <a href="/warehouse/pages/shelves/index.php" class="btn-secondary" style="padding: 8px 12px; margin-top: 18px;" title="Xóa bộ lọc">
              <i class="fa-solid fa-rotate-left text-xs"></i>
            </a>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <!-- ═══════════════ BẢNG DANH SÁCH KỆ ═══════════════ -->
    <section class="table-card">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa-solid fa-list-check" style="color: #B91C1C;"></i>
          Danh Sách Kệ Hàng Hóa (<?= count($shelvesList) ?> kệ)
        </div>
        <div class="text-xs text-gray-500">
          Đơn vị tính sức chứa: <strong>kg</strong>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="custom-table">
          <thead>
            <tr>
              <th>Mã kệ</th>
              <th>Tên kệ</th>
              <th>Kho</th>
              <th>Khu vực</th>
              <th>Vị trí</th>
              <th>Loại kệ</th>
              <th class="text-right">Tối đa (kg)</th>
              <th class="text-right">Hiện tại (kg)</th>
              <th>Tỷ lệ chứa</th>
              <th>Loại hàng</th>
              <th>Trạng thái</th>
              <th class="text-center" style="min-width: 140px;">Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($shelvesList)): ?>
              <tr>
                <td colspan="12" class="text-center py-6 text-gray-500">
                  Không tìm thấy kệ nào phù hợp với điều kiện tìm kiếm/bộ lọc.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($shelvesList as $s): 
                $percent = ($s['sucChuaToiDa'] > 0) ? min(100, round(($s['sucChuaHienTai'] / $s['sucChuaToiDa']) * 100)) : 0;
              ?>
                <tr>
                  <td class="font-bold text-gray-900"><?= htmlspecialchars($s['maKe']) ?></td>
                  <td class="font-bold text-gray-800"><?= htmlspecialchars($s['tenKe']) ?></td>
                  <td class="text-gray-700"><?= htmlspecialchars($s['TenKho'] ?: $s['maKho']) ?></td>
                  <td class="text-center font-bold text-gray-800"><?= htmlspecialchars($s['khuVuc']) ?></td>
                  <td class="text-gray-800 font-mono text-xs"><?= htmlspecialchars($s['viTri']) ?></td>
                  <td class="text-gray-700"><?= htmlspecialchars($s['loaiKe']) ?></td>
                  <td class="text-right font-bold text-gray-900"><?= number_format($s['sucChuaToiDa'], 0) ?></td>
                  <td class="text-right font-bold text-red-700"><?= number_format($s['sucChuaHienTai'], 0) ?></td>
                  <td style="min-width: 110px;">
                    <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden mb-0.5">
                      <div class="h-2 rounded-full <?= $percent >= 90 ? 'bg-red-700' : ($percent >= 60 ? 'bg-amber-500' : 'bg-green-600') ?>" style="width: <?= $percent ?>%"></div>
                    </div>
                    <span class="text-[10px] text-gray-500 font-bold"><?= $percent ?>% sức chứa</span>
                  </td>
                  <td class="text-gray-800 font-medium"><?= htmlspecialchars($s['loaiHangPhuHop']) ?></td>
                  <td>
                    <?php if ($s['trangThai'] === 'Hoạt động'): ?>
                      <span class="status-badge status-completed">
                        <i class="fa-solid fa-circle-check text-[10px] mr-1"></i>Hoạt động
                      </span>
                    <?php else: ?>
                      <span class="status-badge status-waiting" style="background: #FEE2E2; color: #991B1B; border-color: #B91C1C;">
                        <i class="fa-solid fa-ban text-[10px] mr-1"></i>Ngừng sử dụng
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center whitespace-nowrap">
                    <!-- Nút Cập nhật -->
                    <button type="button" 
                            onclick='openEditShelfModal(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                            class="btn-secondary btn-sm inline-flex items-center gap-1 mr-1"
                            title="Cập nhật thông tin kệ">
                      <i class="fa-solid fa-pen-to-square text-xs text-blue-700"></i>
                      Cập nhật
                    </button>

                    <!-- Nút Xóa -->
                    <button type="button" 
                            onclick='openDeleteShelfModal(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                            class="btn-secondary btn-sm inline-flex items-center gap-1 text-red-700 hover:bg-red-50"
                            title="Xóa kệ hoặc chuyển ngừng sử dụng">
                      <i class="fa-solid fa-trash-can text-xs"></i>
                      Xóa
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

  <footer class="dashboard-footer">
    © 2026 Hệ Thống Quản Lý Chuỗi Kho &nbsp;·&nbsp;
    <a href="#">Hỗ trợ kỹ thuật</a>
  </footer>
</div>

<!-- ═══════════════ MODAL THÊM KỆ MỚI ═══════════════ -->
<div id="modalCreateShelf" class="modal-overlay">
  <div class="modal-box" style="max-width: 600px;">
    <div class="modal-box-header">
      <div class="modal-box-title">
        <i class="fa-solid fa-plus-circle text-red-700"></i>
        Thêm Kệ Lưu Trữ Mới
      </div>
      <button type="button" class="close-btn" onclick="closeCreateShelfModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="/warehouse/pages/shelves/index.php">
      <input type="hidden" name="action" value="create_shelf">

      <div class="modal-box-body space-y-3 text-xs">
        <div class="grid grid-cols-2 gap-3">
          <div class="form-group">
            <label class="form-label">Mã kệ <span class="required">*</span></label>
            <input type="text" name="maKe" class="form-control" placeholder="VD: KTP006" required>
            <span class="text-[11px] text-gray-500">Không cho phép trùng mã kệ</span>
          </div>
          <div class="form-group">
            <label class="form-label">Tên kệ <span class="required">*</span></label>
            <input type="text" name="tenKe" class="form-control" placeholder="VD: Kệ nước tăng lực C2" required>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div class="form-group">
            <label class="form-label">Kho <span class="required">*</span></label>
            <select name="maKho" class="form-control" required>
              <option value="">-- Chọn kho --</option>
              <?php foreach ($warehouses as $wh): ?>
                <option value="<?= htmlspecialchars($wh['id']) ?>">
                  <?= htmlspecialchars($wh['name']) ?> (<?= htmlspecialchars($wh['id']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Khu vực <span class="required">*</span></label>
            <input type="text" name="khuVuc" class="form-control" placeholder="A, B, C..." required>
          </div>
          <div class="form-group">
            <label class="form-label">Vị trí <span class="required">*</span></label>
            <input type="text" name="viTri" class="form-control" placeholder="A-01, B-02..." required>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div class="form-group">
            <label class="form-label">Loại kệ <span class="required">*</span></label>
            <select name="loaiKe" class="form-control" required>
              <option value="Kệ pallet">Kệ pallet</option>
              <option value="Kệ trung tải">Kệ trung tải</option>
              <option value="Kệ tay đỡ">Kệ tay đỡ</option>
              <option value="Kệ sàn">Kệ sàn</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Sức chứa tối đa (kg) <span class="required">*</span></label>
            <input type="number" step="0.01" min="0.01" name="sucChuaToiDa" class="form-control" placeholder="VD: 1200" required>
          </div>
          <div class="form-group">
            <label class="form-label">Sức chứa hiện tại</label>
            <input type="text" class="form-control" value="0 kg (Mặc định)" readonly disabled>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="form-group">
            <label class="form-label">Loại hàng phù hợp <span class="required">*</span></label>
            <input type="text" name="loaiHangPhuHop" class="form-control" placeholder="Nước suối, Nước khoáng..." required>
          </div>
          <div class="form-group">
            <label class="form-label">Trạng thái</label>
            <select name="trangThai" class="form-control">
              <option value="Hoạt động">Hoạt động</option>
              <option value="Ngừng sử dụng">Ngừng sử dụng</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Ghi chú</label>
          <textarea name="ghiChu" rows="2" class="form-control" placeholder="Nhập ghi chú đặc điểm lưu trữ của kệ nếu có..."></textarea>
        </div>
      </div>

      <div class="modal-box-footer">
        <button type="button" class="btn-secondary" onclick="closeCreateShelfModal()">Hủy</button>
        <button type="submit" class="btn-primary">
          <i class="fa-solid fa-plus text-xs mr-1"></i>
          Thêm kệ
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════ MODAL CẬP NHẬT KỆ ═══════════════ -->
<div id="modalEditShelf" class="modal-overlay">
  <div class="modal-box" style="max-width: 600px;">
    <div class="modal-box-header">
      <div class="modal-box-title">
        <i class="fa-solid fa-pen-to-square text-red-700"></i>
        Cập Nhật Thông Tin Kệ: <span id="edit_title_maKe" class="ml-1 text-gray-900"></span>
      </div>
      <button type="button" class="close-btn" onclick="closeEditShelfModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="/warehouse/pages/shelves/index.php">
      <input type="hidden" name="action" value="update_shelf">
      <input type="hidden" name="maKe" id="edit_maKe">

      <div class="modal-box-body space-y-3 text-xs">
        <div class="grid grid-cols-2 gap-3">
          <div class="form-group">
            <label class="form-label">Mã kệ (Không thay đổi)</label>
            <input type="text" id="edit_display_maKe" class="form-control bg-gray-100 cursor-not-allowed font-bold" readonly disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Tên kệ <span class="required">*</span></label>
            <input type="text" name="tenKe" id="edit_tenKe" class="form-control" required>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div class="form-group">
            <label class="form-label">Kho <span class="required">*</span></label>
            <select name="maKho" id="edit_maKho" class="form-control" required>
              <?php foreach ($warehouses as $wh): ?>
                <option value="<?= htmlspecialchars($wh['id']) ?>">
                  <?= htmlspecialchars($wh['name']) ?> (<?= htmlspecialchars($wh['id']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <span class="text-[10px] text-gray-500" id="edit_kho_hint">Không chuyển kho khi kệ đang có hàng</span>
          </div>
          <div class="form-group">
            <label class="form-label">Khu vực <span class="required">*</span></label>
            <input type="text" name="khuVuc" id="edit_khuVuc" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Vị trí <span class="required">*</span></label>
            <input type="text" name="viTri" id="edit_viTri" class="form-control" required>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div class="form-group">
            <label class="form-label">Loại kệ <span class="required">*</span></label>
            <select name="loaiKe" id="edit_loaiKe" class="form-control" required>
              <option value="Kệ pallet">Kệ pallet</option>
              <option value="Kệ trung tải">Kệ trung tải</option>
              <option value="Kệ tay đỡ">Kệ tay đỡ</option>
              <option value="Kệ sàn">Kệ sàn</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Sức chứa tối đa (kg) <span class="required">*</span></label>
            <input type="number" step="0.01" min="0.01" name="sucChuaToiDa" id="edit_sucChuaToiDa" class="form-control" required>
            <span class="text-[10px] text-gray-500" id="edit_max_hint">≥ sức chứa hiện tại</span>
          </div>
          <div class="form-group">
            <label class="form-label">Sức chứa hiện tại (kg)</label>
            <input type="text" id="edit_display_sucChuaHienTai" class="form-control bg-gray-100 cursor-not-allowed font-bold text-red-700" readonly disabled>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="form-group">
            <label class="form-label">Loại hàng phù hợp <span class="required">*</span></label>
            <input type="text" name="loaiHangPhuHop" id="edit_loaiHangPhuHop" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Trạng thái</label>
            <select name="trangThai" id="edit_trangThai" class="form-control">
              <option value="Hoạt động">Hoạt động</option>
              <option value="Ngừng sử dụng">Ngừng sử dụng</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Ghi chú</label>
          <textarea name="ghiChu" id="edit_ghiChu" rows="2" class="form-control"></textarea>
        </div>
      </div>

      <div class="modal-box-footer">
        <button type="button" class="btn-secondary" onclick="closeEditShelfModal()">Hủy</button>
        <button type="submit" class="btn-primary">
          <i class="fa-solid fa-floppy-disk text-xs mr-1"></i>
          Lưu thay đổi
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════ MODAL XÁC NHẬN XÓA KỆ ═══════════════ -->
<div id="modalDeleteShelf" class="modal-overlay">
  <div class="modal-box" style="max-width: 480px;">
    <div class="modal-box-header">
      <div class="modal-box-title text-red-700">
        <i class="fa-solid fa-triangle-exclamation"></i>
        Xác Nhận Xóa / Ngừng Sử Dụng Kệ
      </div>
      <button type="button" class="close-btn" onclick="closeDeleteShelfModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="/warehouse/pages/shelves/index.php">
      <input type="hidden" name="action" value="delete_shelf">
      <input type="hidden" name="maKe" id="delete_maKe">

      <div class="modal-box-body space-y-3 text-xs text-gray-700">
        <p>Bạn có chắc chắn muốn xóa kệ <strong id="delete_display_maKe" class="text-gray-900"></strong> (<span id="delete_display_tenKe"></span>)?</p>

        <div id="delete_warning_box" class="p-3 bg-red-50 border border-red-200 rounded text-red-800 space-y-1 hidden">
          <div class="font-bold flex items-center gap-1">
            <i class="fa-solid fa-ban"></i>
            Không thể xóa kệ vì kệ vẫn còn hàng hóa!
          </div>
          <div>Kệ hiện đang chứa: <strong id="delete_current_qty" class="text-red-700"></strong> kg hàng hóa. Bạn phải chuyển hết hàng hóa ra khỏi kệ trước khi thao tác.</div>
        </div>

        <div id="delete_ok_box" class="p-3 bg-amber-50 border border-amber-200 rounded text-amber-900 space-y-1">
          <div>• Kệ hiện không còn chứa hàng hóa (0 kg).</div>
          <div>• Để đảm bảo toàn vẹn dữ liệu hệ thống, kệ sẽ được chuyển trạng thái sang <strong>"Ngừng sử dụng"</strong>.</div>
        </div>
      </div>

      <div class="modal-box-footer">
        <button type="button" class="btn-secondary" onclick="closeDeleteShelfModal()">Đóng</button>
        <button type="submit" id="delete_submit_btn" class="btn-primary">
          <i class="fa-solid fa-check text-xs mr-1"></i>
          Xác nhận
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // Dropdown user & Sidebar mobile
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

  // Modal Thêm Kệ
  function openCreateShelfModal() {
    document.getElementById('modalCreateShelf').classList.add('active');
  }
  function closeCreateShelfModal() {
    document.getElementById('modalCreateShelf').classList.remove('active');
  }

  // Modal Sửa Kệ
  function openEditShelfModal(shelf) {
    document.getElementById('edit_title_maKe').textContent = shelf.maKe;
    document.getElementById('edit_maKe').value = shelf.maKe;
    document.getElementById('edit_display_maKe').value = shelf.maKe;
    document.getElementById('edit_tenKe').value = shelf.tenKe;
    document.getElementById('edit_maKho').value = shelf.maKho;
    document.getElementById('edit_khuVuc').value = shelf.khuVuc;
    document.getElementById('edit_viTri').value = shelf.viTri;
    document.getElementById('edit_loaiKe').value = shelf.loaiKe;
    document.getElementById('edit_sucChuaToiDa').value = parseFloat(shelf.sucChuaToiDa);
    document.getElementById('edit_display_sucChuaHienTai').value = parseFloat(shelf.sucChuaHienTai) + ' kg';
    document.getElementById('edit_loaiHangPhuHop').value = shelf.loaiHangPhuHop;
    document.getElementById('edit_trangThai').value = shelf.trangThai;
    document.getElementById('edit_ghiChu').value = shelf.ghiChu || '';

    // Khóa chuyển kho nếu đang có hàng
    const editMaKhoSelect = document.getElementById('edit_maKho');
    if (parseFloat(shelf.sucChuaHienTai) > 0) {
      editMaKhoSelect.style.pointerEvents = 'none';
      editMaKhoSelect.style.backgroundColor = '#F3F4F6';
      document.getElementById('edit_kho_hint').textContent = 'Kệ đang có hàng (' + shelf.sucChuaHienTai + ' kg) - Không thể chuyển kho';
      document.getElementById('edit_kho_hint').classList.add('text-red-600');
    } else {
      editMaKhoSelect.style.pointerEvents = 'auto';
      editMaKhoSelect.style.backgroundColor = '#FFFFFF';
      document.getElementById('edit_kho_hint').textContent = 'Có thể chuyển sang kho khác';
      document.getElementById('edit_kho_hint').classList.remove('text-red-600');
    }

    document.getElementById('edit_sucChuaToiDa').min = parseFloat(shelf.sucChuaHienTai);
    document.getElementById('edit_max_hint').textContent = 'Tối thiểu ' + shelf.sucChuaHienTai + ' kg (bằng hoặc lớn hơn hiện tại)';

    document.getElementById('modalEditShelf').classList.add('active');
  }
  function closeEditShelfModal() {
    document.getElementById('modalEditShelf').classList.remove('active');
  }

  // Modal Xóa Kệ
  function openDeleteShelfModal(shelf) {
    document.getElementById('delete_maKe').value = shelf.maKe;
    document.getElementById('delete_display_maKe').textContent = shelf.maKe;
    document.getElementById('delete_display_tenKe').textContent = shelf.tenKe;

    const currentQty = parseFloat(shelf.sucChuaHienTai);
    const warningBox = document.getElementById('delete_warning_box');
    const okBox = document.getElementById('delete_ok_box');
    const submitBtn = document.getElementById('delete_submit_btn');

    if (currentQty > 0) {
      document.getElementById('delete_current_qty').textContent = currentQty;
      warningBox.classList.remove('hidden');
      okBox.classList.add('hidden');
      submitBtn.disabled = true;
      submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
    } else {
      warningBox.classList.add('hidden');
      okBox.classList.remove('hidden');
      submitBtn.disabled = false;
      submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    }

    document.getElementById('modalDeleteShelf').classList.add('active');
  }
  function closeDeleteShelfModal() {
    document.getElementById('modalDeleteShelf').classList.remove('active');
  }
</script>
</body>
</html>

