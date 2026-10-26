<?php
/**
 * أدوات ترقيم الصفحات (Pagination) - تستخدم بكل صفحات الحركات
 * حتى ما تنحصر القوائم بآخر 100 حركة وتصير كل الحركات بكل الأقسام قابلة للتصفح.
 */

function get_page_number(string $param = 'p'): int
{
    $p = isset($_GET[$param]) ? (int)$_GET[$param] : 1;
    return $p > 0 ? $p : 1;
}

function paginate_count(PDO $db, string $count_sql, array $params = []): int
{
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * يطبع روابط ترقيم الصفحات.
 * $extra_params: باراميترات إضافية لازم تنحفظ بالرابط (مثلاً bank=islami)
 */
function render_pagination(int $current, int $total_pages, array $extra_params = [], string $param = 'p'): void
{
    if ($total_pages <= 1) {
        return;
    }
    $build = function (int $p) use ($extra_params, $param) {
        $params = $extra_params;
        $params[$param] = $p;
        return '?' . http_build_query($params);
    };

    echo '<div class="pagination">';
    echo '<span class="pagination-info">صفحة ' . $current . ' من ' . $total_pages . '</span>';

    if ($current > 1) {
        echo '<a class="btn btn-sm" href="' . htmlspecialchars($build($current - 1)) . '">‹ السابق</a>';
    }

    $start = max(1, $current - 2);
    $end = min($total_pages, $current + 2);

    if ($start > 1) {
        echo '<a class="btn btn-sm" href="' . htmlspecialchars($build(1)) . '">1</a>';
        if ($start > 2) echo '<span class="pagination-dots">…</span>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $cls = $i === $current ? 'btn btn-sm btn-active' : 'btn btn-sm';
        echo '<a class="' . $cls . '" href="' . htmlspecialchars($build($i)) . '">' . $i . '</a>';
    }

    if ($end < $total_pages) {
        if ($end < $total_pages - 1) echo '<span class="pagination-dots">…</span>';
        echo '<a class="btn btn-sm" href="' . htmlspecialchars($build($total_pages)) . '">' . $total_pages . '</a>';
    }

    if ($current < $total_pages) {
        echo '<a class="btn btn-sm" href="' . htmlspecialchars($build($current + 1)) . '">التالي ›</a>';
    }

    echo '</div>';
}
