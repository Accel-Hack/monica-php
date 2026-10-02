#!/usr/bin/env bash
# Laravel に入れたときの振る舞い。引数は ah-monica/monica を path repository で
# require 済みの Laravel アプリで、fixture の route と command を足してから回す。
#
#   bash tests/laravel.sh /path/to/laravel-app
#
# 見るのは、Laravel の handler を通る例外が 1 回ずつ届くこと、$dontReport が
# 効くこと、DSN が無いときに何もしないこと、queue worker が job の合間に送ること。
set -euo pipefail

app="$(cd "$1" && pwd)"
sdk="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
stub_port=18081
app_port=18082
export MONICA_STUB_LOG="$work/stub.log"
export MONICA_STUB_FILE="$work/none"
dsn="http://msk_test@127.0.0.1:$stub_port/"

if ! grep -q 'monica:fixture' "$app/routes/console.php"; then
  # PHP 7.4 (Laravel 8) でも読めるように、arrow function と throw 式は使わない。
  cat >> "$app/routes/console.php" <<'PHP'

Illuminate\Support\Facades\Artisan::command('monica:fixture-throw', function () {
    throw new RuntimeException('thrown from command');
});
Illuminate\Support\Facades\Artisan::command('monica:fixture-report', function () {
    report(new LogicException('reported'));
});
Illuminate\Support\Facades\Artisan::command('monica:fixture-warning', function () {
    file_get_contents('/monica-missing');
});
Illuminate\Support\Facades\Artisan::command('monica:fixture-fatal', function () {
    str_repeat('x', PHP_INT_MAX);
});
Illuminate\Support\Facades\Artisan::command('monica:fixture-worker', function () {
    report(new LogicException('reported in a worker'));
    $before = (string) file_get_contents(getenv('MONICA_STUB_LOG'));
    event(new Illuminate\Queue\Events\Looping('sync', 'default'));
    $after = (string) file_get_contents(getenv('MONICA_STUB_LOG'));
    $this->line(strpos($before, 'worker') === false && strpos($after, 'worker') !== false
        ? 'flushed on looping'
        : 'not flushed on looping');
});
PHP
  cat >> "$app/routes/web.php" <<'PHP'

Illuminate\Support\Facades\Route::get('/monica-fixture/throw', function () {
    throw new RuntimeException('thrown from route');
});
Illuminate\Support\Facades\Route::get('/monica-fixture/not-found', function () {
    abort(404);
});
PHP
fi

php -S "127.0.0.1:$stub_port" "$sdk/tests/ingest-stub.php" >"$work/stub.out" 2>&1 &
stub_pid=$!
(cd "$app" && MONICA_DSN="$dsn" exec php -S "127.0.0.1:$app_port" -t public public/index.php >"$work/app.out" 2>&1) &
app_pid=$!
trap 'kill $stub_pid $app_pid 2>/dev/null || true; rm -rf "$work"' EXIT
sleep 1

failures=0

# 届いた event の message を " | " でつないで返す。client_report は message を
# 持たないので数えない（稼働確認は tests/run.php が見る）。
messages() {
  php -r '$out = [];
    foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $line) {
      foreach (json_decode($line, true)["messages"] as $m) {
        if ($m !== null) { $out[] = $m; }
      }
    }
    echo implode(" | ", $out);' "$MONICA_STUB_LOG"
}

# expect <名前> <届いた message 全体に当てる ERE> <実行するコマンド...>
expect() {
  local name="$1" pattern="$2"
  shift 2
  : >"$MONICA_STUB_LOG"
  "$@" >"$work/out" 2>&1 || true
  sleep 0.3
  local got
  got="$(messages)"
  if printf '%s\n' "$got" | grep -Eqx "$pattern"; then
    echo "ok   $name"
  else
    echo "FAIL $name: got [$got], want /$pattern/"
    sed 's/^/     | /' "$work/out"
    failures=$((failures + 1))
  fi
}

artisan() { (cd "$app" && MONICA_DSN="$dsn" php artisan "$@"); }
get() { curl -s -o /dev/null "http://127.0.0.1:$app_port$1"; }

expect 'command の例外が 1 回届く' 'thrown from command' artisan monica:fixture-throw
expect 'report() が届く' 'reported' artisan monica:fixture-report
expect 'PHP warning が 1 回だけ届く' 'file_get_contents\(/monica-missing\)[^|]*' artisan monica:fixture-warning
expect 'fatal error が 1 回だけ届く' '[^|]+' artisan monica:fixture-fatal
expect 'route の例外が届く' 'thrown from route' get /monica-fixture/throw
expect '$dontReport の例外は送らない' '' get /monica-fixture/not-found
expect 'DSN が無ければ何もしない' '' bash -c "cd '$app' && env -u MONICA_DSN php artisan monica:fixture-throw"
if ! grep -q 'thrown from command' "$work/out" || grep -q 'must be called first' "$work/out"; then
  echo "FAIL DSN が無いときの出力が Laravel のものではない"
  sed 's/^/     | /' "$work/out"
  failures=$((failures + 1))
fi
expect 'worker は Looping で送る' 'reported in a worker' artisan monica:fixture-worker
if ! grep -qx 'flushed on looping' "$work/out"; then
  echo "FAIL worker が Looping の前に送ったか、Looping で送らなかった"
  sed 's/^/     | /' "$work/out"
  failures=$((failures + 1))
fi

if [ "$failures" -ne 0 ]; then
  echo "$failures failure(s)"
  exit 1
fi
echo "laravel: all passed"
