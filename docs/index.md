# omnigame/itch

itch.io for [omnisense/omnigame](https://github.com/glitchr-omnisense/omnigame): a game page's
record with no key, and - with a server-side API key - whether a player owns one of your games and
the library of the key's own account. The provider calls:

| Operation | Endpoint | Key |
|---|---|---|
| a record | `GET https://{creator}.itch.io/{game}/data.json` (the page's public data) | none |
| ownership | `GET https://api.itch.io/games/{id}/download_keys?user_id=...` (or `email=`, `download_key=`) | server-side key |
| library | `GET https://api.itch.io/profile/owned-keys?page=...&per_page=500` | server-side key |

The key is sent as `Authorization: Bearer`. itch.io has no search API: no search here
([its server-side API](https://itch.io/docs/api/serverside)).

## Installation

```sh
composer require omnigame/itch
```

Requires PHP 8.2, `omnisense/omnigame` (1.x) and `symfony/http-client` (6.4, 7 or 8).

## Credentials

- None for pages.
- A **server-side API key** (the account's settings, API keys; or one from OAuth, scoped) for
  ownership and the library. Ownership is asked about your own games.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `key` | string | `null` | no | the server-side API key |
| `user_agent` | string | `omnigame/1.0 (+https://github.com/glitchr-omnisense/omnigame)` | no | |
| `api` | string | `https://api.itch.io` | no | the server-side API |

## Capabilities

| Contract | |
|---|---|
| `SearchInterface` | no |
| `CatalogueInterface` | by page, `creator/game` (`GameRef::itch('leafo/x-moon')` for https://leafo.itch.io/x-moon) |
| `OwnershipInterface` | with `key`: a player is an itch.io user id, a verified e-mail address or a download key |
| `LibraryInterface` | with `key`: `me` only, the key's own account |
| prices | yes; keyless catalogue |

`attribution`: `Data from itch.io`.

> [!WARNING]
> Known defect: without `key`, `capabilities()` says no library and no ownership, but the provider
> still implements both, so the registry's `library('itch')` and `ownership('itch')` return it;
> `owns()` and `library()` then throw `InvalidConfigException` once they reach the API.

## Behaviour

**A record.** A page that is not `creator/game` (letters, digits, `-`, `_`) is `null` without a
call; a `data.json` without a title is `null`.

| Field | Read from |
|---|---|
| `title` | `title` |
| `identifiers` | the page, lower-cased |
| `url` | `links.self`, else `https://{creator}.itch.io/{game}` |
| `developers` | `authors[].name` |
| `tags` | `tags` |
| `price` | `price` and `original_price` as shown (`$2.00`, `€4,99`): the currency from the symbol (`$` USD, `€` EUR, `£` GBP, `¥` JPY; USD when none), two decimals; the discount computed when the original is higher |
| `images` | `cover_image` (`Cover`), `screenshots` (`Screenshot`) |

> [!WARNING]
> Known defect: the price is always read with two decimals and no thousands separator: `¥500`
> gives 50000 JPY minor units (500 expected: JPY has none), `$1,000.00` gives 100 (one dollar) -
> seen on answers composed for the check in the core's harness, 2026-10-27.

**Ownership.** A page ref is turned into the game's number from its `data.json` (an unknown page:
`false`); a numeric ref is used as the number. The player decides the query: digits a `user_id`,
an `@` an `email`, anything else a `download_key`. A `download_key.id` in the answer is `true`;
itch.io's "no download key found" or "invalid download key" error is `false`, whatever its status;
any other error a `ProviderException`. Look up by e-mail only an address the application verified:
anyone could type another's.

**Library.** `me` only (anything else: `InvalidConfigException`): the key's owned keys, pages of
500, 20 pages at most, each the game's page (from `game.url`, else its number) and title; no
playtime.

## Example

Run live on 2026-10-27 (06:18 UTC), through the core's
[installation script](https://github.com/glitchr-omnisense/omnigame/blob/1.x/docs/installation.md#the-stores-apis) - one call:

```php
$itch = (new ItchFactory(HttpClient::create()))->create(['key' => getenv('ITCH_API_KEY')]);
$xMoon = $itch->game(GameRef::itch('leafo/x-moon'));
$owns = $itch->owns('12345', GameRef::itch('my-studio/my-game'));      // needs the key
```

```
X-Moon [{"itch":"leafo\/x-moon"}] -, 0.00 USD,
  by leafo; https://leafo.itch.io/x-moon
```

In Symfony:

```yaml
services:
    Omnigame\Itch\ItchFactory:
        arguments: ['@http_client']
    Omnigame\Registry:
        arguments:
            $factories: ['@Omnigame\Itch\ItchFactory']
            $config:
                itch: { factory: itch, options: { key: '%env(default::ITCH_API_KEY)%' } }
```

## Errors

- unknown page: `null`; a ref of another scheme: `NotSupportedException`; search:
  `NotSupportedException` from the registry (`The "i" provider (Omnigame\Itch\ItchProvider) does not
  search.`);
- no `key` for ownership or the library, a library of anyone but `me`: `InvalidConfigException`;
- a status of 400 or more but 404 (other than "no download key"): `ProviderException`; a network
  error: `UnreachableException` (the key travels in a header, not in the URL).

## Verified, and not

Tested offline (`Tests/ItchProviderTest.php`, in the core's harness, PHP 8.4): X-Moon's page
recorded on 2026-10-24, an unknown page; ownership by user id and by e-mail, the key's library, a
refused key - answers shaped after the documentation. `Tests/BareTest.php` loads every class with
the package's requirements alone. Verified live: X-Moon's page (`Tests/LiveTest.php`, 2026-10-24;
and on 2026-10-27). Not verified: ownership and the library (no key); how itch.io answers "no
download key found" (its status) is not documented.

## Responsibility

An independent, unofficial client, affiliated with itch.io in no way. Whoever runs it keeps the
[server-side API's](https://itch.io/docs/api/serverside) rules (scopes, verified e-mails) and
itch.io's [terms](https://itch.io/docs/legal/terms): see the README.
