# 🐘 Elephant

**Async PHP that reads like plain PHP.**

Elephant gives PHP an event loop with `async()` and `await()` on top of Fibers. While one task waits for the network, a timer, a child process or a channel, every other task keeps running. You write straight-line code with ordinary return values and ordinary `try`/`catch`. There are no promise chains, no callbacks, no extensions and no separate runtime.

[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-777bb4)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-wiki-blue)](https://github.com/sattorware/elephant/wiki)
[![Packagist](https://img.shields.io/packagist/v/sattorware/elephant)](https://packagist.org/packages/sattorware/elephant)

```bash
composer require sattorware/elephant
```

## Two tasks, one second

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Elephant\Facades\Console;

use function Elephant\Flow\{async, await, delay};

$tea = async(function (): string {
    await(delay(1));

    return 'Tea is ready';
});

$toast = async(function (): string {
    await(delay(1));

    return 'Toast is ready';
});

Console::success(await($tea));
Console::success(await($toast));
```

Both tasks wait one second at the same time, so the script finishes in about one second, not two.

## Many requests, a few at a time, with a deadline

```php
$responses = Tasks::map($urls, static fn (string $url): Response => Http::get($url)->await())
    ->limit(5)
    ->await(timeout: 10);
```

Every URL gets its own task, at most five run at once, the results keep your keys, and the whole group is cancelled if it takes longer than ten seconds.

## What's inside

| | |
| --- | --- |
| **Async and await** | `async()`, `await()`, `delay()`, `every()` on PHP Fibers |
| **Timeouts and cancellation** | `->await(timeout: ...)` and `cancel()` for every task, request, process and group |
| **Tasks** | `Tasks::all()`, `map()`, `settle()`, `race()`, `any()` and `limit()` |
| **Channels** | Buffered and unbuffered channels with back-pressure, read with `foreach` |
| **HTTP client** | A fluent `Http` facade with retries, timeouts and `Http::fake()` for tests |
| **Files** | `File::read()`, `write()` (atomic), `append()`, `lines()` and `delete()` in cooperative chunks |
| **Processes** | `Process::run()` for commands that run while your code keeps going |
| **Console** | `Console::info()`, `success()`, `warning()` and `error()`, plus readable error output |
| **PHPStan** | An extension that infers the exact result shape of `Tasks::*` and catches missing keys |

## Documentation

The **[wiki](https://github.com/sattorware/elephant/wiki)** covers every part of the library with examples you can run:

- [Getting Started](https://github.com/sattorware/elephant/wiki/Getting-Started) and [Core Concepts](https://github.com/sattorware/elephant/wiki/Core-Concepts)
- [Async and Await](https://github.com/sattorware/elephant/wiki/Async-and-Await), [Timeouts and Cancellation](https://github.com/sattorware/elephant/wiki/Timeouts-and-Cancellation), [Tasks](https://github.com/sattorware/elephant/wiki/Tasks) and [Channels](https://github.com/sattorware/elephant/wiki/Channels)
- [HTTP Client](https://github.com/sattorware/elephant/wiki/HTTP-Client), [Filesystem](https://github.com/sattorware/elephant/wiki/Filesystem), [Processes](https://github.com/sattorware/elephant/wiki/Processes) and [Console](https://github.com/sattorware/elephant/wiki/Console)
- [Recipes](https://github.com/sattorware/elephant/wiki/Recipes) and [FAQ and Limitations](https://github.com/sattorware/elephant/wiki/FAQ-and-Limitations)

## Requirements

- PHP 8.2 or newer
- Symfony components 6.4, 7.x or 8.x, which Composer installs for you

## Development

```bash
composer install
composer test
composer analyse
```

## License

Elephant is open source under the [MIT license](LICENSE).
