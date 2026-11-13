<?php
/**
 * =====================================================================
 * "عقل" الشات بوت: يبعت رسالة المستخدم لـ OpenAI مع وصف دقيق
 * لكل الإجراءات الممكنة، وياخد رد بصيغة JSON منظمة، ثم ننفذها.
 * =====================================================================
 */
require_once __DIR__ . '/actions.php';

require_once __DIR__ . '/ai_brain/prompt.php';
require_once __DIR__ . '/ai_brain/openai.php';
require_once __DIR__ . '/ai_brain/logger.php';
require_once __DIR__ . '/ai_brain/handler.php';
require_once __DIR__ . '/ai_brain/executor.php';
