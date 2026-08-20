# Bot-protection test harness

Curl-based regression tests for the PHP security layers (CSRF, dwell time,
honeypot, PoW + sub-reasons, replay, grace window, rate limits) and the
shared-cache invariants. Each scenario asserts the HTTP response AND the
reason line written to `tmp/bot_protection_logs/<today>.yn-botprotection.log`.

## Against the regular local stack

```sh
BASE_URL=http://localhost PAGE_PATH=/some-page-with-a-form FORM_ID=<formId> \
  ./tests/botprotection/run.sh
```

- `RUN_HEAVY=1` also runs the 21-request rejected-attempt lockout.
- `LOG_DIR=` (empty) skips log assertions when tmp/ is not reachable locally.
- `HOST_HEADER=localhost` dodges the .htaccess https redirect when the
  instance runs on a non-80 port.

## Fully isolated (no backend needed)

The harness only needs the PHP layers, which all run before the ynfinite API
call - a throwaway client container + MySQL is enough:

```sh
# 1. minimal env (backend hosts stay unreachable on purpose)
cat > /tmp/bottest.env << 'ENV'
DEV=true
DEBUG_TEMPLATES=false
ENABLE_APCU=false
STATIC_PAGES=true
STATIC_REQUESTS=true
YN_API_KEY=local-test-api-key
YN_SERVICE_ID=local-test-service
DB_HOST=bottest-db
DB_USER=test
DB_NAME=test
DB_PASSWORD=test
ENV

# 2. throwaway db + client (image from the serverstack compose build)
docker network create bottest
docker run -d --name bottest-db --network bottest \
  -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=test \
  -e MYSQL_USER=test -e MYSQL_PASSWORD=test mysql/mysql-server:8.0
docker run -d --name ynclient-bottest --network bottest -p 8089:80 \
  -v "$PWD:/var/www" -v /tmp/bottest.env:/var/www/.env:ro \
  serverstack-ynfinite-client

# 3. pre-warm a synthetic page cache so GET / serves from the index.php fast
#    path (mints cookies without needing the backend to render a page)
KEY=PAGE_$(python3 -c "import hashlib;print(hashlib.md5(b'http://localhost/').hexdigest())")
mkdir -p tmp/static_pages/$KEY
printf '%s' '<!doctype html><html><body><form data-ynform="true" method="post" data-ynformid="test-form" action="/yn-form/send"><input type="hidden" name="yn_form_method_token" value="x"><button type="submit">Send</button></form></body></html>' \
  > tmp/static_pages/$KEY/loggedout.html

# 4. run
BASE_URL=http://localhost:8089 HOST_HEADER=localhost FORM_ID=test-form RUN_HEAVY=1 \
  ./tests/botprotection/run.sh

# 5. cleanup
docker rm -f ynclient-bottest bottest-db && docker network rm bottest
rm -rf tmp/static_pages/$KEY
```

## Grace-window (strict mode) scenarios

The 1h deploy grace window is keyed to `StaticCache::buildStamp()` (mtime of
`public/assets/vendor/ynfinite/js/build-version.txt`, or `app.min.js`,
whichever is newer). To test STRICT behavior locally, age the stamp:

```sh
touch -t $(date -v-2H +%Y%m%d%H%M) public/assets/vendor/ynfinite/js/app.min.js
# ... POST without yn_form_method_token → rejected + logged 'method_token_missing'
# ... POST without the ynfinite-form-ts cookie → rejected + 'dwell_cookie_missing'
# restore afterwards (or rebuild in Prepros)
```

Inside the window the same requests are allowed and logged with a `_grace`
suffix.
