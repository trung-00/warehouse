-- ========================================================
-- DATABASE: HỆ THỐNG QUẢN LÝ KHO & SỰ CỐ
-- ========================================================
CREATE DATABASE IF NOT EXISTS warehouse_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE warehouse_db;

-- Tắt kiểm tra khóa ngoại tạm thời để xóa/tạo bảng sạch sẽ, tránh lỗi #1451
SET FOREIGN_KEY_CHECKS = 0;

-- Xóa bảng cũ theo thứ tự ràng buộc
DROP TABLE IF EXISTS NGUYENNHAN_CHENHLECH;
DROP TABLE IF EXISTS CHITIET_BIENBAN_KIEMKE;
DROP TABLE IF EXISTS BIENBAN_KIEMKE;
DROP TABLE IF EXISTS CHITIETPHIEUXUATKHO;
DROP TABLE IF EXISTS PHIEUXUATKHO;
DROP TABLE IF EXISTS CHITIETPHIEUYEUCAUKHO;
DROP TABLE IF EXISTS PHIEUYEUCAUKHO;
DROP TABLE IF EXISTS TONKHO;
DROP TABLE IF EXISTS LOHANG;
DROP TABLE IF EXISTS KELUUTRU;
DROP TABLE IF EXISTS HANGHOA;
DROP TABLE IF EXISTS KHO;
DROP TABLE IF EXISTS Ke;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS incidents;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS warehouses;

-- Bật lại kiểm tra khóa ngoại
SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- 1. BẢNG KHO HÀNG (warehouses)
-- Gồm: 5 kho thành phẩm, 5 kho nguyên liệu, 1 kho thành phẩm lỗi
-- Tất cả các Mã (id) đều là String
-- --------------------------------------------------------
CREATE TABLE warehouses (
    id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('thanh_pham', 'nguyen_lieu', 'loi') NOT NULL,
    address VARCHAR(200) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO warehouses (id, name, type, address) VALUES
-- 5 kho thành phẩm
('KTP01', 'Kho Thành Phẩm 1', 'thanh_pham', 'Khu A - Lô TP1, Cụm Kho Công Nghiệp'),
('KTP02', 'Kho Thành Phẩm 2', 'thanh_pham', 'Khu A - Lô TP2, Cụm Kho Công Nghiệp'),
('KTP03', 'Kho Thành Phẩm 3', 'thanh_pham', 'Khu A - Lô TP3, Cụm Kho Công Nghiệp'),
('KTP04', 'Kho Thành Phẩm 4', 'thanh_pham', 'Khu A - Lô TP4, Cụm Kho Công Nghiệp'),
('KTP05', 'Kho Thành Phẩm 5', 'thanh_pham', 'Khu A - Lô TP5, Cụm Kho Công Nghiệp'),
-- 5 kho nguyên liệu
('KNL01', 'Kho Nguyên Liệu 1', 'nguyen_lieu', 'Khu B - Lô NL1, Cụm Kho Công Nghiệp'),
('KNL02', 'Kho Nguyên Liệu 2', 'nguyen_lieu', 'Khu B - Lô NL2, Cụm Kho Công Nghiệp'),
('KNL03', 'Kho Nguyên Liệu 3', 'nguyen_lieu', 'Khu B - Lô NL3, Cụm Kho Công Nghiệp'),
('KNL04', 'Kho Nguyên Liệu 4', 'nguyen_lieu', 'Khu B - Lô NL4, Cụm Kho Công Nghiệp'),
('KNL05', 'Kho Nguyên Liệu 5', 'nguyen_lieu', 'Khu B - Lô NL5, Cụm Kho Công Nghiệp'),
-- 1 kho thành phẩm lỗi
('KTPL01', 'Kho Thành Phẩm Lỗi', 'loi', 'Khu C - Lô TPL, Khu Xử Lý Biệt Lập');

-- --------------------------------------------------------
-- 2. BẢNG NGƯỜI DÙNG (users)
-- Mã là String
-- Mật khẩu mặc định cho tất cả: "password123"
-- Nhân viên ban đầu: Nguyễn Văn B (Kho Thành Phẩm 1 - KTP01)
-- --------------------------------------------------------
CREATE TABLE users (
    id VARCHAR(20) PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'staff', 'director') NOT NULL DEFAULT 'staff',
    warehouse_id VARCHAR(20) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hash của 'password123' (nên tự kiểm tra bằng password_verify trong PHP):
-- $2y$10$fjL9wbqrYsPQoIdGiE6S5u7HVtUNDm2rhtMpB6lhMuNyqYEB9u5ty
INSERT INTO users (id, full_name, username, password, role, warehouse_id) VALUES
('AD001', 'Quản Trị Viên', 'admin', '$2y$10$fjL9wbqrYsPQoIdGiE6S5u7HVtUNDm2rhtMpB6lhMuNyqYEB9u5ty', 'admin', NULL),
('QL001', 'Trần Quản Lý', 'quanly', '$2y$10$fjL9wbqrYsPQoIdGiE6S5u7HVtUNDm2rhtMpB6lhMuNyqYEB9u5ty', 'manager', NULL),
('NV001', 'Nguyễn Văn B', 'nhanvien', '$2y$10$fjL9wbqrYsPQoIdGiE6S5u7HVtUNDm2rhtMpB6lhMuNyqYEB9u5ty', 'staff', 'KTP01'),
('BGD001', 'Nguyễn Giám Đốc', 'giamdoc', '$2y$10$fjL9wbqrYsPQoIdGiE6S5u7HVtUNDm2rhtMpB6lhMuNyqYEB9u5ty', 'director', NULL);

-- --------------------------------------------------------
-- 3. BẢNG SỰ CỐ KHO (incidents)
-- Mã sự cố, Mã lô hàng đều là String
-- --------------------------------------------------------
CREATE TABLE incidents (
    id VARCHAR(30) PRIMARY KEY,
    report_date DATE NOT NULL,
    product_type VARCHAR(50) NOT NULL DEFAULT 'Thành phẩm',
    batch_code VARCHAR(50) NOT NULL,
    batch_name VARCHAR(150) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    warehouse_id VARCHAR(20) NOT NULL,
    incident_name VARCHAR(100) NOT NULL,
    affected_quantity INT NOT NULL,
    description TEXT,
    solution VARCHAR(255) DEFAULT NULL,
    status ENUM('Chờ duyệt', 'Đang thực hiện', 'Chờ xác nhận', 'Hoàn thành') NOT NULL DEFAULT 'Chờ duyệt',
    reported_by VARCHAR(20) NOT NULL,
    resolved_by VARCHAR(20) NULL,
    solution_note TEXT NULL,
    completion_note TEXT NULL,
    rejection_note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_affected_quantity CHECK (affected_quantity > 0),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (reported_by) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id),
    INDEX idx_incidents_status (status),
    INDEX idx_incidents_wh_date (warehouse_id, report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. BẢNG THÔNG BÁO (notifications)
-- Gửi thông báo luân chuyển giữa Nhân viên kho và Quản lý kho
-- --------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_role ENUM('admin', 'manager', 'staff') NOT NULL,
    recipient_id VARCHAR(20) NULL,
    incident_id VARCHAR(30) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notif_recipient (recipient_role, recipient_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- DỮ LIỆU MẪU: 10 DÒNG SỰ CỐ BAN ĐẦU
-- Tất cả đều là Thành phẩm tại Kho Thành Phẩm 1 (KTP01)
-- 7 dòng chưa có hướng xử lý (Trạng thái: Chờ duyệt)
-- 3 dòng đã có hướng xử lý:
--    - 1 dòng: Hoàn thành
--    - 2 dòng: Đang thực hiện
-- --------------------------------------------------------
INSERT INTO incidents (
    id, report_date, product_type, batch_code, batch_name, unit,
    warehouse_id, incident_name, affected_quantity, description,
    solution, status, reported_by, resolved_by, solution_note, completion_note
) VALUES
-- 7 dòng CHƯA CÓ HƯỚNG XỬ LÝ (Chờ duyệt)
('SC001', '2026-10-23', 'Thành phẩm', 'LH-TP-001', 'Nước khoáng đóng chai 500ml', 'Chai', 'KTP01', 'Rò rỉ nước', 15, 'Phát hiện 15 chai nước khoáng bị rò rỉ tại góc kệ A1', NULL, 'Chờ duyệt', 'NV001', NULL, NULL, NULL),
('SC002', '2026-10-24', 'Thành phẩm', 'LH-TP-002', 'Nước ép cam đóng chai 1L', 'Chai', 'KTP01', 'Gần hết hạn', 120, 'Lô nước ép cam có hạn sử dụng còn dưới 30 ngày, số lượng tồn kho còn nhiều', NULL, 'Chờ duyệt', 'NV001', NULL, NULL, NULL),
('SC003', '2026-10-24', 'Thành phẩm', 'LH-TP-003', 'Sữa tươi tiệt trùng 1L', 'Hộp', 'KTP01', 'Móp méo', 8, 'Phát hiện 8 hộp sữa bị móp góc do va chạm trong quá trình sắp xếp hàng', NULL, 'Chờ duyệt', 'NV001', NULL, NULL, NULL),
('SC004', '2026-10-25', 'Thành phẩm', 'LH-TP-004', 'Nước tăng lực lon 250ml', 'Lon', 'KTP01', 'Rò rỉ nước', 5, 'Phát hiện 5 lon nước tăng lực bị rò rỉ tại tầng 2 kệ B3', NULL, 'Chờ duyệt', 'NV001', NULL, NULL, NULL),
('SC005', '2026-10-26', 'Thành phẩm', 'LH-TP-005', 'Nước yến đóng chai 180ml', 'Chai', 'KTP01', 'Gần hết hạn', 60, 'Lô nước yến có hạn sử dụng còn 20 ngày, cần có kế hoạch luân chuyển hàng', NULL, 'Chờ duyệt', 'NV001', NULL, NULL, NULL),
('SC006', '2026-10-27', 'Thành phẩm', 'LH-TP-006', 'Trà xanh đóng chai 450ml', 'Chai', 'KTP01', 'Móp méo', 12, 'Phát hiện 12 chai trà xanh bị biến dạng do bị chèn ép trong quá trình lưu kho', NULL, 'Chờ duyệt', 'NV001', NULL, NULL, NULL),
('SC007', '2026-10-28', 'Thành phẩm', 'LH-TP-007', 'Nước dừa đóng chai 330ml', 'Chai', 'KTP01', 'Rò rỉ nước', 4, 'Phát hiện 4 chai nước dừa bị rò rỉ do nắp chai không kín trong quá trình bốc xếp', NULL, 'Chờ duyệt', 'NV001', NULL, NULL, NULL),

-- 1 dòng ĐÃ CÓ HƯỚNG XỬ LÝ: HOÀN THÀNH
('SC008', '2026-10-20', 'Thành phẩm', 'LH-TP-008', 'Nước ép táo đóng chai 1L', 'Chai', 'KTP01', 'Gần hết hạn', 45, 'Lô nước ép táo còn 25 ngày trước hạn sử dụng', 'Ưu tiên xuất kho', 'Hoàn thành', 'NV001', 'QL001', 'Ưu tiên xuất cho các đơn hàng để giảm lượng hàng tồn kho', 'Đã xuất kho toàn bộ 45 chai cho các đơn hàng ưu tiên, không còn hàng tồn trong lô'),

-- 2 dòng ĐÃ CÓ HƯỚNG XỬ LÝ: ĐANG THỰC HIỆN
('SC009', '2026-10-21', 'Thành phẩm', 'LH-TP-009', 'Nước ngọt có gas chai 1.5L', 'Chai', 'KTP01', 'Rò rỉ nước', 18, 'Phát hiện 18 chai nước ngọt bị nứt đáy tại pallet D1', 'Chuyển vào kho thành phẩm lỗi', 'Đang thực hiện', 'NV001', 'QL001', 'Cách ly 18 chai bị nứt và chuyển sang Kho Thành Phẩm Lỗi KTPL01', NULL),
('SC010', '2026-10-22', 'Thành phẩm', 'LH-TP-010', 'Cà phê sữa đóng chai 250ml', 'Chai', 'KTP01', 'Móp méo', 25, 'Phát hiện 25 chai cà phê sữa bị móp, biến dạng trong quá trình dỡ hàng', 'Kiểm tra chất lượng sản phẩm', 'Đang thực hiện', 'NV001', 'QL001', 'Kiểm tra tình trạng chai, nắp và chất lượng đồ uống bên trong', NULL);

-- ========================================================
-- PHẦN BỔ SUNG: PHÂN HỆ QUẢN LÝ XUẤT KHO & TỒN KHO HÀNG HÓA
-- ========================================================

-- 5. BẢNG KHO (KHO)
CREATE TABLE IF NOT EXISTS KHO (
    MaKho VARCHAR(20) PRIMARY KEY,
    TenKho VARCHAR(100) NOT NULL,
    DiaChi VARCHAR(200),
    SucChua INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. BẢNG HÀNG HÓA (HANGHOA)
-- Thành phẩm và nguyên liệu được tổng quát hóa thành thành phẩm
CREATE TABLE IF NOT EXISTS HANGHOA (
    MaHangHoa VARCHAR(20) PRIMARY KEY,
    TenHangHoa VARCHAR(150) NOT NULL,
    DonViTinh VARCHAR(50) NOT NULL,
    LoaiHang VARCHAR(50) NOT NULL DEFAULT 'Thành phẩm'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. BẢNG KỆ LƯU TRỮ (KELUUTRU)
CREATE TABLE IF NOT EXISTS KELUUTRU (
    MaKe VARCHAR(20) PRIMARY KEY,
    TenKe VARCHAR(100) NOT NULL,
    MaKho VARCHAR(20) NOT NULL,
    ViTri VARCHAR(100),
    FOREIGN KEY (MaKho) REFERENCES KHO(MaKho) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. BẢNG LÔ HÀNG (LOHANG)
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

-- 9. BẢNG TỒN KHO (TONKHO)
CREATE TABLE IF NOT EXISTS TONKHO (
    MaKho VARCHAR(20) NOT NULL,
    MaHangHoa VARCHAR(20) NOT NULL,
    SoLuongTon INT NOT NULL DEFAULT 0,
    PRIMARY KEY (MaKho, MaHangHoa),
    FOREIGN KEY (MaKho) REFERENCES KHO(MaKho) ON DELETE CASCADE,
    FOREIGN KEY (MaHangHoa) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. BẢNG PHIẾU YÊU CẦU XUẤT KHO (PHIEUYEUCAUKHO)
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

-- 11. BẢNG CHI TIẾT PHIẾU YÊU CẦU XUẤT KHO (CHITIETPHIEUYEUCAUKHO)
CREATE TABLE IF NOT EXISTS CHITIETPHIEUYEUCAUKHO (
    MaPhieuYeuCau VARCHAR(30) NOT NULL,
    MaHangHoa VARCHAR(20) NOT NULL,
    SoLuongYeuCau INT NOT NULL,
    PRIMARY KEY (MaPhieuYeuCau, MaHangHoa),
    FOREIGN KEY (MaPhieuYeuCau) REFERENCES PHIEUYEUCAUKHO(MaPhieuYeuCau) ON DELETE CASCADE,
    FOREIGN KEY (MaHangHoa) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. BẢNG PHIẾU XUẤT KHO (PHIEUXUATKHO)
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

-- 13. BẢNG CHI TIẾT PHIẾU XUẤT KHO (CHITIETPHIEUXUATKHO)
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

-- --------------------------------------------------------
-- DỮ LIỆU MẪU THEO YÊU CẦU:
-- --------------------------------------------------------

-- 6.1. Dữ liệu kho (KHO)
INSERT INTO KHO (MaKho, TenKho, DiaChi, SucChua) VALUES
('KTP01', 'Kho Thành Phẩm 1', 'TP.HCM', 10000),
('KTP02', 'Kho Thành Phẩm 2', 'TP.HCM', 10000),
('KTP03', 'Kho Thành Phẩm 3', 'TP.HCM', 10000)
ON DUPLICATE KEY UPDATE TenKho = VALUES(TenKho), DiaChi = VALUES(DiaChi), SucChua = VALUES(SucChua);

-- 6.2. Dữ liệu hàng hóa (HANGHOA)
INSERT INTO HANGHOA (MaHangHoa, TenHangHoa, DonViTinh, LoaiHang) VALUES
('HH001', 'Nước lọc 500ml', 'Chai', 'Thành phẩm'),
('HH002', 'Nước ngọt Cola 330ml', 'Lon', 'Thành phẩm'),
('HH003', 'Nước lọc 1.5L', 'Chai', 'Thành phẩm')
ON DUPLICATE KEY UPDATE TenHangHoa = VALUES(TenHangHoa), DonViTinh = VALUES(DonViTinh), LoaiHang = VALUES(LoaiHang);

-- Kệ lưu trữ mẫu (KELUUTRU)
INSERT INTO KELUUTRU (MaKe, TenKe, MaKho, ViTri) VALUES
('KE01', 'Kệ A1 - Tầng 1', 'KTP01', 'Khu A Dãy 1'),
('KE02', 'Kệ A2 - Tầng 2', 'KTP01', 'Khu A Dãy 2'),
('KE03', 'Kệ B1 - Tầng 1', 'KTP01', 'Khu B Dãy 1')
ON DUPLICATE KEY UPDATE TenKe = VALUES(TenKe);

-- 6.5. Dữ liệu lô hàng (LOHANG)
INSERT INTO LOHANG (MaLo, MaHangHoa, NgayNhap, HanSuDung, SoLuongTon, MaKho, MaKe) VALUES
('LO001', 'HH001', '2026-09-01 08:00:00', '2027-03-01', 60, 'KTP01', 'KE01'),
('LO002', 'HH002', '2026-09-15 08:00:00', '2027-04-01', 100, 'KTP01', 'KE02'),
('LO003', 'HH003', '2026-09-25 08:00:00', '2027-06-01', 200, 'KTP01', 'KE03'),
('LO004', 'HH001', '2026-09-10 09:00:00', '2027-05-01', 100, 'KTP01', 'KE01'),
('LO005', 'HH002', '2026-09-20 10:00:00', '2027-07-01', 20, 'KTP01', 'KE02')
ON DUPLICATE KEY UPDATE SoLuongTon = VALUES(SoLuongTon), HanSuDung = VALUES(HanSuDung), NgayNhap = VALUES(NgayNhap);

-- 6.6. Dữ liệu tồn kho – bảng TONKHO
INSERT INTO TONKHO (MaKho, MaHangHoa, SoLuongTon) VALUES
('KTP01', 'HH001', 160),
('KTP01', 'HH002', 120),
('KTP01', 'HH003', 200)
ON DUPLICATE KEY UPDATE SoLuongTon = VALUES(SoLuongTon);

-- 6.3. Dữ liệu yêu cầu xuất kho (PHIEUYEUCAUKHO)
INSERT INTO PHIEUYEUCAUKHO (MaPhieuYeuCau, LoaiYeuCau, NgayYeuCau, BoPhanGui, TrangThaiYeuCau, MaKho) VALUES
('YCX001', 'Xuất kho', '2026-10-28 08:30:00', 'Bộ phận Bán hàng', 'Đã duyệt', 'KTP01'),
('YCX002', 'Xuất kho', '2026-10-29 09:15:00', 'Bộ phận Bán hàng', 'Chờ duyệt', 'KTP01'),
('YCX003', 'Xuất kho', '2026-10-30 10:00:00', 'Bộ phận Sản xuất', 'Từ chối', 'KTP01'),
('YCX004', 'Xuất kho', '2026-10-31 14:20:00', 'Bộ phận Bán hàng', 'Đã duyệt', 'KTP01'),
('YCX005', 'Xuất kho', '2026-11-01 08:45:00', 'Bộ phận Phân phối', 'Đã duyệt', 'KTP01')
ON DUPLICATE KEY UPDATE TrangThaiYeuCau = VALUES(TrangThaiYeuCau);

-- 6.4. Dữ liệu chi tiết yêu cầu xuất kho (CHITIETPHIEUYEUCAUKHO)
INSERT INTO CHITIETPHIEUYEUCAUKHO (MaPhieuYeuCau, MaHangHoa, SoLuongYeuCau) VALUES
('YCX001', 'HH001', 100),
('YCX002', 'HH002', 80),
('YCX003', 'HH003', 150),
('YCX004', 'HH002', 50),
('YCX005', 'HH003', 80)
ON DUPLICATE KEY UPDATE SoLuongYeuCau = VALUES(SoLuongYeuCau);

-- ========================================================
-- PHẦN BỔ SUNG: BẢNG VÀ DỮ LIỆU QUẢN LÝ KỆ (Ke)
-- ========================================================
CREATE TABLE IF NOT EXISTS Ke (
    maKe VARCHAR(20) PRIMARY KEY,
    tenKe VARCHAR(100) NOT NULL,
    maKho VARCHAR(20) NOT NULL,
    khuVuc VARCHAR(50) NOT NULL,
    viTri VARCHAR(50) NOT NULL,
    loaiKe VARCHAR(50) NOT NULL,
    sucChuaToiDa DECIMAL(12,2) NOT NULL,
    sucChuaHienTai DECIMAL(12,2) DEFAULT 0,
    loaiHangPhuHop VARCHAR(100) NOT NULL,
    trangThai VARCHAR(30) NOT NULL,
    ghiChu TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_suc_chua_max CHECK (sucChuaToiDa > 0),
    CONSTRAINT chk_suc_chua_cur CHECK (sucChuaHienTai >= 0 AND sucChuaHienTai <= sucChuaToiDa),
    FOREIGN KEY (maKho) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dữ liệu mẫu bảng Ke (5 dòng có hàng và 2 dòng trống không có hàng hóa để thử nghiệm chức năng xóa)
INSERT INTO Ke (maKe, tenKe, maKho, khuVuc, viTri, loaiKe, sucChuaToiDa, sucChuaHienTai, loaiHangPhuHop, trangThai, ghiChu) VALUES
('KTP001', 'Kệ nước suối A1', 'KTP01', 'A', 'A-01', 'Kệ pallet', 1200.00, 900.00, 'Nước suối', 'Hoạt động', 'Kệ bảo quản nước đóng chai pallet'),
('KTP002', 'Kệ nước khoáng A2', 'KTP01', 'A', 'A-02', 'Kệ pallet', 1200.00, 750.00, 'Nước khoáng', 'Hoạt động', 'Kệ lưu trữ nước khoáng thiên nhiên'),
('KTP003', 'Kệ nước tinh khiết B1', 'KTP01', 'B', 'B-01', 'Kệ pallet', 1000.00, 650.00, 'Nước tinh khiết', 'Hoạt động', 'Kệ chứa thùng nước tinh khiết 500ml'),
('KTP004', 'Kệ nước đóng bình B2', 'KTP01', 'B', 'B-02', 'Kệ pallet', 1500.00, 1100.00, 'Nước đóng bình', 'Hoạt động', 'Kệ chịu tải trọng cao chứa bình 20L'),
('KTP005', 'Kệ nước có gas C1', 'KTP01', 'C', 'C-01', 'Kệ trung tải', 800.00, 400.00, 'Nước có gas', 'Hoạt động', 'Kệ trung tải xếp lon nước có gas'),
('KTP006', 'Kệ dự phòng trống C2', 'KTP01', 'C', 'C-02', 'Kệ pallet', 1200.00, 0.00, 'Nước đóng bình', 'Hoạt động', 'Kệ trống sẵn sàng tiếp nhận hàng hóa hoặc xóa'),
('KTP007', 'Kệ kiểm kê trống D1', 'KTP01', 'D', 'D-01', 'Kệ trung tải', 600.00, 0.00, 'Nước suối', 'Hoạt động', 'Kệ trống đã xuất hết hàng hóa')
ON DUPLICATE KEY UPDATE 
    tenKe = VALUES(tenKe),
    maKho = VALUES(maKho),
    khuVuc = VALUES(khuVuc),
    viTri = VALUES(viTri),
    loaiKe = VALUES(loaiKe),
    sucChuaToiDa = VALUES(sucChuaToiDa),
    sucChuaHienTai = VALUES(sucChuaHienTai),
    loaiHangPhuHop = VALUES(loaiHangPhuHop),
    trangThai = VALUES(trangThai),
    ghiChu = VALUES(ghiChu);

-- ========================================================
-- PHẦN BỔ SUNG: BẢNG VÀ DỮ LIỆU PHÊ DUYỆT KIỂM KÊ (BAN GIÁM ĐỐC)
-- ========================================================

-- Bảng Biên bản kiểm kê
CREATE TABLE IF NOT EXISTS BIENBAN_KIEMKE (
    MaBienBanKiemKe VARCHAR(30) PRIMARY KEY,
    MaKeHoachKiemKe VARCHAR(30) NOT NULL,
    TenKeHoachKiemKe VARCHAR(200) NOT NULL,
    PhamViKiemKe VARCHAR(150) NOT NULL,
    MaKho VARCHAR(20) NOT NULL,
    NgayLap DATETIME NOT NULL,
    NguoiLap VARCHAR(100) NOT NULL,
    TrangThai ENUM('Chờ phê duyệt', 'Đã phê duyệt', 'Từ chối') NOT NULL DEFAULT 'Chờ phê duyệt',
    NguoiDuyet VARCHAR(100) NULL,
    ThoiGianDuyet DATETIME NULL,
    YKienChiDao TEXT NULL,
    LyDoTuChoi VARCHAR(255) NULL,
    NoiDungLyDoKhac TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (MaKho) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bảng Chi tiết biên bản kiểm kê
CREATE TABLE IF NOT EXISTS CHITIET_BIENBAN_KIEMKE (
    id INT AUTO_INCREMENT PRIMARY KEY,
    MaBienBanKiemKe VARCHAR(30) NOT NULL,
    MatHang VARCHAR(20) NOT NULL,
    TenHang VARCHAR(150) NOT NULL,
    TenLo VARCHAR(30) NOT NULL,
    DonViTinh VARCHAR(50) NOT NULL,
    TonHeThong INT NOT NULL,
    ThucTe INT NOT NULL,
    ChenhLech INT NOT NULL,
    FOREIGN KEY (MaBienBanKiemKe) REFERENCES BIENBAN_KIEMKE(MaBienBanKiemKe) ON DELETE CASCADE,
    FOREIGN KEY (MatHang) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bảng Nguyên nhân chênh lệch
CREATE TABLE IF NOT EXISTS NGUYENNHAN_CHENHLECH (
    MaNguyenNhan VARCHAR(20) PRIMARY KEY,
    MaBienBanKiemKe VARCHAR(30) NOT NULL,
    TenNguyenNhan VARCHAR(200) NOT NULL,
    MoTaNguyenNhan TEXT NOT NULL,
    FOREIGN KEY (MaBienBanKiemKe) REFERENCES BIENBAN_KIEMKE(MaBienBanKiemKe) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dữ liệu mẫu kiểm kê
INSERT INTO BIENBAN_KIEMKE (MaBienBanKiemKe, MaKeHoachKiemKe, TenKeHoachKiemKe, PhamViKiemKe, MaKho, NgayLap, NguoiLap, TrangThai) VALUES
('BBKK-2026-001', 'KHKK-10-01', 'Kế hoạch kiểm kê định kỳ Tháng 10 - Kho Thành Phẩm 1', 'Toàn bộ Kho Thành Phẩm 1 (KTP01)', 'KTP01', '2026-10-31 16:30:00', 'Trần Quản Lý', 'Chờ phê duyệt'),
('BBKK-2026-002', 'KHKK-09-02', 'Kế hoạch kiểm kê đột xuất Nước ngọt Cola', 'Khu vực kệ B - Kho Thành Phẩm 1', 'KTP01', '2026-09-30 17:00:00', 'Trần Quản Lý', 'Đã phê duyệt'),
('BBKK-2026-003', 'KHKK-09-01', 'Kế hoạch kiểm kê quý III - Nhóm Nước suối', 'Khu vực kệ A - Kho Thành Phẩm 1', 'KTP01', '2026-09-28 15:45:00', 'Trần Quản Lý', 'Từ chối')
ON DUPLICATE KEY UPDATE TenKeHoachKiemKe = VALUES(TenKeHoachKiemKe), TrangThai = VALUES(TrangThai);

INSERT INTO CHITIET_BIENBAN_KIEMKE (MaBienBanKiemKe, MatHang, TenHang, TenLo, DonViTinh, TonHeThong, ThucTe, ChenhLech) VALUES
('BBKK-2026-001', 'HH001', 'Nước lọc 500ml', 'LO001', 'Chai', 60, 58, -2),
('BBKK-2026-001', 'HH002', 'Nước ngọt Cola 330ml', 'LO002', 'Lon', 100, 105, 5),
('BBKK-2026-002', 'HH002', 'Nước ngọt Cola 330ml', 'LO005', 'Lon', 20, 20, 0),
('BBKK-2026-003', 'HH001', 'Nước lọc 500ml', 'LO004', 'Chai', 100, 95, -5)
ON DUPLICATE KEY UPDATE TenHang = VALUES(TenHang);

INSERT INTO NGUYENNHAN_CHENHLECH (MaNguyenNhan, MaBienBanKiemKe, TenNguyenNhan, MoTaNguyenNhan) VALUES
('NN01', 'BBKK-2026-001', 'Hao hụt rách vỏ và nhầm lẫn kiểm đếm', 'Lô LO001 (Nước lọc 500ml) thiếu 2 chai do rò rỉ rách vỏ trong quá trình bốc dỡ chưa kịp thanh lý; Lô LO002 (Nước ngọt Cola) thừa 5 lon do nhập bù từ đơn hàng khuyến mãi đợt trước chưa ghi sổ kịp thời.'),
('NN02', 'BBKK-2026-002', 'Kiểm đếm khớp sổ sách', 'Số liệu thực tế khớp hoàn toàn với sổ sách hệ thống sau khi kiểm tra chéo.'),
('NN03', 'BBKK-2026-003', 'Chưa rõ nguyên nhân thất thoát', 'Bộ phận kho chưa đưa ra được chứng từ xuất hoặc biên bản sự cố cho 5 chai nước lọc bị hụt.')
ON DUPLICATE KEY UPDATE TenNguyenNhan = VALUES(TenNguyenNhan), MoTaNguyenNhan = VALUES(MoTaNguyenNhan);



-- ========================================================
-- PHẦN BỔ SUNG: PHÂN HỆ QUẢN LÝ XUẤT KHO & TỒN KHO HÀNG HÓA
-- ========================================================

-- 5. BẢNG KHO (KHO)
CREATE TABLE IF NOT EXISTS KHO (
    MaKho VARCHAR(20) PRIMARY KEY,
    TenKho VARCHAR(100) NOT NULL,
    DiaChi VARCHAR(200),
    SucChua INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. BẢNG HÀNG HÓA (HANGHOA)
-- Thành phẩm và nguyên liệu được tổng quát hóa thành thành phẩm
CREATE TABLE IF NOT EXISTS HANGHOA (
    MaHangHoa VARCHAR(20) PRIMARY KEY,
    TenHangHoa VARCHAR(150) NOT NULL,
    DonViTinh VARCHAR(50) NOT NULL,
    LoaiHang VARCHAR(50) NOT NULL DEFAULT 'Thành phẩm'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. BẢNG KỆ LƯU TRỮ (KELUUTRU)
CREATE TABLE IF NOT EXISTS KELUUTRU (
    MaKe VARCHAR(20) PRIMARY KEY,
    TenKe VARCHAR(100) NOT NULL,
    MaKho VARCHAR(20) NOT NULL,
    ViTri VARCHAR(100),
    FOREIGN KEY (MaKho) REFERENCES KHO(MaKho) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. BẢNG LÔ HÀNG (LOHANG)
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

-- 9. BẢNG TỒN KHO (TONKHO)
CREATE TABLE IF NOT EXISTS TONKHO (
    MaKho VARCHAR(20) NOT NULL,
    MaHangHoa VARCHAR(20) NOT NULL,
    SoLuongTon INT NOT NULL DEFAULT 0,
    PRIMARY KEY (MaKho, MaHangHoa),
    FOREIGN KEY (MaKho) REFERENCES KHO(MaKho) ON DELETE CASCADE,
    FOREIGN KEY (MaHangHoa) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. BẢNG PHIẾU YÊU CẦU XUẤT KHO (PHIEUYEUCAUKHO)
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

-- 11. BẢNG CHI TIẾT PHIẾU YÊU CẦU XUẤT KHO (CHITIETPHIEUYEUCAUKHO)
CREATE TABLE IF NOT EXISTS CHITIETPHIEUYEUCAUKHO (
    MaPhieuYeuCau VARCHAR(30) NOT NULL,
    MaHangHoa VARCHAR(20) NOT NULL,
    SoLuongYeuCau INT NOT NULL,
    PRIMARY KEY (MaPhieuYeuCau, MaHangHoa),
    FOREIGN KEY (MaPhieuYeuCau) REFERENCES PHIEUYEUCAUKHO(MaPhieuYeuCau) ON DELETE CASCADE,
    FOREIGN KEY (MaHangHoa) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. BẢNG PHIẾU XUẤT KHO (PHIEUXUATKHO)
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

-- 13. BẢNG CHI TIẾT PHIẾU XUẤT KHO (CHITIETPHIEUXUATKHO)
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

-- --------------------------------------------------------
-- DỮ LIỆU MẪU THEO YÊU CẦU:
-- --------------------------------------------------------

-- 6.1. Dữ liệu kho (KHO)
INSERT INTO KHO (MaKho, TenKho, DiaChi, SucChua) VALUES
('KTP01', 'Kho Thành Phẩm 1', 'TP.HCM', 10000),
('KTP02', 'Kho Thành Phẩm 2', 'TP.HCM', 10000),
('KTP03', 'Kho Thành Phẩm 3', 'TP.HCM', 10000)
ON DUPLICATE KEY UPDATE TenKho = VALUES(TenKho), DiaChi = VALUES(DiaChi), SucChua = VALUES(SucChua);

-- 6.2. Dữ liệu hàng hóa (HANGHOA)
INSERT INTO HANGHOA (MaHangHoa, TenHangHoa, DonViTinh, LoaiHang) VALUES
('HH001', 'Nước lọc 500ml', 'Chai', 'Thành phẩm'),
('HH002', 'Nước ngọt Cola 330ml', 'Lon', 'Thành phẩm'),
('HH003', 'Nước lọc 1.5L', 'Chai', 'Thành phẩm')
ON DUPLICATE KEY UPDATE TenHangHoa = VALUES(TenHangHoa), DonViTinh = VALUES(DonViTinh), LoaiHang = VALUES(LoaiHang);

-- Kệ lưu trữ mẫu (KELUUTRU)
INSERT INTO KELUUTRU (MaKe, TenKe, MaKho, ViTri) VALUES
('KE01', 'Kệ A1 - Tầng 1', 'KTP01', 'Khu A Dãy 1'),
('KE02', 'Kệ A2 - Tầng 2', 'KTP01', 'Khu A Dãy 2'),
('KE03', 'Kệ B1 - Tầng 1', 'KTP01', 'Khu B Dãy 1')
ON DUPLICATE KEY UPDATE TenKe = VALUES(TenKe);

-- 6.5. Dữ liệu lô hàng (LOHANG)
INSERT INTO LOHANG (MaLo, MaHangHoa, NgayNhap, HanSuDung, SoLuongTon, MaKho, MaKe) VALUES
('LO001', 'HH001', '2026-09-01 08:00:00', '2027-03-01', 60, 'KTP01', 'KE01'),
('LO002', 'HH002', '2026-09-15 08:00:00', '2027-04-01', 100, 'KTP01', 'KE02'),
('LO003', 'HH003', '2026-09-25 08:00:00', '2027-06-01', 200, 'KTP01', 'KE03'),
('LO004', 'HH001', '2026-09-10 09:00:00', '2027-05-01', 100, 'KTP01', 'KE01'),
('LO005', 'HH002', '2026-09-20 10:00:00', '2027-07-01', 20, 'KTP01', 'KE02')
ON DUPLICATE KEY UPDATE SoLuongTon = VALUES(SoLuongTon), HanSuDung = VALUES(HanSuDung), NgayNhap = VALUES(NgayNhap);

-- 6.6. Dữ liệu tồn kho – bảng TONKHO
INSERT INTO TONKHO (MaKho, MaHangHoa, SoLuongTon) VALUES
('KTP01', 'HH001', 160),
('KTP01', 'HH002', 120),
('KTP01', 'HH003', 200)
ON DUPLICATE KEY UPDATE SoLuongTon = VALUES(SoLuongTon);

-- 6.3. Dữ liệu yêu cầu xuất kho (PHIEUYEUCAUKHO)
INSERT INTO PHIEUYEUCAUKHO (MaPhieuYeuCau, LoaiYeuCau, NgayYeuCau, BoPhanGui, TrangThaiYeuCau, MaKho) VALUES
('YCX001', 'Xuất kho', '2026-10-28 08:30:00', 'Bộ phận Bán hàng', 'Đã duyệt', 'KTP01'),
('YCX002', 'Xuất kho', '2026-10-29 09:15:00', 'Bộ phận Bán hàng', 'Chờ duyệt', 'KTP01'),
('YCX003', 'Xuất kho', '2026-10-30 10:00:00', 'Bộ phận Sản xuất', 'Từ chối', 'KTP01'),
('YCX004', 'Xuất kho', '2026-10-31 14:20:00', 'Bộ phận Bán hàng', 'Đã duyệt', 'KTP01'),
('YCX005', 'Xuất kho', '2026-11-01 08:45:00', 'Bộ phận Phân phối', 'Đã duyệt', 'KTP01')
ON DUPLICATE KEY UPDATE TrangThaiYeuCau = VALUES(TrangThaiYeuCau);

-- 6.4. Dữ liệu chi tiết yêu cầu xuất kho (CHITIETPHIEUYEUCAUKHO)
INSERT INTO CHITIETPHIEUYEUCAUKHO (MaPhieuYeuCau, MaHangHoa, SoLuongYeuCau) VALUES
('YCX001', 'HH001', 100),
('YCX002', 'HH002', 80),
('YCX003', 'HH003', 150),
('YCX004', 'HH002', 50),
('YCX005', 'HH003', 80)
ON DUPLICATE KEY UPDATE SoLuongYeuCau = VALUES(SoLuongYeuCau);

-- ========================================================
-- PHẦN BỔ SUNG: BẢNG VÀ DỮ LIỆU QUẢN LÝ KỆ (Ke)
-- ========================================================
CREATE TABLE IF NOT EXISTS Ke (
    maKe VARCHAR(20) PRIMARY KEY,
    tenKe VARCHAR(100) NOT NULL,
    maKho VARCHAR(20) NOT NULL,
    khuVuc VARCHAR(50) NOT NULL,
    viTri VARCHAR(50) NOT NULL,
    loaiKe VARCHAR(50) NOT NULL,
    sucChuaToiDa DECIMAL(12,2) NOT NULL,
    sucChuaHienTai DECIMAL(12,2) DEFAULT 0,
    loaiHangPhuHop VARCHAR(100) NOT NULL,
    trangThai VARCHAR(30) NOT NULL,
    ghiChu TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_suc_chua_max CHECK (sucChuaToiDa > 0),
    CONSTRAINT chk_suc_chua_cur CHECK (sucChuaHienTai >= 0 AND sucChuaHienTai <= sucChuaToiDa),
    FOREIGN KEY (maKho) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dữ liệu mẫu bảng Ke (5 dòng có hàng và 2 dòng trống không có hàng hóa để thử nghiệm chức năng xóa)
INSERT INTO Ke (maKe, tenKe, maKho, khuVuc, viTri, loaiKe, sucChuaToiDa, sucChuaHienTai, loaiHangPhuHop, trangThai, ghiChu) VALUES
('KTP001', 'Kệ nước suối A1', 'KTP01', 'A', 'A-01', 'Kệ pallet', 1200.00, 900.00, 'Nước suối', 'Hoạt động', 'Kệ bảo quản nước đóng chai pallet'),
('KTP002', 'Kệ nước khoáng A2', 'KTP01', 'A', 'A-02', 'Kệ pallet', 1200.00, 750.00, 'Nước khoáng', 'Hoạt động', 'Kệ lưu trữ nước khoáng thiên nhiên'),
('KTP003', 'Kệ nước tinh khiết B1', 'KTP01', 'B', 'B-01', 'Kệ pallet', 1000.00, 650.00, 'Nước tinh khiết', 'Hoạt động', 'Kệ chứa thùng nước tinh khiết 500ml'),
('KTP004', 'Kệ nước đóng bình B2', 'KTP01', 'B', 'B-02', 'Kệ pallet', 1500.00, 1100.00, 'Nước đóng bình', 'Hoạt động', 'Kệ chịu tải trọng cao chứa bình 20L'),
('KTP005', 'Kệ nước có gas C1', 'KTP01', 'C', 'C-01', 'Kệ trung tải', 800.00, 400.00, 'Nước có gas', 'Hoạt động', 'Kệ trung tải xếp lon nước có gas'),
('KTP006', 'Kệ dự phòng trống C2', 'KTP01', 'C', 'C-02', 'Kệ pallet', 1200.00, 0.00, 'Nước đóng bình', 'Hoạt động', 'Kệ trống sẵn sàng tiếp nhận hàng hóa hoặc xóa'),
('KTP007', 'Kệ kiểm kê trống D1', 'KTP01', 'D', 'D-01', 'Kệ trung tải', 600.00, 0.00, 'Nước suối', 'Hoạt động', 'Kệ trống đã xuất hết hàng hóa')
ON DUPLICATE KEY UPDATE 
    tenKe = VALUES(tenKe),
    maKho = VALUES(maKho),
    khuVuc = VALUES(khuVuc),
    viTri = VALUES(viTri),
    loaiKe = VALUES(loaiKe),
    sucChuaToiDa = VALUES(sucChuaToiDa),
    sucChuaHienTai = VALUES(sucChuaHienTai),
    loaiHangPhuHop = VALUES(loaiHangPhuHop),
    trangThai = VALUES(trangThai),
    ghiChu = VALUES(ghiChu);

-- ========================================================
-- PHẦN BỔ SUNG: BẢNG VÀ DỮ LIỆU PHÊ DUYỆT KIỂM KÊ (BAN GIÁM ĐỐC)
-- ========================================================

-- Bảng Biên bản kiểm kê
CREATE TABLE IF NOT EXISTS BIENBAN_KIEMKE (
    MaBienBanKiemKe VARCHAR(30) PRIMARY KEY,
    MaKeHoachKiemKe VARCHAR(30) NOT NULL,
    TenKeHoachKiemKe VARCHAR(200) NOT NULL,
    PhamViKiemKe VARCHAR(150) NOT NULL,
    MaKho VARCHAR(20) NOT NULL,
    NgayLap DATETIME NOT NULL,
    NguoiLap VARCHAR(100) NOT NULL,
    TrangThai ENUM('Chờ phê duyệt', 'Đã phê duyệt', 'Từ chối') NOT NULL DEFAULT 'Chờ phê duyệt',
    NguoiDuyet VARCHAR(100) NULL,
    ThoiGianDuyet DATETIME NULL,
    YKienChiDao TEXT NULL,
    LyDoTuChoi VARCHAR(255) NULL,
    NoiDungLyDoKhac TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (MaKho) REFERENCES warehouses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bảng Chi tiết biên bản kiểm kê
CREATE TABLE IF NOT EXISTS CHITIET_BIENBAN_KIEMKE (
    id INT AUTO_INCREMENT PRIMARY KEY,
    MaBienBanKiemKe VARCHAR(30) NOT NULL,
    MatHang VARCHAR(20) NOT NULL,
    TenHang VARCHAR(150) NOT NULL,
    TenLo VARCHAR(30) NOT NULL,
    DonViTinh VARCHAR(50) NOT NULL,
    TonHeThong INT NOT NULL,
    ThucTe INT NOT NULL,
    ChenhLech INT NOT NULL,
    FOREIGN KEY (MaBienBanKiemKe) REFERENCES BIENBAN_KIEMKE(MaBienBanKiemKe) ON DELETE CASCADE,
    FOREIGN KEY (MatHang) REFERENCES HANGHOA(MaHangHoa) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bảng Nguyên nhân chênh lệch
CREATE TABLE IF NOT EXISTS NGUYENNHAN_CHENHLECH (
    MaNguyenNhan VARCHAR(20) PRIMARY KEY,
    MaBienBanKiemKe VARCHAR(30) NOT NULL,
    TenNguyenNhan VARCHAR(200) NOT NULL,
    MoTaNguyenNhan TEXT NOT NULL,
    FOREIGN KEY (MaBienBanKiemKe) REFERENCES BIENBAN_KIEMKE(MaBienBanKiemKe) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dữ liệu mẫu kiểm kê
INSERT INTO BIENBAN_KIEMKE (MaBienBanKiemKe, MaKeHoachKiemKe, TenKeHoachKiemKe, PhamViKiemKe, MaKho, NgayLap, NguoiLap, TrangThai) VALUES
('BBKK-2026-001', 'KHKK-10-01', 'Kế hoạch kiểm kê định kỳ Tháng 10 - Kho Thành Phẩm 1', 'Toàn bộ Kho Thành Phẩm 1 (KTP01)', 'KTP01', '2026-10-31 16:30:00', 'Trần Quản Lý', 'Chờ phê duyệt'),
('BBKK-2026-002', 'KHKK-09-02', 'Kế hoạch kiểm kê đột xuất Nước ngọt Cola', 'Khu vực kệ B - Kho Thành Phẩm 1', 'KTP01', '2026-09-30 17:00:00', 'Trần Quản Lý', 'Đã phê duyệt'),
('BBKK-2026-003', 'KHKK-09-01', 'Kế hoạch kiểm kê quý III - Nhóm Nước suối', 'Khu vực kệ A - Kho Thành Phẩm 1', 'KTP01', '2026-09-28 15:45:00', 'Trần Quản Lý', 'Từ chối')
ON DUPLICATE KEY UPDATE TenKeHoachKiemKe = VALUES(TenKeHoachKiemKe), TrangThai = VALUES(TrangThai);

INSERT INTO CHITIET_BIENBAN_KIEMKE (MaBienBanKiemKe, MatHang, TenHang, TenLo, DonViTinh, TonHeThong, ThucTe, ChenhLech) VALUES
('BBKK-2026-001', 'HH001', 'Nước lọc 500ml', 'LO001', 'Chai', 60, 58, -2),
('BBKK-2026-001', 'HH002', 'Nước ngọt Cola 330ml', 'LO002', 'Lon', 100, 105, 5),
('BBKK-2026-002', 'HH002', 'Nước ngọt Cola 330ml', 'LO005', 'Lon', 20, 20, 0),
('BBKK-2026-003', 'HH001', 'Nước lọc 500ml', 'LO004', 'Chai', 100, 95, -5)
ON DUPLICATE KEY UPDATE TenHang = VALUES(TenHang);

INSERT INTO NGUYENNHAN_CHENHLECH (MaNguyenNhan, MaBienBanKiemKe, TenNguyenNhan, MoTaNguyenNhan) VALUES
('NN01', 'BBKK-2026-001', 'Hao hụt rách vỏ và nhầm lẫn kiểm đếm', 'Lô LO001 (Nước lọc 500ml) thiếu 2 chai do rò rỉ rách vỏ trong quá trình bốc dỡ chưa kịp thanh lý; Lô LO002 (Nước ngọt Cola) thừa 5 lon do nhập bù từ đơn hàng khuyến mãi đợt trước chưa ghi sổ kịp thời.'),
('NN02', 'BBKK-2026-002', 'Kiểm đếm khớp sổ sách', 'Số liệu thực tế khớp hoàn toàn với sổ sách hệ thống sau khi kiểm tra chéo.'),
('NN03', 'BBKK-2026-003', 'Chưa rõ nguyên nhân thất thoát', 'Bộ phận kho chưa đưa ra được chứng từ xuất hoặc biên bản sự cố cho 5 chai nước lọc bị hụt.')
ON DUPLICATE KEY UPDATE TenNguyenNhan = VALUES(TenNguyenNhan), MoTaNguyenNhan = VALUES(MoTaNguyenNhan);


-- ========================================================
-- BỔ SUNG DỮ LIỆU MẪU: 3 BIÊN BẢN, MỖI BIÊN BẢN 1 TRẠNG THÁI
-- ========================================================

INSERT INTO BIENBAN_KIEMKE
(MaBienBanKiemKe, MaKeHoachKiemKe, TenKeHoachKiemKe, PhamViKiemKe, MaKho, NgayLap, NguoiLap, TrangThai, NguoiDuyet, ThoiGianDuyet, YKienChiDao, LyDoTuChoi, NoiDungLyDoKhac) VALUES

-- 1. Chờ phê duyệt (chưa có người duyệt)
('BBKK-2026-004', 'KHKK-10-02', 'Kế hoạch kiểm kê định kỳ Tháng 10 - Nhóm Nước tăng lực', 'Khu vực kệ C - Kho Thành Phẩm 1', 'KTP01', '2026-10-30 14:20:00', 'Trần Quản Lý', 'Chờ phê duyệt', NULL, NULL, NULL, NULL, NULL),

-- 2. Đã phê duyệt (có người duyệt, thời gian duyệt, ý kiến chỉ đạo)
('BBKK-2026-005', 'KHKK-10-03', 'Kế hoạch kiểm kê đột xuất Nước cam ép', 'Khu vực kệ D - Kho Thành Phẩm 1', 'KTP01', '2026-10-29 09:15:00', 'Trần Quản Lý', 'Đã phê duyệt', 'Nguyễn Giám Đốc', '2026-10-29 16:40:00', 'Đồng ý với kết quả kiểm kê. Điều chỉnh sổ sách theo số liệu thực tế và theo dõi thêm hao hụt trong các đợt vận chuyển tới.', NULL, NULL),

-- 3. Từ chối (có người duyệt, lý do từ chối, nội dung lý do khác)
('BBKK-2026-006', 'KHKK-10-04', 'Kế hoạch kiểm kê quý IV - Nhóm Bia lon', 'Khu vực kệ E - Kho Thành Phẩm 1 ', 'KTP01', '2026-10-28 11:00:00', 'Trần Quản Lý', 'Từ chối', 'Nguyễn Giám Đốc', '2026-10-28 17:25:00', 'Yêu cầu kiểm kê lại toàn bộ khu vực kệ E có sự giám sát của bộ phận kế toán.', 'Khác', 'Biên bản thiếu chữ ký xác nhận của thủ kho và chênh lệch lô LO007 quá lớn chưa có chứng từ giải trình.')
ON DUPLICATE KEY UPDATE
    TenKeHoachKiemKe = VALUES(TenKeHoachKiemKe),
    TrangThai = VALUES(TrangThai),
    NguoiDuyet = VALUES(NguoiDuyet),
    ThoiGianDuyet = VALUES(ThoiGianDuyet),
    YKienChiDao = VALUES(YKienChiDao),
    LyDoTuChoi = VALUES(LyDoTuChoi),
    NoiDungLyDoKhac = VALUES(NoiDungLyDoKhac);

-- Chi tiết biên bản
INSERT INTO CHITIET_BIENBAN_KIEMKE
(MaBienBanKiemKe, MatHang, TenHang, TenLo, DonViTinh, TonHeThong, ThucTe, ChenhLech) VALUES
('BBKK-2026-004', 'HH002', 'Nước ngọt Cola 330ml', 'LO006', 'Lon', 80, 78, -2),
('BBKK-2026-004', 'HH001', 'Nước lọc 500ml', 'LO003', 'Chai', 150, 150, 0),
('BBKK-2026-005', 'HH001', 'Nước lọc 500ml', 'LO001', 'Chai', 200, 198, -2),
('BBKK-2026-005', 'HH002', 'Nước ngọt Cola 330ml', 'LO002', 'Lon', 120, 123, 3),
('BBKK-2026-006', 'HH002', 'Nước ngọt Cola 330ml', 'LO007', 'Lon', 300, 270, -30)
ON DUPLICATE KEY UPDATE TenHang = VALUES(TenHang);

-- Nguyên nhân chênh lệch
INSERT INTO NGUYENNHAN_CHENHLECH
(MaNguyenNhan, MaBienBanKiemKe, TenNguyenNhan, MoTaNguyenNhan) VALUES
('NN04', 'BBKK-2026-004', 'Hao hụt nhỏ trong quá trình lưu kho', 'Lô LO006 (Nước ngọt Cola 330ml) thiếu 2 lon do móp méo khi xếp dỡ, đang chờ lập biên bản thanh lý.'),
('NN05', 'BBKK-2026-005', 'Sai lệch do ghi nhận nhập xuất chưa kịp thời', 'Lô LO001 thiếu 2 chai do vỡ chưa ghi sổ; lô LO002 thừa 3 lon do phiếu nhập bù chưa cập nhật vào hệ thống.'),
('NN06', 'BBKK-2026-006', 'Thất thoát lớn chưa có chứng từ giải trình', 'Lô LO007 hụt 30 lon, bộ phận kho chưa cung cấp được phiếu xuất, biên bản hư hỏng hoặc chứng từ điều chuyển liên quan.')
ON DUPLICATE KEY UPDATE
    TenNguyenNhan = VALUES(TenNguyenNhan),
    MoTaNguyenNhan = VALUES(MoTaNguyenNhan);