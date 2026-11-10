<?php
// キャッシュをクリアする。?target=realpath|opcache|all
// realpath cache はこのリクエストを処理したワーカーの分のみ、OPcache は共有メモリ全体が対象。
require __DIR__ . '/../lib/response.php';

$target = $_GET['target'] ?? '';
if (!in_array($target, ['realpath', 'opcache', 'all'], true)) {
    send_json(['error' => 'target must be realpath, opcache or all'], 400);
    return;
}

$cleared = [];
if ($target === 'realpath' || $target === 'all') {
    clearstatcache(true);
    $cleared['realpath'] = true;
}
if ($target === 'opcache' || $target === 'all') {
    $cleared['opcache'] = function_exists('opcache_reset') && opcache_reset();
}

send_json([
    'time' => microtime(true),
    'pid' => getmypid(),
    'cleared' => $cleared,
]);
