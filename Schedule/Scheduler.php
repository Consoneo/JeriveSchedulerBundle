<?php

namespace Jerive\Bundle\SchedulerBundle\Schedule;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Monolog\Logger;
use Jerive\Bundle\SchedulerBundle\Entity\Job;
use Jerive\Bundle\SchedulerBundle\Entity\JobTag;

/**
 * Description of Scheduler
 *
 * @author jerome
 */
class Scheduler implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var EntityManager
     */
    protected $em;

    public function __construct(EntityManager $em)
    {
        $this->em =  $em;
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function setOutputInterface(OutputInterface $output)
    {
        $this->output = $output;
        return $this;
    }

    protected function log($level, $message)
    {
        $logger = $this->container->get('logger');
        $logger->log($level, $message);

        if (isset($this->output)) {
            $this->output->writeln(sprintf('<info>%s</info>', $message));
        }
    }

    /**
     * @param string $serviceId
     * @param null $name
     * @return Job
     */
    public function createJob($serviceId, $name = null)
    {
        $service = $this->container->get($serviceId);

        return (new Job())
            ->setServiceId($serviceId)
            ->setName($name)
            ->setProxy($this->container->get('jerive_scheduler.proxy')->setService($service))
        ;
    }

    /**
     * Process the tags added to the entity
     *
     * @param \Jerive\Bundle\SchedulerBundle\Entity\Job $job
     */
    protected function processTags(Job $job)
    {
        $names = array();
        $collection = $job->getTags();

        if ($collection->count()) {
            foreach($job->getTags() as $key => $tag) {
                if (!$tag->getId()) {
                    unset($collection[$key]);
                    $names[$tag->getName()] = $tag->getName();
                }
            }

            $qb = $this->em->getRepository('JeriveSchedulerBundle:JobTag')->createQueryBuilder('t');
            $qb->where($qb->expr()->in('t.name', array_values($names)));

            foreach($qb->getQuery()->getResult() as $tag) {
                /** @var $tag JobTag */
                unset($names[$tag->getName()]);
                $collection->add($tag);
            }

            foreach($names as $name) {
                $collection->add((new JobTag)->setName($name));
            }
        }
    }

    /**
     * @param Job $job
     * @return Scheduler
     */
    public function schedule(Job $job)
    {
        $this->processTags($job);

        $this->em->persist($job);
        $this->em->flush($job);

        return $this;
    }

    /**
     * @return Scheduler
     */
    public function executeJobs()
    {
        foreach($this->getJobRepository()->getExecutableJobs() as $job) {
            /** @var $job Job */
            $job->prepareForExecution();
            $this->getManager()->persist($job);
            $this->getManager()->flush($job);

            try {
                $job->getProxy()->setDoctrine($this->container->get('doctrine'));
                $job->execute($this->container->get($job->getServiceId()));
                $this->log(Logger::INFO, sprintf('SUCCESS [%s] in job [%s]#%s', $job->getServiceId(), $job->getName(), $job->getId()));
            } catch (\Exception $e) {
                $this->log(Logger::ERROR, sprintf('FAILURE [%s] in job [%s]#%s', $job->getServiceId(), $job->getName(), $job->getId()));
            }

            $this->getManager()->persist($job);
            $this->getManager()->flush($job);
        }

        return $this;
    }

    /**
     * @return Scheduler
     */
    public function cleanJobs()
    {
        foreach($this->getJobRepository()->getRemovableJobs() as $job) {
            /** @var $job Job */
            $this->getManager()->remove($job);
            $this->log(Logger::INFO, sprintf('REMOVE job [%s]#%s', $job->getName(), $job->getId()));
        }

        $this->em->flush();
        return $this;
    }

    /**
     * @param array $tags
     * @param array $criteria
     * @return array|\Jerive\Bundle\SchedulerBundle\Entity\Job[]
     */
    public function findByTags($tags, $criteria = array())
    {
        $qb = $this->getJobRepository()->getQueryBuilderForTags($tags);

        foreach($criteria as $key => $value) {
            $qb->andWhere('j.' . $key . ' = :' . $key)->setParameter($key, $value);
        }

        return $qb
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     *
     * @param object $entity
     * @param array $tags
     * @param array $criteria
     * @return Array|\Jerive\Bundle\SchedulerBundle\Entity\Job[]
     */
    public function findByEntityTag($entity, $tags = array(), $criteria = array())
    {
        if (!is_array($tags)) {
            $tags = array($tags);
        }

        $tags[] = $this->container->get('jerive_scheduler.proxy')->getTagForEntity($entity);
        return $this->findByTags($tags, $criteria);
    }

    /**
     * @return \Jerive\Bundle\SchedulerBundle\Entity\Repository\JobRepository
     */
    protected function getJobRepository()
    {
        return $this->em->getRepository('JeriveSchedulerBundle:Job');
    }

    /**
     * @return \Doctrine\ORM\EntityManager
     */
    protected function getManager()
    {
        return $this->em;
    }
}
