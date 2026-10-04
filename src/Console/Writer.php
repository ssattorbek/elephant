<?php
declare(strict_types=1);

namespace Elephant\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes styled lines to a console output.
 *
 * Colors are applied only when the output supports them.
 */
final class Writer
{
    /**
     * @param OutputInterface $output Destination of the messages.
     * @param OutputFormatter $formatter Formatter used by the output, whose style stack applies the colors.
     */
    public function __construct(
        private readonly OutputInterface $output,
        private readonly OutputFormatter $formatter,
    ) {
    }

    /**
     * Writes the message as one line in the given style.
     */
    public function write(string $message, Style $style): void
    {
        $this->formatter->getStyleStack()->push($style->format());
        $this->output->writeln(OutputFormatter::escape($message));
        $this->formatter->getStyleStack()->pop();
    }
}
