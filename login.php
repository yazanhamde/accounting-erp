<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    header('Location: ' . APP_BASE_URL . '/pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $lock = login_lock_status($username);
    if ($lock['locked']) {
        http_response_code(429);
        header('Retry-After: ' . (int) $lock['retry_after']);
        $error = login_lock_message($lock['retry_after']);
    } elseif (attempt_login($username, $password)) {
        header('Location: ' . APP_BASE_URL . '/pages/dashboard.php');
        exit;
    } else {
        // هل هاي المحاولة الفاشلة هي اللي قفلت الحساب؟ منعلم المستخدم فوراً بدل ما يكتشف بالمحاولة الجاية
        $lock = login_lock_status($username);
        if ($lock['locked']) {
            http_response_code(429);
            header('Retry-After: ' . (int) $lock['retry_after']);
            $error = login_lock_message($lock['retry_after']);
        } else {
            $error = 'اسم المستخدم أو كلمة السر غير صحيحة';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول - <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
    :root{
        --gold: #c9a24b;
        --gold-light: #e8cf8a;
        --navy-1: #0b1220;
        --navy-2: #16213a;
        --navy-3: #1c2b4a;
        --glass-border: rgba(201,162,75,.35);
    }
    *{ box-sizing:border-box; margin:0; padding:0; }
    html,body{ height:100%; }
    body{
        font-family:'Cairo','Segoe UI',Tahoma,sans-serif;
        direction:rtl;
        min-height:100vh;
        display:flex;
        align-items:center;
        justify-content:center;
        background:
            radial-gradient(circle at 15% 15%, rgba(201,162,75,.10), transparent 40%),
            radial-gradient(circle at 85% 85%, rgba(201,162,75,.08), transparent 45%),
            linear-gradient(150deg, var(--navy-1) 0%, var(--navy-2) 55%, var(--navy-3) 100%);
        position:relative;
        overflow:hidden;
        padding:20px;
    }
    body::before, body::after{
        content:"";
        position:absolute;
        width:520px; height:520px;
        border:1px solid rgba(201,162,75,.15);
        border-radius:50%;
        z-index:0;
    }
    body::before{ top:-180px; right:-180px; }
    body::after{ bottom:-220px; left:-220px; border-color: rgba(201,162,75,.10); }

    .grid-lines{
        position:absolute; inset:0; z-index:0;
        background-image:
            linear-gradient(rgba(201,162,75,.05) 1px, transparent 1px),
            linear-gradient(90deg, rgba(201,162,75,.05) 1px, transparent 1px);
        background-size: 46px 46px;
        mask-image: radial-gradient(circle at 50% 40%, rgba(0,0,0,.6), transparent 70%);
    }

    .auth-wrap{
        position:relative;
        z-index:2;
        width:100%;
        max-width:420px;
        animation: rise .7s ease both;
    }
    @keyframes rise{
        from{ opacity:0; transform:translateY(18px); }
        to{ opacity:1; transform:translateY(0); }
    }

    .brand{ text-align:center; margin-bottom:26px; }
    .brand .logo-badge{
        width:74px; height:74px;
        margin:0 auto 14px;
        border-radius:18px;
        display:flex; align-items:center; justify-content:center;
        background: linear-gradient(145deg, #22335a, #101a30);
        border:1px solid var(--glass-border);
        box-shadow: 0 0 0 1px rgba(201,162,75,.08), 0 12px 28px rgba(0,0,0,.45), inset 0 1px 0 rgba(255,255,255,.06);
    }
    .brand .logo-badge svg{ width:34px; height:34px; }
    .brand h1{
        font-size:22px;
        font-weight:800;
        letter-spacing:.3px;
        background: linear-gradient(90deg, var(--gold-light), var(--gold) 60%, #f3e2b3);
        -webkit-background-clip:text;
        background-clip:text;
        color:transparent;
        margin-bottom:6px;
    }
    .brand p{ color:#8ea0c2; font-size:13px; letter-spacing:.5px; }

    .auth-box{
        background: linear-gradient(180deg, rgba(255,255,255,.055), rgba(255,255,255,.02));
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
        border: 1px solid var(--glass-border);
        border-radius: 20px;
        padding: 38px 34px 32px;
        box-shadow:
            0 25px 60px -12px rgba(0,0,0,.55),
            inset 0 1px 0 rgba(255,255,255,.06);
        position:relative;
    }
    .auth-box::before{
        content:"";
        position:absolute; top:0; right:22px; left:22px; height:1px;
        background: linear-gradient(90deg, transparent, var(--gold), transparent);
        opacity:.6;
    }

    .field{ margin-bottom:20px; }
    .field label{ display:block; font-size:13px; color:#c9d3e6; margin-bottom:8px; font-weight:600; }
    .field .input-wrap{ position:relative; }
    .field .input-wrap svg{
        position:absolute; top:50%; right:14px; transform:translateY(-50%);
        width:18px; height:18px; stroke:#8ea0c2; pointer-events:none; transition:stroke .2s;
    }
    .field input{
        width:100%;
        padding:13px 44px 13px 16px;
        background: rgba(9,15,28,.55);
        border:1px solid rgba(201,162,75,.22);
        border-radius:10px;
        color:#f1f4fa;
        font-size:14.5px;
        font-family:inherit;
        transition: border-color .2s, box-shadow .2s, background .2s;
    }
    .field input::placeholder{ color:#5f6f8f; }
    .field input:focus{
        outline:none; border-color: var(--gold);
        background: rgba(9,15,28,.8);
        box-shadow: 0 0 0 3px rgba(201,162,75,.14);
    }
    .field input:focus ~ svg{ stroke: var(--gold); }

    button.submit-btn{
        width:100%; margin-top:8px; padding:14px; border:none; border-radius:10px;
        font-family:inherit; font-size:15.5px; font-weight:700; letter-spacing:.3px;
        color:#1a1508; cursor:pointer;
        background: linear-gradient(90deg, var(--gold-light), var(--gold));
        box-shadow: 0 10px 24px -6px rgba(201,162,75,.45);
        transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
    }
    button.submit-btn:hover{
        transform: translateY(-1px); filter:brightness(1.06);
        box-shadow: 0 14px 30px -6px rgba(201,162,75,.55);
    }
    button.submit-btn:active{ transform: translateY(0); }

    .alert-error{
        background: rgba(179,38,30,.12);
        border:1px solid rgba(179,38,30,.35);
        color:#ff9d94;
        padding:11px 14px;
        border-radius:10px;
        font-size:13.5px;
        margin-bottom:18px;
        text-align:center;
    }

    .foot-note{ text-align:center; margin-top:22px; color:#5f6f8f; font-size:12px; letter-spacing:.4px; }
    .foot-note span{ color: var(--gold); }

    @media (max-width:420px){ .auth-box{ padding:30px 22px 26px; } }
</style>
</head>
<body>
<div class="grid-lines"></div>

<div class="auth-wrap">
    <div class="brand">
        <div class="logo-badge">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 21V9L12 3L21 9V21H15V14H9V21H3Z" stroke="#e8cf8a" stroke-width="1.4" stroke-linejoin="round"/>
                <path d="M9 21V14H15V21" stroke="#e8cf8a" stroke-width="1.4" stroke-linejoin="round"/>
                <circle cx="12" cy="9.5" r="1.4" fill="#e8cf8a"/>
            </svg>
        </div>
        <h1><?= APP_NAME ?></h1>
        <p>نظام إدارة مالية ومحاسبية متكامل</p>
    </div>

    <div class="auth-box">
        <?php if ($error): ?><div class="alert-error"><?= $error ?></div><?php endif; ?>
        <form method="post">
            <div class="field">
                <label>اسم المستخدم</label>
                <div class="input-wrap">
                    <input type="text" name="username" placeholder="أدخل اسم المستخدم" required autofocus>
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M20 21C20 17.13 16.42 14 12 14C7.58 14 4 17.13 4 21" stroke-width="1.6" stroke-linecap="round"/>
                        <circle cx="12" cy="7" r="4" stroke-width="1.6"/>
                    </svg>
                </div>
            </div>
            <div class="field">
                <label>كلمة السر</label>
                <div class="input-wrap">
                    <input type="password" name="password" placeholder="أدخل كلمة السر" required>
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="5" y="11" width="14" height="9" rx="2" stroke-width="1.6"/>
                        <path d="M8 11V7a4 4 0 0 1 8 0v4" stroke-width="1.6" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>
            <button type="submit" class="submit-btn">دخول</button>
        </form>
    </div>

    <div class="foot-note">جميع الحقوق محفوظة &copy; <?= date('Y') ?> &mdash; <span><?= APP_NAME ?></span></div>
</div>
</body>
</html>
