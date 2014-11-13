<?php

namespace Jerive\Bundle\SchedulerBundle\Entity\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Jerive\Bundle\SchedulerBundle\Entity\Job;

/**
 * Description of JobRepository
 *
 * @author jerome
 */
class JobRepository extends EntityRepository
{
    public function getExecutableJobs()
    {
        $qb = $this->createQueryBuilder('t');
        $qb
            ->where('t.nextExecutionDate <= :date')
            ->andWhere('t.status = :status')
            ->orderBy('t.nextExecutionDate')
            ->setParameters(array(
                'date'   => new \DateTime('now'),
                'status' => Job::STATUS_WAITING,
            ))
        ;

        return $qb->getQuery()->getResult();
    }

    public function getRemovableJobs()
    {
        $qb = $this->createQueryBuilder('t');
        $qb
            ->where('t.status = :status')
            ->setParameters(array(
                'status' => Job::STATUS_TERMINATED,
            ))
        ;

        return $qb->getQuery()->getResult();
    }

    public function getFailedJobs()
    {
        $qb = $this->createQueryBuilder('t');
        $qb
            ->where($qb->expr()->orX(
                $qb->expr()->eq('t.status', Job::STATUS_FAILED),
                $qb->expr()->andX(
                    $qb->expr()->eq('t.status', Job::STATUS_RUNNING),
                    $qb->expr()->lte('t.lastExecutionDate', ':date')
            )))
            ->setParameter('date', new \DateTime('30 minutes'))
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array$tags
     * @return QueryBuilder
     */
    public function getQueryBuilderForTags($tags)
    {
        $qb = $this->createQueryBuilder('j');

        return $qb
            ->select('j,t')
            ->join('j.tags', 't')
            ->where($qb->expr()->in('t.name', $tags))
            ->groupBy('j')
            ->having('count(t) = ' . count(array_unique($tags)))
        ;
    }
}
