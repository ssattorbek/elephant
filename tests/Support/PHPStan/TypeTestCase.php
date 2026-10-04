<?php
declare(strict_types=1);

namespace Tests\Support\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;
use Symfony\Component\Filesystem\Path;

abstract class TypeTestCase extends TypeInferenceTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [Path::join(__DIR__, '../../../extension.neon')];
    }
}
