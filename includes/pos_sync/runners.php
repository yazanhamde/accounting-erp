<?php
/**
 * مزامنة POS - نقطتا الدخول التلقائيتان (موردين / عملاء) اللي بيستدعيهم الـ cron فقط
 * (جزء من includes/pos_sync.php سابقاً - انقسم لملفات، المنطق ما تغيّر)
 */

/**
 * مزامنة الموردين التلقائية (دفعات، من الكرون فقط) - دفتر عناوين + كشف حساب آخر POS_SYNC_DAYS_BACK يوم.
 * ما بتلمس العملاء أو المبيعات إطلاقاً.
 */
function pos_run_suppliers_sync($batch_size = null) {
    if (!pos_try_acquire_lock('pos_sync_suppliers')) {
        return [
            'run_id' => null, 'status' => 'skipped',
            'counts' => ['customers_new' => 0, 'customers_updated' => 0, 'suppliers_new' => 0, 'suppliers_updated' => 0, 'sales_tx_new' => 0, 'purchases_tx_new' => 0],
            'errors' => ['في مزامنة موردين شغّالة حالياً - هاي التشغيلة اتخطّت.'],
        ];
    }
    try {
        $db = get_db();
        pos_mark_stale_running_syncs_as_failed();
        $stmt = $db->prepare("INSERT INTO pos_sync_runs (started_at, status, sync_type) VALUES (NOW(), 'running', ?)");
        $stmt->execute(['daily_cron_suppliers']);
        $run_id = $db->lastInsertId();

        $counts = [
            'customers_new' => 0, 'customers_updated' => 0,
            'suppliers_new' => 0, 'suppliers_updated' => 0,
            'sales_tx_new' => 0, 'purchases_tx_new' => 0,
        ];
        $errors = [];

        try { pos_sync_suppliers_v2($run_id, $counts); } catch (Throwable $e) { $errors[] = "pos_sync_suppliers_v2: " . $e->getMessage(); }
        try { pos_sync_supplier_ledgers($run_id, $counts, $batch_size); } catch (Throwable $e) { $errors[] = "pos_sync_supplier_ledgers: " . $e->getMessage(); }

        if (!empty($counts['supplier_ledger_failed'])) {
            $errors[] = "تعذّر سحب كشف حساب {$counts['supplier_ledger_failed']} مورد (rate limit أو مشكلة بمفتاح POS_API_KEY/صلاحياته) - رح تتصحح تلقائياً بالمزامنة الجاية. أول رسالة خطأ: " . ($counts['supplier_ledger_failed_sample'] ?? '');
        }
        if (!empty($counts['tx_failed'])) {
            $errors[] = "فشل حفظ {$counts['tx_failed']} حركة (انعمل rollback لها بدون ما تأثر على الرصيد) - رح تنعاد المحاولة بالمزامنة الجاية. أول خطأ: " . ($counts['tx_failed_sample'] ?? '');
        }

        if (!empty($counts['party_failed'])) {
            $errors[] = "تعذّر حفظ {$counts['party_failed']} من دفتر العناوين (باقي الأسماء انحفظت). أول خطأ: " . ($counts['party_failed_sample'] ?? '');
        }

        $status = empty($errors) ? 'success' : (pos_sum_progress_counts($counts) > 0 ? 'partial' : 'failed');
        $db->prepare('UPDATE pos_sync_runs SET finished_at = NOW(), status = ?, customers_new=?, customers_updated=?, suppliers_new=?, suppliers_updated=?, sales_tx_new=?, purchases_tx_new=?, error_message=? WHERE id = ?')
           ->execute([
               $status,
               $counts['customers_new'], $counts['customers_updated'],
               $counts['suppliers_new'], $counts['suppliers_updated'],
               $counts['sales_tx_new'], $counts['purchases_tx_new'],
               $errors ? implode(' | ', $errors) : null,
               $run_id,
           ]);

        return ['run_id' => $run_id, 'status' => $status, 'counts' => $counts, 'errors' => $errors];
    } finally {
        pos_release_lock('pos_sync_suppliers');
    }
}

/**
 * مزامنة العملاء التلقائية (دفعات، من الكرون فقط) - دفتر عناوين + كشف حساب آخر POS_SYNC_DAYS_BACK يوم.
 */
function pos_run_customer_ledgers_sync($batch_size = null) {
    if (!pos_try_acquire_lock('pos_sync_customer_ledgers')) {
        return [
            'run_id' => null, 'status' => 'skipped',
            'counts' => ['customers_new' => 0, 'customers_updated' => 0, 'suppliers_new' => 0, 'suppliers_updated' => 0, 'sales_tx_new' => 0, 'purchases_tx_new' => 0],
            'errors' => ['في مزامنة عملاء شغّالة حالياً - هاي التشغيلة اتخطّت.'],
        ];
    }
    try {
        $db = get_db();
        pos_mark_stale_running_syncs_as_failed();
        $stmt = $db->prepare("INSERT INTO pos_sync_runs (started_at, status, sync_type) VALUES (NOW(), 'running', ?)");
        $stmt->execute(['daily_cron_customers']);
        $run_id = $db->lastInsertId();

        $counts = [
            'customers_new' => 0, 'customers_updated' => 0,
            'suppliers_new' => 0, 'suppliers_updated' => 0,
            'sales_tx_new' => 0, 'purchases_tx_new' => 0,
        ];
        $errors = [];

        try { pos_sync_customers_v2($run_id, $counts); } catch (Throwable $e) { $errors[] = "pos_sync_customers_v2: " . $e->getMessage(); }
        try { pos_sync_customer_ledgers($run_id, $counts, $batch_size); } catch (Throwable $e) { $errors[] = "pos_sync_customer_ledgers: " . $e->getMessage(); }

        if (!empty($counts['customer_ledger_failed'])) {
            $errors[] = "تعذّر سحب كشف حساب {$counts['customer_ledger_failed']} عميل (rate limit أو مشكلة بمفتاح POS_API_KEY/صلاحياته) - رح تتصحح تلقائياً بالمزامنة الجاية. أول رسالة خطأ: " . ($counts['customer_ledger_failed_sample'] ?? '');
        }
        if (!empty($counts['tx_failed'])) {
            $errors[] = "فشل حفظ {$counts['tx_failed']} حركة (انعمل rollback لها بدون ما تأثر على الرصيد) - رح تنعاد المحاولة بالمزامنة الجاية. أول خطأ: " . ($counts['tx_failed_sample'] ?? '');
        }

        if (!empty($counts['party_failed'])) {
            $errors[] = "تعذّر حفظ {$counts['party_failed']} من دفتر العناوين (باقي الأسماء انحفظت). أول خطأ: " . ($counts['party_failed_sample'] ?? '');
        }

        $status = empty($errors) ? 'success' : (pos_sum_progress_counts($counts) > 0 ? 'partial' : 'failed');
        $db->prepare('UPDATE pos_sync_runs SET finished_at = NOW(), status = ?, customers_new=?, customers_updated=?, suppliers_new=?, suppliers_updated=?, sales_tx_new=?, purchases_tx_new=?, error_message=? WHERE id = ?')
           ->execute([
               $status,
               $counts['customers_new'], $counts['customers_updated'],
               $counts['suppliers_new'], $counts['suppliers_updated'],
               $counts['sales_tx_new'], $counts['purchases_tx_new'],
               $errors ? implode(' | ', $errors) : null,
               $run_id,
           ]);

        return ['run_id' => $run_id, 'status' => $status, 'counts' => $counts, 'errors' => $errors];
    } finally {
        pos_release_lock('pos_sync_customer_ledgers');
    }
}
