# 既存の記事との比較

検証の結果を、シンボリックリンクによるデプロイと PHP のキャッシュについて書かれた次の2つの記事と比べた。

- [記事1] KLab「シンボリックリンク切り換えデプロイを行った際に起きるOPcacheの問題はnginx+php-fpm環境でのみ起きるのか？」（2016/11/30）
  https://www.klab.com/jp/blog/tech/2016/1062775677.html
- [記事2] KLab「PHPにおけるシンボリックリンクを使ったデプロイの危険性について（「realpath_cache」和訳）」（2016/10/31。原文は Julien Pauli 氏「realpath_cache」2014/06/30）
  https://www.klab.com/jp/blog/tech/2016/1062120304.html

## 結論

- 記事1が報告した現象は、PHP 8.5 でも、ファイル単位のシンボリックリンクでも、そのまま再現した
  - apache + mod_php は、realpath cache の期限切れのあとに反映される
  - nginx + php-fpm は、期限切れのあとも古いバージョンのまま
- 記事にない点として、次のことが分かった
  - nginx + php-fpm では、OPcache がキャッシュした v1 の実ファイルの mtime で更新を判定している
  - `opcache.revalidate_path=1` にすると、両方の環境で realpath cache の TTL どおりに反映される
- 記事2の「`expires` は最終アクセス日時から計算される」という記述は、今回の結果と合わない。今回は、アクセスし続けても、登録した時刻から TTL 秒後に期限切れになった
- 2つの記事の間で食い違っている点（`opcache_reset()` で全プロセスの realpath cache がクリアされるか）は、まだ検証していない

## 前提の違い

| 項目 | 記事1 | 記事2 | 今回の検証 |
|---|---|---|---|
| PHP | バージョンの記載なし | 記載なし（ソースの参照は PHP 5.5 / 7.0） | 8.5.12 |
| SAPI | nginx + php-fpm / Apache + mod_php | 指定なし | nginx + php-fpm / Apache + mod_php |
| シンボリックリンク | ディレクトリ単位（`docroot -> 1`） | ディレクトリ単位（`deploy-a` → `deploy-b`） | ファイル単位（`public/index.php -> ../private/vN/index.php`） |
| 計測 | デフォルト設定（TTL 120 秒）で、切り替えの直後と 120 秒後 | なし | 設定を1つずつ変えて、0.5 秒間隔で観測 |

## 記事1との比較

| 記事1の記述 | 今回の検証 | 判定 |
|---|---|---|
| 切り替えた直後は、両方の環境で古いバージョンのまま。原因は realpath cache | 同じ。realpath cache を無効にすると、apache はすぐ反映され、nginx は反映されない | 一致 |
| 120 秒後、Apache + mod_php は新しいバージョンになる | 約 122 秒で v2（期限切れの約2秒後） | 一致 |
| 120 秒後、nginx + php-fpm は古いバージョンのまま | 180 秒間 v1 のまま | 一致 |
| nginx では、realpath cache は新しいリンク先で作り直されているのに、古いコードが動き続ける | 同じ。`index.php` のエントリは v2 を指しているのに、OPcache は v1 を返し続けた | 一致 |
| 原因: OPcache が共有メモリに持つ realpath の情報が、php-fpm では作り直されない | v1 の実ファイルを `touch` すると約2秒で v2 になった。OPcache は v1 の実ファイルの mtime で更新を判定していると考えられる | 今回の検証で詳しく分かった |
| 対策: nginx で `$realpath_root` を使う | 未検証。今回の構成（ファイル単位のリンク）では効かない可能性が高い | 未検証 |
| 対策: PHP 7.0 以降の `opcache_reset()` は、全プロセスの realpath cache もクリアする | 未検証 | 未検証 |

記事1は、`opcache.revalidate_path` と `opcache.validate_timestamps` には触れていない。

## 記事2との比較

| 記事2の記述 | 今回の検証 | 判定 |
|---|---|---|
| realpath cache はプロセスごとにあり、共有されない | WORKERS=2 では、ワーカーごとに反映のタイミングがずれた（`revalidate_path=1`） | 一致 |
| `clearstatcache(true)` で realpath cache をクリアできる。全プロセスを一度にクリアする手段はない | `_clear.php?target=realpath`（`clearstatcache(true)`）は、処理したワーカーの realpath cache だけをクリアした | 一致 |
| パスは要素ごとに分解され、それぞれがエントリになる | `/srv`、`/srv/app`、`/srv/app/public` などのエントリがあった | 一致 |
| `expires` は `realpath_cache_ttl` と最終アクセス日時から計算される | TTL=30 で、0.5 秒ごとにアクセスし続けても、登録した時刻の 30 秒後に期限切れになった | **不一致** |
| デフォルトの size はとても小さい（16K） | 今の PHP のデフォルトは 4096K | PHP のバージョンによる違い |
| OPcache は realpath cache を信頼していて、エントリが期限切れになると変更に気づく | apache の default と、両方の環境の `revalidate_path=1` ではこのとおり。nginx の default は当てはまらない | 一部一致 |
| OPcache がなくても、realpath cache だけで同じ問題が起きうる（訳注） | OPcache 無効でも、TTL が切れるまで v1 のまま。TTL=0 なら反映されない | 一致 |
| size が小さいと、問題が起きないかもしれない（訳注） | realpath cache だけなら正しい（SIZE=0 ですぐ反映）。ただし OPcache がデフォルトの nginx では、realpath cache を無効にしても v1 のまま | 一部一致 |
| 対策: 新旧の PHP ワーカープールを分ける | 対象外 | 対象外 |

## 2つの記事の間で食い違っている点

- **OPcache が realpath の情報を共有メモリに持つか**
  - 記事1と記事2の訳注は「持つ」と書いている。記事2の本文は「realpath cache はプロセスごと」と書いている
  - 今回の検証では OPcache の内部を直接は見ていない。apache / WORKERS=2 / default では、片方のワーカーが v2 になると、もう片方も次のリクエストで v2 になった。これは、OPcache の状態がワーカー間で共有されていることと合っている
- **全プロセスの realpath cache を一度にクリアできるか**
  - 記事1は「PHP 7.0 以降の `opcache_reset()` で可能」と書いている。記事2は「手段はない」と書いている（`clearstatcache` についての記述）
  - 今回の環境では、WORKERS=2 で `_clear.php?target=opcache` を1回呼び、両方のワーカーが v2 になるかで確かめられる（未検証）
