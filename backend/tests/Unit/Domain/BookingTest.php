<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Entity\Service;
use App\Domain\Barbershop\Entity\Stylist;
use App\Infrastructure\ValueObject\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class BookingTest extends TestCase
{
    public function testBookingNormalizesTimestampsToUtc(): void
    {
        $start = new DateTimeImmutable('2026-09-14 09:00:00', new DateTimeZone('Europe/Prague'));

        $booking = new Booking(
            Uuid::generate(),
            new Service(Uuid::generate(), 'Haircut', 30, 350.0, 'CZK'),
            new Stylist(Uuid::generate(), 'Stylist'),
            $start,
            $start->modify('+30 minutes'),
            'Customer',
            'customer@example.com',
        );

        self::assertSame('2026-09-14T07:00:00+00:00', $booking->getStartTime()->format(DATE_ATOM));
        self::assertSame('2026-09-14T07:30:00+00:00', $booking->getEndTime()->format(DATE_ATOM));
        self::assertSame('UTC', $booking->getStartTime()->getTimezone()->getName());
        self::assertSame('UTC', $booking->getEndTime()->getTimezone()->getName());
    }
}
