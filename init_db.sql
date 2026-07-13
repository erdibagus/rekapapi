-- =====================================================
-- RekapBNP WiFi - Database Schema & Seed Data
-- Jalankan di phpMyAdmin atau MySQL CLI
-- =====================================================

CREATE DATABASE IF NOT EXISTS rekapwifi_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rekapwifi_db;

-- =====================================================
-- Tabel users
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  name VARCHAR(100) NOT NULL,
  role ENUM('Admin','Operator') NOT NULL DEFAULT 'Operator',
  avatar VARCHAR(10) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Password disimpan sebagai hash (bcrypt via PHP password_hash)
-- Default: admin123 dan op123
INSERT INTO users (username, password, name, role, avatar) VALUES
('admin',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin BNP', 'Admin', 'AB'),
('operator', '$2y$10$6CKr5oVsM2o2ZjIcBjYQA.kIXKd9m0sNkrCX/OwEqD4Kl5IJ0o/VW', 'Budi Santoso', 'Operator', 'BS');

-- =====================================================
-- Tabel pakets
-- =====================================================
CREATE TABLE IF NOT EXISTS pakets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  harga INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO pakets (nama, harga) VALUES
('Basic 5 Mbps',     100000),
('Standard 10 Mbps', 150000),
('Premium 20 Mbps',  200000),
('Ultra 50 Mbps',    300000);

-- =====================================================
-- Tabel pelanggan
-- =====================================================
CREATE TABLE IF NOT EXISTS pelanggan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  alamat TEXT NOT NULL,
  telepon VARCHAR(20) NOT NULL,
  paket_id INT NOT NULL,
  jatuh_tempo TINYINT NOT NULL DEFAULT 1 COMMENT 'Tanggal jatuh tempo (1-31)',
  status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  bergabung DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (paket_id) REFERENCES pakets(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO pelanggan (nama, alamat, telepon, paket_id, jatuh_tempo, status, bergabung) VALUES
('Erdi Bagus',       'Jl. Merdeka No. 12, RT 02',      '08562774511',  2, 5,  'aktif',    '2024-01-15'),
('Siti Rahma',       'Jl. Kenanga No. 7, RT 03',        '082345678901', 1, 10, 'aktif',    '2024-02-20'),
('Bambang Wibowo',   'Jl. Anggrek No. 3, RT 01',        '083456789012', 3, 15, 'aktif',    '2023-11-10'),
('Dewi Lestari',     'Jl. Mawar No. 21, RT 05',         '084567890123', 1, 20, 'aktif',    '2024-03-05'),
('Eko Putranto',     'Jl. Melati No. 8, RT 04',         '085678901234', 4, 1,  'aktif',    '2023-09-12'),
('Fitri Handayani',  'Jl. Dahlia No. 15, RT 06',        '086789012345', 2, 7,  'aktif',    '2024-04-18'),
('Gunawan Susanto',  'Jl. Flamboyan No. 4, RT 02',      '087890123456', 3, 12, 'nonaktif', '2023-07-22'),
('Hesti Wijayanti',  'Jl. Cempaka No. 9, RT 03',        '088901234567', 2, 25, 'aktif',    '2024-05-30'),
('Irfan Maulana',    'Jl. Teratai No. 6, RT 01',        '089012345678', 1, 18, 'aktif',    '2024-06-10'),
('Juliana Putri',    'Jl. Bougenville No. 11, RT 07',   '080123456789', 4, 3,  'aktif',    '2023-12-01');

-- =====================================================
-- Tabel payments
-- =====================================================
CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pelanggan_id INT NOT NULL,
  bulan TINYINT NOT NULL,
  tahun SMALLINT NOT NULL,
  nominal INT NOT NULL DEFAULT 0,
  lunas TINYINT(1) NOT NULL DEFAULT 0,
  tgl_bayar DATE DEFAULT NULL,
  keterangan TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_payment (pelanggan_id, bulan, tahun),
  FOREIGN KEY (pelanggan_id) REFERENCES pelanggan(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- Generate payments 6 bulan terakhir (via stored procedure)
-- =====================================================
DELIMITER $$

DROP PROCEDURE IF EXISTS generate_payments$$

CREATE PROCEDURE generate_payments()
BEGIN
  DECLARE done INT DEFAULT FALSE;
  DECLARE pid INT;
  DECLARE ppid INT;
  DECLARE pharga INT;
  DECLARE pstatus VARCHAR(20);

  DECLARE cur CURSOR FOR
    SELECT p.id, p.paket_id, pk.harga, p.status
    FROM pelanggan p
    JOIN pakets pk ON pk.id = p.paket_id;

  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO pid, ppid, pharga, pstatus;
    IF done THEN
      LEAVE read_loop;
    END IF;

    -- Generate 6 bulan ke belakang
    SET @m = 5;
    WHILE @m >= 0 DO
      SET @target_date = DATE_SUB(CURDATE(), INTERVAL @m MONTH);
      SET @bln = MONTH(@target_date);
      SET @thn = YEAR(@target_date);

      -- Pelanggan nonaktif hanya muncul di 3 bulan terakhir
      IF NOT (pstatus = 'nonaktif' AND @m > 2) THEN
        -- Bulan lalu ke belakang: semua lunas; bulan ini: sebagian belum
        IF @m > 0 THEN
          SET @lunas = 1;
          SET @tgl_bayar = DATE_FORMAT(@target_date, '%Y-%m-05');
          SET @ket = 'Pembayaran via transfer';
        ELSE
          -- Bulan ini: id 1,3,5,6,8,10 lunas
          IF pid IN (1, 3, 5, 6, 8, 10) THEN
            SET @lunas = 1;
            SET @tgl_bayar = DATE_FORMAT(CURDATE(), '%Y-%m-03');
            SET @ket = 'Pembayaran via transfer';
          ELSE
            SET @lunas = 0;
            SET @tgl_bayar = NULL;
            SET @ket = NULL;
          END IF;
        END IF;

        INSERT IGNORE INTO payments (pelanggan_id, bulan, tahun, nominal, lunas, tgl_bayar, keterangan)
        VALUES (pid, @bln, @thn, pharga, @lunas, @tgl_bayar, @ket);
      END IF;

      SET @m = @m - 1;
    END WHILE;
  END LOOP;
  CLOSE cur;
END$$

DELIMITER ;

CALL generate_payments();
DROP PROCEDURE IF EXISTS generate_payments;
