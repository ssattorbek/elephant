<?php
declare(strict_types=1);

use Elephant\Facades\Process;
use Elephant\Facades\Tasks;
use Elephant\Future\TimeoutException;
use Elephant\Process\ProcessException;
use Elephant\Process\Result;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Stopwatch\Stopwatch;

use function Elephant\Flow\{await, delay, every};

beforeEach(function (): void {
    $this->directory = Path::join(sys_get_temp_dir(), uniqid('elephant-process-', true));
    (new Filesystem())->mkdir($this->directory);
});

afterEach(function (): void {
    (new Filesystem())->remove($this->directory);
});

it('runs a program and returns its output', function (): void {
    $result = Process::run([PHP_BINARY, '-r', 'echo "hello elephant";'])->await();

    expect($result->output())->toBe('hello elephant')
        ->and($result->errorOutput())->toBe('')
        ->and($result->exitCode())->toBe(0)
        ->and($result->successful())->toBeTrue();
});

it('runs a shell command with pipes', function (): void {
    $result = Process::run('echo elephant | tr a-z A-Z')->await();

    expect($result->output())->toBe("ELEPHANT\n");
});

it('reports the error output and the exit code', function (): void {
    $result = Process::run([PHP_BINARY, '-r', 'fwrite(STDERR, "oops"); exit(3);'])->await();

    expect($result->errorOutput())->toBe('oops')
        ->and($result->exitCode())->toBe(3)
        ->and($result->successful())->toBeFalse();
});

it('runs in the given directory', function (): void {
    $result = Process::run([PHP_BINARY, '-r', 'echo getcwd();'], $this->directory)->await();

    expect($result->output())->toBe(realpath($this->directory));
});

it('throws when the process cannot be started', function (): void {
    $missing = Path::join($this->directory, 'missing');

    expect(fn (): Result => Process::run([PHP_BINARY, '-v'], $missing)->await())
        ->toThrow(ProcessException::class, 'Cannot start process: ');
});

it('runs several processes at the same time', function (): void {
    $stopwatch = new Stopwatch(true);
    $stopwatch->start('processes');

    $results = Tasks::all([
        'one' => Process::run([PHP_BINARY, '-r', 'usleep(200000); echo 1;']),
        'two' => Process::run([PHP_BINARY, '-r', 'usleep(200000); echo 2;']),
        'three' => Process::run([PHP_BINARY, '-r', 'usleep(200000); echo 3;']),
    ])->await();

    expect($results['three']->output())->toBe('3')
        ->and($stopwatch->stop('processes')->getDuration())->toBeLessThan(500);
});

it('keeps the event loop running while a process runs', function (): void {
    $ticks = 0;
    $ticker = every(0.01, function () use (&$ticks): void {
        $ticks++;
    });

    Process::run([PHP_BINARY, '-r', 'usleep(200000);'])->await();
    $ticker->cancel();

    expect($ticks)->toBeGreaterThan(5);
});

it('stops the process when the timeout runs out', function (): void {
    $marker = Path::join($this->directory, 'late.txt');
    $script = 'usleep(300000); file_put_contents($argv[1], "late");';

    expect(fn (): Result => Process::run([PHP_BINARY, '-r', $script, $marker])->await(0.05))
        ->toThrow(TimeoutException::class);

    await(delay(0.5));

    expect(file_exists($marker))->toBeFalse();
});

it('finishes the process when it is not stopped', function (): void {
    $marker = Path::join($this->directory, 'done.txt');
    $script = 'usleep(50000); file_put_contents($argv[1], "done");';

    Process::run([PHP_BINARY, '-r', $script, $marker])->await();

    expect(file_get_contents($marker))->toBe('done');
});
