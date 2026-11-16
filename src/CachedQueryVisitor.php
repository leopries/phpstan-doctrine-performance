<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\NodeVisitorAbstract;

/**
 * Marks query objects whose results come from Doctrine's result cache or second-level query cache, as in
 * `$qb->getQuery()->enableResultCache(3600)->getResult()` or `$query->setCacheable(true)` before `$query->getResult()`.
 * Works on the syntax tree, so the marker follows method chains and local variables, not properties or other methods.
 */
final class CachedQueryVisitor extends NodeVisitorAbstract
{
    public const string ATTRIBUTE = 'doctrinePerformanceCached';

    /** Calls that return a different object, which does not inherit the cache setting of the receiver. */
    private const array FRESH = ['getentitymanager', 'getconnection', 'createquery', 'createquerybuilder', 'createnamedquery', 'createnativequery', 'getrepository'];

    /** @var array<string, bool> cache state of the local variables of the current function */
    private array $variables = [];

    /** @var list<array<string, bool>> the same for the enclosing functions */
    private array $outer = [];

    public function beforeTraverse(array $nodes): ?array
    {
        $this->variables = $this->outer = [];

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof FunctionLike) {
            $this->outer[] = $this->variables;
            // Arrow functions capture the enclosing variables by value.
            if (!$node instanceof ArrowFunction) {
                $this->variables = [];
            }
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof FunctionLike) {
            $this->variables = array_pop($this->outer) ?? [];
        } elseif ($node instanceof Variable && \is_string($node->name)) {
            if (true === ($this->variables[$node->name] ?? false)) {
                $node->setAttribute(self::ATTRIBUTE, true);
            }
        } elseif (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier) {
            $name = strtolower($node->name->toString());
            $effect = $node->isFirstClassCallable() ? null : self::effect($name, $node->getArgs()[0] ?? null);
            $cached = $effect ?? (true === $node->var->getAttribute(self::ATTRIBUTE) && !\in_array($name, self::FRESH, true));
            if ($cached) {
                $node->setAttribute(self::ATTRIBUTE, true);
            }
            // `$query->enableResultCache();` as a statement changes the query object the variable holds.
            if (null !== $effect && $node->var instanceof Variable && \is_string($node->var->name)) {
                $this->variables[$node->var->name] = $effect;
            }
        } elseif ($node instanceof Assign && $node->var instanceof Variable && \is_string($node->var->name)) {
            $this->variables[$node->var->name] = true === $node->expr->getAttribute(self::ATTRIBUTE);
        }

        return null;
    }

    /** Whether the call switches the cache on (true) or off (false); null when it does not touch it. */
    private static function effect(string $name, ?Arg $argument): ?bool
    {
        $literal = $argument?->value instanceof ConstFetch ? strtolower($argument->value->name->toString()) : null;

        return match ($name) {
            'enableresultcache', 'setresultcachelifetime', 'setresultcacheid' => true,
            'disableresultcache' => false,
            'setresultcacheprofile', 'setresultcache' => 'null' !== $literal,
            // Second-level query cache on an ORM query or query builder.
            'setcacheable' => 'false' !== $literal,
            default => null,
        };
    }
}
