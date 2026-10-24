<?php

namespace Omnireach\Plausible;

use Omnireach\Config;
use Omnireach\Exception\InvalidConfigException;
use Omnireach\ProviderFactory;
use Omnireach\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Plausible, from its options:
 *
 *   api_key  a Stats API key (account settings, "API Keys")
 *   site     the site's domain as Plausible knows it ("example.org")
 *   url      https://plausible.io, or a self-hosted Community Edition
 */
final class PlausibleFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnireach.factory_name' => 'plausible',
            'omnireach.factory_title' => 'Plausible',
            'omnireach.required_options' => ['api_key', 'site'],
            'url' => 'https://plausible.io',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new PlausibleProvider(
            $this->http ?? throw new InvalidConfigException('The "plausible" provider needs an HTTP client: give one to PlausibleFactory.'),
            (string) $c->string('api_key'),
            (string) $c->string('site'),
            rtrim($c->string('url') ?? 'https://plausible.io', '/'),
        );
    }
}
