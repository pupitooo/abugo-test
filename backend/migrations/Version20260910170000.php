<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an explicit IANA timezone to each business';
    }

    public function preUp(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof SqlitePlatform, 'This migration requires SQLite.');

        $bookings = $this->connection->executeQuery(
            'SELECT id, start_time, end_time FROM barbershop_bookings',
        );

        while (($booking = $bookings->fetchAssociative()) !== false) {
            $this->abortIf(
                !$this->isCanonicalStorageValue($booking['start_time'])
                || !$this->isCanonicalStorageValue($booking['end_time']),
                "Booking {$booking['id']} has a timestamp outside the canonical YYYY-MM-DD HH:MM:SS format. "
                . 'Audit and convert legacy bookings before retrying this migration.',
            );
        }
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof SqlitePlatform, 'This migration requires SQLite.');

        // Legacy booking values stay untouched: their discarded source offsets cannot be reconstructed safely.
        // The required audit and conversion procedure is documented in README.md.
        $this->addSql(
            "ALTER TABLE barbershop_businesses ADD COLUMN timezone VARCHAR(64) DEFAULT 'Europe/Prague' NOT NULL",
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof SqlitePlatform, 'This migration requires SQLite.');

        $this->addSql('ALTER TABLE barbershop_businesses DROP COLUMN timezone');
    }

    private function isCanonicalStorageValue(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        try {
            $dateTime = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $value,
                new DateTimeZone('UTC'),
            );
        } catch (\ValueError) {
            return false;
        }
        $errors = DateTimeImmutable::getLastErrors();

        return $dateTime !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $dateTime->format('Y-m-d H:i:s') === $value;
    }
}
