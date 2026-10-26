<?php
require_once __DIR__ . '/includes/auth.php';
require_owner(); // معلومات بنية القاعدة - المالك فقط
$db = get_db();
$page_title = 'سجل ترحيلات القاعدة';

function table_exists_mig($db, $name) {
    $s = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$name]);
    return (bool)$s->fetch();
}
$has_table = table_exists_mig($db, 'schema_migrations');
$rows = $has_table ? $db->query("SELECT * FROM schema_migrations ORDER BY id ASC")->fetchAll() : [];

// أي ملف .sql موجود بمجلد database بس مش مسجّل بالجدول (نسيت توثقه!)
$disk_files = array_map('basename', glob(__DIR__ . '/database/*.sql'));
$logged_files = array_column($rows, 'filename');
$untracked = array_values(array_diff($disk_files, $logged_files));

require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h2>📜 سجل ترحيلات قاعدة البيانات (Migrations Log)</h2>
  <p>كل ملف SQL اتنفذ على القاعدة لازم يكون هون. لو شغّلت ملف جديد من phpMyAdmin، سجّله فوراً بسطر INSERT بنفس الملف (زي ما هو موضّح بأعلى <code>migration_v3_five_fixes.sql</code>) حتى ما حدا ينفذه مرتين بالغلط أو ينسى شو تغيّر وإيمتى.</p>

  <?php if (!$has_table): ?>
    <div class="panel">⚠️ شغّل ملف <code>database/migration_v3_five_fixes.sql</code> أولاً حتى ينشأ هذا السجل.</div>
  <?php else: ?>

  <?php if ($untracked): ?>
  <div class="panel" style="border:2px solid #d9534f;background:#fff5f5">
    <strong>⚠️ في ملفات SQL بمجلد database/ مش مسجّلة بالسجل (يعني ما موثّق إنها اتنفذت فعلياً):</strong>
    <ul><?php foreach ($untracked as $u): ?><li><code><?= htmlspecialchars($u) ?></code></li><?php endforeach; ?></ul>
    <p>لو فعلاً نفّذتها على القاعدة، ضيفها للجدول: <code>INSERT INTO schema_migrations (filename, notes) VALUES ('اسم_الملف.sql', 'وصف مختصر');</code></p>
  </div>
  <?php endif; ?>

  <table class="table">
    <thead><tr><th>#</th><th>اسم الملف</th><th>تاريخ التنفيذ</th><th>ملاحظات</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><code><?= htmlspecialchars($r['filename']) ?></code></td>
        <td><?= $r['applied_at'] ?></td>
        <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
