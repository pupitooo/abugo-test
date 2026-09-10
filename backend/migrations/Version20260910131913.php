<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910131913 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prevent overlapping active bookings for a stylist';
    }

    public function preUp(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof SqlitePlatform, 'This migration requires SQLite.');

        $existingOverlap = $this->connection->fetchOne(<<<'SQL'
            SELECT 1
            FROM barbershop_bookings first_booking
            INNER JOIN barbershop_bookings second_booking
                ON first_booking.id <> second_booking.id
               AND first_booking.stylist_id = second_booking.stylist_id
               AND first_booking.status IN ('pending', 'confirmed')
               AND second_booking.status IN ('pending', 'confirmed')
               AND first_booking.start_time < second_booking.end_time
               AND first_booking.end_time > second_booking.start_time
            LIMIT 1
            SQL);

        $this->abortIf(
            $existingOverlap !== false,
            'Overlapping active bookings already exist. Resolve them before retrying this migration.',
        );
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof SqlitePlatform, 'This migration requires SQLite.');

        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_BOOKINGS_ACTIVE_INTERVAL
            ON barbershop_bookings (stylist_id, start_time, end_time)
            WHERE status IN ('pending', 'confirmed')
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER TRG_BOOKINGS_NO_ACTIVE_OVERLAP_INSERT
            BEFORE INSERT ON barbershop_bookings
            FOR EACH ROW
            WHEN NEW.status IN ('pending', 'confirmed')
             AND EXISTS (
                SELECT 1
                FROM barbershop_bookings booking
                WHERE booking.stylist_id = NEW.stylist_id
                  AND booking.status IN ('pending', 'confirmed')
                  AND booking.start_time < NEW.end_time
                  AND booking.end_time > NEW.start_time
             )
            BEGIN
                SELECT RAISE(ABORT, 'booking_slot_unavailable');
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER TRG_BOOKINGS_NO_ACTIVE_OVERLAP_UPDATE
            BEFORE UPDATE OF stylist_id, start_time, end_time, status ON barbershop_bookings
            FOR EACH ROW
            WHEN NEW.status IN ('pending', 'confirmed')
             AND EXISTS (
                SELECT 1
                FROM barbershop_bookings booking
                WHERE booking.id <> OLD.id
                  AND booking.stylist_id = NEW.stylist_id
                  AND booking.status IN ('pending', 'confirmed')
                  AND booking.start_time < NEW.end_time
                  AND booking.end_time > NEW.start_time
             )
            BEGIN
                SELECT RAISE(ABORT, 'booking_slot_unavailable');
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof SqlitePlatform, 'This migration requires SQLite.');

        $this->addSql('DROP TRIGGER TRG_BOOKINGS_NO_ACTIVE_OVERLAP_INSERT');
        $this->addSql('DROP TRIGGER TRG_BOOKINGS_NO_ACTIVE_OVERLAP_UPDATE');
        $this->addSql('DROP INDEX IDX_BOOKINGS_ACTIVE_INTERVAL');
    }
}
