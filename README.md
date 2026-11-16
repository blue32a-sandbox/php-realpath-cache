# php-realpath-cache

PHP の realpath cache と OPcache が、シンボリックリンクによるリリースの切り替えにどう影響するかを検証する実験環境です。

`public/index.php -> ../private/vN/index.php` のシンボリックリンクを v1 から v2 に切り替え、その切り替えが PHP の実行結果にいつ反映されるかを観測します。

検証結果は [report/](report/README.md) にまとめています。

## 構成

| パス | 内容 |
|---|---|
| `app/public/` | ドキュメントルート。`index.php` は起動時に作るシンボリックリンク。`_status.php` と `_clear.php` は制御用 |
| `app/private/v1/`、`app/private/v2/` | 切り替え先。バージョン番号だけを持つ |
| `app/lib/` | 共通処理（観測用の JSON を返す）。切り替えの対象外 |
| `client/observe.php` | 観測クライアント |
| `docker/` | 環境ごとの Dockerfile と設定、`php.ini`、`entrypoint.sh`、`switch.sh` |
| `results/` | 観測の記録（コミット対象外） |

実行環境は次の2つです。Compose profiles で、1つずつ起動します。

| profile / サービス | 構成 |
|---|---|
| `nginx` | nginx + php-fpm（`pm=static`）、PHP 8.5 |
| `apache` | Apache（prefork）+ mod_php、PHP 8.5 |

## セットアップ（クローンした直後に1回）

```sh
cp .env.example .env
docker compose --profile nginx --profile apache build
```

- `.env` は必須です。ない場合、コンテナを起動できません
- `docker/` 配下（Dockerfile、設定ファイル、`entrypoint.sh`、`switch.sh`）を変更したときも、ビルドし直してください

## 実験手順（実験のたびに）

コマンド中の `<env>` は、使う環境に合わせて読み替えてください。

| 環境 | `<env>` |
|---|---|
| nginx + php-fpm | `nginx` |
| Apache + mod_php | `apache` |

0. 使わない環境のコンテナが動いていれば止める。ここでの `<env>` は、使わないほうの環境

   ```sh
   docker compose --profile <env> down
   ```

   2つの環境は同じポート（`127.0.0.1:8080`）を使うため、同時には起動できません

1. `.env` でワーカー数とキャッシュ設定を決める（[パラメータ](#パラメータenv)）
2. コンテナを起動する。動いている場合は作り直す

   ```sh
   docker compose --profile <env> up -d --force-recreate <env>
   ```

   起動のたびに `app/` をコンテナ内の `/srv/app` にコピーし、`public/index.php -> v1` を作ります。そのため、作り直すと v1 に戻り、キャッシュも空になります

3. 約3秒待ってから、`/` に1回リクエストする

   ```sh
   curl http://127.0.0.1:8080/
   ```

   起動直後のファイルは、`opcache.file_update_protection`（2秒）の間は OPcache に載りません

4. 観測を開始する

   ```sh
   docker compose run --rm client --until=v2 --timeout=180 --out=results/example.jsonl
   ```

5. 別のターミナルで、シンボリックリンクを切り替える

   ```sh
   docker compose exec <env> switch v2
   ```

6. 全ワーカーが v2 を返すか、timeout になると観測が終わる。記録は `results/` に残る

必要に応じて、観測の前後で `_status.php` を記録したり、`_clear.php` でキャッシュをクリアしたりします。

実験を終えるときは、コンテナを止めます。

```sh
docker compose --profile <env> down
```

### エンドポイント

| URL | 内容 |
|---|---|
| `/` | バージョン、PID、SAPI、実行したファイル、キャッシュ設定を JSON で返す |
| `/_status.php` | このリクエストを処理したワーカーの realpath cache のエントリと、OPcache の状態（hits、misses、キャッシュしたスクリプト） |
| `/_clear.php?target=realpath\|opcache\|all` | キャッシュをクリアする。realpath cache は処理したワーカーの分だけ、OPcache は共有メモリ全体が対象 |

realpath cache はワーカーごとに、OPcache は Web サーバーの共有メモリにあります。そのため、CLI（`docker compose exec ... php`）からは参照もクリアもできません。

### 観測クライアント

```sh
docker compose run --rm client [--url=URL] [--interval=秒] [--until=VERSION] [--timeout=秒] [--out=PATH]
```

| オプション | デフォルト | 内容 |
|---|---|---|
| `--url` | `http://web/` | 観測する URL（起動中の環境に、エイリアス `web` で接続する） |
| `--interval` | `0.5` | リクエストの間隔（秒） |
| `--until` | なし | `.env` の `WORKERS` 個の PID が、すべてこのバージョンを返したら終了する |
| `--timeout` | `60` | 観測の上限時間（秒） |
| `--out` | `results/YYYYmmdd-HHMMSS.jsonl` | 記録の出力先。サマリーは `*.summary.json` |

- リクエストは毎回新しい接続で行います。Keep-Alive で同じワーカーに固定されないようにするためです
- JSONL には、リクエストごとに `client_time`、`elapsed`、`status`、`version`、`pid` を記録します
- サマリーには、終了理由（`switched` / `timeout`）と、PID ごとに最初に `--until` のバージョンを返した時刻（`switched_at`）を記録します

## パラメータ（`.env`）

`.env` の値は、起動時に PHP の ini と Web サーバーの設定に展開されます。変更したら、`up -d --force-recreate` でコンテナを作り直してください。

| `.env` | 展開先 | `.env.example` の値 |
|---|---|---|
| `WORKERS` | php-fpm の `pm.max_children` / Apache prefork の子プロセス数 | `2` |
| `REALPATH_CACHE_SIZE` | `realpath_cache_size` | `4096K` |
| `REALPATH_CACHE_TTL` | `realpath_cache_ttl` | `120` |
| `OPCACHE_ENABLE` | `opcache.enable` | `1` |
| `OPCACHE_VALIDATE_TIMESTAMPS` | `opcache.validate_timestamps` | `1` |
| `OPCACHE_REVALIDATE_FREQ` | `opcache.revalidate_freq` | `2` |
| `OPCACHE_REVALIDATE_PATH` | `opcache.revalidate_path` | `0` |
| `OPCACHE_FILE_UPDATE_PROTECTION` | `opcache.file_update_protection` | `2` |

`.env.example` の値は、`WORKERS` を除いて PHP のデフォルト値です。各パラメータを変えたときの結果は [report/](report/README.md) を参照してください。

## 注意点

- **シンボリックリンクの切り替えはアトミックではありません**。`switch` の中身は `ln -sfn`（unlink してから symlink する）です
- **`app/` を変更したら、コンテナを作り直してください**。ソースは起動時にコピーされるので、そのままでは反映されません
- **mtime**: v1 と v2 は起動時に同時にコピーされるため、mtime がほぼ同じです（リンク先の mtime の影響は [report/opcache-mtime.md](report/opcache-mtime.md) を参照）

### Windows（Docker Desktop）での注意点

- **シンボリックリンクはコンテナ内に作ります**。Windows の bind mount 上でシンボリックリンクを扱わないためです。`app/` は読み取り専用でマウントし、起動時にコンテナ内へコピーします
- **Git Bash のパス変換に注意してください**。Git Bash から `docker compose exec <svc> <コマンド> /srv/...` のようにコンテナ内のパスを渡すと、Windows のパス（`C:/Program Files/Git/srv/...`）に変換されてしまいます
  - 切り替えは、コンテナ内のスクリプト `switch` を使えば影響を受けません
  - それ以外のコマンドでコンテナ内のパスを渡すときは、`MSYS_NO_PATHCONV=1` を付けてください
- **改行コードは `.gitattributes` で LF に固定しています**。シェルスクリプトや設定ファイルが CRLF になると、コンテナ内で動きません
