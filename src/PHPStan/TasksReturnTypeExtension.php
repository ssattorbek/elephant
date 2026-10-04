<?php
declare(strict_types=1);

namespace Elephant\PHPStan;

use Elephant\Facades\Tasks;
use Elephant\Future\Awaitable;
use Elephant\Tasks\PendingResults;
use Elephant\Tasks\Result;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Gives Tasks::all(), Tasks::settle() and Tasks::map() the exact shape of their results.
 *
 * For Tasks::all(['user' => Http::get($url), 'count' => fn (): int => 5]) the results become
 * array{user: Response, count: int}, so every key keeps its own type and missing keys can be reported.
 * Tasks::map() keeps the keys of its items and gives each of them the callback's return type.
 */
final class TasksReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return Tasks::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['all', 'settle', 'map'], true);
    }

    /**
     * Returns PendingResults typed with the shape of the given tasks, or null to keep the declared type.
     *
     * The key type is widened to string or int, so a missing key is reported once, by TaskNotFoundRule.
     */
    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, Scope $scope): ?Type
    {
        $shapes = self::shapes($methodReflection->getName(), $methodCall, $scope);

        if ($shapes === []) {
            return null;
        }

        $shape = TypeCombinator::union(...$shapes);
        $keys = $shape->getIterableKeyType()->generalize(GeneralizePrecision::lessSpecific());

        return new GenericObjectType(PendingResults::class, [$shape, $keys, $shape->getIterableValueType()]);
    }

    /**
     * Returns one result shape for every constant array the call may receive.
     *
     * @return list<Type>
     */
    private static function shapes(string $method, StaticCall $methodCall, Scope $scope): array
    {
        if ($method === 'map') {
            return self::mapped($methodCall, $scope);
        }

        $argument = $methodCall->getArg('tasks', 0);

        if ($argument === null) {
            return [];
        }

        $settle = $method === 'settle';

        return array_map(
            static fn (ConstantArrayType $group): Type => self::shape($group, $scope, $settle),
            $scope->getType($argument->value)->getConstantArrays(),
        );
    }

    /**
     * Returns the result shapes of Tasks::map(): the keys of the items, each with the callback's return type.
     *
     * @return list<Type>
     */
    private static function mapped(StaticCall $methodCall, Scope $scope): array
    {
        $items = $methodCall->getArg('items', 0);
        $callback = $methodCall->getArg('callback', 1);

        if ($items === null || $callback === null) {
            return [];
        }

        $value = self::value($scope->getType($callback->value), $scope);

        return array_map(
            static fn (ConstantArrayType $group): Type => self::fill($group, $value),
            $scope->getType($items->value)->getConstantArrays(),
        );
    }

    /**
     * Builds a shape with the keys of the items, all holding the same value type.
     */
    private static function fill(ConstantArrayType $items, Type $value): Type
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();

        foreach ($items->getKeyTypes() as $key) {
            $builder->setOffsetValueType($key, $value);
        }

        return $builder->getArray();
    }

    /**
     * Builds the result shape of one group of tasks, keeping every key.
     */
    private static function shape(ConstantArrayType $tasks, Scope $scope, bool $settle): Type
    {
        $builder = ConstantArrayTypeBuilder::createEmpty();
        $values = $tasks->getValueTypes();

        foreach ($tasks->getKeyTypes() as $index => $key) {
            $builder->setOffsetValueType($key, self::result($values[$index], $scope, $settle));
        }

        return $builder->getArray();
    }

    /**
     * Returns what one task resolves to, wrapped in Result for Tasks::settle().
     */
    private static function result(Type $task, Scope $scope, bool $settle): Type
    {
        $value = self::value($task, $scope);

        if ($settle) {
            return new GenericObjectType(Result::class, [$value]);
        }

        return $value;
    }

    /**
     * Returns the value of an awaitable or the return type of a closure task.
     */
    private static function value(Type $task, Scope $scope): Type
    {
        if ((new ObjectType(Awaitable::class))->isSuperTypeOf($task)->yes()) {
            return $task->getTemplateType(Awaitable::class, 'T');
        }

        if ($task->isCallable()->yes()) {
            return TypeCombinator::union(...array_map(
                static fn (ParametersAcceptor $acceptor): Type => $acceptor->getReturnType(),
                $task->getCallableParametersAcceptors($scope),
            ));
        }

        return new MixedType();
    }
}
