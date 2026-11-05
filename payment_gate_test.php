<?php

declare(strict_types=1);
require __DIR__ . '/bot/api/factory_payment.php';
require __DIR__ . '/bot/api/factory_workflow.php';
require __DIR__ . '/bot/api/factory_catalog.php';

$passed = 0;
$failed = 0;
$cases = [
    'paid positive order allowed' => [null, ['owner_id' => 'customer-1', 'amount_toman' => 300000, 'status' => 'paid'], 'customer-1'],
    'awaiting payment blocked' => ['payment_required', ['owner_id' => 'customer-1', 'amount_toman' => 300000, 'status' => 'awaiting_payment'], 'customer-1'],
    'legacy active_test blocked' => ['payment_required', ['owner_id' => 'customer-1', 'amount_toman' => 300000, 'status' => 'active_test'], 'customer-1'],
    'provisioning is not payment proof' => ['payment_required', ['owner_id' => 'customer-1', 'amount_toman' => 300000, 'status' => 'provisioning'], 'customer-1'],
    'active is not payment proof for direct provisioning' => ['payment_required', ['owner_id' => 'customer-1', 'amount_toman' => 300000, 'status' => 'active'], 'customer-1'],
    'stopped order blocked' => ['payment_required', ['owner_id' => 'customer-1', 'amount_toman' => 300000, 'status' => 'stopped'], 'customer-1'],
    'cross tenant blocked' => ['order_forbidden', ['owner_id' => 'customer-2', 'amount_toman' => 300000, 'status' => 'paid'], 'customer-1'],
    'empty owner blocked' => ['order_forbidden', ['owner_id' => 'customer-1', 'amount_toman' => 300000, 'status' => 'paid'], ''],
    'negative amount blocked' => ['order_invalid', ['owner_id' => 'customer-1', 'amount_toman' => -1, 'status' => 'paid'], 'customer-1'],
    'non numeric amount blocked' => ['order_invalid', ['owner_id' => 'customer-1', 'amount_toman' => 'not-a-number', 'status' => 'paid'], 'customer-1'],
    'missing fields blocked' => ['order_invalid', ['status' => 'paid'], 'customer-1'],
    'explicit free order allowed' => [null, ['owner_id' => 'customer-1', 'amount_toman' => 0, 'status' => 'free'], 'customer-1'],
    'zero paid status is not a free grant' => ['payment_required', ['owner_id' => 'customer-1', 'amount_toman' => 0, 'status' => 'paid'], 'customer-1'],
    'positive free status blocked' => ['payment_required', ['owner_id' => 'customer-1', 'amount_toman' => 300000, 'status' => 'free'], 'customer-1'],
];

foreach ($cases as $label => [$expected, $order, $ownerId]) {
    $actual = factoryOrderProvisionError($order, $ownerId);
    if ($actual === $expected) {
        ++$passed;
        echo "PASS: {$label}\n";
    } else {
        ++$failed;
        echo "FAIL: {$label}; expected=" . var_export($expected, true) . ' actual=' . var_export($actual, true) . "\n";
    }
}

$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        echo "PASS: {$label}\n";
    } else {
        ++$failed;
        echo "FAIL: {$label}\n";
    }
};

$check(!factoryCheckoutOrderReusable(['status' => 'free', 'amount_toman' => 0], 300000), 'legacy free order is not reusable at paid price');
$check(factoryCheckoutOrderReusable(['status' => 'free', 'amount_toman' => 0], 0), 'free order reusable only at zero price');
$check(factoryCheckoutOrderReusable(['status' => 'paid', 'amount_toman' => 200000], 300000), 'paid order keeps its authoritative paid amount');
$check(factoryCheckoutOrderReusable(['status' => 'active', 'amount_toman' => 300000], 300000), 'active order reusable for idempotent completion');
$check(factoryCheckoutOrderReusable(['status' => 'awaiting_payment', 'amount_toman' => 300000], 300000), 'awaiting order reusable at same price');
$check(!factoryCheckoutOrderReusable(['status' => 'awaiting_payment', 'amount_toman' => 200000], 300000), 'awaiting order not reusable after price change');
$check(factoryCheckoutOrderReusable(['status' => 'payment_rejected', 'amount_toman' => 300000], 300000), 'rejected order reusable for receipt resubmission');
$check(!factoryCheckoutOrderReusable(['status' => 'cancelled', 'amount_toman' => 300000], 300000), 'cancelled order cannot be reused');
$check(!factoryCheckoutOrderReusable(['status' => 'paid', 'amount_toman' => 'invalid'], 300000), 'invalid stored amount rejected');

$check(factoryPaymentTransition('awaiting_payment', 'submit_receipt') === 'awaiting_payment', 'receipt accepted while awaiting');
$check(factoryPaymentTransition('payment_rejected', 'submit_receipt') === 'awaiting_payment', 'rejected receipt can be resubmitted');
$check(factoryPaymentTransition('paid', 'submit_receipt') === null, 'paid order rejects new receipt');
$check(factoryPaymentTransition('awaiting_payment', 'approve') === 'paid', 'awaiting order can be approved');
$check(factoryPaymentTransition('payment_rejected', 'approve') === null, 'rejected receipt cannot bypass resubmission');
$check(factoryPaymentTransition('awaiting_payment', 'reject') === 'payment_rejected', 'awaiting receipt can be rejected');
$check(factoryPaymentTransition('paid', 'reject') === null, 'paid order cannot be rejected as a receipt');
$check(factoryPaymentTransition('awaiting_payment', 'cancel') === 'cancelled', 'unpaid order can be cancelled');
$check(factoryPaymentTransition('paid', 'cancel') === null, 'paid order cannot be cancelled through unpaid flow');
$check(factoryOrderStatusLabel('payment_rejected') !== 'وضعیت نامشخص', 'rejected status has a customer label');

foreach (['welcome', 'report', 'followup'] as $template) {
    $draft = factoryWorkflowTemplate($template, 'TESTONLY_' . $template);
    [$valid, $errors] = factoryWorkflowValidate($draft);
    $check($valid, 'template validates: ' . $template . ($valid ? '' : ' — ' . implode(',', $errors)));
    $sideEffectFree = true;
    foreach ($draft['workflow']['nodes'] as $node) {
        $config = $node['config'] ?? [];
        if (!empty($config['send_now']) || in_array((string) ($config['operation'] ?? ''), ['stop_bot', 'start_bot', 'send_message'], true)) {
            $sideEffectFree = false;
        }
    }
    $check($sideEffectFree, 'template has no default external side effect: ' . $template);
}

$unknownTemplateBlocked = false;
try {
    factoryWorkflowTemplate('unknown', 'TESTONLY_unknown');
} catch (RuntimeException $e) {
    $unknownTemplateBlocked = $e->getMessage() === 'template_not_found';
}
$check($unknownTemplateBlocked, 'unknown workflow template blocked');

$check(factoryPlan('vpn')['code'] === 'v:t', 'legacy VPN plan maps to Telegram');
$check(count(factoryCatalogPlatforms()) === 4, 'all four platforms are defined');
$check(count(factoryCatalogPackages()) === 15, 'all fifteen platform packages are defined');
$check(count(factoryPlanChoices(1)) === 4, 'single-platform category has four choices');
$check(count(factoryPlanChoices(2)) === 6, 'dual-platform category has six choices');
$check(count(factoryPlanChoices(3)) === 4, 'triple-platform category has four choices');
$check(count(factoryPlanChoices(4)) === 1, 'complete category has one choice');
$check(array_key_first(factoryPlanChoices(4)) === 'v:t+b+r+s', 'complete category selects all four platforms');
$check(count(factoryPlan('v:t+r+s')['platforms']) === 3, 'triple package contains three platforms');
$check(count(factoryPlan('v:t+b+r+s')['platforms']) === 4, 'complete package contains all four platforms');
$check(factoryPlan('v:t+b')['platforms'] === ['telegram', 'bale'], 'Telegram and Bale dual package exists');
$check(factoryPlan('v:t+b+s')['platforms'] === ['telegram', 'bale', 'soroush'], 'non-Rubika triple package exists');
$check(factoryPlanLabel('v:t+b') === 'تلگرام + بله', 'Bale package label is customer readable');
$check(factoryPlan('v:t+s')['platforms'] === ['telegram', 'soroush'], 'Telegram and Soroush dual package preserved');
$nextBundle = ['tokens' => []];
$nextPlan = factoryPlan('v:t+b+s');
$check(factoryNextTokenPlatform($nextPlan, $nextBundle) === 'telegram', 'first required token is Telegram');
$nextBundle['tokens']['telegram'] = 'example';
$check(factoryNextTokenPlatform($nextPlan, $nextBundle) === 'bale', 'second required token is Bale');
$nextBundle['tokens']['bale'] = 'example';
$check(factoryNextTokenPlatform($nextPlan, $nextBundle) === 'soroush', 'third required token is Soroush');
$nextBundle['tokens']['soroush'] = 'example';
$check(factoryNextTokenPlatform($nextPlan, $nextBundle) === null, 'completed bundle requires no more tokens');
$check(factoryPlatformTokenValid('telegram', '123456:ABCDEFGHIJKLMNOPQRST'), 'Telegram token format accepted');
$check(!factoryPlatformTokenValid('telegram', 'not-a-token'), 'invalid Telegram token blocked');
$check(factoryPlatformTokenValid('rubika', 'abcdefghijklmnopqrstuvwxyz_123456'), 'Rubika token shape accepted for future validation');
$check(factoryPlatformTokenValid('soroush', '123456:ABCDEFGHIJKLMNOPQRST'), 'Soroush Telegram-compatible token accepted');
$check(factoryPlatformTokenValid('bale', '23168863:ABCDEFGHIJKLMNOPQRST'), 'Bale Telegram-compatible token accepted');
$check(!factoryPlatformTokenValid('bale', 'invalid-bale-token'), 'invalid Bale token blocked');
$normalizedToken = factoryNormalizePlatformToken('telegram', '۱۲۳۴۵۶:ABCDEFGHIJKLMNOPQRST');
$check($normalizedToken === '123456:ABCDEFGHIJKLMNOPQRST', 'Persian digits normalized in token');

$factorySource = file_get_contents(__DIR__ . '/bot/api/factory.php');
$provisionStart = strpos($factorySource, 'function factoryProvisionRuntime');
$provisionEnd = strpos($factorySource, 'function factoryWriteTenantStatus', $provisionStart);
$provisionSource = substr($factorySource, $provisionStart, $provisionEnd - $provisionStart);
$check(!str_contains($provisionSource, 'modasr-factory-spawn'), 'provisioning never starts systemd service');
$check(str_contains($provisionSource, "'status' => 'stopped'"), 'new tenant is explicitly stopped');
$check(str_contains($provisionSource, "'fresh_install' => true"), 'new tenant is marked as a fresh install');
$check(str_contains($provisionSource, "'root_owner_ids'"), 'root owner aliases are tenant scoped');
$check(str_contains($factorySource, 'function factoryPrepareFullCore'), 'full MODASR core is provisioned per tenant');
$check(str_contains($factorySource, 'HOSTING_FULL_CORE_V1'), 'full core has an explicit feature flag');
$check(str_contains($factorySource, 'function factoryWriteTenantStatus'), 'start and stop synchronize tenant status');
$check(str_contains($factorySource, 'rubika_route_required'), 'Rubika package blocked before payment without HTTPS route');
$check(!str_contains($factorySource, 'factoryStartRuntime('), 'legacy auto-start provisioning path removed');

$check(!str_contains($factorySource, 'array_chunk($rows, 2)'), 'platform menu avoids invalid array-chunk markup');
$check(str_contains($factorySource, 'factoryKeyboardRows($buttons, 2)'), 'platform menu uses validated keyboard rows');

$workerSource = file_get_contents(__DIR__ . '/modasr-hosting-tenants/worker-template.php');
$check(str_contains($workerSource, "'soroush' => 'https://api.splus.ir/bot'"), 'Soroush long polling transport exists');
$check(str_contains($workerSource, "'bale' => 'https://tapi.bale.ai/bot'"), 'Bale long polling transport exists');
$check(str_contains($workerSource, "'bale' => 3"), 'Bale has an independent update offset index');
$check(str_contains($workerSource, "platform . ':' . \$userId"), 'platform-prefixed user identities prevent collisions');
$check(str_contains($workerSource, 'if (!$newEvent)'), 'duplicate updates return before sending a response');
$check(str_contains($workerSource, "'root_owner_ids'"), 'worker checks hidden root owner');
$check(str_contains($workerSource, 'function tenantRunCore'), 'worker delegates updates to the full MODASR core');
$check(str_contains($workerSource, "proc_get_status"), 'worker reads real child process status');
$check(!str_contains($workerSource, 'if ($code !== 0)'), 'worker does not rely on proc_close exit code');
$coreIndexSource = file_get_contents(__DIR__ . '/bot/index.php');
$check(str_contains($coreIndexSource, "if ((string) getenv('MODASR_WORKER_INTERNAL') !== '1')"), 'public webhook enforcement remains outside internal workers');
$check(!str_contains($workerSource, "'command' => 'owner'"), 'owner command is not registered in customer commands');

$invalidPlatformCount = false;
try {
    factoryPlanChoices(5);
} catch (RuntimeException $e) {
    $invalidPlatformCount = $e->getMessage() === 'platform_count_invalid';
}
$check($invalidPlatformCount, 'invalid platform category is rejected');

$configExampleSource = file_get_contents(__DIR__ . '/bot/config.example.php');
$check(str_contains($configExampleSource, "getenv('DB_PORT')"), 'core config includes the tenant database port');
$check(str_contains($configExampleSource, "';port='"), 'core DSN includes an explicit database port');

$backupStart = strpos($factorySource, 'function factoryQuarantineMirzaBackup');
$backupEnd = strpos($factorySource, 'function factoryProvisionRuntime', $backupStart);
$backupSource = substr($factorySource, $backupStart, $backupEnd - $backupStart);
$check(!str_contains($backupSource, 'ZipArchive'), 'Backup ZIP is never extracted');
$check(!str_contains($backupSource, '->exec('), 'Backup SQL is never executed');

$keyboardRows = factoryKeyboardRows([
    ['text' => 'a', 'callback_data' => 'a'],
    ['text' => 'b', 'callback_data' => 'b'],
    ['text' => 'c', 'callback_data' => 'c'],
    ['text' => 'd', 'callback_data' => 'd'],
], 2);
$check(count($keyboardRows) === 2 && count($keyboardRows[0]) === 2 && count($keyboardRows[1]) === 2, 'keyboard rows have valid two-button structure');
$check($keyboardRows[0][0]['text'] === 'a' && $keyboardRows[0][1]['text'] === 'b', 'keyboard button order preserved');
$check(factoryProviderErrorCode('Bad Request: can\'t parse reply markup', 400) === 'provider_rejected_400|reply_markup', 'reply markup error is classified');
$check(factoryProviderErrorCode('Bad Request: message text is empty', 400) === 'provider_rejected_400|message_empty', 'empty message error is classified');
$check(factoryProviderErrorCode('Bad Request: query is too old', 400) === 'provider_rejected_400|callback_query', 'old callback error is classified');
$check(factoryProviderErrorCode('Bad Request: chat not found', 400) === 'provider_rejected_400|chat_not_found', 'chat error is classified');
$check(factoryProviderErrorCode('unknown provider error', 400) === 'provider_rejected_400|http_400', 'unknown provider error stays bounded');

echo "Passed: {$passed}  Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
