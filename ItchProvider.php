<?php

namespace Omnigame\Itch;

use Omnigame\Capabilities;
use Omnigame\CatalogueInterface;
use Omnigame\Exception\InvalidConfigException;
use Omnigame\Exception\NotSupportedException;
use Omnigame\Exception\ProviderException;
use Omnigame\Http\Data;
use Omnigame\Http\JsonClient;
use Omnigame\LibraryInterface;
use Omnigame\Model\Game;
use Omnigame\Model\GameRef;
use Omnigame\Model\Identifiers;
use Omnigame\Model\Image;
use Omnigame\Model\ImageKind;
use Omnigame\Model\OwnedGame;
use Omnigame\Model\Price;
use Omnigame\Model\Scheme;
use Omnigame\OwnershipInterface;
use Omnigame\ProviderInterface;

/**
 * itch.io: a game page's record from its public data.json (no key: the page
 * is "creator/game", leafo/x-moon for https://leafo.itch.io/x-moon), and -
 * with a server-side API key - whether a player owns one of your games (a
 * download key or a purchase: /games/{id}/download_keys) and the library of
 * the key's own account (/profile/owned-keys). itch.io has no search API.
 */
final class ItchProvider implements ProviderInterface, CatalogueInterface, OwnershipInterface, LibraryInterface
{
    private const CURRENCIES = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP', '¥' => 'JPY'];

    public function __construct(private readonly JsonClient $json, private readonly ?string $key = null, private readonly string $api = 'https://api.itch.io')
    {
    }

    public function getName(): string
    {
        return 'itch';
    }

    public function getTitle(): string
    {
        return 'itch.io';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            catalogue: true,
            library: null !== $this->key,
            ownership: null !== $this->key,
            lookupBy: [Scheme::Itch],
            prices: true,
            player: 'an itch.io user id ("12345"), a verified e-mail address, or a download key; "me" for the library of the key\'s account',
            attribution: 'Data from itch.io',
            terms: ['https://itch.io/docs/legal/terms', 'https://itch.io/docs/api/serverside'],
            keyless: true,
        );
    }

    public function game(GameRef $ref, ?string $language = null, ?string $country = null): ?Game
    {
        if (!$this->capabilities()->resolves($ref)) {
            throw NotSupportedException::ref($this->getTitle(), $ref);
        }
        $data = $this->page($ref->id);
        if (null === $data || null === $data->string('title')) {
            return null;
        }
        $images = [];
        if (null !== $cover = $data->string('cover_image')) {
            $images[] = new Image(ImageKind::Cover, $cover);
        }
        foreach ($data->strings('screenshots') as $screenshot) {
            $images[] = new Image(ImageKind::Screenshot, $screenshot);
        }

        return new Game(
            title: (string) $data->string('title'),
            identifiers: Identifiers::of(['itch' => strtolower($ref->id)]),
            source: 'itch',
            url: $data->string('links.self') ?? self::url($ref->id),
            developers: $data->strings('authors', 'name'),
            tags: $data->strings('tags'),
            price: self::price($data->string('price'), $data->string('original_price')),
            images: $images,
        );
    }

    public function owns(string $player, GameRef $game): bool
    {
        if (Scheme::Itch !== $game->scheme) {
            throw NotSupportedException::ref($this->getTitle(), $game);
        }
        $id = ctype_digit($game->id) ? $game->id : $this->page($game->id)?->string('id');
        if (null === $id) {
            return false;
        }
        $by = match (true) {
            ctype_digit($player) => 'user_id',
            str_contains($player, '@') => 'email',
            default => 'download_key',
        };
        try {
            $answer = $this->json->get($this->api.'/games/'.$id.'/download_keys', [$by => $player], $this->authorization());
        } catch (ProviderException $e) {
            // itch.io answers its "no download key found" as an error.
            if (str_contains(strtolower($e->getMessage()), 'no download key') || str_contains(strtolower($e->getMessage()), 'invalid download key')) {
                return false;
            }
            throw $e;
        }

        return null !== $answer && null !== $answer->string('download_key.id');
    }

    public function library(string $player): array
    {
        if ('me' !== $player) {
            throw new InvalidConfigException('itch.io lets a key read the library of its own account only: ask for "me".');
        }
        $games = [];
        for ($page = 1; $page <= 20; ++$page) {
            $answer = $this->json->get($this->api.'/profile/owned-keys', ['page' => $page, 'per_page' => 500], $this->authorization());
            $keys = $answer?->list('owned_keys') ?? [];
            foreach ($keys as $key) {
                $url = $key->string('game.url');
                $games[] = new OwnedGame(Identifiers::of(['itch' => (null === $url ? null : self::pageOf($url)) ?? $key->string('game_id')]), (string) ($key->string('game.title') ?? $key->string('game_id')));
            }
            if (\count($keys) < 500) {
                break;
            }
        }

        return $games;
    }

    /** The page's public data.json. */
    private function page(string $page): ?Data
    {
        return preg_match('~^[a-z0-9_-]+/[a-z0-9_-]+$~i', $page) ? $this->json->get(self::url($page).'/data.json') : null;
    }

    /** @return array<string, string> */
    private function authorization(): array
    {
        return ['Authorization' => 'Bearer '.($this->key ?? throw new InvalidConfigException('itch.io\'s ownership and library need the "key" option (a server-side API key).'))];
    }

    private static function url(string $page): string
    {
        [$creator, $game] = explode('/', strtolower($page), 2) + [1 => ''];

        return 'https://'.$creator.'.itch.io/'.$game;
    }

    /** "https://leafo.itch.io/x-moon" → "leafo/x-moon" */
    private static function pageOf(string $url): ?string
    {
        return preg_match('~^https?://([a-z0-9_-]+)\.itch\.io/([a-z0-9_-]+)~i', $url, $m) ? strtolower($m[1].'/'.$m[2]) : null;
    }

    /** "$2.00", "€4,99": minor units and the currency; the price before a sale when given. */
    private static function price(?string $price, ?string $original): ?Price
    {
        $amount = self::amount($price);
        if (null === $amount || null === $price) {
            return null;
        }
        $currency = 'USD';
        foreach (self::CURRENCIES as $symbol => $code) {
            if (str_contains($price, $symbol)) {
                $currency = $code;
            }
        }
        $before = self::amount($original);
        $discounted = null !== $before && $before > $amount;

        return new Price($amount, $currency, $discounted ? $before : null, $discounted ? (int) round(100 * ($before - $amount) / $before) : 0);
    }

    private static function amount(?string $value): ?int
    {
        if (null === $value || !preg_match('~(\d+)(?:[.,](\d{1,2}))?~', $value, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
