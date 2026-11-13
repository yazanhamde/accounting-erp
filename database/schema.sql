-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `your_database`
--

DELIMITER $$
--
-- Functions
--
CREATE FUNCTION `is_period_locked` (`p_date` DATE) RETURNS TINYINT(1) DETERMINISTIC BEGIN
    DECLARE v_count INT;
    SELECT COUNT(*) INTO v_count FROM period_locks WHERE period = DATE_FORMAT(p_date, '%Y-%m');
    RETURN v_count > 0;
END$$

CREATE FUNCTION `resolve_account_name` (`p_source` VARCHAR(50)) RETURNS VARCHAR(50) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci DETERMINISTIC BEGIN
    IF p_source IS NULL OR p_source = '' THEN
        RETURN 'suspense';
    ELSEIF p_source = 'cash' THEN
        RETURN 'cash';
    ELSEIF p_source = 'jordan' THEN
        RETURN 'bank_jordan_business';
    ELSE
        RETURN CONCAT('bank_', p_source);
    END IF;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `account_type_map`
--

CREATE TABLE `account_type_map` (
  `account_type_key` varchar(50) NOT NULL,
  `account_code` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `account_type_map`
--

INSERT INTO `account_type_map` (`account_type_key`, `account_code`) VALUES
('cash', '1010'),
('bank_jordan', '1020'),
('bank_jordan_business', '1020'),
('bank_jordan_personal', '1021'),
('bank_etihad', '1022'),
('bank_islami', '1023'),
('bank_dinarak', '1024'),
('bank_investment', '1025'),
('customer', '1100'),
('suspense', '1200'),
('checks_in_hand', '1300'),
('supplier', '2100'),
('employee', '2200'),
('car_employee', '2300'),
('personal', '2400'),
('checks_payable', '2500'),
('opening_equity', '3100'),
('equity_distribution', '3200'),
('shareholder', '3200'),
('retained_earnings', '3300'),
('personal_withdrawal', '3400'),
('external_revenue', '4100'),
('misc_income', '4100'),
('car_revenue', '4200'),
('workshop_revenue', '4300'),
('expense', '5100'),
('payroll_expense', '5200'),
('salary_expense', '5200'),
('car_expense', '5300'),
('donation', '5400'),
('cash_over_short', '5500'),
('cash_variance', '5500'),
('workshop_expense', '5600');

-- --------------------------------------------------------

--
-- Table structure for table `attachments`
--

CREATE TABLE `attachments` (
  `id` int(11) NOT NULL,
  `entity_table` varchar(50) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `record_id` int(11) NOT NULL,
  `action` enum('insert','update','delete') NOT NULL,
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_data`)),
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `bank_dinarak`
--

CREATE TABLE `bank_dinarak` (
  `id` int(11) NOT NULL,
  `tx_type` enum('deposit','withdraw') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `related_customer` varchar(150) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `linked_source_table` varchar(50) DEFAULT NULL,
  `linked_source_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `bank_dinarak`
--
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bd_after` AFTER INSERT ON `bank_dinarak` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by) VALUES ('bank_dinarak', NEW.id, 'insert', JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount,'linked',NEW.linked_source_table), NEW.created_by);
    IF NEW.linked_source_table IS NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('دينارك - ',COALESCE(NEW.related_customer,'')), 'bank_dinarak', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'bank_dinarak','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','credit',NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'bank_dinarak','credit',NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bd_after_delete` AFTER DELETE ON `bank_dinarak` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('bank_dinarak', OLD.id, 'delete', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'bank_dinarak' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bd_before_delete` BEFORE DELETE ON `bank_dinarak` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف حركة بنكية - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bd_insert` BEFORE INSERT ON `bank_dinarak` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة'; END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bd_update` AFTER UPDATE ON `bank_dinarak` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('bank_dinarak', NEW.id, 'update', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'bank_dinarak' AND source_id = NEW.id;
    IF NEW.linked_source_table IS NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (NEW.tx_date, CONCAT('دينارك - ', COALESCE(NEW.related_customer,''), ' (معدّل)'), 'bank_dinarak', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'bank_dinarak', 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'credit', NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'bank_dinarak', 'credit', NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `bank_etihad`
--

CREATE TABLE `bank_etihad` (
  `id` int(11) NOT NULL,
  `tx_type` enum('deposit','withdraw') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `usage_type` enum('transfer','purchase') DEFAULT 'transfer',
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `linked_source_table` varchar(50) DEFAULT NULL,
  `linked_source_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


--
-- Triggers `bank_etihad`
--
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_be_after` AFTER INSERT ON `bank_etihad` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by) VALUES ('bank_etihad', NEW.id, 'insert', JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount,'linked',NEW.linked_source_table), NEW.created_by);
    IF NEW.linked_source_table IS NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('بنك الاتحاد - ',NEW.usage_type), 'bank_etihad', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'bank_etihad','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','credit',NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'bank_etihad','credit',NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_be_after_delete` AFTER DELETE ON `bank_etihad` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('bank_etihad', OLD.id, 'delete', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'bank_etihad' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_be_before_delete` BEFORE DELETE ON `bank_etihad` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف حركة بنكية - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_be_insert` BEFORE INSERT ON `bank_etihad` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة'; END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_be_update` AFTER UPDATE ON `bank_etihad` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('bank_etihad', NEW.id, 'update', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'bank_etihad' AND source_id = NEW.id;
    IF NEW.linked_source_table IS NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (NEW.tx_date, CONCAT('بنك الاتحاد - ', NEW.usage_type, ' (معدّل)'), 'bank_etihad', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'bank_etihad', 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'credit', NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'bank_etihad', 'credit', NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `bank_investment`
--

CREATE TABLE `bank_investment` (
  `id` int(11) NOT NULL,
  `tx_type` enum('deposit','withdraw') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `usage_type` enum('capital','profit','other') DEFAULT 'other',
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `linked_source_table` varchar(50) DEFAULT NULL,
  `linked_source_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `bank_investment`
--
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bv_after` AFTER INSERT ON `bank_investment` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by) VALUES ('bank_investment', NEW.id, 'insert', JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount,'linked',NEW.linked_source_table), NEW.created_by);
    IF NEW.linked_source_table IS NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('البنك الاستثماري - ',NEW.usage_type), 'bank_investment', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'bank_investment','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','credit',NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'bank_investment','credit',NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bv_after_delete` AFTER DELETE ON `bank_investment` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('bank_investment', OLD.id, 'delete', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'bank_investment' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bv_before_delete` BEFORE DELETE ON `bank_investment` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف حركة بنكية - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bv_insert` BEFORE INSERT ON `bank_investment` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة'; END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bv_update` AFTER UPDATE ON `bank_investment` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('bank_investment', NEW.id, 'update', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'bank_investment' AND source_id = NEW.id;
    IF NEW.linked_source_table IS NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (NEW.tx_date, CONCAT('البنك الاستثماري - ', NEW.usage_type, ' (معدّل)'), 'bank_investment', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'bank_investment', 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'credit', NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'bank_investment', 'credit', NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `bank_islami`
--

CREATE TABLE `bank_islami` (
  `id` int(11) NOT NULL,
  `tx_type` enum('deposit','withdraw') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `usage_type` enum('pos_machine','check','other') DEFAULT 'pos_machine',
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `linked_source_table` varchar(50) DEFAULT NULL,
  `linked_source_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


--
-- Triggers `bank_islami`
--
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bi_after` AFTER INSERT ON `bank_islami` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by) VALUES ('bank_islami', NEW.id, 'insert', JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount,'linked',NEW.linked_source_table), NEW.created_by);
    IF NEW.linked_source_table IS NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('البنك الإسلامي - ',NEW.usage_type), 'bank_islami', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'bank_islami','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','credit',NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'bank_islami','credit',NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bi_after_delete` AFTER DELETE ON `bank_islami` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('bank_islami', OLD.id, 'delete', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'bank_islami' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bi_before_delete` BEFORE DELETE ON `bank_islami` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف حركة بنكية - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bi_insert` BEFORE INSERT ON `bank_islami` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة'; END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bank_direct_bi_update` AFTER UPDATE ON `bank_islami` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('bank_islami', NEW.id, 'update', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'bank_islami' AND source_id = NEW.id;
    IF NEW.linked_source_table IS NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (NEW.tx_date, CONCAT('البنك الإسلامي - ', NEW.usage_type, ' (معدّل)'), 'bank_islami', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'bank_islami', 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'credit', NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'bank_islami', 'credit', NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `bank_jordan`
--

CREATE TABLE `bank_jordan` (
  `id` int(11) NOT NULL,
  `tx_type` enum('deposit','withdraw') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `usage_type` enum('personal','business') DEFAULT 'business',
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `linked_source_table` varchar(50) DEFAULT NULL,
  `linked_source_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


--
-- Triggers `bank_jordan`
--
DELIMITER $$
CREATE TRIGGER `trg_bj_after_delete` AFTER DELETE ON `bank_jordan` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('bank_jordan', OLD.id, 'delete', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'bank_jordan' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bj_after_insert` AFTER INSERT ON `bank_jordan` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    DECLARE v_account VARCHAR(30);
    SET v_account = CONCAT('bank_jordan_', NEW.usage_type);
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by) VALUES ('bank_jordan', NEW.id, 'insert', JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount,'linked',NEW.linked_source_table), NEW.created_by);
    IF NEW.linked_source_table IS NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('بنك الأردن - ',NEW.usage_type), 'bank_jordan', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,v_account,'debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','credit',NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'suspense','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,v_account,'credit',NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bj_after_update` AFTER UPDATE ON `bank_jordan` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    DECLARE v_account VARCHAR(30);
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('bank_jordan', NEW.id, 'update', JSON_OBJECT('tx_type',OLD.tx_type,'amount',OLD.amount), JSON_OBJECT('tx_type',NEW.tx_type,'amount',NEW.amount), @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'bank_jordan' AND source_id = NEW.id;

    IF NEW.linked_source_table IS NULL THEN
        SET v_account = CONCAT('bank_jordan_', NEW.usage_type);
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (NEW.tx_date, CONCAT('بنك الأردن - ', NEW.usage_type, ' (معدّل)'), 'bank_jordan', NEW.id, 0);
        SET v_id = LAST_INSERT_ID();
        IF NEW.tx_type = 'deposit' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, v_account, 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'credit', NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'suspense', 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, v_account, 'credit', NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_bj_before_insert` BEFORE INSERT ON `bank_jordan` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة محاسبياً، لا يمكن إضافة حركة بتاريخها';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `bank_statement_lines`
--

CREATE TABLE `bank_statement_lines` (
  `id` int(11) NOT NULL,
  `bank_name` varchar(50) NOT NULL,
  `stmt_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `imported_batch` varchar(100) DEFAULT NULL,
  `matched_table` varchar(50) DEFAULT NULL,
  `matched_id` int(11) DEFAULT NULL,
  `matched_at` datetime DEFAULT NULL,
  `matched_by` int(11) DEFAULT NULL,
  `status` enum('unmatched','auto_matched','manual_matched','ignored') DEFAULT 'unmatched',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `car_employees`
--

CREATE TABLE `car_employees` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `car_info` varchar(150) DEFAULT NULL,
  `base_salary` decimal(14,2) DEFAULT 0.00 COMMENT 'الراتب الأساسي الشهري',
  `notes` text DEFAULT NULL,
  `balance` decimal(14,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `car_employee_transactions`
--

CREATE TABLE `car_employee_transactions` (
  `id` int(11) NOT NULL,
  `car_employee_id` int(11) NOT NULL,
  `type` enum('revenue','expense','advance','deduction','ads','salary','bonus','fuel','parts','vehicle_maintenance','secretary_salary') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `customer_count` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `paid_from_bank` varchar(50) DEFAULT NULL,
  `no_bank_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `car_employee_transactions`
--
DELIMITER $$
CREATE TRIGGER `trg_car_after_delete` AFTER DELETE ON `car_employee_transactions` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by) VALUES ('car_employee_transactions', OLD.id, 'delete', JSON_OBJECT('car_employee_id',OLD.car_employee_id,'type',OLD.type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'car_employee_transactions' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_car_after_insert` AFTER INSERT ON `car_employee_transactions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by) VALUES ('car_employee_transactions', NEW.id, 'insert', JSON_OBJECT('car_employee_id',NEW.car_employee_id,'type',NEW.type,'amount',NEW.amount), NEW.created_by);
    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('سائق #',NEW.car_employee_id,' - ',NEW.type), 'car_employee_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL OR NEW.type IN ('revenue','expense','deduction'));
    SET v_id = LAST_INSERT_ID();
    IF NEW.type = 'revenue' THEN
        -- لو السائق حدد إنه حط الفلوس ببنك معيّن (paid_from_bank)، الإيراد بيروح لهداك البنك.
        -- لو ما حدد شي (سائب فاضي)، منفترض إنها كاش فعلي بالصندوق.
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, IF(NEW.paid_from_bank IS NULL OR NEW.paid_from_bank = '', 'cash', resolve_account_name(NEW.paid_from_bank)), 'debit', NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'car_revenue','credit',NEW.amount);
    ELSEIF NEW.type = 'expense' THEN
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'car_expense','debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'car_employee',NEW.car_employee_id,'credit',NEW.amount);
    ELSEIF NEW.type = 'deduction' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'car_employee',NEW.car_employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'misc_income','credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'car_employee',NEW.car_employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank),'credit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_car_after_update` AFTER UPDATE ON `car_employee_transactions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('car_employee_transactions', NEW.id, 'update', JSON_OBJECT('type',OLD.type,'amount',OLD.amount), JSON_OBJECT('type',NEW.type,'amount',NEW.amount), @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'car_employee_transactions' AND source_id = NEW.id;

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('سائق #',NEW.car_employee_id,' - ',NEW.type), 'car_employee_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL OR NEW.type IN ('revenue','expense','deduction'));
    SET v_id = LAST_INSERT_ID();
    IF NEW.type = 'revenue' THEN
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, IF(NEW.paid_from_bank IS NULL OR NEW.paid_from_bank = '', 'cash', resolve_account_name(NEW.paid_from_bank)), 'debit', NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'car_revenue','credit',NEW.amount);
    ELSEIF NEW.type = 'expense' THEN
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'car_expense','debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'car_employee',NEW.car_employee_id,'credit',NEW.amount);
    ELSEIF NEW.type = 'deduction' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'car_employee',NEW.car_employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'misc_income','credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'car_employee',NEW.car_employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank),'credit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_car_before_delete` BEFORE DELETE ON `car_employee_transactions` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_car_before_insert` BEFORE INSERT ON `car_employee_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة'; END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_car_before_update` BEFORE UPDATE ON `car_employee_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `cash_box`
--

CREATE TABLE `cash_box` (
  `id` int(11) NOT NULL,
  `count_date` date NOT NULL,
  `expected_amount` decimal(14,2) NOT NULL,
  `actual_amount` decimal(14,2) NOT NULL,
  `difference` decimal(14,2) GENERATED ALWAYS AS (`actual_amount` - `expected_amount`) STORED,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `cash_box`
--
DELIMITER $$
CREATE TRIGGER `trg_cash_after_delete` AFTER DELETE ON `cash_box` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('cash_box', OLD.id, 'delete', JSON_OBJECT('expected',OLD.expected_amount,'actual',OLD.actual_amount,'diff',OLD.difference), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'cash_box' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_cash_after_insert` AFTER INSERT ON `cash_box` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by) VALUES ('cash_box', NEW.id, 'insert', JSON_OBJECT('expected',NEW.expected_amount,'actual',NEW.actual_amount,'diff',NEW.difference), NEW.created_by);
    IF NEW.difference <> 0 THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (NEW.count_date, CONCAT('فرق عد كاش - ', IF(NEW.difference > 0, 'زيادة', 'عجز')), 'cash_box', NEW.id, 1);
        SET v_id = LAST_INSERT_ID();
        IF NEW.difference > 0 THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'cash','debit',NEW.difference);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'cash_variance','credit',NEW.difference);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'cash_variance','debit',ABS(NEW.difference));
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'cash','credit',ABS(NEW.difference));
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_cash_after_update` AFTER UPDATE ON `cash_box` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('cash_box', NEW.id, 'update', JSON_OBJECT('diff',OLD.difference), JSON_OBJECT('diff',NEW.difference), @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'cash_box' AND source_id = NEW.id;

    IF NEW.difference <> 0 THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (NEW.count_date, CONCAT('فرق عد كاش (معدّل) - ', IF(NEW.difference > 0, 'زيادة', 'عجز')), 'cash_box', NEW.id, 1);
        SET v_id = LAST_INSERT_ID();
        IF NEW.difference > 0 THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'cash','debit',NEW.difference);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'cash_over_short','credit',NEW.difference);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'cash_over_short','debit',-NEW.difference);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'cash','credit',-NEW.difference);
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_cash_before_delete` BEFORE DELETE ON `cash_box` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف عدّ كاش - فقط المالك';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `cash_transactions`
--

CREATE TABLE `cash_transactions` (
  `id` int(11) NOT NULL,
  `tx_type` enum('deposit','withdraw') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `linked_source_table` varchar(50) DEFAULT NULL,
  `linked_source_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
  `id` int(11) NOT NULL,
  `account_code` varchar(30) NOT NULL,
  `account_name_ar` varchar(150) NOT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `is_control_account` tinyint(1) DEFAULT 0,
  `normal_balance` enum('debit','credit') NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chart_of_accounts`
--

INSERT INTO `chart_of_accounts` (`id`, `account_code`, `account_name_ar`, `account_type`, `is_control_account`, `normal_balance`, `is_active`, `notes`, `created_at`) VALUES
(1, '2500', 'شيكات مستحقة الدفع (Checks Payable)', 'liability', 0, 'credit', 1, 'أضيف تلقائياً - migration_fix_coa_gaps', '2026-07-16 13:05:06'),
(2, '5500', 'فروقات جرد الكاش (Cash Over/Short)', 'expense', 0, 'debit', 1, 'أضيف تلقائياً - migration_fix_coa_gaps', '2026-07-16 13:05:06'),
(4, '1010', 'كاش الصندوق', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(5, '1020', 'بنك الأردني - مؤسسة', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(6, '1021', 'بنك الأردني - شخصي', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(7, '1022', 'بنك الاتحاد', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(8, '1023', 'بنك الإسلامي', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(9, '1024', 'محفظة دينارك', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(10, '1025', 'البنك الاستثماري', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(11, '1100', 'ذمم العملاء', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(12, '1200', 'حساب معلّق (Suspense)', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(13, '1300', 'شيكات بالصندوق (Checks in hand)', 'asset', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(15, '2100', 'ذمم الموردين', 'liability', 0, 'credit', 1, NULL, '2026-07-16 13:25:33'),
(16, '2200', 'مستحقات الموظفين', 'liability', 0, 'credit', 1, NULL, '2026-07-16 13:25:33'),
(17, '2300', 'أمانات سائقي السيارات', 'liability', 0, 'credit', 1, NULL, '2026-07-16 13:25:33'),
(18, '2400', 'دائن/مدين شخصي', 'liability', 0, 'credit', 1, NULL, '2026-07-16 13:25:33'),
(20, '3100', 'رأس المال الافتتاحي', 'equity', 0, 'credit', 1, NULL, '2026-07-16 13:25:33'),
(21, '3200', 'حقوق المساهمين وتوزيعات الأرباح', 'equity', 0, 'credit', 1, NULL, '2026-07-16 13:25:33'),
(23, '4100', 'إيرادات خارجية / متنوعة', 'revenue', 0, 'credit', 1, NULL, '2026-07-16 13:25:33'),
(24, '4200', 'إيرادات سائقي السيارات', 'revenue', 0, 'credit', 1, NULL, '2026-07-16 13:25:33'),
(26, '5100', 'مصاريف تشغيلية عامة', 'expense', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(27, '5200', 'رواتب وأجور', 'expense', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(28, '5300', 'مصاريف السيارات', 'expense', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(29, '5400', 'تبرعات', 'expense', 0, 'debit', 1, NULL, '2026-07-16 13:25:33'),
(57, '3300', 'الأرباح المحتجزة (غير موزّعة)', 'equity', 0, 'credit', 1, NULL, '2026-07-18 07:19:57'),
(85, '3400', 'سحوبات المالك الشخصية', 'equity', 0, 'credit', 1, NULL, '2026-07-18 10:49:43'),
(86, '4300', 'إيراد المشغل', 'revenue', 0, 'credit', 1, 'أضيف تلقائياً - migration_workshop_module', '2026-07-22 08:45:52'),
(87, '5600', 'مصاريف المشغل', 'expense', 0, 'debit', 1, 'أضيف تلقائياً - migration_workshop_module', '2026-07-22 08:45:52');

--
-- Triggers `chart_of_accounts`
--
DELIMITER $$
CREATE TRIGGER `trg_coa_before_insert` BEFORE INSERT ON `chart_of_accounts` FOR EACH ROW BEGIN
    IF NEW.account_type IN ('asset','expense') AND NEW.normal_balance <> 'debit' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ محاسبي: حسابات الأصول والمصاريف لازم يكون normal_balance = debit';
    END IF;
    IF NEW.account_type IN ('liability','equity','revenue') AND NEW.normal_balance <> 'credit' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ محاسبي: حسابات الالتزامات/حقوق الملكية/الإيرادات لازم يكون normal_balance = credit';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_coa_before_update` BEFORE UPDATE ON `chart_of_accounts` FOR EACH ROW BEGIN
    IF NEW.account_type IN ('asset','expense') AND NEW.normal_balance <> 'debit' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ محاسبي: حسابات الأصول والمصاريف لازم يكون normal_balance = debit';
    END IF;
    IF NEW.account_type IN ('liability','equity','revenue') AND NEW.normal_balance <> 'credit' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ محاسبي: حسابات الالتزامات/حقوق الملكية/الإيرادات لازم يكون normal_balance = credit';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `chat_log`
--

CREATE TABLE `chat_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_message` text NOT NULL,
  `parsed_action` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parsed_action`)),
  `bot_reply` text DEFAULT NULL,
  `status` enum('success','failed','needs_clarification') DEFAULT 'success',
  `action_type` varchar(50) DEFAULT NULL,
  `entity_table` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `undone` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `chat_pending_actions`
--

CREATE TABLE `chat_pending_actions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`action_json`)),
  `summary` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `pending_image_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `checks`
--

CREATE TABLE `checks` (
  `id` int(11) NOT NULL,
  `check_number` varchar(50) DEFAULT NULL,
  `direction` enum('incoming','outgoing') NOT NULL,
  `party_name` varchar(150) NOT NULL,
  `entity_type` enum('customer','supplier','person') DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `linked_tx_table` varchar(30) DEFAULT NULL,
  `linked_tx_id` int(11) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `bank_name` varchar(50) DEFAULT 'islami',
  `due_date` date NOT NULL,
  `status` enum('pending','cleared','bounced') DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `notified_at` datetime DEFAULT NULL,
  `cleared_via_bank` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `checks`
--
DELIMITER $$
CREATE TRIGGER `trg_chk2_after_insert` AFTER INSERT ON `checks` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.due_date, CONCAT('شيك ', NEW.direction, ' - ', NEW.party_name), 'checks', NEW.id, 0);
    SET v_entry_id = LAST_INSERT_ID();

    IF NEW.direction = 'incoming' THEN
        -- شيك مستلم = أصل "شيكات تحت التحصيل"، والمقابل حالياً suspense لأنه مش مربوط بجدول عميل مباشرة
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'checks_in_hand','debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'suspense','credit',NEW.amount);
    ELSE
        -- شيك صادر = التزام "شيكات مستحقة الدفع"
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'suspense','debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'checks_payable','credit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_chk2_after_update` AFTER UPDATE ON `checks` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;

    -- الحالة 1: كان قيد الانتظار وهلق صار "تم الصرف" أو "مرتجع" -> قيد التسوية الأول (كان موجود قبل)
    IF OLD.status = 'pending' AND NEW.status IN ('cleared','bounced') THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (CURDATE(), CONCAT('تسوية شيك #', NEW.id, ' - ', NEW.status), 'checks_settlement', NEW.id, NEW.cleared_via_bank IS NOT NULL);
        SET v_entry_id = LAST_INSERT_ID();

        IF NEW.direction = 'incoming' AND NEW.status = 'cleared' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.cleared_via_bank),'debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'checks_in_hand','credit',NEW.amount);
        ELSEIF NEW.direction = 'incoming' AND NEW.status = 'bounced' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'suspense','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'checks_in_hand','credit',NEW.amount);
        ELSEIF NEW.direction = 'outgoing' AND NEW.status = 'cleared' THEN
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'checks_payable','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.cleared_via_bank),'credit',NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'checks_payable','debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'suspense','credit',NEW.amount);
        END IF;

    -- الحالة 2 (جديدة): كان "تم الصرف" فعلاً (قيد اتسجل وفلوس دخلت/طلعت من بنك حقيقي)
    -- وهلق ترجع لـ"قيد الانتظار" أو صار "مرتجع" -> لازم نعكس نفس القيد الأول بالضبط
    ELSEIF OLD.status = 'cleared' AND NEW.status != 'cleared' AND OLD.cleared_via_bank IS NOT NULL THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (CURDATE(), CONCAT('عكس تسوية شيك #', NEW.id, ' - كانت تم الصرف وصارت ', NEW.status), 'checks_settlement_reversal', NEW.id, 1);
        SET v_entry_id = LAST_INSERT_ID();

        IF OLD.direction = 'incoming' THEN
            -- كانت: مدين البنك / دائن checks_in_hand -> نعكسها: دائن البنك / مدين checks_in_hand
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id, resolve_account_name(OLD.cleared_via_bank),'credit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'checks_in_hand','debit',NEW.amount);
        ELSE
            -- كانت: مدين checks_payable / دائن البنك -> نعكسها: دائن checks_payable / مدين البنك
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id, resolve_account_name(OLD.cleared_via_bank),'debit',NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_entry_id,'checks_payable','credit',NEW.amount);
        END IF;
    END IF;

    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('checks', NEW.id, 'update', JSON_OBJECT('status',OLD.status,'cleared_via_bank',OLD.cleared_via_bank), JSON_OBJECT('status',NEW.status,'cleared_via_bank',NEW.cleared_via_bank), @current_user_id);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_chk2_before_insert` BEFORE INSERT ON `checks` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.due_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة محاسبياً';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_chk_after_delete` AFTER DELETE ON `checks` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('checks', OLD.id, 'delete', JSON_OBJECT('direction',OLD.direction,'amount',OLD.amount,'party_name',OLD.party_name), @current_user_id);
    DELETE FROM journal_entries
    WHERE source_table IN ('checks','checks_settlement','checks_settlement_reversal') AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_chk_after_insert` AFTER INSERT ON `checks` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by)
    VALUES ('checks', NEW.id, 'insert', JSON_OBJECT('direction',NEW.direction,'amount',NEW.amount,'party_name',NEW.party_name,'due_date',NEW.due_date), NEW.created_by);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_chk_before_delete` BEFORE DELETE ON `checks` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف شيك - فقط المالك';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `pos_id` varchar(50) DEFAULT NULL,
  `source` enum('local','pos') NOT NULL DEFAULT 'local',
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `balance` decimal(14,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `customer_transactions`
--

CREATE TABLE `customer_transactions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `type` enum('debit','credit') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `source` enum('local','pos') NOT NULL DEFAULT 'local',
  `pos_ref_type` varchar(20) DEFAULT NULL COMMENT 'sale مثلاً',
  `pos_ref_id` varchar(50) DEFAULT NULL COMMENT 'رقم الفاتورة/العملية بنظام الـ POS',
  `created_at` datetime DEFAULT current_timestamp(),
  `paid_from_bank` varchar(50) DEFAULT NULL COMMENT 'jordan/etihad/islami/dinarak/investment/cash - اختياري',
  `no_bank_reason` varchar(255) DEFAULT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `amount_excl_tax` decimal(14,2) DEFAULT NULL COMMENT 'المبلغ قبل الضريبة - لو NULL يعني amount ما فيه ضريبة أصلاً',
  `tax_rate` decimal(5,2) DEFAULT NULL,
  `tax_amount` decimal(14,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


--
-- Triggers `customer_transactions`
--
DELIMITER $$
CREATE TRIGGER `trg_ct_after_delete` AFTER DELETE ON `customer_transactions` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('customer_transactions', OLD.id, 'delete', JSON_OBJECT('customer_id',OLD.customer_id,'type',OLD.type,'amount',OLD.amount,'tx_date',OLD.tx_date), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'customer_transactions' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ct_after_insert` AFTER INSERT ON `customer_transactions` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by)
    VALUES ('customer_transactions', NEW.id, 'insert', JSON_OBJECT('customer_id',NEW.customer_id,'type',NEW.type,'amount',NEW.amount,'tx_date',NEW.tx_date), NEW.created_by);

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('حركة عميل #', NEW.customer_id, ' - ', COALESCE(NEW.description,'')), 'customer_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    IF NEW.type = 'debit' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'customer',NEW.customer_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'customer',NEW.customer_id,'credit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'debit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ct_after_update` AFTER UPDATE ON `customer_transactions` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('customer_transactions', NEW.id, 'update',
        JSON_OBJECT('type',OLD.type,'amount',OLD.amount,'tx_date',OLD.tx_date),
        JSON_OBJECT('type',NEW.type,'amount',NEW.amount,'tx_date',NEW.tx_date),
        @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'customer_transactions' AND source_id = NEW.id;

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('حركة عميل #', NEW.customer_id, ' - ', COALESCE(NEW.description,'')), 'customer_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    IF NEW.type = 'debit' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'customer',NEW.customer_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'customer',NEW.customer_id,'credit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'debit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ct_balance_after_delete` AFTER DELETE ON `customer_transactions` FOR EACH ROW BEGIN
    UPDATE customers
    SET balance = (
        SELECT COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0)
        FROM customer_transactions WHERE customer_id = OLD.customer_id
    ),
    updated_at = NOW()
    WHERE id = OLD.customer_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ct_balance_after_insert` AFTER INSERT ON `customer_transactions` FOR EACH ROW BEGIN
    UPDATE customers
    SET balance = (
        SELECT COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0)
        FROM customer_transactions WHERE customer_id = NEW.customer_id
    ),
    updated_at = NOW()
    WHERE id = NEW.customer_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ct_balance_after_update` AFTER UPDATE ON `customer_transactions` FOR EACH ROW BEGIN
    UPDATE customers
    SET balance = (
        SELECT COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0)
        FROM customer_transactions WHERE customer_id = NEW.customer_id
    ),
    updated_at = NOW()
    WHERE id = NEW.customer_id;

    IF OLD.customer_id <> NEW.customer_id THEN
        UPDATE customers
        SET balance = (
            SELECT COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0)
            FROM customer_transactions WHERE customer_id = OLD.customer_id
        ),
        updated_at = NOW()
        WHERE id = OLD.customer_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ct_before_delete` BEFORE DELETE ON `customer_transactions` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف حركات مالية - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ct_before_insert` BEFORE INSERT ON `customer_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة محاسبياً، لا يمكن إضافة حركة بتاريخها';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ct_before_update` BEFORE UPDATE ON `customer_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `employee_type` enum('regular','driver') NOT NULL DEFAULT 'regular',
  `base_salary` decimal(14,2) DEFAULT 0.00 COMMENT 'الراتب الأساسي الشهري',
  `notes` text DEFAULT NULL,
  `balance` decimal(14,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `employee_transactions`
--

CREATE TABLE `employee_transactions` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `type` enum('salary','bonus','advance','deduction','payment') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `paid_from_bank` varchar(50) DEFAULT NULL,
  `no_bank_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `employee_transactions`
--
DELIMITER $$
CREATE TRIGGER `trg_emp_after_delete` AFTER DELETE ON `employee_transactions` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by) VALUES ('employee_transactions', OLD.id, 'delete', JSON_OBJECT('employee_id',OLD.employee_id,'type',OLD.type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'employee_transactions' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_emp_after_insert` AFTER INSERT ON `employee_transactions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by) VALUES ('employee_transactions', NEW.id, 'insert', JSON_OBJECT('employee_id',NEW.employee_id,'type',NEW.type,'amount',NEW.amount), NEW.created_by);
    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('موظف #',NEW.employee_id,' - ',NEW.type), 'employee_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL OR NEW.type='deduction');
    SET v_id = LAST_INSERT_ID();
    IF NEW.type = 'salary' THEN
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'salary_expense','debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'credit',NEW.amount);
    ELSEIF NEW.type = 'bonus' THEN
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'salary_expense','debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'credit',NEW.amount);
    ELSEIF NEW.type = 'advance' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank),'credit',NEW.amount);
    ELSEIF NEW.type = 'payment' THEN
        -- تسليم راتب مستحق فعلياً - فلوس طالعة من بنك/كاش، وبتنقص مستحق الموظف
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank),'credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'misc_income','credit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_emp_after_update` AFTER UPDATE ON `employee_transactions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('employee_transactions', NEW.id, 'update', JSON_OBJECT('type',OLD.type,'amount',OLD.amount), JSON_OBJECT('type',NEW.type,'amount',NEW.amount), @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'employee_transactions' AND source_id = NEW.id;

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('موظف #',NEW.employee_id,' - ',NEW.type), 'employee_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL OR NEW.type='deduction');
    SET v_id = LAST_INSERT_ID();
    IF NEW.type = 'salary' THEN
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'salary_expense','debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'credit',NEW.amount);
    ELSEIF NEW.type = 'bonus' THEN
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'salary_expense','debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'credit',NEW.amount);
    ELSEIF NEW.type = 'advance' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank),'credit',NEW.amount);
    ELSEIF NEW.type = 'payment' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank),'credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'employee',NEW.employee_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id,'misc_income','credit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_emp_before_delete` BEFORE DELETE ON `employee_transactions` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_emp_before_insert` BEFORE INSERT ON `employee_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة'; END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_emp_before_update` BEFORE UPDATE ON `employee_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `category` enum('expense','personal_withdrawal','donation') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `paid_from_bank` varchar(50) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `no_bank_reason` varchar(255) DEFAULT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `amount_excl_tax` decimal(14,2) DEFAULT NULL,
  `tax_rate` decimal(5,2) DEFAULT NULL,
  `tax_amount` decimal(14,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


--
-- Triggers `expenses`
--
DELIMITER $$
CREATE TRIGGER `trg_exp_after_delete` AFTER DELETE ON `expenses` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('expenses', OLD.id, 'delete', JSON_OBJECT('category',OLD.category,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'expenses' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_exp_after_insert` AFTER INSERT ON `expenses` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by)
    VALUES ('expenses', NEW.id, 'insert', JSON_OBJECT('category',NEW.category,'amount',NEW.amount,'paid_from_bank',NEW.paid_from_bank,'tx_date',NEW.tx_date), NEW.created_by);

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT(NEW.category,' - ',COALESCE(NEW.description,'')), 'expenses', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, NEW.category, NULL, 'debit', NEW.amount);
    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL, 'credit', NEW.amount);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_exp_after_update` AFTER UPDATE ON `expenses` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('expenses', NEW.id, 'update', JSON_OBJECT('category',OLD.category,'amount',OLD.amount,'paid_from_bank',OLD.paid_from_bank), JSON_OBJECT('category',NEW.category,'amount',NEW.amount,'paid_from_bank',NEW.paid_from_bank), @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'expenses' AND source_id = NEW.id;

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT(NEW.category,' - ',COALESCE(NEW.description,'')), 'expenses', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, NEW.category, NULL, 'debit', NEW.amount);
    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL, 'credit', NEW.amount);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_exp_before_delete` BEFORE DELETE ON `expenses` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_exp_before_insert` BEFORE INSERT ON `expenses` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة محاسبياً';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_exp_before_update` BEFORE UPDATE ON `expenses` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `external_revenues`
--

CREATE TABLE `external_revenues` (
  `id` int(11) NOT NULL,
  `source_person` varchar(150) NOT NULL COMMENT 'مين جاب الإيراد، مثلاً: رمزي',
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `paid_from_bank` varchar(50) DEFAULT NULL COMMENT 'jordan/etihad/islami/dinarak/investment/cash - وين راحت الفلوس',
  `no_bank_reason` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


--
-- Triggers `external_revenues`
--
DELIMITER $$
CREATE TRIGGER `trg_extrev_after_delete` AFTER DELETE ON `external_revenues` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('external_revenues', OLD.id, 'delete', JSON_OBJECT('source_person',OLD.source_person,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'external_revenues' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_extrev_after_insert` AFTER INSERT ON `external_revenues` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by)
    VALUES ('external_revenues', NEW.id, 'insert', JSON_OBJECT('source_person',NEW.source_person,'amount',NEW.amount,'tx_date',NEW.tx_date), NEW.created_by);

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('إيراد خارجي - ', NEW.source_person, ' - ', COALESCE(NEW.description,'')), 'external_revenues', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    -- الفلوس دخلت (مدين على الحساب/الكاش) مقابل إيراد خارجي (دائن)
    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL, 'debit', NEW.amount);
    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, 'external_revenue', NULL, 'credit', NEW.amount);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_extrev_after_update` AFTER UPDATE ON `external_revenues` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('external_revenues', NEW.id, 'update', JSON_OBJECT('source_person',OLD.source_person,'amount',OLD.amount), JSON_OBJECT('source_person',NEW.source_person,'amount',NEW.amount), @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'external_revenues' AND source_id = NEW.id;

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('إيراد خارجي - ', NEW.source_person, ' - ', COALESCE(NEW.description,'')), 'external_revenues', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL, 'debit', NEW.amount);
    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, 'external_revenue', NULL, 'credit', NEW.amount);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_extrev_before_delete` BEFORE DELETE ON `external_revenues` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_extrev_before_insert` BEFORE INSERT ON `external_revenues` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة محاسبياً';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_extrev_before_update` BEFORE UPDATE ON `external_revenues` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `source_table` varchar(50) NOT NULL,
  `source_id` int(11) NOT NULL,
  `settled_via_bank` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `journal_lines`
--

CREATE TABLE `journal_lines` (
  `id` int(11) NOT NULL,
  `entry_id` int(11) NOT NULL,
  `account_type` varchar(30) NOT NULL,
  `account_ref_id` int(11) DEFAULT NULL,
  `side` enum('debit','credit') NOT NULL,
  `amount` decimal(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `opening_balances`
--

CREATE TABLE `opening_balances` (
  `account_key` varchar(30) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


--
-- Triggers `opening_balances`
--
DELIMITER $$
CREATE TRIGGER `trg_ob_after_delete` AFTER DELETE ON `opening_balances` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('opening_balances', 0, 'delete', JSON_OBJECT('account_key',OLD.account_key,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'opening_balances' AND description LIKE CONCAT('رصيد افتتاحي - ', OLD.account_key, ' - %');
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ob_after_insert` AFTER INSERT ON `opening_balances` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by)
    VALUES ('opening_balances', 0, 'insert', JSON_OBJECT('account_key',NEW.account_key,'amount',NEW.amount,'balance_date',NEW.balance_date), NEW.updated_by);

    -- cash_diff مش حساب ميزانية حقيقي (مجرد رقم مرجعي لبطاقة العجز/الزيادة) - ما بيتسجل قيد له
    IF NEW.amount <> 0 AND NEW.account_key <> 'cash_diff' THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (NEW.balance_date, CONCAT('رصيد افتتاحي - ', NEW.account_key, ' - ', COALESCE(NEW.notes,'')), 'opening_balances', 0, 1);
        SET v_entry_id = LAST_INSERT_ID();
        IF NEW.amount > 0 THEN
            INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, NEW.account_key, NULL, 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, 'opening_equity', NULL, 'credit', NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, 'opening_equity', NULL, 'debit', -NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, NEW.account_key, NULL, 'credit', -NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ob_after_update` AFTER UPDATE ON `opening_balances` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;

    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('opening_balances', 0, 'update',
        JSON_OBJECT('account_key',OLD.account_key,'amount',OLD.amount,'balance_date',OLD.balance_date),
        JSON_OBJECT('account_key',NEW.account_key,'amount',NEW.amount,'balance_date',NEW.balance_date),
        NEW.updated_by);

    -- نمسح القيد القديم لنفس الحساب ونولّد قيد جديد بالقيمة المحدّثة
    DELETE FROM journal_entries WHERE source_table = 'opening_balances'
        AND description LIKE CONCAT('رصيد افتتاحي - ', OLD.account_key, ' - %');

    IF NEW.amount <> 0 AND NEW.account_key <> 'cash_diff' THEN
        INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
        VALUES (NEW.balance_date, CONCAT('رصيد افتتاحي - ', NEW.account_key, ' - ', COALESCE(NEW.notes,'')), 'opening_balances', 0, 1);
        SET v_entry_id = LAST_INSERT_ID();
        IF NEW.amount > 0 THEN
            INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, NEW.account_key, NULL, 'debit', NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, 'opening_equity', NULL, 'credit', NEW.amount);
        ELSE
            INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, 'opening_equity', NULL, 'debit', -NEW.amount);
            INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, NEW.account_key, NULL, 'credit', -NEW.amount);
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_ob_before_delete` BEFORE DELETE ON `opening_balances` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية تعديل/حذف الرصيد الافتتاحي - فقط المالك';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `period_locks`
--

CREATE TABLE `period_locks` (
  `period` char(7) NOT NULL,
  `locked_by` int(11) DEFAULT NULL,
  `locked_at` datetime DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_accounts`
--

CREATE TABLE `personal_accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `balance` decimal(14,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `personal_transactions`
--

CREATE TABLE `personal_transactions` (
  `id` int(11) NOT NULL,
  `personal_account_id` int(11) NOT NULL,
  `type` enum('lend','repay') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `paid_from_bank` varchar(50) DEFAULT NULL,
  `no_bank_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


--
-- Triggers `personal_transactions`
--
DELIMITER $$
CREATE TRIGGER `trg_pt_after_delete` AFTER DELETE ON `personal_transactions` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('personal_transactions', OLD.id, 'delete', JSON_OBJECT('personal_account_id',OLD.personal_account_id,'type',OLD.type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'personal_transactions' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pt_after_insert` AFTER INSERT ON `personal_transactions` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by)
    VALUES ('personal_transactions', NEW.id, 'insert', JSON_OBJECT('personal_account_id',NEW.personal_account_id,'type',NEW.type,'amount',NEW.amount,'tx_date',NEW.tx_date), NEW.created_by);

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('حساب شخصي #', NEW.personal_account_id, ' - ', COALESCE(NEW.description,'')), 'personal_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    -- lend: أعطينا سلفة (هو صار مدين لنا) = مدين على حساب "شخصي"، دائن على البنك/الكاش يلي طلعت منه
    -- repay: رجع سدد = دائن على حساب "شخصي" (تقل مديونيته)، مدين على البنك/الكاش يلي دخلت فيه
    IF NEW.type = 'lend' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'personal',NEW.personal_account_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'personal',NEW.personal_account_id,'credit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'debit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pt_after_update` AFTER UPDATE ON `personal_transactions` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('personal_transactions', NEW.id, 'update',
        JSON_OBJECT('type',OLD.type,'amount',OLD.amount,'tx_date',OLD.tx_date),
        JSON_OBJECT('type',NEW.type,'amount',NEW.amount,'tx_date',NEW.tx_date),
        @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'personal_transactions' AND source_id = NEW.id;

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('حساب شخصي #', NEW.personal_account_id, ' - ', COALESCE(NEW.description,'')), 'personal_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    IF NEW.type = 'lend' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'personal',NEW.personal_account_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'personal',NEW.personal_account_id,'credit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'debit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pt_before_delete` BEFORE DELETE ON `personal_transactions` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pt_before_insert` BEFORE INSERT ON `personal_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة محاسبياً';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pt_before_update` BEFORE UPDATE ON `personal_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `pos_sync_checkpoints`
--

CREATE TABLE `pos_sync_checkpoints` (
  `name` varchar(50) NOT NULL,
  `value` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `pos_sync_items`
--

CREATE TABLE `pos_sync_items` (
  `id` int(11) NOT NULL,
  `run_id` int(11) NOT NULL,
  `entity_type` enum('customer','supplier','customer_transaction','supplier_transaction','customer_balance') NOT NULL,
  `action` enum('insert','update','set','delete') NOT NULL,
  `entity_id` int(11) NOT NULL,
  `label` varchar(255) NOT NULL COMMENT 'وصف مختصر يظهر بالسجل: اسم العميل / رقم الفاتورة...',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `pos_sync_runs`
--

CREATE TABLE `pos_sync_runs` (
  `id` int(11) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `status` enum('running','success','partial','failed') NOT NULL DEFAULT 'running',
  `sync_type` enum('manual','scheduled','from_date','from_date_cli','from_date_customers','from_date_suppliers','manual_customers','manual_suppliers','manual_customer_ledger','daily_cron_customers','daily_cron_suppliers','historical_balances_only') NOT NULL DEFAULT 'manual',
  `customers_new` int(11) NOT NULL DEFAULT 0,
  `customers_updated` int(11) NOT NULL DEFAULT 0,
  `balances_set` int(11) NOT NULL DEFAULT 0,
  `suppliers_new` int(11) NOT NULL DEFAULT 0,
  `suppliers_updated` int(11) NOT NULL DEFAULT 0,
  `sales_tx_new` int(11) NOT NULL DEFAULT 0,
  `purchases_tx_new` int(11) NOT NULL DEFAULT 0,
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `profit_distributions`
--

CREATE TABLE `profit_distributions` (
  `id` int(11) NOT NULL,
  `shareholder_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `period_label` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `paid_from_bank` varchar(50) DEFAULT NULL,
  `no_bank_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `profit_distributions`
--
DELIMITER $$
CREATE TRIGGER `trg_pd_after_delete` AFTER DELETE ON `profit_distributions` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('profit_distributions', OLD.id, 'delete', JSON_OBJECT('shareholder_id',OLD.shareholder_id,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'profit_distributions' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pd_after_insert` AFTER INSERT ON `profit_distributions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by)
    VALUES ('profit_distributions', NEW.id, 'insert', JSON_OBJECT('shareholder_id',NEW.shareholder_id,'amount',NEW.amount), NEW.created_by);
    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('توزيع أرباح - ',NEW.period_label), 'profit_distributions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_id = LAST_INSERT_ID();
    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'shareholder',NEW.shareholder_id,'debit',NEW.amount);
    INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank),'credit',NEW.amount);
    -- تم حذف سطر UPDATE shareholders SET capital_amount = capital_amount - NEW.amount
    -- لأن توزيع الأرباح لا يخفّض رأس المال المساهم فيه.
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pd_after_update` AFTER UPDATE ON `profit_distributions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('profit_distributions', NEW.id, 'update', JSON_OBJECT('amount',OLD.amount), JSON_OBJECT('amount',NEW.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'profit_distributions' AND source_id = NEW.id;
    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('توزيع أرباح - ', NEW.period_label), 'profit_distributions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_id = LAST_INSERT_ID();
    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id,'shareholder',NEW.shareholder_id,'debit',NEW.amount);
    INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank),'credit',NEW.amount);
    -- تم حذف سطري UPDATE shareholders الخاصين برأس المال
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pd_before_delete` BEFORE DELETE ON `profit_distributions` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pd_before_insert` BEFORE INSERT ON `profit_distributions` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة'; END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_pd_before_update` BEFORE UPDATE ON `profit_distributions` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `schema_migrations`
--

CREATE TABLE `schema_migrations` (
  `id` int(11) NOT NULL,
  `filename` varchar(150) NOT NULL,
  `applied_at` datetime DEFAULT current_timestamp(),
  `applied_by` varchar(100) DEFAULT NULL,
  `checksum` varchar(64) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `shareholders`
--

CREATE TABLE `shareholders` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `capital_amount` decimal(14,2) DEFAULT 0.00,
  `share_percentage` decimal(5,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shareholder_contributions`
--

CREATE TABLE `shareholder_contributions` (
  `id` int(11) NOT NULL,
  `shareholder_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `paid_from_bank` varchar(50) DEFAULT NULL,
  `no_bank_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `shareholder_contributions`
--
DELIMITER $$
CREATE TRIGGER `trg_sc_after_delete` AFTER DELETE ON `shareholder_contributions` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('shareholder_contributions', OLD.id, 'delete', JSON_OBJECT('shareholder_id',OLD.shareholder_id,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'shareholder_contributions' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sc_after_insert` AFTER INSERT ON `shareholder_contributions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by)
    VALUES ('shareholder_contributions', NEW.id, 'insert', JSON_OBJECT('shareholder_id',NEW.shareholder_id,'amount',NEW.amount), NEW.created_by);

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('مساهمة/إضافة فلوس - ', COALESCE(NEW.description,'')), 'shareholder_contributions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_id = LAST_INSERT_ID();

    INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank), 'debit', NEW.amount);
    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id, 'shareholder', NEW.shareholder_id, 'credit', NEW.amount);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sc_after_insert_capital` AFTER INSERT ON `shareholder_contributions` FOR EACH ROW BEGIN
    UPDATE shareholders SET capital_amount = capital_amount + NEW.amount WHERE id = NEW.shareholder_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sc_after_update` AFTER UPDATE ON `shareholder_contributions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('shareholder_contributions', NEW.id, 'update', JSON_OBJECT('amount',OLD.amount), JSON_OBJECT('amount',NEW.amount), @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'shareholder_contributions' AND source_id = NEW.id;

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('مساهمة/إضافة فلوس - ', COALESCE(NEW.description,'')), 'shareholder_contributions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_id = LAST_INSERT_ID();

    INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank), 'debit', NEW.amount);
    INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_id, 'shareholder', NEW.shareholder_id, 'credit', NEW.amount);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sc_after_update_capital` AFTER UPDATE ON `shareholder_contributions` FOR EACH ROW BEGIN
    UPDATE shareholders SET capital_amount = capital_amount - OLD.amount + NEW.amount WHERE id = NEW.shareholder_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sc_before_delete` BEFORE DELETE ON `shareholder_contributions` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sc_before_delete_capital` BEFORE DELETE ON `shareholder_contributions` FOR EACH ROW BEGIN
    UPDATE shareholders SET capital_amount = capital_amount - OLD.amount WHERE id = OLD.shareholder_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sc_before_insert` BEFORE INSERT ON `shareholder_contributions` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة'; END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sc_before_update` BEFORE UPDATE ON `shareholder_contributions` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `pos_id` varchar(50) DEFAULT NULL,
  `source` enum('local','pos') NOT NULL DEFAULT 'local',
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `balance` decimal(14,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Table structure for table `supplier_transactions`
--

CREATE TABLE `supplier_transactions` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `type` enum('debit','credit') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `source` enum('local','pos') NOT NULL DEFAULT 'local',
  `pos_ref_type` varchar(20) DEFAULT NULL COMMENT 'purchase مثلاً',
  `pos_ref_id` varchar(50) DEFAULT NULL COMMENT 'رقم فاتورة الشراء بنظام الـ POS',
  `created_at` datetime DEFAULT current_timestamp(),
  `paid_from_bank` varchar(50) DEFAULT NULL,
  `no_bank_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


--
-- Triggers `supplier_transactions`
--
DELIMITER $$
CREATE TRIGGER `trg_st_after_delete` AFTER DELETE ON `supplier_transactions` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by)
    VALUES ('supplier_transactions', OLD.id, 'delete', JSON_OBJECT('supplier_id',OLD.supplier_id,'type',OLD.type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'supplier_transactions' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_st_after_insert` AFTER INSERT ON `supplier_transactions` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by)
    VALUES ('supplier_transactions', NEW.id, 'insert', JSON_OBJECT('supplier_id',NEW.supplier_id,'type',NEW.type,'amount',NEW.amount,'tx_date',NEW.tx_date), NEW.created_by);

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('حركة مورد #', NEW.supplier_id), 'supplier_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    IF NEW.type = 'debit' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'supplier',NEW.supplier_id,'credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'supplier',NEW.supplier_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'credit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_st_after_update` AFTER UPDATE ON `supplier_transactions` FOR EACH ROW BEGIN
    DECLARE v_entry_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('supplier_transactions', NEW.id, 'update',
        JSON_OBJECT('type',OLD.type,'amount',OLD.amount,'tx_date',OLD.tx_date),
        JSON_OBJECT('type',NEW.type,'amount',NEW.amount,'tx_date',NEW.tx_date),
        @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'supplier_transactions' AND source_id = NEW.id;

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank)
    VALUES (NEW.tx_date, CONCAT('حركة مورد #', NEW.supplier_id), 'supplier_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL);
    SET v_entry_id = LAST_INSERT_ID();

    IF NEW.type = 'debit' THEN
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'supplier',NEW.supplier_id,'credit',NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id,'supplier',NEW.supplier_id,'debit',NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, account_ref_id, side, amount) VALUES (v_entry_id, resolve_account_name(NEW.paid_from_bank), NULL,'credit',NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_st_balance_after_delete` AFTER DELETE ON `supplier_transactions` FOR EACH ROW BEGIN
    UPDATE suppliers
    SET balance = (
        SELECT COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0)
        FROM supplier_transactions WHERE supplier_id = OLD.supplier_id
    ),
    updated_at = NOW()
    WHERE id = OLD.supplier_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_st_balance_after_insert` AFTER INSERT ON `supplier_transactions` FOR EACH ROW BEGIN
    UPDATE suppliers
    SET balance = (
        SELECT COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0)
        FROM supplier_transactions WHERE supplier_id = NEW.supplier_id
    ),
    updated_at = NOW()
    WHERE id = NEW.supplier_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_st_balance_after_update` AFTER UPDATE ON `supplier_transactions` FOR EACH ROW BEGIN
    UPDATE suppliers
    SET balance = (
        SELECT COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0)
        FROM supplier_transactions WHERE supplier_id = NEW.supplier_id
    ),
    updated_at = NOW()
    WHERE id = NEW.supplier_id;

    IF OLD.supplier_id <> NEW.supplier_id THEN
        UPDATE suppliers
        SET balance = (
            SELECT COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END), 0)
            FROM supplier_transactions WHERE supplier_id = OLD.supplier_id
        ),
        updated_at = NOW()
        WHERE id = OLD.supplier_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_st_before_delete` BEFORE DELETE ON `supplier_transactions` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف حركات مالية - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_st_before_insert` BEFORE INSERT ON `supplier_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة محاسبياً';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_st_before_update` BEFORE UPDATE ON `supplier_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `tax_settings`
--

CREATE TABLE `tax_settings` (
  `id` int(11) NOT NULL,
  `tax_name` varchar(80) NOT NULL,
  `tax_rate` decimal(5,2) NOT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tax_settings`
--

INSERT INTO `tax_settings` (`id`, `tax_name`, `tax_rate`, `is_default`, `is_active`, `created_at`) VALUES
(1, '16%', 16.00, 1, 1, '2026-07-18 08:51:44'),
(2, 'معفى (0%)', 0.00, 0, 1, '2026-07-18 08:51:44');

-- --------------------------------------------------------

--
-- Table structure for table `transfers`
--

CREATE TABLE `transfers` (
  `id` int(11) NOT NULL,
  `from_account` varchar(30) NOT NULL,
  `to_account` varchar(30) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('owner','employee') DEFAULT 'employee',
  `created_at` datetime DEFAULT current_timestamp(),
  `can_delete` tinyint(1) DEFAULT 0 COMMENT '1 = يقدر يحذف/يعدل قيود مرحّلة (المالك فقط افتراضياً)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--


-- --------------------------------------------------------

--
-- Stand-in structure for view `v_all_bank_transactions`
-- (See below for the actual view)
--
CREATE TABLE `v_all_bank_transactions` (
`bank_key` varchar(10)
,`id` int(11)
,`tx_type` varchar(8)
,`amount` decimal(14,2)
,`usage_type` varchar(11)
,`related_customer` varchar(150)
,`description` varchar(255)
,`tx_date` date
,`created_by` int(11)
,`created_at` datetime /* mariadb-5.3 */
,`linked_source_table` varchar(50)
,`linked_source_id` int(11)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_balance_sheet_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_balance_sheet_summary` (
`category` varchar(25)
,`net_amount` decimal(36,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_bank_reconciliation_totals`
-- (See below for the actual view)
--
CREATE TABLE `v_bank_reconciliation_totals` (
`bank_name` varchar(50)
,`imported_batch` varchar(100)
,`period_from` date
,`period_to` date
,`statement_net_movement` decimal(36,2)
,`matched_amount` decimal(36,2)
,`unmatched_amount` decimal(36,2)
,`total_lines` bigint(21)
,`unmatched_lines` decimal(23,0)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_bank_statement_unmatched`
-- (See below for the actual view)
--
CREATE TABLE `v_bank_statement_unmatched` (
`id` int(11)
,`bank_name` varchar(50)
,`stmt_date` date
,`description` varchar(255)
,`amount` decimal(14,2)
,`reference_no` varchar(100)
,`imported_batch` varchar(100)
,`matched_table` varchar(50)
,`matched_id` int(11)
,`matched_at` datetime
,`matched_by` int(11)
,`status` enum('unmatched','auto_matched','manual_matched','ignored')
,`created_at` datetime
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_dashboard_accounts`
-- (See below for the actual view)
--
CREATE TABLE `v_dashboard_accounts` (
`account_code` varchar(30)
,`account_name_ar` varchar(150)
,`category` enum('asset','liability','equity','revenue','expense')
,`balance` decimal(36,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_dashboard_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_dashboard_summary` (
`category` enum('asset','liability','equity','revenue','expense')
,`total` decimal(58,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_dashboard_unmapped`
-- (See below for the actual view)
--
CREATE TABLE `v_dashboard_unmapped` (
`account_type` varchar(30)
,`lines_count` bigint(21)
,`total_amount` decimal(36,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_journal_with_accounts`
-- (See below for the actual view)
--
CREATE TABLE `v_journal_with_accounts` (
`line_id` int(11)
,`entry_id` int(11)
,`entry_date` date
,`description` varchar(255)
,`account_type` varchar(30)
,`account_code` varchar(30)
,`account_name_ar` varchar(150)
,`category` enum('asset','liability','equity','revenue','expense')
,`normal_balance` enum('debit','credit')
,`side` enum('debit','credit')
,`amount` decimal(14,2)
,`signed_amount` decimal(14,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_reconciliation_customers`
-- (See below for the actual view)
--
CREATE TABLE `v_reconciliation_customers` (
`id` int(11)
,`name` varchar(150)
,`stored_balance` decimal(14,2)
,`computed_balance` decimal(36,2)
,`difference` decimal(37,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_reconciliation_personal`
-- (See below for the actual view)
--
CREATE TABLE `v_reconciliation_personal` (
`id` int(11)
,`name` varchar(150)
,`stored_balance` decimal(14,2)
,`computed_balance` decimal(36,2)
,`difference` decimal(37,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_reconciliation_suppliers`
-- (See below for the actual view)
--
CREATE TABLE `v_reconciliation_suppliers` (
`id` int(11)
,`name` varchar(150)
,`stored_balance` decimal(14,2)
,`computed_balance` decimal(36,2)
,`difference` decimal(37,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_suspense_monitor`
-- (See below for the actual view)
--
CREATE TABLE `v_suspense_monitor` (
`source_table` varchar(50)
,`source_id` int(11)
,`entry_date` date
,`description` varchar(255)
,`side` enum('debit','credit')
,`amount` decimal(14,2)
,`reason` varchar(255)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_suspense_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_suspense_summary` (
`total_lines` bigint(21)
,`net_suspense_balance` decimal(36,2)
,`undocumented_count` decimal(23,0)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_tax_report`
-- (See below for the actual view)
--
CREATE TABLE `v_tax_report` (
`period` varchar(7)
,`side` varchar(15)
,`tax_total` decimal(36,2)
,`base_total` decimal(36,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_trial_balance`
-- (See below for the actual view)
--
CREATE TABLE `v_trial_balance` (
`account_type` varchar(30)
,`account_ref_id` int(11)
,`total_debit` decimal(36,2)
,`total_credit` decimal(36,2)
,`net_balance` decimal(36,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_trial_balance_by_account`
-- (See below for the actual view)
--
CREATE TABLE `v_trial_balance_by_account` (
`account_code` varchar(30)
,`account_name_ar` varchar(150)
,`account_type` enum('asset','liability','equity','revenue','expense')
,`normal_balance` enum('debit','credit')
,`total_debit` decimal(36,2)
,`total_credit` decimal(36,2)
,`net_balance` decimal(36,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_processed_messages`
--

CREATE TABLE `whatsapp_processed_messages` (
  `wa_message_id` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لمنع تكرار تنفيذ نفس رسالة واتساب لو ميتا أعادت إرسال نفس webhook';

--


-- --------------------------------------------------------

--
-- Table structure for table `workshop_transactions`
--

CREATE TABLE `workshop_transactions` (
  `id` int(11) NOT NULL,
  `type` enum('revenue','expense') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `paid_from_bank` varchar(50) DEFAULT NULL COMMENT 'jordan/etihad/islami/dinarak/investment/cash',
  `no_bank_reason` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `workshop_transactions`
--
DELIMITER $$
CREATE TRIGGER `trg_wkshp_after_delete` AFTER DELETE ON `workshop_transactions` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by) VALUES ('workshop_transactions', OLD.id, 'delete', JSON_OBJECT('type',OLD.type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'workshop_transactions' AND source_id = OLD.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_wkshp_after_insert` AFTER INSERT ON `workshop_transactions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, new_data, changed_by) VALUES ('workshop_transactions', NEW.id, 'insert', JSON_OBJECT('type',NEW.type,'amount',NEW.amount), NEW.created_by);
    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('مشغل - ', NEW.type, ' - ', COALESCE(NEW.description,'')), 'workshop_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL OR NEW.type = 'revenue');
    SET v_id = LAST_INSERT_ID();
    IF NEW.type = 'revenue' THEN
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, IF(NEW.paid_from_bank IS NULL OR NEW.paid_from_bank = '', 'cash', resolve_account_name(NEW.paid_from_bank)), 'debit', NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'workshop_revenue', 'credit', NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'workshop_expense', 'debit', NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank), 'credit', NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_wkshp_after_update` AFTER UPDATE ON `workshop_transactions` FOR EACH ROW BEGIN
    DECLARE v_id INT;
    INSERT INTO audit_log(table_name, record_id, action, old_data, new_data, changed_by)
    VALUES ('workshop_transactions', NEW.id, 'update', JSON_OBJECT('type',OLD.type,'amount',OLD.amount), JSON_OBJECT('type',NEW.type,'amount',NEW.amount), @current_user_id);

    DELETE FROM journal_entries WHERE source_table = 'workshop_transactions' AND source_id = NEW.id;

    INSERT INTO journal_entries(entry_date, description, source_table, source_id, settled_via_bank) VALUES (NEW.tx_date, CONCAT('مشغل - ', NEW.type, ' - ', COALESCE(NEW.description,'')), 'workshop_transactions', NEW.id, NEW.paid_from_bank IS NOT NULL OR NEW.type = 'revenue');
    SET v_id = LAST_INSERT_ID();
    IF NEW.type = 'revenue' THEN
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, IF(NEW.paid_from_bank IS NULL OR NEW.paid_from_bank = '', 'cash', resolve_account_name(NEW.paid_from_bank)), 'debit', NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'workshop_revenue', 'credit', NEW.amount);
    ELSE
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, 'workshop_expense', 'debit', NEW.amount);
        INSERT INTO journal_lines(entry_id, account_type, side, amount) VALUES (v_id, resolve_account_name(NEW.paid_from_bank), 'credit', NEW.amount);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_wkshp_before_delete` BEFORE DELETE ON `workshop_transactions` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف - فقط المالك';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_wkshp_before_insert` BEFORE INSERT ON `workshop_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_wkshp_before_update` BEFORE UPDATE ON `workshop_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_type_map`
--
ALTER TABLE `account_type_map`
  ADD PRIMARY KEY (`account_type_key`),
  ADD KEY `account_code` (`account_code`);

--
-- Indexes for table `attachments`
--
ALTER TABLE `attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_entity` (`entity_table`,`entity_id`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_record` (`table_name`,`record_id`);

--
-- Indexes for table `bank_dinarak`
--
ALTER TABLE `bank_dinarak`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bank_etihad`
--
ALTER TABLE `bank_etihad`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bank_investment`
--
ALTER TABLE `bank_investment`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bank_islami`
--
ALTER TABLE `bank_islami`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bank_jordan`
--
ALTER TABLE `bank_jordan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bank_statement_lines`
--
ALTER TABLE `bank_statement_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bank_date_amount` (`bank_name`,`stmt_date`,`amount`);

--
-- Indexes for table `car_employees`
--
ALTER TABLE `car_employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_car_employees_name` (`name`);

--
-- Indexes for table `car_employee_transactions`
--
ALTER TABLE `car_employee_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `car_employee_id` (`car_employee_id`);

--
-- Indexes for table `cash_box`
--
ALTER TABLE `cash_box`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cash_transactions`
--
ALTER TABLE `cash_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cash_tx_linked` (`linked_source_table`,`linked_source_id`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_code` (`account_code`);

--
-- Indexes for table `chat_log`
--
ALTER TABLE `chat_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chat_pending_actions`
--
ALTER TABLE `chat_pending_actions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `checks`
--
ALTER TABLE `checks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_customers_name` (`name`),
  ADD UNIQUE KEY `uq_customers_pos_id` (`pos_id`),
  ADD KEY `idx_pos_id` (`pos_id`),
  ADD KEY `idx_phone` (`phone`);

--
-- Indexes for table `customer_transactions`
--
ALTER TABLE `customer_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ct_pos_ref` (`pos_ref_type`,`pos_ref_id`,`customer_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_tx_date` (`tx_date`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_employees_name` (`name`);

--
-- Indexes for table `employee_transactions`
--
ALTER TABLE `employee_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `external_revenues`
--
ALTER TABLE `external_revenues`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_source` (`source_table`,`source_id`);

--
-- Indexes for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_entry_id` (`entry_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_time` (`ip`,`success`,`attempted_at`),
  ADD KEY `idx_user_time` (`username`,`success`,`attempted_at`);

--
-- Indexes for table `opening_balances`
--
ALTER TABLE `opening_balances`
  ADD PRIMARY KEY (`account_key`);

--
-- Indexes for table `period_locks`
--
ALTER TABLE `period_locks`
  ADD PRIMARY KEY (`period`);

--
-- Indexes for table `personal_accounts`
--
ALTER TABLE `personal_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_personal_accounts_name` (`name`);

--
-- Indexes for table `personal_transactions`
--
ALTER TABLE `personal_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `personal_account_id` (`personal_account_id`);

--
-- Indexes for table `pos_sync_checkpoints`
--
ALTER TABLE `pos_sync_checkpoints`
  ADD PRIMARY KEY (`name`);

--
-- Indexes for table `pos_sync_items`
--
ALTER TABLE `pos_sync_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_run_id` (`run_id`);

--
-- Indexes for table `pos_sync_runs`
--
ALTER TABLE `pos_sync_runs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `profit_distributions`
--
ALTER TABLE `profit_distributions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shareholder_id` (`shareholder_id`);

--
-- Indexes for table `schema_migrations`
--
ALTER TABLE `schema_migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `filename` (`filename`);

--
-- Indexes for table `shareholders`
--
ALTER TABLE `shareholders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_shareholders_name` (`name`);

--
-- Indexes for table `shareholder_contributions`
--
ALTER TABLE `shareholder_contributions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `shareholder_id` (`shareholder_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_suppliers_name` (`name`),
  ADD UNIQUE KEY `uq_suppliers_pos_id` (`pos_id`);

--
-- Indexes for table `supplier_transactions`
--
ALTER TABLE `supplier_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_st_pos_ref` (`pos_ref_type`,`pos_ref_id`,`supplier_id`),
  ADD KEY `fk_st_supplier` (`supplier_id`);

--
-- Indexes for table `tax_settings`
--
ALTER TABLE `tax_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transfers`
--
ALTER TABLE `transfers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `whatsapp_processed_messages`
--
ALTER TABLE `whatsapp_processed_messages`
  ADD PRIMARY KEY (`wa_message_id`);

--
-- Indexes for table `workshop_transactions`
--
ALTER TABLE `workshop_transactions`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attachments`
--
ALTER TABLE `attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `bank_dinarak`
--
ALTER TABLE `bank_dinarak`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_etihad`
--
ALTER TABLE `bank_etihad`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `bank_investment`
--
ALTER TABLE `bank_investment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_islami`
--
ALTER TABLE `bank_islami`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `bank_jordan`
--
ALTER TABLE `bank_jordan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `bank_statement_lines`
--
ALTER TABLE `bank_statement_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `car_employees`
--
ALTER TABLE `car_employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `car_employee_transactions`
--
ALTER TABLE `car_employee_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_box`
--
ALTER TABLE `cash_box`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `cash_transactions`
--
ALTER TABLE `cash_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `chat_log`
--
ALTER TABLE `chat_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `chat_pending_actions`
--
ALTER TABLE `chat_pending_actions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `checks`
--
ALTER TABLE `checks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `customer_transactions`
--
ALTER TABLE `customer_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `employee_transactions`
--
ALTER TABLE `employee_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `external_revenues`
--
ALTER TABLE `external_revenues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `journal_lines`
--
ALTER TABLE `journal_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `personal_accounts`
--
ALTER TABLE `personal_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `personal_transactions`
--
ALTER TABLE `personal_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `pos_sync_items`
--
ALTER TABLE `pos_sync_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `pos_sync_runs`
--
ALTER TABLE `pos_sync_runs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `profit_distributions`
--
ALTER TABLE `profit_distributions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schema_migrations`
--
ALTER TABLE `schema_migrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `shareholders`
--
ALTER TABLE `shareholders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `shareholder_contributions`
--
ALTER TABLE `shareholder_contributions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `supplier_transactions`
--
ALTER TABLE `supplier_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `tax_settings`
--
ALTER TABLE `tax_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `transfers`
--
ALTER TABLE `transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

--
-- AUTO_INCREMENT for table `workshop_transactions`
--
ALTER TABLE `workshop_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,;

-- --------------------------------------------------------

--
-- Structure for view `v_all_bank_transactions`
--
DROP TABLE IF EXISTS `v_all_bank_transactions`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_all_bank_transactions`  AS SELECT 'jordan' AS `bank_key`, `bank_jordan`.`id` AS `id`, `bank_jordan`.`tx_type` AS `tx_type`, `bank_jordan`.`amount` AS `amount`, `bank_jordan`.`usage_type` AS `usage_type`, NULL AS `related_customer`, `bank_jordan`.`description` AS `description`, `bank_jordan`.`tx_date` AS `tx_date`, `bank_jordan`.`created_by` AS `created_by`, `bank_jordan`.`created_at` AS `created_at`, `bank_jordan`.`linked_source_table` AS `linked_source_table`, `bank_jordan`.`linked_source_id` AS `linked_source_id` FROM `bank_jordan`union all select 'etihad' AS `etihad`,`bank_etihad`.`id` AS `id`,`bank_etihad`.`tx_type` AS `tx_type`,`bank_etihad`.`amount` AS `amount`,`bank_etihad`.`usage_type` AS `usage_type`,NULL AS `NULL`,`bank_etihad`.`description` AS `description`,`bank_etihad`.`tx_date` AS `tx_date`,`bank_etihad`.`created_by` AS `created_by`,`bank_etihad`.`created_at` AS `created_at`,`bank_etihad`.`linked_source_table` AS `linked_source_table`,`bank_etihad`.`linked_source_id` AS `linked_source_id` from `bank_etihad` union all select 'islami' AS `islami`,`bank_islami`.`id` AS `id`,`bank_islami`.`tx_type` AS `tx_type`,`bank_islami`.`amount` AS `amount`,`bank_islami`.`usage_type` AS `usage_type`,NULL AS `NULL`,`bank_islami`.`description` AS `description`,`bank_islami`.`tx_date` AS `tx_date`,`bank_islami`.`created_by` AS `created_by`,`bank_islami`.`created_at` AS `created_at`,`bank_islami`.`linked_source_table` AS `linked_source_table`,`bank_islami`.`linked_source_id` AS `linked_source_id` from `bank_islami` union all select 'investment' AS `investment`,`bank_investment`.`id` AS `id`,`bank_investment`.`tx_type` AS `tx_type`,`bank_investment`.`amount` AS `amount`,`bank_investment`.`usage_type` AS `usage_type`,NULL AS `NULL`,`bank_investment`.`description` AS `description`,`bank_investment`.`tx_date` AS `tx_date`,`bank_investment`.`created_by` AS `created_by`,`bank_investment`.`created_at` AS `created_at`,`bank_investment`.`linked_source_table` AS `linked_source_table`,`bank_investment`.`linked_source_id` AS `linked_source_id` from `bank_investment` union all select 'dinarak' AS `dinarak`,`bank_dinarak`.`id` AS `id`,`bank_dinarak`.`tx_type` AS `tx_type`,`bank_dinarak`.`amount` AS `amount`,NULL AS `usage_type`,`bank_dinarak`.`related_customer` AS `related_customer`,`bank_dinarak`.`description` AS `description`,`bank_dinarak`.`tx_date` AS `tx_date`,`bank_dinarak`.`created_by` AS `created_by`,`bank_dinarak`.`created_at` AS `created_at`,`bank_dinarak`.`linked_source_table` AS `linked_source_table`,`bank_dinarak`.`linked_source_id` AS `linked_source_id` from `bank_dinarak`  ;

-- --------------------------------------------------------

--
-- Structure for view `v_balance_sheet_summary`
--
DROP TABLE IF EXISTS `v_balance_sheet_summary`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_balance_sheet_summary`  AS SELECT CASE `coa`.`account_type` WHEN 'asset' THEN 'أصول (Assets)' WHEN 'liability' THEN 'التزامات (Liabilities)' WHEN 'equity' THEN 'حقوق ملكية (Equity)' WHEN 'revenue' THEN 'إيرادات (Income)' WHEN 'expense' THEN 'مصاريف (Expenses)' ELSE 'أخرى/غير مصنّف (Suspense)' END AS `category`, sum(case when `jl`.`side` = `coa`.`normal_balance` then `jl`.`amount` else -`jl`.`amount` end) AS `net_amount` FROM ((`journal_lines` `jl` left join `account_type_map` `m` on(`m`.`account_type_key` = `jl`.`account_type`)) left join `chart_of_accounts` `coa` on(`coa`.`account_code` = `m`.`account_code`)) GROUP BY CASE `coa`.`account_type` WHEN 'asset' THEN 'أصول (Assets)' WHEN 'liability' THEN 'التزامات (Liabilities)' WHEN 'equity' THEN 'حقوق ملكية (Equity)' WHEN 'revenue' THEN 'إيرادات (Income)' WHEN 'expense' THEN 'مصاريف (Expenses)' ELSE 'أخرى/غير مصنّف (Suspense)' END ;

-- --------------------------------------------------------

--
-- Structure for view `v_bank_reconciliation_totals`
--
DROP TABLE IF EXISTS `v_bank_reconciliation_totals`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_bank_reconciliation_totals`  AS SELECT `bank_statement_lines`.`bank_name` AS `bank_name`, `bank_statement_lines`.`imported_batch` AS `imported_batch`, min(`bank_statement_lines`.`stmt_date`) AS `period_from`, max(`bank_statement_lines`.`stmt_date`) AS `period_to`, sum(`bank_statement_lines`.`amount`) AS `statement_net_movement`, sum(case when `bank_statement_lines`.`status` in ('auto_matched','manual_matched') then `bank_statement_lines`.`amount` else 0 end) AS `matched_amount`, sum(case when `bank_statement_lines`.`status` = 'unmatched' then `bank_statement_lines`.`amount` else 0 end) AS `unmatched_amount`, count(0) AS `total_lines`, sum(case when `bank_statement_lines`.`status` = 'unmatched' then 1 else 0 end) AS `unmatched_lines` FROM `bank_statement_lines` GROUP BY `bank_statement_lines`.`bank_name`, `bank_statement_lines`.`imported_batch` ;

-- --------------------------------------------------------

--
-- Structure for view `v_bank_statement_unmatched`
--
DROP TABLE IF EXISTS `v_bank_statement_unmatched`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_bank_statement_unmatched`  AS SELECT `bank_statement_lines`.`id` AS `id`, `bank_statement_lines`.`bank_name` AS `bank_name`, `bank_statement_lines`.`stmt_date` AS `stmt_date`, `bank_statement_lines`.`description` AS `description`, `bank_statement_lines`.`amount` AS `amount`, `bank_statement_lines`.`reference_no` AS `reference_no`, `bank_statement_lines`.`imported_batch` AS `imported_batch`, `bank_statement_lines`.`matched_table` AS `matched_table`, `bank_statement_lines`.`matched_id` AS `matched_id`, `bank_statement_lines`.`matched_at` AS `matched_at`, `bank_statement_lines`.`matched_by` AS `matched_by`, `bank_statement_lines`.`status` AS `status`, `bank_statement_lines`.`created_at` AS `created_at` FROM `bank_statement_lines` WHERE `bank_statement_lines`.`status` = 'unmatched' ORDER BY `bank_statement_lines`.`stmt_date` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_dashboard_accounts`
--
DROP TABLE IF EXISTS `v_dashboard_accounts`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_dashboard_accounts`  AS SELECT `coa`.`account_code` AS `account_code`, `coa`.`account_name_ar` AS `account_name_ar`, `coa`.`account_type` AS `category`, coalesce(sum(`v`.`signed_amount`),0) AS `balance` FROM (`chart_of_accounts` `coa` left join `v_journal_with_accounts` `v` on(`v`.`account_code` = `coa`.`account_code`)) WHERE `coa`.`is_control_account` = 0 GROUP BY `coa`.`account_code`, `coa`.`account_name_ar`, `coa`.`account_type` ORDER BY `coa`.`account_code` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `v_dashboard_summary`
--
DROP TABLE IF EXISTS `v_dashboard_summary`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_dashboard_summary`  AS SELECT `v_dashboard_accounts`.`category` AS `category`, sum(`v_dashboard_accounts`.`balance`) AS `total` FROM `v_dashboard_accounts` GROUP BY `v_dashboard_accounts`.`category` ;

-- --------------------------------------------------------

--
-- Structure for view `v_dashboard_unmapped`
--
DROP TABLE IF EXISTS `v_dashboard_unmapped`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_dashboard_unmapped`  AS SELECT `jl`.`account_type` AS `account_type`, count(0) AS `lines_count`, sum(`jl`.`amount`) AS `total_amount` FROM (`journal_lines` `jl` left join `account_type_map` `m` on(`m`.`account_type_key` = `jl`.`account_type`)) WHERE `m`.`account_type_key` is null GROUP BY `jl`.`account_type` ;

-- --------------------------------------------------------

--
-- Structure for view `v_journal_with_accounts`
--
DROP TABLE IF EXISTS `v_journal_with_accounts`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_journal_with_accounts`  AS SELECT `jl`.`id` AS `line_id`, `jl`.`entry_id` AS `entry_id`, `je`.`entry_date` AS `entry_date`, `je`.`description` AS `description`, `jl`.`account_type` AS `account_type`, `coa`.`account_code` AS `account_code`, `coa`.`account_name_ar` AS `account_name_ar`, `coa`.`account_type` AS `category`, `coa`.`normal_balance` AS `normal_balance`, `jl`.`side` AS `side`, `jl`.`amount` AS `amount`, CASE WHEN `jl`.`side` = `coa`.`normal_balance` THEN `jl`.`amount` ELSE -`jl`.`amount` END AS `signed_amount` FROM (((`journal_lines` `jl` join `journal_entries` `je` on(`je`.`id` = `jl`.`entry_id`)) left join `account_type_map` `m` on(`m`.`account_type_key` = `jl`.`account_type`)) left join `chart_of_accounts` `coa` on(`coa`.`account_code` = `m`.`account_code`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_reconciliation_customers`
--
DROP TABLE IF EXISTS `v_reconciliation_customers`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_reconciliation_customers`  AS SELECT `c`.`id` AS `id`, `c`.`name` AS `name`, `c`.`balance` AS `stored_balance`, coalesce(sum(case when `t`.`type` = 'debit' then `t`.`amount` else -`t`.`amount` end),0) AS `computed_balance`, `c`.`balance`- coalesce(sum(case when `t`.`type` = 'debit' then `t`.`amount` else -`t`.`amount` end),0) AS `difference` FROM (`customers` `c` left join `customer_transactions` `t` on(`t`.`customer_id` = `c`.`id`)) GROUP BY `c`.`id`, `c`.`name`, `c`.`balance` HAVING `difference` <> 0 ;

-- --------------------------------------------------------

--
-- Structure for view `v_reconciliation_personal`
--
DROP TABLE IF EXISTS `v_reconciliation_personal`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_reconciliation_personal`  AS SELECT `p`.`id` AS `id`, `p`.`name` AS `name`, `p`.`balance` AS `stored_balance`, coalesce(sum(case when `t`.`type` = 'lend' then `t`.`amount` else -`t`.`amount` end),0) AS `computed_balance`, `p`.`balance`- coalesce(sum(case when `t`.`type` = 'lend' then `t`.`amount` else -`t`.`amount` end),0) AS `difference` FROM (`personal_accounts` `p` left join `personal_transactions` `t` on(`t`.`personal_account_id` = `p`.`id`)) GROUP BY `p`.`id`, `p`.`name`, `p`.`balance` HAVING `difference` <> 0 ;

-- --------------------------------------------------------

--
-- Structure for view `v_reconciliation_suppliers`
--
DROP TABLE IF EXISTS `v_reconciliation_suppliers`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_reconciliation_suppliers`  AS SELECT `s`.`id` AS `id`, `s`.`name` AS `name`, `s`.`balance` AS `stored_balance`, coalesce(sum(case when `t`.`type` = 'debit' then `t`.`amount` else -`t`.`amount` end),0) AS `computed_balance`, `s`.`balance`- coalesce(sum(case when `t`.`type` = 'debit' then `t`.`amount` else -`t`.`amount` end),0) AS `difference` FROM (`suppliers` `s` left join `supplier_transactions` `t` on(`t`.`supplier_id` = `s`.`id`)) GROUP BY `s`.`id`, `s`.`name`, `s`.`balance` HAVING `difference` <> 0 ;

-- --------------------------------------------------------

--
-- Structure for view `v_suspense_monitor`
--
DROP TABLE IF EXISTS `v_suspense_monitor`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_suspense_monitor`  AS SELECT `je`.`source_table` AS `source_table`, `je`.`source_id` AS `source_id`, `je`.`entry_date` AS `entry_date`, `je`.`description` AS `description`, `jl`.`side` AS `side`, `jl`.`amount` AS `amount`, CASE `je`.`source_table` WHEN 'customer_transactions' THEN (select `customer_transactions`.`no_bank_reason` from `customer_transactions` where `customer_transactions`.`id` = `je`.`source_id`) WHEN 'supplier_transactions' THEN (select `supplier_transactions`.`no_bank_reason` from `supplier_transactions` where `supplier_transactions`.`id` = `je`.`source_id`) WHEN 'personal_transactions' THEN (select `personal_transactions`.`no_bank_reason` from `personal_transactions` where `personal_transactions`.`id` = `je`.`source_id`) WHEN 'profit_distributions' THEN (select `profit_distributions`.`no_bank_reason` from `profit_distributions` where `profit_distributions`.`id` = `je`.`source_id`) WHEN 'expenses' THEN (select `expenses`.`no_bank_reason` from `expenses` where `expenses`.`id` = `je`.`source_id`) ELSE NULL END AS `reason` FROM (`journal_lines` `jl` join `journal_entries` `je` on(`je`.`id` = `jl`.`entry_id`)) WHERE `jl`.`account_type` = 'suspense' ORDER BY `je`.`entry_date` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_suspense_summary`
--
DROP TABLE IF EXISTS `v_suspense_summary`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_suspense_summary`  AS SELECT count(0) AS `total_lines`, sum(case when `jl`.`side` = 'debit' then `jl`.`amount` else -`jl`.`amount` end) AS `net_suspense_balance`, sum(case when `je`.`source_table` = 'customer_transactions' and (select `customer_transactions`.`no_bank_reason` from `customer_transactions` where `customer_transactions`.`id` = `je`.`source_id`) is null then 1 when `je`.`source_table` = 'supplier_transactions' and (select `supplier_transactions`.`no_bank_reason` from `supplier_transactions` where `supplier_transactions`.`id` = `je`.`source_id`) is null then 1 when `je`.`source_table` = 'personal_transactions' and (select `personal_transactions`.`no_bank_reason` from `personal_transactions` where `personal_transactions`.`id` = `je`.`source_id`) is null then 1 when `je`.`source_table` = 'profit_distributions' and (select `profit_distributions`.`no_bank_reason` from `profit_distributions` where `profit_distributions`.`id` = `je`.`source_id`) is null then 1 when `je`.`source_table` = 'expenses' and (select `expenses`.`no_bank_reason` from `expenses` where `expenses`.`id` = `je`.`source_id`) is null then 1 else 0 end) AS `undocumented_count` FROM (`journal_lines` `jl` join `journal_entries` `je` on(`je`.`id` = `jl`.`entry_id`)) WHERE `jl`.`account_type` = 'suspense' ;

-- --------------------------------------------------------

--
-- Structure for view `v_tax_report`
--
DROP TABLE IF EXISTS `v_tax_report`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_tax_report`  AS SELECT `combined`.`period` AS `period`, `combined`.`side` AS `side`, sum(`combined`.`tax_total`) AS `tax_total`, sum(`combined`.`base_total`) AS `base_total` FROM (select date_format(`customer_transactions`.`tx_date`,'%Y-%m') AS `period`,_utf8mb4'output_sales' collate utf8mb4_general_ci AS `side`,coalesce(`customer_transactions`.`tax_amount`,0) AS `tax_total`,coalesce(`customer_transactions`.`amount_excl_tax`,`customer_transactions`.`amount`) AS `base_total` from `customer_transactions` where `customer_transactions`.`tax_amount` is not null and `customer_transactions`.`tax_amount` <> 0 union all select date_format(`expenses`.`tx_date`,'%Y-%m') AS `period`,_utf8mb4'input_purchases' collate utf8mb4_general_ci AS `side`,coalesce(`expenses`.`tax_amount`,0) AS `tax_total`,coalesce(`expenses`.`amount_excl_tax`,`expenses`.`amount`) AS `base_total` from `expenses` where `expenses`.`tax_amount` is not null and `expenses`.`tax_amount` <> 0) AS `combined` GROUP BY `combined`.`period`, `combined`.`side` ;

-- --------------------------------------------------------

--
-- Structure for view `v_trial_balance`
--
DROP TABLE IF EXISTS `v_trial_balance`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_trial_balance`  AS SELECT `journal_lines`.`account_type` AS `account_type`, `journal_lines`.`account_ref_id` AS `account_ref_id`, sum(case when `journal_lines`.`side` = 'debit' then `journal_lines`.`amount` else 0 end) AS `total_debit`, sum(case when `journal_lines`.`side` = 'credit' then `journal_lines`.`amount` else 0 end) AS `total_credit`, sum(case when `journal_lines`.`side` = 'debit' then `journal_lines`.`amount` else -`journal_lines`.`amount` end) AS `net_balance` FROM `journal_lines` GROUP BY `journal_lines`.`account_type`, `journal_lines`.`account_ref_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_trial_balance_by_account`
--
DROP TABLE IF EXISTS `v_trial_balance_by_account`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_trial_balance_by_account`  AS SELECT `coa`.`account_code` AS `account_code`, `coa`.`account_name_ar` AS `account_name_ar`, `coa`.`account_type` AS `account_type`, `coa`.`normal_balance` AS `normal_balance`, sum(case when `jl`.`side` = 'debit' then `jl`.`amount` else 0 end) AS `total_debit`, sum(case when `jl`.`side` = 'credit' then `jl`.`amount` else 0 end) AS `total_credit`, sum(case when `jl`.`side` = `coa`.`normal_balance` then `jl`.`amount` else -`jl`.`amount` end) AS `net_balance` FROM ((`journal_lines` `jl` left join `account_type_map` `m` on(`m`.`account_type_key` = `jl`.`account_type`)) left join `chart_of_accounts` `coa` on(`coa`.`account_code` = `m`.`account_code`)) GROUP BY `coa`.`account_code`, `coa`.`account_name_ar`, `coa`.`account_type`, `coa`.`normal_balance` ORDER BY `coa`.`account_code` ASC ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `account_type_map`
--
ALTER TABLE `account_type_map`
  ADD CONSTRAINT `account_type_map_ibfk_1` FOREIGN KEY (`account_code`) REFERENCES `chart_of_accounts` (`account_code`);

--
-- Constraints for table `car_employee_transactions`
--
ALTER TABLE `car_employee_transactions`
  ADD CONSTRAINT `car_employee_transactions_ibfk_1` FOREIGN KEY (`car_employee_id`) REFERENCES `car_employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_transactions`
--
ALTER TABLE `customer_transactions`
  ADD CONSTRAINT `customer_transactions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ct_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Constraints for table `employee_transactions`
--
ALTER TABLE `employee_transactions`
  ADD CONSTRAINT `employee_transactions_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD CONSTRAINT `fk_jl_entry` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_journal_lines_entry` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `personal_transactions`
--
ALTER TABLE `personal_transactions`
  ADD CONSTRAINT `personal_transactions_ibfk_1` FOREIGN KEY (`personal_account_id`) REFERENCES `personal_accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pos_sync_items`
--
ALTER TABLE `pos_sync_items`
  ADD CONSTRAINT `fk_sync_items_run` FOREIGN KEY (`run_id`) REFERENCES `pos_sync_runs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `profit_distributions`
--
ALTER TABLE `profit_distributions`
  ADD CONSTRAINT `profit_distributions_ibfk_1` FOREIGN KEY (`shareholder_id`) REFERENCES `shareholders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shareholder_contributions`
--
ALTER TABLE `shareholder_contributions`
  ADD CONSTRAINT `shareholder_contributions_ibfk_1` FOREIGN KEY (`shareholder_id`) REFERENCES `shareholders` (`id`);

--
-- Constraints for table `supplier_transactions`
--
ALTER TABLE `supplier_transactions`
  ADD CONSTRAINT `fk_st_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `supplier_transactions_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
