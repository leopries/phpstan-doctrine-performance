<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports calls inside loops that pass a loop-dependent entity to a method which reads lazy associations of that
 * parameter, itself or further down its call chain: `foreach ($orders as $order) { $this->mapper->map($order); }`.
 *
 * @phpstan-import-type Call from EntityArgumentCollector
 *
 * @phpstan-type Load array{path: string, cause: string, toOne: bool, target: string, conditional: bool, uncertain: bool, chain: list<string>, origin: string}
 *
 * @implements Rule<CollectedDataNode>
 */
final class IndirectLazyAssociationRule implements Rule
{
    /** Loads further down than this many calls are dropped: every hop adds conditions the summary does not see. */
    private const int MAX_CHAIN = 4;
    private const int LISTED = 3;

    /** @var array<string, array<int, array<string, Load>>> the loads a method causes itself, by parameter and association path */
    private array $reads = [];

    /** @var array<string, list<Call>> */
    private array $passes = [];

    /** @var array<string, list<string>> */
    private array $descendants = [];

    /** @var array<string, array<string, Load>|null> null while the method is being resolved */
    private array $loads = [];

    /** @param list<string> $ignoredMethods */
    public function __construct(
        private readonly Severity $severity,
        private readonly array $ignoredMethods,
        private readonly int $maxImplementations,
    ) {
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $this->reads = $this->passes = $this->descendants = $this->loads = [];
        $direct = [];
        foreach ($node->get(ParameterAssociationCollector::class) as $file => $reads) {
            foreach ($reads as $read) {
                $key = $read['variable'].'.'.$read['path'];
                $direct[$file][$key] = [...$direct[$file][$key] ?? [], ...$read['loops']];
                if (null === $read['param']) {
                    continue;
                }

                $load = ['path' => $read['path'], 'cause' => $read['cause'], 'toOne' => $read['toOne'], 'target' => $read['target'], 'conditional' => $read['conditional'], 'uncertain' => false, 'chain' => [$read['method']], 'origin' => $file.':'.$read['line']];
                $known = $this->reads[$read['method']][$read['param']][$read['path']] ?? null;
                if (null === $known || self::weight($load) > self::weight($known)) {
                    $this->reads[$read['method']][$read['param']][$read['path']] = $load;
                }
            }
        }
        $sites = [];
        foreach ($node->get(EntityArgumentCollector::class) as $file => $calls) {
            foreach ($calls as $call) {
                if (null !== $call['caller']) {
                    $this->passes[$call['caller']][] = $call;
                }
                $sites[] = [$file, $call];
            }
        }
        $this->descendants = ClassHierarchyCollector::descendants($node);

        $errors = [];
        foreach ($sites as [$file, $call]) {
            foreach ($call['callees'] as $callee) {
                $loads = [];
                $depth = 0;
                foreach ($call['args'] as $index => $argument) {
                    foreach (0 === $argument['depth'] ? [] : $this->through($callee, $index) as $path => $load) {
                        // Skipped: a fetch join the calling method is known to have made, and an association the loop itself reads on the
                        // same variable, which the direct rule reports. Anything else about the argument's state is unknown here.
                        if (!\in_array($path, $argument['fetched'], true)
                            && [] === array_intersect($call['loops'], $direct[$file][($argument['variable'] ?? '').'.'.$argument['path'].$path] ?? [])
                            && (!isset($loads[$path]) || self::weight($load) > self::weight($loads[$path]))) {
                            $loads[$path] = $load;
                            $depth = max($depth, $argument['depth']);
                        }
                    }
                }
                if ([] === $loads) {
                    continue;
                }

                // Worst first; among equals the association closest to the argument, which a fetch join has to cover first.
                usort($loads, static fn (array $a, array $b): int => [self::weight($b), substr_count($a['path'], '.'), $a['path']] <=> [self::weight($a), substr_count($b['path'], '.'), $b['path']]);
                $worst = $loads[0];
                $assessment = $this->severity->assess($worst['toOne'] ? Severity::TO_ONE : Severity::TO_MANY, $depth, $file, $worst['target'], $worst['uncertain'], $worst['conditional'] || $call['conditional']);
                // The direct rule sees where its receiver comes from; here the entity crossed a call, so a fetch join made elsewhere stays invisible.
                $assessment = Severity::adjust($assessment, -10, 'fetch state of the argument is not known across the call');

                $listed = [];
                foreach (\array_slice($loads, 0, self::LISTED) as $load) {
                    $listed[] = CauseReport::shorten($load['cause']).(1 === \count($load['chain']) ? ' in '.CauseReport::shorten($load['chain'][0]).'()' : ' via '.implode(' → ', array_map(CauseReport::shorten(...), $load['chain'])));
                }
                // The message names only the call, so a baseline entry survives changes inside the callee.
                $error = $this->severity->apply(RuleErrorBuilder::message(\sprintf(
                    'Call to %s() inside %s lazily loads Doctrine associations of its argument. Fetch-join or batch-load them before the loop.',
                    CauseReport::shorten($callee),
                    $depth > 1 ? 'nested loops' : 'a loop',
                ))->identifier('doctrine.indirectLazyAssociationInLoop')->file($file)->line($call['line']), $assessment, $worst['cause'], $worst['origin'], \sprintf(
                    'Reads %s%s; potential %s queries unless the argument is already fetch-joined.',
                    implode(', ', $listed),
                    \count($loads) > self::LISTED ? \sprintf(' and %d more', \count($loads) - self::LISTED) : '',
                    $depth > 1 ? 'N×M (possibly N²)' : 'N+1',
                ));
                if (null !== $error) {
                    $errors[$file.':'.$call['line'].':'.$error->getMessage()] = $error;
                }
            }
        }
        $this->reads = $this->passes = $this->descendants = $this->loads = [];

        return array_values($errors);
    }

    /**
     * The loads behind a callee as written at the call site: its own and those of the overrides that may run instead.
     *
     * @return array<string, Load>
     */
    private function through(string $callee, int $param): array
    {
        $targets = ClassHierarchyCollector::targets($callee, $this->descendants, fn (string $method): bool => isset($this->reads[$method]) || isset($this->passes[$method]), $this->maxImplementations);
        $loads = [];
        foreach ($targets as $target) {
            foreach ($this->loads($target, $param) as $path => $load) {
                $load['uncertain'] = $load['uncertain'] || [$callee] !== $targets;
                if (!isset($loads[$path]) || self::weight($load) > self::weight($loads[$path])) {
                    $loads[$path] = $load;
                }
            }
        }

        return $loads;
    }

    /**
     * What a method loads on one of its parameters, keyed by the association path from that parameter.
     *
     * @return array<string, Load>
     */
    private function loads(string $method, int $param): array
    {
        $key = $method.'#'.$param;
        if (\array_key_exists($key, $this->loads) || \in_array($method, $this->ignoredMethods, true)) {
            // Recursion contributes nothing new: the cycle's loads are found on the first visit.
            return $this->loads[$key] ?? [];
        }

        $this->loads[$key] = null;
        $loads = $this->reads[$method][$param] ?? [];
        foreach ($this->passes[$method] ?? [] as $call) {
            foreach ($call['args'] as $index => $argument) {
                if ($argument['param'] !== $param) {
                    continue;
                }
                foreach ($call['callees'] as $callee) {
                    foreach ($this->through($callee, $index) as $load) {
                        if (\count($load['chain']) >= self::MAX_CHAIN) {
                            continue;
                        }
                        // `$this->customers->map($order->getCustomer())` hands on an association: the callee's paths continue from there.
                        $load = ['path' => $argument['path'].$load['path'], 'conditional' => $load['conditional'] || $call['conditional'], 'chain' => [$method, ...$load['chain']]] + $load;
                        if (!isset($loads[$load['path']]) || self::weight($load) > self::weight($loads[$load['path']])) {
                            $loads[$load['path']] = $load;
                        }
                    }
                }
            }
        }

        return $this->loads[$key] = $loads;
    }

    /**
     * The load that would score highest represents its association; ties go to the shorter chain.
     *
     * @param Load $load
     *
     * @return array{int, int}
     */
    private static function weight(array $load): array
    {
        return [($load['toOne'] ? 30 : 45) - ($load['conditional'] ? 10 : 0) - ($load['uncertain'] ? 10 : 0), -\count($load['chain'])];
    }
}
