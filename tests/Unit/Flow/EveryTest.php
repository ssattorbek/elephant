<?php
declare(strict_types=1);

use Elephant\ElephantException;
use Elephant\Application;
use Elephant\Flow\Interval;
use Elephant\Future\CancelledException;
use Symfony\Component\Stopwatch\Stopwatch;
use Tests\Support\Gauge;
use Tests\Support\Journal;

use function Elephant\Flow\{await, delay, every};

it('runs the callback the given number of times', function (): void {
    $journal = new Journal();

    every(0.01, $journal->record('tick'), times: 3)->await();

    expect($journal->entries())->toBe(['tick', 'tick', 'tick']);
});

it('waits one interval before the first run', function (): void {
    $stopwatch = new Stopwatch(true);
    $stopwatch->start('every');

    every(0.05, static fn (): null => null, times: 1)->await();

    expect($stopwatch->stop('every')->getDuration())->toBeGreaterThanOrEqual(50);
});

it('never overlaps runs', function (): void {
    $gauge = new Gauge();

    every(0.01, $gauge->task(0.03), times: 3)->await();

    expect($gauge->peak())->toBe(1);
});

it('keeps running until it is cancelled', function (): void {
    $journal = new Journal();
    $interval = every(0.01, $journal->record('tick'));

    await(delay(0.05));
    $interval->cancel();
    $runs = count($journal->entries());
    await(delay(0.05));

    expect($runs)->toBeGreaterThan(1)
        ->and($journal->entries())->toHaveCount($runs);
});

it('lets the script finish right after it is cancelled', function (): void {
    every(10, static fn (): null => null)->cancel();

    $stopwatch = new Stopwatch(true);
    $stopwatch->start('loop');

    Application::loop()->run();

    expect($stopwatch->stop('loop')->getDuration())->toBeLessThan(100);
});

it('throws when awaiting a cancelled interval', function (): void {
    $interval = every(0.01, static fn (): null => null);
    $interval->cancel();

    expect(function () use ($interval): void {
        $interval->await();
    })->toThrow(CancelledException::class);
});

it('stops and fails when a run throws', function (): void {
    $journal = new Journal();

    $interval = every(0.01, static function () use ($journal): never {
        $journal->write('run');

        throw new RuntimeException('boom');
    }, times: 3);

    expect(function () use ($interval): void {
        $interval->await();
    })->toThrow(RuntimeException::class, 'boom')
        ->and($journal->entries())->toBe(['run']);
});

it('rejects an interval that is not positive', function (): void {
    expect(fn (): Interval => every(0, static fn (): null => null))->toThrow(ElephantException::class, 'The interval must be longer than zero seconds.');
});

it('rejects a limit below one', function (): void {
    expect(fn (): Interval => every(1, static fn (): null => null, times: 0))->toThrow(ElephantException::class, 'An interval must run at least once.');
});
