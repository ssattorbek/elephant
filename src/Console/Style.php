<?php
declare(strict_types=1);

namespace Elephant\Console;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 * Visual style of a console message.
 */
enum Style
{
    case Plain;
    case Info;
    case Success;
    case Warning;
    case Error;

    /**
     * Returns the Symfony formatter style that renders this style.
     */
    public function format(): OutputFormatterStyle
    {
        return match ($this) {
            self::Plain => new OutputFormatterStyle(),
            self::Info => new OutputFormatterStyle('cyan'),
            self::Success => new OutputFormatterStyle('green'),
            self::Warning => new OutputFormatterStyle('yellow'),
            self::Error => new OutputFormatterStyle('red'),
        };
    }
}
