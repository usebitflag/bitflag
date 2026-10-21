<?php

declare(strict_types=1);

namespace Tools\PHPStan\Rules;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Every write to the database goes through an Action. This rule reports Eloquent and query-builder writes made anywhere
 * else: controllers, queries, domain classes, jobs, routes and configuration.
 *
 * Allowed: module Actions, the shared audit, outbox, idempotency and concurrency primitives, database/ (migrations,
 * seeders, factories) and tests.
 *
 * @implements Rule<CallLike>
 */
final class NoModelWritesOutsideActionsRule implements Rule
{
    private const WRITE_METHODS = [
        'save', 'update', 'delete', 'create', 'insert', 'upsert', 'increment', 'decrement', 'forceDelete', 'push',
        'updateOrCreate', 'firstOrCreate', 'insertGetId', 'insertOrIgnore', 'truncate',
    ];

    private const ALLOWED_NAMESPACES = [
        '/^App\\\\Modules\\\\[A-Za-z]+\\\\Actions(\\\\|$)/',
        '/^App\\\\Shared\\\\(Audit|Outbox|Idempotency|Concurrency)(\\\\|$)/',
        '/^Database\\\\/',
        '/^Tests\\\\/',
    ];

    /** Classes whose write methods are database writes. */
    private const WRITER_CLASSES = [
        Model::class,
        EloquentBuilder::class,
        QueryBuilder::class,
        Relation::class,
        DB::class,
    ];

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return [];
        }

        if (! $node->name instanceof Identifier || ! in_array($node->name->toString(), self::WRITE_METHODS, true)) {
            return [];
        }

        if ($this->isAllowed($scope->getNamespace())) {
            return [];
        }

        $writer = $node instanceof MethodCall
            ? $this->writerOfInstance($scope->getType($node->var))
            : $this->writerOfClass($node->class instanceof Name ? $scope->resolveName($node->class) : null);

        if ($writer === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Database writes (%s::%s) belong in a module Action, not in %s.',
                $writer,
                $node->name->toString(),
                $scope->getNamespace() ?? 'global code',
            ))->identifier('bitflag.writeOutsideAction')->build(),
        ];
    }

    private function isAllowed(?string $namespace): bool
    {
        if ($namespace === null) {
            return false;
        }

        foreach (self::ALLOWED_NAMESPACES as $pattern) {
            if (preg_match($pattern, $namespace) === 1) {
                return true;
            }
        }

        return false;
    }

    private function writerOfInstance(Type $type): ?string
    {
        foreach (self::WRITER_CLASSES as $class) {
            if ((new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                return $class;
            }
        }

        return null;
    }

    private function writerOfClass(?string $className): ?string
    {
        if ($className === null || ! $this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $reflection = $this->reflectionProvider->getClass($className);

        foreach (self::WRITER_CLASSES as $class) {
            if ($reflection->getName() === $class || $reflection->is($class)) {
                return $class;
            }
        }

        return null;
    }
}
