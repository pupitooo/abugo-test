<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Fixture\BarbershopFixtures;
use App\UserInterface\GraphQL\Bootstrap as GraphQLBootstrap;
use App\UserInterface\GraphQL\NullExceptionHandler;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Facade;
use Nette\Bootstrap\Configurator;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use Rebing\GraphQL\GraphQL;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class CreateBookingTest extends TestCase
{
    private const STYLIST_A = 'bbbbbbbb-bbbb-bbbb-bbbb-aaaaaaaaaaaa';
    private const STYLIST_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    private const SERVICE   = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const START     = '2026-09-14T09:00:00+02:00';

    private const CREATE_BOOKING_MUTATION = <<<'GRAPHQL'
        mutation CreateBooking($input: CreateBookingInput!) {
          createBooking(input: $input) {
            stylist { id }
            errors { field message }
          }
        }
        GRAPHQL;

    private const REJECT_BOOKING_MUTATION = <<<'GRAPHQL'
        mutation RejectBooking($input: RejectBookingInput!) {
          rejectBooking(input: $input) {
            booking { id status }
            errors { field message }
          }
        }
        GRAPHQL;

    private ?EntityManagerInterface $entityManager = null;
    private ?GraphQL $graphql = null;
    private string $databasePath = '';
    private string $containerTempDirectory = '';

    protected function setUp(): void
    {
        $backendRoot = dirname(__DIR__, 2);
        $testId = getmypid() . '-' . bin2hex(random_bytes(4));

        $this->databasePath = sys_get_temp_dir() . "/abugo-$testId.sqlite";
        $this->containerTempDirectory = sys_get_temp_dir() . "/abugo-container-$testId";

        $configurator = new Configurator();
        $configurator->setTempDirectory($this->containerTempDirectory);
        $configurator->addStaticParameters(['appDir' => $backendRoot . '/src']);
        $configurator->addConfig($backendRoot . '/config/config.neon');
        $configurator->addConfig([
            'nettrine.dbal' => [
                'connection' => [
                    'path' => $this->databasePath,
                ],
            ],
        ]);

        $netteContainer = $configurator->createContainer();
        $this->entityManager = $netteContainer->getByType(EntityManagerInterface::class);

        $console = new Application();
        $console->setAutoExit(false);
        $console->setCatchExceptions(false);
        foreach ($netteContainer->findByType(ConsoleCommand::class) as $serviceId) {
            $console->add($netteContainer->getService($serviceId));
        }
        $exitCode = $console->run(new ArrayInput([
            'command'          => 'migrations:migrate',
            '--no-interaction' => true,
        ]), new NullOutput());
        self::assertSame(ConsoleCommand::SUCCESS, $exitCode, 'Test database migration failed.');

        (new BarbershopFixtures())->load($this->entityManager);

        Facade::clearResolvedInstances();
        $graphqlContainer = GraphQLBootstrap::createContainer($netteContainer);
        $graphqlContainer->instance(ExceptionHandler::class, new NullExceptionHandler());
        $this->graphql = $graphqlContainer->make(GraphQL::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager?->close();
        $this->entityManager = null;
        $this->graphql = null;

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        IlluminateContainer::setInstance(null);

        if ($this->databasePath !== '') {
            @unlink($this->databasePath);
        }

        if ($this->containerTempDirectory !== '' && is_dir($this->containerTempDirectory)) {
            FileSystem::delete($this->containerTempDirectory);
        }
    }

    public function testFirstBookingIsCreated(): void
    {
        $result = $this->createBooking(customerName: 'First Customer');

        $this->assertCreateBookingSucceeded($result, self::STYLIST_A);
        self::assertSame(1, $this->bookingCount());
    }

    public function testDuplicateBookingIsRejectedAndOnlyOneRowRemains(): void
    {
        $first = $this->createBooking(customerName: 'First Customer');
        $second = $this->createBooking(customerName: 'Second Customer');

        $this->assertCreateBookingSucceeded($first, self::STYLIST_A);
        self::assertSame(1, $this->bookingCount(), 'A conflicting booking must not be persisted.');
        $this->assertCreateBookingRejected($second);
    }

    public function testSameTimeForDifferentStylistIsAllowed(): void
    {
        $first = $this->createBooking(stylistId: self::STYLIST_A, customerName: 'First Customer');
        $second = $this->createBooking(stylistId: self::STYLIST_B, customerName: 'Second Customer');

        $this->assertCreateBookingSucceeded($first, self::STYLIST_A);
        $this->assertCreateBookingSucceeded($second, self::STYLIST_B);
        self::assertSame(2, $this->bookingCount());
    }

    public function testRejectedBookingDoesNotBlockSlot(): void
    {
        $created = $this->createBooking(customerName: 'First Customer');
        $this->assertCreateBookingSucceeded($created, self::STYLIST_A);

        $bookingId = $this->entityManager()->getConnection()->fetchOne(
            'SELECT id FROM barbershop_bookings LIMIT 1',
        );
        self::assertIsString($bookingId);

        $rejected = $this->rejectBooking($bookingId);
        self::assertArrayNotHasKey('errors', $rejected, 'Reject mutation must not produce a top-level GraphQL error.');
        self::assertSame([], $rejected['data']['rejectBooking']['errors']);
        self::assertSame('REJECTED', $rejected['data']['rejectBooking']['booking']['status']);

        $replacement = $this->createBooking(customerName: 'Second Customer');
        $this->assertCreateBookingSucceeded($replacement, self::STYLIST_A);
        self::assertSame(2, $this->bookingCount());
    }

    public function testBookingConflictIsReturnedAsDomainGraphqlError(): void
    {
        $first = $this->createBooking(customerName: 'First Customer');
        $conflict = $this->createBooking(customerName: 'Second Customer');

        $this->assertCreateBookingSucceeded($first, self::STYLIST_A);
        $this->assertCreateBookingRejected($conflict);
    }

    /** @return array<string, mixed> */
    private function createBooking(
        string $stylistId = self::STYLIST_A,
        string $customerName = 'Test Customer',
    ): array {
        return $this->graphql()->query(self::CREATE_BOOKING_MUTATION, [
            'input' => [
                'stylistId'       => $stylistId,
                'serviceId'       => self::SERVICE,
                'startTime'       => self::START,
                'customerName'    => $customerName,
                'customerContact' => strtolower(str_replace(' ', '.', $customerName)) . '@example.com',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function rejectBooking(string $bookingId): array
    {
        return $this->graphql()->query(self::REJECT_BOOKING_MUTATION, [
            'input' => [
                'bookingId' => $bookingId,
                'stylistId' => self::STYLIST_A,
                'reason'    => 'Test rejection',
            ],
        ]);
    }

    /** @param array<string, mixed> $result */
    private function assertCreateBookingSucceeded(array $result, string $stylistId): void
    {
        self::assertArrayNotHasKey('errors', $result, 'Mutation must not produce a top-level GraphQL error.');
        self::assertSame([], $result['data']['createBooking']['errors']);
        self::assertSame($stylistId, $result['data']['createBooking']['stylist']['id']);
    }

    /** @param array<string, mixed> $result */
    private function assertCreateBookingRejected(array $result): void
    {
        self::assertArrayNotHasKey(
            'errors',
            $result,
            'The expected booking conflict must be handled as a domain error, not a top-level GraphQL error.',
        );
        self::assertNull($result['data']['createBooking']['stylist']);
        self::assertNotEmpty($result['data']['createBooking']['errors']);
        self::assertNull($result['data']['createBooking']['errors'][0]['field']);
        self::assertNotSame('', $result['data']['createBooking']['errors'][0]['message']);
    }

    private function bookingCount(): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM barbershop_bookings',
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->entityManager ?? throw new \LogicException('Entity manager is not initialized.');
    }

    private function graphql(): GraphQL
    {
        return $this->graphql ?? throw new \LogicException('GraphQL is not initialized.');
    }
}
