<?php
declare(strict_types=1);

use Elephant\Facades\{Http, Tasks};
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\PHPStan\TypeTestCase;

use function PHPStan\Testing\assertType;

uses(TypeTestCase::class);

it('narrows a failed result to its error', function (): void {
    Http::fake(new MockResponse('', ['error' => 'boom']));

    $result = Tasks::settle(['user' => Http::get('https://api.test/user')])->await()['user'];

    assertType('Elephant\Http\Response|null', $result->value());
    assertType('Throwable|null', $result->error());

    expect($result->failed())->toBeTrue();

    if ($result->failed()) {
        assertType('Throwable', $result->error());
        assertType('null', $result->value());

        expect($result->error()->getMessage())->toContain('boom');
    }

    if ($result->successful() === false) {
        assertType('Throwable', $result->error());
        assertType('null', $result->value());
    }
});

it('narrows a successful result to its value', function (): void {
    $result = Tasks::settle(['date' => static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-05')])->await()['date'];

    expect($result->successful())->toBeTrue();

    if ($result->successful()) {
        assertType('DateTimeImmutable', $result->value());
        assertType('null', $result->error());

        expect($result->value()->format('Y'))->toBe('2026');
    }

    if ($result->failed() === false) {
        assertType('DateTimeImmutable', $result->value());
        assertType('null', $result->error());
    }
});

it('agrees with PHPStan on every asserted type', function (string $assertion, string $file, mixed ...$arguments): void {
    $this->assertFileAsserts($assertion, $file, ...$arguments);
})->with(fn (): array => TypeTestCase::gatherAssertTypes(__FILE__));
