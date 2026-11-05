<?php
/**
 * MODASR Storefront Auth (phone + OTP via bot)
 * -------------------------------------------
 * request: sends a 6-digit login code to the user's Telegram via the bot.
 * verify:  validates the code and returns the linked bot account.
 *
 * Security: HMAC-SHA256 signature (same scheme as storefront_order),
 * OTP expiry 5 min, max 5 verify attempts, per-phone/IP rate limits,
 * OTP stored hashed (sha256) in a short-lived file, never in plaintext.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Storefront-Timestamp, X-Storefront-Signature');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Asia/Tehran');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const SF_AUTH_MAX_SKEW = 300;
const SF_AUTH_CODE_TTL = 300;      // 5 minutes
const SF_AUTH_MAX_ATTEMPTS = 5;
const SF_AUTH_REQ_COOLDOWN = 60;   // 1 code per minute per phone

function sfa_respond(bool $ok, string $msg, array $obj = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['status' => $ok, 'msg' => $msg, 'obj' => $obj], JSON_UNESCAPED_UNICODE);
    exit;
}

function sfa_secret(): string
{
    $file = __DIR__ . '/storefront_secret.php';
    if (!is_file($file)) return '';
    $secret = include $file;
    return is_string($secret) ? trim($secret) : '';
}

function sfa_verify(string $secret, string $ts, string $raw, string $sig): bool
{
    if (!preg_match('/^\d{9,12}$/', $ts) || abs(time() - (int)$ts) > SF_AUTH_MAX_SKEW) return false;
    return hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secret), $sig);
}

function sfa_otp_file(string $phone): string
{
    return sys_get_temp_dir() . '/modasr_otp_' . hash('sha256', $phone) . '.json';
}

function sfa_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    if (str_starts_with($digits, '0098')) $digits = substr($digits, 4);
    elseif (str_starts_with($digits, '98') && strlen($digits) > 10) $digits = substr($digits, 2);
    return $digits;
}

function sfa_telegram(string $token, string $chatId, string $text): void
{
    if ($token === '' || !ctype_digit($chatId)) return;
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']),
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function sfa_bale(string $token, string $chatId, string $text): void
{
    if ($token === '' || $chatId === '') return;
    $ch = curl_init("https://tapi.bale.ai/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']),
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sfa_respond(false, 'method invalid', [], 405);
}
$secret = sfa_secret();
if ($secret === '') sfa_respond(false, 'not configured', [], 503);

$raw = (string)file_get_contents('php://input');
$ts = (string)($_SERVER['HTTP_X_STOREFRONT_TIMESTAMP'] ?? '');
$sig = (string)($_SERVER['HTTP_X_STOREFRONT_SIGNATURE'] ?? '');
$signed = sfa_verify($secret, $ts, $raw, $sig);

$data = json_decode($raw, true);
if (!is_array($data)) sfa_respond(false, 'invalid json', [], 400);
$action = $data['action'] ?? '';

// Signed server-to-server calls allow every action. Unsigned browser calls
// are limited to OTP request/verify and Telegram-widget verification, and
// are hard rate-limited per IP to prevent abuse.
if (!$signed) {
    $allowedUnsigned = ['request', 'verify', 'widget_verify'];
    if (!in_array($action, $allowedUnsigned, true)) {
        sfa_respond(false, 'signature invalid', [], 403);
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rlFile = sys_get_temp_dir() . '/modasr_auth_rl_' . hash('sha256', $ip) . '.json';
    $hits = is_file($rlFile) ? (json_decode((string)file_get_contents($rlFile), true) ?: []) : [];
    $now = time();
    $hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $now - 60));
    if (count($hits) >= 10) {
        sfa_respond(false, 'rate_limited', [], 429);
    }
    $hits[] = $now;
    @file_put_contents($rlFile, json_encode($hits), LOCK_EX);
}
$phone = sfa_normalize_phone((string)($data['phone'] ?? ''));
$telegramId = trim((string)($data['telegram_id'] ?? ''));
if ($phone === '' && $telegramId === '') sfa_respond(false, 'identifier required', [], 422);
if ($phone !== '' && !preg_match('/^09\d{9}$/', $phone)) sfa_respond(false, 'phone invalid', [], 422);
if ($telegramId !== '' && !ctype_digit($telegramId)) sfa_respond(false, 'telegram_id invalid', [], 422);
$identity = $telegramId !== '' ? $telegramId : $phone;

try {
    // find the bot account by telegram id or phone number
    if ($telegramId !== '') {
        $stmt = $pdo->prepare("SELECT id, username, number, Balance, User_Status FROM user WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $telegramId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, username, number, Balance, User_Status FROM user WHERE number = :num LIMIT 1");
        $stmt->execute([':num' => $phone]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user && $action !== 'widget_login') {
        sfa_respond(false, 'account_not_found', [], 404);
    }
    if ($user && ($user['User_Status'] ?? 'Active') !== 'Active') {
        sfa_respond(false, 'user blocked', [], 403);
    }

    if ($action === 'request') {
        $file = sfa_otp_file($identity);
        if (is_file($file)) {
            $prev = json_decode((string)file_get_contents($file), true) ?: [];
            if (!empty($prev['requested_at']) && time() - $prev['requested_at'] < SF_AUTH_REQ_COOLDOWN) {
                sfa_respond(false, 'code_recently_sent', ['wait' => SF_AUTH_REQ_COOLDOWN - (time() - $prev['requested_at'])], 429);
            }
        }
        $code = (string)random_int(100000, 999999);
        $payload = [
            'hash' => hash('sha256', $code),
            'requested_at' => time(),
            'expires_at' => time() + SF_AUTH_CODE_TTL,
            'attempts' => 0,
        ];
        file_put_contents($file, json_encode($payload), LOCK_EX);

        global $APIKEY, $PLATFORM_TOKENS;
        $botToken = (string)($PLATFORM_TOKENS['telegram'] ?? '');
        $msg = "🔐 <b>کد ورود به وب‌سایت مدثر</b>\n\nکد شما: <code>{$code}</code>\n\nاین کد تا ۵ دقیقه معتبر است. آن را در صفحه ورود سایت وارد کنید.\nاگر شما درخواست نداده‌اید، این پیام را نادیده بگیرید.";
        $platform = (string)($data['platform'] ?? 'telegram');
        if ($platform === 'bale') {
            $baleToken = (string)($PLATFORM_TOKENS['bale'] ?? '');
            sfa_bale($baleToken !== '' ? $baleToken : $botToken, (string)$user['id'], $msg);
        } else {
            sfa_telegram($botToken, (string)$user['id'], $msg);
        }

        sfa_respond(true, 'code_sent', ['expires_in' => SF_AUTH_CODE_TTL]);
    }

    if ($action === 'verify') {
        $code = trim((string)($data['code'] ?? ''));
        $file = sfa_otp_file($identity);
        if (!is_file($file)) sfa_respond(false, 'code_not_requested', [], 404);
        $payload = json_decode((string)file_get_contents($file), true) ?: [];

        if (($payload['expires_at'] ?? 0) < time()) {
            @unlink($file);
            sfa_respond(false, 'code_expired', [], 410);
        }
        if (($payload['attempts'] ?? 0) >= SF_AUTH_MAX_ATTEMPTS) {
            @unlink($file);
            sfa_respond(false, 'too_many_attempts', [], 429);
        }
        if (!hash_equals((string)$payload['hash'], hash('sha256', $code))) {
            $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
            file_put_contents($file, json_encode($payload), LOCK_EX);
            sfa_respond(false, 'code_wrong', ['attempts_left' => SF_AUTH_MAX_ATTEMPTS - $payload['attempts']], 403);
        }

        @unlink($file);
        sfa_respond(true, 'verified', [
            'user' => [
                'telegram_id' => (string)$user['id'],
                'username' => (string)$user['username'],
                'balance' => (int)$user['Balance'],
            ],
        ]);
    }

    if ($action === 'widget_verify') {
        // Telegram Login Widget data posted directly by the browser.
        // Verify Telegram's HMAC with the bot token here on the server.
        $w = $data['widget'] ?? null;
        if (!is_array($w) || empty($w['id']) || empty($w['hash']) || empty($w['auth_date'])) {
            sfa_respond(false, 'invalid_payload', [], 422);
        }
        global $PLATFORM_TOKENS;
        $botToken = (string)($PLATFORM_TOKENS['telegram'] ?? '');
        $secretKey = hash('sha256', $botToken, true);
        $fields = $w;
        $providedHash = (string)$fields['hash'];
        unset($fields['hash']);
        ksort($fields);
        $pairs = [];
        foreach ($fields as $k => $v) { $pairs[] = $k . '=' . $v; }
        $expected = hash_hmac('sha256', implode("\n", $pairs), $secretKey);
        if (!hash_equals($expected, $providedHash)) {
            sfa_respond(false, 'hash_invalid', [], 403);
        }
        if (time() - (int)$w['auth_date'] > 86400) {
            sfa_respond(false, 'auth_expired', [], 403);
        }
        $telegramId = (string)$w['id'];
        $data['username'] = (string)($w['username'] ?? $w['first_name'] ?? '');
        $action = 'widget_login';
    }

    if ($action === 'widget_login') {
        // Telegram Login Widget: user identity is already verified by Telegram
        // (HMAC checked on the site server). Auto-register if the account is new.
        $stmt = $pdo->prepare("SELECT id FROM user WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $telegramId]);
        if (!$stmt->fetch()) {
            $username = trim((string)($data['username'] ?? ''));
            $newCode = bin2hex(random_bytes(4));
            $ins = $pdo->prepare("INSERT IGNORE INTO user (id, platform, step, limit_usertest, User_Status, number, Balance, pagenumber, username, agent, message_count, last_message_time, affiliates, affiliatescount, cardpayment, number_username, namecustom, register, verify, codeInvitation, pricediscount, maxbuyagent, joinchannel, score, status_cron) VALUES (:id, 'telegram', 'none', 0, 'Active', 'none', 0, 1, :username, 'f', 0, 0, 0, 0, 0, 100, 'none', :reg, 0, :code, 0, 0, 0, 0, 1)");
            $ins->execute([
                ':id' => $telegramId,
                ':username' => $username,
                ':reg' => date('Y-m-d H:i:s'),
                ':code' => $newCode,
            ]);
            $stmt = $pdo->prepare("SELECT id, username, number, Balance, User_Status FROM user WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $telegramId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $user = ['id' => $telegramId, 'username' => '', 'Balance' => 0, 'User_Status' => 'Active'];
            $full = $pdo->prepare("SELECT id, username, number, Balance, User_Status FROM user WHERE id = :id LIMIT 1");
            $full->execute([':id' => $telegramId]);
            $user = $full->fetch(PDO::FETCH_ASSOC);
        }
        sfa_respond(true, 'verified', [
            'user' => [
                'telegram_id' => (string)$user['id'],
                'username' => (string)($user['username'] ?? ''),
                'balance' => (int)($user['Balance'] ?? 0),
            ],
        ]);
    }

    sfa_respond(false, 'action invalid', [], 400);
} catch (Throwable $e) {
    error_log('storefront_auth error: ' . $e->getMessage());
    sfa_respond(false, 'server error', [], 500);
}
