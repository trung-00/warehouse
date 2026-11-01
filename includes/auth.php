<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /warehouse/auth/login.php');
        exit;
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    // Nếu chưa có tên kho trong session, truy vấn tự động
    if (!isset($_SESSION['warehouse_name']) || empty($_SESSION['warehouse_name'])) {
        try {
            $db = getDB();
            $stmt = $db->prepare("
                SELECT u.*, w.name AS warehouse_name 
                FROM users u 
                LEFT JOIN warehouses w ON u.warehouse_id = w.id 
                WHERE u.id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $u = $stmt->fetch();
            if ($u) {
                $_SESSION['warehouse_name'] = $u['warehouse_name'] ?? 'Toàn hệ thống';
            }
        } catch (Exception $e) {
            $_SESSION['warehouse_name'] = 'Toàn hệ thống';
        }
    }

    return [
        'id'             => $_SESSION['user_id']          ?? null,
        'name'           => $_SESSION['user_name']        ?? '',
        'username'       => $_SESSION['username']         ?? '',
        'role'           => $_SESSION['user_role']        ?? '',
        'warehouse_id'   => $_SESSION['warehouse_id']     ?? null,
        'warehouse_name' => $_SESSION['warehouse_name']   ?? 'Toàn hệ thống',
    ];
}

function getRoleLabel($role) {
    $labels = [
        'admin'       => 'Quản trị viên',
        'manager'     => 'Quản lý kho',
        'staff'       => 'Nhân viên kho',
        'director'    => 'Ban Giám Đốc',
        'stocktaking' => 'Bộ phận kiểm kê',
    ];
    return $labels[$role] ?? $role;
}

function getRoleBadgeClass($role) {
    $classes = [
        'admin'       => 'badge-role-admin',
        'manager'     => 'badge-role-manager',
        'staff'       => 'badge-role-staff',
        'director'    => 'badge-role-director',
        'stocktaking' => 'badge-role-stocktaking',
    ];
    return $classes[$role] ?? 'badge-role-staff';
}

function isStaff() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'staff';
}

function isManager() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'manager';
}

function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function isDirector() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'director';
}

function isStocktaking() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'stocktaking';
}

function requireStocktaking() {
    requireLogin();
    if (!isStocktaking()) {
        header('Location: /warehouse/pages/dashboard.php');
        exit;
    }
}

function requireDirector() {
    requireLogin();
    if (!isDirector()) {
        header('Location: /warehouse/pages/dashboard.php');
        exit;
    }
}

function requireStaff() {
    requireLogin();
    if (!isStaff()) {
        header('Location: /warehouse/pages/dashboard.php');
        exit;
    }
}

function requireManager() {
    requireLogin();
    if (!isManager()) {
        header('Location: /warehouse/pages/dashboard.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /warehouse/pages/dashboard.php');
        exit;
    }
}

/**
 * Trả về danh sách hướng xử lý theo Tên sự cố
 * Rò rỉ nước: Chuyển vào kho thành phẩm lỗi, tiêu hủy
 * Gần hết hạn: Ưu tiên xuất kho
 * Móp méo: Chuyển vào kho thành phẩm lỗi, Kiểm tra chất lượng sản phẩm
 */
function getIncidentSolutions($incidentName) {
    $solutionsMap = [
        'Rò rỉ nước' => [
            'Chuyển vào kho thành phẩm lỗi',
            'Tiêu hủy'
        ],
        'Gần hết hạn' => [
            'Ưu tiên xuất kho'
        ],
        'Móp méo' => [
            'Chuyển vào kho thành phẩm lỗi',
            'Kiểm tra chất lượng sản phẩm'
        ]
    ];
    return $solutionsMap[$incidentName] ?? [];
}

/**
 * Hiển thị Badge trạng thái chuẩn màu yêu cầu:
 * - Hoàn thành: Xanh lá (#16A34A)
 * - Đang thực hiện: Cam (#D97706)
 * - Chờ xác nhận: Cam (#D97706)
 * - Chờ duyệt / Chưa có hướng xử lý: Xám/Đỏ nhạt
 */
function renderStatusBadge($status) {
    switch ($status) {
        case 'Hoàn thành':
            return '<span class="status-badge status-completed"><i class="fa-solid fa-circle-check text-[10px] mr-1"></i>Hoàn thành</span>';
        case 'Đang thực hiện':
            return '<span class="status-badge status-processing"><i class="fa-solid fa-spinner fa-spin text-[10px] mr-1"></i>Đang thực hiện</span>';
        case 'Chờ xác nhận':
            return '<span class="status-badge status-pending-confirm"><i class="fa-solid fa-clock-rotate-left text-[10px] mr-1"></i>Chờ xác nhận</span>';
        case 'Chờ duyệt':
        default:
            return '<span class="status-badge status-waiting"><i class="fa-solid fa-hourglass-half text-[10px] mr-1"></i>Chờ duyệt</span>';
    }
}

/**
 * Gửi thông báo hệ thống
 */
function addNotification($recipientRole, $recipientId, $incidentId, $title, $message) {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO notifications (recipient_role, recipient_id, incident_id, title, message)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$recipientRole, $recipientId, $incidentId, $title, $message]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Lấy số thông báo chưa đọc
 */
function getUnreadNotificationCount($role, $userId = null) {
    try {
        $db = getDB();
        if ($userId) {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE (recipient_role = ? OR recipient_id = ?) AND is_read = 0
            ");
            $stmt->execute([$role, $userId]);
        } else {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE recipient_role = ? AND is_read = 0
            ");
            $stmt->execute([$role]);
        }
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
