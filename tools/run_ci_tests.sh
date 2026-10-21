#!/usr/bin/env bash
set -euo pipefail
mkdir -p .runtime/wordpress .runtime/browsers
curl -fsSL https://wordpress.org/latest.zip -o .runtime/wordpress.zip
unzip -q .runtime/wordpress.zip -d .runtime
curl -fsSL https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip -o .runtime/woocommerce.zip
unzip -q .runtime/woocommerce.zip -d .runtime/wordpress/wp-content/plugins
cp -r mik-shop .runtime/wordpress/wp-content/plugins/mik-shop
mkdir -p .runtime/wordpress/wp-content/mu-plugins/templates
cp tests/fixture-mu.php .runtime/wordpress/wp-content/mu-plugins/mik-test.php
cp tests/fixture-template.php .runtime/wordpress/wp-content/mu-plugins/templates/mik-test-template.php
curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o .runtime/wp-cli.phar
wp=(php .runtime/wp-cli.phar --allow-root --path=.runtime/wordpress)
"${wp[@]}" config create --dbname=mik_test --dbuser=root --dbpass="$MIK_DB_PASSWORD" --dbhost=127.0.0.1:3306 --skip-check
"${wp[@]}" config set WP_HOME http://127.0.0.1:8095
"${wp[@]}" config set WP_SITEURL http://127.0.0.1:8095
"${wp[@]}" config set WP_DEBUG true --raw
"${wp[@]}" config set WP_DEBUG_LOG true --raw
"${wp[@]}" config set WP_DEBUG_DISPLAY false --raw
"${wp[@]}" core install --url=http://127.0.0.1:8095 --title='MIK Tests' --admin_user=mik_test_admin --admin_password=Local-Test-Only-Password-2026 --admin_email=admin@example.test --skip-email
"${wp[@]}" plugin activate woocommerce mik-shop
"${wp[@]}" rewrite structure '/%postname%/'
"${wp[@]}" eval-file tests/wp-fixtures.php
find mik-shop -name '*.php' -print0 | xargs -0 -n1 php -l
node --check mik-shop/assets/js/store.js
node --check mik-shop/assets/js/admin.js
PHP_CLI_SERVER_WORKERS=3 php -S 127.0.0.1:8095 -t .runtime/wordpress tests/router.php >.runtime/http.log 2>&1 &
server=$!
trap 'kill "$server" 2>/dev/null || true' EXIT
for n in $(seq 1 40); do if curl -fsS http://127.0.0.1:8095/wp-json/mik/v1/hours >/dev/null; then break; fi; sleep .5; done
python3 tests/integration.py
python3 tests/verify_xls.py
PLAYWRIGHT_BROWSERS_PATH="$PWD/.runtime/browsers" node tests/browser.mjs
"${wp[@]}" eval 'if (count_users()["total_users"] !== 1) { WP_CLI::error("Customer account leaked into WordPress users"); } WP_CLI::success("Custom registrations did not create WordPress users");'
python3 tools/build.py
