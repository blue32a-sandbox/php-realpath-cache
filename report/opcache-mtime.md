# OPcache・シンボリックリンク・リンク先 mtime の検証

`opcache.validate_timestamps=1` の OPcache が、シンボリックリンクを切り替えたときに、どのファイルの mtime で更新を判定しているかを確認した。

## 結論

**nginx + php-fpm**
- リンク先の mtime は関係しなかった
  - v2 の mtime を v1 と同じにしても、新しくしても、古くしても、180 秒間 v1 のままだった
  - OPcache の misses は増えず、v2 はコンパイルされなかった
- OPcache は、キャッシュした実ファイル（`private/v1/index.php`）の mtime で更新を判定していると考えられる
  - 切り替えのあとに v1 を `touch` すると、約 1.8 秒後（`revalidate_freq=2` 以内）に v2 になった
  - このとき、v1 を読み直すのではなく、リンク経由で v2 がコンパイルされた

**Apache + mod_php**
- mtime に関係なく、すべてのケースで切り替え後の最初のリクエストで v2 になった
  - 切り替えた直後に v2 がコンパイルされた（misses が 3 から 4 に増えた）
- realpath cache が無効なので、リクエストごとに解決し直した実パス（v2）で OPcache を引いていると考えられる（推測）
  - nginx + php-fpm では、解決前のパスで引いていると考えられる
  - この違いの原因は、まだ確認していない
  - 注: [realpath cache + OPcache の検証](realpath-opcache.md)では、apache でも `validate_timestamps=0` だと反映されなかった。そのため、この推測は合わない可能性が高い。詳しくはそちらの「未解決の点」を参照

**共通**
- 実際のリリースのように「新しいバージョンのほうが mtime が新しい」としても、それだけでは切り替えの反映に影響しない

## 条件

| 項目 | 値 |
|---|---|
| 環境 | nginx + php-fpm / Apache + mod_php（PHP 8.5.12） |
| `WORKERS` | 1 |
| realpath cache | 無効（`REALPATH_CACHE_TTL=-1`、`REALPATH_CACHE_SIZE=0`） |
| OPcache | デフォルト（`validate_timestamps=1`、`revalidate_freq=2`、`revalidate_path=0`、`file_update_protection=2`） |
| 観測 | 0.5 秒間隔、`--timeout=180`、手動クリアなし |

ケースごとの mtime の設定:

この検証のときだけ、`docker/entrypoint.sh` でコピー直後に v1 の mtime を1時間前に戻していた（`touch -d '1 hour ago'`）。mtime は結果に影響しないと分かったため、この変更は元に戻している。

| ケース | mtime |
|---|---|
| same | v2 を v1 と同じにする（`touch -r`） |
| newer | v2 が v1 より1時間新しい（entrypoint で設定したまま） |
| older | v2 を v1 より約1時間古くする（`touch -d '2 hours ago'`） |
| touch-v1 | newer と同じ。切り替えの約10秒後に v1 を `touch` する |

## 手順

1. コンテナを再起動し、mtime を設定する
2. `/` に1回リクエストし、`_status.php` を記録する
3. 観測を開始し、約5秒後に `switch v2` を実行する（touch-v1 では、さらに約10秒後に v1 を `touch` する）
4. 全 PID が v2 になるか、timeout まで観測する。そのあと mtime と `_status.php` を記録する

## 結果

経過時間は観測を開始してからの秒数で、切り替えは約 5.0 秒の時点である。

| 環境 | ケース | 結果 | 最初の v2 | OPcache（観測後） |
|---|---|---|---|---|
| nginx | same | 180 秒間すべて v1 | なし | misses 3。v1 だけ |
| nginx | newer | 180 秒間すべて v1 | なし | misses 3。v1 だけ |
| nginx | older | 180 秒間すべて v1 | なし | misses 3。v1 だけ |
| nginx | touch-v1 | v1 を `touch` した約 1.8 秒後に v2 | 17.09 秒 | misses 4。v2 を追加。v1 の timestamp は 0 |
| apache | same | 切り替え後の最初のリクエストで v2 | 5.02 秒 | misses 4。v2 を追加。v1 の timestamp は 0 |
| apache | newer | 切り替え後の最初のリクエストで v2 | 5.53 秒 | 同上 |
| apache | older | 切り替え後の最初のリクエストで v2 | 5.03 秒 | 同上 |
| apache | touch-v1 | 切り替え後の最初のリクエストで v2（`touch` より前） | 5.54 秒 | 同上 |

どのケースも PID は1つで、エラーはなかった。

## 記録

`results/mtime-<env>-<ケース>.*`（コミット対象外）

- `.jsonl`、`.summary.json`: 観測クライアントの記録
- `.mtime-before.txt`、`.mtime-after.txt`: v1 と v2 の mtime
- `.first.json`: 最初の `/` へのレスポンス
- `.before.json`、`.after.json`: 観測の前後の `_status.php`
