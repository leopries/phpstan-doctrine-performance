<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\UnionType;

/**
 * @phpstan-type Context array{guards: list<array{string, bool}>, conditional: bool, condition: bool, parameters: array<string, true>, chunks: array<string, true>}
 */
final class LoopDepthVisitor extends NodeVisitorAbstract
{
    public const string ATTRIBUTE = 'doctrinePerformanceLoopDepth';
    public const string LOOPS_ATTRIBUTE = 'doctrinePerformanceLoops';
    public const string USES_ATTRIBUTE = 'doctrinePerformanceAssociationUses';
    public const string LOOP_VARIABLES_ATTRIBUTE = 'doctrinePerformanceLoopVariables';
    public const string GUARDS_ATTRIBUTE = 'doctrinePerformanceGuards';
    public const string CONDITIONAL_ATTRIBUTE = 'doctrinePerformanceConditional';
    public const string CONDITION_ATTRIBUTE = 'doctrinePerformanceLoopCondition';
    private const array CONTEXT = ['guards' => [], 'conditional' => false, 'condition' => false, 'parameters' => [], 'chunks' => []];
    private const string ITERATES_ATTRIBUTE = 'doctrinePerformanceIterates';
    private const array ITERATING_FUNCTIONS = ['array_map', 'array_filter', 'array_walk', 'array_reduce', 'array_find', 'array_find_key', 'array_any', 'array_all', 'usort', 'uasort', 'uksort', 'iterator_apply'];
    private const array ITERATING_METHODS = ['map', 'filter', 'forall', 'exists', 'findfirst', 'reduce', 'partition', 'each'];
    private const array REFERENCE_FUNCTIONS = ['array_shift', 'array_pop', 'array_push', 'array_unshift', 'array_splice', 'array_walk', 'next', 'prev', 'reset', 'end', 'each', 'sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort', 'shuffle', 'preg_match', 'preg_match_all', 'settype', 'extract', 'parse_str'];
    /** Expressions whose value follows from their operands alone; anything else in a query counts as loop-dependent. */
    private const array TRANSPARENT = [Variable::class, PropertyFetch::class, NullsafePropertyFetch::class, ArrayDimFetch::class, MethodCall::class, NullsafeMethodCall::class, StaticCall::class, FuncCall::class, New_::class, ClassConstFetch::class, ConstFetch::class, Scalar::class, InterpolatedStringPart::class, Array_::class, ArrayItem::class, Arg::class, Name::class, Identifier::class, Cast::class, BinaryOp::class, UnaryMinus::class, BooleanNot::class, Ternary::class];

    public function beforeTraverse(array $nodes): ?array
    {
        $variableUses = [];
        $this->collectVariableUses($nodes, null, $variableUses);
        foreach ($nodes as $node) {
            $this->annotate($node, 0, null, $variableUses);
        }

        return null;
    }

    /**
     * The number of loops around an annotated node, counted from the outside, up to the innermost one that changes the
     * given receiver or argument. An association is loaded once per owner: only those loops multiply the queries.
     */
    public static function changingDepth(Expr $expr, Node $annotated): int
    {
        $depth = $annotated->getAttribute(self::ATTRIBUTE, 0);
        if (!\is_int($depth) || $depth < 1) {
            return 0;
        }

        while ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch || $expr instanceof ArrayDimFetch) {
            // Arguments and computed keys can select a different object in every iteration.
            if ((($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) && [] !== $expr->args) || ($expr instanceof ArrayDimFetch && !$expr->dim instanceof Scalar)) {
                return $depth;
            }
            $expr = $expr->var;
        }

        $variables = $annotated->getAttribute(self::LOOP_VARIABLES_ATTRIBUTE);
        if (!$expr instanceof Variable || !\is_string($expr->name) || !\is_array($variables) || \count($variables) !== $depth) {
            return $depth;
        }

        for ($index = $depth - 1; $index >= 0; --$index) {
            if (\is_array($variables[$index] ?? null) && isset($variables[$index][$expr->name])) {
                return $index + 1;
            }
        }

        return 0;
    }

    /**
     * Whether anything the enclosing loops change reaches the receiver or the arguments of a call. Without that the
     * call does the same in every iteration. Conservative: what is not understood counts as dependent.
     */
    public static function dependsOnLoop(MethodCall|StaticCall $call, Scope $scope): bool
    {
        $loops = $call->getAttribute(self::LOOP_VARIABLES_ATTRIBUTE);
        // A query in the loop condition decides when the loop ends: the body is expected to change its result.
        if (!\is_array($loops) || [] === $loops || false !== $call->getAttribute(self::CONDITION_ATTRIBUTE)) {
            return true;
        }
        $changing = [];
        foreach ($loops as $loop) {
            // The outermost loop contains the inner ones, so its call counts are the largest.
            $changing += \is_array($loop) ? $loop : [];
        }

        $chain = [];
        for ($node = $call; $node instanceof CallLike || $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch || $node instanceof ArrayDimFetch;) {
            $chain[spl_object_id($node)] = true;
            $node = $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch || $node instanceof ArrayDimFetch
                ? $node->var : ($node instanceof StaticCall ? $node->class : null);
        }

        $seen = [];
        $ownProperties = [];
        foreach (new NodeFinder()->find([$call], static fn (): bool => true) as $node) {
            if (isset($ownProperties[spl_object_id($node)])) {
                continue;
            }
            if (!array_any(self::TRANSPARENT, static fn (string $class): bool => $node instanceof $class)) {
                return true;
            }
            // Calls outside the receiver chain feed the arguments: only getters are trusted to return the same value again.
            if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && !isset($chain[spl_object_id($node)])
                && (!$node->name instanceof Identifier || 1 !== preg_match('/^(get|is|has|to|format)(?![a-z])/', $node->name->name))) {
                return true;
            }
            if ($node instanceof FuncCall && !$node->name instanceof Name) {
                return true;
            }

            $key = $node instanceof Expr ? self::changeKey($node, false) : null;
            if (null === $key ? $node instanceof Variable : isset($changing[$key])) {
                return true;
            }
            if ($node instanceof Variable) {
                $seen[$key] = $node;
            } elseif (($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) && $node->var instanceof Variable && 'this' === $node->var->name && null !== $key) {
                // `$this->repository` stands for the property; other assignments to `$this` do not change it.
                $seen[$key] = $node;
                $ownProperties[spl_object_id($node->var)] = true;
            }
        }

        // Another call on the same object, or one that receives it, can change what this call does: a query builder
        // gets its parameter set, array_shift() takes the next element. Scalars and arrays are passed by value.
        // Doctrine's own services answer by their arguments, like the services in `$this`.
        $own = self::callUses($call);
        $services = new UnionType([new ObjectType(ObjectManager::class), new ObjectType(ObjectRepository::class), new ObjectType(Connection::class)]);
        foreach ($seen as $key => $expr) {
            $uses = $changing[$key.'()'] ?? 0;
            $type = $scope->getType($expr);
            if (isset($changing[$key.'&']) || (\is_int($uses) && $uses > ($own[$key.'()'] ?? 0) && !$type->isObject()->no() && !$services->isSuperTypeOf($type)->yes())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<string>>   $variableUses
     * @param list<int>                     $loops
     * @param list<array<string, int|true>> $loopVariables what changes per iteration, for each enclosing loop; see changingVariables()
     * @param Context                       $context
     */
    private function annotate(Node $node, int $depth, ?Node $parent = null, array $variableUses = [], array $loops = [], array $loopVariables = [], array $context = self::CONTEXT): void
    {
        if (($node instanceof FuncCall && $node->name instanceof Name && \in_array($node->name->toLowerString(), self::ITERATING_FUNCTIONS, true))
            || (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier && \in_array($node->name->toLowerString(), self::ITERATING_METHODS, true))) {
            foreach ($node->args as $argument) {
                if ($argument instanceof Arg && ($argument->value instanceof Closure || $argument->value instanceof ArrowFunction)) {
                    $argument->value->setAttribute(self::ITERATES_ATTRIBUTE, true);
                }
            }
        }

        if ($node instanceof FunctionLike || $node instanceof ClassLike) {
            if (true === $node->getAttribute(self::ITERATES_ATTRIBUTE)) {
                // The callback runs once per element, like a loop body.
                ++$depth;
                $loops[] = $node->getStartFilePos();
                $loopVariables[] = $this->changingVariables($node);
            } else {
                $depth = 0;
                $loops = [];
                $loopVariables = [];
            }
            $context = self::CONTEXT;
            if ($node instanceof FunctionLike) {
                foreach ($node->getParams() as $parameter) {
                    if ($parameter->var instanceof Variable && \is_string($parameter->var->name)) {
                        $context['parameters'][$parameter->var->name] = true;
                    }
                }
                foreach (new NodeFinder()->findInstanceOf([$node], Assign::class) as $assignment) {
                    if ($assignment->var instanceof Variable && \is_string($assignment->var->name) && $this->isChunking($assignment->expr, [])) {
                        $context['chunks'][$assignment->var->name] = true;
                    }
                }
            }
            $variableUses = [];
            foreach ($node->getSubNodeNames() as $name) {
                $this->collectVariableUses($node->{$name}, $node, $variableUses); // @phpstan-ignore property.dynamicName (Traverse PHP-Parser's declared subnodes.)
            }
        }

        if ($node instanceof MethodCall || $node instanceof StaticCall || $node instanceof NullsafeMethodCall || $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
            $node->setAttribute(self::ATTRIBUTE, $depth);
            $node->setAttribute(self::LOOPS_ATTRIBUTE, $loops);
            $node->setAttribute(self::LOOP_VARIABLES_ATTRIBUTE, $loopVariables);
            $node->setAttribute(self::GUARDS_ATTRIBUTE, $context['guards']);
            $node->setAttribute(self::CONDITIONAL_ATTRIBUTE, $context['conditional']);
            $node->setAttribute(self::CONDITION_ATTRIBUTE, $context['condition']);
            $use = $this->describeUse($node, $parent);
            if ($parent instanceof Assign && $parent->expr === $node && $parent->var instanceof Variable && \is_string($parent->var->name)) {
                $node->setAttribute(self::USES_ATTRIBUTE, $variableUses[$parent->var->name] ?? ['reference']);
            } else {
                $node->setAttribute(self::USES_ATTRIBUTE, [$use]);
            }
        }

        // `while (true)`, `for (;;)` and loops over array_chunk() work through batches: one query per batch is the point, not growth.
        $batchLoop = (($node instanceof While_ || $node instanceof Do_) && $node->cond instanceof ConstFetch && 'true' === $node->cond->name->toLowerString())
            || ($node instanceof For_ && [] === $node->cond)
            || ($node instanceof Foreach_ && $this->isChunking($node->expr, $context['chunks']));
        $guard = $node instanceof If_ && [] === $node->elseifs ? $this->parameterGuard($node->cond, $context['parameters']) : null;
        $isLoop = !$batchLoop && ($node instanceof Foreach_ || $node instanceof For_ || $node instanceof While_ || $node instanceof Do_);
        $changing = $isLoop ? $this->changingVariables($node) : [];

        foreach ($node->getSubNodeNames() as $name) {
            $childDepth = $depth;
            $childLoops = $loops;
            $childVariables = $loopVariables;
            $childContext = $context;
            if ($isLoop && (!$node instanceof Foreach_ || 'stmts' === $name)
                && (!$node instanceof For_ || \in_array($name, ['cond', 'loop', 'stmts'], true))) {
                ++$childDepth;
                $childLoops[] = $node->getStartFilePos();
                $childVariables[] = $changing;
                $childContext['conditional'] = false;
                $childContext['condition'] = 'stmts' !== $name;
            }
            if (null !== $guard && \in_array($name, ['stmts', 'else'], true)) {
                // `if ($flush)` depends on the caller's argument, which the call graph resolves; it is not a data condition.
                $childContext['guards'][] = [$guard[0], 'stmts' === $name ? $guard[1] : !$guard[1]];
            } elseif (($node instanceof If_ && 'cond' !== $name) || ($node instanceof Ternary && 'cond' !== $name) || ($node instanceof TryCatch && 'catches' === $name)) {
                $childContext['conditional'] = true;
            }

            $children = $node->{$name}; // @phpstan-ignore property.dynamicName (Traverse PHP-Parser's declared subnodes.)
            foreach (\is_array($children) ? $children : [$children] as $child) {
                if (!$child instanceof Node) {
                    continue;
                }
                $this->annotate($child, $childDepth, $node, $variableUses, $childLoops, $childVariables, $childContext);

                // After `if (!$flush) { return; }` the rest of the block only runs when $flush is true.
                $exit = $child instanceof If_ && [] === $child->elseifs && null === $child->else ? $this->parameterGuard($child->cond, $context['parameters']) : null;
                $last = $child instanceof If_ && [] !== $child->stmts ? $child->stmts[\count($child->stmts) - 1] : null;
                if (null !== $exit && ($last instanceof Return_ || $last instanceof Continue_ || ($last instanceof Expression && $last->expr instanceof Throw_))) {
                    $childContext['guards'][] = [$exit[0], !$exit[1]];
                }
            }
        }
    }

    /**
     * @param array<string, true> $parameters
     *
     * @return array{string, bool}|null the parameter and the value it must have for the branch to run
     */
    private function parameterGuard(Expr $condition, array $parameters): ?array
    {
        $expected = true;
        if ($condition instanceof BooleanNot) {
            $condition = $condition->expr;
            $expected = false;
        }

        return $condition instanceof Variable && \is_string($condition->name) && isset($parameters[$condition->name]) ? [$condition->name, $expected] : null;
    }

    /** @param array<string, true> $chunks variables assigned from array_chunk() */
    private function isChunking(Expr $expr, array $chunks): bool
    {
        return ($expr instanceof FuncCall && $expr->name instanceof Name && 'array_chunk' === $expr->name->toLowerString())
            || ($expr instanceof Variable && \is_string($expr->name) && isset($chunks[$expr->name]));
    }

    /**
     * What a loop changes: the variables it assigns (iteration variables, callback parameters, anything set or
     * counted in its body) as `name`, assigned properties of `$this` as `this->name`, and the entries of callUses().
     *
     * @return array<string, int|true>
     */
    private function changingVariables(Node $loop): array
    {
        $variables = [];
        $targets = [];
        foreach (new NodeFinder()->find([$loop], static fn (Node $node): bool => $node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef || $node instanceof Foreach_ || $node instanceof Param
            || $node instanceof PreInc || $node instanceof PostInc || $node instanceof PreDec || $node instanceof PostDec || $node instanceof Catch_) as $node) {
            if ($node instanceof Foreach_) {
                $targets[] = $node->valueVar;
                $targets[] = $node->keyVar;
            } elseif ($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef || $node instanceof Param
                || $node instanceof PreInc || $node instanceof PostInc || $node instanceof PreDec || $node instanceof PostDec || $node instanceof Catch_) {
                $targets[] = $node->var;
            }
        }
        foreach (new NodeFinder()->find(array_filter($targets), static fn (Node $node): bool => $node instanceof Variable || $node instanceof PropertyFetch) as $target) {
            if ($target instanceof Variable && \is_string($target->name)) {
                $variables[$target->name] = true;
            } elseif ($target instanceof PropertyFetch && null !== ($key = self::changeKey($target, false)) && str_starts_with($key, 'this->')) {
                $variables[$key] = true;
            }
        }

        return $variables + self::callUses($loop);
    }

    /**
     * How often calls may change what is behind a variable or a property of `$this`: under `name()` as the receiver
     * or an argument of a method or constructor, under `name&` as an argument of a function that takes references.
     *
     * @return array<string, int>
     */
    private static function callUses(Node $node): array
    {
        $uses = [];
        foreach (new NodeFinder()->findInstanceOf([$node], CallLike::class) as $call) {
            // `$this` and the services in its properties are taken to be stateless as receivers; local objects (query builders, iterators) are not.
            $receiver = $call instanceof MethodCall || $call instanceof NullsafeMethodCall ? self::changeKey($call->var, true) : null;
            $keys = null === $receiver || str_starts_with($receiver, 'this') ? [] : [$receiver.'()'];
            $byReference = $call instanceof FuncCall && (!$call->name instanceof Name || \in_array($call->name->toLowerString(), self::REFERENCE_FUNCTIONS, true));
            foreach ($call->isFirstClassCallable() || ($call instanceof FuncCall && !$byReference) ? [] : $call->getArgs() as $argument) {
                $key = self::changeKey($argument->value, false);
                $keys[] = null === $key ? null : $key.($byReference ? '&' : '()');
            }
            foreach (array_filter($keys) as $key) {
                $uses[$key] = ($uses[$key] ?? 0) + 1;
            }
        }

        return $uses;
    }

    /** The variable an expression reads from, or `this->name` for a property of `$this`; null when it is neither. */
    private static function changeKey(Expr $expr, bool $throughCalls): ?string
    {
        $property = null;
        while ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch || $expr instanceof ArrayDimFetch
            || ($throughCalls && ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall))) {
            $property = ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) && $expr->name instanceof Identifier ? $expr->name->name : null;
            $expr = $expr->var;
        }
        if (!$expr instanceof Variable || !\is_string($expr->name)) {
            return null;
        }

        return 'this' !== $expr->name ? $expr->name : (null === $property ? 'this' : 'this->'.$property);
    }

    /** @param array<string, list<string>> $uses */
    private function collectVariableUses(mixed $value, ?Node $parent, array &$uses, bool $opaque = false): void
    {
        if (\is_array($value)) {
            foreach ($value as $child) {
                $this->collectVariableUses($child, $parent, $uses, $opaque);
            }

            return;
        }

        if (!$value instanceof Node) {
            return;
        }

        $opaque = $opaque || $value instanceof FunctionLike || $value instanceof ClassLike;
        if ($value instanceof Variable && \is_string($value->name)) {
            if (!$opaque && (($parent instanceof Assign && $parent->var === $value)
                || $parent instanceof Param
                || ($parent instanceof Foreach_ && ($parent->valueVar === $value || $parent->keyVar === $value)))) {
                return;
            }

            $uses[$value->name][] = $opaque ? 'unknown' : $this->describeUse($value, $parent);
        }

        foreach ($value->getSubNodeNames() as $name) {
            $this->collectVariableUses($value->{$name}, $value, $uses, $opaque); // @phpstan-ignore property.dynamicName (Traverse PHP-Parser's declared subnodes.)
        }
    }

    private function describeUse(Node $node, ?Node $parent): string
    {
        // shortcut: returned references and references stored in arrays are not followed; extend tracking for later reads when needed.
        if (($parent instanceof MethodCall || $parent instanceof NullsafeMethodCall) && $parent->var === $node && $parent->name instanceof Identifier) {
            return $parent->isFirstClassCallable() ? 'reference' : 'method:'.$parent->name->toString();
        }

        if (($parent instanceof PropertyFetch || $parent instanceof NullsafePropertyFetch) && $parent->var === $node && $parent->name instanceof Identifier) {
            return 'property:'.$parent->name->toString();
        }

        // Comparing identity or handing the object on does not initialize a proxy.
        if ($parent instanceof Expression || $parent instanceof Return_ || $parent instanceof Instanceof_
            || $parent instanceof Identical || $parent instanceof NotIdentical
            || ($parent instanceof ArrowFunction && $parent->expr === $node)) {
            return 'reference';
        }

        if ($parent instanceof Assign && $parent->expr === $node && $parent->var instanceof ArrayDimFetch && $parent->var->var instanceof Variable && \is_string($parent->var->var->name)) {
            return 'array:'.$parent->var->var->name;
        }

        return 'unknown';
    }
}
