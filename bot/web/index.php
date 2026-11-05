<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$token = (string) ($_GET['token'] ?? '');
if ($token !== '') {
    try {
        $magic = webConsumeMagicToken(webPdo(), $token);
        webStartSession(webPdo(), $magic);
        header('Location: index.php');
        exit;
    } catch (Throwable) {
        $error = 'web_magic_invalid';
    }
}

$session = null;
$tenant = null;
$actorId = '';
try {
    $session = webContext(webPdo());
    $tenant = webTenantInfo($session);
    $actorId = webActorId($session);
} catch (Throwable) {
    http_response_code(401);
}

$page = (string) ($_GET['page'] ?? 'dashboard');
$pages = ['dashboard', 'users', 'products', 'orders', 'payments', 'panels', 'instances', 'support', 'settings', 'backups', 'audit'];
if (!in_array($page, $pages, true)) {
    $page = 'dashboard';
}
$titles = [
    'dashboard' => 'داشبورد عملیات',
    'users' => 'کاربران ربات',
    'products' => 'محصولات و قیمت‌ها',
    'orders' => 'سفارش‌ها',
    'payments' => 'پرداخت‌ها',
    'panels' => 'پنل‌های VPN',
    'instances' => 'Bot Instances',
    'support' => 'پشتیبانی',
    'settings' => 'تنظیمات امن',
    'backups' => 'Backup و بازیابی',
    'audit' => 'Audit Log',
];
$csrf = $session['csrf_token'] ?? '';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="<?= webEsc($csrf) ?>">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23050810'/%3E%3Cpath d='M8 22V10l8-4 8 4v12l-8 4z' fill='%2322d3ee'/%3E%3C/svg%3E">
    <title><?= webEsc($titles[$page]) ?> — MODASR Hosting</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body data-page="<?= webEsc($page) ?>">
<a class="skip-link" href="#main">رفتن به محتوای اصلی</a>
<div class="shell">
    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="باز و بسته کردن منو" aria-expanded="false" aria-controls="sidebar">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <aside class="sidebar" id="sidebar" aria-label="ناوبری اصلی">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M5 25V7l11-4 11 4v18l-11 4z"/><path d="M10 21V11l6-2 6 2v10l-6 2z"/></svg></span>
            <span><strong>MODASR</strong><small>Hosting Console</small></span>
        </div>
        <nav class="nav">
            <?php foreach ($titles as $key => $title): ?>
                <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="?page=<?= webEsc($key) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>><?= webEsc($title) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-meta">
            <span class="status-dot stopped"></span>
            <span>Secure Tenant Session</span>
        </div>
    </aside>
    <div class="content-wrap">
        <header class="topbar">
            <div>
                <p class="eyebrow">Tenant Operations</p>
                <h1><?= webEsc($titles[$page]) ?></h1>
            </div>
            <div class="account">
                <?php if ($session): ?>
                    <span class="role-pill"><?= $session['role'] === 'PLATFORM_OWNER' ? 'PLATFORM OWNER' : 'TENANT OWNER' ?></span>
                    <span class="actor" dir="ltr">#<?= webEsc($actorId) ?></span>
                    <form method="post" action="api/index.php?action=auth.logout" class="logout-form" data-action="logout">
                        <input type="hidden" name="csrf_token" value="<?= webEsc($csrf) ?>">
                        <button class="button button-ghost" type="submit">خروج امن</button>
                    </form>
                <?php endif; ?>
            </div>
        </header>
        <main id="main" tabindex="-1">
            <?php if (!$session): ?>
                <section class="auth-card" aria-labelledby="auth-title">
                    <span class="auth-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>
                    <h2 id="auth-title">ورود به پنل مدیریت وب</h2>
                    <p>برای ورود امن، لینک یک‌بارمصرف را از ربات MODASR Hosting دریافت کنید. لینک فقط ۱۰ دقیقه و یک‌بار قابل استفاده است.</p>
                    <ol>
                        <li>به ربات Hosting بروید و دستور <code>/webpanel</code> را بفرستید.</li>
                        <li>روی دکمه «پنل مدیریت وب» بزنید.</li>
                        <li>Session شما با CSRF و انقضای زمانی ساخته می‌شود و به هیچ مشتری دیگری دسترسی ندارید.</li>
                    </ol>
                    <?php if (!empty($error)): ?>
                        <div class="alert danger" role="alert">لینک نامعتبر، منقضی یا قبلاً استفاده شده است. لینک جدید بگیرید.</div>
                    <?php else: ?>
                        <div class="alert info" role="status">برای ورود، Magic Link معتبر لازم است.</div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <div id="app" aria-live="polite">
                    <div class="loading-block" role="status">
                        <span class="spinner" aria-hidden="true"></span>
                        <span>در حال بارگذاری اطلاعات واقعی Tenant…</span>
                    </div>
                </div>
                <noscript><div class="alert info">برای مشاهده جدول‌ها و عملیات پنل، JavaScript را فعال کنید.</div></noscript>
            <?php endif; ?>
        </main>
    </div>
</div>
<div class="toast-region" id="toastRegion" aria-live="assertive" aria-atomic="true"></div>
<div class="modal-backdrop" id="modalBackdrop" hidden>
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" tabindex="-1">
        <button class="modal-close" type="button" aria-label="بستن" data-close-modal>×</button>
        <h2 id="modalTitle"></h2>
        <div id="modalBody"></div>
    </section>
</div>
<script src="assets/app.js" defer></script>
</body>
</html>
