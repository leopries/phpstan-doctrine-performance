<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use Doctrine\ORM\EntityManagerInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use PHPStan\Type\ObjectType;

/** @phpstan-type Source array{class: class-string, paths: list<string>, prefix: string, depth: int} */
final class FetchedAssociations
{
    private const int MAX_CACHED_CLASSES = 32;

    /** @var array<string, ClassLike|null> */
    private array $classes = [];

    public function __construct(
        private readonly Parser $parser,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ObjectMetadataResolver $metadataResolver,
    ) {
    }

    public function isFetched(Expr $receiver, string $property, Scope $scope): bool
    {
        // shortcut: only recognized source paths establish fetch guarantees; extend tracking when other patterns need support.
        $function = $scope->getFunction();
        if (!$function instanceof MethodReflection || $scope->isInAnonymousFunction()) {
            return false;
        }

        $class = $function->getDeclaringClass();
        $method = $this->getMethod($class, $function->getName());
        if (null === $method) {
            return false;
        }

        $source = $this->source($receiver, $class, $method, []);

        return null !== $source && 0 === $source['depth']
            && \in_array($source['prefix'].$property, $source['paths'], true);
    }

    public function getReturnedProperty(ClassReflection $class, string $name): ?string
    {
        $statements = $this->getMethod($class, $name)?->stmts;
        if (null === $statements || 1 !== \count($statements) || !$statements[0] instanceof Return_) {
            return null;
        }

        $expr = $statements[0]->expr;

        return $expr instanceof PropertyFetch && $expr->var instanceof Variable
            && 'this' === $expr->var->name && $expr->name instanceof Identifier
            ? $expr->name->toString() : null;
    }

    /** @param array<string, true> $visited
     * @return Source|null
     */
    private function source(Expr $expr, ClassReflection $class, ClassMethod $method, array $visited): ?array
    {
        $key = $class->getName().'::'.$method->name->toString().':'.$expr->getType().':'.$expr->getStartFilePos().':'.$expr->getEndFilePos();
        if (isset($visited[$key]) || \count($visited) > 32) {
            return null;
        }
        $visited[$key] = true;

        if ($expr instanceof Variable && \is_string($expr->name)) {
            $sources = [];
            $emptyArray = false;
            foreach ($this->nodes($method->stmts ?? []) as $node) {
                if ($node->getStartFilePos() >= $expr->getStartFilePos()) {
                    continue;
                }
                if ($node instanceof Foreach_ && $node->getEndFilePos() >= $expr->getStartFilePos()
                    && $node->valueVar instanceof Variable && $node->valueVar->name === $expr->name) {
                    $source = $this->source($node->expr, $class, $method, $visited);
                    if (null === $source || $source['depth'] < 1 || $node->byRef) {
                        return null;
                    }
                    --$source['depth'];
                    $sources[] = $source;
                }
                if (!$node instanceof Assign) {
                    continue;
                }
                if ($node->var instanceof Variable && $node->var->name === $expr->name) {
                    if (!$this->isUnconditionalAssignment($node, $method)) {
                        return null;
                    }
                    if ($node->expr instanceof Array_ && [] === $node->expr->items) {
                        $emptyArray = true;
                        continue;
                    }
                    $sources[] = $this->source($node->expr, $class, $method, $visited);
                } elseif ($node->var instanceof ArrayDimFetch && $node->var->var instanceof Variable && $node->var->var->name === $expr->name) {
                    if (!$emptyArray) {
                        return null;
                    }
                    $source = $this->source($node->expr, $class, $method, $visited);
                    if (null === $source) {
                        return null;
                    }
                    ++$source['depth'];
                    $sources[] = $source;
                }
            }

            if ([] !== $sources) {
                $source = $this->intersect($sources);

                return null === $source ? null : $this->withoutChangedPaths($expr, $source, $method);
            }

            foreach ($method->params as $index => $param) {
                if (!$param->var instanceof Variable || $param->var->name !== $expr->name || !$method->isPrivate()) {
                    continue;
                }
                $classNode = $this->getClass($class);
                if (null === $classNode) {
                    return null;
                }
                foreach ($classNode->getMethods() as $caller) {
                    foreach ($this->nodes($caller->stmts ?? []) as $call) {
                        if ($call instanceof Expr\StaticCall && $call->name instanceof Identifier
                            && strtolower($call->name->toString()) === strtolower($method->name->toString())) {
                            return null;
                        }
                        if ($call instanceof Array_ && isset($call->items[0], $call->items[1])
                            && $call->items[0]->value instanceof Variable && 'this' === $call->items[0]->value->name
                            && $call->items[1]->value instanceof String_ && strtolower($call->items[1]->value->value) === strtolower($method->name->toString())) {
                            return null;
                        }
                        if (!$call instanceof MethodCall || !$call->var instanceof Variable || 'this' !== $call->var->name) {
                            continue;
                        }
                        if (!$call->name instanceof Identifier) {
                            return null;
                        }
                        if (strtolower($call->name->toString()) !== strtolower($method->name->toString())) {
                            continue;
                        }
                        if ($call->isFirstClassCallable() || !isset($call->getArgs()[$index]) || null !== $call->getArgs()[$index]->name) {
                            return null;
                        }
                        $sources[] = $this->source($call->getArgs()[$index]->value, $class, $caller, $visited);
                    }
                }

                return $this->intersect($sources);
            }

            return null;
        }

        if ($expr instanceof ArrayDimFetch) {
            $source = $this->source($expr->var, $class, $method, $visited);
            if (null !== $source && $source['depth'] > 0) {
                --$source['depth'];

                return $source;
            }

            return null;
        }

        if ($expr instanceof MethodCall && $expr->name instanceof Identifier && !$expr->isFirstClassCallable()) {
            $query = $this->query($expr, $class, $method);
            if (null !== $query) {
                return $query;
            }
            $receiverClass = $this->receiverClass($expr->var, $class, $method);
            if (null !== $receiverClass) {
                $callee = $this->getMethod($receiverClass, $expr->name->toString());
                if (null !== $callee && null !== $callee->stmts) {
                    $sources = [];
                    foreach ($this->nodes($callee->stmts) as $return) {
                        if ($return instanceof Return_) {
                            if (null === $return->expr || ($return->expr instanceof Array_ && [] === $return->expr->items)) {
                                continue;
                            }
                            $sources[] = $this->source($return->expr, $receiverClass, $callee, $visited);
                        }
                    }

                    return $this->intersect($sources);
                }
            }
        }

        if (($expr instanceof MethodCall || $expr instanceof PropertyFetch) && $expr->name instanceof Identifier) {
            $source = $this->source($expr->var, $class, $method, $visited);
            if (null === $source || 0 !== $source['depth']) {
                return null;
            }
            $property = $expr instanceof PropertyFetch ? $expr->name->toString()
                : $this->getReturnedProperty($this->reflectionProvider->getClass($source['class']), $expr->name->toString());
            $mapping = null === $property ? null : ($this->metadataResolver->getClassMetadata($source['class'])?->associationMappings[$property] ?? null);
            if (null !== $mapping) {
                $source['prefix'] .= $property.'.';
                $source['class'] = $mapping->targetEntity;
                $source['depth'] = $mapping->isToOne() ? 0 : 1;

                return $source;
            }
        }

        return null;
    }

    /** @return Source|null */
    private function query(MethodCall $expr, ClassReflection $class, ClassMethod $method): ?array
    {
        if (!$expr->name instanceof Identifier || !\in_array(strtolower($expr->name->toString()), ['getresult', 'getoneornullresult', 'getsingleresult', 'toiterable'], true) || [] !== $expr->args) {
            return null;
        }
        $calls = $this->queryCalls($expr, $method);
        if (null === $calls || [] === $calls) {
            return null;
        }
        $factory = $calls[0];
        $factoryClass = $this->receiverClass($factory->var, $class, $method);
        if (!$factory->name instanceof Identifier || 'createQueryBuilder' !== $factory->name->toString()
            || null === $factoryClass || !(new ObjectType(EntityManagerInterface::class))->isSuperTypeOf(new ObjectType($factoryClass->getName()))->yes()) {
            return null;
        }

        $selected = [];
        $aliases = [];
        $rootClass = null;
        foreach ($calls as $call) {
            if (!$call->name instanceof Identifier) {
                return null;
            }
            $name = strtolower($call->name->toString());
            $args = $call->getArgs();
            foreach ($args as $arg) {
                if (null !== $arg->name || $arg->unpack) {
                    return null;
                }
            }
            if ('from' === $name) {
                if (null !== $rootClass || !isset($args[0], $args[1]) || !$args[0]->value instanceof ClassConstFetch
                    || !$args[0]->value->class instanceof Name || !$args[0]->value->name instanceof Identifier
                    || 'class' !== strtolower($args[0]->value->name->toString()) || !$args[1]->value instanceof String_) {
                    return null;
                }
                $entity = $args[0]->value->class->toString();
                if (!$this->reflectionProvider->hasClass($entity) || null === $this->metadataResolver->getClassMetadata($entity)) {
                    return null;
                }
                $rootClass = $entity;
                $aliases[$args[1]->value->value] = '';
            } elseif ('select' === $name || 'addselect' === $name) {
                if ('select' === $name) {
                    $selected = [];
                }
                foreach ($args as $arg) {
                    if (!$arg->value instanceof String_) {
                        return null;
                    }
                    foreach (explode(',', $arg->value->value) as $alias) {
                        $selected[] = trim(preg_replace('/^DISTINCT\s+/i', '', trim($alias)) ?? '');
                    }
                }
            } elseif (\in_array($name, ['join', 'innerjoin', 'leftjoin'], true)) {
                if (!isset($args[0], $args[1]) || !$args[0]->value instanceof String_ || !$args[1]->value instanceof String_) {
                    return null;
                }
                $parts = explode('.', $args[0]->value->value);
                if (2 !== \count($parts) || !isset($aliases[$parts[0]]) || isset($aliases[$args[1]->value->value])) {
                    return null;
                }
                $aliases[$args[1]->value->value] = $aliases[$parts[0]].$parts[1].'.';
            } elseif (!\in_array($name, ['createquerybuilder', 'getquery', 'getresult', 'getoneornullresult', 'getsingleresult', 'toiterable', 'where', 'andwhere', 'orwhere', 'setparameter', 'setparameters', 'orderby', 'addorderby', 'groupby', 'addgroupby', 'having', 'andhaving', 'orhaving', 'setmaxresults', 'setfirstresult', 'distinct'], true)) {
                return null;
            }
        }

        if (null === $rootClass || !\in_array(array_search('', $aliases, true), $selected, true)) {
            return null;
        }
        $paths = [];
        foreach ($selected as $alias) {
            if (isset($aliases[$alias]) && '' !== $aliases[$alias]) {
                $path = rtrim($aliases[$alias], '.');
                $parent = substr($path, 0, (int) strrpos($path, '.'));
                if (!str_contains($path, '.') || \in_array($parent, $paths, true)) {
                    $paths[] = $path;
                }
            }
        }

        return ['class' => $rootClass, 'paths' => $paths, 'prefix' => '', 'depth' => \in_array(strtolower($expr->name->toString()), ['getoneornullresult', 'getsingleresult'], true) ? 0 : 1];
    }

    /** @return list<MethodCall>|null */
    private function queryCalls(Expr $expr, ClassMethod $method): ?array
    {
        $calls = [];
        while ($expr instanceof MethodCall) {
            array_unshift($calls, $expr);
            $expr = $expr->var;
        }
        if (!$expr instanceof Variable || !\is_string($expr->name) || 'this' === $expr->name) {
            return $calls;
        }
        $prefix = null;
        foreach ($method->stmts ?? [] as $stmt) {
            if ($stmt->getEndFilePos() >= $calls[0]->getStartFilePos()) {
                break;
            }
            if ($stmt instanceof Expression && $stmt->expr instanceof Assign && $stmt->expr->var instanceof Variable && $stmt->expr->var->name === $expr->name) {
                if (null !== $prefix) {
                    return null;
                }
                $prefix = $this->queryCalls($stmt->expr->expr, $method);
            } elseif ($stmt instanceof Expression && $stmt->expr instanceof MethodCall) {
                $root = $stmt->expr;
                while ($root instanceof MethodCall) {
                    $root = $root->var;
                }
                if ($root instanceof Variable && $root->name === $expr->name) {
                    $chain = [];
                    $call = $stmt->expr;
                    while ($call instanceof MethodCall) {
                        foreach ($this->nodes($call->getArgs()) as $argument) {
                            if ($argument instanceof Variable && $argument->name === $expr->name) {
                                return null;
                            }
                        }
                        array_unshift($chain, $call);
                        $call = $call->var;
                    }
                    $prefix = null === $prefix ? null : [...$prefix, ...$chain];
                } else {
                    foreach ($this->nodes([$stmt]) as $node) {
                        if ($node instanceof Variable && $node->name === $expr->name) {
                            return null;
                        }
                    }
                }
            } else {
                foreach ($this->nodes([$stmt]) as $node) {
                    if ($node instanceof Variable && $node->name === $expr->name) {
                        return null;
                    }
                }
            }
        }

        return null === $prefix ? null : [...$prefix, ...$calls];
    }

    private function receiverClass(Expr $expr, ClassReflection $class, ClassMethod $method): ?ClassReflection
    {
        if ($expr instanceof Variable && 'this' === $expr->name) {
            return $class;
        }
        if ($expr instanceof PropertyFetch && $expr->var instanceof Variable && 'this' === $expr->var->name && $expr->name instanceof Identifier
            && $class->hasNativeProperty($expr->name->toString())) {
            $classes = $class->getNativeProperty($expr->name->toString())->getReadableType()->getObjectClassReflections();

            return 1 === \count($classes) ? $classes[0] : null;
        }
        if ($expr instanceof Variable) {
            foreach ($class->getNativeMethod($method->name->toString())->getVariants()[0]->getParameters() as $param) {
                if ($param->getName() === $expr->name) {
                    $classes = $param->getType()->getObjectClassReflections();

                    return 1 === \count($classes) ? $classes[0] : null;
                }
            }
        }

        return null;
    }

    /** @param list<Source|null> $sources
     * @return Source|null
     */
    private function intersect(array $sources): ?array
    {
        $result = array_shift($sources);
        if (null === $result) {
            return null;
        }
        foreach ($sources as $source) {
            if (null === $source || $source['class'] !== $result['class'] || $source['depth'] !== $result['depth'] || $source['prefix'] !== $result['prefix']) {
                return null;
            }
            $result['paths'] = array_values(array_intersect($result['paths'], $source['paths']));
        }

        return $result;
    }

    private function getClass(ClassReflection $class): ?ClassLike
    {
        $name = $class->getName();
        if (!\array_key_exists($name, $this->classes)) {
            $file = $class->getFileName();
            $node = null === $file ? null : new NodeFinder()->findFirst($this->parser->parseFile($file), static fn (Node $node): bool => $node instanceof ClassLike && isset($node->namespacedName) && $node->namespacedName->toString() === $name);
            if (\count($this->classes) >= self::MAX_CACHED_CLASSES) {
                array_shift($this->classes);
            }
            $this->classes[$name] = $node instanceof ClassLike ? $node : null;
        }

        return $this->classes[$name];
    }

    private function getMethod(ClassReflection $class, string $name): ?ClassMethod
    {
        if (!$class->hasNativeMethod($name)) {
            return null;
        }

        return $this->getClass($class->getNativeMethod($name)->getDeclaringClass())?->getMethod($name);
    }

    /** @param array<Node> $nodes
     * @return list<Node>
     */
    private function nodes(array $nodes): array
    {
        return array_values(new NodeFinder()->find($nodes, static fn (Node $node): bool => true));
    }

    private function isUnconditionalAssignment(Assign $assignment, ClassMethod $method): bool
    {
        $statements = $method->stmts ?? [];
        foreach ($this->nodes($statements) as $node) {
            if ($node instanceof Expr\Closure) {
                $statements = [...$statements, ...$node->stmts];
            }
        }
        foreach ($statements as $stmt) {
            if ($stmt instanceof Expression && $stmt->expr === $assignment) {
                return true;
            }
        }

        return false;
    }

    /** @param Source $source
     * @return Source|null
     */
    private function withoutChangedPaths(Variable $variable, array $source, ClassMethod $method): ?array
    {
        foreach ($this->nodes($method->stmts ?? []) as $node) {
            if ($node->getStartFilePos() >= $variable->getStartFilePos()) {
                continue;
            }
            if (($node instanceof Node\Arg && $node->value instanceof Variable && $node->value->name === $variable->name)
                || ($node instanceof Assign && $node->expr instanceof Variable && $node->expr->name === $variable->name)) {
                return null;
            }
            if ($node instanceof Expr\AssignRef || ($node instanceof Assign && $node->var instanceof PropertyFetch)) {
                foreach ($this->nodes([$node]) as $part) {
                    if ($part instanceof Variable && $part->name === $variable->name) {
                        return null;
                    }
                }
            }
            if (!$node instanceof MethodCall || $node->isFirstClassCallable()) {
                continue;
            }
            $root = $node->var;
            while ($root instanceof MethodCall || $root instanceof PropertyFetch || $root instanceof ArrayDimFetch) {
                $root = $root->var;
            }
            if (!$root instanceof Variable || $root->name !== $variable->name) {
                continue;
            }
            if (!$node->name instanceof Identifier) {
                return null;
            }
            $class = $this->reflectionProvider->getClass($source['class']);
            $prefix = $source['prefix'];
            $accesses = [];
            $receiver = $node->var;
            while ($receiver instanceof MethodCall || $receiver instanceof PropertyFetch) {
                array_unshift($accesses, $receiver);
                $receiver = $receiver->var;
            }
            foreach ($accesses as $access) {
                if (!$access->name instanceof Identifier) {
                    return null;
                }
                $property = $access instanceof PropertyFetch ? $access->name->toString() : $this->getReturnedProperty($class, $access->name->toString());
                $mapping = null === $property ? null : ($this->metadataResolver->getClassMetadata($class->getName())?->associationMappings[$property] ?? null);
                if (null === $mapping || !$mapping->isToOne()) {
                    return null;
                }
                $class = $this->reflectionProvider->getClass($mapping->targetEntity);
                $prefix .= $property.'.';
            }
            if (null !== $this->getReturnedProperty($class, $node->name->toString())) {
                continue;
            }
            $body = $this->getMethod($class, $node->name->toString())?->stmts;
            if (null === $body) {
                return null;
            }
            foreach ($body as $stmt) {
                if ($stmt instanceof Return_ && $stmt->expr instanceof Variable && 'this' === $stmt->expr->name) {
                    continue;
                }
                if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign || !$stmt->expr->var instanceof PropertyFetch
                    || !$stmt->expr->var->var instanceof Variable || 'this' !== $stmt->expr->var->var->name
                    || !$stmt->expr->var->name instanceof Identifier
                    || (!$stmt->expr->expr instanceof Variable && !$stmt->expr->expr instanceof Node\Scalar && !$stmt->expr->expr instanceof ConstFetch)) {
                    return null;
                }
                $changed = $prefix.$stmt->expr->var->name->toString();
                $source['paths'] = array_values(array_filter($source['paths'], static fn (string $path): bool => $path !== $changed && !str_starts_with($path, $changed.'.')));
            }
        }

        return $source;
    }
}
