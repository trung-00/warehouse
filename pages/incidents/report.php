<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// Phân quyền: Nhân viên kho chỉ có chức năng báo cáo sự cố
if (!isStaff()) {
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

// Xử lý POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // BƯỚC 1: Nhân viên tạo và gửi báo cáo sự cố (Trạng thái: Chờ duyệt)
    if ($action === 'create_incident') {
        $batch_code        = trim($_POST['batch_code'] ?? '');
        $batch_name        = trim($_POST['batch_name'] ?? '');
        $unit              = trim($_POST['unit'] ?? '');
        $incident_name     = trim($_POST['incident_name'] ?? '');
        $affected_quantity = (int)($_POST['affected_quantity'] ?? 0);
        $description       = trim($_POST['description'] ?? '');

        if (empty($batch_code) || empty($batch_name) || empty($unit) || empty($incident_name) || $affected_quantity <= 0) {
            $_SESSION['flash_error'] = 'Vui lòng điền đầy đủ các thông tin bắt buộc và số lượng lớn hơn 0.';
        } else {
            try {
                // Tự sinh mã sự cố dạng chuỗi: SC011, SC012...
                $stmtMax = $db->query("SELECT id FROM incidents WHERE id LIKE 'SC%' ORDER BY id DESC LIMIT 1");
                $lastId = $stmtMax->fetchColumn();
                $nextNum = 1;
                if ($lastId && preg_match('/SC(\d+)/', $lastId, $matches)) {
                    $nextNum = (int)$matches[1] + 1;
                }
                $newId = 'SC' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

                // Loại hàng tự phát sinh (Kho Thành Phẩm -> Thành phẩm, Kho Nguyên Liệu -> Nguyên liệu)
                $warehouseId = $user['warehouse_id'] ?? 'KTP01';
                $productType = (strpos($warehouseId, 'KNL') === 0) ? 'Nguyên liệu' : 'Thành phẩm';
                $reportDate  = date('Y-m-d');

                $stmtInsert = $db->prepare("
                    INSERT INTO incidents (
                        id, report_date, product_type, batch_code, batch_name, unit,
                        warehouse_id, incident_name, affected_quantity, description,
                        solution, status, reported_by
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        NULL, 'Chờ duyệt', ?
                    )
                ");
                $stmtInsert->execute([
                    $newId, $reportDate, $productType, $batch_code, $batch_name, $unit,
                    $warehouseId, $incident_name, $affected_quantity, $description,
                    $user['id']
                ]);

                // Bước 3: Gửi thông báo cho Quản lý kho
                addNotification(
                    'manager',
                    null,
                    $newId,
                    "Sự cố mới cần duyệt [$newId]",
                    "Nhân viên {$user['name']} vừa báo cáo sự cố '{$incident_name}' tại {$user['warehouse_name']}. Trạng thái: Chờ duyệt."
                );

                $_SESSION['flash_success'] = "Tạo báo cáo sự cố thành công! Mã sự cố: $newId (Trạng thái: Chờ duyệt).";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Lỗi hệ thống khi lưu báo cáo: ' . $e->getMessage();
            }
        }
        header('Location: /warehouse/pages/incidents/report.php');
        exit;
    }

    // BƯỚC 5: Nhân viên bấm nút "Báo hoàn thành" khi sự cố ở trạng thái "Đang thực hiện"
    // Chuyển sang trạng thái "Chờ xác nhận" và gửi thông báo cho Quản lý kho
    if ($action === 'report_complete') {
        $incident_id     = trim($_POST['incident_id'] ?? '');
        $completion_note = trim($_POST['completion_note'] ?? '');

        try {
            $stmtCheck = $db->prepare("SELECT * FROM incidents WHERE id = ?");
            $stmtCheck->execute([$incident_id]);
            $inc = $stmtCheck->fetch();

            if (!$inc) {
                $_SESSION['flash_error'] = 'Không tìm thấy sự cố cần báo hoàn thành.';
            } elseif ($inc['status'] !== 'Đang thực hiện') {
                $_SESSION['flash_error'] = 'Chỉ sự cố đang ở trạng thái "Đang thực hiện" mới có thể báo hoàn thành.';
            } else {
                $stmtUp = $db->prepare("
                    UPDATE incidents 
                    SET status = 'Chờ xác nhận', completion_note = ? 
                    WHERE id = ?
                ");
                $stmtUp->execute([$completion_note, $incident_id]);

                // Gửi thông báo cho Quản lý kho
                addNotification(
                    'manager',
                    null,
                    $incident_id,
                    "Báo hoàn thành xử lý [$incident_id]",
                    "Nhân viên {$user['name']} đã thực hiện xong và báo hoàn thành sự cố $incident_id. Vui lòng kiểm tra và xác nhận."
                );

                $_SESSION['flash_success'] = "Đã gửi báo hoàn thành cho sự cố $incident_id! Trạng thái hiện tại: Chờ xác nhận.";
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Lỗi khi cập nhật trạng thái: ' . $e->getMessage();
        }
        header('Location: /warehouse/pages/incidents/report.php');
        exit;
    }
}

// Lấy danh sách sự cố của kho nhân viên phụ trách
try {
    $stmtIncidents = $db->prepare("
        SELECT i.*, w.name AS warehouse_name, u.full_name AS reporter_name 
        FROM incidents i
        LEFT JOIN warehouses w ON i.warehouse_id = w.id
        LEFT JOIN users u ON i.reported_by = u.id
        WHERE i.warehouse_id = ?
        ORDER BY i.created_at DESC
    ");
    $stmtIncidents->execute([$user['warehouse_id'] ?? 'KTP01']);
    $incidents = $stmtIncidents->fetchAll();
} catch (Exception $e) {
    $incidents = [];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Báo cáo sự cố — Quản Lý Kho</title>
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
    <a href="/warehouse/pages/export/index.php" class="nav-item">
      <i class="fa-solid fa-file-invoice"></i>
      <span>Lập phiếu xuất kho</span>
    </a>

    <!-- Nhân viên kho chỉ có menu Báo cáo sự cố -->
    <div class="nav-section">Sự cố kho</div>
    <a href="/warehouse/pages/incidents/report.php" class="nav-item active">
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
      <span class="breadcrumb-label">Báo cáo sự cố</span>
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

    <!-- Thông báo Flash nếu có -->
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

    <!-- Khung Action: Tiêu đề & Nút Báo cáo sự cố (Đỏ đậm #B91C1C) -->
    <section class="action-box">
      <div>
        <h3 class="action-title">
          <i class="fa-solid fa-triangle-exclamation" style="color: #B91C1C;"></i>
          Báo Cáo Sự Cố Kho Hàng
        </h3>
        <p class="action-desc">
          Nhân viên phát hiện sự cố hàng hóa tại <?= htmlspecialchars($user['warehouse_name']) ?> có thể tạo báo cáo gửi tới Quản lý kho.
        </p>
      </div>

      <div>
        <button type="button" onclick="openCreateModal()" class="btn-primary">
          <i class="fa-solid fa-plus text-sm"></i>
          Báo cáo sự cố
        </button>
      </div>
    </section>

    <!-- Bảng danh sách các báo cáo sự cố trước đây -->
    <section class="table-card">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa-solid fa-list-check" style="color: #B91C1C;"></i>
          Danh Sách Báo Cáo Sự Cố Trước Đây (<?= count($incidents) ?> sự cố)
        </div>
        <div class="text-xs text-gray-500">
          Kho: <strong class="text-gray-800"><?= htmlspecialchars($user['warehouse_name']) ?></strong>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="custom-table">
          <thead>
            <tr>
              <th>Mã sự cố</th>
              <th>Ngày báo cáo</th>
              <th>Loại hàng</th>
              <th>Mã lô hàng – Tên lô hàng</th>
              <th>Kho xảy ra sự cố</th>
              <th>Tên sự cố</th>
              <th>Số lượng ảnh hưởng</th>
              <th>Hướng xử lý</th>
              <th>Trạng thái</th>
              <th class="text-center">Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($incidents)): ?>
              <tr>
                <td colspan="10" class="text-center py-6 text-gray-500">
                  Chưa có sự cố nào được ghi nhận tại kho này.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($incidents as $inc): ?>
                <tr>
                  <!-- Mã sự cố (String) -->
                  <td class="font-bold text-gray-900"><?= htmlspecialchars($inc['id']) ?></td>

                  <!-- Ngày báo cáo -->
                  <td class="text-gray-700 whitespace-nowrap">
                    <?= date('d/m/Y', strtotime($inc['report_date'])) ?>
                  </td>

                  <!-- Loại hàng -->
                  <td>
                    <span class="inline-block px-2 py-0.5 rounded text-xs font-bold <?= $inc['product_type'] === 'Thành phẩm' ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-orange-50 text-orange-700 border border-orange-200' ?>">
                      <?= htmlspecialchars($inc['product_type']) ?>
                    </span>
                  </td>

                  <!-- Mã lô hàng – Tên lô hàng -->
                  <td>
                    <div class="font-bold text-gray-900"><?= htmlspecialchars($inc['batch_code']) ?></div>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($inc['batch_name']) ?></div>
                  </td>

                  <!-- Kho xảy ra sự cố -->
                  <td class="text-gray-800 font-medium"><?= htmlspecialchars($inc['warehouse_name']) ?></td>

                  <!-- Tên sự cố: Rò rỉ nước, Gần hết hạn, Móp méo -->
                  <td>
                    <span class="font-bold text-gray-800"><?= htmlspecialchars($inc['incident_name']) ?></span>
                  </td>

                  <!-- Số lượng ảnh hưởng: Int + Đơn vị tính -->
                  <td class="font-bold text-red-700 whitespace-nowrap">
                    <?= number_format($inc['affected_quantity']) ?> <?= htmlspecialchars($inc['unit']) ?>
                  </td>

                  <!-- Hướng xử lý: combox theo tên sự cố -->
                  <td>
                    <?php if (!empty($inc['solution'])): ?>
                      <div class="font-bold text-gray-900 text-xs flex items-center gap-1">
                        <i class="fa-solid fa-arrow-turn-down text-gray-400 text-[10px]"></i>
                        <?= htmlspecialchars($inc['solution']) ?>
                      </div>
                      <?php if (!empty($inc['solution_note'])): ?>
                        <div class="text-[11px] text-gray-500 italic mt-0.5"><?= htmlspecialchars($inc['solution_note']) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-gray-400 italic text-xs">Chưa có hướng xử lý</span>
                    <?php endif; ?>
                  </td>

                  <!-- Trạng thái -->
                  <td>
                    <?= renderStatusBadge($inc['status']) ?>
                  </td>

                  <!-- Thao tác: Báo hoàn thành (khi Đang thực hiện), Xem chi tiết -->
                  <td class="text-center whitespace-nowrap">
                    <?php if ($inc['status'] === 'Đang thực hiện'): ?>
                      <!-- Bước 5: Nhân viên bấm nút "Báo hoàn thành" -->
                      <button type="button" 
                              onclick='openCompleteModal(<?= json_encode($inc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                              class="btn-primary btn-sm inline-flex items-center gap-1 mr-1">
                        <i class="fa-solid fa-check text-xs"></i>
                        Báo hoàn thành
                      </button>
                    <?php endif; ?>

                    <button type="button" 
                            onclick='openDetailModal(<?= json_encode($inc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                            class="btn-secondary btn-sm inline-flex items-center gap-1">
                      <i class="fa-regular fa-eye text-xs"></i>
                      Chi tiết
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

  <!-- Footer -->
  <footer class="dashboard-footer">
    © 2026 Hệ Thống Quản Lý Chuỗi Kho &nbsp;·&nbsp;
    <a href="#">Hỗ trợ kỹ thuật</a>
  </footer>
</div>

<!-- ═══════════════ MODAL TẠO BÁO CÁO SỰ CỐ (BƯỚC 1) ═══════════════ -->
<div id="modalCreate" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-box-header">
      <div class="modal-box-title">
        <i class="fa-solid fa-triangle-exclamation" style="color: #B91C1C;"></i>
        Tạo Báo Cáo Sự Cố Mới
      </div>
      <button type="button" class="close-btn" onclick="closeCreateModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="/warehouse/pages/incidents/report.php">
      <input type="hidden" name="action" value="create_incident">

      <div class="modal-box-body space-y-3">
        <!-- Thông tin kho & loại hàng -->
        <div class="grid grid-cols-2 gap-3 bg-gray-50 p-3 rounded border border-gray-200 text-xs">
          <div>
            <span class="text-gray-500 font-bold block">Kho xảy ra sự cố:</span>
            <span class="text-gray-800 font-bold"><?= htmlspecialchars($user['warehouse_name']) ?> (<?= htmlspecialchars($user['warehouse_id']) ?>)</span>
          </div>
          <div>
            <span class="text-gray-500 font-bold block">Loại hàng:</span>
            <span class="text-gray-800 font-bold">Thành phẩm</span>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <!-- Mã lô hàng -->
          <div class="form-group">
            <label class="form-label">Mã lô hàng <span class="required">*</span></label>
            <input type="text" name="batch_code" class="form-control" placeholder="Ví dụ: LH-TP-011" required>
          </div>

          <!-- Tên lô hàng -->
          <div class="form-group">
            <label class="form-label">Tên lô hàng <span class="required">*</span></label>
            <input type="text" name="batch_name" class="form-control" placeholder="Ví dụ: Sữa tiệt trùng 180ml" required>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <!-- Đơn vị tính -->
          <div class="form-group">
            <label class="form-label">Đơn vị tính <span class="required">*</span></label>
            <input type="text" name="unit" class="form-control" placeholder="Thùng, Hộp, Chai..." required>
          </div>

          <!-- Tên sự cố: List (Rò rỉ nước, gần hết hạn, móp méo) -->
          <div class="form-group">
            <label class="form-label">Tên sự cố <span class="required">*</span></label>
            <select name="incident_name" class="form-control" required>
              <option value="">-- Chọn sự cố --</option>
              <option value="Rò rỉ nước">Rò rỉ nước</option>
              <option value="Gần hết hạn">Gần hết hạn</option>
              <option value="Móp méo">Móp méo</option>
            </select>
          </div>

          <!-- Số lượng bị ảnh hưởng -->
          <div class="form-group">
            <label class="form-label">Số lượng ảnh hưởng <span class="required">*</span></label>
            <input type="number" name="affected_quantity" min="1" class="form-control" placeholder="Số lượng" required>
          </div>
        </div>

        <!-- Mô tả sự cố -->
        <div class="form-group">
          <label class="form-label">Mô tả sự cố chi tiết</label>
          <textarea name="description" rows="3" class="form-control" placeholder="Mô tả hiện trạng sự cố tại kho (vị trí pallet, mức độ hư hại...)"></textarea>
        </div>

        <div class="p-3 bg-red-50 border border-red-200 rounded text-xs text-red-700">
          <i class="fa-solid fa-circle-info mr-1"></i>
          Sau khi gửi, sự cố sẽ ở trạng thái <strong>"Chờ duyệt"</strong>. Quản lý kho sẽ tiếp nhận và chỉ định hướng xử lý cho bạn.
        </div>
      </div>

      <div class="modal-box-footer">
        <button type="button" class="btn-secondary" onclick="closeCreateModal()">Hủy</button>
        <button type="submit" class="btn-primary">
          <i class="fa-solid fa-paper-plane text-xs"></i>
          Gửi báo cáo sự cố
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════ MODAL BÁO HOÀN THÀNH (BƯỚC 5) ═══════════════ -->
<div id="modalComplete" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-box-header">
      <div class="modal-box-title">
        <i class="fa-solid fa-circle-check" style="color: #16A34A;"></i>
        Báo Cáo Hoàn Thành Xử Lý Thực Tế
      </div>
      <button type="button" class="close-btn" onclick="closeCompleteModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <form method="POST" action="/warehouse/pages/incidents/report.php">
      <input type="hidden" name="action" value="report_complete">
      <input type="hidden" name="incident_id" id="complete_incident_id">

      <div class="modal-box-body space-y-3">
        <div class="p-3 bg-gray-50 border border-gray-200 rounded text-xs space-y-1">
          <div class="font-bold text-gray-800" id="complete_info_title">Mã sự cố: SC000</div>
          <div class="text-gray-600" id="complete_info_solution">Hướng xử lý đã được duyệt: ...</div>
        </div>

        <div class="form-group">
          <label class="form-label">Kết quả xử lý ngoài thực tế <span class="required">*</span></label>
          <textarea name="completion_note" rows="4" class="form-control" placeholder="Nhập kết quả đã xử lý (Ví dụ: Đã di chuyển 18 chai nứt vỡ sang Kho Thành Phẩm Lỗi KTPL01 theo đúng hướng dẫn...)" required></textarea>
        </div>

        <div class="p-3 bg-amber-50 border border-amber-200 rounded text-xs text-amber-800">
          <i class="fa-solid fa-circle-info mr-1"></i>
          Sau khi báo hoàn thành, sự cố sẽ chuyển sang trạng thái <strong>"Chờ xác nhận"</strong> và gửi tới Quản lý kho để nghiệm thu kết quả.
        </div>
      </div>

      <div class="modal-box-footer">
        <button type="button" class="btn-secondary" onclick="closeCompleteModal()">Đóng</button>
        <button type="submit" class="btn-primary">
          <i class="fa-solid fa-check text-xs"></i>
          Xác nhận hoàn thành & Gửi quản lý
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════ MODAL XEM CHI TIẾT SỰ CỐ ═══════════════ -->
<div id="modalDetail" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-box-header">
      <div class="modal-box-title">
        <i class="fa-solid fa-circle-info" style="color: #B91C1C;"></i>
        Chi Tiết Sự Cố: <span id="detail_id" class="ml-1 text-gray-800"></span>
      </div>
      <button type="button" class="close-btn" onclick="closeDetailModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="modal-box-body space-y-3 text-xs">
      <div class="grid grid-cols-2 gap-3 p-3 bg-gray-50 border border-gray-200 rounded">
        <div>
          <span class="text-gray-500 font-bold block">Ngày báo cáo:</span>
          <span id="detail_date" class="font-bold text-gray-800"></span>
        </div>
        <div>
          <span class="text-gray-500 font-bold block">Trạng thái:</span>
          <span id="detail_status"></span>
        </div>
        <div>
          <span class="text-gray-500 font-bold block">Kho xảy ra:</span>
          <span id="detail_warehouse" class="text-gray-800"></span>
        </div>
        <div>
          <span class="text-gray-500 font-bold block">Loại hàng:</span>
          <span id="detail_product_type" class="text-gray-800"></span>
        </div>
      </div>

      <div class="p-3 bg-gray-50 border border-gray-200 rounded space-y-1">
        <div><strong class="text-gray-600">Lô hàng:</strong> <span id="detail_batch" class="font-bold text-gray-900"></span></div>
        <div><strong class="text-gray-600">Sự cố:</strong> <span id="detail_incident" class="font-bold text-red-700"></span></div>
        <div><strong class="text-gray-600">Số lượng ảnh hưởng:</strong> <span id="detail_qty" class="font-bold text-gray-900"></span></div>
        <div><strong class="text-gray-600">Mô tả sự cố:</strong> <span id="detail_desc" class="text-gray-700"></span></div>
      </div>

      <div class="p-3 bg-blue-50 border border-blue-200 rounded space-y-1">
        <div class="font-bold text-blue-900">Hướng xử lý từ Quản lý kho:</div>
        <div id="detail_solution" class="font-bold text-blue-800"></div>
        <div id="detail_solution_note" class="text-blue-700 italic"></div>
      </div>

      <div id="detail_completion_box" class="p-3 bg-emerald-50 border border-emerald-200 rounded space-y-1 hidden">
        <div class="font-bold text-emerald-900">Báo cáo hoàn thành thực tế:</div>
        <div id="detail_completion_note" class="text-emerald-800"></div>
      </div>

      <div id="detail_rejection_box" class="p-3 bg-amber-50 border border-amber-200 rounded space-y-1 hidden">
        <div class="font-bold text-amber-900">Lý do yêu cầu xử lý lại từ Quản lý:</div>
        <div id="detail_rejection_note" class="text-amber-800"></div>
      </div>
    </div>

    <div class="modal-box-footer">
      <button type="button" class="btn-secondary" onclick="closeDetailModal()">Đóng</button>
    </div>
  </div>
</div>

<script>
  // Dropdown & sidebar toggle
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

  // Modal Tạo báo cáo
  function openCreateModal() {
    document.getElementById('modalCreate').classList.add('active');
  }
  function closeCreateModal() {
    document.getElementById('modalCreate').classList.remove('active');
  }

  // Modal Báo hoàn thành
  function openCompleteModal(inc) {
    document.getElementById('complete_incident_id').value = inc.id;
    document.getElementById('complete_info_title').textContent = 'Mã sự cố: ' + inc.id + ' — Lô hàng: ' + inc.batch_code + ' (' + inc.batch_name + ')';
    document.getElementById('complete_info_solution').textContent = 'Hướng xử lý: ' + (inc.solution || 'Chưa có');
    document.getElementById('modalComplete').classList.add('active');
  }
  function closeCompleteModal() {
    document.getElementById('modalComplete').classList.remove('active');
  }

  // Modal Chi tiết
  function openDetailModal(inc) {
    document.getElementById('detail_id').textContent = inc.id;
    document.getElementById('detail_date').textContent = inc.report_date;
    document.getElementById('detail_status').innerHTML = '<span class="font-bold">' + inc.status + '</span>';
    document.getElementById('detail_warehouse').textContent = inc.warehouse_name;
    document.getElementById('detail_product_type').textContent = inc.product_type;
    document.getElementById('detail_batch').textContent = inc.batch_code + ' - ' + inc.batch_name;
    document.getElementById('detail_incident').textContent = inc.incident_name;
    document.getElementById('detail_qty').textContent = inc.affected_quantity + ' ' + inc.unit;
    document.getElementById('detail_desc').textContent = inc.description || 'Không có mô tả chi tiết';

    document.getElementById('detail_solution').textContent = inc.solution ? inc.solution : 'Chưa có hướng xử lý';
    document.getElementById('detail_solution_note').textContent = inc.solution_note ? ('Chỉ đạo: ' + inc.solution_note) : '';

    const compBox = document.getElementById('detail_completion_box');
    if (inc.completion_note) {
      compBox.classList.remove('hidden');
      document.getElementById('detail_completion_note').textContent = inc.completion_note;
    } else {
      compBox.classList.add('hidden');
    }

    const rejBox = document.getElementById('detail_rejection_box');
    if (inc.rejection_note) {
      rejBox.classList.remove('hidden');
      document.getElementById('detail_rejection_note').textContent = inc.rejection_note;
    } else {
      rejBox.classList.add('hidden');
    }

    document.getElementById('modalDetail').classList.add('active');
  }
  function closeDetailModal() {
    document.getElementById('modalDetail').classList.remove('active');
  }
</script>
</body>
</html>
