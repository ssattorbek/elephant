<?php
declare(strict_types=1);

use Elephant\ElephantException;
use Elephant\Facades\Http;
use Elephant\Facades\Tasks;
use Elephant\Future\CancelledException;
use Elephant\Future\TimeoutException;
use Elephant\Http\Response;
use Elephant\Tasks\AggregateException;
use Elephant\Tasks\PendingResult;
use Elephant\Tasks\PendingResults;
use Elephant\Tasks\TaskNotFoundException;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Stopwatch\Stopwatch;
use Tests\Support\Gauge;
use Tests\Support\Journal;

use function Elephant\Flow\{async, await, delay};

function after(float $seconds, mixed $value): Closure
{
    return static function () use ($seconds, $value): mixed {
        await(delay($seconds));

        return $value;
    };
}

function slowly(int $pauses = 4): Generator
{
    for ($pause = 1; $pause <= $pauses; $pause++) {
        yield '';
    }

    yield 'done';
}

function finishAfter(float $seconds, Journal $journal): Closure
{
    return static function () use ($seconds, $journal): string {
        await(delay($seconds));
        $journal->write('finished');

        return 'finished';
    };
}

function failAfter(float $seconds, string $message): Closure
{
    return static function () use ($seconds, $message): never {
        await(delay($seconds));

        throw new RuntimeException($message);
    };
}

describe('all()', function (): void {
    it('returns results in the original order with their keys', function (): void {
        $results = Tasks::all([
            'slow' => after(0.05, 'slow'),
            'fast' => after(0.01, 'fast'),
        ])->await();

        expect($results->all())->toBe(['slow' => 'slow', 'fast' => 'fast']);
    });

    it('accepts closures and awaitables', function (): void {
        $results = Tasks::all([
            'closure' => after(0.01, 'closure'),
            'future' => async(static fn (): string => 'future'),
        ])->await();

        expect($results->all())->toBe(['closure' => 'closure', 'future' => 'future']);
    });

    it('runs tasks concurrently', function (): void {
        $stopwatch = new Stopwatch(true);
        $stopwatch->start('tasks');

        Tasks::all([after(0.1, 1), after(0.1, 2), after(0.1, 3)])->await();

        expect($stopwatch->stop('tasks')->getDuration())->toBeLessThan(180);
    });

    it('fails fast with the first error', function (): void {
        $stopwatch = new Stopwatch(true);
        $stopwatch->start('tasks');

        expect(fn (): array => Tasks::all([after(1, 'slow'), failAfter(0.01, 'boom')])->await())
            ->toThrow(RuntimeException::class, 'boom')
            ->and($stopwatch->stop('tasks')->getDuration())->toBeLessThan(500);
    });

    it('hands errors to exception handlers', function (): void {
        $message = Tasks::all([failAfter(0.01, 'boom')])
            ->exception(static fn (RuntimeException $error): string => $error->getMessage())
            ->await();

        expect($message)->toBe('boom');
    });

    it('resolves an empty list immediately', function (): void {
        expect(Tasks::all([])->await()->all())->toBe([]);
    });
});

describe('limit()', function (): void {
    it('never runs more tasks at once than the limit', function (): void {
        $gauge = new Gauge();

        Tasks::all(array_fill(0, 6, $gauge->task(0.02)))->limit(2)->await();

        expect($gauge->peak())->toBe(2);
    });

    it('runs every task at once without a limit', function (): void {
        $gauge = new Gauge();

        Tasks::all(array_fill(0, 6, $gauge->task(0.02)))->await();

        expect($gauge->peak())->toBe(6);
    });

    it('keeps results in the original order', function (): void {
        $results = Tasks::all([
            'slow' => after(0.03, 'slow'),
            'fast' => after(0.01, 'fast'),
        ])->limit(1)->await();

        expect($results->all())->toBe(['slow' => 'slow', 'fast' => 'fast']);
    });

    it('stops starting new tasks after a failure', function (): void {
        $journal = new Journal();

        expect(fn (): array => Tasks::all([failAfter(0.01, 'boom'), $journal->record('started')])->limit(1)->await())
            ->toThrow(RuntimeException::class, 'boom');

        await(delay(0.03));

        expect($journal->entries())->toBe([]);
    });

    it('rejects a limit below one', function (): void {
        expect(fn (): PendingResults => Tasks::all([after(0.01, 'value')])->limit(0))
            ->toThrow(ElephantException::class, 'The concurrency limit must be at least one.');
    });

    it('refuses to limit tasks that are already running', function (): void {
        expect(fn (): PendingResults => Tasks::all([async(static fn (): string => 'running')])->limit(1))
            ->toThrow(ElephantException::class, 'Only closure tasks can be limited');
    });

    it('cannot change the limit once the tasks have started', function (): void {
        $results = Tasks::all([after(0.01, 'value')]);

        await(delay(0));

        expect(fn (): PendingResults => $results->limit(1))->toThrow(ElephantException::class, 'The concurrency limit must be set before the tasks start.');
    });

    it('limits settle() as well', function (): void {
        $gauge = new Gauge();

        Tasks::settle(array_fill(0, 6, $gauge->task(0.02)))->limit(2)->await();

        expect($gauge->peak())->toBe(2);
    });

    it('keeps settling the remaining tasks after a failure', function (): void {
        $results = Tasks::settle([
            'broken' => failAfter(0.01, 'boom'),
            'slow' => after(0.02, 'slow'),
            'fast' => after(0.01, 'fast'),
        ])->limit(1)->await();

        expect($results['broken']->failed())->toBeTrue()
            ->and($results['slow']->value())->toBe('slow')
            ->and($results['fast']->value())->toBe('fast')
            ->and(array_keys($results->all()))->toBe(['broken', 'slow', 'fast']);
    });
});

describe('results', function (): void {
    beforeEach(function (): void {
        $this->results = Tasks::all(['one' => after(0.01, 1), 'two' => after(0.01, 2)])->await();
    });

    it('reads results like an array', function (): void {
        expect($this->results['one'])->toBe(1)
            ->and(isset($this->results['two']))->toBeTrue()
            ->and($this->results)->toHaveCount(2)
            ->and(iterator_to_array($this->results))->toBe(['one' => 1, 'two' => 2]);
    });

    it('reports a missing key as not found', function (): void {
        expect(fn (): mixed => $this->results['nothing'])
            ->toThrow(TaskNotFoundException::class, 'Task not found: nothing');
    });

    it('points the error at the line that read the missing key', function (): void {
        expect(fn (): mixed => $this->results['nothing'])->toThrow(function (TaskNotFoundException $error): void {
            expect($error->getFile())->toBe(__FILE__);
        });
    });

    it('is read-only', function (): void {
        expect(fn (): mixed => $this->results['three'] = 3)->toThrow(ElephantException::class, 'Task results are read-only.');
    });
});

describe('map()', function (): void {
    it('runs the callback for every item and keeps the keys', function (): void {
        $results = Tasks::map(['a' => 1, 'b' => 2], static fn (int $number): int => $number * 10)->await();

        expect($results->all())->toBe(['a' => 10, 'b' => 20]);
    });

    it('passes the key when the callback asks for it', function (): void {
        $results = Tasks::map(['a' => 1, 'b' => 2], static fn (int $number, string $key): string => $key)->await();

        expect($results->all())->toBe(['a' => 'a', 'b' => 'b']);
    });

    it('accepts built-in functions', function (): void {
        expect(Tasks::map(['name' => 'elephant'], strtoupper(...))->await()->all())->toBe(['name' => 'ELEPHANT']);
    });

    it('never passes the key to built-in functions', function (): void {
        expect(Tasks::map(['  elephant  '], trim(...))->await()->all())->toBe(['elephant']);
    });

    it('respects the concurrency limit', function (): void {
        $gauge = new Gauge();

        Tasks::map(range(1, 6), static fn (int $number): mixed => ($gauge->task(0.02))())->limit(2)->await();

        expect($gauge->peak())->toBe(2);
    });

    it('fails as soon as one item fails', function (): void {
        expect(fn (): mixed => Tasks::map([1, 2], static fn (int $number): never => throw new RuntimeException('boom'))->await())
            ->toThrow(RuntimeException::class, 'boom');
    });

    it('sends one request per item', function (): void {
        Http::fake([
            new JsonMockResponse(['page' => 'home']),
            new JsonMockResponse(['page' => 'docs']),
        ]);

        $pages = Tasks::map(
            ['home' => 'https://api.test/home', 'docs' => 'https://api.test/docs'],
            static fn (string $url): Response => Http::get($url)->await(),
        )->limit(1)->await();

        expect($pages['home']->json())->toBe(['page' => 'home'])
            ->and($pages['docs']->json())->toBe(['page' => 'docs']);
    });
});

describe('cancel()', function (): void {
    it('cancels the whole group', function (): void {
        $group = Tasks::all([after(0.05, 'slow')]);
        $group->cancel();

        expect(fn (): mixed => $group->await())->toThrow(CancelledException::class);
    });

    it('never starts the tasks of a cancelled group', function (): void {
        $journal = new Journal();

        Tasks::all([$journal->record('started')])->cancel();

        await(delay(0.01));

        expect($journal->entries())->toBe([]);
    });

    it('aborts the other requests when one task fails', function (): void {
        Http::fake(new MockResponse(slowly()));

        $page = Http::get('https://api.test');

        expect(fn (): mixed => Tasks::all([failAfter(0, 'boom'), $page])->await())
            ->toThrow(RuntimeException::class, 'boom')
            ->and($page->future()->error())->toBeInstanceOf(CancelledException::class);
    });

    it('stops the running closure tasks when one task fails', function (): void {
        $journal = new Journal();

        $slow = static function () use ($journal): void {
            await(delay(0.05));
            $journal->write('finished');
        };

        expect(fn (): mixed => Tasks::all([$slow, failAfter(0.01, 'boom')])->await())
            ->toThrow(RuntimeException::class, 'boom');

        await(delay(0.08));

        expect($journal->entries())->toBe([]);
    });

    it('aborts the slower requests once a race is decided', function (): void {
        Http::fake(new MockResponse(slowly()));

        $page = Http::get('https://api.test');

        expect(Tasks::race([after(0, 'fast'), $page])->await())->toBe('fast')
            ->and($page->future()->error())->toBeInstanceOf(CancelledException::class);
    });
});

describe('settle()', function (): void {
    it('returns a result for every task', function (): void {
        [$success, $failure] = Tasks::settle([after(0.01, 'value'), failAfter(0.01, 'boom')])->await();

        expect($success->successful())->toBeTrue()
            ->and($success->value())->toBe('value')
            ->and($failure->failed())->toBeTrue()
            ->and($failure->error())->toBeInstanceOf(RuntimeException::class);
    });
});

describe('race()', function (): void {
    it('resolves with the first task to finish', function (): void {
        expect(Tasks::race([after(0.05, 'slow'), after(0.01, 'fast')])->await())->toBe('fast');
    });

    it('fails when the first task to finish fails', function (): void {
        expect(fn (): mixed => Tasks::race([after(0.05, 'slow'), failAfter(0.01, 'boom')])->await())
            ->toThrow(RuntimeException::class, 'boom');
    });

    it('stops the competing tasks when the race times out', function (): void {
        $journal = new Journal();

        expect(fn (): mixed => Tasks::race([finishAfter(0.05, $journal), finishAfter(0.05, $journal)])->await(timeout: 0.01))
            ->toThrow(TimeoutException::class);

        await(delay(0.08));

        expect($journal->entries())->toBe([]);
    });

    it('stops the competing tasks when the race is cancelled', function (): void {
        $journal = new Journal();

        $race = Tasks::race([finishAfter(0.02, $journal), finishAfter(0.02, $journal)]);

        await(delay(0.01));
        $race->cancel();
        await(delay(0.05));

        expect(fn (): mixed => $race->await())->toThrow(CancelledException::class)
            ->and($journal->entries())->toBe([]);
    });
});

describe('any()', function (): void {
    it('resolves with the first successful task', function (): void {
        expect(Tasks::any([failAfter(0.01, 'boom'), after(0.03, 'value')])->await())->toBe('value');
    });

    it('fails with every error when all tasks fail', function (): void {
        $messages = Tasks::any([failAfter(0.01, 'first'), failAfter(0.02, 'second')])
            ->exception(static fn (AggregateException $error): array => array_map(
                static fn (Throwable $error): string => $error->getMessage(),
                $error->errors(),
            ))
            ->await();

        expect($messages)->toBe(['first', 'second']);
    });

    it('stops the other tasks once one succeeds', function (): void {
        $journal = new Journal();

        expect(Tasks::any([after(0.01, 'fast'), finishAfter(0.05, $journal)])->await())->toBe('fast');

        await(delay(0.08));

        expect($journal->entries())->toBe([]);
    });

    it('stops the competing tasks when it times out', function (): void {
        $journal = new Journal();

        expect(fn (): mixed => Tasks::any([finishAfter(0.05, $journal), failAfter(0.01, 'boom')])->await(timeout: 0.02))
            ->toThrow(TimeoutException::class);

        await(delay(0.08));

        expect($journal->entries())->toBe([]);
    });

    it('stops the competing tasks when it is cancelled', function (): void {
        $journal = new Journal();

        $any = Tasks::any([finishAfter(0.02, $journal), finishAfter(0.02, $journal)]);

        await(delay(0.01));
        $any->cancel();
        await(delay(0.05));

        expect(fn (): mixed => $any->await())->toThrow(CancelledException::class)
            ->and($journal->entries())->toBe([]);
    });
});

it('requires at least one task to race', function (): void {
    expect(fn (): PendingResult => Tasks::race([]))->toThrow(ElephantException::class, 'At least one task is required.')
        ->and(fn (): PendingResult => Tasks::any([]))->toThrow(ElephantException::class, 'At least one task is required.');
});

it('waits for many http requests at once', function (): void {
    Http::fake([
        new JsonMockResponse(['name' => 'Leanne Graham']),
        new JsonMockResponse([['title' => 'First post']]),
    ]);

    $results = Tasks::all([
        'user' => Http::get('https://api.test/user'),
        'posts' => Http::get('https://api.test/posts'),
    ])->await();

    expect($results['user']->json()['name'])->toBe('Leanne Graham')
        ->and($results['posts']->json()[0]['title'])->toBe('First post');
});
