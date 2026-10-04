<?php
declare(strict_types=1);

use Symfony\Component\Stopwatch\Stopwatch;
use Tests\Support\Journal;

use function Elephant\Flow\{async, await, delay};

it('returns the value of an async task', function (): void {
    expect(await(async(static fn (): int => 42)))->toBe(42);
});

it('runs tasks concurrently', function (): void {
    $journal = new Journal();

    $slow = async(static function () use ($journal): void {
        $journal->write('slow: start');
        await(delay(0.05));
        $journal->write('slow: done');
    });

    $fast = async(static function () use ($journal): void {
        $journal->write('fast: start');
        $journal->write('fast: done');
    });

    await($slow);
    await($fast);

    expect($journal->entries())->toBe(['slow: start', 'fast: start', 'fast: done', 'slow: done']);
});

it('waits for delays in parallel', function (): void {
    $stopwatch = new Stopwatch(true);
    $stopwatch->start('delays');

    $first = async(static fn (): mixed => await(delay(0.1)));
    $second = async(static fn (): mixed => await(delay(0.1)));

    await($first);
    await($second);

    expect($stopwatch->stop('delays')->getDuration())->toBeLessThan(180);
});

it('supports nested tasks', function (): void {
    $outer = async(static fn (): string => await(async(static fn (): string => 'inner')) . ' + outer');

    expect(await($outer))->toBe('inner + outer');
});

it('rethrows task errors on await', function (): void {
    $task = async(static fn (): never => throw new RuntimeException('boom'));

    expect(fn (): mixed => await($task))->toThrow(RuntimeException::class, 'boom');
});
