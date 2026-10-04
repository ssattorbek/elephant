<?php
declare(strict_types=1);

use Elephant\Facades\Http;
use Elephant\Future\CancelledException;
use Elephant\Future\Status;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Stopwatch\Stopwatch;
use Tests\Support\Journal;

use function Elephant\Flow\{async, await, delay};

it('never starts a task cancelled before it began', function (): void {
    $journal = new Journal();

    $task = async($journal->record('started'));
    $task->cancel();

    await(delay(0.01));

    expect($journal->entries())->toBe([])
        ->and($task->status())->toBe(Status::Failed)
        ->and(function () use ($task): void {
            $task->await();
        })->toThrow(CancelledException::class);
});

it('stops a waiting task at its await', function (): void {
    $journal = new Journal();
    $stopwatch = new Stopwatch(true);
    $stopwatch->start('task');

    $task = async(static function () use ($journal): void {
        $journal->write('before');
        await(delay(1));
        $journal->write('after');
    });

    await(delay(0.01));
    $task->cancel();

    expect(function () use ($task): void {
        $task->await();
    })->toThrow(CancelledException::class)
        ->and($journal->entries())->toBe(['before'])
        ->and($stopwatch->stop('task')->getDuration())->toBeLessThan(500);
});

it('runs finally blocks of a cancelled task', function (): void {
    $journal = new Journal();

    $task = async(static function () use ($journal): void {
        try {
            await(delay(1));
        } finally {
            $journal->write('cleanup');
        }
    });

    await(delay(0.01));
    $task->cancel();

    expect(function () use ($task): void {
        $task->await();
    })->toThrow(CancelledException::class)
        ->and($journal->entries())->toBe(['cleanup']);
});

it('lets a task catch its cancellation', function (): void {
    $task = async(static function (): string {
        try {
            await(delay(1));
        } catch (CancelledException) {
            return 'stopped';
        }

        return 'finished';
    });

    await(delay(0.01));
    $task->cancel();

    expect($task->await())->toBe('stopped');
});

it('cancels the request the task is waiting for', function (): void {
    Http::fake(new MockResponse((static function (): Generator {
        for ($pause = 1; $pause <= 10; $pause++) {
            yield '';
        }

        yield 'done';
    })()));

    $page = Http::get('https://api.test');
    $task = async(static fn (): string => $page->await()->body());

    await(delay(0));
    $task->cancel();
    await(delay(0.01));

    expect($page->future()->error())->toBeInstanceOf(CancelledException::class);
});

it('changes nothing once the task has finished', function (): void {
    $task = async(static fn (): string => 'done');

    expect($task->await())->toBe('done');

    $task->cancel();

    expect($task->await())->toBe('done')
        ->and($task->status())->toBe(Status::Completed);
});

it('ignores a second cancel while the task cleans up', function (): void {
    $journal = new Journal();

    $task = async(static function () use ($journal): string {
        try {
            await(delay(1));
        } catch (CancelledException) {
            $journal->write('cancelled');
            await(delay(0.01));
            $journal->write('cleaned up');

            return 'stopped';
        }

        return 'finished';
    });

    await(delay(0.01));
    $task->cancel();
    $task->cancel();

    expect($task->await())->toBe('stopped')
        ->and($journal->entries())->toBe(['cancelled', 'cleaned up'])
        ->and(function (): void {
            await(delay(0.05));
        })->not->toThrow(FiberError::class);
});

it('ignores cancelling a task again after it handled the cancellation', function (): void {
    $task = async(static function (): string {
        try {
            await(delay(1));
        } catch (CancelledException) {
            await(delay(0.05));

            return 'stopped';
        }

        return 'finished';
    });

    await(delay(0.01));
    $task->cancel();
    await(delay(0.01));
    $task->cancel();

    expect($task->await())->toBe('stopped');
});

it('throws the cancellation at the next await of a task that cancels itself', function (): void {
    $journal = new Journal();
    $task = null;

    $task = async(static function () use (&$task, $journal): void {
        $task->cancel();
        $journal->write('before');
        await(delay(0.01));
        $journal->write('after');
    });

    expect(function () use ($task): void {
        $task->await();
    })->toThrow(CancelledException::class)
        ->and(function (): void {
            await(delay(0.05));
        })->not->toThrow(FiberError::class)
        ->and($journal->entries())->toBe(['before']);
});

it('lets a task that cancels itself recover and await again', function (): void {
    $task = null;

    $task = async(static function () use (&$task): string {
        $task->cancel();

        try {
            await(delay(0.01));
        } catch (CancelledException) {
            await(delay(0.01));

            return 'recovered';
        }

        return 'finished';
    });

    expect($task->await())->toBe('recovered')
        ->and(function (): void {
            await(delay(0.05));
        })->not->toThrow(FiberError::class);
});

it('stays quiet when nobody awaits the cancelled task', function (): void {
    async(static fn (): mixed => await(delay(1)))->cancel();

    expect(function (): void {
        await(delay(0.01));
    })->not->toThrow(CancelledException::class);
});
