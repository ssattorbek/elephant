<?php
declare(strict_types=1);

namespace Tests\Support\PHPStan;

use Elephant\PHPStan\TaskNotFoundRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Symfony\Component\Filesystem\Path;

abstract class TaskNotFoundRuleTestCase extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new TaskNotFoundRule();
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [Path::join(__DIR__, '../../../extension.neon')];
    }
}
