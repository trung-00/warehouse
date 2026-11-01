<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = getCurrentUser();
$initials = mb_strtoupper(mb_substr($user['name'], 0, 1));
$unreadCount = getUnreadNotificationCount($user['role'], $user['id']);

// Lấy danh sách thông báo mới nhất cho user
$notifications = [];
try {
    $db = getDB();
    $notifStmt = $db->prepare("
        SELECT * FROM notifications 
        WHERE recipient_role = ? OR recipient_id = ?
        ORDER BY created_at DESC LIMIT 5
    ");
    $notifStmt->execute([$user['role'], $user['id']]);
    $notifications = $notifStmt->fetchAll();
} catch (Exception $e) {
    $notifications = [];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trang chủ — Quản Lý Kho</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/warehouse/assets/css/dashboard.css">
</head>
<body>

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside id="sidebar">

  <!-- Logo thương hiệu -->
  <div class="sidebar-brand">
    <img src="/warehouse/assets/images/logo-icon.svg" alt="Logo" class="w-8 h-8 flex-shrink-0" />
    <div class="min-w-0">
      <div class="sidebar-title">Quản Lý Kho</div>
      <div class="sidebar-subtitle">Hệ thống chuỗi kho</div>
    </div>
  </div>

  <!-- Menu thanh điều hướng (Phân quyền chính xác theo vai trò) -->
  <nav class="sidebar-nav">
    <div class="nav-section">Tổng quan</div>
    <a href="/warehouse/pages/dashboard.php" class="nav-item active">
      <i class="fa-solid fa-house"></i>
      <span>Trang chủ</span>
    </a>

    <?php if ($user['role'] === 'staff'): ?>
    <div class="nav-section">Nghiệp vụ xuất kho</div>
    <a href="/warehouse/pages/export/index.php" class="nav-item">
      <i class="fa-solid fa-file-invoice"></i>
      <span>Lập phiếu xuất kho</span>
    </a>

    <div class="nav-section">Sự cố kho</div>
    <a href="/warehouse/pages/incidents/report.php" class="nav-item">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <span>Báo cáo sự cố</span>
    </a>
    <?php endif; ?>

    <?php if ($user['role'] === 'manager'): ?>
    <div class="nav-section">Quản lý kho bãi</div>
    <a href="/warehouse/pages/shelves/index.php" class="nav-item">
      <i class="fa-solid fa-cubes-stacked"></i>
      <span>Quản lý kệ kho</span>
    </a>

    <div class="nav-section">Sự cố kho</div>
    <a href="/warehouse/pages/incidents/handle.php" class="nav-item">
      <i class="fa-solid fa-screwdriver-wrench"></i>
      <span>Xử lý sự cố</span>
    </a>
    <?php endif; ?>

    <?php if ($user['role'] === 'director'): ?>
    <div class="nav-section">Kiểm kê & Phê duyệt</div>
    <a href="/warehouse/pages/inventory/index.php" class="nav-item">
      <i class="fa-solid fa-clipboard-check"></i>
      <span>Phê duyệt kiểm kê</span>
    </a>
    <?php endif; ?>

    <?php if ($user['role'] === 'stocktaking'): ?>
    <div class="nav-section">Kiểm kê kho</div>
    <a href="/warehouse/pages/inventory/create.php" class="nav-item">
      <i class="fa-solid fa-clipboard-check"></i>
      <span>Lập biên bản kiểm kê</span>
    </a>
    <?php endif; ?>

    <?php if ($user['role'] === 'admin'): ?>
    <div class="nav-section">Hệ thống</div>
    <a href="/warehouse/pages/accounts/index.php" class="nav-item">
      <i class="fa-solid fa-users-gear"></i>
      <span>Quản lý tài khoản</span>
    </a>
    <?php endif; ?>
  </nav>

  <!-- Nút đăng xuất sidebar -->
  <div class="sidebar-footer">
    <a href="/warehouse/auth/logout.php" class="nav-item sidebar-logout-btn">
      <i class="fa-solid fa-arrow-right-from-bracket"></i>
      <span>Đăng xuất</span>
    </a>
  </div>
</aside>

<!-- Màn mờ mobile khi mở menu -->
<div id="overlay" onclick="closeSidebar()"></div>

<!-- ═══════════════ MAIN WRAPPER ═══════════════ -->
<div id="main-wrap">

  <!-- Thanh topbar (Trắng #FFFFFF) -->
  <header id="topbar">
    <button onclick="toggleSidebar()" class="lg:hidden p-1.5 rounded hover:bg-gray-100 text-gray-700 mr-1">
      <i class="fa-solid fa-bars text-base"></i>
    </button>

    <!-- Đường dẫn breadcrumb -->
    <div class="flex items-center gap-1.5 text-sm flex-1 min-w-0">
      <i class="fa-solid fa-house text-gray-400 text-xs"></i>
      <span class="text-gray-400">/</span>
      <span class="breadcrumb-label">Trang chủ</span>
    </div>

    <!-- Góc phải topbar -->
    <div class="flex items-center gap-3">
      <!-- Tên kho phụ trách -->
      <div class="hidden sm:flex badge-branch">
        <i class="fa-solid fa-location-dot text-xs" style="color: #B91C1C;"></i>
        <span><?= htmlspecialchars($user['warehouse_name']) ?></span>
      </div>

      <!-- Thông báo chuông -->
      <div class="relative" id="notifWrap">
        <button class="bell-btn" onclick="toggleNotifDrop()">
          <i class="fa-regular fa-bell text-base"></i>
          <?php if ($unreadCount > 0): ?>
            <span class="bell-dot"></span>
          <?php endif; ?>
        </button>

        <div id="notifDrop" class="hidden absolute right-0 top-full mt-2 w-80 bg-white border border-gray-200 rounded-lg z-50 p-3">
          <div class="flex items-center justify-between border-b pb-2 mb-2">
            <span class="text-xs font-bold text-gray-800 uppercase">Thông báo hệ thống</span>
            <span class="text-xs text-gray-500"><?= $unreadCount ?> mới</span>
          </div>
          <?php if (empty($notifications)): ?>
            <p class="text-xs text-gray-500 py-3 text-center">Không có thông báo mới</p>
          <?php else: ?>
            <div class="space-y-2 max-h-60 overflow-y-auto">
              <?php foreach ($notifications as $n): ?>
                <div class="p-2 bg-gray-50 border border-gray-100 rounded text-xs">
                  <p class="font-bold text-gray-800"><?= htmlspecialchars($n['title']) ?></p>
                  <p class="text-gray-600 mt-0.5"><?= htmlspecialchars($n['message']) ?></p>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Dropdown người dùng -->
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

  <!-- ─── Nội dung chính: Trang chủ (Vai trò & Kho phụ trách) ─── -->
  <main class="dashboard-main">

    <!-- Banner chào mừng -->
    <section class="banner-welcome">
      <div class="banner-content">
        <div class="banner-avatar"><?= $initials ?></div>
        <div>
          <p class="banner-greeting">Chào mừng trở lại,</p>
          <h1 class="banner-name"><?= htmlspecialchars($user['name']) ?></h1>
          <span class="<?= getRoleBadgeClass($user['role']) ?> inline-flex items-center gap-1.5 mt-1">
            <i class="fa-solid fa-shield-halved text-[10px]"></i>
            <?= getRoleLabel($user['role']) ?>
          </span>
        </div>
      </div>
    </section>

    <!-- Khối thông tin: Vai trò & Kho phụ trách -->
    <section class="info-grid">

      <!-- Thẻ vai trò -->
      <div class="info-card">
        <div class="info-card-header">
          <div class="info-card-icon">
            <i class="fa-solid fa-shield-halved"></i>
          </div>
          <span class="info-card-label">Vai trò</span>
        </div>
        <p class="info-card-val"><?= getRoleLabel($user['role']) ?></p>
        <span class="<?= getRoleBadgeClass($user['role']) ?> inline-block mt-1">
          <?= strtoupper($user['role']) ?>
        </span>
      </div>

      <!-- Thẻ kho phụ trách -->
      <div class="info-card">
        <div class="info-card-header">
          <div class="info-card-icon">
            <i class="fa-solid fa-warehouse"></i>
          </div>
          <span class="info-card-label">Kho phụ trách</span>
        </div>
        <p class="info-card-val"><?= htmlspecialchars($user['warehouse_name']) ?></p>
        <p class="info-card-desc"><?= $user['warehouse_id'] ? 'Mã kho: ' . htmlspecialchars($user['warehouse_id']) : 'Điều phối toàn chuỗi kho' ?></p>
      </div>

    </section>

  </main>

  <!-- Chân trang -->
  <footer class="dashboard-footer">
    © 2026 Hệ Thống Quản Lý Chuỗi Kho &nbsp;·&nbsp;
    <a href="#">Hỗ trợ kỹ thuật</a>
  </footer>
</div>

<script>
  // Đóng/mở Dropdown thông tin user
  const drop = document.getElementById('userDrop');
  function toggleDrop() {
    drop.style.display = drop.style.display === 'none' || drop.style.display === '' ? 'block' : 'none';
  }
  document.addEventListener('click', (e) => {
    if (!document.getElementById('userDropWrap').contains(e.target)) {
      drop.style.display = 'none';
    }
    if (!document.getElementById('notifWrap').contains(e.target)) {
      document.getElementById('notifDrop').classList.add('hidden');
    }
  });

  function toggleNotifDrop() {
    const nd = document.getElementById('notifDrop');
    nd.classList.toggle('hidden');
  }

  // Đóng/mở sidebar trên thiết bị di động
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
