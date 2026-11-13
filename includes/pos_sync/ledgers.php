<?php
/**
 * مزامنة POS - مزامنة كشوف حساب الموردين والعملاء
 * (جزء من includes/pos_sync.php سابقاً - انقسم لملفات، المنطق ما تغيّر)
 */

/**
 * مزامنة حركات الموردين عن طريق كشف حساب (ledger) كامل لكل مورد
 * من customer_supplier_reports/supplier_statement - كل سطر برجع من الـ API
 * (فاتورة شراء أو دفعة) بيتسجل هون كحركة وحدة بالضبط زي ما هي، معتمدين
 * على "signed_amount" لتحديد الاتجاه (موجب = زيادة دين علينا، سالب = تسديد)
 * مش على "movement_type" لأنه في حالات (خصم مسموح به مثلاً) بتيجي payment
 * بس بأثر عكسي.
 *
 * بتسحب آخر POS_SYNC_DAYS_BACK يوم بس (مزامنة دورية تلقائية - ما في سحب من تاريخ لتاريخ).
 */
function pos_sync_supplier_ledgers($run_id, array &$counts, $batch_size = null) {
    $db = get_db();

    $days_back = (int) env('POS_SYNC_DAYS_BACK', 1);
    $start_date = date('Y-m-d', strtotime("-{$days_back} days"));
    $end_date = date('Y-m-d');

    $all_suppliers = $db->query("SELECT id, pos_id FROM suppliers WHERE pos_id IS NOT NULL AND pos_id <> '' ORDER BY id")->fetchAll();

    if ($batch_size !== null && $batch_size > 0 && count($all_suppliers) > 0) {
        $checkpoint_key = 'supplier_ledger_batch_cursor';
        $cursor = pos_get_checkpoint($checkpoint_key);
        $total = count($all_suppliers);
        $start_idx = ($cursor - 1) % $total;

        $suppliers = [];
        for ($i = 0; $i < min($batch_size, $total); $i++) {
            $suppliers[] = $all_suppliers[($start_idx + $i) % $total];
        }

        $next_cursor = $start_idx + count($suppliers) + 1;
        if ($next_cursor > $total) $next_cursor = (($next_cursor - 1) % $total) + 1;

        $counts['supplier_ledger_batch_info'] = "دفعة {$batch_size} من أصل {$total} مورد (بدأت من ترتيب " . ($start_idx + 1) . ")";
    } else {
        $suppliers = $all_suppliers;
    }

    $deadline = time() + POS_SYNC_MAX_RUN_SECONDS; // سقف زمني للتشغيلة - لو الـ API علق ما نضل شغّالين لحد ما السيرفر يقتلنا
    foreach ($suppliers as $idx => $sup) {
        if (time() > $deadline) { $counts['supplier_ledger_stopped_early'] = true; break; } // الـ checkpoint محفوظ بعد كل مورد
        $local_supplier_id = $sup['id'];
        $pos_supplier_id = $sup['pos_id'];

        // ⚠️ نفس حماية العملاء - فشل مورد واحد ما لازم يسقط باقي الموردين معه.
        try {
            // سحب على دفعات (مش طلب واحد) - نفس تصحيح كشف حساب العملاء، لتفادي
            // تعبئة ذاكرة PHP لما يكون مدى التاريخ طويل (مزامنة من 2020 مثلاً).
            $rows = pos_fetch_all_pages('customer_supplier_reports/supplier_statement', [
                'supplier_id' => $pos_supplier_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
            ]);
        } catch (Throwable $e) {
            $counts['supplier_ledger_failed'] = ($counts['supplier_ledger_failed'] ?? 0) + 1;
            $counts['supplier_ledger_failed_sample'] = $counts['supplier_ledger_failed_sample'] ?? $e->getMessage();

            // 🛑 circuit breaker - لو rate limit أو 401/403 (مفتاح/صلاحية)، نوقف فوراً بدل ما نضيع وقت
            if (pos_is_rate_limit_error($e->getMessage()) || pos_is_auth_error($e->getMessage())) {
                $counts['supplier_ledger_stopped_early'] = true;
                // نحفظ الـ checkpoint عند المورد اللي فشل حتى المرة الجاية تعيد منه
                if (isset($checkpoint_key)) {
                    $failed_idx = $idx;
                    if ($failed_idx !== false) {
                        $rewind_cursor = (($start_idx + $failed_idx) % $total) + 1;
                        pos_set_checkpoint($checkpoint_key, $rewind_cursor);
                    }
                }
                break;
            }

            // فشل عادي (مش rate limit): نسجّله ونتقدّم للمورد التالي عشان ما نعلق عنده
            if (isset($checkpoint_key)) pos_set_checkpoint($checkpoint_key, (($start_idx + $idx + 1) % $total) + 1);
            usleep(300000);
            continue;
        }

        $seen_docs = [];
        foreach ($rows as $row) {
            $document_id = pos_field($row, ['document_id']);
            if (!$document_id) continue;

            $signed = round((float) pos_field($row, ['signed_amount'], 0), 2);
            if (abs($signed) <= POS_AMOUNT_EPSILON) continue; // ما إله أثر مالي فعلي

            $movement_type = pos_field($row, ['movement_type'], '');
            // مطابق لقناعة suppliers.php: debit = فاتورة ذمم (شراء بيرفع الدين)، credit = دفعة من الحساب (بتنزّل الدين)
            $type = $signed > 0 ? 'debit' : 'credit';
            $amount = abs($signed);
            if ($type === 'credit') {
                // دفعة فعلية للمورد - نقرأ طريقة الدفع الحقيقية من الـ POS بدل افتراض كاش
                [$paid_from_bank, $no_bank_reason] = pos_resolve_payment_method(pos_field($row, ['paid_by']));
            } else {
                $paid_from_bank = null;
                $no_bank_reason = 'مشترى POS - متبقي غير مدفوع';
            }

            $date_raw = pos_field($row, ['date']);
            $tx_date = $date_raw ? date('Y-m-d', strtotime($date_raw)) : date('Y-m-d');

            // 🔒 حماية دائمة: أي حركة تاريخها = تاريخ القطع أو قبله، معناها أصلاً
            // ملخّصة جوا حركة "رصيد افتتاحي" - سجلها بالتفصيل هون بيكرر المبلغ.
            // هاي الحماية بتشتغل حتى بالمزامنة اليومية العادية (آخر 5 أيام)، مش بس
            // بالمزامنة اليدوية الكاملة - لأنه "آخر 5 أيام" ممكن يوصل لتاريخ القطع
            // نفسه لغاية كم يوم بعده.
            if ($tx_date <= OPENING_BALANCE_CUTOFF_DATE) continue;
            $seen_docs[(string) $document_id] = true;

            $note = pos_clean_note(pos_field($row, ['note'], ''));
            $ref_no = pos_field($row, ['reference_no'], '');
            $label = $movement_type === 'purchase' ? 'مشترى POS' : ($type === 'credit' ? 'دفعة POS' : 'حركة POS');
            $description = $label . ' #' . $document_id . ($ref_no ? " ($ref_no)" : '') . ($note !== '' ? " - $note" : '');

            // ⚠️ لازم نربط الفحص بالمورد نفسه (local_supplier_id) مش بس برقم المستند،
            // لأنه أرقام المستندات (document_id) بالـ POS مش فريدة عالمستوى الكامل بين
            // كل الموردين - كل مورد إله ترقيم مستقل بكشف حسابه. لو دورنا برقم المستند
            // بس، حركة مورد ممكن "تتصادم" برقم حركة مورد تاني وتنعالج غلط كتحديث
            // على حركة مش إلها، وتأثر على رصيد مورد مش صاحب الحركة الحقيقي.
            $stmt = $db->prepare("SELECT id, amount, type FROM supplier_transactions WHERE pos_ref_type = 'supplier_ledger' AND pos_ref_id = ? AND supplier_id = ?");
            $stmt->execute([$document_id, $local_supplier_id]);
            $existing = $stmt->fetch();

            if (!$existing) {
                try {
                    $db->beginTransaction();
                    $db->prepare("INSERT INTO supplier_transactions
                            (supplier_id, type, amount, description, tx_date, created_by, source, pos_ref_type, pos_ref_id, paid_from_bank, no_bank_reason)
                         VALUES (?, ?, ?, ?, ?, ?, 'pos', 'supplier_ledger', ?, ?, ?)")
                       ->execute([$local_supplier_id, $type, $amount, $description, $tx_date, POS_SYNC_SYSTEM_USER_ID, $document_id, $paid_from_bank, $no_bank_reason]);
                    $new_id = $db->lastInsertId();

                    // الرصيد بيتحدّث تلقائياً من trigger (trg_st_balance_after_insert) - ما منعدّله يدوياً عشان ما ينحسب مرتين
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    pos_record_tx_failure($counts, $e);
                    continue;
                }

                $counts['purchases_tx_new']++;
                pos_log_item($run_id, 'supplier_transaction', 'insert', $new_id, "$description - " . ($type === 'debit' ? "دين $amount" : "دفعة $amount"));
            } else {
                $old_signed = $existing['type'] === 'debit' ? (float) $existing['amount'] : -(float) $existing['amount'];
                if (abs($signed - $old_signed) > POS_AMOUNT_EPSILON) {
                    try {
                        $db->beginTransaction();
                        $db->prepare('UPDATE supplier_transactions SET type = ?, amount = ?, description = ?, paid_from_bank = ?, no_bank_reason = ? WHERE id = ?')
                           ->execute([$type, $amount, $description, $paid_from_bank, $no_bank_reason, $existing['id']]);

                        // الرصيد بيتحدّث تلقائياً من trigger (trg_st_balance_after_update) - بدون تعديل يدوي
                        $db->commit();
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        pos_record_tx_failure($counts, $e);
                        continue;
                    }

                    $counts['purchases_tx_new']++;
                    pos_log_item($run_id, 'supplier_transaction', 'update', $existing['id'], "$description - تغيّرت من $old_signed إلى $signed");
                }
            }
        }

        // 🗑️ حذف الحركات المحلية اللي انحذفت من الـ POS (ضمن نفس المدى الزمني بس)
        pos_prune_deleted_ledger_tx('supplier_transactions', 'supplier_id', 'supplier_ledger', 'suppliers',
            $local_supplier_id, $seen_docs, $start_date, $end_date, $run_id, 'supplier_transaction', $counts);

        // ✅ نحفظ التقدّم بعد كل مورد (مش بس آخر الدفعة) - لو السيرفر قتل التشغيلة بنكمل من هون
        if (isset($checkpoint_key)) pos_set_checkpoint($checkpoint_key, (($start_idx + $idx + 1) % $total) + 1);

        usleep(300000); // وقفة بسيطة بين كل مورد وتالي لتفادي rate limit الـ POS API
    }
}

// ==================== كشف حساب عملاء (نفس منطق الموردين بالضبط - customer_supplier_reports) ====================
/**
 * $batch_size: لو انبعت رقم (مش null)، ما منعالج كل العملاء بمرة وحدة - منعالج
 * دفعة بس بحجم $batch_size، ومنحفظ "وين وصلنا" بجدول pos_sync_checkpoints
 * (checkpoint key: customer_ledger_batch_cursor). المرة الجاية بتكمل من وين
 * وقفت، ولما توصل لآخر عميل بترجع تلف من الأول تاني. هيك لو قسمنا اليوم لـ 12
 * مزامنة (كل وحدة دفعة ~29 عميل من 346)، كل دفعة بتضل تحت سقف الـ POS بأمان،
 * وبعد الـ 12 مزامنة بيكونوا انسحبوا كلهم.
 */
function pos_sync_customer_ledgers($run_id, array &$counts, $batch_size = null) {
    $db = get_db();

    $days_back = (int) env('POS_SYNC_DAYS_BACK', 1);
    $start_date = date('Y-m-d', strtotime("-{$days_back} days"));
    $end_date = date('Y-m-d');

    $all_customers = $db->query("SELECT id, pos_id FROM customers WHERE pos_id IS NOT NULL AND pos_id <> '' ORDER BY id")->fetchAll();

    if ($batch_size !== null && $batch_size > 0 && count($all_customers) > 0) {
        $checkpoint_key = 'customer_ledger_batch_cursor';
        $cursor = pos_get_checkpoint($checkpoint_key); // 1-indexed، أول مرة = 1
        $total = count($all_customers);
        $start_idx = ($cursor - 1) % $total; // نلف تلقائياً لو وصلنا آخر القائمة

        $customers = [];
        for ($i = 0; $i < min($batch_size, $total); $i++) {
            $customers[] = $all_customers[($start_idx + $i) % $total];
        }

        $next_cursor = $start_idx + count($customers) + 1;
        if ($next_cursor > $total) $next_cursor = (($next_cursor - 1) % $total) + 1;
        // الـ checkpoint بينحفظ بعد كل عميل، ولما يصير rate limit/401 بيرجع للعميل اللي وقفنا عنده
        $rate_limit_hit_at = null; // index بقائمة $customers لو صار rate limit

        $counts['customer_ledger_batch_info'] = "دفعة {$batch_size} من أصل {$total} عميل (بدأت من ترتيب " . ($start_idx + 1) . ")";
    } else {
        $customers = $all_customers;
    }

    $deadline = time() + POS_SYNC_MAX_RUN_SECONDS;
    foreach ($customers as $idx => $cust) {
        if (time() > $deadline) { $counts['customer_ledger_stopped_early'] = true; break; } // الـ checkpoint محفوظ بعد كل عميل
        $local_customer_id = $cust['id'];
        $pos_customer_id = $cust['pos_id'];

        // ⚠️ لازم نغلّف كل عميل بمحاولة/التقاط لحاله - لو فشل عميل واحد (حتى
        // بعد إعادة المحاولات جوا pos_api_get)، ما لازم يسقط باقي الـ 345 عميل
        // معه. منسجل الفشل ومنكمل - وبيتصحح تلقائياً بالمزامنة الجاية.
        try {
            // ⚠️ لازم نسحب على دفعات (pos_fetch_all_pages) مش بطلب واحد - لأنه لما يكون
            // مدى التاريخ طويل (من 2020 مثلاً) الـ API بيرجع آلاف السجلات دفعة وحدة
            // ورد الـ JSON الضخم كان بيعبي الذاكرة المسموحة لـ PHP ويطيح الصفحة
            // (Allowed memory size exhausted بملف pos_api_client.php).
            $rows = pos_fetch_all_pages('customer_supplier_reports/customer_statement', [
                'customer_id' => $pos_customer_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
            ]);
        } catch (Throwable $e) {
            // ⚠️ ما منسجلها بجدول pos_sync_items لأنه عمود entity_type/action فيه enum
            // بقيم محددة سلفاً وما بيقبل قيم جديدة زي "error" - بنكتفي بعدّاد هون،
            // ورسالة الخطأ الكاملة بتنضاف لملخص العملية (pos_sync_runs.error_message)
            // من الدالة يلي بتنادي هاي الدالة.
            $counts['customer_ledger_failed'] = ($counts['customer_ledger_failed'] ?? 0) + 1;
            $counts['customer_ledger_failed_sample'] = $counts['customer_ledger_failed_sample'] ?? $e->getMessage();

            // 🛑 Circuit breaker: لو السبب rate limit، يعني الحصة (quota) مستنفذة
            // فعلياً - وباقي العملاء رح يفشلوا كمان أكيد. نوقف الحلقة فوراً بدل
            // ما نضيع وقت (ودقايق تنفيذ السيرفر) على 300+ محاولة فاشلة أكيد.
            // باقي العملاء رح ينسحبوا بالمزامنة الجاية لما الحصة تترجع.
            if (pos_is_rate_limit_error($e->getMessage()) || pos_is_auth_error($e->getMessage())) {
                $counts['customer_ledger_stopped_early'] = true;
                // نحفظ الـ checkpoint عند العميل اللي فشل (مش بعده) حتى المرة الجاية تعيد منه
                $failed_idx = $idx;
                if ($failed_idx !== false && isset($checkpoint_key)) {
                    $rewind_cursor = (($start_idx + $failed_idx) % $total) + 1;
                    pos_set_checkpoint($checkpoint_key, $rewind_cursor);
                }
                break;
            }

            // فشل عادي (مش rate limit): نسجّله ونتقدّم للعميل التالي عشان ما نعلق عنده
            if (isset($checkpoint_key)) pos_set_checkpoint($checkpoint_key, (($start_idx + $idx + 1) % $total) + 1);
            usleep(300000);
            continue;
        }

        $seen_docs = [];
        foreach ($rows as $row) {
            $document_id = pos_field($row, ['document_id']);
            if (!$document_id) continue;

            $signed = round((float) pos_field($row, ['signed_amount'], 0), 2);
            if (abs($signed) <= POS_AMOUNT_EPSILON) continue;

            $movement_type = pos_field($row, ['movement_type'], '');
            // مبيعة بترفع دين العميل (debit) / دفعة من العميل بتنزّل الدين (credit)
            $type = $signed > 0 ? 'debit' : 'credit';
            $amount = abs($signed);
            if ($type === 'credit') {
                // دفعة فعلية من العميل - نقرأ طريقة الدفع الحقيقية من الـ POS بدل افتراض كاش
                [$paid_from_bank, $no_bank_reason] = pos_resolve_payment_method(pos_field($row, ['paid_by']));
            } else {
                $paid_from_bank = null;
                $no_bank_reason = 'مبيعة POS - متبقي غير مدفوع';
            }

            $date_raw = pos_field($row, ['date']);
            $tx_date = $date_raw ? date('Y-m-d', strtotime($date_raw)) : date('Y-m-d');

            // 🔒 نفس حماية الموردين: تجاهل أي حركة بتاريخ القطع أو قبله (ملخّصة
            // أصلاً بالرصيد الافتتاحي).
            if ($tx_date <= OPENING_BALANCE_CUTOFF_DATE) continue;
            $seen_docs[(string) $document_id] = true;

            $note = pos_clean_note(pos_field($row, ['note'], ''));
            $ref_no = pos_field($row, ['reference_no'], '');
            $label = $movement_type === 'sale' ? 'مبيعة POS' : 'حركة POS';
            $description = $label . ' #' . $document_id . ($ref_no ? " ($ref_no)" : '') . ($note !== '' ? " - $note" : '');

            // ⚠️ نفس درس الموردين: لازم نربط الفحص بالعميل نفسه، مش برقم المستند بس
            $stmt = $db->prepare("SELECT id, amount, type FROM customer_transactions WHERE pos_ref_type = 'customer_ledger' AND pos_ref_id = ? AND customer_id = ?");
            $stmt->execute([$document_id, $local_customer_id]);
            $existing = $stmt->fetch();

            if (!$existing) {
                try {
                    $db->beginTransaction();
                    $db->prepare("INSERT INTO customer_transactions
                            (customer_id, type, amount, description, tx_date, created_by, source, pos_ref_type, pos_ref_id, paid_from_bank, no_bank_reason)
                         VALUES (?, ?, ?, ?, ?, ?, 'pos', 'customer_ledger', ?, ?, ?)")
                       ->execute([$local_customer_id, $type, $amount, $description, $tx_date, POS_SYNC_SYSTEM_USER_ID, $document_id, $paid_from_bank, $no_bank_reason]);
                    $new_id = $db->lastInsertId();

                    // الرصيد بيتحدّث تلقائياً من trigger (trg_ct_balance_after_insert) - ما منعدّله يدوياً عشان ما ينحسب مرتين
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    pos_record_tx_failure($counts, $e);
                    continue;
                }

                $counts['sales_tx_new']++;
                pos_log_item($run_id, 'customer_transaction', 'insert', $new_id, "$description - " . ($type === 'debit' ? "دين $amount" : "دفعة $amount"));
            } else {
                $old_signed = $existing['type'] === 'debit' ? (float) $existing['amount'] : -(float) $existing['amount'];
                if (abs($signed - $old_signed) > POS_AMOUNT_EPSILON) {
                    try {
                        $db->beginTransaction();
                        $db->prepare('UPDATE customer_transactions SET type = ?, amount = ?, description = ?, paid_from_bank = ?, no_bank_reason = ? WHERE id = ?')
                           ->execute([$type, $amount, $description, $paid_from_bank, $no_bank_reason, $existing['id']]);

                        // الرصيد بيتحدّث تلقائياً من trigger (trg_ct_balance_after_update) - بدون تعديل يدوي
                        $db->commit();
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        pos_record_tx_failure($counts, $e);
                        continue;
                    }

                    $counts['sales_tx_new']++;
                    pos_log_item($run_id, 'customer_transaction', 'update', $existing['id'], "$description - تغيّرت من $old_signed إلى $signed");
                }
            }
        }

        // 🗑️ حذف الحركات المحلية اللي انحذفت من الـ POS (ضمن نفس المدى الزمني بس)
        pos_prune_deleted_ledger_tx('customer_transactions', 'customer_id', 'customer_ledger', 'customers',
            $local_customer_id, $seen_docs, $start_date, $end_date, $run_id, 'customer_transaction', $counts);

        // ✅ نحفظ التقدّم بعد كل عميل (مش بس آخر الدفعة) - لو السيرفر قتل التشغيلة بنكمل من هون
        if (isset($checkpoint_key)) pos_set_checkpoint($checkpoint_key, (($start_idx + $idx + 1) % $total) + 1);

        usleep(300000); // نفس وقفة الموردين لتفادي rate limit
    }
}

/**
 * بتحذف من عندنا أي حركة POS (ضمن المدى start..end فقط) ما رجعت بكشف الحساب الحالي من الـ POS،
 * يعني انحذفت أو صارت بدون أثر مالي هناك، وبتعكس أثرها على الرصيد.
 * ⚠️ بتنادى فقط بعد نجاح سحب كشف الحساب كامل (لو صار خطأ بنقفز قبل ما نوصل لهون).
 * أسماء الجداول/الأعمدة جاية من الكود نفسه (مش من مدخلات مستخدم).
 */
function pos_prune_deleted_ledger_tx($tx_table, $party_col, $ref_type, $party_table, $party_id, array $seen_docs, $start_date, $end_date, $run_id, $entity_type, array &$counts) {
    $db = get_db();
    $from = max($start_date, date('Y-m-d', strtotime(OPENING_BALANCE_CUTOFF_DATE . ' +1 day')));
    $stmt = $db->prepare("SELECT id, type, amount, description, pos_ref_id FROM {$tx_table}
                          WHERE pos_ref_type = ? AND {$party_col} = ? AND source = 'pos' AND tx_date BETWEEN ? AND ?");
    $stmt->execute([$ref_type, $party_id, $from, $end_date]);
    foreach ($stmt->fetchAll() as $tx) {
        if (isset($seen_docs[(string) $tx['pos_ref_id']])) continue;

        try {
            $db->beginTransaction();
            // الرصيد بيتحدّث تلقائياً من trigger (trg_*_balance_after_delete) - بدون تعديل يدوي
            // ملاحظة: trigger الحذف (trg_*_before_delete) بيرفض الحذف إلا لمستخدم عنده can_delete،
            // والكرون ما عنده مستخدم - فالحذف ممكن يفشل، ونسجّله بدل ما نبلعه بصمت.
            $db->prepare("DELETE FROM {$tx_table} WHERE id = ?")->execute([$tx['id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            pos_record_tx_failure($counts, $e);
            continue;
        }

        $counts['deleted_tx'] = ($counts['deleted_tx'] ?? 0) + 1;
        // العمود action بجدول pos_sync_items لازم يدعم 'delete' (شغّل database/migration_pos_sync_delete_action.sql) - وإلا بنتجاهل التسجيل بدون ما نوقف المزامنة
        try {
            pos_log_item($run_id, $entity_type, 'delete', $tx['id'], $tx['description'] . ' - انحذفت من الـ POS (رُجّع ' . $tx['amount'] . ')');
        } catch (Throwable $e) {}
    }
}
