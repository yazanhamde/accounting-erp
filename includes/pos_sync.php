<?php
/**
 * نقطة الدخول لمزامنة الـ POS.
 * كان هالملف 1200 سطر، وهلق انقسم لملفات صغيرة داخل includes/pos_sync/
 * الأسماء ما تغيّرت، فكل الملفات اللي بتعمل require_once لهالملف (cron/ و admin/) ما بتحتاج تعديل.
 */
require_once __DIR__ . '/pos_api_client.php';
require_once __DIR__ . '/db.php';

foreach (['config', 'api', 'runtime', 'parties', 'ledgers', 'runners'] as $pos_sync_part) {
    require_once __DIR__ . '/pos_sync/' . $pos_sync_part . '.php';
}
unset($pos_sync_part);
