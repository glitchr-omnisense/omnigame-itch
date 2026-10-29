<?php

namespace Omnigame\Itch\Tests;

use Omnigame\Exception\InvalidConfigException;
use Omnigame\Exception\ProviderException;
use Omnigame\Itch\ItchFactory;
use Omnigame\Itch\ItchProvider;
use Omnigame\Model\GameRef;
use Omnigame\Model\ImageKind;
use Omnigame\Model\OwnedGame;
use Omnigame\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * A page's data.json recorded on 2026-10-24; the server-side API's answers
 * shaped after its documentation (itch.io/docs/api/serverside), written by
 * hand (no key was used).
 */
final class ItchProviderTest extends TestCase
{
    public function testAGamePagesRecord(): void
    {
        $game = self::provider(self::http())->game(GameRef::itch('leafo/x-moon'));

        self::assertNotNull($game);
        self::assertSame(['X-Moon', ['leafo'], 'https://leafo.itch.io/x-moon', ['itch' => 'leafo/x-moon']], [$game->title, $game->developers, $game->url, $game->identifiers->toArray()]);
        self::assertSame(['amount' => 0, 'currency' => 'USD', 'initial' => null, 'discount' => 0], $game->price?->toArray());
        self::assertContains('love2d', $game->tags);
        self::assertNotNull($game->image(ImageKind::Cover));
        self::assertNull(self::provider(self::http())->game(GameRef::itch('leafo/no-such-game-here')));
        self::assertNull(self::provider(self::http())->game(GameRef::itch('not a page')));
    }

    /**
     * Prices in ISO 4217 minor units, whatever the writing: the yen has none (ISO 4217's exponent 0),
     * a thousands separator is not a decimal one. Composed in the shape of a page's data.json.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('prices')]
    public function testAPriceInMinorUnits(string $price, int $amount, string $currency, ?string $original = null, ?int $initial = null): void
    {
        $page = json_encode(['id' => 3, 'title' => 'X-Moon', 'price' => $price, 'authors' => [['name' => 'leafo', 'url' => 'https://leafo.itch.io']], 'links' => ['self' => 'https://leafo.itch.io/x-moon']] + (null !== $original ? ['original_price' => $original] : []));
        $game = self::provider(RecordedHttpClient::routes(['~data\.json~' => [200, (string) $page]]))->game(GameRef::itch('leafo/x-moon'));

        self::assertSame([$amount, $currency, $initial], [$game?->price?->amount, $game?->price?->currency, $game?->price?->initial]);
    }

    public static function prices(): iterable
    {
        yield 'yen' => ['¥500', 500, 'JPY'];
        yield 'thousands' => ['$1,000.00', 100000, 'USD'];
        yield 'euros' => ['€4,99', 499, 'EUR'];
        yield 'euros and thousands' => ['€1.299,00', 129900, 'EUR'];
        yield 'no decimals' => ['$2', 200, 'USD'];
        yield 'pounds, thousands only' => ['£1,000', 100000, 'GBP'];
        yield 'a sale' => ['$1.50', 150, 'USD', '$3.00', 300];
    }

    public function testOwnershipByUserIdOrEmailWithTheKey(): void
    {
        $http = self::http();
        $provider = self::provider($http, ['key' => 'not-a-real-itch-api-key']);

        self::assertTrue($provider->owns('12345', GameRef::itch('leafo/x-moon')));
        self::assertSame('https://api.itch.io/games/3/download_keys', $http->requests[1]['url']);
        self::assertSame(['user_id' => '12345'], $http->requests[1]['options']['query'] ?? null);
        self::assertSame('Bearer not-a-real-itch-api-key', $http->header('Authorization'));
        self::assertFalse($provider->owns('someone@example.org', GameRef::itch('3')));
    }

    public function testTheKeysOwnLibrary(): void
    {
        $provider = self::provider(self::http(), ['key' => 'not-a-real-itch-api-key']);

        self::assertSame([['leafo/x-moon', 'X-Moon']], array_map(static fn (OwnedGame $g) => [$g->identifiers->get('itch'), $g->title], $provider->library('me')));
        $this->expectException(InvalidConfigException::class);
        $provider->library('12345');
    }

    public function testOwnershipNeedsAKeyAndOtherErrorsAreErrors(): void
    {
        try {
            self::provider(self::http())->owns('12345', GameRef::itch('3'));
            self::fail('No key.');
        } catch (InvalidConfigException) {
            self::addToAssertionCount(1);
        }
        $http = RecordedHttpClient::routes(['~~' => [401, '{"errors": ["invalid key"]}']]);
        $this->expectException(ProviderException::class);
        self::provider($http, ['key' => 'x'])->owns('12345', GameRef::itch('3'));
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes([
            '~^GET https://leafo\.itch\.io/x-moon/data\.json~' => [200, '@'.__DIR__.'/Fixtures/x-moon.json'],
            '~/games/3/download_keys\?user_id=12345~' => [200, '{"download_key": {"id": 9001, "created_at": "2026-01-02T10:00:00Z", "downloads": 2, "key": "not-a-real-download-key", "game_id": 3, "owner": {"id": 12345, "username": "example"}}}'],
            '~/games/3/download_keys\?email=~' => [400, '{"errors": ["no download key found"]}'],
            '~/profile/owned-keys~' => [200, '{"page": 1, "per_page": 500, "owned_keys": [{"id": 1, "game_id": 3, "created_at": "2026-01-02T10:00:00Z", "downloads": 1, "game": {"id": 3, "title": "X-Moon", "url": "https://leafo.itch.io/x-moon"}}]}'],
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(RecordedHttpClient $http, array $options = []): ItchProvider
    {
        $provider = (new ItchFactory($http))->create($options);
        self::assertInstanceOf(ItchProvider::class, $provider);

        return $provider;
    }
}
