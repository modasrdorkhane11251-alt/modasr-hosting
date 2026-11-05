<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/storefront_payment.php';
require_once __DIR__ . '/../lib/storefront_core.php';

date_default_timezone_set('Asia/Tehran');

function sfc_page(string $title, string $message, string $orderNo, string $color): void
{
    http_response_code(200);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeOrder = htmlspecialchars($orderNo, ENT_QUOTES, 'UTF-8');
    $link = 'https://shop.modasr.site/order.html?order_no=' . rawurlencode($orderNo);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $safeTitle . '</title><style>body{margin:0;background:#0b1117;color:#f8fafc;font-family:Tahoma,sans-serif;display:grid;place-items:center;min-height:100vh}.box{max-width:520px;margin:20px;padding:32px;border:1px solid #263449;border-radius:22px;background:#16212c;text-align:center;line-height:2}.color{color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '}a{display:inline-block;margin-top:14px;padding:11px 18px;border-radius:12px;background:#35bfae;color:#0b1117;text-decoration:none;font-weight:bold}</style></head><body><div class="box"><h2 class="color">' . $safeTitle . '</h2><p>' . $safeMessage . '</p>' . ($orderNo !== '' ? '<p>شماره سفارش: <bdi dir="ltr">' . $safeOrder . '</bdi></p><a href="' . $link . '">پیگیری سفارش</a>' : '') . '</div></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sfc_page('درخواست نامعتبر', 'روش ارسال درخواست صحیح نیست.', '', '#e05b5b');
}
$orderNo = trim((string) ($_POST['invoice_id'] ?? ''));
$transid = trim((string) ($_POST['transid'] ?? ''));
if (!preg_match('/^SF-[A-F0-9]{8}$/', $orderNo) || !preg_match('/^[A-Za-z0-9_\-]{3,128}$/', $transid)) {
    sfc_page('درخواست نامعتبر', 'شناسه سفارش یا تراکنش معتبر نیست.', '', '#e05b5b');
}
$pin = sf_aqaye_merchant($pdo);
if ($pin === '') {
    sfc_page('پرداخت نامشخص', 'تنظیم درگاه انجام نشده است. با پشتیبانی تماس بگیرید.', $orderNo, '#fbb740');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE type = 'storefront' AND id_payment = ? LIMIT 1");
    $stmt->execute([$orderNo]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("SELECT * FROM storefront_payment WHERE order_id = ? AND provider = 'aqayepardakht' LIMIT 1");
    $stmt->execute([(int) ($order['id'] ?? 0)]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    $eligibility = sf_claim_eligibility($order ?: null, $payment ?: null, $transid);
    if ($eligibility === 'already_paid') {
        sfc_page('پرداخت قبلاً تأیید شده', 'این سفارش قبلاً با موفقیت پرداخت و ثبت شده است.', $orderNo, '#35bfae');
    }
    if ($eligibility !== 'claim') {
        sfc_page('امکان تأیید پرداخت نیست', 'اطلاعات تراکنش با سفارش مطابقت ندارد. با پشتیبانی تماس بگیرید.', $orderNo, '#e05b5b');
    }

    $amount = (int) $payment['amount_toman'];
    $verified = sf_aqaye_verify($pin, $amount, $transid);
    if (!($verified['paid'] ?? false)) {
        $unknown = (bool) ($verified['unknown'] ?? false);
        $stmt = $pdo->prepare("UPDATE storefront_payment SET status = ?, error = ?, updated_at = ? WHERE id = ? AND status <> 'paid'");
        $stmt->execute([$unknown ? 'unknown' : 'failed', $unknown ? 'verify_transport' : 'verify_rejected', time(), (int) $payment['id']]);
        if ($unknown) {
            sfc_page('پرداخت در انتظار بررسی', 'پاسخ قطعی از درگاه دریافت نشد. وضعیت سفارش بعداً دوباره بررسی می‌شود؛ لطفاً سفارش را دوباره پرداخت نکنید.', $orderNo, '#fbb740');
        }
        sfc_page('پرداخت ناموفق', 'تراکنش توسط درگاه تأیید نشد. سفارش شما هنوز پرداخت‌نشده است.', $orderNo, '#e05b5b');
    }

    $claimResult = storefrontClaimPaid($pdo, $order, $payment, $transid);
    if ($claimResult === 'already_paid') {
        sfc_page('پرداخت قبلاً تأیید شده', 'این سفارش قبلاً با موفقیت پرداخت و ثبت شده است.', $orderNo, '#35bfae');
    }
    if ($claimResult !== 'claimed') {
        sfc_page('امکان تأیید پرداخت نیست', 'وضعیت سفارش یا مبلغ آن تغییر کرده است. با پشتیبانی تماس بگیرید.', $orderNo, '#e05b5b');
    }
    $claimed = true;

    $fulfillment = ['status' => 'manual_required'];
    if ($claimed) {
        try {
            $fulfillment = storefrontFulfill($pdo, (int) $order['id']);
        } catch (Throwable $deliveryError) {
            error_log('storefront auto delivery error: ' . $deliveryError->getMessage());
            $fulfillment = ['status' => 'manual_required'];
        }
        global $PLATFORM_TOKENS;
        $token = (string) ($PLATFORM_TOKENS['telegram'] ?? $APIKEY ?? '');
        $delivered = ($fulfillment['status'] ?? '') === 'delivered';
        $message = $delivered
            ? "📦 <b>سفارش شما با موفقیت تحویل شد</b>\n\nشماره سفارش: <code>{$orderNo}</code>\n\n" . storefrontDeliveryText((string) $fulfillment['output'])
            : "✅ <b>پرداخت سفارش شما تأیید شد</b>\n\nشماره سفارش: <code>{$orderNo}</code>\nمبلغ: <b>" . number_format($amount) . " تومان</b>\n\nسفارش شما در صف تحویل قرار گرفت. نتیجه از همین ربات اطلاع‌رسانی می‌شود.";
        sf_telegram_send($token, (string) $order['id_user'], $message);
        $setting = $pdo->query("SELECT Channel_Report FROM setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $topic = $pdo->query("SELECT idreport FROM topicid WHERE report = 'paymentreport' LIMIT 1")->fetchColumn();
        if (!empty($setting['Channel_Report'])) {
            sf_telegram_send($token, (string) $setting['Channel_Report'], "💳 پرداخت وب‌سایت تأیید شد\nسفارش: {$orderNo}\nمبلغ: " . number_format($amount) . " تومان\nوضعیت: " . ($delivered ? 'تحویل خودکار انجام شد' : 'در انتظار تحویل'), (int) $topic ?: null);
        }
    }
    $deliveredNow = ($fulfillment['status'] ?? '') === 'delivered';
    sfc_page($deliveredNow ? 'سفارش شما تحویل شد' : 'پرداخت با موفقیت انجام شد', $deliveredNow ? 'جزئیات تحویل در صفحه پیگیری و ربات برای شما ارسال شد.' : 'سفارش شما در صف تحویل قرار گرفت. شماره سفارش را نگه دارید.', $orderNo, '#35bfae');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('storefront payment callback error: ' . $e->getMessage());
    sfc_page('خطای بررسی پرداخت', 'بررسی تراکنش با خطا مواجه شد. در صورت کسر مبلغ، با شماره سفارش با پشتیبانی تماس بگیرید.', $orderNo, '#fbb740');
}
