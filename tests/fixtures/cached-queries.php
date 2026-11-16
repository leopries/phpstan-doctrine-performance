<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * @param list<int>                 $ids
 * @param EntityRepository<Country> $countries
 */
function cachedQueries(array $ids, QueryBuilder $builder, EntityManagerInterface $manager, EntityRepository $countries, Connection $connection, DbalQueryBuilder $dbalBuilder, QueryCacheProfile $profile): void
{
    foreach ($ids as $id) {
        // Result cache in the chain.
        $builder->getQuery()->enableResultCache(3600)->getResult();
        $builder->getQuery()->setResultCacheLifetime(3600)->getOneOrNullResult();
        $builder->getQuery()->setCacheable(true)->getResult();

        // Result cache on a variable.
        $query = $builder->getQuery();
        $query->enableResultCache();
        $query->getResult();
        $cached = $builder->getQuery()->enableResultCache();
        $cached->getArrayResult();

        // Second-level cache enabled on the query builder.
        $secondLevel = $manager->createQueryBuilder()->select('c')->from(Customer::class, 'c')->setCacheable(true);
        $secondLevel->where('c.id = :id')->getQuery()->getResult();

        // Entities with #[ORM\Cache] come from the second-level cache.
        $countries->find($id);
        $countries->findOneBy(['id' => $id]);
        $manager->find(Country::class, $id);

        // DBAL result cache.
        $connection->executeCacheQuery('SELECT 1', [], [], $profile);
        $connection->executeQuery('SELECT 1', [], [], $profile);
        $dbalBuilder->enableResultCache($profile)->fetchAllAssociative();
    }
}

/** @param list<int> $ids */
function uncachedQueries(array $ids, QueryBuilder $builder, EntityManagerInterface $manager, Connection $connection): void
{
    foreach ($ids as $id) {
        $builder->getQuery()->enableResultCache()->disableResultCache()->getResult();
        $builder->getQuery()->setCacheable(false)->getResult();
        $builder->getQuery()->setResultCacheProfile(null)->getResult();

        $query = $builder->getQuery()->enableResultCache();
        $query = $builder->getQuery();
        $query->getResult();

        $other = $builder->getQuery();
        $other->enableResultCache();
        $other->disableResultCache();
        $other->getResult();

        // A cached query builder does not make the entity manager it returns cached.
        $builder->setCacheable(true);
        $builder->getEntityManager()->createQuery('SELECT c FROM Customer c')->getResult();

        $manager->find(Customer::class, $id);
        $connection->executeQuery('SELECT 1', [], [], null);
    }
}
