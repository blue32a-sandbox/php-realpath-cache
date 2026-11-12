<?php
// 観測クライアント。サーバーへ定期的にリクエストし、version / pid の推移を記録する。
//
// 使い方: php observe.php [--url=URL] [--interval=秒] [--until=VERSION] [--timeout=秒] [--out=PATH]
//   --until   : 環境変数 WORKERS 個の PID がすべてこのバージョンを返したら終了（省略時は timeout まで観測）
//   --timeout : 観測の上限時間（秒）
//   --out     : JSONL の出力先（省略時 results/YYYYmmdd-HHMMSS.jsonl）。サマリーは *.summary.json
//
// リクエストは毎回新しい接続で行う（Keep-Alive で同じワーカーに固定されないようにするため）。

// PHP は環境変数 TZ を参照しないため明示的に設定する
date_default_timezone_set(getenv('TZ') ?: 'UTC');

$opts = getopt('', ['url:', 'interval:', 'until:', 'timeout:', 'out:']);
$url = $opts['url'] ?? 'http://web/';
$interval = (float) ($opts['interval'] ?? 0.5);
$until = $opts['until'] ?? null;
$timeout = (float) ($opts['timeout'] ?? 60);
$out = $opts['out'] ?? 'results/' . date('Ymd-His') . '.jsonl';
$workers = (int) getenv('WORKERS');

if ($until !== null && $workers < 1) {
    fwrite(STDERR, "--until には環境変数 WORKERS (1 以上) が必要です\n");
    exit(1);
}

$summaryPath = preg_replace('/\.jsonl$/', '', $out) . '.summary.json';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}
$fp = fopen($out, 'w');

$context = stream_context_create(['http' => [
    'header' => "Connection: close\r\n",
    'ignore_errors' => true,  // 4xx/5xx でも本文を取得する
    'timeout' => 5,
]]);

$start = hrtime(true);
$elapsed = fn (): float => round((hrtime(true) - $start) / 1e9, 3);

$total = 0;
$errors = 0;
$versions = [];          // version => 件数
$pids = [];              // pid => [requests, first_seen, last_version, switched_at]
$firstUntilAt = null;
$reason = 'timeout';

while ($elapsed() < $timeout) {
    $record = ['client_time' => microtime(true), 'elapsed' => $elapsed()];
    $body = @file_get_contents($url, false, $context);
    $headers = http_get_last_response_headers() ?? [];
    $status = preg_match('#^HTTP/\S+ (\d{3})#', $headers[0] ?? '', $m) ? (int) $m[1] : null;
    $data = $body === false ? null : json_decode($body, true);

    $record['status'] = $status;
    $record['version'] = $data['version'] ?? null;
    $record['pid'] = $data['pid'] ?? null;
    if ($status !== 200 || $record['version'] === null) {
        $record['error'] = $body === false ? (error_get_last()['message'] ?? 'request failed') : 'unexpected response';
        $errors++;
    }

    $total++;
    if (($v = $record['version']) !== null && ($pid = $record['pid']) !== null) {
        $versions[$v] = ($versions[$v] ?? 0) + 1;
        $p = $pids[$pid] ?? ['requests' => 0, 'first_seen' => $record['elapsed'], 'last_version' => null, 'switched_at' => null];
        $p['requests']++;
        $p['last_version'] = $v;
        if ($v === $until && $p['switched_at'] === null) {
            $p['switched_at'] = $record['elapsed'];
            $firstUntilAt ??= $record['elapsed'];
        }
        $pids[$pid] = $p;
    }

    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite($fp, $line . "\n");
    echo $line, "\n";

    // 終了判定: WORKERS 個以上の PID を観測し、そのすべての最新バージョンが --until
    if ($until !== null && count($pids) >= $workers
        && count(array_filter($pids, fn ($p) => $p['last_version'] !== $until)) === 0) {
        $reason = 'switched';
        break;
    }

    usleep((int) ($interval * 1e6));
}
fclose($fp);

ksort($pids);
$summary = [
    'url' => $url,
    'until' => $until,
    'workers' => $workers ?: null,
    'reason' => $reason,
    'duration' => $elapsed(),
    'total' => $total,
    'errors' => $errors,
    'versions' => $versions,
    'first_until_at' => $firstUntilAt,
    'pids' => $pids,
    'jsonl' => $out,
];
$json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($summaryPath, $json . "\n");
echo "\n", $json, "\n";
