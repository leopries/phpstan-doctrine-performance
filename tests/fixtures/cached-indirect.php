<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\CachedIndirect;

use Doctrine\ORM\EntityRepository;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Customer;

/** @extends EntityRepository<Customer> */
final class HistoryRepository extends EntityRepository
{
    /** @return list<Customer> */
    public function readOnlyByTeam(int $team): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.team = :team')
            ->setParameter('team', $team)
            ->getQuery()
            ->enableResultCache(3600)
            ->getResult();
    }

    /** @return list<Customer> */
    public function findByTeamCached(int $team): array
    {
        $query = $this->createQueryBuilder('h')->where('h.team = :team')->setParameter('team', $team)->getQuery();
        $query->setCacheable(true);

        return $query->getResult();
    }

    /** @return list<Customer> */
    public function byTeam(int $team): array
    {
        return $this->createQueryBuilder('h')->where('h.team = :team')->setParameter('team', $team)->getQuery()->getResult();
    }
}

final class Service
{
    public function __construct(private HistoryRepository $history)
    {
    }

    /** @param list<int> $teams */
    public function run(array $teams): void
    {
        foreach ($teams as $team) {
            $this->history->readOnlyByTeam($team);
            $this->history->findByTeamCached($team);
            $this->history->byTeam($team);
        }
    }
}
