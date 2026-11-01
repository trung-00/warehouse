<?php
// Tự động kiểm tra và tạo bảng cho phân hệ Lập phiếu xuất kho nếu chưa có trong DB
require_once __DIR__ . '/../config/database.php';

function checkAndInitExportTables() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $db = getDB();
        $stmt = $db->query("SHOW TABLES LIKE 'PHIEUYEUCAUKHO'");
        if ($stmt->rowCount() == 0) {
            // Đọc và chạy các lệnh tạo bảng liên quan từ setup.sql
            $sqlStatements = "
            CREATE TABLE IF NOT EXISTS KHO (
                MaKho VARCHAR(20) PRIMARY KEY,
                TenKho VARCHAR(100) NOT NULL,
                DiaChi VARCHAR(200),
                SucChua INT DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS HANGHOA (
                MaHangHoa VARCHAR(20) PRIMARY KEY,
                TenHangHoa VARCHAR(150) NOT NULL,
                DonViTinh VARCHAR(50) NOT NULL,
                LoaiHang VARCHAR(50) NOT NULL DEFAULT 'Thành phẩm'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS KELUUTRU (
                MaKe VARCHAR(20) PRIMARY KEY,
                TenKe VARCHAR(100) NOT NULL,
                MaKho VARCHAR(20) NOT NULL,
                ViTri VARCHAR(100),
                FOREIGN KEY (MaKho) REFERENCES KHO(MaKho) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS LOHANG (
                MaLo VARCHAR(30) PRIMARY KEY,
                MaHangHoa VARCHAR(20) NOT NULL,
                NgayNhap DATETIME NOT NULL,
                HanSuDung DATE NOT NULL,
                SoLuongTon INT NOT NULL DEFAULT 0,
                MaKho VARCHAR(20) NOT NULL,
                MaKe VARCHAR(20) NULL,
                FOREIGN KEY (MaHangHoa) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT,
                FOREIGN KEY (MaKho) REFERENCES KHO(MaKho) ON DELETE CASCADE,
                FOREIGN KEY (MaKe) REFERENCES KELUUTRU(MaKe) ON DELETE SET NULL,
                INDEX idx_lohang_fefo (MaHangHoa, MaKho, HanSuDung, NgayNhap)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS TONKHO (
                MaKho VARCHAR(20) NOT NULL,
                MaHangHoa VARCHAR(20) NOT NULL,
                SoLuongTon INT NOT NULL DEFAULT 0,
                PRIMARY KEY (MaKho, MaHangHoa),
                FOREIGN KEY (MaKho) REFERENCES KHO(MaKho) ON DELETE CASCADE,
                FOREIGN KEY (MaHangHoa) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS PHIEUYEUCAUKHO (
                MaPhieuYeuCau VARCHAR(30) PRIMARY KEY,
                LoaiYeuCau VARCHAR(50) NOT NULL DEFAULT 'Xuất kho',
                NgayYeuCau DATETIME NOT NULL,
                BoPhanGui VARCHAR(100) NOT NULL,
                TrangThaiYeuCau ENUM('Chờ duyệt', 'Đã duyệt', 'Từ chối') NOT NULL DEFAULT 'Chờ duyệt',
                MaKho VARCHAR(20) NOT NULL,
                FOREIGN KEY (MaKho) REFERENCES KHO(MaKho) ON DELETE RESTRICT,
                INDEX idx_pyck_kho_trangthai (MaKho, TrangThaiYeuCau)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS CHITIETPHIEUYEUCAUKHO (
                MaPhieuYeuCau VARCHAR(30) NOT NULL,
                MaHangHoa VARCHAR(20) NOT NULL,
                SoLuongYeuCau INT NOT NULL,
                PRIMARY KEY (MaPhieuYeuCau, MaHangHoa),
                FOREIGN KEY (MaPhieuYeuCau) REFERENCES PHIEUYEUCAUKHO(MaPhieuYeuCau) ON DELETE CASCADE,
                FOREIGN KEY (MaHangHoa) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS PHIEUXUATKHO (
                MaPhieu VARCHAR(30) PRIMARY KEY,
                LoaiPhieu VARCHAR(50) NOT NULL DEFAULT 'Xuất kho bán hàng',
                NgayLap DATETIME NOT NULL,
                TrangThaiPhieu VARCHAR(50) NOT NULL DEFAULT 'Đã xuất',
                NguoiLap VARCHAR(100) NOT NULL,
                MaKho VARCHAR(20) NOT NULL,
                NgayXuat DATE NOT NULL,
                MaPhieuYeuCau VARCHAR(30) NOT NULL,
                GhiChu TEXT NULL,
                FOREIGN KEY (MaKho) REFERENCES KHO(MaKho) ON DELETE RESTRICT,
                FOREIGN KEY (MaPhieuYeuCau) REFERENCES PHIEUYEUCAUKHO(MaPhieuYeuCau) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS CHITIETPHIEUXUATKHO (
                MaPhieu VARCHAR(30) NOT NULL,
                MaHangHoa VARCHAR(20) NOT NULL,
                MaLo VARCHAR(30) NOT NULL,
                SoLuongXuat INT NOT NULL,
                PRIMARY KEY (MaPhieu, MaHangHoa, MaLo),
                FOREIGN KEY (MaPhieu) REFERENCES PHIEUXUATKHO(MaPhieu) ON DELETE CASCADE,
                FOREIGN KEY (MaHangHoa) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT,
                FOREIGN KEY (MaLo) REFERENCES LOHANG(MaLo) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            INSERT INTO KHO (MaKho, TenKho, DiaChi, SucChua) VALUES
            ('KTP01', 'Kho Thành Phẩm 1', 'TP.HCM', 10000),
            ('KTP02', 'Kho Thành Phẩm 2', 'TP.HCM', 10000),
            ('KTP03', 'Kho Thành Phẩm 3', 'TP.HCM', 10000)
            ON DUPLICATE KEY UPDATE TenKho = VALUES(TenKho);

            INSERT INTO HANGHOA (MaHangHoa, TenHangHoa, DonViTinh, LoaiHang) VALUES
            ('HH001', 'Nước lọc 500ml', 'Chai', 'Thành phẩm'),
            ('HH002', 'Nước ngọt Cola 330ml', 'Lon', 'Thành phẩm'),
            ('HH003', 'Nước lọc 1.5L', 'Chai', 'Thành phẩm')
            ON DUPLICATE KEY UPDATE TenHangHoa = VALUES(TenHangHoa);

            INSERT INTO KELUUTRU (MaKe, TenKe, MaKho, ViTri) VALUES
            ('KE01', 'Kệ A1 - Tầng 1', 'KTP01', 'Khu A Dãy 1'),
            ('KE02', 'Kệ A2 - Tầng 2', 'KTP01', 'Khu A Dãy 2'),
            ('KE03', 'Kệ B1 - Tầng 1', 'KTP01', 'Khu B Dãy 1')
            ON DUPLICATE KEY UPDATE TenKe = VALUES(TenKe);

            INSERT INTO LOHANG (MaLo, MaHangHoa, NgayNhap, HanSuDung, SoLuongTon, MaKho, MaKe) VALUES
            ('LO001', 'HH001', '2026-09-01 08:00:00', '2027-03-01', 60, 'KTP01', 'KE01'),
            ('LO002', 'HH002', '2026-09-15 08:00:00', '2027-04-01', 100, 'KTP01', 'KE02'),
            ('LO003', 'HH003', '2026-09-25 08:00:00', '2027-06-01', 200, 'KTP01', 'KE03'),
            ('LO004', 'HH001', '2026-09-10 09:00:00', '2027-05-01', 100, 'KTP01', 'KE01'),
            ('LO005', 'HH002', '2026-09-20 10:00:00', '2027-07-01', 20, 'KTP01', 'KE02')
            ON DUPLICATE KEY UPDATE SoLuongTon = VALUES(SoLuongTon);

            INSERT INTO TONKHO (MaKho, MaHangHoa, SoLuongTon) VALUES
            ('KTP01', 'HH001', 160),
            ('KTP01', 'HH002', 120),
            ('KTP01', 'HH003', 200)
            ON DUPLICATE KEY UPDATE SoLuongTon = VALUES(SoLuongTon);

            INSERT INTO PHIEUYEUCAUKHO (MaPhieuYeuCau, LoaiYeuCau, NgayYeuCau, BoPhanGui, TrangThaiYeuCau, MaKho) VALUES
            ('YCX001', 'Xuất kho', '2026-10-28 08:30:00', 'Bộ phận Bán hàng', 'Đã duyệt', 'KTP01'),
            ('YCX002', 'Xuất kho', '2026-10-29 09:15:00', 'Bộ phận Bán hàng', 'Chờ duyệt', 'KTP01'),
            ('YCX003', 'Xuất kho', '2026-10-30 10:00:00', 'Bộ phận Sản xuất', 'Từ chối', 'KTP01'),
            ('YCX004', 'Xuất kho', '2026-10-31 14:20:00', 'Bộ phận Bán hàng', 'Đã duyệt', 'KTP01'),
            ('YCX005', 'Xuất kho', '2026-11-01 08:45:00', 'Bộ phận Phân phối', 'Đã duyệt', 'KTP01')
            ON DUPLICATE KEY UPDATE TrangThaiYeuCau = VALUES(TrangThaiYeuCau);

            INSERT INTO CHITIETPHIEUYEUCAUKHO (MaPhieuYeuCau, MaHangHoa, SoLuongYeuCau) VALUES
            ('YCX001', 'HH001', 100),
            ('YCX002', 'HH002', 80),
            ('YCX003', 'HH003', 150),
            ('YCX004', 'HH002', 50),
            ('YCX005', 'HH003', 80)
            ON DUPLICATE KEY UPDATE SoLuongYeuCau = VALUES(SoLuongYeuCau);
            ON DUPLICATE KEY UPDATE SoLuongYeuCau = VALUES(SoLuongYeuCau);
            ";

            $db->exec($sqlStatements);
        }
    } catch (Exception $e) {
        // Ghi log hoặc bỏ qua nếu người dùng đã import thủ công bằng phpMyAdmin
    }
}
