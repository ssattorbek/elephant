<?php
declare(strict_types=1);

use Elephant\Console\Style;
use Elephant\Console\Writer;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\BufferedOutput;

use function Symfony\Component\String\u as String;

function printed(string $message, Style $style, bool $decorated = true): string
{
    $output = new BufferedOutput(decorated: $decorated);

    (new Writer($output, $output->getFormatter()))->write($message, $style);

    return String($output->fetch())->trimEnd()->toString();
}

it('colors messages by style', function (Style $style, OutputFormatterStyle $color): void {
    expect(printed('message', $style))->toBe($color->apply('message'));
})->with([
    'info' => [Style::Info, new OutputFormatterStyle('cyan')],
    'success' => [Style::Success, new OutputFormatterStyle('green')],
    'warning' => [Style::Warning, new OutputFormatterStyle('yellow')],
    'error' => [Style::Error, new OutputFormatterStyle('red')],
]);

it('keeps plain messages uncolored', function (): void {
    expect(printed('message', Style::Plain))->toBe('message');
});

it('skips colors when the output is not decorated', function (): void {
    expect(printed('message', Style::Success, decorated: false))->toBe('message');
});

it('resets the style after every message', function (): void {
    $output = new BufferedOutput(decorated: true);
    $writer = new Writer($output, $output->getFormatter());

    $writer->write('red', Style::Error);
    $writer->write('plain', Style::Plain);

    expect(String($output->fetch())->trimEnd()->toString())->toEndWith('plain');
});
