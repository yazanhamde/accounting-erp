<?php
/**
 * مزامنة POS - جلب البيانات من الـ POS API ودوال مساعدة (حقول، طريقة الدفع، تنظيف الملاحظات)
 * (جزء من includes/pos_sync.php سابقاً - انقسم لملفات، المنطق ما تغيّر)
 */

/** يجيب كل صفحات endpoint معيّن (الحد الأقصى للـ limit حسب توثيق SMA هو 20) */
function pos_fetch_all_pages($path, $extra_params = [], $limit = 20, $max_pages = 500) {
    $all = [];
    $start = 1;
    for ($i = 0; $i < $max_pages; $i++) {
        $params = array_merge(['start' => $start, 'limit' => $limit], $extra_params);
        $decoded = pos_api_get($path, $params);
        $rows = pos_api_extract_list($decoded);
        if (empty($rows)) break;
        $all = array_merge($all, $rows);
        if (count($rows) < $limit) break; // آخر صفحة
        $start += $limit;
        usleep(400000); // وقفة بسيطة (0.4 ثانية) بين كل صفحة وتالية لتفادي rate limit الـ POS API
    }
    return $all;
}

/**
 * زي pos_fetch_all_pages بس بتوقف لما توصل لسجلات أقدم من $cutoff_date
 * (لازم يكون الترتيب order_by=date,desc). هاي بتفرق كتير لما يكون عدد
 * السجلات بالـ endpoint كبير جداً (مثلاً 60 ألف فاتورة مبيعات) لأنه
 * بدون هالحد، كل مزامنة كانت بتجيب كل السجلات من أول يوم = آلاف طلبات
 * API متتالية وهاد اللي كان يضرب rate limit الموردة من POS مباشرة.
 */
function pos_fetch_recent_pages($path, $extra_params, $cutoff_date, $limit = 20, $max_pages = 500) {
    $all = [];
    $start = 1;
    $params_base = array_merge(['order_by' => 'date,desc'], $extra_params);
    for ($i = 0; $i < $max_pages; $i++) {
        $params = array_merge($params_base, ['start' => $start, 'limit' => $limit]);
        $decoded = pos_api_get($path, $params);
        $rows = pos_api_extract_list($decoded);
        if (empty($rows)) break;

        $reached_cutoff = false;
        foreach ($rows as $row) {
            $d = pos_field($row, ['date']);
            $ts = $d ? strtotime($d) : null;
            if ($ts !== null && $ts < $cutoff_date) {
                $reached_cutoff = true;
                continue; // هاد السجل أقدم من الحد - نتجاهله بس منكمل نفحص الباقي بنفس الصفحة
            }
            $all[] = $row;
        }
        if ($reached_cutoff) break; // وصلنا لسجلات أقدم من الحد - وقف تماماً
        if (count($rows) < $limit) break; // آخر صفحة أصلاً
        $start += $limit;
        usleep(400000);
    }
    return $all;
}

function pos_field($row, array $keys, $default = null) {
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '') return $row[$k];
    }
    return $default;
}

/**
 * تحدّد طريقة الدفع الفعلية لحركة "دفعة" (credit) قادمة من الـ POS، بالاعتماد
 * على حقل paid_by الحقيقي يلي بيرجعه الـ API - بدل الافتراض القديم إنه كل
 * دفعة = كاش تلقائياً (وهاد كان غلط: الـ API ممكن يرجّع "Cheque" مثلاً،
 * ووقتها الدفعة مش كاش أبداً).
 *
 * بترجع مصفوفة [paid_from_bank, no_bank_reason]:
 * - paid_from_bank: القيمة يلي بتتخزن بعمود paid_from_bank بجدول الحركات
 *   (cash لو دفعة كاش فعلية حسب الـ POS، أو null لو مش كاش/مش معروف)
 * - no_bank_reason: سبب واضح يفهمه المحاسب/المدقق، بيظهر بتقرير suspense
 *   لأي حالة مش "كاش مؤكد 100%" عشان تنراجع يدوياً.
 *
 * ⚠️ القيم المعروفة لحد هلق من توثيق/تجربة SMA: "cash", "Cheque".
 * أي قيمة تانية (أو null رغم إنها دفعة فعلية) بتترك paid_from_bank فاضي
 * وبتتحط بخانة الاستثناءات، عن قصد - أحسن نراجعها يدوياً من إننا نخمّن
 * ونحطها بحساب بنكي غلط.
 */
function pos_resolve_payment_method($paid_by_raw) {
    $normalized = is_string($paid_by_raw) ? trim($paid_by_raw) : $paid_by_raw;

    if ($normalized === null || $normalized === '') {
        return [null, 'دفعة POS - طريقة الدفع غير محددة من الـ API (يحتاج مراجعة يدوية)'];
    }

    $lower = strtolower($normalized);

    if ($lower === 'cash') {
        return ['cash', null];
    }

    if ($lower === 'cheque' || $lower === 'check') {
        return [null, 'دفعة POS بشيك (paid_by=' . $normalized . ') - راجع/سجّل الشيك يدوياً بقسم الشيكات، مش كاش'];
    }

    // قيمة غير متوقعة (مثلاً اسم بنك أو تحويل) - نحطها بالاستثناءات بدل ما نخمّن
    return [null, 'دفعة POS - قيمة paid_by غير معروفة بالنظام: "' . $normalized . '" (يحتاج مراجعة/إضافة دعم لها)'];
}

/** بينضف نص الملاحظة الراجعة من الـ API (فيها HTML entities مشفرة زي &lt;p&gt;) */
function pos_clean_note($note) {
    if (!$note) return '';
    $note = html_entity_decode((string) $note, ENT_QUOTES, 'UTF-8');
    $note = strip_tags($note);
    return trim(preg_replace('/\s+/u', ' ', $note));
}
