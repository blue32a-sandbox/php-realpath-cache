#!/bin/sh
# ソースを /srv/app へコピーし、public/index.php -> v1 を作成する。
# （Windows の bind mount 上で symlink を扱わないため、コンテナ内で作る）
set -eu

rm -rf /srv/app
mkdir -p /srv/app
cp -R /src/app/. /srv/app/
ln -sfn ../private/v1/index.php /srv/app/public/index.php

exec "$@"
