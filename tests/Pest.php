<?php
declare(strict_types=1);

use Elephant\Application;
use Pest\Expectation;
use Symfony\Component\HttpFoundation\ParameterBag;

use function Symfony\Component\String\u as String;

Application::container();

pest()->in('Unit', 'Feature')->beforeEach(function (): void {
    Application::reset();
});

expect()->extend('toHaveSentHeader', function (string $name, string $value): Expectation {
    $lines = (new ParameterBag($this->value->getRequestOptions()['normalized_headers']))->all(String($name)->lower()->toString());
    $values = array_map(static fn (string $line): string => String($line)->after(': ')->toString(), $lines);

    expect($values)->toContain($value);

    return $this;
});
