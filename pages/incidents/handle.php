<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// Phân quyền: Quản lý kho chỉ có chức năng xử lý sự cố, không có chức năng báo cáo sự cố
if (!isManager()) {
    header('Location: /warehouse/pages/dashboard.php');
    exit;
}

$user = getCurrentUser();
$initials = mb_strtoupper(mb_substr($user['name'], 0, 1));
$unreadCount = getUnreadNotificationCount($user['role'], $user['id']);

$db = getDB();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Xử lý các thao tác POST của Quản lý kho
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // BƯỚC 2b: Quản lý kho chọn hướng xử lý và xác nhận
    // Trạng thái: Chuyển từ "Chờ duyệt" sang "Đang thực hiện"
    // Gửi thông báo cho Nhân viên kho
    if ($action === 'assign_solution') {
        $incident_id   = trim($_POST['incident_id'] ?? '');
        $solution      = trim($_POST['solution'] ?? '');
        $solution_note = trim($_POST['solution_note'] ?? '');

        if (empty($incident_id) || empty($solution)) {
            $_SESSION['flash_error'] = 'Vui lòng chọn hướng xử lý cho sự cố.';
        } else {
            try {
                $stmtCheck = $db->prepare("SELECT * FROM incidents WHERE id = ?");
                $stmtCheck->execute([$incident_id]);
                $inc = $stmtCheck->fetch();

                if (!$inc) {
                    $_SESSION['flash_error'] = 'Không tìm thấy sự cố cần xử lý.';
                } else {
                    $stmtUp = $db->prepare("
                        UPDATE incidents 
                        SET solution = ?, solution_note = ?, status = 'Đang thực hiện', resolved_by = ? 
                        WHERE id = ?
                    ");
                    $stmtUp->execute([$solution, $solution_note, $user['id'], $incident_id]);

                    // Bước 3: Gửi thông báo cho Nhân viên kho
                    addNotification(
                        'staff',
                        $inc['reported_by'],
                        $incident_id,
                        "Hướng xử lý sự cố [$incident_id]",
                        "Quản lý đã duyệt hướng xử lý: '{$solution}'. Vui lòng thực hiện xử lý ngoài thực tế và báo hoàn thành."
                    );

                    $_SESSION['flash_success'] = "Đã xác nhận hướng xử lý cho sự cố $incident_id! Trạng thái chuyển sang: Đang thực hiện.";
                }
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Lỗi cập nhật hướng xử lý: ' . $e->getMessage();
            }
        }
        header('Location: /warehouse/pages/incidents/handle.php');
        exit;
    }

    // BƯỚC 7a: Nếu kết quả đạt yêu cầu, quản lý kho xác nhận hoàn thành
    // Trạng thái: Chuyển từ "Chờ xác nhận" sang "Hoàn thành"
    if ($action === 'confirm_completed') {
        $incident_id = trim($_POST['incident_id'] ?? '');

        try {
            $stmtCheck = $db->prepare("SELECT * FROM incidents WHERE id = ?");
            $stmtCheck->execute([$incident_id]);
            $inc = $stmtCheck->fetch();

            if ($inc && $inc['status'] === 'Chờ xác nhận') {
                $stmtUp = $db->prepare("UPDATE incidents SET status = 'Hoàn thành' WHERE id = ?");
                $stmtUp->execute([$incident_id]);

                // Thông báo cho nhân viên
                addNotification(
                    'staff',
                    $inc['reported_by'],
                    $incident_id,
                    "Nghiệm thu hoàn thành [$incident_id]",
                    "Quản lý đã kiểm tra và xác nhận hoàn thành sự cố $incident_id. Cảm ơn bạn!"
                );

                $_SESSION['flash_success'] = "Đã nghiệm thu và xác nhận hoàn thành sự cố $incident_id thành công!";
            } else {
                $_SESSION['flash_error'] = 'Sự cố không ở trạng thái Chờ xác nhận hoặc không tồn tại.';
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Lỗi hệ thống: ' . $e->getMessage();
        }
        header('Location: /warehouse/pages/incidents/handle.php');
        exit;
    }

    // BƯỚC 7b: Nếu kết quả chưa đạt yêu cầu, quản lý kho yêu cầu nhân viên xử lý lại
    // Trạng thái: Chuyển lại về "Đang thực hiện"
    if ($action === 'request_rework') {
        $incident_id    = trim($_POST['incident_id'] ?? '');
        $rejection_note = trim($_POST['rejection_note'] ?? '');

        if (empty($rejection_note)) {
            $_SESSION['flash_error'] = 'Vui lòng nhập lý do chưa đạt và yêu cầu xử lý lại.';
        } else {
            try {
                $stmtCheck = $db->prepare("SELECT * FROM incidents WHERE id = ?");
                $stmtCheck->execute([$incident_id]);
                $inc = $stmtCheck->fetch();

                if ($inc && $inc['status'] === 'Chờ xác nhận') {
                    $stmtUp = $db->prepare("
                        UPDATE incidents 
                        SET status = 'Đang thực hiện', rejection_note = ? 
                        WHERE id = ?
                    ");
                    $stmtUp->execute([$rejection_note, $incident_id]);

                    // Gửi thông báo cho nhân viên
                    addNotification(
                        'staff',
                        $inc['reported_by'],
                        $incident_id,
                        "Yêu cầu xử lý lại sự cố [$incident_id]",
                        "Quản lý chưa nghiệm thu sự cố $incident_id. Lý do: {$rejection_note}. Vui lòng xử lý lại."
                    );

                    $_SESSION['flash_success'] = "Đã gửi yêu cầu xử lý lại tới nhân viên kho cho sự cố $incident_id!";
                } else {
                    $_SESSION['flash_error'] = 'Sự cố không hợp lệ để yêu cầu xử lý lại.';
                }
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Lỗi hệ thống: ' . $e->getMessage();
            }
        }
        header('Location: /warehouse/pages/incidents/handle.php');
        exit;
    }
}

// Lấy danh sách các nhóm sự cố
try {
    // 1. Chờ duyệt (cần chọn hướng xử lý)
    $stmtWaiting = $db->query("
        SELECT i.*, w.name AS warehouse_name, u.full_name AS reporter_name 
        FROM incidents i
        LEFT JOIN warehouses w ON i.warehouse_id = w.id
        LEFT JOIN users u ON i.reported_by = u.id
        WHERE i.status = 'Chờ duyệt'
        ORDER BY i.created_at ASC
    ");
    $waitingIncidents = $stmtWaiting->fetchAll();

    // 2. Chờ xác nhận (cần kiểm tra kết quả hoàn thành của nhân viên)
    $stmtConfirm = $db->query("
        SELECT i.*, w.name AS warehouse_name, u.full_name AS reporter_name 
        FROM incidents i
        LEFT JOIN warehouses w ON i.warehouse_id = w.id
        LEFT JOIN users u ON i.reported_by = u.id
        WHERE i.status = 'Chờ xác nhận'
        ORDER BY i.updated_at ASC
    ");
    $confirmIncidents = $stmtConfirm->fetchAll();

    // 3. Toàn bộ danh sách sự cố
    $stmtAll = $db->query("
        SELECT i.*, w.name AS warehouse_name, u.full_name AS reporter_name 
        FROM incidents i
        LEFT JOIN warehouses w ON i.warehouse_id = w.id
        LEFT JOIN users u ON i.reported_by = u.id
        ORDER BY i.created_at DESC
    ");
    $allIncidents = $stmtAll->fetchAll();
} catch (Exception $e) {
    $waitingIncidents = [];
    $confirmIncidents = [];
    $allIncidents = [];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Xử lý sự cố — Quản Lý Kho</title>
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

    <div class="nav-section">Quản lý kho bãi</div>
    <a href="/warehouse/pages/shelves/index.php" class="nav-item">
      <i class="fa-solid fa-cubes-stacked"></i>
      <span>Quản lý kệ kho</span>
    </a>

    <!-- Quản lý kho có menu Xử lý sự cố -->
    <div class="nav-section">Sự cố kho</div>
    <a href="/warehouse/pages/incidents/handle.php" class="nav-item active">
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
      <span class="breadcrumb-label">Xử lý sự cố</span>
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

    <!-- Action Header -->
    <section class="action-box">
      <div>
        <h3 class="action-title">
          <i class="fa-solid fa-screwdriver-wrench" style="color: #B91C1C;"></i>
          Trung Tâm Xử Lý & Phê Duyệt Sự Cố
        </h3>
        <p class="action-desc">
          Quản lý tiếp nhận báo cáo từ Nhân viên kho, chọn hướng xử lý cho từng loại sự cố và kiểm tra kết quả thực hiện.
        </p>
      </div>

      <!-- Bộ đếm nhanh -->
      <div class="flex items-center gap-3">
        <div class="px-3 py-1.5 bg-gray-100 rounded border border-gray-300 text-xs">
          Chờ duyệt: <strong class="text-red-700"><?= count($waitingIncidents) ?></strong>
        </div>
        <div class="px-3 py-1.5 bg-amber-50 rounded border border-amber-300 text-xs text-amber-800">
          Chờ xác nhận: <strong><?= count($confirmIncidents) ?></strong>
        </div>
      </div>
    </section>

    <!-- ═══════════════ KHU VỰC 1: SỰ CỐ CHỜ DUYỆT (BƯỚC 2a & 2b) ═══════════════ -->
    <section class="table-card mb-6">
      <div class="table-card-header bg-red-50/40">
        <div class="table-card-title text-red-800">
          <i class="fa-solid fa-hourglass-half" style="color: #B91C1C;"></i>
          Báo Cáo Sự Cố Chờ Duyệt Hướng Xử Lý (<?= count($waitingIncidents) ?>)
        </div>
        <span class="text-xs text-gray-500">Quản lý kho chọn hướng xử lý tương ứng cho từng sự cố</span>
      </div>

      <div class="overflow-x-auto">
        <table class="custom-table">
          <thead>
            <tr>
              <th>Mã sự cố</th>
              <th>Ngày báo cáo</th>
              <th>Kho xảy ra</th>
              <th>Mã - Tên lô hàng</th>
              <th>Tên sự cố</th>
              <th>Số lượng</th>
              <th>Mô tả sự cố</th>
              <th class="text-center" style="min-width: 280px;">Hướng xử lý theo quy định</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($waitingIncidents)): ?>
              <tr>
                <td colspan="8" class="text-center py-6 text-gray-500">
                  <i class="fa-solid fa-circle-check text-green-600 mr-1"></i>
                  Hiện không có sự cố nào đang chờ duyệt hướng xử lý.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($waitingIncidents as $inc): 
                $solutions = getIncidentSolutions($inc['incident_name']);
              ?>
                <tr>
                  <!-- Mã sự cố (String) -->
                  <td class="font-bold text-gray-900"><?= htmlspecialchars($inc['id']) ?></td>

                  <!-- Ngày báo cáo -->
                  <td class="text-gray-700 whitespace-nowrap">
                    <?= date('d/m/Y', strtotime($inc['report_date'])) ?>
                  </td>

                  <!-- Kho xảy ra -->
                  <td class="font-medium text-gray-800"><?= htmlspecialchars($inc['warehouse_name']) ?></td>

                  <!-- Mã & Tên lô hàng -->
                  <td>
                    <div class="font-bold text-gray-900"><?= htmlspecialchars($inc['batch_code']) ?></div>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($inc['batch_name']) ?></div>
                  </td>

                  <!-- Tên sự cố -->
                  <td>
                    <span class="font-bold text-red-700"><?= htmlspecialchars($inc['incident_name']) ?></span>
                  </td>

                  <!-- Số lượng ảnh hưởng -->
                  <td class="font-bold whitespace-nowrap">
                    <?= number_format($inc['affected_quantity']) ?> <?= htmlspecialchars($inc['unit']) ?>
                  </td>

                  <!-- Mô tả sự cố -->
                  <td class="text-xs text-gray-600 max-w-xs">
                    <?= htmlspecialchars($inc['description']) ?>
                  </td>

                  <!-- Bước 2b: Combox chọn Hướng xử lý tương ứng và Xác nhận -->
                  <td class="text-center bg-gray-50/60 p-2">
                    <form method="POST" action="/warehouse/pages/incidents/handle.php" class="space-y-2 text-left">
                      <input type="hidden" name="action" value="assign_solution">
                      <input type="hidden" name="incident_id" value="<?= htmlspecialchars($inc['id']) ?>">

                      <div>
                        <select name="solution" class="form-control text-xs font-bold" required>
                          <option value="">-- Chọn hướng xử lý --</option>
                          <?php foreach ($solutions as $sol): ?>
                            <option value="<?= htmlspecialchars($sol) ?>"><?= htmlspecialchars($sol) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div class="flex items-center gap-1.5">
                        <input type="text" name="solution_note" class="form-control text-xs py-1" placeholder="Ghi chú chỉ đạo (nếu có)">
                        <button type="submit" class="btn-primary btn-sm whitespace-nowrap" title="Xác nhận hướng xử lý và chuyển trạng thái Đang thực hiện">
                          <i class="fa-solid fa-check text-xs"></i>
                          Xác nhận
                        </button>
                      </div>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ═══════════════ KHU VỰC 2: SỰ CỐ CHỜ KIỂM TRA XÁC NHẬN (BƯỚC 6, 7a, 7b) ═══════════════ -->
    <section class="table-card mb-6">
      <div class="table-card-header bg-amber-50/40">
        <div class="table-card-title text-amber-800">
          <i class="fa-solid fa-clock-rotate-left" style="color: #D97706;"></i>
          Sự Cố Chờ Nghiệm Thu Kết Quả Xử Lý (<?= count($confirmIncidents) ?>)
        </div>
        <span class="text-xs text-gray-500">Nhân viên kho đã báo hoàn thành thực tế, cần Quản lý kiểm tra xác nhận</span>
      </div>

      <div class="overflow-x-auto">
        <table class="custom-table">
          <thead>
            <tr>
              <th>Mã sự cố</th>
              <th>Kho & Người báo</th>
              <th>Lô hàng</th>
              <th>Sự cố & Hướng xử lý đã duyệt</th>
              <th>Kết quả xử lý thực tế</th>
              <th class="text-center" style="min-width: 220px;">Kiểm tra nghiệm thu</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($confirmIncidents)): ?>
              <tr>
                <td colspan="6" class="text-center py-6 text-gray-500">
                  Không có sự cố nào đang chờ nghiệm thu.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($confirmIncidents as $inc): ?>
                <tr>
                  <td class="font-bold text-gray-900"><?= htmlspecialchars($inc['id']) ?></td>
                  <td>
                    <div class="font-medium text-gray-800"><?= htmlspecialchars($inc['warehouse_name']) ?></div>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($inc['reporter_name']) ?></div>
                  </td>
                  <td>
                    <div class="font-bold text-gray-900"><?= htmlspecialchars($inc['batch_code']) ?></div>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($inc['batch_name']) ?></div>
                  </td>
                  <td>
                    <div class="font-bold text-red-700"><?= htmlspecialchars($inc['incident_name']) ?></div>
                    <div class="text-xs text-gray-700 font-medium mt-0.5">
                      <i class="fa-solid fa-arrow-turn-down text-gray-400 text-[10px]"></i>
                      <?= htmlspecialchars($inc['solution']) ?>
                    </div>
                  </td>
                  <td class="max-w-xs">
                    <div class="p-2 bg-emerald-50 border border-emerald-200 rounded text-xs text-emerald-900 font-medium">
                      <?= htmlspecialchars($inc['completion_note'] ?: 'Đã xử lý xong ngoài thực tế') ?>
                    </div>
                  </td>
                  <td class="text-center whitespace-nowrap">
                    <!-- Bước 7a: Quản lý xác nhận hoàn thành -->
                    <form method="POST" action="/warehouse/pages/incidents/handle.php" class="inline-block mr-1">
                      <input type="hidden" name="action" value="confirm_completed">
                      <input type="hidden" name="incident_id" value="<?= htmlspecialchars($inc['id']) ?>">
                      <button type="submit" class="btn-success btn-sm inline-flex items-center gap-1 font-bold" onclick="return confirm('Bạn xác nhận kết quả xử lý đạt yêu cầu và chuyển sang Hoàn thành?');">
                        <i class="fa-solid fa-circle-check text-xs"></i>
                        Đạt (Hoàn thành)
                      </button>
                    </form>

                    <!-- Bước 7b: Quản lý yêu cầu xử lý lại -->
                    <button type="button" 
                            onclick='openReworkModal(<?= json_encode($inc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                            class="btn-warning btn-sm inline-flex items-center gap-1 font-bold">
                      <i class="fa-solid fa-rotate-left text-xs"></i>
                      Chưa đạt (Làm lại)
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ═══════════════ KHU VỰC 3: TOÀN BỘ DANH SÁCH SỰ CỐ (GIÁM SÁT TỔNG THỂ) ═══════════════ -->
    <section class="table-card">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa-solid fa-list" style="color: #B91C1C;"></i>
          Toàn Bộ Danh Sách Sự Cố Kho (<?= count($allIncidents) ?>)
        </div>
        <div class="text-xs text-gray-500">Giám sát trạng thái chuỗi kho</div>
      </div>

      <div class="overflow-x-auto">
        <table class="custom-table">
          <thead>
            <tr>
              <th>Mã sự cố</th>
              <th>Ngày báo cáo</th>
              <th>Loại hàng</th>
              <th>Mã - Tên lô hàng</th>
              <th>Kho xảy ra</th>
              <th>Tên sự cố</th>
              <th>Số lượng</th>
              <th>Hướng xử lý</th>
              <th>Trạng thái</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allIncidents)): ?>
              <tr>
                <td colspan="9" class="text-center py-6 text-gray-500">Chưa có sự cố nào.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($allIncidents as $inc): ?>
                <tr>
                  <td class="font-bold text-gray-900"><?= htmlspecialchars($inc['id']) ?></td>
                  <td class="text-gray-700 whitespace-nowrap"><?= date('d/m/Y', strtotime($inc['report_date'])) ?></td>
                  <td>
                    <span class="inline-block px-2 py-0.5 rounded text-xs font-bold <?= $inc['product_type'] === 'Thành phẩm' ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-orange-50 text-orange-700 border border-orange-200' ?>">
                      <?= htmlspecialchars($inc['product_type']) ?>
                    </span>
                  </td>
                  <td>
                    <div class="font-bold text-gray-900"><?= htmlspecialchars($inc['batch_code']) ?></div>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($inc['batch_name']) ?></div>
                  </td>
                  <td class="font-medium text-gray-800"><?= htmlspecialchars($inc['warehouse_name']) ?></td>
                  <td><span class="font-bold text-gray-800"><?= htmlspecialchars($inc['incident_name']) ?></span></td>
                  <td class="font-bold text-red-700 whitespace-nowrap"><?= number_format($inc['affected_quantity']) ?> <?= htmlspecialchars($inc['unit']) ?></td>
                  <td>
                    <?php if (!empty($inc['solution'])): ?>
                      <div class="font-bold text-gray-900 text-xs"><?= htmlspecialchars($inc['solution']) ?></div>
                    <?php else: ?>
                      <span class="text-gray-400 italic text-xs">Chưa có hướng xử lý</span>
                    <?php endif; ?>
                  </td>
                  <td><?= renderStatusBadge($inc['status']) ?></td>
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

<!-- ═══════════════ MODAL YÊU CẦU XỬ LÝ LẠI (BƯỚC 7b) ═══════════════ -->
<div id="modalRework" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-box-header">
      <div class="modal-box-title">
        <i class="fa-solid fa-triangle-exclamation" style="color: #D97706;"></i>
        Yêu Cầu Nhân Viên Kho Xử Lý Lại
      </div>
      <button type="button" class="close-btn" onclick="closeReworkModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="/warehouse/pages/incidents/handle.php">
      <input type="hidden" name="action" value="request_rework">
      <input type="hidden" name="incident_id" id="rework_incident_id">

      <div class="modal-box-body space-y-3">
        <div class="p-3 bg-gray-50 border border-gray-200 rounded text-xs space-y-1">
          <div class="font-bold text-gray-800" id="rework_title">Mã sự cố: SC000</div>
          <div class="text-gray-600" id="rework_solution">Hướng xử lý: ...</div>
        </div>

        <div class="form-group">
          <label class="form-label">Lý do chưa đạt yêu cầu & Hướng dẫn làm lại <span class="required">*</span></label>
          <textarea name="rejection_note" rows="4" class="form-control" placeholder="Nhập lý do chưa đạt để nhân viên kho thực hiện lại (Ví dụ: Chưa dán nhãn phân loại, số lượng cách ly chưa đủ so với số lượng báo cáo...)" required></textarea>
        </div>

        <div class="p-3 bg-amber-50 border border-amber-200 rounded text-xs text-amber-800">
          <i class="fa-solid fa-circle-info mr-1"></i>
          Khi gửi yêu cầu này, sự cố sẽ chuyển trạng thái trở lại <strong>"Đang thực hiện"</strong> và hệ thống sẽ gửi thông báo hướng dẫn cho nhân viên phụ trách kho.
        </div>
      </div>

      <div class="modal-box-footer">
        <button type="button" class="btn-secondary" onclick="closeReworkModal()">Hủy</button>
        <button type="submit" class="btn-warning">
          <i class="fa-solid fa-rotate-left text-xs"></i>
          Gửi yêu cầu làm lại
        </button>
      </div>
    </form>
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

  function openReworkModal(inc) {
    document.getElementById('rework_incident_id').value = inc.id;
    document.getElementById('rework_title').textContent = 'Mã sự cố: ' + inc.id + ' — Lô hàng: ' + inc.batch_code + ' (' + inc.batch_name + ')';
    document.getElementById('rework_solution').textContent = 'Hướng xử lý: ' + (inc.solution || 'Chưa có');
    document.getElementById('modalRework').classList.add('active');
  }
  function closeReworkModal() {
    document.getElementById('modalRework').classList.remove('active');
  }
</script>
</body>
</html>

