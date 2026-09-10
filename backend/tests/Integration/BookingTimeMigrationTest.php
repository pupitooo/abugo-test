<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Barbershop\Entity\Booking;
use App\Infrastructure\Fixture\BarbershopFixtures;
use App\Infrastructure\Migration\Version20260910131913;
use App\Infrastructure\Migration\Version20260910170000;
use DateTimeInterface;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Bootstrap\Configurator;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class BookingTimeMigrationTest extends TestCase
{
    private const BUSINESS = '11111111-1111-1111-1111-111111111111';
    private const STYLIST = 'bbbbbbbb-bbbb-bbbb-bbbb-aaaaaaaaaaaa';
    private const SERVICE = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private ?EntityManagerInterface $entityManager = null;
    private string $backendRoot = '';
    private string $databasePath = '';
    private string $containerTempDirectory = '';
    private int $migrationRun = 0;

    protected function setUp(): void
    {
        $this->backendRoot = dirname(__DIR__, 2);
        $testId = getmypid() . '-' . bin2hex(random_bytes(4));

        $this->databasePath = sys_get_temp_dir() . "/abugo-migration-$testId.sqlite";
        $this->containerTempDirectory = sys_get_temp_dir() . "/abugo-migration-container-$testId";

        $configurator = new Configurator();
        $configurator->setTempDirectory($this->containerTempDirectory . '/application');
        $configurator->addStaticParameters(['appDir' => $this->backendRoot . '/src']);
        $configurator->addConfig($this->backendRoot . '/config/config.neon');
        $configurator->addConfig([
            'nettrine.dbal' => [
                'connection' => [
                    'path' => $this->databasePath,
                ],
            ],
        ]);

        $container = $configurator->createContainer();
        $this->entityManager = $container->getByType(EntityManagerInterface::class);

        self::assertSame(ConsoleCommand::SUCCESS, $this->migrate());
        (new BarbershopFixtures())->load($this->entityManager);
    }

    protected function tearDown(): void
    {
        $this->entityManager?->close();
        $this->entityManager = null;

        if ($this->databasePath !== '') {
            @unlink($this->databasePath);
        }

        if ($this->containerTempDirectory !== '' && is_dir($this->containerTempDirectory)) {
            FileSystem::delete($this->containerTempDirectory);
        }
    }

    public function testUpgradeAndDowngradePreserveBookingsAndOverlapProtection(): void
    {
        $this->insertBooking('eeeeeeee-eeee-eeee-eeee-000000000001');
        $timestamps = $this->storedTimestamps();

        self::assertSame(ConsoleCommand::SUCCESS, $this->migrate(Version20260910131913::class));
        self::assertNotContains('timezone', $this->businessColumns());
        self::assertSame($timestamps, $this->storedTimestamps());
        $this->assertOverlapSchemaObjectsExist();

        self::assertSame(ConsoleCommand::SUCCESS, $this->migrate(Version20260910170000::class));
        self::assertContains('timezone', $this->businessColumns());
        self::assertSame(['Europe/Prague', 'Europe/Prague'], $this->businessTimezones());
        self::assertSame($timestamps, $this->storedTimestamps());
        $this->assertOverlapSchemaObjectsExist();

        $this->entityManager()->clear();
        /** @var Booking $booking */
        $booking = $this->entityManager()->createQueryBuilder()
            ->select('booking')
            ->from(Booking::class, 'booking')
            ->getQuery()
            ->getSingleResult();
        self::assertSame('2026-09-14T07:00:00+00:00', $booking->getStartTime()->format(DateTimeInterface::ATOM));
    }

    public function testUpgradeRejectsUnreadableLegacyTimestamp(): void
    {
        self::assertSame(ConsoleCommand::SUCCESS, $this->migrate(Version20260910131913::class));

        foreach ([
            'eeeeeeee-eeee-eeee-eeee-000000000002' => '-0001-12-31 00:01:00',
            'eeeeeeee-eeee-eeee-eeee-000000000003' => "2026-09-14 07:00:00\0",
        ] as $id => $startTime) {
            $this->insertBooking($id, $startTime, '2026-09-14 07:30:00');

            try {
                $this->migrate(Version20260910170000::class);
                self::fail('The migration must reject a timestamp outside the canonical UTC storage format.');
            } catch (AbortMigration $exception) {
                self::assertStringContainsString($id, $exception->getMessage());
                self::assertStringContainsString('YYYY-MM-DD HH:MM:SS', $exception->getMessage());
            }

            $this->entityManager()->getConnection()->delete('barbershop_bookings', ['id' => $id]);
            self::assertNotContains('timezone', $this->businessColumns());
        }
    }

    private function migrate(string $version = 'latest'): int
    {
        $configurator = new Configurator();
        $configurator->setTempDirectory(
            $this->containerTempDirectory . '/migration-' . ++$this->migrationRun,
        );
        $configurator->addStaticParameters(['appDir' => $this->backendRoot . '/src']);
        $configurator->addConfig($this->backendRoot . '/config/config.neon');
        $configurator->addConfig([
            'nettrine.dbal' => [
                'connection' => [
                    'path' => $this->databasePath,
                ],
            ],
        ]);

        $container = $configurator->createContainer();
        $console = new Application();
        $console->setAutoExit(false);
        $console->setCatchExceptions(false);
        foreach ($container->findByType(ConsoleCommand::class) as $serviceId) {
            $console->add($container->getService($serviceId));
        }

        return $console->run(new ArrayInput([
            'command'          => 'migrations:migrate',
            'version'          => $version,
            '--no-interaction' => true,
        ]), new NullOutput());
    }

    private function insertBooking(
        string $id,
        string $startTime = '2026-09-14 07:00:00',
        string $endTime = '2026-09-14 07:30:00',
    ): void {
        $this->entityManager()->getConnection()->insert('barbershop_bookings', [
            'id'               => $id,
            'service_id'       => self::SERVICE,
            'stylist_id'       => self::STYLIST,
            'start_time'       => $startTime,
            'end_time'         => $endTime,
            'status'           => 'pending',
            'customer_name'    => 'Migration Test',
            'customer_contact' => 'migration@example.com',
        ]);
    }

    /** @return string[] */
    private function businessColumns(): array
    {
        return array_column(
            $this->entityManager()->getConnection()->fetchAllAssociative('PRAGMA table_info(barbershop_businesses)'),
            'name',
        );
    }

    /** @return string[] */
    private function businessTimezones(): array
    {
        return $this->entityManager()->getConnection()->fetchFirstColumn(
            'SELECT timezone FROM barbershop_businesses ORDER BY id',
        );
    }

    /** @return array{start_time: string, end_time: string} */
    private function storedTimestamps(): array
    {
        $row = $this->entityManager()->getConnection()->fetchAssociative(
            'SELECT start_time, end_time FROM barbershop_bookings ORDER BY id LIMIT 1',
        );

        if ($row === false) {
            throw new \LogicException('Expected a stored booking.');
        }

        return [
            'start_time' => (string) $row['start_time'],
            'end_time'   => (string) $row['end_time'],
        ];
    }

    private function assertOverlapSchemaObjectsExist(): void
    {
        self::assertSame([
            'IDX_BOOKINGS_ACTIVE_INTERVAL',
            'TRG_BOOKINGS_NO_ACTIVE_OVERLAP_INSERT',
            'TRG_BOOKINGS_NO_ACTIVE_OVERLAP_UPDATE',
        ], $this->entityManager()->getConnection()->fetchFirstColumn(<<<'SQL'
            SELECT name
            FROM sqlite_master
            WHERE name IN (
                'IDX_BOOKINGS_ACTIVE_INTERVAL',
                'TRG_BOOKINGS_NO_ACTIVE_OVERLAP_INSERT',
                'TRG_BOOKINGS_NO_ACTIVE_OVERLAP_UPDATE'
            )
            ORDER BY name
            SQL));
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->entityManager ?? throw new \LogicException('Entity manager is not initialized.');
    }
}
