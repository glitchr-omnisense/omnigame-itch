<?php

namespace Omnigame\Itch\Tests;

use Omnigame\Itch\ItchProvider;
use Omnigame\Testing\BareTestCase;

/** omnigame/itch's classes load with omniscient/omnigame and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return ItchProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
