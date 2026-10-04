<?php
declare(strict_types=1);

namespace Elephant\PHPStan;

use Elephant\Tasks\Results;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;

use function Symfony\Component\String\u as String;

/**
 * Reports reading a task result with a key that no task was given, such as $results['none'].
 *
 * Reads inside isset(), empty() and ?? are allowed, because they are meant to check whether a key exists.
 *
 * @implements Rule<ArrayDimFetch>
 */
final class TaskNotFoundRule implements Rule
{
    public function getNodeType(): string
    {
        return ArrayDimFetch::class;
    }

    /**
     * @param ArrayDimFetch $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->dim === null || $scope->isUndefinedExpressionAllowed($node)) {
            return [];
        }

        $results = $scope->getType($node->var);

        if ((new ObjectType(Results::class))->isSuperTypeOf($results)->yes() === false) {
            return [];
        }

        $shapes = $results->getTemplateType(Results::class, 'TShape')->getConstantArrays();
        $key = $scope->getType($node->dim);

        if ($shapes === []) {
            return [];
        }

        foreach ($shapes as $shape) {
            if ($shape->hasOffsetValueType($key)->no() === false) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(String('Task not found: ')->append($key->describe(VerbosityLevel::value()))->toString())
                ->identifier('elephant.taskNotFound')
                ->build(),
        ];
    }
}
