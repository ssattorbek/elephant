<?php
declare(strict_types=1);

use Elephant\Facades\{Http, Tasks};
use Elephant\Tasks\TaskNotFoundException;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Tests\Support\PHPStan\TaskNotFoundRuleTestCase;

uses(TaskNotFoundRuleTestCase::class);

it('throws when reading a task that does not exist', function (): void {
    Http::fake(new JsonMockResponse(['name' => 'Elephant']));

    $results = Tasks::all(['user' => Http::get('https://api.test/user')])->await();

    expect(fn (): mixed => $results['none'])->toThrow(TaskNotFoundException::class)
        ->and(isset($results['none']))->toBeFalse();
});

it('reports the same mistake during static analysis', function (): void {
    $this->analyse([__FILE__], [
        ["Task not found: 'none'", 16],
    ]);
});
