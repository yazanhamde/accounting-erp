-- ============================================================================
-- migration_employee_payment_type.sql
-- ============================================================================
-- المشكلة: صفحة الموظفين ما كان فيها طريقة تسجّل فيها "دفعت/سلّمت الموظف راتبه
-- المستحق فعلياً" (فلوس طلعت من كاش/بنك) - كان في بس:
--   - "راتب" (salary)  = يزيد المستحق (استحقاق بس، بدون فلوس فعلية)
--   - "سلفة" (advance) = فلوس فعلية طالعة، بس معناها سلفة قبل الاستحقاق مش تسليم الراتب
--   - "خصم" (deduction) = تنقيص إداري بدون فلوس فعلية
--
-- هيك أضفنا نوع رابع: "دفع راتب مستحق" (payment) - فلوس فعلية طالعة من بنك/كاش
-- ("الموظف اخذ راتبه")، بتنقص المستحق (نفس تأثير السلفة بالضبط على الرصيد
-- والقيد المحاسبي)، بس بمسمى صحيح يوضح إنها تسليم راتب مستحق مش سلفة مسبقة.
-- ============================================================================

ALTER TABLE `employee_transactions`
  MODIFY COLUMN `type` ENUM('salary','bonus','advance','deduction','payment') NOT NULL;

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_emp_after_insert`$$
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
END$$

DROP TRIGGER IF EXISTS `trg_emp_after_update`$$
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
END$$

DELIMITER ;

-- ============================================================================
-- تسجيل تنفيذ هذا الملف بجدول schema_migrations:
-- ============================================================================
INSERT INTO schema_migrations (filename, notes) VALUES
  ('migration_employee_payment_type.sql', 'إضافة نوع "دفع راتب مستحق" لصفحة الموظفين - فلوس فعلية طالعة، مش سلفة');
