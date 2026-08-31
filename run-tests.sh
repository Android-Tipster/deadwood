#!/usr/bin/env sh
# Runs the Deadwood suite. Pass --live to also exercise api.wordpress.org.
#
# The extension flags exist because a bare PHP CLI often ships without curl and
# openssl enabled, and the live checks need HTTPS. They are harmless when the
# extensions are already on.
PHP="${PHP:-php}"
EXT_DIR="${PHP_EXT_DIR:-}"

if [ -n "$EXT_DIR" ]; then
  exec "$PHP" -d extension_dir="$EXT_DIR" -d extension=php_openssl.dll -d extension=php_curl.dll tests/run-tests.php "$@"
fi

exec "$PHP" tests/run-tests.php "$@"
