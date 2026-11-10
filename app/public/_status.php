<?php
// このリクエストを処理したワーカーの realpath cache と、共有メモリ上の OPcache の状態を返す。
require __DIR__ . '/../lib/response.php';

$opcache = function_exists('opcache_get_status') ? opcache_get_status(true) : false;

send_json([
    'time' => microtime(true),
    'pid' => getmypid(),
    'realpath_cache' => [
        'used' => realpath_cache_size(),
        'entries' => realpath_cache_get(),
    ],
    'opcache' => $opcache === false ? null : [
        'hits' => $opcache['opcache_statistics']['hits'],
        'misses' => $opcache['opcache_statistics']['misses'],
        'scripts' => array_values(array_map(
            fn(array $s) => ['path' => $s['full_path'], 'timestamp' => $s['timestamp'] ?? null],
            $opcache['scripts'] ?? [],
        )),
    ],
]);
