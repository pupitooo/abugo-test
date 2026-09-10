<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an explicit IANA timezone to each business';
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
}
