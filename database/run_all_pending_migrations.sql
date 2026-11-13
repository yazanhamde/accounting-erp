-- ============================================================================
-- migration_car_revenue_to_cash.sql
-- ============================================================================
-- ليش هاد التعديل:
-- المحاسب طلب إنه أي حركة "إيراد" (revenue) لسائق/موظف سيارة تعتبر فلوس فعلية
-- دخلت المحل من لحظة تسجيلها، مش مجرد "أمانة"/مستحق معلّق عند السائق بحساب
-- car_employee زي ما كان الوضع قبل هيك. الفلوس بتروح لأي حساب حدده السائق:
--   - لو ما حدد شي بحقل "من/إلى أي حساب" (paid_from_bank فاضي) => بتعتبر كاش فعلي
--     بصندوق المحل (account_type = cash).
--   - لو حدد بنك معيّن (يعني حط إيداع بنكي) => بتروح لهداك البنك تحديداً
--     (account_type = resolve_account_name(paid_from_bank))، مش للكاش.
--   قبل: دايماً Debit car_employee (2300 - أمانات سائقي السيارات) / Credit car_revenue (4200)
--   بعد: Debit (كاش أو البنك المحدد)                              / Credit car_revenue (4200)
-- هيك رصيد الكاش أو البنك بالداشبورد رح ينعكس فوراً بمجرد تسجيل الإيراد،
-- بدل ما يضل صفر لحد ما ينعمل تسوية يدوية.
--
-- ملاحظة: هذا ما بيأثر على أنواع الحركات التانية (expense/deduction/advance/ads/
-- fuel/parts/vehicle_maintenance/secretary_salary) ولا على عمود car_employees.balance
-- (يلي بيضل يتحدث من الكود العادي بـ actions.php لتتبع سلف/خصومات كل سائق).
--
-- طريقة التنفيذ: نفّذ هذا الملف كامل على القاعدة (من phpMyAdmin مثلاً)، وبعدها
-- سجّله بجدول schema_migrations متل ما موضح آخر الملف.
-- ============================================================================

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_car_after_insert`$$
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
END$$

DROP TRIGGER IF EXISTS `trg_car_after_update`$$
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
END$$

DELIMITER ;

-- ============================================================================
-- تحديث الحركات القديمة يلي كانت مسجّلة قبل هالتعديل (يلي إلها journal_lines
-- بحساب car_employee بدل cash/البنك المحدد لنوع revenue) حتى الرصيد الفعلي
-- ينعكس فوراً بدون ما تحتاج تحذف وتعيد إدخالهم يدوياً. كل حركة قديمة بتاخذ
-- الحساب الصحيح إلها حسب paid_from_bank يلي كانت محدده فعلياً (أو cash لو فاضي).
-- ============================================================================
UPDATE journal_lines jl
JOIN journal_entries je ON je.id = jl.entry_id
JOIN car_employee_transactions ct ON ct.id = je.source_id AND je.source_table = 'car_employee_transactions'
SET jl.account_type = IF(ct.paid_from_bank IS NULL OR ct.paid_from_bank = '', 'cash', resolve_account_name(ct.paid_from_bank)),
    jl.account_ref_id = NULL
WHERE je.source_table = 'car_employee_transactions'
  AND jl.side = 'debit'
  AND jl.account_type = 'car_employee'
  AND ct.type = 'revenue';

-- ============================================================================
-- تسجيل تنفيذ هذا الملف بجدول schema_migrations:
-- ============================================================================
INSERT INTO schema_migrations (filename, notes) VALUES
  ('migration_car_revenue_to_cash.sql', 'إيراد السائق بيعكس فوراً على صندوق الكاش الفعلي بدل ما يضل أمانة معلّقة');


-- ============================================================================
-- migration_workshop_module.sql
-- ============================================================================
-- قسم جديد "مشغل": كيان واحد إجمالي (مش قائمة أسماء زي صيانة خارجية) بحركتين
-- بس: إيراد (revenue) ومصاريف (expense) عام بدون تصنيف فرعي. صافي إيراد المشغل
-- = إجمالي الإيراد - إجمالي المصاريف.
--
-- نفس منطق صيانة خارجية بالضبط بخصوص الكاش/البنك:
--   - إيراد المشغل: لو ما حددت حساب (paid_from_bank فاضي) => كاش فعلي بالصندوق.
--                    لو حددت بنك معيّن => بيروح لهداك البنك تحديداً (ديبوزيت حقيقي
--                    بجدول البنك، مش بس قيد محاسبي - عشان يظهر بويدجت البنك بالداشبورد).
--   - مصاريف المشغل: فلوس فعلية طالعة - لازم تحدد بنك/كاش أو تكتب سبب واضح
--                    (نفس شرط مصاريف صيانة خارجية).
--
-- طريقة التنفيذ: نفّذ هذا الملف كامل على القاعدة، وبعدها فعّل سطر الـ INSERT
-- بجدول schema_migrations آخر الملف.
-- ============================================================================

CREATE TABLE `workshop_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('revenue','expense') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `tx_date` date NOT NULL,
  `paid_from_bank` varchar(50) DEFAULT NULL COMMENT 'jordan/etihad/islami/dinarak/investment/cash',
  `no_bank_reason` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- دليل الحسابات: إيراد ومصاريف المشغل
INSERT INTO `chart_of_accounts` (`account_code`, `account_name_ar`, `account_type`, `is_control_account`, `normal_balance`, `is_active`, `notes`) VALUES
('4300', 'إيراد المشغل', 'revenue', 0, 'credit', 1, 'أضيف تلقائياً - migration_workshop_module'),
('5600', 'مصاريف المشغل', 'expense', 0, 'debit', 1, 'أضيف تلقائياً - migration_workshop_module');

-- ربط account_type بكود الحساب (نفس آلية account_type_map المستخدمة بباقي الوحدات)
INSERT INTO `account_type_map` (`account_type_key`, `account_code`) VALUES
('workshop_revenue', '4300'),
('workshop_expense', '5600');

DELIMITER $$

CREATE TRIGGER `trg_wkshp_before_insert` BEFORE INSERT ON `workshop_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(NEW.tx_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'الفترة مقفلة';
    END IF;
END$$

CREATE TRIGGER `trg_wkshp_before_update` BEFORE UPDATE ON `workshop_transactions` FOR EACH ROW BEGIN
    IF is_period_locked(OLD.tx_date) AND (SELECT can_delete FROM users WHERE id = @current_user_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'هذه الفترة مقفلة، فقط المالك يقدر يعدّل حركة قديمة';
    END IF;
END$$

CREATE TRIGGER `trg_wkshp_before_delete` BEFORE DELETE ON `workshop_transactions` FOR EACH ROW BEGIN
    IF (SELECT COALESCE((SELECT can_delete FROM users WHERE id = @current_user_id),0)) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ما عندك صلاحية حذف - فقط المالك';
    END IF;
END$$

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
END$$

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
END$$

CREATE TRIGGER `trg_wkshp_after_delete` AFTER DELETE ON `workshop_transactions` FOR EACH ROW BEGIN
    INSERT INTO audit_log(table_name, record_id, action, old_data, changed_by) VALUES ('workshop_transactions', OLD.id, 'delete', JSON_OBJECT('type',OLD.type,'amount',OLD.amount), @current_user_id);
    DELETE FROM journal_entries WHERE source_table = 'workshop_transactions' AND source_id = OLD.id;
END$$

DELIMITER ;

-- ============================================================================
-- تسجيل تنفيذ هذا الملف بجدول schema_migrations:
-- ============================================================================
INSERT INTO schema_migrations (filename, notes) VALUES
  ('migration_workshop_module.sql', 'إضافة قسم المشغل: إيراد/مصاريف عامة، الإيراد ينعكس فوراً على كاش/بنك زي صيانة خارجية');


-- ============================================================================
-- migration_employee_type_driver_link.sql
-- ============================================================================
-- بيضيف تصنيف "نوع الموظف" لصفحة الموظفين: موظف عادي (regular) أو موظف صيانة/سائق
-- (driver). أي موظف تحدده "موظف صيانة" بيتربط تلقائياً بصفحة "صيانة خارجية" -
-- بيتعمله find_or_create بنفس الاسم بجدول car_employees لو مش موجود أصلاً، فيبين
-- تلقائياً بقائمة السواق هناك (حتى لو لسا ما سجلتله أي حركة إيراد/مصروف).
--
-- ملاحظة: هذا بس تصنيف/ربط بالاسم - الجدولين (employees / car_employees) يضلوا
-- منفصلين محاسبياً تماماً زي ما هم (راتب الموظف بمكان، إيراد ومصاريف تشغيل
-- السيارة بمكان تاني).
-- ============================================================================

ALTER TABLE `employees`
  ADD COLUMN `employee_type` ENUM('regular','driver') NOT NULL DEFAULT 'regular' AFTER `job_title`;

-- ============================================================================
-- تسجيل تنفيذ هذا الملف بجدول schema_migrations:
-- ============================================================================
INSERT INTO schema_migrations (filename, notes) VALUES
  ('migration_employee_type_driver_link.sql', 'إضافة تصنيف نوع الموظف (عادي/صيانة) وربط موظف الصيانة تلقائياً بصيانة خارجية');
