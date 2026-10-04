<?php
declare(strict_types=1);

use Elephant\Console\ErrorRenderer;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Output\BufferedOutput;

function rendered(Throwable $error, bool $decorated = false): string
{
    $output = new BufferedOutput(decorated: $decorated);

    (new ErrorRenderer($output))->render($error);

    return $output->fetch();
}

it('shows the message in an error section', function (): void {
    expect(rendered(new RuntimeException('boom')))->toContain('[ERROR] boom');
});

it('shows the line number with the full file path', function (): void {
    expect(rendered(new RuntimeException('boom')))->toContain(__FILE__);
});

it('leaves the code out', function (): void {
    expect(rendered(new RuntimeException('boom')))->not->toContain('new RuntimeException');
});

it('colors both sections when the output supports colors', function (): void {
    $error = new RuntimeException('boom');
    $location = (new FormatterHelper())->formatSection((string) $error->getLine(), __FILE__, 'comment');

    expect(rendered($error, decorated: true))
        ->toContain((new OutputFormatter(true))->format($location))
        ->toContain((new OutputFormatterStyle('white', 'red', ['bold']))->apply('[ERROR]'));
});
