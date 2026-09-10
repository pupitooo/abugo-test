<?php

declare(strict_types=1);

// Run in separate processes to simulate independent SQLite connections and verify
// that the database rejects overlapping bookings atomically under concurrent writes.
if ($argc !== 4) {
    fwrite(STDERR, "Expected database path, booking ID, and lock duration.\n");
    exit(2);
}

[, $databasePath, $bookingId, $lockDurationMilliseconds] = $argv;

$connection = new PDO(
    'sqlite:' . $databasePath,
    options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ],
);
$connection->exec('PRAGMA foreign_keys = ON');

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

if (trim((string) fgets(STDIN)) !== 'GO') {
    fwrite(STDERR, "Expected GO signal.\n");
    exit(2);
}

try {
    if ((int) $lockDurationMilliseconds > 0) {
        $connection->beginTransaction();
    }

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

    if ($connection->inTransaction()) {
        fwrite(STDOUT, "LOCKED\n");
        fflush(STDOUT);
        usleep((int) $lockDurationMilliseconds * 1_000);
        $connection->commit();
    }

    fwrite(STDOUT, json_encode(['status' => 'created'], JSON_THROW_ON_ERROR) . "\n");
} catch (PDOException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }

    fwrite(STDOUT, json_encode([
        'status'   => 'error',
        'sqlState' => $exception->errorInfo[0] ?? null,
        'code'     => $exception->errorInfo[1] ?? null,
        'message'  => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR) . "\n");
}
