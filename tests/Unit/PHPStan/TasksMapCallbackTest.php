<?php
declare(strict_types=1);

use Elephant\Facades\Tasks;
use Tests\Support\PHPStan\CallStaticMethodsRuleTestCase;

uses(CallStaticMethodsRuleTestCase::class);

it('accepts a callback that takes the key', function (): void {
    $results = Tasks::map(['a' => 1, 'b' => 2], static fn (int $number, string $key): string => $key)->await();

    expect($results->all())->toBe(['a' => 'a', 'b' => 'b']);
});

it('accepts a built-in function with an optional second parameter', function (): void {
    expect(Tasks::map(['name' => '  elephant  '], trim(...))->await()->all())->toBe(['name' => 'elephant']);
});

it('rejects a callback whose item type does not match', function (): void {
    expect(fn (): array => Tasks::map(['a' => 1], static fn (string $item): string => $item)->await()->all())
        ->toThrow(TypeError::class);
});

it('rejects a callback whose key type does not match', function (): void {
    expect(fn (): array => Tasks::map(['a' => 1], static fn (int $number, int $key): int => $key)->await()->all())
        ->toThrow(TypeError::class);
});

it('agrees with PHPStan on which callbacks fit', function (): void {
    $this->analyse([__FILE__], [
        [
            'Parameter #2 $callback of static method Elephant\\Facades\\Tasks::map() expects (Closure(1): string)|(Closure(1, \'a\'): string), Closure(string): string given.',
            20,
        ],
        [
            'Parameter #2 $callback of static method Elephant\\Facades\\Tasks::map() expects (Closure(1): int)|(Closure(1, \'a\'): int), Closure(int, int): int given.',
            25,
            'Type #1 from the union: Parameter #2 $key of passed callable is required but accepting callable does not have that parameter. It will be called without it.',
        ],
    ]);
});
