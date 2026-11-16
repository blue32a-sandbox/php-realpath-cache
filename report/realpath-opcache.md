# realpath cache + OPcache の検証

realpath cache と OPcache がどちらも有効な状態で、OPcache の設定ごとに、シンボリックリンクの切り替えがいつ反映されるかを確認した。

## 結論

| 設定 | nginx + php-fpm | Apache + mod_php |
|---|---|---|
| default | 反映されない（180 秒間 v1） | realpath cache の期限切れの約 2 秒後に反映 |
| `validate_timestamps=0` | 反映されない | 反映されない |
| `revalidate_path=1` | realpath cache の期限切れの直後に反映 | realpath cache の期限切れの直後に反映 |

- **nginx + php-fpm**
  - デフォルト設定では、realpath cache が期限切れになっても反映されない
  - 反映させるには、`revalidate_path=1` にするか、OPcache をクリアする必要がある
- **Apache + mod_php**
  - デフォルト設定でも、realpath cache が期限切れになると反映される
  - ただし `validate_timestamps=0` では反映されない
  - 期限切れから反映までの約 2 秒の遅れは、`revalidate_freq=2` によるものと考えられる（推測）
- **`revalidate_path=1`**
  - 両方の環境で、realpath cache が期限切れになった直後のリクエストで反映された
  - realpath cache の TTL が、そのまま反映までの時間になる
- **WORKERS=2**
  - realpath cache はワーカーごとにあるため、ワーカーごとに反映のタイミングがずれた（`revalidate_path=1`）
  - apache の default では、片方のワーカーで v2 になると、もう片方も次のリクエストで v2 になった。OPcache が共有メモリにあるためと考えられる
  - apache / WORKERS=2 / default では、反映のタイミングが一定しなかった。4回のうち2回は realpath cache の期限切れのあと（約 123 秒）、2回はそれより早く（約 65 秒、約 89 秒）反映された。原因は分かっていない（[未解決の点](#未解決の点)）

実施しなかったパターンの結果は、ほかの検証の結果で代えた。

- **realpath cache 無効（OPcache デフォルト）**: [mtime の検証](opcache-mtime.md)の newer のケースと同じ条件
  - nginx は反映されない
  - apache は切り替えた直後に反映される
- **OPcache 無効（realpath cache デフォルト）**: [realpath cache の検証](realpath-cache.md)より、realpath cache の TTL どおりに反映される

## 条件

| 項目 | 値 |
|---|---|
| 環境 | nginx + php-fpm / Apache + mod_php（PHP 8.5.12） |
| realpath cache | デフォルト（`REALPATH_CACHE_SIZE=4096K`、`REALPATH_CACHE_TTL=120`） |
| OPcache | デフォルト（`validate_timestamps=1`、`revalidate_freq=2`、`revalidate_path=0`、`file_update_protection=2`）から、1つだけ変更 |
| v1 / v2 の mtime | 起動時にコピーしたまま（ほぼ同じ時刻） |
| 観測 | 0.5 秒間隔、`--timeout=180`、手動クリアなし |

| ID | 変更内容 |
|---|---|
| default | なし |
| oc-novalidate | `OPCACHE_VALIDATE_TIMESTAMPS=0` |
| oc-revalpath | `OPCACHE_REVALIDATE_PATH=1` |

## 手順

1. `.env` を設定してコンテナを再起動し、約3秒待つ
2. `/` に1回リクエストし、`_status.php` を記録する
3. 観測を開始し、約5秒後に `switch v2` を実行する
4. 全 PID が v2 になるか、timeout まで観測する。そのあと `_status.php` を記録する

## 結果

経過時間は観測を開始してからの秒数で、切り替えは約 5 秒の時点である。「realpath の期限切れ」は、切り替え前の `_status.php` で見た `public/index.php` エントリの `expires` から求めた、期限切れになる時点の経過時間である（`expires` の秒を過ぎると期限切れ）。

| 環境 | WORKERS | 設定 | 結果 | realpath の期限切れ | 反映（PID ごとの最初の v2） | OPcache（観測後） |
|---|---|---|---|---|---|---|
| nginx | 1 | default | 180 秒間すべて v1 | 約 120.3 秒 | なし | misses 3。v1 だけ |
| nginx | 1 | oc-novalidate | 180 秒間すべて v1 | 約 119.7 秒 | なし | misses 3。v1 だけ |
| nginx | 1 | oc-revalpath | 期限切れの直後に v2 | 約 120.1 秒 | 120.09 秒 | misses 4。v2 を追加 |
| nginx | 2 | oc-revalpath | ワーカーごとに期限切れの直後に v2 | 記録なし | PID 15: 120.56 秒、PID 16: 121.06 秒 | misses 4。v2 を追加 |
| apache | 1 | default | 期限切れの約 2.3 秒後に v2 | 約 120.2 秒 | 122.45 秒 | misses 4。v2 を追加 |
| apache | 1 | oc-novalidate | 180 秒間すべて v1 | 約 119.6 秒 | なし | misses 3。v1 だけ |
| apache | 1 | oc-revalpath | 期限切れの直後に v2 | 約 120.0 秒 | 120.03 秒 | misses 4。v2 を追加 |
| apache | 2 | default | 両方のワーカーがほぼ同時に v2。タイミングは一定しない（下表） | 記録なし | 1回目: PID 24: 64.77 秒、PID 23: 65.28 秒 | misses 4。v2 を追加 |
| apache | 2 | oc-revalpath | ワーカーごとに期限切れの直後に v2 | 記録なし | PID 23: 120.45 秒、PID 24: 121.95 秒 | misses 4。v2 を追加 |

- WORKERS=2 のケースでは、切り替え前の `_status.php` を処理したワーカーに `index.php` のエントリがなかったため、期限は記録できていない
- WORKERS=2 では、2つのワーカーの最初のリクエストが約 0.5 秒ずれている。nginx / oc-revalpath で反映が 0.5 秒ずれたのは、このためと考えられる
- エラーはなかった

## 未解決の点

- **apache / WORKERS=2 / default の反映のタイミングが一定しない**
  - 同じ条件で4回実施した結果は、次のとおり

    | 回 | 先に v2 になった PID | 反映（PID ごとの最初の v2） |
    |---|---|---|
    | 1 | 24 | PID 24: 64.77 秒、PID 23: 65.28 秒 |
    | 2 | 23 | PID 23: 122.56 秒、PID 24: 123.07 秒 |
    | 3 | 24 | PID 24: 88.88 秒、PID 23: 89.38 秒 |
    | 4 | 23 | PID 23: 122.46 秒、PID 24: 122.96 秒 |

  - 2回と4回は、WORKERS=1 と同じく、realpath cache の期限切れの約 2 秒後に反映された
  - 1回と3回は、それより早く反映された。どちらも先に v2 になったのは PID 24 で、その次のリクエストで PID 23 も v2 になった
    - PID 23 の realpath cache は、まだ期限切れではないはず
    - 1回目では、観測後の PID 24 のエントリが、v2 を返した時刻に作り直されていた。PID 24 では、何らかの理由で期限より前に `index.php` が解決し直されたと考えられる
  - 原因は分かっていない。WORKERS=1 の apache / default は1回しか実施していないため、WORKERS=1 でも同じことが起きるかは確認していない
  - 記録: `results/apache-w2-default.*`（1回目）、`results/apache-w2-default-r2.*` 〜 `-r4.*`
- **apache と nginx の違いの原因**
  - [mtime の検証](opcache-mtime.md)では、apache は「解決後の実パスで OPcache を引いている」と推測した。しかし今回、`validate_timestamps=0` で反映されなかったので、この推測は合わない
  - 今回の結果からは、apache では OPcache の更新チェック（`validate_timestamps`）のときに、realpath cache を通して解決したパスを見ている可能性がある（推測）

## 記録

`results/<env>-w<WORKERS>-<設定>.*`（コミット対象外）

- `.jsonl`、`.summary.json`、`.client.log`: 観測クライアントの記録
- `.first.json`: 最初の `/` へのレスポンス
- `.before.json`、`.after.json`: 観測の前後の `_status.php`
