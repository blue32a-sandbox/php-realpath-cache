# realpath cache の検証

OPcache を無効にして、realpath cache の設定（`realpath_cache_ttl` / `realpath_cache_size`）だけで、シンボリックリンクの切り替えがいつ反映されるかを確認した。`realpath_cache_ttl=0` を指定するとエントリが無期限になるという情報があるが、公式ドキュメントには記載がないため、これもあわせて確認した。

## 結論

nginx + php-fpm と Apache + mod_php で、同じ結果になった。

- **`realpath_cache_ttl=0` は無期限**
  - エントリは `expires=-1` で登録され、180 秒経っても期限切れにならなかった
  - シンボリックリンクを切り替えても、v1 を解決し続けた
  - realpath cache だけをクリアすると v2 になったことから、v1 を返し続けた原因は realpath cache にある
- **`realpath_cache_ttl=-1` は実質的に無効**
  - エントリは登録されるが、`expires` は登録した時刻の1秒前になる
  - そのため次の参照では必ず期限切れとして扱われ、切り替えはすぐに反映された
- **`realpath_cache_ttl=30` は TTL どおり**
  - エントリが期限切れになった直後のリクエストで、v2 が返った
  - 切り替えから反映までの時間は、「エントリを登録してから TTL が切れるまでの残り時間」で決まる。切り替えた時刻からではない
  - `expires` と同じ秒にはまだ v1 が返り、その次の秒から v2 になった
- **`realpath_cache_size=0` は無効**
  - エントリは登録されず（0 件）、切り替えはすぐに反映された

## 条件

| 項目 | 値 |
|---|---|
| 環境 | nginx + php-fpm / Apache + mod_php（PHP 8.5.12） |
| `WORKERS` | 1 |
| `OPCACHE_ENABLE` | 0 |
| `REALPATH_CACHE_TTL` | 0 / -1 / 30（SIZE の検証ではデフォルトの 120） |
| `REALPATH_CACHE_SIZE` | 0（TTL の検証ではデフォルトの 4096K） |
| 観測 | 0.5 秒間隔、`--timeout=180`、手動クリアなし |

## 手順

1. コンテナを再起動し、`/` に1回リクエストする（v1 のエントリを作る）
2. `_status.php` を記録する
3. 観測を開始し、約5秒後に `switch v2` を実行する
4. 全 PID が v2 になるか、timeout まで観測する。そのあと `_status.php` を記録する
5. v1 のままだった場合は、`_clear.php?target=realpath` で realpath cache だけをクリアし、`/` を確認する

## 結果

時刻は、最初のリクエストで realpath cache にエントリができた時刻を 0 秒とした目安である。

| 環境 | 設定 | `index.php` エントリの `expires` | 結果 | 切り替えから反映まで |
|---|---|---|---|---|
| nginx | TTL=0 | `-1` | 180 秒間すべて v1。realpath cache だけをクリアすると v2 | 反映されない |
| apache | TTL=0 | `-1` | 180 秒間すべて v1。realpath cache だけをクリアすると v2 | 反映されない |
| nginx | TTL=-1 | 登録した時刻 − 1 秒 | 切り替え後の最初のリクエストで v2 | 約 0.5 秒（次のリクエスト） |
| apache | TTL=-1 | 登録した時刻 − 1 秒 | 切り替え後の最初のリクエストで v2 | 約 0.5 秒（次のリクエスト） |
| nginx | TTL=30 | 登録した時刻 + 30 秒 | エントリが期限切れになった直後に v2（約 30.7 秒） | 約 25.6 秒 |
| apache | TTL=30 | 登録した時刻 + 30 秒 | エントリが期限切れになった直後に v2（約 31.1 秒） | 約 26.0 秒 |
| nginx | SIZE=0 | エントリなし（0 件、used 0） | 切り替え後の最初のリクエストで v2 | 約 0.5 秒（次のリクエスト） |
| apache | SIZE=0 | エントリなし（0 件、used 0） | 切り替え後の最初のリクエストで v2 | 約 0.5 秒（次のリクエスト） |

どのケースも PID は1つ（nginx は 15、apache は 23）で、エラーはなかった。

## 記録

`results/` 配下（コミット対象外）:

- `ttl-<env>-<TTL>.*`（例: `ttl-nginx-0`、`ttl-nginx--1`、`ttl-apache-30`）
- `size-<env>-0.*`

ファイルの中身:

- `.jsonl`、`.summary.json`: 観測クライアントの記録
- `.before.json`、`.after.json`: 観測の前後の `_status.php`
- `.first.json`: 最初の `/` へのレスポンス。nginx / TTL=0 と nginx / TTL=-1 は手動で実施したため、このファイルはない
- `.clear.json`、`.afterclear.json`: realpath cache をクリアしたときの記録。timeout になったケースだけにある
