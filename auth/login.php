<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (isLoggedIn()) {
    header('Location: /warehouse/pages/dashboard.php');
    exit;
}

$error = '';
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']      ?? '';
    $username_val = htmlspecialchars($username);

    if (empty($username) || empty($password)) {
        $error = 'Vui lòng nhập đầy đủ thông tin đăng nhập.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare("
                SELECT u.*, w.name AS warehouse_name 
                FROM users u 
                LEFT JOIN warehouses w ON u.warehouse_id = w.id 
                WHERE u.username = ? AND u.is_active = 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']        = $user['id'];
                $_SESSION['user_name']      = $user['full_name'];
                $_SESSION['username']       = $user['username'];
                $_SESSION['user_role']      = $user['role'];
                $_SESSION['warehouse_id']   = $user['warehouse_id'];
                $_SESSION['warehouse_name'] = $user['warehouse_name'] ?? 'Toàn hệ thống';
                header('Location: /warehouse/pages/dashboard.php');
                exit;
            } else {
                $error = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
            }
        } catch (Exception $e) {
            $error = 'Lỗi kết nối hệ thống. Vui lòng thử lại sau.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Đăng nhập — Hệ Thống Quản Lý Kho</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/warehouse/assets/css/login.css">
</head>
<body class="min-h-screen flex items-center justify-center" style="background-color: #F3F4F6;">

  <div class="w-full max-w-sm mx-4">

    <!-- Logo & Tiêu đề -->
    <div class="flex flex-col items-center mb-6">
      <img src="/warehouse/assets/images/logo.svg" alt="Logo Quản Lý Kho" class="w-14 h-14 mb-3" />
      <h1 class="text-xl font-bold" style="color: #1F2937;">Quản Lý Kho</h1>
      <p class="text-sm mt-0.5" style="color: #4B5563;">Hệ thống quản lý chuỗi kho</p>
    </div>

    <!-- Khung Form Đăng nhập -->
    <div class="rounded-lg border p-8" style="background-color: #FFFFFF; border-color: #E5E7EB;">
      <h2 class="text-lg font-bold mb-1" style="color: #1F2937;">Đăng nhập</h2>
      <p class="text-sm mb-6" style="color: #6B7280;">Nhập tài khoản để tiếp tục</p>

      <!-- Thông báo lỗi nếu có -->
      <?php if ($error): ?>
      <div class="flex items-start gap-2.5 rounded p-3 text-sm mb-5 border" style="background-color: #FEE2E2; border-color: #B91C1C; color: #B91C1C;">
        <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-shrink-0"></i>
        <span><?= $error ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" autocomplete="off" class="space-y-4">

        <!-- Tên đăng nhập -->
        <div>
          <label class="block text-sm font-bold mb-1.5" style="color: #1F2937;">Tên đăng nhập</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
              <i class="fa-regular fa-user text-sm" style="color: #6B7280;"></i>
            </span>
            <input type="text" name="username" value="<?= $username_val ?>"
                   placeholder="Nhập tên đăng nhập"
                   class="kv-input" required autofocus>
          </div>
        </div>

        <!-- Mật khẩu -->
        <div>
          <label class="block text-sm font-bold mb-1.5" style="color: #1F2937;">Mật khẩu</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
              <i class="fa-solid fa-lock text-sm" style="color: #6B7280;"></i>
            </span>
            <input type="password" id="pwd" name="password"
                   placeholder="Nhập mật khẩu"
                   class="kv-input pr-10" required>
            <button type="button" id="togglePwd"
                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-red-700 transition-colors">
              <i class="fa-regular fa-eye text-sm" id="eyeIcon"></i>
            </button>
          </div>
        </div>

        <!-- Ghi nhớ & Quên mật khẩu -->
        <div class="flex items-center justify-between text-sm">
          <label class="flex items-center gap-2 cursor-pointer" style="color: #1F2937;">
            <input type="checkbox" name="remember" class="w-4 h-4 rounded" style="accent-color: #B91C1C;">
            Ghi nhớ đăng nhập
          </label>
          <a href="#" class="link-accent">Quên mật khẩu?</a>
        </div>

        <!-- Nút Submit -->
        <button type="submit" class="kv-btn mt-1">
          <i class="fa-solid fa-right-to-bracket text-sm"></i>
          Đăng nhập
        </button>

      </form>
    </div>

    <!-- Hướng dẫn tài khoản demo -->
    <div class="mt-4 p-3 bg-white rounded border border-gray-200 text-xs text-gray-600">
      <p class="font-bold text-gray-800 mb-1.5 flex items-center gap-1.5">
        <i class="fa-solid fa-key" style="color: #B91C1C;"></i>
        Tài khoản thử nghiệm (Mật khẩu: password123)
      </p>
      <div class="space-y-1">
        <div class="flex justify-between items-center py-0.5 border-b border-gray-100">
          <span>Nhân viên: <strong class="text-gray-900">nhanvien</strong> (Nguyễn Văn B)</span>
          <span class="text-[10px] bg-green-50 text-green-700 px-1.5 py-0.5 rounded border border-green-200">Kho Thành Phẩm 1</span>
        </div>
        <div class="flex justify-between items-center py-0.5 border-b border-gray-100">
          <span>Quản lý kho: <strong class="text-gray-900">quanly</strong> (Trần Quản Lý)</span>
          <span class="text-[10px] bg-amber-50 text-amber-700 px-1.5 py-0.5 rounded border border-amber-200">Quản lý kho</span>
        </div>
        <div class="flex justify-between items-center py-0.5 border-b border-gray-100">
          <span>Ban Giám Đốc: <strong class="text-gray-900">giamdoc</strong> (Nguyễn Giám Đốc)</span>
          <span class="text-[10px] bg-indigo-50 text-indigo-700 px-1.5 py-0.5 rounded border border-indigo-200">Duyệt kiểm kê</span>
        </div>
        <div class="flex justify-between items-center py-0.5">
          <span>Quản trị viên: <strong class="text-gray-900">admin</strong></span>
          <span class="text-[10px] bg-red-50 text-red-700 px-1.5 py-0.5 rounded border border-red-200">Quản lý tài khoản</span>
        </div>
      </div>
    </div>

    <p class="text-center text-xs mt-4" style="color: #6B7280;">© 2026 Hệ Thống Quản Lý Kho</p>
  </div>

  <script>
    const pwd     = document.getElementById('pwd');
    const eyeIcon = document.getElementById('eyeIcon');
    document.getElementById('togglePwd').addEventListener('click', () => {
      const show = pwd.type === 'password';
      pwd.type   = show ? 'text' : 'password';
      eyeIcon.className = show ? 'fa-regular fa-eye-slash text-sm' : 'fa-regular fa-eye text-sm';
    });
  </script>
</body>
</html>
