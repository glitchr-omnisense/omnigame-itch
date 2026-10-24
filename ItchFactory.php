<?php

namespace Omnigame\Itch;

use Omnigame\Config;
use Omnigame\Http\JsonClient;
use Omnigame\ProviderFactory;
use Omnigame\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "itch": options key (a server-side API key, from the account's settings or
 * OAuth: only for ownership and library; the pages need none), user_agent,
 * api ("https://api.itch.io").
 */
final class ItchFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnigame.factory_name' => 'itch',
            'omnigame.factory_title' => 'itch.io',
            'omnigame.required_options' => [],
            'key' => null,
            'user_agent' => 'omnigame/1.0 (+https://github.com/glitchr-studio/omnigame)',
            'api' => 'https://api.itch.io',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new ItchProvider(new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'itch.io', ['Accept' => 'application/json', 'User-Agent' => (string) $c->string('user_agent')]), $c->string('key'), rtrim((string) $c->string('api'), '/'));
    }
}
