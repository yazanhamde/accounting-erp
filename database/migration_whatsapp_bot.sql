-- ============================================================================
-- migration_whatsapp_bot.sql
-- ============================================================================
-- جدول بسيط لمنع تنفيذ نفس رسالة الواتساب مرتين. ميتا ممكن ترسل نفس webhook
-- أكتر من مرة (retry) لو السيرفر تأخر بالرد أو صار أي انقطاع مؤقت، فلازم
-- نتأكد إنه message id معين ما بينفّذ إلا مرة وحدة بس.
-- نفّذ هذا الملف كامل على القاعدة قبل ما تفعّل api/whatsapp_webhook.php
-- ============================================================================

CREATE TABLE IF NOT EXISTS `whatsapp_processed_messages` (
  `wa_message_id` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`wa_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='لمنع تكرار تنفيذ نفس رسالة واتساب لو ميتا أعادت إرسال نفس webhook';
