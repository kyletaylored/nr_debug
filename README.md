# New Relic Debug (`nr_debug`)

A development utility module for debugging the New Relic + Monolog integration on Drupal 11 / Pantheon.

## Requirements

- Drupal 11
- `drupal/monolog` module
- `newrelic/monolog-enricher` (fork: `kyletaylored/newrelic-monolog-logenricher-php`)
- New Relic PHP agent

## Installation

```bash
drush en nr_debug && drush cr
```

The module appears under **Reports → New Relic Debug** in the admin menu.

## Pages

### Status Report (`/admin/reports/newrelic-debug`)

Displays the current state of the New Relic integration:

| Section | What it shows |
|---|---|
| PHP Extension | Whether the `newrelic` extension is loaded, version, and which NR functions are available |
| INI Settings | All `newrelic.*` directives (license key masked) |
| Linking Metadata | Output of `newrelic_get_linking_metadata()` — empty in CLI, populated with `trace.id`/`span.id` during active web transactions |
| Monolog Configuration | Channel → handler mapping, registered handler services and their classes, active processors |
| Log Files | Last 50 lines of the rotating application log and the NR agent log |

### Test Tools (`/admin/reports/newrelic-debug/test`)

Four independent test actions:

**1. Send via Drupal Logger**
Fires a log record through `\Drupal::logger()` at a configurable level and channel, routing it through the full Monolog handler chain defined in `monolog.services.yml`.

**2. Send Directly to NR Logs API**
Bypasses Monolog entirely and POSTs directly to `log-api.newrelic.com/log/v1` via curl. Attaches linking metadata (`trace.id`, `span.id`, `entity.name`) if available. Shows the HTTP response code and body inline — useful for isolating whether an issue is in the Monolog pipeline or the API connection.

**3. Query NerdGraph**
Queries New Relic's NerdGraph API for recent logs using a User API key (`NRAK-...`) and account ID. Accepts a custom NRQL query and displays results inline. Note: the ingest license key (`NRAL`) cannot be used here — a separate User API key is required.

**4. Probe Monolog Pipeline**
Inspects each handler configured for a given channel without sending real logs:
- Instantiates each handler and reports its class, level threshold, and bubble setting
- Runs a test record through each handler's formatter and displays the output
- Performs a live send test: calls `handle()` and `close()` on each handler, catching and reporting any exceptions
- Runs a direct curl capture against the `new_relic_log` handler with linking metadata applied, showing the exact payload and NR's HTTP response

## Monolog services overview

Handlers active on the `default` channel (configured in `web/sites/default/monolog.services.yml`):

| Handler | Class | Destination |
|---|---|---|
| `new_relic_log` | `NewRelic\Monolog\Enricher\Handler` | NR Logs API (HTTP POST, synchronous) |
| `new_relic_error` | `Monolog\Handler\NewRelicHandler` | NR APM Errors Inbox (`newrelic_notice_error()`) |
| `rotating_file` | `Monolog\Handler\RotatingFileHandler` | `/logs/php/application-YYYY-MM-DD.log` |

## Logs in Context

Logs are enriched with linking metadata (`trace.id`, `span.id`, `entity.guid`) by the `new_relic_processor` via `newrelic_get_linking_metadata()`. This metadata is only populated during active web transactions — CLI/drush logs will have empty context. Distributed tracing must be enabled for `trace.id` and `span.id` to appear:

```ini
; web/.user.ini
newrelic.distributed_tracing_enabled = true
```

## Troubleshooting

**Logs appear in the rotating file but not in New Relic**
Run the pipeline probe and check the "Direct Curl Capture" section. A `202` response confirms the API path is working. If the probe sends successfully but Drupal logger calls don't, the issue is in the Monolog handler chain — check the handler's `write()` method wraps the payload in `[{"logs":[...]}]`.

**`newrelic-context` is empty (`[]`)**
`newrelic_get_linking_metadata()` returned an empty array. This is expected in CLI/drush contexts and when distributed tracing is disabled. Enable distributed tracing via `.user.ini`.

**Rotating file stopped receiving logs**
Check that no handler in the chain has `bubble: false` before `rotating_file` in the handler order, as this will stop propagation.
