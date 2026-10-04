<?php
declare(strict_types=1);

use Elephant\ElephantException;
use Elephant\Application;
use Elephant\Future\Deferred;
use Elephant\Future\Status;

use function Elephant\Flow\{async, await};

it('stays pending until it is completed', function (): void {
    $deferred = new Deferred(Application::loop());
    $future = $deferred->future();

    expect($future->isPending())->toBeTrue();

    $deferred->complete('value');

    expect($future->isSettled())->toBeTrue()
        ->and($future->result())->toBe('value');
});

it('reports its status through the lifecycle', function (): void {
    $completed = new Deferred(Application::loop());
    $failed = new Deferred(Application::loop());

    expect($completed->future()->status())->toBe(Status::Pending);

    $completed->complete('value');
    $failed->fail(new RuntimeException('boom'));

    expect($completed->future()->status())->toBe(Status::Completed)
        ->and($failed->future()->status())->toBe(Status::Failed)
        ->and(fn (): mixed => $failed->future()->result())->toThrow(RuntimeException::class);
});

it('cannot be completed twice', function (): void {
    $deferred = new Deferred(Application::loop());
    $deferred->complete('first');

    expect(function () use ($deferred): void {
        $deferred->complete('second');
    })->toThrow(ElephantException::class, 'The future is already settled.');
});

describe('exception()', function (): void {
    it('handles errors matching the handler type', function (): void {
        $future = async(static fn (): never => throw new RuntimeException('boom'))
            ->exception(static fn (RuntimeException $error): string => $error->getMessage());

        expect(await($future))->toBe('boom');
    });

    it('skips handlers for other error types', function (): void {
        $future = async(static fn (): never => throw new LogicException('logic'))
            ->exception(static fn (RuntimeException $error): string => 'runtime')
            ->exception(static fn (LogicException $error): string => $error->getMessage());

        expect(await($future))->toBe('logic');
    });

    it('catches everything with an untyped handler', function (): void {
        $future = async(static fn (): never => throw new LogicException('logic'))
            ->exception(static fn (): string => 'caught');

        expect(await($future))->toBe('caught');
    });

    it('rethrows errors that no handler accepts', function (): void {
        $future = async(static fn (): never => throw new LogicException('logic'))
            ->exception(static fn (RuntimeException $error): string => 'runtime');

        expect(fn (): mixed => await($future))->toThrow(LogicException::class, 'logic');
    });

    it('passes successful values through', function (): void {
        $future = async(static fn (): string => 'value')
            ->exception(static fn (): string => 'caught');

        expect(await($future))->toBe('value');
    });
});
