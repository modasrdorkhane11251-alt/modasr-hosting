<?php
/**
 * Public live USDT/TMN rate (Wallex) for the MODASR website.
 * Read-only, cached 30s, CORS-enabled so the browser can call it directly.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=30');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Asia/Tehran');

$cacheFile = sys_get_temp_dir() . '/modasr_public_rate.json';
$rate = null;
$at = null;

if (is_file($cacheFile) && time() - filemtime($cacheFile) < 30) {
    $d = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($d) && !empty($d['rate'])) {
        $rate = (float)$d['rate'];
        $at = $d['at'] ?? null;
    }
}

if ($rate === null) {
    $json = @file_get_contents('https://api.wallex.ir/v1/markets', false, stream_context_create(['http' => ['timeout' => 20]]));
    if ($json) {
        $j = json_decode($json, true);
        $r = (float)($j['result']['symbols']['USDTTMN']['stats']['lastPrice'] ?? 0);
        if ($r > 0) {
            $rate = $r;
            $at = gmdate('c');
            @file_put_contents($cacheFile, json_encode(['rate' => $rate, 'at' => $at]), LOCK_EX);
        }
    }
}

if ($rate === null) {
    http_response_code(502);
    echo json_encode(['status' => false, 'msg' => 'rate_unavailable']);
    exit;
}

echo json_encode([
    'status' => true,
    'rate' => $rate,
    'at' => $at ?? gmdate('c'),
    'source' => 'wallex',
]);
