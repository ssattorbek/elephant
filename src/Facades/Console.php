<?php
declare(strict_types=1);

namespace Elephant\Facades;

use Elephant\Application;
use Elephant\Console\Style;
use Stringable;

/**
 * Static entry point for writing styled lines to the console.
 */
final class Console
{
    /**
     * Writes an unstyled line.
     */
    public static function log(string|Stringable $message): void
    {
        self::write($message, Style::Plain);
    }

    /**
     * Writes an informational line in cyan.
     */
    public static function info(string|Stringable $message): void
    {
        self::write($message, Style::Info);
    }

    /**
     * Writes a success line in green.
     */
    public static function success(string|Stringable $message): void
    {
        self::write($message, Style::Success);
    }

    /**
     * Writes a warning line in yellow.
     */
    public static function warning(string|Stringable $message): void
    {
        self::write($message, Style::Warning);
    }

    /**
     * Writes an error line in red.
     */
    public static function error(string|Stringable $message): void
    {
        self::write($message, Style::Error);
    }

    private static function write(string|Stringable $message, Style $style): void
    {
        Application::writer()->write((string) $message, $style);
    }
}
