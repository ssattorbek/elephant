<?php
declare(strict_types=1);

use Elephant\Application;
use Elephant\ElephantException;
use Elephant\Facades\Tasks;
use Elephant\Future\Status;
use Elephant\Future\TimeoutException;
use Symfony\Component\Stopwatch\Stopwatch;
use Tests\Support\Journal;

use function Elephant\Flow\{async, await, delay};

function sleeper(float $seconds, string $value): Closure
{
    return static function () use ($seconds, $value): string {
        await(delay($seconds));

        return $value;
    };
}

it('returns the value when the work finishes in time', function (): void {
    expect(async(sleeper(0.01, 'done'))->await(timeout: 1))->toBe('done');
});

it('throws when the time runs out', function (): void {
    $stopwatch = new Stopwatch(true);
    $stopwatch->start('timeout');

    $task = async(sleeper(1, 'late'));

    expect(function () use ($task): void {
        $task->await(timeout: 0.05);
    })->toThrow(TimeoutException::class, 'Task timed out: 50 ms')
        ->and($stopwatch->stop('timeout')->getDuration())->toBeLessThan(500);
});

it('cancels the work when the time runs out', function (): void {
    $journal = new Journal();

    $task = async(static function () use ($journal): void {
        await(delay(0.1));
        $journal->write('finished');
    });

    expect(function () use ($task): void {
        $task->await(timeout: 0.01);
    })->toThrow(TimeoutException::class);

    await(delay(0.15));

    expect($task->status())->toBe(Status::Failed)
        ->and($journal->entries())->toBe([]);
});

it('points the error at the line that awaited', function (): void {
    $task = async(sleeper(1, 'late'));

    expect(function () use ($task): void {
        $task->await(timeout: 0.01);
    })->toThrow(function (TimeoutException $error): void {
        expect($error->getFile())->toBe(__FILE__);
    });
});

it('limits a whole group of tasks', function (): void {
    $group = Tasks::all(['slow' => sleeper(1, 'slow'), 'fast' => sleeper(0.01, 'fast')]);

    expect(function () use ($group): void {
        $group->await(timeout: 0.05);
    })->toThrow(TimeoutException::class);
});

it('works with the await() function', function (): void {
    expect(await(async(sleeper(0.01, 'done')), timeout: 1))->toBe('done');
});

it('rejects a timeout that is not positive', function (): void {
    expect(function (): void {
        async(sleeper(0.01, 'done'))->await(timeout: 0);
    })->toThrow(ElephantException::class, 'The timeout must be longer than zero seconds.');
});

it('lets the script finish once a task waiting with a timeout is cancelled', function (): void {
    $task = async(static fn (): mixed => await(delay(10), timeout: 5));

    await(delay(0.01));
    $task->cancel();

    $stopwatch = new Stopwatch(true);
    $stopwatch->start('loop');

    Application::loop()->run();

    expect($stopwatch->stop('loop')->getDuration())->toBeLessThan(100)
        ->and($task->status())->toBe(Status::Failed);
});
