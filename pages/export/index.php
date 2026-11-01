<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/export_helper.php';
requireLogin();

// Phân quyền: Nhân viên kho thực hiện chức năng Lập phiếu xuất kho
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

// Lấy danh sách yêu cầu xuất kho được phân công cho kho mà nhân viên đang quản lý
try {
    $stmt = $db->prepare("
        SELECT 
            p.MaPhieuYeuCau,
            p.LoaiYeuCau,
            p.NgayYeuCau,
            p.BoPhanGui,
            p.TrangThaiYeuCau,
            p.MaKho,
            k.TenKho,
            px.MaPhieu AS DaCoPhieuXuat,
            (SELECT COUNT(*) FROM CHITIETPHIEUYEUCAUKHO ct WHERE ct.MaPhieuYeuCau = p.MaPhieuYeuCau) AS SoMatHang
        FROM PHIEUYEUCAUKHO p
        JOIN KHO k ON p.MaKho = k.MaKho
        LEFT JOIN PHIEUXUATKHO px ON p.MaPhieuYeuCau = px.MaPhieuYeuCau
        WHERE p.MaKho = ?
        ORDER BY p.NgayYeuCau DESC
    ");
    $stmt->execute([$maKhoNhanVien]);
    $yeuCauList = $stmt->fetchAll();
} catch (Exception $e) {
    $yeuCauList = [];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lập phiếu xuất kho — Quản Lý Kho</title>
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
      <span class="breadcrumb-label">Lập phiếu xuất kho</span>
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
    <?php if ($flash_success): ?>
      <div class="alert-box alert-success">
        <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0"></i>
        <span><?= $flash_success ?></span>
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
          <i class="fa-solid fa-file-invoice" style="color: #B91C1C;"></i>
          Danh Sách Yêu Cầu Xuất Kho
        </h3>
        <p class="action-desc">
          Các yêu cầu xuất kho được phân công cho <strong><?= htmlspecialchars($user['warehouse_name']) ?></strong>. Nhân viên chọn yêu cầu để xem chi tiết và lập phiếu xuất kho.
        </p>
      </div>
      <div>
        <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-gray-100 rounded text-xs font-bold text-gray-700 border border-gray-200">
          <i class="fa-solid fa-warehouse text-red-700"></i>
          Mã kho: <?= htmlspecialchars($maKhoNhanVien) ?>
        </span>
      </div>
    </section>

    <!-- Bảng danh sách yêu cầu xuất kho -->
    <section class="table-card">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa-solid fa-list-check" style="color: #B91C1C;"></i>
          Danh Sách Yêu Cầu Xuất Kho Cần Xử Lý (<?= count($yeuCauList) ?> yêu cầu)
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="custom-table">
          <thead>
            <tr>
              <th>Mã phiếu yêu cầu</th>
              <th>Ngày yêu cầu</th>
              <th>Bộ phận gửi</th>
              <th>Kho xuất</th>
              <th>Trạng thái yêu cầu</th>
              <th class="text-center" style="min-width: 180px;">Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($yeuCauList)): ?>
              <tr>
                <td colspan="6" class="text-center py-6 text-gray-500">
                  Không có yêu cầu xuất kho nào được phân công cho kho này.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($yeuCauList as $yc): ?>
                <tr>
                  <td class="font-bold text-gray-900"><?= htmlspecialchars($yc['MaPhieuYeuCau']) ?></td>
                  <td class="text-gray-700 whitespace-nowrap">
                    <?= date('d/m/Y H:i', strtotime($yc['NgayYeuCau'])) ?>
                  </td>
                  <td class="font-medium text-gray-800"><?= htmlspecialchars($yc['BoPhanGui']) ?></td>
                  <td class="font-medium text-gray-800"><?= htmlspecialchars($yc['TenKho']) ?> (<?= htmlspecialchars($yc['MaKho']) ?>)</td>
                  <td>
                    <?php if ($yc['TrangThaiYeuCau'] === 'Chờ duyệt'): ?>
                      <span class="status-badge status-processing">
                        <i class="fa-solid fa-hourglass-half text-[10px] mr-1"></i>Chờ duyệt
                      </span>
                    <?php elseif ($yc['TrangThaiYeuCau'] === 'Đã duyệt'): ?>
                      <span class="status-badge status-completed">
                        <i class="fa-solid fa-circle-check text-[10px] mr-1"></i>Đã duyệt
                      </span>
                    <?php else: ?>
                      <span class="status-badge status-waiting" style="color: #991B1B; background: #FEE2E2; border-color: #B91C1C;">
                        <i class="fa-solid fa-circle-xmark text-[10px] mr-1"></i>Từ chối
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center whitespace-nowrap">
                    <!-- Thao tác xem chi tiết và lập phiếu -->
                    <?php if ($yc['TrangThaiYeuCau'] === 'Từ chối'): ?>
                      <button type="button" class="btn-secondary btn-sm opacity-60 cursor-not-allowed inline-flex items-center gap-1" title="Yêu cầu đã bị từ chối, không được phép lập phiếu">
                        <i class="fa-solid fa-ban text-xs text-red-600"></i>
                        Đã từ chối
                      </button>
                    <?php elseif (!empty($yc['DaCoPhieuXuat'])): ?>
                      <span class="inline-flex items-center gap-1 text-xs font-bold text-green-700 bg-green-50 px-2.5 py-1 rounded border border-green-200">
                        <i class="fa-solid fa-check-double text-xs"></i>
                        Đã lập: <?= htmlspecialchars($yc['DaCoPhieuXuat']) ?>
                      </span>
                    <?php elseif ($yc['TrangThaiYeuCau'] === 'Đã duyệt'): ?>
                      <a href="/warehouse/pages/export/create.php?id=<?= urlencode($yc['MaPhieuYeuCau']) ?>" 
                         class="btn-primary btn-sm inline-flex items-center gap-1">
                        <i class="fa-solid fa-file-pen text-xs"></i>
                        Lập phiếu xuất kho
                      </a>
                    <?php else: ?>
                      <button type="button" class="btn-secondary btn-sm opacity-60 cursor-not-allowed inline-flex items-center gap-1" title="Chờ quản lý duyệt yêu cầu trước khi lập phiếu">
                        <i class="fa-solid fa-hourglass-half text-xs text-amber-600"></i>
                        Chờ duyệt
                      </button>
                    <?php endif; ?>
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

