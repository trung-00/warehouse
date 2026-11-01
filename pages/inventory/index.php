<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// Phân quyền: Đối tượng sử dụng (Actor chính) là Ban Giám Đốc
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

// -------------------------------------------------------------
// TÌM KIẾM & BỘ LỌC
// -------------------------------------------------------------
$searchKeyword = trim($_GET['search'] ?? '');
$filterPhamVi  = trim($_GET['filter_phamvi'] ?? '');
$filterStatus  = trim($_GET['filter_status'] ?? '');

$whereClauses = [];
$params = [];

if (!empty($searchKeyword)) {
    $whereClauses[] = "(b.MaBienBanKiemKe LIKE ? OR b.TenKeHoachKiemKe LIKE ? OR b.MaKeHoachKiemKe LIKE ?)";
    $params[] = "%$searchKeyword%";
    $params[] = "%$searchKeyword%";
    $params[] = "%$searchKeyword%";
}
if (!empty($filterPhamVi)) {
    $whereClauses[] = "b.PhamViKiemKe LIKE ?";
    $params[] = "%$filterPhamVi%";
}
if (!empty($filterStatus)) {
    $whereClauses[] = "b.TrangThai = ?";
    $params[] = $filterStatus;
}

$sql = "
    SELECT b.*, w.name AS TenKho
    FROM BIENBAN_KIEMKE b
    LEFT JOIN warehouses w ON b.MaKho = w.id
";
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}
$sql .= " ORDER BY CASE WHEN b.TrangThai = 'Chờ phê duyệt' THEN 1 WHEN b.TrangThai = 'Đã phê duyệt' THEN 2 ELSE 3 END, b.NgayLap DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$auditList = $stmt->fetchAll();

// Danh sách phạm vi distinct
$phamViList = $db->query("SELECT DISTINCT PhamViKiemKe FROM BIENBAN_KIEMKE ORDER BY PhamViKiemKe ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Phê duyệt chênh lệch kiểm kê — Ban Giám Đốc</title>
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

    <!-- Ban Giám Đốc có menu Phê duyệt kiểm kê -->
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
      <span class="breadcrumb-label">Phê duyệt chênh lệch kiểm kê</span>
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
          <i class="fa-solid fa-clipboard-check" style="color: #B91C1C;"></i>
          Danh Sách Biên Bản Kiểm Kê Cần Duyệt
        </h3>
        <p class="action-desc">
          Ban Giám Đốc tiếp nhận các biên bản có phát sinh chênh lệch kiểm đếm thực tế để xem xét giải trình và phê duyệt điều chỉnh số liệu tồn kho.
        </p>
      </div>
    </section>

    <!-- ═══════════════ THANH TÌM KIẾM & BỘ LỌC ═══════════════ -->
    <section class="table-card p-4 mb-4 bg-white">
      <form method="GET" action="/warehouse/pages/inventory/index.php" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 items-end text-xs">
        <!-- Ô nhập từ khóa tìm kiếm (Mã biên bản, Tên kế hoạch kiểm kê) -->
        <div class="md:col-span-2">
          <label class="font-bold text-gray-700 block mb-1">Tìm kiếm từ khóa:</label>
          <div class="relative">
            <input type="text" name="search" value="<?= htmlspecialchars($searchKeyword) ?>" class="form-control pl-8" placeholder="Nhập mã biên bản, mã / tên kế hoạch...">
            <i class="fa-solid fa-magnifying-glass absolute left-2.5 top-2.5 text-gray-400"></i>
          </div>
        </div>

        <!-- Bộ lọc phạm vi kiểm kê -->
        <div>
          <label class="font-bold text-gray-700 block mb-1">Phạm vi kiểm kê:</label>
          <select name="filter_phamvi" class="form-control">
            <option value="">-- Tất cả phạm vi --</option>
            <?php foreach ($phamViList as $pv): ?>
              <option value="<?= htmlspecialchars($pv) ?>" <?= $filterPhamVi === $pv ? 'selected' : '' ?>>
                <?= htmlspecialchars($pv) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Bộ lọc trạng thái biên bản -->
        <div>
          <label class="font-bold text-gray-700 block mb-1">Trạng thái biên bản:</label>
          <select name="filter_status" class="form-control">
            <option value="">-- Tất cả trạng thái --</option>
            <option value="Chờ phê duyệt" <?= $filterStatus === 'Chờ phê duyệt' ? 'selected' : '' ?>>Chờ phê duyệt</option>
            <option value="Đã phê duyệt" <?= $filterStatus === 'Đã phê duyệt' ? 'selected' : '' ?>>Đã phê duyệt</option>
            <option value="Từ chối" <?= $filterStatus === 'Từ chối' ? 'selected' : '' ?>>Từ chối</option>
          </select>
        </div>

        <!-- Nút Lọc & Xóa bộ lọc -->
        <div class="flex items-center gap-1.5">
          <button type="submit" class="btn-primary" style="padding: 8px 14px; margin-top: 18px;" title="Lọc dữ liệu">
            <i class="fa-solid fa-filter text-xs mr-1"></i>
            Lọc
          </button>
          <?php if (!empty($searchKeyword) || !empty($filterPhamVi) || !empty($filterStatus)): ?>
            <a href="/warehouse/pages/inventory/index.php" class="btn-secondary" style="padding: 8px 12px; margin-top: 18px;" title="Xóa bộ lọc">
              <i class="fa-solid fa-rotate-left text-xs"></i>
            </a>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <!-- ═══════════════ BẢNG DỮ LIỆU DANH SÁCH BIÊN BẢN KIỂM KÊ ═══════════════ -->
    <section class="table-card">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa-solid fa-list" style="color: #B91C1C;"></i>
          Danh Sách Biên Bản Kiểm Kê (<?= count($auditList) ?> biên bản)
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="custom-table">
          <thead>
            <tr>
              <th>Mã biên bản kiểm kê</th>
              <th>Mã kế hoạch</th>
              <th>Tên kế hoạch kiểm kê</th>
              <th>Phạm vi kiểm kê</th>
              <th>Ngày lập</th>
              <th>Trạng thái biên bản</th>
              <th class="text-center" style="min-width: 140px;">Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($auditList)): ?>
              <tr>
                <td colspan="7" class="text-center py-6 text-gray-500">
                  Không tìm thấy biên bản kiểm kê nào.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($auditList as $b): ?>
                <tr>
                  <td class="font-bold text-gray-900"><?= htmlspecialchars($b['MaBienBanKiemKe']) ?></td>
                  <td class="font-mono text-xs text-gray-600"><?= htmlspecialchars($b['MaKeHoachKiemKe']) ?></td>
                  <td class="font-bold text-gray-800"><?= htmlspecialchars($b['TenKeHoachKiemKe']) ?></td>
                  <td class="text-gray-700"><?= htmlspecialchars($b['PhamViKiemKe']) ?></td>
                  <td class="text-gray-700 whitespace-nowrap"><?= date('d/m/Y H:i', strtotime($b['NgayLap'])) ?></td>
                  <td>
                    <?php if ($b['TrangThai'] === 'Chờ phê duyệt'): ?>
                      <span class="status-badge status-processing">
                        <i class="fa-solid fa-hourglass-half text-[10px] mr-1"></i>Chờ phê duyệt
                      </span>
                    <?php elseif ($b['TrangThai'] === 'Đã phê duyệt'): ?>
                      <span class="status-badge status-completed">
                        <i class="fa-solid fa-circle-check text-[10px] mr-1"></i>Đã phê duyệt
                      </span>
                    <?php else: ?>
                      <span class="status-badge status-waiting" style="background: #FEE2E2; color: #991B1B; border-color: #B91C1C;">
                        <i class="fa-solid fa-ban text-[10px] mr-1"></i>Từ chối
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center whitespace-nowrap">
                    <!-- Nút Xem chi tiết / Xử lý -->
                    <a href="/warehouse/pages/inventory/detail.php?id=<?= urlencode($b['MaBienBanKiemKe']) ?>" 
                       class="btn-primary btn-sm inline-flex items-center gap-1 font-bold">
                      <i class="fa-solid fa-eye text-xs"></i>
                      Xem chi tiết / Xử lý
                    </a>
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
</script>
</body>
</html>

