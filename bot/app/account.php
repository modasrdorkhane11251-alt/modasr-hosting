<?php
/**
 * MODASR — Premium account page (mini-app account tab redesign).
 * Resolves the account from ?key=... (persistent key) or ?auth_token=...
 * (one-time magic link), then renders a glassmorphism profile + stats page.
 */

$authToken = isset($_GET['auth_token']) && is_string($_GET['auth_token']) ? trim($_GET['auth_token']) : '';
$persistentKey = isset($_GET['key']) && is_string($_GET['key']) ? trim($_GET['key']) : '';

$user = null;
$entryPlatform = 'web';
if ($authToken !== '' || $persistentKey !== '') {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../function.php';

    if ($authToken !== '') {
        $auth = miniapp_consume_auth_token($authToken);
        if ($auth !== null) {
            $user = select('user', '*', 'id', (string) $auth['user_id'], 'select');
            $entryPlatform = (string) $auth['platform'];
        }
    } else {
        $keyUser = miniapp_resolve_key($persistentKey);
        if ($keyUser) {
            $user = $keyUser;
            $entryPlatform = (string) ($keyUser['platform'] ?? 'web');
        }
    }
}

if (!$user) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مدثر</title><style>body{margin:0;background:#0b1117;color:#e8eef4;font-family:Tahoma;display:flex;align-items:center;justify-content:center;min-height:100vh}.c{text-align:center;padding:30px}a{color:#35bfae}</style></head><body><div class="c"><h2>برای دیدن حساب کاربری وارد شوید</h2><p>از داخل ربات، بخش حساب را باز کنید تا لینک اختصاصی شما ساخته شود.</p></div></body></html>';
    exit;
}

$primaryId = modasr_primary_user_id((string) $user['id']);
$primary = select('user', '*', 'id', $primaryId, 'select') ?: $user;
$userId = (string) $primary['id'];

// ---------- account stats ----------
$balance = (int) ($primary['Balance'] ?? 0);
$displayName = miniapp_display_name($primary);
$username = (string) ($primary['username'] ?? '');
$phone = (string) ($primary['number'] ?? 'none');
$register = (string) ($primary['register'] ?? '');
$affiliatesCount = (int) ($primary['affiliatescount'] ?? 0);
$inviteCode = (string) ($primary['codeInvitation'] ?? '');
$agent = (string) ($primary['agent'] ?? 'f');
$agentLabel = $agent === 'f' ? 'عادی' : 'نماینده';

$servicesCount = 0;
$invoicesCount = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE id_user = :id AND status = 'active'");
    $s->execute([':id' => $userId]);
    $servicesCount = (int) $s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE id_user = :id");
    $s->execute([':id' => $userId]);
    $invoicesCount = (int) $s->fetchColumn();
} catch (Throwable $e) {
    // keep zeros on error
}

$links = [];
try {
    $overview = accountLinkList($userId);
    $links = $overview['links'] ?? [];
} catch (Throwable $e) {
    $links = [];
}
$platformLabels = ['telegram' => '📱 تلگرام', 'bale' => '🟡 بله', 'rubika' => '🟢 روبیکا', 'splus' => '🔵 سروش'];

// ---------- Telegram profile photo (cached) ----------
$photoUrl = null;
try {
    global $APIKEY;
    $botToken = isset($APIKEY) ? (string) $APIKEY : '';
    if ($botToken !== '' && ctype_digit($userId)) {
        $cacheDir = __DIR__ . '/assets/avatars';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $cacheFile = $cacheDir . '/' . $userId . '.jpg';
        if (is_file($cacheFile) && time() - filemtime($cacheFile) < 7 * 86400) {
            $photoUrl = 'assets/avatars/' . $userId . '.jpg';
        } else {
            $resp = @file_get_contents("https://api.telegram.org/bot{$botToken}/getUserProfilePhotos?user_id={$userId}&limit=1", false, stream_context_create(['http' => ['timeout' => 8]]));
            $j = json_decode((string) $resp, true);
            $fileId = $j['result']['photos'][0][0]['file_id'] ?? null;
            if ($fileId) {
                $fr = @file_get_contents("https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}", false, stream_context_create(['http' => ['timeout' => 8]]));
                $fj = json_decode((string) $fr, true);
                $path = $fj['result']['file_path'] ?? null;
                if ($path) {
                    $img = @file_get_contents("https://api.telegram.org/file/bot{$botToken}/{$path}", false, stream_context_create(['http' => ['timeout' => 10]]));
                    if ($img) {
                        @file_put_contents($cacheFile, $img);
                        $photoUrl = 'assets/avatars/' . $userId . '.jpg';
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    $photoUrl = null;
}

$initial = $displayName !== '' ? mb_substr($displayName, 0, 1) : 'م';
$balanceFmt = number_format($balance);
$miniappHome = 'index.php' . ($persistentKey !== '' ? ('?key=' . urlencode($persistentKey)) : '');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>حساب کاربری | مدثر</title>
<link rel="stylesheet" href="fonts/Vazirmatn/Vazirmatn.css">
<style>
:root{
  --bg:#070d13; --card:rgba(18,28,38,.72); --stroke:rgba(53,191,174,.18);
  --teal:#35bfae; --violet:#7c5cff; --text:#e8eef4; --muted:#8fa3b3;
}
*{margin:0;padding:0;box-sizing:border-box;font-family:Vazirmatn,Tahoma,sans-serif}
body{background:var(--bg);color:var(--text);min-height:100vh;padding:18px 14px 40px;position:relative;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background:
  radial-gradient(600px 320px at 85% -5%,rgba(53,191,174,.14),transparent 60%),
  radial-gradient(520px 300px at 0% 20%,rgba(124,92,255,.12),transparent 60%);pointer-events:none;z-index:-1}
.wrap{max-width:520px;margin:0 auto}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
.brand{font-weight:900;font-size:19px;letter-spacing:1px}
.brand span{color:var(--teal)}
.back{color:var(--muted);text-decoration:none;font-size:12px;border:1px solid var(--stroke);padding:7px 12px;border-radius:10px;background:rgba(255,255,255,.03)}
.hero{position:relative;border-radius:26px;padding:26px 20px 22px;background:var(--card);border:1px solid var(--stroke);backdrop-filter:blur(18px);box-shadow:0 24px 60px rgba(0,0,0,.45);text-align:center;overflow:hidden}
.hero::before{content:'';position:absolute;inset:0 0 auto;height:1px;background:linear-gradient(90deg,transparent,rgba(53,191,174,.6),transparent)}
.avatar{width:104px;height:104px;border-radius:50%;margin:0 auto 14px;position:relative;padding:3px;background:linear-gradient(140deg,var(--teal),var(--violet))}
.avatar .in{width:100%;height:100%;border-radius:50%;background:#0d1a24;display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:900;color:var(--teal);overflow:hidden}
.avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.avatar::after{content:'';position:absolute;inset:-7px;border-radius:50%;border:1px solid rgba(53,191,174,.35);animation:pulse 3s infinite}
@keyframes pulse{0%{transform:scale(.95);opacity:.9}70%{transform:scale(1.12);opacity:0}100%{opacity:0}}
.name{font-size:21px;font-weight:900;margin-bottom:4px}
.username{color:var(--muted);font-size:12px;direction:ltr}
.chips{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:14px}
.chip{font-size:11px;padding:6px 12px;border-radius:999px;border:1px solid var(--stroke);background:rgba(53,191,174,.08);color:#9fe6da}
.chip.violet{background:rgba(124,92,255,.12);color:#c9b8ff;border-color:rgba(124,92,255,.3)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px}
.stat{position:relative;border-radius:18px;padding:16px 14px;background:var(--card);border:1px solid var(--stroke);backdrop-filter:blur(14px);overflow:hidden}
.stat .ico{position:absolute;left:12px;top:12px;width:34px;height:34px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px}
.stat .val{font-size:19px;font-weight:900;margin-top:26px}
.stat .lbl{font-size:11px;color:var(--muted);margin-top:4px}
.i-teal{background:rgba(53,191,174,.16);color:var(--teal)}
.i-violet{background:rgba(124,92,255,.18);color:#b9a3ff}
.i-amber{background:rgba(251,191,36,.16);color:#fbbf24}
.i-pink{background:rgba(244,114,182,.16);color:#f472b6}
.panel{margin-top:18px;border-radius:20px;padding:16px;background:var(--card);border:1px solid var(--stroke);backdrop-filter:blur(14px)}
.panel h3{font-size:13px;color:#bcd2df;margin-bottom:12px;font-weight:700}
.row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px dashed rgba(255,255,255,.06);font-size:12.5px}
.row:last-child{border-bottom:none}
.row .k{color:var(--muted);display:flex;align-items:center;gap:7px}
.row .v{font-weight:700;direction:ltr}
.copy{cursor:pointer;border:1px solid var(--stroke);background:rgba(255,255,255,.04);color:#9fe6da;font-size:10px;padding:4px 9px;border-radius:8px;margin-inline-start:8px}
.copy:active{transform:scale(.94)}
.linkrow{display:flex;gap:8px;flex-wrap:wrap}
.ltag{font-size:11px;padding:6px 11px;border-radius:10px;border:1px solid var(--stroke);background:rgba(255,255,255,.04)}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div class="brand">MODASR<span> · حساب من</span></div>
    <a class="back" href="<?= htmlspecialchars($miniappHome) ?>">بازگشت به فروشگاه</a>
  </div>

  <div class="hero">
    <div class="avatar"><div class="in">
      <?php if ($photoUrl): ?>
        <img src="<?= htmlspecialchars($photoUrl) ?>" alt="">
      <?php else: ?>
        <?= htmlspecialchars($initial) ?>
      <?php endif; ?>
    </div></div>
    <div class="name"><?= htmlspecialchars($displayName !== '' ? $displayName : 'کاربر مدثر') ?></div>
    <div class="username"><?= $username !== '' ? '@' . htmlspecialchars($username) : 'MODASR MEMBER' ?></div>
    <div class="chips">
      <span class="chip">گروه: <?= htmlspecialchars($agentLabel) ?></span>
      <span class="chip violet">شناسه: <?= htmlspecialchars($userId) ?></span>
      <?php if ($register): ?><span class="chip">عضویت: <?= htmlspecialchars($register) ?></span><?php endif; ?>
    </div>
  </div>

  <div class="grid">
    <div class="stat">
      <div class="ico i-teal">💳</div>
      <div class="val"><?= $balanceFmt ?></div>
      <div class="lbl">موجودی کیف پول (تومان)</div>
    </div>
    <div class="stat">
      <div class="ico i-violet">🛍</div>
      <div class="val"><?= $servicesCount ?></div>
      <div class="lbl">سرویس‌های فعال</div>
    </div>
    <div class="stat">
      <div class="ico i-amber">🧾</div>
      <div class="val"><?= $invoicesCount ?></div>
      <div class="lbl">فاکتورهای ثبت‌شده</div>
    </div>
    <div class="stat">
      <div class="ico i-pink">👥</div>
      <div class="val"><?= number_format($affiliatesCount) ?></div>
      <div class="lbl">زیرمجموعه‌ها</div>
    </div>
  </div>

  <div class="panel">
    <h3>اطلاعات حساب</h3>
    <div class="row"><span class="k">🆔 شناسه کاربری</span><span class="v"><?= htmlspecialchars($userId) ?><button class="copy" onclick="copyTxt('<?= htmlspecialchars($userId) ?>')">کپی</button></span></div>
    <?php if ($inviteCode): ?>
    <div class="row"><span class="k">🎁 کد معرف</span><span class="v"><?= htmlspecialchars($inviteCode) ?><button class="copy" onclick="copyTxt('<?= htmlspecialchars($inviteCode) ?>')">کپی</button></span></div>
    <?php endif; ?>
    <div class="row"><span class="k">📞 شماره تماس</span><span class="v"><?= $phone !== 'none' && $phone !== '' ? htmlspecialchars($phone) : 'ثبت نشده' ?></span></div>
    <?php if ($register): ?>
    <div class="row"><span class="k">📅 زمان ثبت‌نام</span><span class="v"><?= htmlspecialchars($register) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if (count($links) > 0): ?>
  <div class="panel">
    <h3>اکانت‌های متصل</h3>
    <div class="linkrow">
      <?php foreach ($links as $lnk): ?>
        <span class="ltag"><?= htmlspecialchars($platformLabels[$lnk['platform']] ?? $lnk['platform']) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function copyTxt(t){
  const done = (el) => { el.textContent = 'کپی شد ✓'; setTimeout(() => el.textContent = 'کپی', 1600); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(t).then(() => done(event.target)).catch(() => fallback());
  } else fallback();
  function fallback(){
    const ta = document.createElement('textarea');
    ta.value = t; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(event.target); } catch(e){}
    document.body.removeChild(ta);
  }
}
</script>
</body>
</html>
