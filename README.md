# omnigame/itch

[omniscient/omnigame](https://github.com/glitchr-studio/omnigame)'s itch.io, under any PHP host:

- **a game page's record**, no key: its public `data.json` (title, authors, price and the price
  before a sale, tags, cover, screenshots); a page is "creator/game" (`GameRef::itch('leafo/x-moon')`
  for https://leafo.itch.io/x-moon);
- **ownership** with a server-side API key (`api.itch.io/games/{id}/download_keys`): whether a
  player, named by their itch.io user id (best: it cannot be spoofed when they signed in through
  your app), a verified e-mail address or a download key, holds a key of one of your games
  (bought or given);
- **the library of the key's own account** (`/profile/owned-keys`): `library('me')`.

itch.io has no search API: no search here.

```php
$itch = (new ItchFactory($httpClient))->create(['key' => getenv('ITCH_API_KEY')]);
$owns = $itch->owns('12345', GameRef::itch('leafo/x-moon'));
```

Options: `key`, `user_agent`, `api` ("https://api.itch.io").

## itch.io's terms, as read on 2026-10-24

From the [server-side API documentation](https://itch.io/docs/api/serverside): a key sent as
`Authorization: Bearer`, scoped when it comes from OAuth; a lookup by e-mail only after the address
was verified (anyone could type another's); no rate limit documented. Its
[terms](https://itch.io/docs/legal/terms) govern the rest.

## Verified, and not

- Verified (the core's harness, PHP 8.4): X-Moon's page recorded on 2026-10-24, an unknown page;
  ownership by user id and by e-mail, the key's library, a refused key - answers shaped after the
  documentation (no key); `BareTest`; PHPStan max.
- Verified live (2026-10-24): X-Moon's page.
- Not verified live: ownership and library (no key). How itch.io answers "no download key found"
  (its status) is not documented: the provider reads that error as "not owned" whatever the status.

## Responsibility

An independent, unofficial client, affiliated with, endorsed by or sponsored by none of the services
it reaches. The MIT licence covers this code only and grants no right to the service or its data:
whoever runs it complies with the terms above and obtains what they require. The authors publish
it free of charge, make no commercial use of it and accept no liability for its use.

## Licence

MIT.
