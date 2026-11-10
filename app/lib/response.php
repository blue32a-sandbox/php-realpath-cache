<?php
// private/vN/index.php と public/_*.php から include する共通処理。
// このファイル自体はシンボリックリンク切り替え・検証の対象外。

function send_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

function respond(string $version, string $file): void
{
    send_json([
        'version' => $version,
        'time' => microtime(true),
        'pid' => getmypid(),
        'sapi' => PHP_SAPI,
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? null,
        'file' => $file,
        'realpath_cache_used' => realpath_cache_size(),
        'ini' => [
            'realpath_cache_size' => ini_get('realpath_cache_size'),
            'realpath_cache_ttl' => ini_get('realpath_cache_ttl'),
            'opcache.enable' => ini_get('opcache.enable'),
            'opcache.validate_timestamps' => ini_get('opcache.validate_timestamps'),
            'opcache.revalidate_freq' => ini_get('opcache.revalidate_freq'),
            'opcache.revalidate_path' => ini_get('opcache.revalidate_path'),
            'opcache.file_update_protection' => ini_get('opcache.file_update_protection'),
        ],
    ]);
}
