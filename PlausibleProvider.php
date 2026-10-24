<?php

namespace Omnireach\Plausible;

use Omnireach\AudienceInterface;
use Omnireach\Capabilities;
use Omnireach\Http\JsonApi;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Report;
use Omnireach\Model\Row;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Plausible's Stats API v2 (https://plausible.io/docs/stats-api):
 * POST /api/v2/query with a bearer key - metrics, a date range, dimensions,
 * "is" filters, pages of results.
 */
final class PlausibleProvider implements AudienceInterface
{
    private const DIMENSIONS = ['page' => 'event:page', 'entry_page' => 'visit:entry_page', 'source' => 'visit:source', 'country' => 'visit:country', 'device' => 'visit:device', 'date' => 'time:day'];

    private const METRICS = ['visitors' => 'visitors', 'visits' => 'visits', 'pageviews' => 'pageviews', 'bounce_rate' => 'bounce_rate', 'visit_duration' => 'visit_duration'];

    /** The most results Plausible returns in one page. */
    private const PAGE = 10000;

    private readonly JsonApi $api;

    public function __construct(
        HttpClientInterface $http,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $site,
        private readonly string $url = 'https://plausible.io',
    ) {
        $this->api = new JsonApi($http, 'Plausible');
    }

    public function getName(): string
    {
        return 'plausible';
    }

    public function getTitle(): string
    {
        return 'Plausible';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            audience: true,
            dimensions: [Dimension::Page, Dimension::EntryPage, Dimension::Source, Dimension::Country, Dimension::Device, Dimension::Date],
            metrics: [Metric::Visitors, Metric::Visits, Metric::Pageviews, Metric::BounceRate, Metric::VisitDuration],
            terms: ['https://plausible.io/docs/stats-api', 'https://plausible.io/terms'],
            limits: ['600 requests an hour per API key, by default'],
        );
    }

    public function audience(ReachQuery $query): Report
    {
        $metrics = $query->metricsOr([Metric::Visitors, Metric::Visits, Metric::Pageviews]);
        $this->capabilities()->check($query, $metrics, 'Plausible');
        $site = $query->site ?? $this->site;
        $body = [
            'site_id' => $site,
            'metrics' => array_map(static fn (Metric $m) => self::METRICS[$m->value] ?? $m->value, $metrics),
            'date_range' => [$query->start->format('Y-m-d'), $query->end->format('Y-m-d')],
            'dimensions' => array_map(static fn (Dimension $d) => self::DIMENSIONS[$d->value] ?? $d->value, $query->dimensions),
        ];
        if ([] !== $query->filters) {
            $body['filters'] = array_map(static fn (string $dimension, string $value) => ['is', self::DIMENSIONS[$dimension] ?? $dimension, [$value]], array_keys($query->filters), $query->filters);
        }

        $rows = [];
        for ($offset = 0; \count($rows) < $query->limit; $offset += self::PAGE) {
            $size = min(self::PAGE, $query->limit - \count($rows));
            $results = $this->api->request('POST', $this->url.'/api/v2/query', ['json' => $body + ['pagination' => ['limit' => $size, 'offset' => $offset]], 'auth_bearer' => $this->apiKey], 'the query')->list('results');
            foreach ($results as $result) {
                $dimensions = [];
                foreach ($query->dimensions as $i => $dimension) {
                    $value = (string) $result->string('dimensions.'.$i);
                    $dimensions[$dimension->value] = match ($dimension) {
                        Dimension::Device => strtolower($value),
                        Dimension::Country => strtoupper($value),
                        default => $value,
                    };
                }
                $values = [];
                foreach ($metrics as $i => $metric) {
                    $value = $result->raw('metrics.'.$i);
                    if (\is_int($value) || \is_float($value)) {
                        $values[$metric->value] = Metric::BounceRate === $metric ? round($value / 100, 4) : $value;
                    }
                }
                $rows[] = new Row($dimensions, $values);
            }
            if (\count($results) < $size) {
                break;
            }
        }

        return new Report('plausible', $site, $query, $query->apply($rows));
    }
}
