<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports calls inside loops to methods that run a Doctrine query somewhere down their call chain.
 *
 * @phpstan-import-type Call from CallGraphCollector
 * @phpstan-import-type Guard from CallGraphCollector
 *
 * @phpstan-type Site array{caller: string, line: int, depth: int, callees: list<string>, query: string|null, kind: string|null, entity: string|null, guards: list<Guard>, args: array<int|string, bool|null>|null, conditional: bool, dependent: bool, file: string}
 * @phpstan-type Reach array{depth: int, chain: list<string>, kind: QueryCalls::*, entity: string|null, uncertain: bool, conditional: bool, writes: bool, cause: string, origin: string}
 *
 * @implements Rule<CollectedDataNode>
 */
final class IndirectQueryInLoopRule implements Rule
{
    /** @var array<string, list<Site>> */
    private array $calls = [];

    /** @var array<string, list<string>> */
    private array $descendants = [];

    /** @var array<string, Reach|false|null> null while the method is being resolved */
    private array $reach = [];

    /** @var array<string, array{list<string>, bool}> */
    private array $targets = [];

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
        $this->calls = $this->descendants = $this->reach = $this->targets = [];
        $sites = [];
        foreach ($node->get(CallGraphCollector::class) as $file => $calls) {
            foreach ($calls as $call) {
                $call += ['file' => $file];
                $this->calls[$call['caller']][] = $call;
                if ($call['depth'] > 0 && null === $call['query']) {
                    $sites[] = [$file, $call];
                }
            }
        }
        $this->descendants = ClassHierarchyCollector::descendants($node);

        $errors = [];
        foreach ($sites as [$file, $call]) {
            $reach = $this->reachThrough($call);
            if (null === $reach) {
                continue;
            }

            $depth = $call['depth'] + $reach['depth'];
            $query = array_pop($reach['chain']);
            // Same call, same queries in every iteration; a callee that also writes has an effect each time and stays an N+1.
            $independent = !$call['dependent'] && !$reach['writes'];
            // The message names only the call, so a baseline entry survives changes further down the chain.
            $error = $this->severity->apply(RuleErrorBuilder::message(\sprintf(
                'Call to %s() inside %s executes a Doctrine query. Batch the work or fetch the data before the loop.',
                CauseReport::shorten($reach['chain'][0]),
                $call['depth'] > 1 ? 'nested loops' : 'a loop',
            ))->identifier('doctrine.indirectQueryInLoop')->file($file)->line($call['line']), $this->severity->assess(Severity::kind($reach['kind']), $depth, $file, $reach['entity'], $reach['uncertain'], $call['conditional'] || [] !== $call['guards'] || $reach['conditional'], $independent), $reach['cause'], $reach['origin'], \sprintf(
                'Runs %s()%s; %s',
                $query,
                \count($reach['chain']) > 1 ? ' via '.implode(' → ', array_map(CauseReport::shorten(...), \array_slice($reach['chain'], 1))) : '',
                $independent ? 'receiver and arguments of the call do not depend on the loop, so every iteration runs the same query: call it once before the loop and reuse the result, unless the loop changes the data it reads.'
                    : 'potential '.($depth > 1 ? 'N×M (possibly N²)' : 'N+1').' queries.',
            ));
            if (null !== $error) {
                $errors[$file.':'.$call['line'].':'.$error->getMessage()] = $error;
            }
        }
        $this->calls = $this->descendants = $this->reach = $this->targets = [];

        return array_values($errors);
    }

    /**
     * The deepest query a call leads to, with the chain of methods in between.
     *
     * @param Site $call
     *
     * @return Reach|null
     */
    private function reachThrough(array $call): ?array
    {
        $best = null;
        $writes = false;
        foreach ($call['callees'] as $callee) {
            [$targets, $uncertain] = $this->targets($callee);
            foreach ($targets as $target) {
                $reach = $this->reach($target, $call['args']);
                $writes = $writes || (null !== $reach && $reach['writes']);
                if (null === $reach || (null !== $best && !self::worse($reach, $best))) {
                    continue;
                }
                $best = ['chain' => [$target, ...$reach['chain']], 'uncertain' => $uncertain || $reach['uncertain']] + $reach;
            }
        }

        return null === $best ? null : ['writes' => $writes] + $best;
    }

    /**
     * @param array<int|string, bool|null>|null $args the literal booleans the caller passes
     *
     * @return Reach|null
     */
    private function reach(string $method, ?array $args): ?array
    {
        if (\in_array($method, $this->ignoredMethods, true)) {
            return null;
        }

        // A method with `if ($flush)` behaves differently per argument, so each combination is resolved on its own.
        $key = $method;
        foreach ($this->calls[$method] ?? [] as $call) {
            foreach ($call['guards'] as $guard) {
                $key .= '|'.$guard['index'].var_export(self::passed($guard, $args), true);
            }
        }
        if (\array_key_exists($key, $this->reach)) {
            // Recursion contributes nothing new: the cycle's queries are found on the first visit.
            $known = $this->reach[$key];

            return false === $known ? null : $known;
        }

        $this->reach[$key] = null;
        $best = null;
        // The representative chain may be a read while another statement of the method writes.
        $writes = false;
        foreach ($this->calls[$method] ?? [] as $call) {
            foreach ($call['guards'] as $guard) {
                $passed = self::passed($guard, $args);
                if (null !== $passed && $passed !== $guard['value']) {
                    continue 2;
                }
            }

            if (null !== $call['query']) {
                $reach = [
                    'depth' => $call['depth'], 'chain' => [$call['query']], 'kind' => match ($call['kind']) {
                        QueryCalls::FLUSH => QueryCalls::FLUSH,
                        QueryCalls::WRITE => QueryCalls::WRITE,
                        QueryCalls::FIND => QueryCalls::FIND,
                        default => QueryCalls::READ,
                    }, 'entity' => $call['entity'],
                    'uncertain' => false, 'conditional' => $call['conditional'], 'writes' => \in_array($call['kind'], [QueryCalls::WRITE, QueryCalls::FLUSH], true),
                    'cause' => $method.' → '.$call['query'].'()', 'origin' => $call['file'].':'.$call['line'],
                ];
            } else {
                $reach = $this->reachThrough($call);
                if (null === $reach) {
                    continue;
                }
                $reach['depth'] += $call['depth'];
                $reach['conditional'] = $reach['conditional'] || $call['conditional'];
            }
            $writes = $writes || $reach['writes'];
            if (null === $best || self::worse($reach, $best)) {
                $best = $reach;
            }
        }
        if (null !== $best) {
            $best['writes'] = $writes;
        }
        $this->reach[$key] = $best ?? false;

        return $best;
    }

    /**
     * @param Guard                             $guard
     * @param array<int|string, bool|null>|null $args
     */
    private static function passed(array $guard, ?array $args): ?bool
    {
        return match (true) {
            null === $args => null,
            \array_key_exists($guard['index'], $args) => $args[$guard['index']],
            \array_key_exists($guard['name'], $args) => $args[$guard['name']],
            default => $guard['default'],
        };
    }

    /**
     * The method itself plus overrides in subclasses and implementations.
     *
     * @return array{list<string>, bool} targets and whether it is uncertain which one runs
     */
    private function targets(string $callee): array
    {
        if (isset($this->targets[$callee])) {
            return $this->targets[$callee];
        }

        $targets = ClassHierarchyCollector::targets($callee, $this->descendants, fn (string $method): bool => isset($this->calls[$method]), $this->maxImplementations);

        return $this->targets[$callee] = [$targets, \count($targets) > 1];
    }

    /**
     * @param Reach $candidate
     * @param Reach $current
     */
    private static function worse(array $candidate, array $current): bool
    {
        // The chain that would score highest represents the call; ties go to the deeper, then the shorter one.
        return [Severity::weight($candidate['kind'], $candidate['depth'] > 0, $candidate['conditional'], $candidate['uncertain']), $candidate['depth'], -\count($candidate['chain'])]
            > [Severity::weight($current['kind'], $current['depth'] > 0, $current['conditional'], $current['uncertain']), $current['depth'], -\count($current['chain'])];
    }
}
