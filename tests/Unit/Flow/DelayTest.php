<?php
declare(strict_types=1);

use Elephant\Application;
use Elephant\ElephantException;
use Elephant\Future\CancelledException;
use Elephant\Future\Status;
use Elephant\Future\TimeoutException;
use Symfony\Component\Stopwatch\Stopwatch;

use function Elephant\Flow\{async, await, delay};

it('completes after the given time', function (): void {
    $stopwatch = new Stopwatch(true);
    $stopwatch->start('delay');

    expect(await(delay(0.05)))->toBeNull()
        ->and($stopwatch->stop('delay')->getDuration())->toBeGreaterThanOrEqual(50);
});

it('can be awaited through its own method', function (): void {
    $pause = delay(0.01);
    $pause->await();

    expect($pause->future()->status())->toBe(Status::Completed);
});

it('throws when awaiting a cancelled delay', function (): void {
    $pause = delay(10);
    $pause->cancel();

    expect(function () use ($pause): void {
        $pause->await();
    })->toThrow(CancelledException::class);
});

it('lets the script finish right after it is cancelled', function (): void {
    delay(10)->cancel();

    $stopwatch = new Stopwatch(true);
    $stopwatch->start('loop');

    Application::loop()->run();

    expect($stopwatch->stop('loop')->getDuration())->toBeLessThan(100);
});

it('lets the script finish right after a timeout', function (): void {
    expect(function (): void {
        await(delay(10), timeout: 0.01);
    })->toThrow(TimeoutException::class);

    $stopwatch = new Stopwatch(true);
    $stopwatch->start('loop');

    Application::loop()->run();

    expect($stopwatch->stop('loop')->getDuration())->toBeLessThan(100);
});

it('lets the script finish right after the task waiting for it is cancelled', function (): void {
    $pause = delay(10);
    $task = async(static fn (): mixed => await($pause));

    await(delay(0.01));
    $task->cancel();

    $stopwatch = new Stopwatch(true);
    $stopwatch->start('loop');

    Application::loop()->run();

    expect($stopwatch->stop('loop')->getDuration())->toBeLessThan(100)
        ->and($task->status())->toBe(Status::Failed)
        ->and($pause->future()->error())->toBeInstanceOf(CancelledException::class);
});

it('changes nothing once it has completed', function (): void {
    $pause = delay(0.01);
    $pause->await();
    $pause->cancel();

    expect($pause->future()->status())->toBe(Status::Completed);
});

it('stays quiet when cancelled right after it became due', function (): void {
    $pause = null;

    Application::loop()->delay(0, static function () use (&$pause): void {
        $pause->cancel();
    });

    $pause = delay(0);

    expect(function (): void {
        await(delay(0.01));
    })->not->toThrow(ElephantException::class)
        ->and($pause->future()->status())->toBe(Status::Failed);
});
