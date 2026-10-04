<?php
declare(strict_types=1);

namespace Elephant\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Renders uncaught errors as two Symfony Console sections: the message, then the line number with the full file path.
 */
final class ErrorRenderer
{
    private readonly FormatterHelper $sections;

    /**
     * Makes the output's error style bold so the [ERROR] label stands out.
     *
     * @param OutputInterface $output Output that receives the error, usually the console's error output.
     */
    public function __construct(
        private readonly OutputInterface $output,
    ) {
        $this->sections = new FormatterHelper();

        $output->getFormatter()->setStyle('error', new OutputFormatterStyle('white', 'red', ['bold']));
    }

    /**
     * Writes the error message and where it happened.
     */
    public function render(Throwable $error): void
    {
        $this->output->writeln('');
        $this->output->writeln($this->sections->formatSection('ERROR', OutputFormatter::escape($error->getMessage()), 'error'));
        $this->output->writeln($this->sections->formatSection((string) $error->getLine(), OutputFormatter::escape($error->getFile()), 'comment'));
        $this->output->writeln('');
    }
}
