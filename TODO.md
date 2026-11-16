# TODO

各ステップ完了後に動作確認を行い、確認が取れてから次へ進む。

## 決定事項

- 切り替え方式: `public/index.php -> ../private/vN/index.php`（ファイル単位のシンボリックリンク）
- v1 / v2 は事前配置。切り替えは `docker compose exec <svc> switch vN`（中身は `ln -sfn`、非アトミック。Git Bash のパス変換を避けるためコンテナ内スクリプト化）
- `private/vN/index.php` は共通処理 `lib/response.php` を include（`lib/` は切り替え・検証の対象外）
- 状態参照は HTTP（`/_status.php`）、キャッシュクリアは HTTP（`/_clear.php`）。realpath cache は処理したワーカーのみが対象
- ワーカー数は `.env` の `WORKERS` で 1〜n を指定（fpm: `pm=static` / Apache: prefork 固定数）
- 設定変更は `.env` を書き換えてコンテナ再起動
- 1環境ずつ起動（Compose profiles: `apache` / `nginx`）。公開ポートは `127.0.0.1:8080`
- 起動時にソースをコンテナ内 `/srv/app` へコピーし、`index.php -> v1` を作成（named volume なし）
- クライアントは PHP CLI。記録は JSONL + 終了時サマリー

## ステップ

### Step 1: nginx + php-fpm 8.5 で v1 を返す

- [x] `.gitattributes`（LF 固定）、`.gitignore`（`results/`, `.env`）
- [x] `.env.example`（`WORKERS`, `REALPATH_CACHE_SIZE`, `REALPATH_CACHE_TTL`, `OPCACHE_*`）
- [x] `docker/php.ini`（環境変数を展開するキャッシュ設定）
- [x] `docker/entrypoint.sh`（`/srv/app` へコピー、`index.php -> v1` 作成）
- [x] `docker/nginx/`（Dockerfile, nginx.conf, www.conf）
- [x] `compose.yaml`（nginx サービス、profile `nginx`、ネットワークエイリアス `web`）
- [x] `app/private/v1/index.php`, `app/private/v2/index.php`, `app/lib/response.php`（観測 JSON を返す）
- [x] 確認: `curl http://127.0.0.1:8080/` で v1 の JSON が返る

### Step 2: 制御エンドポイント

- [x] `app/public/_status.php`（ワーカーの realpath cache と OPcache の状態）、`app/public/_clear.php`（`target=realpath|opcache|all`）
- [x] 確認: `ln -sfn` で v2 に切り替え → キャッシュクリア → 反映されることを curl で確認

### Step 3: Apache + mod_php 8.5

- [x] `docker/apache/`（Dockerfile, vhost.conf。prefork、`FollowSymLinks`）
- [x] `compose.yaml` に apache サービス追加（profile `apache`、エイリアス `web`）
- [x] 確認: 共通ファイル（`app/`, `docker/php.ini`, `entrypoint.sh`）を変更せずに Step 1〜2 と同じ確認が通る

### Step 4: 観測クライアント

- [x] `client/observe.php`（`--url`, `--interval`, `--until`, `--timeout`, `--out`）
- [x] `compose.yaml` に client サービス追加
- [x] 確認: 観測中に切り替え → JSONL とサマリーが出力される

### Step 5: 検証と README

#### 検証条件

- 手順: 観測開始 → `switch v2` → 手動クリアなしで `--timeout=180` まで観測（全 PID が v2 になれば終了）
- 各設定は単独で変更し、ほかは `.env.example` のデフォルトのまま（組み合わせは試さない）
- 記録: `results/<env>-w<WORKERS>-<設定>.jsonl`、結果は `report/` にまとめてコミット
- 実行は手動（手順は README に記載）

設定:

| ID | 変更内容 |
|---|---|
| default | なし |
| rp-off | `REALPATH_CACHE_TTL=-1` かつ `REALPATH_CACHE_SIZE=0`（realpath cache 無効） |
| oc-off | `OPCACHE_ENABLE=0` |
| oc-novalidate | `OPCACHE_VALIDATE_TIMESTAMPS=0` |
| oc-revalpath | `OPCACHE_REVALIDATE_PATH=1` |

#### realpath cache 単独の検証（先に実施し、結果を踏まえて下の検証パターンを見直す）

OPcache を無効にして、realpath cache の設定だけで切り替えの反映がどう変わるかを確認する。`realpath_cache_ttl=0` が無期限になるという情報は公式に記載がないため、これも確認する。

- 共通条件: `OPCACHE_ENABLE=0`、`WORKERS=1`、`--timeout=180`
- 手順: 再起動 → `/` に1回リクエスト → `_status.php` で ini と `expires` を記録 → 観測開始 → `switch v2` → v2 になるか timeout まで観測 → `_status.php` を記録 → v1 のままなら `_clear.php?target=realpath` 後に `/` が v2 になることを確認
- 記録: `results/ttl-<env>-<TTL>.*`、`results/size-<env>-0.*`。結果は `report/realpath-cache.md`

TTL（`REALPATH_CACHE_SIZE` はデフォルト）:

- [x] nginx / TTL=0
- [x] nginx / TTL=-1
- [x] nginx / TTL=30
- [x] apache / TTL=0
- [x] apache / TTL=-1
- [x] apache / TTL=30

SIZE（`REALPATH_CACHE_TTL` はデフォルトの 120）:

- [x] nginx / SIZE=0
- [x] apache / SIZE=0

- [x] 結果を踏まえて、下の検証パターン（realpath cache + OPcache）を見直す
  - `rp-ttl0` / `rp-size0` は realpath cache 無効の意図だったため、`rp-off`（TTL=-1 かつ SIZE=0）に統合
  - WORKERS=2 は差が出そうなパターンのみ。realpath cache または OPcache を無効にするパターン（`rp-off` / `oc-off`）では行わない

#### OPcache・シンボリックリンク・リンク先 mtime の検証（先に実施し、結果を踏まえて下の検証パターンを再開する）

`validate_timestamps=1` の OPcache が、シンボリックリンクの切り替え時にどのファイルの mtime で更新を判定するかを確認する。

- 共通条件: realpath cache 無効（`REALPATH_CACHE_TTL=-1`、`REALPATH_CACHE_SIZE=0`）、OPcache はデフォルト、`WORKERS=1`、`--timeout=180`
- mtime は起動後・最初のリクエスト前に `docker compose exec <svc> touch ...` で設定する（検証時のみ entrypoint で v1 を1時間前にしていた。検証後に元に戻した）
- 手順: 再起動 → mtime 設定 → `/` に1回リクエスト → `_status.php` を記録 → 観測開始 → `switch v2` →（touch-v1 のみ約10秒後に v1 を `touch`）→ v2 になるか timeout まで観測 → `_status.php` を記録
- 記録: `results/mtime-<env>-<ケース>.*`。結果は `report/opcache-mtime.md`

| ケース | mtime |
|---|---|
| same | v2 を v1 と同じにする（`touch -r`） |
| newer | v2 が v1 より新しい（検証時の entrypoint で設定） |
| older | v2 を v1 より古くする |
| touch-v1 | newer と同じ。切り替え後に v1 を `touch` する |

- [x] nginx / same
- [x] nginx / newer
- [x] nginx / older
- [x] nginx / touch-v1
- [x] apache / same
- [x] apache / newer
- [x] apache / older
- [x] apache / touch-v1

- [x] mtime の検証を踏まえて、下の検証パターンを再度見直す
  - `rp-off` は mtime の検証（newer）、`oc-off` は realpath cache 単独の検証の結果で代え、実施しない
  - WORKERS=2 は realpath cache の期限切れで反映されると予想されるパターンのみ（ずっと v1 のパターンではワーカー差が出ない）
  - 以前に実施した nginx / WORKERS=1 / default は mtime を変えた条件だったため、やり直す

#### 検証パターン

nginx + php-fpm:

- [x] nginx / WORKERS=1 / default
- [x] nginx / WORKERS=1 / oc-novalidate
- [x] nginx / WORKERS=1 / oc-revalpath
- [x] nginx / WORKERS=2 / oc-revalpath

Apache + mod_php:

- [x] apache / WORKERS=1 / default
- [x] apache / WORKERS=1 / oc-novalidate
- [x] apache / WORKERS=1 / oc-revalpath
- [x] apache / WORKERS=2 / default
- [x] apache / WORKERS=2 / oc-revalpath

#### まとめ

- [ ] `report/`（検証ごとのファイルと概要 `report/README.md`）
- [ ] `README.md`（起動方法、実験手順、結果に影響する設定一覧、Windows での注意点）
