#!/bin/sh
# public/index.php のリンク先を private/<version>/index.php に切り替える。
# 使い方: docker compose exec <svc> switch v2
# ln -sfn は unlink + symlink の2段階で、アトミックではない。
set -eu

version="${1:?usage: switch <version>}"
if [ ! -f "/srv/app/private/$version/index.php" ]; then
    echo "not found: /srv/app/private/$version/index.php" >&2
    exit 1
fi

ln -sfn "../private/$version/index.php" /srv/app/public/index.php
ls -l /srv/app/public/index.php
