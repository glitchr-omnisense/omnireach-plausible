# omnireach/plausible

Plausible for [omnisense/omnireach](https://github.com/glitchr-omnisense/omnireach): visitors,
visits, pageviews, bounce rate and visit duration of a site, by page, entry page, source, country,
device or day, combined as needed, through Plausible's Stats API v2
(<https://plausible.io/docs/stats-api>) - plausible.io or a self-hosted Community Edition. Every
report is `POST {url}/api/v2/query` with a bearer key; `{url}` is `https://plausible.io` by
default. There is no sandbox. No PHP client of the Stats API v2 was found to reuse.

## Installation

```sh
composer require omnireach/plausible symfony/http-client
```

Requires PHP 8.2, `omnisense/omnireach` (1.x) and `symfony/http-client-contracts` (3).

## Credentials

A **Stats API key** (Plausible's account settings, "API Keys"), sent as `Authorization: Bearer`.
The **site** is its domain as Plausible knows it (`example.org`, no scheme).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | yes | the Stats API key |
| `site` | string | - | yes | the site's domain in Plausible: `example.org` |
| `url` | string | `https://plausible.io` | no | plausible.io, or a self-hosted Community Edition's address (trailing slash removed) |

Per query: `ReachQuery::$site` replaces `site` for one report.

## Capabilities

| | |
|---|---|
| contract | `AudienceInterface` |
| dimensions | page, entry page, source, country, device, date - combined freely |
| metrics | visitors, visits, pageviews, bounce rate, visit duration (visitors, visits, pageviews by default) |
| filters | on any of its dimensions, exact (`is`) |
| `SearchPerformanceInterface` | no |

The family's names map to Plausible's:

| Omnireach | Plausible |
|---|---|
| `page` | `event:page` |
| `entry_page` | `visit:entry_page` |
| `source` | `visit:source` |
| `country` | `visit:country` |
| `device` | `visit:device` |
| `date` | `time:day` |
| metrics | the same names: `visitors`, `visits`, `pageviews`, `bounce_rate`, `visit_duration` |

## Behaviour

**The query.** One `POST` per page of results:

```
POST https://plausible.io/api/v2/query
  json: {"site_id":"atelier.example.org","metrics":["visits","bounce_rate"],"date_range":["2026-10-01","2026-10-31"],"dimensions":["visit:entry_page"],"filters":[["is","visit:source",["Google"]]],"pagination":{"limit":1000,"offset":0}}
```

(entry pages, visits and bounce rate, filtered on `source => Google`, seen on a recorded client in
the harness, 2026-10-26)

- every filter is `["is", <dimension>, [<value>]]`, all of them together (and);
- `pagination.limit` is the results still wanted, at most 10,000, `offset` the results already
  read: the provider pages until `limit` rows are read or a page comes back short (the package's
  test reads 12,000 rows in two pages, offsets 0 and 10,000).

**Rows.** Each result's `dimensions` and `metrics` are read by position, in the query's order.
Devices are lower-cased (`desktop`, `mobile`, `tablet`), countries upper-cased (`FR`); pages,
entry pages, sources and dates are passed as Plausible gives them. The bounce rate, which
Plausible gives in percent, is divided by 100 and rounded to 4 decimals; the visit duration is
Plausible's, in seconds. A metric that is not a number (`null`) is left out of its row. Rows keep
Plausible's order; the query's filters and limit are then applied. `Report::$note` is always
`null`.

> [!NOTE]
> Filter values are sent as given, while rows are compared in the family's units: Plausible's
> devices come as `Desktop`, `Mobile` (in the shape the tests use) and are lower-cased in rows. A
> report broken down by device and filtered on `Mobile` therefore loses its rows in
> `ReachQuery::apply()`; filtered on `mobile`, it depends on whether Plausible matches `is`
> case-insensitively, which was not checked. Prefer filtering on device in a report not broken
> down by it, or filter the rows in the application.

**Plausible's own refusals.** Plausible refuses some mixes - session metrics with event dimensions,
for one: its message comes back in a `ProviderException` (a 400). The provider's capabilities do
not know these rules, so such a query costs a call.

**Limits** (`capabilities()->limits`): 600 requests an hour per API key, by default.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Plausible\PlausibleFactory;
use Omnireach\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new PlausibleFactory(HttpClient::create())], [
    'stats' => ['factory' => 'plausible', 'options' => ['api_key' => getenv('PLAUSIBLE_API_KEY'), 'site' => 'example.org']],
]);
$plausible = $registry->audience('stats');

// Entry pages of September, from France.
$report = $plausible->audience(new ReachQuery(
    new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'),
    [Dimension::EntryPage], [Metric::Visitors, Metric::Visits, Metric::BounceRate],
    ['country' => 'FR'],
    limit: 50,
));
foreach ($report->rows as $row) {
    printf("%-40s %d visits, %.0f%% bounce\n", $row->dimension(Dimension::EntryPage), $row->metric(Metric::Visits), 100 * ($row->metric(Metric::BounceRate) ?? 0));
}
echo $report->totals()['visits'] ?? 0, "\n";

// Visits by device, day by day.
$history = $plausible->audience(ReachQuery::lastDays(30, [Dimension::Date, Dimension::Device], [Metric::Visits]));
```

In Symfony:

```yaml
services:
    Omnireach\Plausible\PlausibleFactory:
        arguments: ['@http_client']
    Omnireach\Registry:
        arguments:
            $factories: ['@Omnireach\Plausible\PlausibleFactory']
            $config:
                stats: { factory: plausible, options: { api_key: '%env(PLAUSIBLE_API_KEY)%', site: 'example.org' } }
```

The example needs a key: it was not run live.

## Errors

Seen in the harness on 2026-10-26, on recorded answers:

```
401       InvalidKeyException (code 401): Plausible refused the credentials (401) for the query: Invalid API key or site ID
404       NotFoundException (code 404): Plausible has no the query: not found
429       ProviderException (code 429): Plausible answered 429 for the query: Too many requests
500 html  ProviderException (code 500): Plausible answered 500 for the query.
200 html  ProviderException (code 200): Plausible answered 200 for the query: an answer that is not JSON
```

A network error is an `UnreachableException`; a missing `api_key` or `site` an
`InvalidConfigException` when the provider is built (`The "plausible" provider needs: api_key.`);
a search dimension or metric a `NotSupportedException` before any call (`Plausible does not report
the dimension "query".`).

## Verified, and not

Tested offline (`Tests/PlausibleProviderTest.php`, in the core's harness, PHP 8.4): entry pages
with a filter, the request's URL, body and key; a daily history by device, with no totals (distinct
visitors do not add up); pages of results up to the limit; the refusals; the factory through a
`Registry`. The answers are composed in the shape the Stats API v2 documents, not recorded.
`Tests/BareTest.php` loads every class with the package's requirements alone.

Not verified: no Plausible key was used; no call was made to plausible.io or to a self-hosted
instance. How Plausible matches a filter's case (a device `mobile` against its `Mobile`) is
unchecked: filter values are sent as given.

## Responsibility

An independent, unofficial client, affiliated with Plausible Analytics in no way. Whoever runs it
complies with Plausible's terms (<https://plausible.io/terms>): see the README.
