<?php

namespace Omnireach\Plausible\Tests;

use Omnireach\Exception\InvalidKeyException;
use Omnireach\Exception\NotSupportedException;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Row;
use Omnireach\Plausible\PlausibleFactory;
use Omnireach\Plausible\PlausibleProvider;
use Omnireach\Registry;
use Omnireach\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Plausible's answers are composed for these tests in the shape its Stats
 * API v2 documents (results of metrics and dimensions, meta, query); they
 * were not recorded with a key. The site is an example.
 */
final class PlausibleProviderTest extends TestCase
{
    public function testEntryPages(): void
    {
        $http = RecordedHttpClient::routes(['POST /api/v2/query' => self::fixture('entry-pages')]);
        $report = self::provider($http)->audience(new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), [Dimension::EntryPage], [Metric::Visitors, Metric::Visits, Metric::Pageviews, Metric::BounceRate], ['source' => 'Google']));

        self::assertSame([['/', 3120, 3644, 7012, 0.54], ['/ateliers', 912, 1005, 2210, 0.61], ['/contact', 304, 330, 455, 0.72]], array_map(static fn (Row $r) => [$r->dimension(Dimension::EntryPage), ...array_values($r->metrics)], $report->rows), 'the bounce rate as a ratio');
        self::assertSame(['site_id' => 'atelier.example.org', 'metrics' => ['visitors', 'visits', 'pageviews', 'bounce_rate'], 'date_range' => ['2026-10-01', '2026-10-31'], 'dimensions' => ['visit:entry_page'], 'filters' => [['is', 'visit:source', ['Google']]], 'pagination' => ['limit' => 1000, 'offset' => 0]], $http->requests[0]['options']['json']);
        self::assertSame('example-key-not-real', $http->requests[0]['options']['auth_bearer']);
        self::assertSame('https://plausible.io/api/v2/query', $http->requests[0]['url']);
    }

    public function testAHistoryByDevice(): void
    {
        $report = self::provider(RecordedHttpClient::routes(['POST /api/v2/query' => self::fixture('daily-devices')]))->audience(new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02'), [Dimension::Date, Dimension::Device], [Metric::Visitors]));

        self::assertSame([['date' => '2026-10-01', 'device' => 'desktop'], ['date' => '2026-10-01', 'device' => 'mobile'], ['date' => '2026-10-02', 'device' => 'desktop']], array_map(static fn (Row $r) => $r->dimensions, $report->rows));
        self::assertSame([], $report->totals(), 'distinct visitors do not add up across rows');
    }

    public function testPagesUntilTheLimit(): void
    {
        $offsets = [];
        $http = new RecordedHttpClient(static function (string $method, string $url, array $options) use (&$offsets): array {
            $json = \is_array($options['json'] ?? null) ? $options['json'] : [];
            $pagination = \is_array($json['pagination'] ?? null) ? $json['pagination'] : [];
            $limit = \is_int($pagination['limit'] ?? null) ? $pagination['limit'] : 0;
            $offsets[] = [$pagination['offset'] ?? null, $limit];

            return [200, (string) json_encode(['results' => array_fill(0, $limit, ['metrics' => [1], 'dimensions' => ['/p']])])];
        });

        self::assertCount(12000, self::provider($http)->audience(new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01'), [Dimension::Page], [Metric::Pageviews], limit: 12000))->rows);
        self::assertSame([[0, 10000], [10000, 2000]], $offsets);
    }

    public function testRefusals(): void
    {
        try {
            self::provider(RecordedHttpClient::routes([]))->audience(new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01'), [Dimension::Query]));
            self::fail('No search queries in Plausible.');
        } catch (NotSupportedException $e) {
            self::assertSame('Plausible does not report the dimension "query".', $e->getMessage());
        }
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('Plausible refused the credentials (401) for the query: Invalid API key or site ID. Please make sure you\'re using a valid API key with access to the site you\'ve requested.');
        self::provider(RecordedHttpClient::routes(['POST /api/v2/query' => [401, '{"error":"Invalid API key or site ID. Please make sure you\'re using a valid API key with access to the site you\'ve requested."}']]))->audience(ReachQuery::lastDays(7));
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new PlausibleFactory(RecordedHttpClient::routes(['POST /api/v2/query' => self::fixture('entry-pages')]))], ['stats' => ['factory' => 'plausible', 'options' => ['api_key' => 'example-key-not-real', 'site' => 'atelier.example.org', 'url' => 'https://stats.example.org/']]]);

        self::assertSame(['audience'], $registry->get('stats')->capabilities()->contracts());
        self::assertSame('atelier.example.org', $registry->audience('stats')->audience(new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), [Dimension::EntryPage], [Metric::Visitors, Metric::Visits, Metric::Pageviews, Metric::BounceRate]))->site);
    }

    private static function provider(RecordedHttpClient $http): PlausibleProvider
    {
        return new PlausibleProvider($http, 'example-key-not-real', 'atelier.example.org');
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json');
    }
}
