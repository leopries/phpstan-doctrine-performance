<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests;

use Composer\InstalledVersions;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Leopries\PHPStanDoctrinePerformance\ClassHierarchyCollector;
use Leopries\PHPStanDoctrinePerformance\EntityArgumentCollector;
use Leopries\PHPStanDoctrinePerformance\IndirectLazyAssociationRule;
use Leopries\PHPStanDoctrinePerformance\ParameterAssociationCollector;

/**
 * Lazy association reads behind a condition on the entity's state, with the state set by the caller.
 *
 * @extends RuleTestCase<IndirectLazyAssociationRule>
 *
 * @coversNothing
 */
final class GuardedStateTest extends RuleTestCase
{
    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            InstalledVersions::getInstallPath('phpstan/phpstan-doctrine').'/extension.neon',
            __DIR__.'/indirect-lazy.neon',
        ];
    }

    public function testGuardedState(): void
    {
        $call = static fn (string $method): string => \sprintf('Call to MembershipRepository::%s() inside a loop lazily loads Doctrine associations of its argument. Fetch-join or batch-load them before the loop.', $method);
        $tip = static fn (string $method, string $callee, string $severity = 'info (10/100) = 30 to-one load, served from the identity map for repeated targets − 10 behind a condition, not on every iteration'): string => \sprintf(
            'Reads Membership::$community (lazy to-one) via MembershipRepository::%1$s → MembershipRepository::%2$s, Membership::$user (lazy to-one) via MembershipRepository::%1$s → MembershipRepository::%2$s; potential N+1 queries unless the argument is already fetch-joined. Severity %3$s − 10 fetch state of the argument is not known across the call.',
            $method, $callee, $severity,
        );
        $this->analyse([__DIR__.'/fixtures/guarded-state.php'], [
            // forceInvitation() on 128 sets Invited, for which addHistory() returns before it reads the associations.
            [$call('forceActivation'), 136, $tip('forceActivation', 'addHistory')],
            // keepState() sets nothing; maybeActivate() may set Active behind a condition.
            [$call('keepState'), 139, $tip('keepState', 'addHistory')],
            [$call('maybeActivate'), 142, $tip('maybeActivate', 'addHistory')],
            // Declined (145) is excluded by in_array(), Left (148) by `!==`. changeAfterGuard() changes the state before its
            // guard, so the Invited that inviteThenChange() set decides nothing there.
            [$call('inviteThenChange'), 151, $tip('inviteThenChange', 'changeAfterGuard', 'low (20/100) = 30 to-one load, served from the identity map for repeated targets')],
        ]);
    }

    protected function getRule(): Rule
    {
        require_once __DIR__.'/fixtures/Entities.php';
        require_once __DIR__.'/fixtures/guarded-state.php';

        return self::getContainer()->getByType(IndirectLazyAssociationRule::class);
    }

    protected function getCollectors(): array
    {
        return [
            self::getContainer()->getByType(ParameterAssociationCollector::class),
            self::getContainer()->getByType(EntityArgumentCollector::class),
            self::getContainer()->getByType(ClassHierarchyCollector::class),
        ];
    }
}
