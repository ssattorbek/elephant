<?php
declare(strict_types=1);

namespace Tests\Support\PHPStan;

use PHPStan\Rules\Methods\CallStaticMethodsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Symfony\Component\Filesystem\Path;

abstract class CallStaticMethodsRuleTestCase extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(CallStaticMethodsRule::class);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [Path::join(__DIR__, '../../../extension.neon')];
    }
}
