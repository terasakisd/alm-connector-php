#!/bin/sh
# ALM コネクタ PHP版 — 自動テスト（docker の php:8.2-cli で実行）
# 使い方:  sh tests/run.sh
# ローカルに php があるなら:  php tests/test.php
set -e
cd "$(dirname "$0")/.."
IMAGE="${ALM_TEST_PHP_IMAGE:-php:8.2-cli}"
exec docker run --rm -v "$PWD":/work -w /work "$IMAGE" php -d display_errors=1 tests/test.php
