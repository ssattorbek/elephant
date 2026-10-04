<?php
declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

final class PhpstanSources
{
    public static function mirror(): void
    {
        (new Filesystem())->mirror('phar://vendor/phpstan/phpstan/phpstan.phar/src', 'vendor/phpstan/phpstan/src');
    }
}
