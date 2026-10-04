<?php
declare(strict_types=1);

use Elephant\Facades\{Http, Tasks};
use Elephant\Http\Response;
use Elephant\Tasks\Result;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Tests\Support\PHPStan\TypeTestCase;

use function PHPStan\Testing\assertType;

uses(TypeTestCase::class);

it('gives every result of Tasks::all() its own type', function (): void {
    Http::fake(new JsonMockResponse(['name' => 'Elephant']));

    $results = Tasks::all([
        'user' => Http::get('https://api.test/user'),
        'count' => static fn (): int => (int) microtime(true),
    ])->await();

    assertType('Elephant\Http\Response', $results['user']);
    assertType('int', $results['count']);

    expect($results['user'])->toBeInstanceOf(Response::class)
        ->and($results['count'])->toBeInt();
});

it('wraps every result of Tasks::settle() in Result', function (): void {
    Http::fake(new JsonMockResponse(['name' => 'Elephant']));

    $settled = Tasks::settle(['user' => Http::get('https://api.test/user')])->await();

    assertType('Elephant\Tasks\Result<Elephant\Http\Response>', $settled['user']);

    expect($settled['user'])->toBeInstanceOf(Result::class);
});

it('gives every result of Tasks::map() the callback type', function (): void {
    Http::fake([new JsonMockResponse(['page' => 'home']), new JsonMockResponse(['page' => 'docs'])]);

    $pages = Tasks::map(
        ['home' => 'https://api.test/home', 'docs' => 'https://api.test/docs'],
        static fn (string $url): Response => Http::get($url)->await(),
    )->await();

    assertType('Elephant\Http\Response', $pages['home']);

    expect($pages['home'])->toBeInstanceOf(Response::class);
});

it('gives every result of Tasks::map() the type of a callback that takes the key', function (): void {
    $labels = Tasks::map(['a' => 1, 'b' => 2], static fn (int $number, string $key): string => $key)->await();

    assertType('string', $labels['a']);
    assertType('array{a: string, b: string}', $labels->all());

    expect($labels->all())->toBe(['a' => 'a', 'b' => 'b']);
});

it('gives every result of Tasks::map() the return type of a built-in function', function (): void {
    $names = Tasks::map(['name' => '  elephant  '], trim(...))->await();

    assertType('string', $names['name']);

    expect($names['name'])->toBe('elephant');
});

it('agrees with PHPStan on every asserted type', function (string $assertion, string $file, mixed ...$arguments): void {
    $this->assertFileAsserts($assertion, $file, ...$arguments);
})->with(fn (): array => TypeTestCase::gatherAssertTypes(__FILE__));
