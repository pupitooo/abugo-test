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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rebing\GraphQL\GraphQL;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class CreateBookingTest extends TestCase
{
    private const BUSINESS  = '11111111-1111-1111-1111-111111111111';
    private const STYLIST_A = 'bbbbbbbb-bbbb-bbbb-bbbb-aaaaaaaaaaaa';
    private const STYLIST_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    private const SERVICE   = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const START     = '2026-09-14T07:00:00+00:00';
    private const SLOT_UNAVAILABLE_ERROR = 'This time slot is no longer available.';
    private const BOOKING_TEMPORARILY_UNAVAILABLE_ERROR = 'Booking service is temporarily unavailable. Please try again.';
    private const INVALID_CUSTOMER_NAME_ERROR = 'Customer name must not be blank.';
    private const INVALID_START_TIME_ERROR = 'Start time must use YYYY-MM-DDTHH:MM:SS+00:00; '
        . 'the booking must stay within UTC years 0000-9999.';

    private const CREATE_BOOKING_MUTATION = <<<'GRAPHQL'
        mutation CreateBooking($input: CreateBookingInput!, $serviceId: ID!, $date: String!) {
          createBooking(input: $input) {
            stylist {
              id
              availableSlots(serviceId: $serviceId, date: $date) {
                edges { node { startTime endTime } }
              }
            }
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

    private const CONFIRM_BOOKING_MUTATION = <<<'GRAPHQL'
        mutation ConfirmBooking($input: ConfirmBookingInput!) {
          confirmBooking(input: $input) {
            booking { id status }
            errors { field message }
          }
        }
        GRAPHQL;

    private const AVAILABLE_SLOTS_QUERY = <<<'GRAPHQL'
        query AvailableSlots($businessId: ID!, $serviceId: ID!, $date: String!) {
          business(id: $businessId) {
            timezone
            stylists {
              edges {
                node {
                  id
                  availableSlots(serviceId: $serviceId, date: $date) {
                    edges { node { startTime endTime } }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    private const BUSINESS_BOOKINGS_QUERY = <<<'GRAPHQL'
        query BusinessBookings($businessId: ID!) {
          businessBookings(businessId: $businessId) {
            edges { node { id startTime endTime } }
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

    #[DataProvider('blankCustomerNames')]
    public function testWhitespaceOnlyCustomerNameIsRejected(string $customerName): void
    {
        $result = $this->createBooking(
            customerName: $customerName,
            customerContact: 'customer@example.com',
        );

        self::assertArrayNotHasKey('errors', $result, 'Validation must not produce a top-level GraphQL error.');
        self::assertNull($result['data']['createBooking']['stylist']);
        self::assertSame([
            ['field' => 'customerName', 'message' => self::INVALID_CUSTOMER_NAME_ERROR],
        ], $result['data']['createBooking']['errors']);
        self::assertSame(0, $this->bookingCount());
    }

    /** @return iterable<string, array{string}> */
    public static function blankCustomerNames(): iterable
    {
        yield 'ASCII spaces' => ['   '];
        yield 'Unicode whitespace' => ["\u{00A0}\u{2003}\u{202F}\u{3000}"];
    }

    public function testStartTimeReturnedByAvailabilityCanBeSubmittedUnchanged(): void
    {
        $startTime = $this->availableSlotStartTimes('2026-09-14')[0];
        self::assertSame('2026-09-14T07:00:00+00:00', $startTime);

        $created = $this->createBooking(startTime: $startTime);

        $this->assertCreateBookingSucceeded($created, self::STYLIST_A);
        self::assertSame(1, $this->bookingCount());
    }

    public function testCanonicalUtcInputIsPersistedAndRehydratedAsUtc(): void
    {
        $created = $this->createBooking(startTime: '2026-09-14T07:00:00+00:00');

        $this->assertCreateBookingSucceeded($created, self::STYLIST_A);
        self::assertSame(
            '2026-09-14 07:00:00',
            $this->entityManager()->getConnection()->fetchOne(
                'SELECT start_time FROM barbershop_bookings LIMIT 1',
            ),
        );

        $this->entityManager()->clear();
        $persistedBooking = $this->entityManager()->getRepository(\App\Domain\Barbershop\Entity\Booking::class)
            ->findOneBy([]);
        self::assertInstanceOf(\App\Domain\Barbershop\Entity\Booking::class, $persistedBooking);
        self::assertSame('UTC', $persistedBooking->getStartTime()->getTimezone()->getName());
        self::assertSame('UTC', $persistedBooking->getEndTime()->getTimezone()->getName());

        $bookings = $this->businessBookings();

        self::assertCount(1, $bookings);
        self::assertSame('2026-09-14T07:00:00+00:00', $bookings[0]['startTime']);
        self::assertSame('2026-09-14T07:30:00+00:00', $bookings[0]['endTime']);
    }

    #[DataProvider('nonCanonicalStartTimes')]
    public function testNonCanonicalStartTimeIsRejectedWithControlledDomainError(string $startTime): void
    {
        $result = $this->createBooking(startTime: $startTime);

        $this->assertCreateBookingStartTimeInvalid($result);
        self::assertSame(0, $this->bookingCount());
    }

    /** @return array<string, array{string}> */
    public static function nonCanonicalStartTimes(): array
    {
        return [
            'Z suffix instead of canonical +00:00' => ['2026-09-14T07:00:00Z'],
            'non-zero UTC offset'                  => ['2026-09-14T09:00:00+02:00'],
            'negative zero UTC offset'             => ['2026-09-14T07:00:00-00:00'],
            'missing UTC offset'                   => ['2026-09-14T07:00:00'],
            'missing seconds'                      => ['2026-09-14T07:00+00:00'],
            'fractional seconds'                   => ['2026-09-14T07:00:00.000+00:00'],
            'invalid calendar date'                => ['2026-02-30T07:00:00+00:00'],
            'invalid time'                         => ['2026-09-14T24:00:00+00:00'],
            'non-padded date and time fields'      => ['2026-9-14T7:00:00+00:00'],
            'leading whitespace'                   => [' 2026-09-14T07:00:00+00:00'],
        ];
    }

    public function testBookingEndOutsideFourDigitUtcYearReturnsControlledDomainError(): void
    {
        $result = $this->createBooking(startTime: '9999-12-31T23:45:00+00:00');

        $this->assertCreateBookingStartTimeInvalid($result);
        self::assertSame(0, $this->bookingCount());
    }

    public function testPragueOpeningSlotIsSerializedAsUtcInWinterAndSummer(): void
    {
        self::assertSame(
            '2026-01-12T08:00:00+00:00',
            $this->availableSlotStartTimes('2026-01-12')[0],
        );
        self::assertSame(
            '2026-07-13T07:00:00+00:00',
            $this->availableSlotStartTimes('2026-07-13')[0],
        );
    }

    public function testSlotsUseTheConfiguredNonPragueBusinessTimezone(): void
    {
        $this->entityManager()->getConnection()->update(
            'barbershop_businesses',
            ['timezone' => 'America/New_York'],
            ['id' => self::BUSINESS],
        );
        $this->entityManager()->clear();

        self::assertSame(
            '2026-01-12T14:00:00+00:00',
            $this->availableSlotStartTimes('2026-01-12', 'America/New_York')[0],
        );
        self::assertSame(
            '2026-07-13T13:00:00+00:00',
            $this->availableSlotStartTimes('2026-07-13', 'America/New_York')[0],
        );
    }

    public function testSlotsFollowPragueClockAcrossDstTransitions(): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO barbershop_opening_hours (id, business_id, day_of_week, open_from, open_to)
                VALUES (:id, :businessId, 7, '01:00', '04:00')
                SQL,
            [
                'id'         => 'dddddddd-dddd-dddd-dddd-000000000007',
                'businessId' => self::BUSINESS,
            ],
        );
        $this->entityManager()->clear();

        self::assertSame([
            '2026-03-29T00:00:00+00:00',
            '2026-03-29T00:30:00+00:00',
            '2026-03-29T01:00:00+00:00',
            '2026-03-29T01:30:00+00:00',
        ], $this->availableSlotStartTimes('2026-03-29'));

        self::assertSame([
            '2026-10-24T23:00:00+00:00',
            '2026-10-24T23:30:00+00:00',
            '2026-10-25T00:00:00+00:00',
            '2026-10-25T00:30:00+00:00',
            '2026-10-25T01:00:00+00:00',
            '2026-10-25T01:30:00+00:00',
            '2026-10-25T02:00:00+00:00',
            '2026-10-25T02:30:00+00:00',
        ], $this->availableSlotStartTimes('2026-10-25'));
    }

    public function testBookingCrossingLocalMidnightBlocksOverlappingSlotOnNextDay(): void
    {
        $this->useMidnightOpeningHours(2);

        $created = $this->createBooking(
            customerName: 'Midnight Customer',
            startTime: '2026-09-14T21:50:00+00:00',
        );
        $this->assertCreateBookingSucceeded($created, self::STYLIST_A);

        self::assertSame(
            ['2026-09-14T22:30:00+00:00'],
            $this->availableSlotStartTimes('2026-09-15'),
        );
    }

    public function testBookingStartingExactlyAtDayStartBlocksTheFirstSlot(): void
    {
        $this->useMidnightOpeningHours(2);
        $this->insertBookingInterval(
            startTime: '2026-09-14 22:00:00',
            endTime: '2026-09-14 22:30:00',
        );

        self::assertSame(
            ['2026-09-14T22:30:00+00:00'],
            $this->availableSlotStartTimes('2026-09-15'),
        );
    }

    public function testBookingEndingExactlyAtDayStartDoesNotBlockAnySlot(): void
    {
        $this->useMidnightOpeningHours(2);
        $this->insertBookingInterval(
            startTime: '2026-09-14 21:30:00',
            endTime: '2026-09-14 22:00:00',
        );

        self::assertSame(
            [
                '2026-09-14T22:00:00+00:00',
                '2026-09-14T22:30:00+00:00',
            ],
            $this->availableSlotStartTimes('2026-09-15'),
        );
    }

    public function testBookingStartingExactlyAtDayEndBelongsToFollowingDay(): void
    {
        $this->useMidnightOpeningHours(2, 3);
        $this->insertBookingInterval(
            startTime: '2026-09-15 22:00:00',
            endTime: '2026-09-15 22:30:00',
        );

        self::assertSame(
            [
                '2026-09-14T22:00:00+00:00',
                '2026-09-14T22:30:00+00:00',
            ],
            $this->availableSlotStartTimes('2026-09-15'),
        );
        self::assertSame(
            ['2026-09-15T22:30:00+00:00'],
            $this->availableSlotStartTimes('2026-09-16'),
        );
    }

    public function testBookingAdjacentToFirstSlotDoesNotBlockIt(): void
    {
        $this->useMidnightOpeningHours(2);
        $this->insertBookingInterval(
            startTime: '2026-09-14 22:30:00',
            endTime: '2026-09-14 23:00:00',
        );

        self::assertSame(
            ['2026-09-14T22:00:00+00:00'],
            $this->availableSlotStartTimes('2026-09-15'),
        );
    }

    public function testAvailableSlotsExcludesTheCreatedBooking(): void
    {
        $result = $this->createBooking(customerName: 'First Customer');

        $this->assertCreateBookingSucceeded($result, self::STYLIST_A);
        $edges = $result['data']['createBooking']['stylist']['availableSlots']['edges'];
        $availableStartTimes = array_map(
            static fn(array $edge): string => (new \DateTimeImmutable($edge['node']['startTime']))
                ->setTimezone(new \DateTimeZone('Europe/Prague'))
                ->format('H:i'),
            $edges,
        );

        self::assertCount(17, $availableStartTimes);
        self::assertNotContains('09:00', $availableStartTimes);
    }

    public function testDuplicateBookingIsReturnedAsDomainErrorAndNotPersisted(): void
    {
        $first = $this->createBooking(customerName: 'First Customer');
        $conflict = $this->createBooking(customerName: 'Second Customer');

        $this->assertCreateBookingSucceeded($first, self::STYLIST_A);
        $this->assertCreateBookingRejected($conflict);
        self::assertSame(1, $this->bookingCount(), 'A conflicting booking must not be persisted.');
    }

    public function testSameTimeForDifferentStylistIsAllowed(): void
    {
        $first = $this->createBooking(stylistId: self::STYLIST_A, customerName: 'First Customer');
        $second = $this->createBooking(stylistId: self::STYLIST_B, customerName: 'Second Customer');

        $this->assertCreateBookingSucceeded($first, self::STYLIST_A);
        $this->assertCreateBookingSucceeded($second, self::STYLIST_B);
        self::assertSame(2, $this->bookingCount());
    }

    public function testOverlappingBookingIsRejected(): void
    {
        $first = $this->createBooking(customerName: 'First Customer');
        $overlap = $this->createBooking(
            customerName: 'Second Customer',
            startTime: '2026-09-14T07:10:00+00:00',
        );

        $this->assertCreateBookingSucceeded($first, self::STYLIST_A);
        $this->assertCreateBookingRejected($overlap);
        self::assertSame(1, $this->bookingCount());
    }

    public function testAdjacentBookingIsAllowed(): void
    {
        $first = $this->createBooking(customerName: 'First Customer');
        $adjacent = $this->createBooking(
            customerName: 'Second Customer',
            startTime: '2026-09-14T07:30:00+00:00',
        );

        $this->assertCreateBookingSucceeded($first, self::STYLIST_A);
        $this->assertCreateBookingSucceeded($adjacent, self::STYLIST_A);
        self::assertSame(2, $this->bookingCount());
    }

    public function testRejectedBookingDoesNotBlockSlot(): void
    {
        $created = $this->createBooking(customerName: 'First Customer');
        $this->assertCreateBookingSucceeded($created, self::STYLIST_A);

        $bookingId = $this->firstBookingId();

        $rejected = $this->rejectBooking($bookingId);
        self::assertArrayNotHasKey('errors', $rejected, 'Reject mutation must not produce a top-level GraphQL error.');
        self::assertSame([], $rejected['data']['rejectBooking']['errors']);
        self::assertSame('REJECTED', $rejected['data']['rejectBooking']['booking']['status']);

        $replacement = $this->createBooking(customerName: 'Second Customer');
        $this->assertCreateBookingSucceeded($replacement, self::STYLIST_A);
        self::assertSame(2, $this->bookingCount());
    }

    public function testRejectedBookingCannotBeReactivatedOverAnActiveBooking(): void
    {
        $created = $this->createBooking(customerName: 'First Customer');
        $this->assertCreateBookingSucceeded($created, self::STYLIST_A);
        $originalBookingId = $this->firstBookingId();

        $rejected = $this->rejectBooking($originalBookingId);
        self::assertSame([], $rejected['data']['rejectBooking']['errors']);

        $replacement = $this->createBooking(customerName: 'Second Customer');
        $this->assertCreateBookingSucceeded($replacement, self::STYLIST_A);

        $confirmation = $this->confirmBooking($originalBookingId);
        self::assertArrayNotHasKey('errors', $confirmation);
        self::assertNull($confirmation['data']['confirmBooking']['booking']);
        self::assertSame([
            ['field' => null, 'message' => self::SLOT_UNAVAILABLE_ERROR],
        ], $confirmation['data']['confirmBooking']['errors']);
        self::assertSame(
            ['pending', 'rejected'],
            $this->entityManager()->getConnection()->fetchFirstColumn(
                'SELECT status FROM barbershop_bookings ORDER BY status',
            ),
        );
    }

    public function testDatabaseLockTimeoutIsReturnedAsDomainGraphqlError(): void
    {
        $lockingConnection = new \PDO(
            'sqlite:' . $this->databasePath,
            options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $lockingConnection->exec('BEGIN IMMEDIATE');
        $this->entityManager()->getConnection()->executeStatement('PRAGMA busy_timeout = 0');

        try {
            $result = $this->createBooking(customerName: 'Locked Customer');
        } finally {
            $lockingConnection->exec('ROLLBACK');
        }

        self::assertArrayNotHasKey('errors', $result, 'A lock timeout must not produce a top-level GraphQL error.');
        self::assertNull($result['data']['createBooking']['stylist']);
        self::assertSame([
            ['field' => null, 'message' => self::BOOKING_TEMPORARILY_UNAVAILABLE_ERROR],
        ], $result['data']['createBooking']['errors']);
        self::assertSame(0, $this->bookingCount());
    }

    public function testConcurrentDatabaseWritesCreateOnlyOneActiveBooking(): void
    {
        $firstWorker = null;
        $secondWorker = null;

        try {
            $firstWorker = $this->startConcurrentBookingWorker(
                'eeeeeeee-eeee-eeee-eeee-000000000001',
                'holder',
            );
            $secondWorker = $this->startConcurrentBookingWorker(
                'eeeeeeee-eeee-eeee-eeee-000000000002',
                'contender',
            );

            $this->sendWorkerCommand($firstWorker, 'GO');
            self::assertSame('LOCKED', $this->readWorkerLine($firstWorker['pipes'][1]));

            $this->sendWorkerCommand($secondWorker, 'GO');
            $blockedResult = $this->readWorkerResult($secondWorker['pipes'][1]);
            self::assertSame('blocked', $blockedResult['status']);
            self::assertSame(5, $blockedResult['code']);

            $this->sendWorkerCommand($firstWorker, 'COMMIT');
            $firstResult = $this->readWorkerResult($firstWorker['pipes'][1]);

            $this->sendWorkerCommand($secondWorker, 'RETRY');
            $secondResult = $this->readWorkerResult($secondWorker['pipes'][1]);
            $firstError = stream_get_contents($firstWorker['pipes'][2]);
            $secondError = stream_get_contents($secondWorker['pipes'][2]);

            $firstExitCode = $this->closeWorker($firstWorker);
            $firstWorker = null;
            $secondExitCode = $this->closeWorker($secondWorker);
            $secondWorker = null;
        } finally {
            if ($firstWorker !== null) {
                $this->terminateWorker($firstWorker);
            }
            if ($secondWorker !== null) {
                $this->terminateWorker($secondWorker);
            }
        }

        self::assertSame(0, $firstExitCode, $firstError);
        self::assertSame(0, $secondExitCode, $secondError);
        self::assertSame(['status' => 'created'], $firstResult);
        self::assertSame('error', $secondResult['status']);
        self::assertSame('23000', $secondResult['sqlState']);
        self::assertSame(19, $secondResult['code']);
        self::assertStringContainsString('booking_slot_unavailable', $secondResult['message']);
        self::assertSame(1, $this->bookingCount());
    }

    public function testUnexpectedDatabaseFailureIsNotConvertedToSlotUnavailable(): void
    {
        $this->entityManager()->getConnection()->executeStatement(<<<'SQL'
            CREATE TRIGGER TRG_BOOKINGS_UNEXPECTED_FAILURE
            BEFORE INSERT ON barbershop_bookings
            BEGIN
                SELECT RAISE(ABORT, 'unexpected_database_failure');
            END
            SQL);

        $result = $this->createBooking(customerName: 'Test Customer');

        self::assertArrayHasKey('errors', $result);
        self::assertNull($result['data']['createBooking'] ?? null);
        self::assertStringNotContainsString(self::SLOT_UNAVAILABLE_ERROR, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->bookingCount());
    }

    /** @return array<string, mixed> */
    private function createBooking(
        string $stylistId = self::STYLIST_A,
        string $customerName = 'Test Customer',
        string $startTime = self::START,
        ?string $customerContact = null,
    ): array {
        return $this->graphql()->query(self::CREATE_BOOKING_MUTATION, [
            'input' => [
                'stylistId'       => $stylistId,
                'serviceId'       => self::SERVICE,
                'startTime'       => $startTime,
                'customerName'    => $customerName,
                'customerContact' => $customerContact
                    ?? strtolower(str_replace(' ', '.', $customerName)) . '@example.com',
            ],
            'serviceId' => self::SERVICE,
            'date'      => '2026-09-14',
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

    /** @return array<string, mixed> */
    private function confirmBooking(string $bookingId): array
    {
        return $this->graphql()->query(self::CONFIRM_BOOKING_MUTATION, [
            'input' => [
                'bookingId' => $bookingId,
                'stylistId' => self::STYLIST_A,
            ],
        ]);
    }

    /** @return string[] */
    private function availableSlotStartTimes(
        string $date,
        string $expectedTimezone = 'Europe/Prague',
    ): array
    {
        $result = $this->graphql()->query(self::AVAILABLE_SLOTS_QUERY, [
            'businessId' => self::BUSINESS,
            'serviceId'  => self::SERVICE,
            'date'       => $date,
        ]);

        self::assertArrayNotHasKey('errors', $result);
        self::assertNotNull($result['data']['business']);
        self::assertSame($expectedTimezone, $result['data']['business']['timezone']);

        foreach ($result['data']['business']['stylists']['edges'] as $edge) {
            if ($edge['node']['id'] !== self::STYLIST_A) {
                continue;
            }

            return array_map(
                static fn(array $slotEdge): string => $slotEdge['node']['startTime'],
                $edge['node']['availableSlots']['edges'],
            );
        }

        self::fail('Expected stylist was not returned by the business query.');
    }

    /** @return array<int, array{id: string, startTime: string, endTime: string}> */
    private function businessBookings(): array
    {
        $result = $this->graphql()->query(self::BUSINESS_BOOKINGS_QUERY, [
            'businessId' => self::BUSINESS,
        ]);

        self::assertArrayNotHasKey('errors', $result);

        return array_map(
            static fn(array $edge): array => $edge['node'],
            $result['data']['businessBookings']['edges'],
        );
    }

    private function useMidnightOpeningHours(int ...$daysOfWeek): void
    {
        foreach ($daysOfWeek as $dayOfWeek) {
            $this->entityManager()->getConnection()->executeStatement(
                <<<'SQL'
                    UPDATE barbershop_opening_hours
                    SET open_from = '00:00', open_to = '01:00'
                    WHERE business_id = :businessId AND day_of_week = :dayOfWeek
                    SQL,
                [
                    'businessId' => self::BUSINESS,
                    'dayOfWeek'  => $dayOfWeek,
                ],
            );
        }
        $this->entityManager()->clear();
    }

    private function insertBookingInterval(string $startTime, string $endTime): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO barbershop_bookings (
                    id,
                    service_id,
                    stylist_id,
                    start_time,
                    end_time,
                    status,
                    customer_name,
                    customer_contact
                ) VALUES (
                    :id,
                    :serviceId,
                    :stylistId,
                    :startTime,
                    :endTime,
                    'pending',
                    'Interval Boundary Customer',
                    'interval.boundary@example.com'
                )
                SQL,
            [
                'id'        => 'eeeeeeee-eeee-eeee-eeee-000000000003',
                'serviceId' => self::SERVICE,
                'stylistId' => self::STYLIST_A,
                'startTime' => $startTime,
                'endTime'   => $endTime,
            ],
        );
        $this->entityManager()->clear();
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
        self::assertSame([
            ['field' => 'startTime', 'message' => self::SLOT_UNAVAILABLE_ERROR],
        ], $result['data']['createBooking']['errors']);
    }

    /** @param array<string, mixed> $result */
    private function assertCreateBookingStartTimeInvalid(array $result): void
    {
        self::assertArrayNotHasKey('errors', $result, 'Invalid input must not produce a top-level GraphQL error.');
        self::assertNull($result['data']['createBooking']['stylist']);
        self::assertSame([
            ['field' => 'startTime', 'message' => self::INVALID_START_TIME_ERROR],
        ], $result['data']['createBooking']['errors']);
    }

    private function bookingCount(): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM barbershop_bookings',
        );
    }

    private function firstBookingId(): string
    {
        $bookingId = $this->entityManager()->getConnection()->fetchOne(
            'SELECT id FROM barbershop_bookings ORDER BY id LIMIT 1',
        );
        self::assertIsString($bookingId);

        return $bookingId;
    }

    /**
     * @return array{
     *     process: resource,
     *     pipes: array{0: resource, 1: resource, 2: resource}
     * }
     */
    private function startConcurrentBookingWorker(string $bookingId, string $mode): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__) . '/Support/concurrent-booking-worker.php',
                $this->databasePath,
                $bookingId,
                $mode,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        self::assertCount(3, $pipes);
        stream_set_timeout($pipes[1], 10);

        self::assertSame('READY', $this->readWorkerLine($pipes[1]));

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param array{
     *     process: resource,
     *     pipes: array{0: resource, 1: resource, 2: resource}
     * } $worker
     */
    private function sendWorkerCommand(array $worker, string $command): void
    {
        self::assertNotFalse(fwrite($worker['pipes'][0], $command . "\n"));
        self::assertTrue(fflush($worker['pipes'][0]));
    }

    /** @param resource $stream */
    private function readWorkerLine($stream): string
    {
        $line = fgets($stream);
        $metadata = stream_get_meta_data($stream);

        self::assertFalse($metadata['timed_out'], 'Concurrent booking worker timed out.');
        self::assertNotFalse($line, 'Concurrent booking worker ended without a response.');

        return trim($line);
    }

    /**
     * @param resource $stream
     * @return array<string, mixed>
     */
    private function readWorkerResult($stream): array
    {
        $result = json_decode($this->readWorkerLine($stream), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array{
     *     process: resource,
     *     pipes: array{0: resource, 1: resource, 2: resource}
     * } $worker
     */
    private function closeWorker(array $worker): int
    {
        foreach ($worker['pipes'] as $pipe) {
            fclose($pipe);
        }

        return proc_close($worker['process']);
    }

    /**
     * @param array{
     *     process: resource,
     *     pipes: array{0: resource, 1: resource, 2: resource}
     * } $worker
     */
    private function terminateWorker(array $worker): void
    {
        proc_terminate($worker['process']);
        $this->closeWorker($worker);
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
