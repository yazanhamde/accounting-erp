<?php
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
$page_title = 'سجل محادثات البوت';
$db = get_db();

// حذف رسالة (تصحيح سجل غلط أو تنظيف)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_log') {
    $db->prepare('DELETE FROM chat_log WHERE id = ?')->execute([(int)$_POST['log_id']]);
    header('Location: chat_log.php');
    exit;
}

$filter = $_GET['status'] ?? '';
$sql = 'SELECT cl.*, u.username FROM chat_log cl LEFT JOIN users u ON u.id = cl.user_id';
$params = [];
if (in_array($filter, ['success', 'failed', 'needs_clarification'], true)) {
    $sql .= ' WHERE cl.status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY cl.id DESC LIMIT 200';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div class="panel">
    <h2>💬 سجل محادثات البوت (آخر 200 رسالة)</h2>
    <p style="color:#666; font-size:14px;">مراجعة كل رسائل المستخدمين للبوت، شو فهم منها، وشو كانت النتيجة. مفيد لتصليح أي غلط بالفهم أو تحسين تعليمات البوت.</p>

    <div style="margin-bottom:14px; display:flex; gap:8px;">
        <a href="chat_log.php" class="btn <?= $filter === '' ? 'btn-active' : '' ?>">الكل</a>
        <a href="chat_log.php?status=success" class="btn <?= $filter === 'success' ? 'btn-active' : '' ?>">✅ ناجحة</a>
        <a href="chat_log.php?status=failed" class="btn <?= $filter === 'failed' ? 'btn-active' : '' ?>">❌ فاشلة</a>
        <a href="chat_log.php?status=needs_clarification" class="btn <?= $filter === 'needs_clarification' ? 'btn-active' : '' ?>">⏳ بانتظار تأكيد</a>
    </div>

    <div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th>الوقت</th>
                <th>المستخدم</th>
                <th>رسالة المستخدم</th>
                <th>فهم البوت (JSON)</th>
                <th>رد البوت</th>
                <th>الحالة</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td style="white-space:nowrap;"><?= htmlspecialchars($log['created_at']) ?></td>
                <td style="white-space:normal; max-width:120px;"><?= htmlspecialchars($log['username'] ?? '—') ?></td>
                <td style="white-space:normal; max-width:220px; word-break:break-word;"><?= htmlspecialchars($log['user_message']) ?></td>
                <td style="max-width:280px; font-size:12px; direction:ltr; text-align:left; white-space:normal; word-break:break-word;">
                    <code><?= htmlspecialchars($log['parsed_action'] ?? '') ?></code>
                </td>
                <td style="white-space:normal; max-width:220px; word-break:break-word;"><?= htmlspecialchars($log['bot_reply'] ?? '') ?></td>
                <td style="white-space:nowrap;">
                    <?php if ($log['status'] === 'success'): ?>
                        <span style="color:#16a34a;">✅ ناجحة<?= $log['undone'] ? ' (متراجع عنها)' : '' ?></span>
                    <?php elseif ($log['status'] === 'failed'): ?>
                        <span style="color:#dc2626;">❌ فاشلة</span>
                    <?php else: ?>
                        <span style="color:#d97706;">⏳ بانتظار تأكيد</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" onsubmit="return confirm('حذف هذا السجل نهائياً؟ (هذا بيحذف السجل بس، مش الحركة المحاسبية نفسها لو انسجلت)');">
                        <input type="hidden" name="action" value="delete_log">
                        <input type="hidden" name="log_id" value="<?= (int)$log['id'] ?>">
                        <button type="submit" class="btn-icon" title="حذف السجل">🗑️</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
            <tr><td colspan="7" style="text-align:center; color:#888;">ما في رسائل بعد.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
