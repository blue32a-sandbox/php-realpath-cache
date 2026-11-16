# 検証結果の概要

シンボリックリンク（`public/index.php -> ../private/vN/index.php`）を v1 から v2 に切り替えたとき、PHP の実行結果にいつ反映されるかを、realpath cache と OPcache の設定ごとに検証した結果をまとめる。

## 検証の一覧

| 検証 | 内容 | 状態 |
|---|---|---|
| [realpath cache の検証](realpath-cache.md) | OPcache を無効にし、`realpath_cache_ttl` / `realpath_cache_size` だけで反映のタイミングを確認 | 完了 |
| [OPcache・シンボリックリンク・リンク先 mtime の検証](opcache-mtime.md) | realpath cache を無効にし、OPcache がどのファイルの mtime で更新を判定するかを確認 | 完了 |
| realpath cache + OPcache の検証 | 両方のキャッシュの設定を組み合わせたときの反映のタイミングを確認 | 未実施 |

## 主な結論

**realpath cache**（nginx + php-fpm、Apache + mod_php で同じ）
- `realpath_cache_ttl=0` は無期限。キャッシュをクリアしない限り、切り替えは反映されない
- `realpath_cache_ttl=-1` と `realpath_cache_size=0` は、実質的に無効。切り替えはすぐに反映される
- TTL が有限の場合、エントリを登録してから TTL 秒後に反映される。切り替えた時刻からではない

**OPcache**（realpath cache は無効、OPcache はデフォルト）
- リンク先の mtime は、どちらの環境でも反映に影響しない
- nginx + php-fpm では、OPcache がキャッシュした v1 の実ファイルの mtime で判定するため、切り替えは反映されない。v1 を書き換えると反映される
- Apache + mod_php では、切り替えた直後に反映される。nginx + php-fpm との違いの原因は未確認

## 共通の前提

| 項目 | 値 |
|---|---|
| PHP | 8.5.12 |
| 環境 | nginx + php-fpm（`pm=static`）/ Apache + mod_php（prefork） |
| 切り替え | `docker compose exec <svc> switch v2`（`ln -sfn`） |
| 観測 | `client/observe.php`。0.5 秒間隔、毎回新しい接続 |

観測の生データは `results/` に保存している（コミット対象外）。
