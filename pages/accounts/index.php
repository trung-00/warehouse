<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// Phân quyền: Quản trị viên
if (!isAdmin()) {
    header('Location: /warehouse/pages/dashboard.php');
    exit;
}

$user = getCurrentUser();
$initials = mb_strtoupper(mb_substr($user['name'], 0, 1));
$db = getDB();

try {
    $stmtUsers = $db->query("
        SELECT u.*, w.name AS warehouse_name 
        FROM users u 
        LEFT JOIN warehouses w ON u.warehouse_id = w.id 
        ORDER BY u.created_at ASC
    ");
    $userList = $stmtUsers->fetchAll();
} catch (Exception $e) {
    $userList = [];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Quản lý tài khoản — Quản Trị Hệ Thống</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/warehouse/assets/css/dashboard.css">
</head>
<body>

<!-- SIDEBAR -->
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

    <div class="nav-section">Hệ thống</div>
    <a href="/warehouse/pages/accounts/index.php" class="nav-item active">
      <i class="fa-solid fa-users-gear"></i>
      <span>Quản lý tài khoản</span>
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

<!-- MAIN WRAPPER -->
<div id="main-wrap">
  <header id="topbar">
    <button onclick="toggleSidebar()" class="lg:hidden p-1.5 rounded hover:bg-gray-100 text-gray-700 mr-1">
      <i class="fa-solid fa-bars text-base"></i>
    </button>

    <div class="flex items-center gap-1.5 text-sm flex-1 min-w-0">
      <a href="/warehouse/pages/dashboard.php" class="text-gray-400 hover:text-red-700">
        <i class="fa-solid fa-house text-xs"></i>
      </a>
      <span class="text-gray-400">/</span>
      <span class="breadcrumb-label">Quản lý tài khoản</span>
    </div>

    <div class="flex items-center gap-3">
      <div class="hidden sm:flex badge-branch">
        <i class="fa-solid fa-shield-halved text-xs" style="color: #B91C1C;"></i>
        <span>Quản trị viên</span>
      </div>

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

  <main class="dashboard-main dashboard-main-wide">
    <section class="action-box">
      <div>
        <h3 class="action-title">
          <i class="fa-solid fa-users-gear" style="color: #B91C1C;"></i>
          Danh Sách Tài Khoản Hệ Thống
        </h3>
        <p class="action-desc">
          Xem danh mục người dùng và phân quyền kho phụ trách trong hệ thống.
        </p>
      </div>
    </section>

    <section class="table-card">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa-solid fa-id-card" style="color: #B91C1C;"></i>
          Tài Khoản Hiện Có (<?= count($userList) ?>)
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="custom-table">
          <thead>
            <tr>
              <th>Mã người dùng</th>
              <th>Họ và tên</th>
              <th>Tên đăng nhập</th>
              <th>Vai trò</th>
              <th>Kho phụ trách</th>
              <th>Trạng thái</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($userList as $u): ?>
              <tr>
                <td class="font-bold text-gray-900"><?= htmlspecialchars($u['id']) ?></td>
                <td class="font-bold text-gray-800"><?= htmlspecialchars($u['full_name']) ?></td>
                <td class="text-gray-700"><?= htmlspecialchars($u['username']) ?></td>
                <td>
                  <span class="<?= getRoleBadgeClass($u['role']) ?>">
                    <?= getRoleLabel($u['role']) ?>
                  </span>
                </td>
                <td class="font-medium text-gray-800">
                  <?= $u['warehouse_name'] ? htmlspecialchars($u['warehouse_name']) : '<span class="text-gray-400 italic">Toàn hệ thống</span>' ?>
                </td>
                <td>
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-green-50 text-green-700 border border-green-200">
                    <i class="fa-solid fa-circle-check text-[10px] mr-1"></i>
                    Đang hoạt động
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
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

