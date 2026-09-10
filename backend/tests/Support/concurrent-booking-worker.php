<?php

declare(strict_types=1);

// Run in separate processes to prove that one SQLite writer is blocked while another
// holds the transaction, then verify that retrying is rejected by the overlap trigger.
if ($argc !== 4) {
    fwrite(STDERR, "Expected database path, booking ID, and worker mode.\n");
    exit(2);
}

[, $databasePath, $bookingId, $mode] = $argv;

if (!in_array($mode, ['holder', 'contender'], true)) {
    fwrite(STDERR, "Expected holder or contender worker mode.\n");
    exit(2);
}

$connection = new PDO(
    'sqlite:' . $databasePath,
    options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 0,
    ],
);
$connection->exec('PRAGMA foreign_keys = ON');
$connection->exec('PRAGMA busy_timeout = 0');

$writeLine = static function (string $line): void {
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
};

/** @param array<string, mixed> $result */
$writeResult = static function (array $result) use ($writeLine): void {
    $writeLine(json_encode($result, JSON_THROW_ON_ERROR));
};

$readCommand = static function (string $expected): void {
    if (trim((string) fgets(STDIN)) !== $expected) {
        fwrite(STDERR, "Expected $expected signal.\n");
        exit(2);
    }
};

/** @return array<string, mixed> */
$errorResult = static function (PDOException $exception): array {
    return [
        'status'   => 'error',
        'sqlState' => $exception->errorInfo[0] ?? null,
        'code'     => $exception->errorInfo[1] ?? null,
        'message'  => $exception->getMessage(),
    ];
};

$writeLine('READY');
$readCommand('GO');

$insertBooking = static function () use ($connection, $bookingId): void {
    $statement = $connection->prepare(<<<'SQL'
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
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'bbbbbbbb-bbbb-bbbb-bbbb-aaaaaaaaaaaa',
            '2026-09-14 09:00:00',
            '2026-09-14 09:30:00',
            'pending',
            :customer_name,
            :customer_contact
        )
        SQL);
    $statement->execute([
        'id'               => $bookingId,
        'customer_name'    => 'Concurrent ' . $bookingId,
        'customer_contact' => $bookingId . '@example.com',
    ]);
};

if ($mode === 'holder') {
    try {
        $connection->beginTransaction();
        $insertBooking();
        $writeLine('LOCKED');
        $readCommand('COMMIT');
        $connection->commit();
        $writeResult(['status' => 'created']);
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        $writeResult($errorResult($exception));
    }

    exit(0);
}

try {
    $insertBooking();
    $writeResult(['status' => 'created']);
    exit(0);
} catch (PDOException $exception) {
    if (($exception->errorInfo[1] ?? null) !== 5) {
        $writeResult($errorResult($exception));
        exit(0);
    }

    $writeResult([
        'status'   => 'blocked',
        'sqlState' => $exception->errorInfo[0] ?? null,
        'code'     => $exception->errorInfo[1],
    ]);
}

$readCommand('RETRY');

try {
    $insertBooking();
    $writeResult(['status' => 'created']);
} catch (PDOException $exception) {
    $writeResult($errorResult($exception));
}
