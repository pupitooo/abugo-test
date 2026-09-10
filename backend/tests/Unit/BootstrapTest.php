<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testApplicationRuntimeIsForcedToUtc(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');

        try {
            Bootstrap::boot();

            self::assertSame('UTC', date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}
