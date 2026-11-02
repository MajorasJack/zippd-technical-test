# Order Ingest Service

A small Laravel API that takes delivery orders from partners, geocodes their addresses in the background, and tells the partner about each status change by webhook.

- `POST /api/orders` creates an order. It's validated, saved in one transaction, and idempotent on the partner reference or an `Idempotency-Key` header.
- Each address is geocoded by its own queued job, which retries with backoff. Jobs that keep failing are recorded and alerted on.
- `PATCH /api/orders/{id}/status` moves an order forward through `received`, `out-for-delivery` and `delivered`.
- Each status change sends a signed webhook to the partner from a queued job, so a slow or down partner never blocks the API.

Built with Laravel 13, MySQL 8.4 and Pest, running in Laravel Sail.

## How to run it

You need Docker running, plus PHP 8.3+ and Composer on your machine for the first step.

```bash
git clone git@github.com:MajorasJack/zippd-technical-test.git && cd zippd-technical-test
composer setup
```

`composer setup` copies `.env.example` to `.env` if there isn't one, installs the dependencies, and starts the containers with `sail up -d --wait`, which returns once MySQL is ready. It then generates the app key and runs the migrations. The first run builds the Docker image, so it takes a few minutes. Run it once: running it again generates a new app key.

Docker provides the runtime, meaning PHP, MySQL and the queue worker. The one-off steps stay in a setup script rather than in the containers' start-up, because running migrations every time a container boots is a pattern to avoid in production.

Without PHP on your machine, install the dependencies with Sail's Composer image first, then run the remaining steps from `composer.json`'s `setup` script by hand:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs
```

The app is on `http://localhost:8080`, and MySQL is forwarded to port `3307`. Change `APP_PORT` and `FORWARD_DB_PORT` in `.env` if those ports are taken.

**Set the webhook target.** In `.env`, set `PARTNER_WEBHOOK_URL` to somewhere that can receive a POST, such as a https://webhook.site URL. `PARTNER_WEBHOOK_SECRET` already has a development value. Until the URL is set, every webhook fails, and you'll see it in the alerts log.

**Create a partner.** This prints the partner's API token. It's shown once, so keep it.

```bash
./vendor/bin/sail artisan partner:create "Acme Couriers"
```

**The queue worker starts with the app.** `sail up` also starts a `queue` container running `php artisan queue:listen`, so geocoding and webhooks happen without a separate terminal. `queue:listen` boots the app fresh for each job, so code and `.env` changes take effect without a restart. In production I'd run `queue:work` under a process supervisor instead, as it's faster. Follow the worker's output with:

```bash
./vendor/bin/sail logs -f queue
```

**Run the tests.** They use a separate `testing` database, which Sail's MySQL container creates on first start.

```bash
./vendor/bin/sail test
```

Jobs that fail permanently are written to `storage/logs/alerts.log`, and `./vendor/bin/sail artisan queue:failed` lists them.

## The API

Every request needs the partner's token as `Authorization: Bearer <token>`. A missing or bad token gets a `401`.

### Create an order

```bash
curl -X POST http://localhost:8080/api/orders \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 7b0c9f3e-2d4a-4b8e-9c1f-5a6d7e8f9012" \
  -d '{
    "partner_reference": "ACME-1001",
    "collection_address": {"line_1": "1 Mill Street", "city": "Manchester", "postcode": "M1 1AA", "country_code": "GB"},
    "delivery_address": {"line_1": "2 Deansgate", "line_2": "Flat 4", "city": "Manchester", "postcode": "M3 2BB", "country_code": "GB"},
    "parcels": [{"weight_grams": 1200}, {"weight_grams": 800}]
  }'
```

| Response | When |
|---|---|
| `201` with the order | A new order was created. |
| `200` with the existing order | The partner reference or the `Idempotency-Key` matches an order this partner already sent. Nothing is written. |
| `409` | The reference matches one order and the key matches a different one, so it can't tell which one the partner means. |
| `422` | The body is invalid. The response lists the errors. |

The `Idempotency-Key` header is optional. Each address has `line_1`, `city`, `postcode` and a 2-letter `country_code`, plus an optional `line_2`. Each address line is at most 255 characters. An order has between 1 and 50 parcels, and each parcel needs a `weight_grams` between 1 and 50,000.

### Change an order's status

```bash
curl -X PATCH http://localhost:8080/api/orders/1/status \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"status": "out-for-delivery"}'
```

| Response | When |
|---|---|
| `200` with the order | The status moved forward, or the order already had that status, in which case nothing changes. |
| `422` | The move goes backwards (e.g. `delivered` to `received`), or the status isn't a known one. |
| `404` | The order doesn't exist, or it belongs to another partner. |

### The webhook partners receive

Every status change made through the PATCH endpoint sends one POST to `PARTNER_WEBHOOK_URL`:

```json
{
  "event_id": 7,
  "order_id": 6,
  "partner_reference": "ACME-1001",
  "from_status": "received",
  "to_status": "out-for-delivery",
  "changed_at": "2026-11-02T14:05:12+00:00"
}
```

It carries two headers:

- `X-Event-Id`, the same as `event_id`. Partners should skip any event ID they've already processed (see "At-least-once delivery" below).
- `X-Signature`, an HMAC-SHA256 of the raw request body using the shared secret. Partners check it like this:

```php
hash_equals(hash_hmac('sha256', $rawBody, $sharedSecret), $request->header('X-Signature'));
```

## Key decisions

**One Sanctum token per partner.** Orders belong to the partner that sent them, partner references are unique per partner rather than across everyone, and a partner can only change its own orders. Another partner's order answers `404` rather than `403`, so no one can tell which order IDs exist.

**The database is the idempotency guarantee.** Unique indexes on `(partner_id, partner_reference)` and `(partner_id, idempotency_key)` make a duplicate order impossible, whatever the application code does. The lookup before the insert gives a duplicate a clean `200`. If two identical requests both get past the lookup at the same moment, the second insert hits the unique index, and the API catches that and returns the first request's order. A duplicate gets the existing order back, not an error: a partner retrying after a timeout needs the order ID, and refusing would turn a safe retry into a failure. The partner reference identifies the order and the key identifies the request, so either one matching counts. The lookup is two exact queries, one per unique index, rather than one query with an `OR`. MySQL can't use both composite indexes through an `OR`, so it falls back to the shared `partner_id` prefix and reads every order the partner has ever sent, on every request.

**One transaction, and jobs queued after it commits.** The order, its parcels and the initial `received` status are written together, so a failure saves nothing. The geocoding jobs are dispatched with `afterCommit()`, so a rolled-back order never has jobs queued for it, and the request does nothing slow.

**One geocoding job per address.** The two lookups run on separate workers at the same time, and a failure only retries the address that failed. A job skips an address that already has coordinates, so running it twice does no harm. It retries 5 times, waiting 10, 30, 60 and then 120 seconds. That covers a few minutes of outage without holding a worker.

**Forward-only statuses.** An order can move to any later status, and it can't go backwards. Skipping is allowed because status updates come from scans, and scans get missed. If a parcel goes on the van without its `out-for-delivery` scan, a strict one-step rule would refuse the `delivered` update, and the order would be stuck at `received` even though the parcel arrived. Losing a real delivery is worse than accepting a skipped step. The only invalid move the brief gives is going backwards, from `delivered` to `received`. Asking for the status it already has returns `200` and does nothing, so a partner retrying a PATCH doesn't get an error.

**Compare-and-set instead of row locks.** The status change is `UPDATE orders SET status = ? WHERE id = ? AND status = <the status this request read>`. When two requests arrive together, the database lets only one of them change the row. The other sees zero rows changed and starts again from the order's new state. Statuses only move forward through three values, so this always ends. A unique index on `(order_id, to_status)` backs it up: an order can enter each status once, so the database refuses a duplicate status row even if the application logic were wrong.

**Jobs are queued after the commit, with Redis in mind.** Every dispatch uses `afterCommit()`, so a change that rolls back never queues a job, and a worker can never pick up a job before its row exists. That's the correct choice on Redis, which is the queue I'd use in production. On the `database` driver used here, dispatching *inside* the transaction would also make the job atomic with the status change, a free outbox, and close the small gap where a crash right after the commit loses that one webhook. Checking the queue driver at runtime doesn't belong in the domain code. So if the queue had to stay on MySQL in production, I'd drop `afterCommit()` there instead.

**The status-change row is the webhook record.** Each `order_status_changes` row has `webhook_delivered_at` and `webhook_failed_at`, so there's no separate outbox table. The webhook job checks `webhook_delivered_at` before sending, so a change that has been delivered is never sent again.

**At-least-once delivery.** Exactly-once delivery over a network isn't possible. If the partner processes a webhook but the response is lost (a timeout is the usual cause), the job can't tell that apart from a webhook that never arrived. The choice is between never resending, which risks losing updates, and resending, which risks duplicates. I chose to resend, and give partners `X-Event-Id` to drop duplicates. An update known to be delivered is never sent again. The job retries 5 times, waiting 10 seconds, 30 seconds, 1 minute and then 5 minutes, with a 5-second timeout per attempt.

**Signed webhooks.** `X-Signature` lets a partner check a webhook came from this service. The body is encoded once and those exact bytes are both signed and sent, so the signature always matches what arrives.

**One place for alerts.** A single `Queue::failing()` listener writes every job that fails permanently to the `alerts` log channel (`storage/logs/alerts.log`). Each job's `failed()` also marks its own record (`geocode_failed_at`, `webhook_failed_at`), so you can see the failure on the order itself. The alerts channel stands in for a real alerting service: in production it would ship to Logstash, Datadog or similar, where the alert rules live. Each alert carries the job's class and its UUID, which is the ID `queue:failed` and `queue:retry` use, and never customer addresses.

**Sail with MySQL.** Development and tests run on the same database as production would, so nothing relies on database behaviour that can't be checked locally.

## Assumptions

- **Addresses** are an object with `line_1`, optional `line_2`, `city`, `postcode` and a 2-letter `country_code`. The brief doesn't define one. Unknown keys are refused, because the address is stored as-is.
- **Weights** are whole grams. Integers add up exactly, so there are no floating-point surprises.
- **50,000 g per parcel is a placeholder** for the partner's real courier limits. It lives in one validation rule, so it's easy to change per partner later.
- **Parcels have no identity of their own.** The brief asks for parcels with weights, so they're part of the order. Tracking parcels separately would need a parcel ID or a partner's own parcel reference.
- **Up to 50 parcels per order**, and address lines up to 255 characters, so one request can't hold a long transaction open on the database.
- **`geocode_failed_at` covers both addresses.** The address still without a latitude is the one that failed.
- **One webhook URL and secret** are shared by every partner, as the brief asks for a URL set in config.
- **No webhook for the initial `received` status.** The partner already knows it sent the order. "Every status change" is read as every change made through the PATCH endpoint.
- **Webhooks for one order can arrive out of order.** Each change has its own job, so after retries a partner can receive `delivered` before `out-for-delivery`. The partner's own systems may reorder updates too. Partners should order updates by `changed_at`.
- **A late update for an earlier status is refused, not backfilled.** If `out-for-delivery` arrives after `delivered`, it gets a `422`. The PATCH carries no timestamp from the partner, so a late update can't be told apart from a mistaken one.

## Testing

`./vendor/bin/sail test` runs 42 tests against MySQL, with 97% line coverage. Alongside input validation and its limits, the exact response shape partners receive, and the stub geocoder itself, they cover the brief's four required areas:

- **Idempotency:** a repeated reference, a repeated key, two requests racing past the lookup, the same reference from two partners, and the `409`.
- **Rollback:** a parcel that fails to save leaves no order, parcel, status change or queued job.
- **Invalid transitions:** backwards moves, unknown statuses, a no-op repeat, and another partner's order.
- **Webhook retries:** a `500` is retried, a retry after a success sends nothing, and failures are recorded.

The test suite runs one test at a time, so it can't run two requests at exactly the same moment. The two race tests simulate the losing request directly instead. One triggers a competing order from inside the first request's lookup. The other passes in an order whose status is already out of date.

## What's missing

Nothing planned is missing. The items below are deliberate omissions, and "With more time" lists where they would go next.

## With more time

These are roughly in the order they'd matter under load, at around 100k orders a day.

- **Move the queue off MySQL, to Redis.** The `database` driver polls, inserts and deletes about four jobs per order on the same primary that takes the API's writes. It's the first thing to strain as volume grows.
- **Separate queues** for geocoding and webhooks. While a partner's endpoint is down, each webhook attempt can hold a worker for up to 5 seconds, five times over, which starves geocoding on a shared queue.
- **Throttle Sanctum's `last_used_at` writes.** Sanctum updates the token's row on every request, which is a hot single-row write per partner.
- **Retry a deadlock on duplicate inserts.** With three or more identical requests at once, InnoDB can report a deadlock instead of a duplicate key, and that request gets a `500` until the partner retries. Catching the deadlock and re-running the lookup would handle it like a duplicate.
- **Data retention.** `order_status_changes` grows by about three rows per order, and nothing prunes `failed_jobs`. Both need archiving or pruning.
- **Reject a reused reference or key with a different body** with a `409`. Today a duplicate returns the original order whatever the body says. The only `409` is a reference and key that point at two different orders.
- **Per-partner webhook URLs and secrets,** stored on the partner, with the secret encrypted.
- **Honour `Retry-After`** from the geocoding service and from partners, instead of always using the fixed backoff. Releasing a job for that long counts as an attempt, so the tries would need adjusting.
- **Stop retrying a `4xx`** that can never succeed, such as a `400` or `410`, and fail the job straight away.
- **A time limit on signatures** (a signed timestamp), so a captured webhook can't be replayed later.
- **A sweeper** that finds status changes whose webhook was never queued or delivered, for example after a worker crash, and queues them again. It would need an index covering `webhook_delivered_at`, `webhook_failed_at` and `created_at`.
- **Deduplicate alerts,** so an hour-long partner outage raises one alert rather than thousands. Check too that a real geocoder's exceptions don't carry the address, for example in a request URL.
- **A cache in front of the idempotency lookup** at high volume, keeping keys for about 24 hours, with the unique indexes staying as the real guarantee.
- **Rate limiting** per partner.
- **Leave `PARTNER_WEBHOOK_SECRET` blank in `.env.example`,** so a production install can't inherit the development value.

## What I'd monitor and alert on in production

- **The alerts channel**, shipped to Logstash or similar, with an alert on every critical entry. These are jobs that failed after all their retries.
- **`failed_jobs` growth**, and the number of orders with `geocode_failed_at` set.
- **Undelivered webhooks:** status changes with `webhook_failed_at` set, or with neither timestamp set after a few minutes, which would point to stuck workers or a missing job.
- **Queue depth and wait time**, so slow workers are spotted before partners notice.
- **Slow queries and rows examined** on the `orders` lookups, so a query that stops using its index shows up before it hurts the primary.
- **Partner webhook response times and error rates**, per partner.
- **API error rates and latency:** `401`s (bad tokens), `409`s (partners reusing keys incorrectly) and `5xx`s.
