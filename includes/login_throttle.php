<?php
/**
 * حد محاولات تسجيل الدخول - حماية من تخمين كلمات السر (brute force)
 * ----------------------------------------------------------------
 * القفل بيتحسب على 3 مستويات بنافذة 15 دقيقة (كل المحاولات الفاشلة فقط):
 *   - نفس (اسم المستخدم + IP):  5 محاولات  -> قفل
 *   - نفس الـ IP (أي اسم):     20 محاولة   -> قفل (بيتحمل أكتر من موظف خلف نفس الراوتر)
 *   - نفس اسم المستخدم (أي IP): 25 محاولة  -> قفل (رقم عالي حتى ما يقدر حدا يقفل حساب المالك بسهولة)
 * لو الجدول مش موجود/في خطأ DB: بنفتح (fail-open) ونكتب بـ error_log، حتى ما ينقفل النظام على أصحابه.
 *
 * لو الموقع ورا Cloudflare/بروكسي: حط بالـ .env  TRUSTED_IP_HEADER=HTTP_CF_CONNECTING_IP
 * (بدونها بنعتمد REMOTE_ADDR فقط، لأن X-Forwarded-For بيتزوّر بسهولة)
 */
const LOGIN_WINDOW_SECONDS = 900;
const LOGIN_MAX_PAIR = 5;
const LOGIN_MAX_IP = 20;
const LOGIN_MAX_USER = 25;
// هاش وهمي: نستخدمه لما اسم المستخدم مش موجود حتى وقت الرد يبقى نفسه (منع كشف أسماء المستخدمين بالتوقيت)
const LOGIN_DUMMY_HASH = '$2y$10$VzuXbxgctxS8jGmgxLYKNuvMMiF5PIvkVFWsHSOavKP0BGM6BE9X6';

function login_client_ip() {
    $hdr = function_exists('env') ? env('TRUSTED_IP_HEADER', '') : '';
    if ($hdr !== '' && !empty($_SERVER[$hdr])) {
        $ip = trim(explode(',', $_SERVER[$hdr])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function login_norm_username($username) {
    return mb_substr(mb_strtolower(trim((string)$username)), 0, 64);
}

function login_ensure_table($db) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at INT UNSIGNED NOT NULL,
            KEY idx_ip_time (ip, success, attempted_at),
            KEY idx_user_time (username, success, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (\Throwable $e) {
        error_log('[login_throttle] create table: ' . $e->getMessage());
    }
}

// المحاولة رقم N من الأحدث (لو في N فاشلة بالنافذة) -> وقت القفل ينتهي عند (وقتها + النافذة)
function login_scope_retry_after($db, $where_sql, $params, $max, $now) {
    $st = $db->prepare("SELECT attempted_at FROM login_attempts WHERE success = 0 AND attempted_at > ? AND $where_sql ORDER BY attempted_at DESC LIMIT " . (int)$max);
    $st->execute(array_merge([$now - LOGIN_WINDOW_SECONDS], $params));
    $times = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($times) < $max) return 0;
    return max(1, (int)end($times) + LOGIN_WINDOW_SECONDS - $now);
}

/** @return array ['locked'=>bool, 'retry_after'=>seconds] */
function login_lock_status($username, $now = null) {
    $now = $now ?? time();
    try {
        $db = get_db();
        login_ensure_table($db);
        $u = login_norm_username($username);
        $ip = login_client_ip();
        $retry = max(
            login_scope_retry_after($db, 'ip = ? AND username = ?', [$ip, $u], LOGIN_MAX_PAIR, $now),
            login_scope_retry_after($db, 'ip = ?', [$ip], LOGIN_MAX_IP, $now),
            login_scope_retry_after($db, 'username = ?', [$u], LOGIN_MAX_USER, $now)
        );
        return ['locked' => $retry > 0, 'retry_after' => $retry];
    } catch (\Throwable $e) {
        error_log('[login_throttle] status: ' . $e->getMessage());
        return ['locked' => false, 'retry_after' => 0]; // fail-open
    }
}

function login_record_attempt($username, $success, $now = null) {
    $now = $now ?? time();
    try {
        $db = get_db();
        login_ensure_table($db);
        $u = login_norm_username($username);
        $ip = login_client_ip();
        if ($success) {
            // دخول ناجح: نصفّر الفاشلة لهالمستخدم من هالـ IP فقط
            $db->prepare('DELETE FROM login_attempts WHERE success = 0 AND username = ? AND ip = ?')->execute([$u, $ip]);
            return;
        }
        $db->prepare('INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES (?,?,0,?)')->execute([$u, $ip, $now]);
        if (mt_rand(1, 50) === 1) { // تنظيف دوري للسجلات الأقدم من يوم
            $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$now - 86400]);
        }
    } catch (\Throwable $e) {
        error_log('[login_throttle] record: ' . $e->getMessage());
    }
}

function login_lock_message($retry_after) {
    $mins = max(1, (int)ceil($retry_after / 60));
    return "محاولات دخول فاشلة كتير. حاول مرة ثانية بعد حوالي {$mins} دقيقة.";
}
