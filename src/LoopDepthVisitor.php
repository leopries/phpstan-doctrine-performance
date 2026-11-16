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
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Match_;
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
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
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
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\UnionType;

/**
 * Assignment: receiver path, `*` for a new object or `property:name`/`method:name`, loop depth of the statement,
 * depth of the innermost loop that changes the assigned value (-1: never a lazy proxy), the assigned constant (see
 * constant()) and the calls before it in a fluent chain, which have to return `$this`.
 *
 * Condition: a node only runs when the value behind a receiver path and member (`method:getState`, `property:state`)
 * is (true) or is not (false) one of the given constants. The last field says whether nothing in the function wrote to
 * the object before: only then does the state a caller set still hold there.
 *
 * Decision: the receiver path and member a `match` decides on, the constants it maps to true and to false, and its
 * default arm (null without one).
 *
 * @phpstan-type Assignment array{string, string, int, int, string|null, list<string>}
 * @phpstan-type Condition array{string, string, bool, list<string>, bool}
 * @phpstan-type Decision array{string, string, list<string>, list<string>, bool|null}
 * @phpstan-type Context array{guards: list<array{string, bool}>, conditional: bool, condition: bool, parameters: array<string, true>, chunks: array<string, true>, assigned: list<Assignment>, conditions: list<Condition>, decisions: array<string, Decision>, written: array<string, true>}
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
    public const string LOOP_ITERATES_ATTRIBUTE = 'doctrinePerformanceLoopIterates';
    public const string ASSIGNED_ATTRIBUTE = 'doctrinePerformanceAssigned';
    public const string CONDITIONS_ATTRIBUTE = 'doctrinePerformanceConditions';
    private const string GROUP_ATTRIBUTE = 'doctrinePerformanceLoopGroup';
    private const array CONTEXT = ['guards' => [], 'conditional' => false, 'condition' => false, 'parameters' => [], 'chunks' => [], 'assigned' => [], 'conditions' => [], 'decisions' => [], 'written' => []];
    private const string ITERATES_ATTRIBUTE = 'doctrinePerformanceIterates';
    private const array ITERATING_FUNCTIONS = ['array_map', 'array_filter', 'array_walk', 'array_reduce', 'array_find', 'array_find_key', 'array_any', 'array_all', 'usort', 'uasort', 'uksort', 'iterator_apply'];
    private const array ITERATING_METHODS = ['map', 'filter', 'forall', 'exists', 'findfirst', 'reduce', 'partition', 'each'];
    private const array REFERENCE_FUNCTIONS = ['array_shift', 'array_pop', 'array_push', 'array_unshift', 'array_splice', 'array_walk', 'next', 'prev', 'reset', 'end', 'each', 'sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort', 'shuffle', 'preg_match', 'preg_match_all', 'settype', 'extract', 'parse_str'];
    /** Expressions whose value follows from their operands alone; anything else in a query counts as loop-dependent. */
    private const array TRANSPARENT = [Variable::class, PropertyFetch::class, NullsafePropertyFetch::class, ArrayDimFetch::class, MethodCall::class, NullsafeMethodCall::class, StaticCall::class, FuncCall::class, New_::class, ClassConstFetch::class, ConstFetch::class, Scalar::class, InterpolatedStringPart::class, Array_::class, ArrayItem::class, Arg::class, Name::class, Identifier::class, Cast::class, BinaryOp::class, UnaryMinus::class, BooleanNot::class, Ternary::class];

    public function beforeTraverse(array $nodes): ?array
    {
        $this->groupScope($nodes);
        foreach (new NodeFinder()->findInstanceOf($nodes, FunctionLike::class) as $function) {
            $this->groupScope($function->getStmts() ?? []);
        }
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
     * Names the iteration that decides a variable at an annotated node: the innermost loop that iterates it as its value
     * or assigns it. Loops over the same unchanged array share one name for their value: the second loop sees the
     * entities, and the associations, the first one loaded. Null when no enclosing loop changes the variable.
     */
    public static function iterationKey(Node $annotated, string $variable): ?string
    {
        $loops = $annotated->getAttribute(self::LOOPS_ATTRIBUTE);
        $iterates = $annotated->getAttribute(self::LOOP_ITERATES_ATTRIBUTE);
        $variables = $annotated->getAttribute(self::LOOP_VARIABLES_ATTRIBUTE);
        if (!\is_array($loops) || !\is_array($iterates) || !\is_array($variables)) {
            return null;
        }

        for ($index = \count($loops) - 1; $index >= 0; --$index) {
            $iterated = $iterates[$index] ?? null;
            if (\is_array($iterated) && $iterated[1] === $variable) {
                return '@'.$iterated[0];
            }
            if (\is_array($variables[$index] ?? null) && isset($variables[$index][$variable])) {
                return $loops[$index].':'.$variable;
            }
        }

        return null;
    }

    /**
     * The variable and the property fetches or argument-less calls an expression is made of: `$order->getCustomer()`
     * is `order.getCustomer`. Null for anything else.
     */
    public static function receiverPath(Expr $expr): ?string
    {
        $path = '';
        while (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch)
            && $expr->name instanceof Identifier && (($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) || [] === $expr->args)) {
            $path = '.'.$expr->name->toString().$path;
            $expr = $expr->var;
        }

        return $expr instanceof Variable && \is_string($expr->name) ? $expr->name.$path : null;
    }

    /**
     * What earlier statements of the same iteration put into an object at an annotated node, so that reading it loads
     * nothing: `*` when the object was created with `new`, `property:name` for an assigned property and `method:name`
     * for a call with arguments, which may be a setter. Only statements inside the loops that change the object count,
     * and only values those loops do not change: a different proxy in every iteration is still loaded every time.
     *
     * @return list<string>
     */
    public static function assignedMembers(Node $annotated, Expr $receiver, int $changingDepth): array
    {
        $path = self::receiverPath($receiver);
        $assigned = $annotated->getAttribute(self::ASSIGNED_ATTRIBUTE, []);
        if (null === $path || !\is_array($assigned)) {
            return [];
        }

        $members = [];
        foreach ($assigned as $assignment) {
            if (\is_array($assignment) && $assignment[0] === $path && $assignment[2] >= $changingDepth && $assignment[3] < $changingDepth) {
                $members[] = $assignment[1];
            }
        }

        return $members;
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
     * @param list<array{int, string|null}> $iterates     for each enclosing loop its group and the variable it iterates; see iterationKey()
     * @param Context                       $context
     */
    private function annotate(Node $node, int $depth, ?Node $parent = null, array $variableUses = [], array $loops = [], array $loopVariables = [], array $context = self::CONTEXT, array $iterates = []): void
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
                $iterates[] = [$node->getStartFilePos(), null];
            } else {
                $depth = 0;
                $loops = [];
                $loopVariables = [];
                $iterates = [];
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
            $node->setAttribute(self::LOOP_ITERATES_ATTRIBUTE, $iterates);
            $node->setAttribute(self::ASSIGNED_ATTRIBUTE, $context['assigned']);
            $node->setAttribute(self::CONDITIONS_ATTRIBUTE, $context['conditions']);
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
        $condition = $node instanceof If_ && [] === $node->elseifs ? self::condition($node->cond, $context['decisions']) : null;
        if (null !== $condition) {
            $condition[4] = !isset($context['written'][explode('.', $condition[0])[0]]);
        }
        $isLoop = !$batchLoop && ($node instanceof Foreach_ || $node instanceof For_ || $node instanceof While_ || $node instanceof Do_);
        $changing = $isLoop ? $this->changingVariables($node) : [];

        foreach ($node->getSubNodeNames() as $name) {
            $childDepth = $depth;
            $childLoops = $loops;
            $childVariables = $loopVariables;
            $childIterates = $iterates;
            $childContext = $context;
            if ($isLoop && (!$node instanceof Foreach_ || 'stmts' === $name)
                && (!$node instanceof For_ || \in_array($name, ['cond', 'loop', 'stmts'], true))) {
                ++$childDepth;
                $childLoops[] = $node->getStartFilePos();
                $childVariables[] = $changing;
                $group = $node->getAttribute(self::GROUP_ATTRIBUTE);
                $childIterates[] = [\is_int($group) ? $group : $node->getStartFilePos(),
                    $node instanceof Foreach_ && !$node->byRef && $node->valueVar instanceof Variable && \is_string($node->valueVar->name) ? $node->valueVar->name : null];
                $childContext['conditional'] = false;
                $childContext['condition'] = 'stmts' !== $name;
            }
            if (null !== $guard && \in_array($name, ['stmts', 'else'], true)) {
                // `if ($flush)` depends on the caller's argument, which the call graph resolves; it is not a data condition.
                $childContext['guards'][] = [$guard[0], 'stmts' === $name ? $guard[1] : !$guard[1]];
            } elseif (($node instanceof If_ && 'cond' !== $name) || ($node instanceof Ternary && 'cond' !== $name) || ($node instanceof TryCatch && 'catches' === $name)) {
                $childContext['conditional'] = true;
            }
            if (null !== $condition && \in_array($name, ['stmts', 'else'], true)) {
                $childContext['conditions'][] = 'stmts' === $name ? $condition : self::negate($condition);
            }

            $children = $node->{$name}; // @phpstan-ignore property.dynamicName (Traverse PHP-Parser's declared subnodes.)
            foreach (\is_array($children) ? $children : [$children] as $child) {
                if (!$child instanceof Node) {
                    continue;
                }
                $this->annotate($child, $childDepth, $node, $variableUses, $childLoops, $childVariables, $childContext, $childIterates);
                $childContext['assigned'] = $this->assignments($child, $childDepth, $childVariables, $childContext['assigned']);
                [$childContext['conditions'], $childContext['decisions']] = $this->conditions($child, $childContext['conditions'], $childContext['decisions']);
                $childContext['written'] += $this->writtenVariables($child);

                // After `if (!$flush) { return; }` the rest of the block only runs when $flush is true.
                $last = $child instanceof If_ && [] !== $child->stmts ? $child->stmts[\count($child->stmts) - 1] : null;
                if ($child instanceof If_ && [] === $child->elseifs && null === $child->else
                    && ($last instanceof Return_ || $last instanceof Continue_ || ($last instanceof Expression && $last->expr instanceof Throw_))) {
                    $exit = $this->parameterGuard($child->cond, $context['parameters']);
                    if (null !== $exit) {
                        $childContext['guards'][] = [$exit[0], !$exit[1]];
                    }
                    // After `if (!$shouldPersist) { return; }` with `$shouldPersist = match ($membership->getState()) {…}`.
                    $exit = self::condition($child->cond, $childContext['decisions']);
                    if (null !== $exit) {
                        $exit[4] = !isset($childContext['written'][explode('.', $exit[0])[0]]);
                        $childContext['conditions'][] = self::negate($exit);
                    }
                }
            }
        }
    }

    /**
     * The assignments still known after a statement: those to variables it reassigns are dropped, and an unconditional
     * `$order = new Order()`, `$order->customer = $customer` or `$order->setCustomer($customer)` is added.
     *
     * @param list<array<string, int|true>> $loopVariables
     * @param list<Assignment>              $assigned
     *
     * @return list<Assignment>
     */
    private function assignments(Node $statement, int $depth, array $loopVariables, array $assigned): array
    {
        if ([] !== $assigned) {
            // A reassigned variable is another object; a member written anywhere in the statement, also behind a condition,
            // may hold something else now. The unconditional writes are added again below.
            $reassigned = $this->reassignedVariables($statement);
            $written = self::writtenMembers($statement);
            $assigned = array_values(array_filter($assigned, static fn (array $assignment): bool => !isset($reassigned[explode('.', $assignment[0])[0]]) && !isset($written[$assignment[0].'|'.$assignment[1]])));
        }
        if (!$statement instanceof Expression) {
            return $assigned;
        }

        $expr = $statement->expr;
        if ($expr instanceof Assign && $expr->var instanceof Variable && \is_string($expr->var->name) && $expr->expr instanceof New_) {
            $assigned[] = [$expr->var->name, '*', $depth, -1, null, []];
        } elseif ($expr instanceof Assign && ($expr->var instanceof PropertyFetch || $expr->var instanceof NullsafePropertyFetch) && $expr->var->name instanceof Identifier
            && null !== ($path = self::receiverPath($expr->var->var))) {
            $assigned[] = [$path, 'property:'.$expr->var->name->toString(), $depth, self::valueDepth($expr->expr, $depth, $loopVariables, $assigned), self::constant($expr->expr), []];
        } else {
            // `$membership->setState(MembershipState::Invited)->setInvitedAt($now)`: each call of a fluent chain.
            $chain = [];
            while (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) && $expr->name instanceof Identifier && !$expr->isFirstClassCallable() && [] !== $expr->getArgs()) {
                array_unshift($chain, [$expr->name->toString(), self::argumentValues($expr)]);
                $expr = $expr->var;
            }
            $path = [] === $chain ? null : self::receiverPath($expr);
            if (null !== $path) {
                $via = [];
                foreach ($chain as [$name, $values]) {
                    $assigned[] = [$path, 'method:'.$name, $depth, max(-1, ...array_map(static fn (Expr $value): int => self::valueDepth($value, $depth, $loopVariables, $assigned), $values)),
                        1 === \count($values) ? self::constant($values[0]) : null, $via];
                    $via[] = $name;
                }
            }
        }

        return $assigned;
    }

    /**
     * The conditions and the decisions still known after a statement. Writing a member of the object a condition is
     * about, or reassigning its variable, may change the value it tested; `$keep = match (…) {…}` adds a decision.
     *
     * @param list<Condition>         $conditions
     * @param array<string, Decision> $decisions
     *
     * @return array{list<Condition>, array<string, Decision>}
     */
    private function conditions(Node $statement, array $conditions, array $decisions): array
    {
        if ([] !== $conditions || [] !== $decisions) {
            $reassigned = $this->reassignedVariables($statement);
            $written = [];
            foreach (array_keys(self::writtenMembers($statement)) as $member) {
                $written[explode('|', $member)[0]] = true;
            }
            $changed = static fn (string $path): bool => isset($reassigned[explode('.', $path)[0]]) || isset($written[$path]);
            $conditions = array_values(array_filter($conditions, static fn (array $condition): bool => !$changed($condition[0])));
            $decisions = array_filter($decisions, static fn (array $decision, string $variable): bool => !isset($reassigned[$variable]) && !$changed($decision[0]), \ARRAY_FILTER_USE_BOTH);
        }

        if ($statement instanceof Expression && $statement->expr instanceof Assign && $statement->expr->var instanceof Variable && \is_string($statement->expr->var->name)
            && $statement->expr->expr instanceof Match_ && null !== ($decision = self::decision($statement->expr->expr))) {
            $decisions[$statement->expr->var->name] = $decision;
        }

        return [$conditions, $decisions];
    }

    /**
     * The variables whose objects a statement may change: reassigned ones and those it writes a member of or calls a
     * method with arguments on, which may be a setter.
     *
     * @return array<string, true>
     */
    private function writtenVariables(Node $statement): array
    {
        $written = $this->reassignedVariables($statement);
        foreach (array_keys(self::writtenMembers($statement)) as $member) {
            $written[explode('.', explode('|', $member)[0])[0]] = true;
        }

        return $written;
    }

    /**
     * The members a statement may write anywhere inside it, as `path|property:name` or `path|method:name` for any call
     * with arguments, which may be a setter.
     *
     * @return array<string, true>
     */
    private static function writtenMembers(Node $statement): array
    {
        $written = [];
        foreach (new NodeFinder()->find([$statement], static fn (Node $node): bool => $node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef || $node instanceof MethodCall || $node instanceof NullsafeMethodCall) as $node) {
            if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier && !$node->isFirstClassCallable() && [] !== $node->getArgs()) {
                $path = self::receiverPath($node->var);
                // The receiver of a fluent chain is the object at its start.
                for ($receiver = $node->var; null === $path && ($receiver instanceof MethodCall || $receiver instanceof NullsafeMethodCall);) {
                    $receiver = $receiver->var;
                    $path = self::receiverPath($receiver);
                }
                if (null !== $path) {
                    $written[$path.'|method:'.$node->name->toString()] = true;
                }
            } elseif (($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef)
                && ($node->var instanceof PropertyFetch || $node->var instanceof NullsafePropertyFetch) && $node->var->name instanceof Identifier
                && null !== ($path = self::receiverPath($node->var->var))) {
                $written[$path.'|property:'.$node->var->name->toString()] = true;
            }
        }

        return $written;
    }

    /**
     * What has to hold for a condition to be true, when it tests a member against constants: `$membership->getState()
     * === MembershipState::Active`, `!==`, `in_array(…)`, a `match` that yields booleans, or a variable assigned one.
     *
     * @param array<string, Decision> $decisions
     *
     * @return Condition|null
     */
    private static function condition(Expr $condition, array $decisions): ?array
    {
        if ($condition instanceof BooleanNot) {
            $inner = self::condition($condition->expr, $decisions);

            return null === $inner ? null : self::negate($inner);
        }
        $decision = $condition instanceof Variable && \is_string($condition->name) ? ($decisions[$condition->name] ?? null)
            : ($condition instanceof Match_ ? self::decision($condition) : null);
        if (null !== $decision) {
            // Without a default arm a value the arms do not list throws an UnhandledMatchError: it never gets here either.
            return true === $decision[4] ? [$decision[0], $decision[1], false, $decision[3], true] : [$decision[0], $decision[1], true, $decision[2], true];
        }
        if ($condition instanceof Identical || $condition instanceof NotIdentical || $condition instanceof Equal || $condition instanceof NotEqual) {
            [$subject, $value] = null !== self::subject($condition->left) ? [self::subject($condition->left), self::constant($condition->right)] : [self::subject($condition->right), self::constant($condition->left)];

            return null === $subject || null === $value ? null : [$subject[0], $subject[1], $condition instanceof Identical || $condition instanceof Equal, [$value], true];
        }
        if ($condition instanceof FuncCall && $condition->name instanceof Name && 'in_array' === $condition->name->toLowerString() && !$condition->isFirstClassCallable()
            && \count($condition->getArgs()) >= 2 && null !== ($subject = self::subject($condition->getArgs()[0]->value)) && $condition->getArgs()[1]->value instanceof Array_) {
            $values = [];
            foreach ($condition->getArgs()[1]->value->items as $item) {
                $value = null === $item->key && !$item->unpack ? self::constant($item->value) : null;
                if (null === $value) {
                    return null;
                }
                $values[] = $value;
            }

            return [$subject[0], $subject[1], true, $values, true];
        }

        return null;
    }

    /** @return Decision|null */
    private static function decision(Match_ $match): ?array
    {
        $subject = self::subject($match->cond);
        if (null === $subject) {
            return null;
        }
        $decision = [$subject[0], $subject[1], [], [], null];
        foreach ($match->arms as $arm) {
            $result = $arm->body instanceof ConstFetch ? $arm->body->name->toLowerString() : null;
            if ('true' !== $result && 'false' !== $result) {
                return null;
            }
            if (null === $arm->conds) {
                $decision[4] = 'true' === $result;
                continue;
            }
            foreach ($arm->conds as $cond) {
                $value = self::constant($cond);
                if (null === $value) {
                    return null;
                }
                $decision['true' === $result ? 2 : 3][] = $value;
            }
        }

        return $decision;
    }

    /**
     * @param Condition $condition
     *
     * @return Condition
     */
    private static function negate(array $condition): array
    {
        $condition[2] = !$condition[2];

        return $condition;
    }

    /** @return array{string, string}|null the receiver path and member a getter call or property fetch reads */
    private static function subject(Expr $expr): ?array
    {
        if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) && $expr->name instanceof Identifier && !$expr->isFirstClassCallable() && [] === $expr->getArgs()
            && null !== ($path = self::receiverPath($expr->var))) {
            return [$path, 'method:'.$expr->name->toString()];
        }
        if (($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) && $expr->name instanceof Identifier && null !== ($path = self::receiverPath($expr->var))) {
            return [$path, 'property:'.$expr->name->toString()];
        }

        return null;
    }

    /** A value known without running the code: `Enum::Case` and other class constants, true, false, null, strings and integers. */
    private static function constant(Expr $expr): ?string
    {
        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier
            && !\in_array($expr->class->toLowerString(), ['self', 'static', 'parent'], true)) {
            return ltrim($expr->class->toString(), '\\').'::'.$expr->name->toString();
        }
        if ($expr instanceof ConstFetch && \in_array($expr->name->toLowerString(), ['true', 'false', 'null'], true)) {
            return $expr->name->toLowerString();
        }

        return $expr instanceof String_ ? var_export($expr->value, true) : ($expr instanceof Int_ ? (string) $expr->value : null);
    }

    /**
     * How deep the innermost loop sits that changes an assigned value: -1 for values that are never a lazy proxy (new
     * objects, scalars, null), the statement's own depth for anything not understood, such as a getReference() call.
     *
     * @param list<array<string, int|true>> $loopVariables
     * @param list<Assignment>              $assigned
     */
    private static function valueDepth(Expr $value, int $depth, array $loopVariables, array $assigned): int
    {
        if ($value instanceof New_ || $value instanceof Scalar || $value instanceof ConstFetch || $value instanceof ClassConstFetch || $value instanceof Array_) {
            return -1;
        }
        if (!$value instanceof Variable || !\is_string($value->name)) {
            return $depth;
        }
        foreach ($assigned as $assignment) {
            if ($assignment[0] === $value->name && '*' === $assignment[1]) {
                return -1;
            }
        }
        for ($index = \count($loopVariables) - 1; $index >= 0; --$index) {
            if (isset($loopVariables[$index][$value->name])) {
                return $index + 1;
            }
        }

        return 0;
    }

    /** @return array<string, true> the variables a statement may assign anywhere inside it */
    private function reassignedVariables(Node $statement): array
    {
        $targets = [];
        foreach (new NodeFinder()->find([$statement], static fn (Node $node): bool => $node instanceof Assign || $node instanceof AssignRef || $node instanceof AssignOp || $node instanceof Foreach_ || $node instanceof Unset_ || $node instanceof ClosureUse) as $node) {
            if ($node instanceof Foreach_) {
                array_push($targets, $node->valueVar, $node->keyVar);
            } elseif ($node instanceof Unset_) {
                array_push($targets, ...$node->vars);
            } elseif ($node instanceof ClosureUse) {
                $targets[] = $node->byRef ? $node->var : null;
            } elseif ($node instanceof Assign || $node instanceof AssignRef || $node instanceof AssignOp) {
                $targets[] = $node->var;
            }
        }

        $variables = [];
        foreach ($targets as $target) {
            foreach ($target instanceof List_ || $target instanceof Array_ ? new NodeFinder()->findInstanceOf([$target], Variable::class) : [$target] as $variable) {
                if ($variable instanceof Variable && \is_string($variable->name)) {
                    $variables[$variable->name] = true;
                }
            }
        }

        return $variables;
    }

    /**
     * Marks each `foreach` over a variable with the first earlier loop of the same function, at the same loop depth,
     * that iterates the same variable while nothing changes it in between: both see the same entities.
     *
     * @param array<Node> $statements
     */
    private function groupScope(array $statements): void
    {
        $loops = [];
        $nodes = [];
        $this->scopeNodes($statements, '', $loops, $nodes);

        $seen = [];
        foreach ($loops as [$loop, $stack]) {
            if (!$loop->expr instanceof Variable || !\is_string($loop->expr->name) || $loop->byRef) {
                continue;
            }
            foreach (array_reverse($seen) as [$earlier, $earlierStack]) {
                if ($earlier->expr instanceof Variable && $earlier->expr->name === $loop->expr->name && $earlierStack === $stack && $earlier->getEndFilePos() < $loop->getStartFilePos()) {
                    if (!self::changedBetween($loop->expr->name, $nodes, $earlier->getStartFilePos(), $loop->getStartFilePos())) {
                        $loop->setAttribute(self::GROUP_ATTRIBUTE, $earlier->getAttribute(self::GROUP_ATTRIBUTE, $earlier->getStartFilePos()));
                    }
                    break;
                }
            }
            $seen[] = [$loop, $stack];
        }
    }

    /**
     * @param list<array{Foreach_, string}> $loops every foreach of the scope and the loops around it
     * @param list<Node>                    $nodes every node of the scope, without nested functions and classes
     */
    private function scopeNodes(mixed $value, string $stack, array &$loops, array &$nodes): void
    {
        if (\is_array($value)) {
            foreach ($value as $child) {
                $this->scopeNodes($child, $stack, $loops, $nodes);
            }

            return;
        }
        if (!$value instanceof Node || $value instanceof FunctionLike || $value instanceof ClassLike) {
            return;
        }

        $nodes[] = $value;
        if ($value instanceof Foreach_) {
            $loops[] = [$value, $stack];
        }
        if ($value instanceof Foreach_ || $value instanceof For_ || $value instanceof While_ || $value instanceof Do_) {
            $stack .= '/'.$value->getStartFilePos();
        }
        foreach ($value->getSubNodeNames() as $name) {
            $this->scopeNodes($value->{$name}, $stack, $loops, $nodes); // @phpstan-ignore property.dynamicName (Traverse PHP-Parser's declared subnodes.)
        }
    }

    /**
     * Whether anything between two positions may change what a variable holds: an assignment to it or into it,
     * a reference to it, or a call that receives it. Conservative: an array handed to any method counts.
     *
     * @param list<Node> $nodes
     */
    private static function changedBetween(string $variable, array $nodes, int $from, int $to): bool
    {
        foreach ($nodes as $node) {
            if ($node->getStartFilePos() < $from || $node->getStartFilePos() >= $to) {
                continue;
            }
            $targets = match (true) {
                $node instanceof Assign, $node instanceof AssignOp => [$node->var],
                $node instanceof AssignRef => [$node->var, $node->expr],
                $node instanceof Unset_ => $node->vars,
                $node instanceof Foreach_ => array_filter([$node->valueVar, $node->keyVar, $node->byRef ? $node->expr : null]),
                $node instanceof ClosureUse => $node->byRef ? [$node->var] : [],
                $node instanceof MethodCall, $node instanceof NullsafeMethodCall => [$node->var, ...self::argumentValues($node)],
                $node instanceof StaticCall, $node instanceof New_ => self::argumentValues($node),
                $node instanceof FuncCall => !$node->name instanceof Name || \in_array($node->name->toLowerString(), self::REFERENCE_FUNCTIONS, true) ? self::argumentValues($node) : [],
                default => [],
            };
            foreach ($targets as $target) {
                if (self::changeKey($target, true) === $variable) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<Expr> */
    private static function argumentValues(CallLike $call): array
    {
        return $call->isFirstClassCallable() ? [] : array_values(array_map(static fn (Arg $argument): Expr => $argument->value, $call->getArgs()));
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
