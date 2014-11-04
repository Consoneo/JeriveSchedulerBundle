<?php

namespace Jerive\Bundle\SchedulerBundle\Entity;

use Jerive\Bundle\SchedulerBundle\Schedule\ScheduledServiceInterface;
use Jerive\Bundle\SchedulerBundle\Schedule\DelayedProxy;

use Symfony\Component\HttpKernel\Exception\FlattenException;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @ORM\HasLifecycleCallbacks
 * @ORM\Entity(repositoryClass="Jerive\Bundle\SchedulerBundle\Entity\Repository\JobRepository")
 * @ORM\Table(indexes={
 *      @ORM\Index(columns={"status", "nextExecutionDate"})
 * })
 */
class Job
{
    const STATUS_WAITING    = 'waiting';

    const STATUS_RUNNING    = 'running';

    const STATUS_TERMINATED = 'terminated';

    const STATUS_FAILED     = 'failed';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected $name;

    /**
     * @ORM\Column(type="string")
     */
    protected $serviceId;

    /**
     * @var DelayedProxy
     *
     * @ORM\Column(type="object")
     */
    protected $proxy;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $nextExecutionDate;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $insertionDate;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $firstExecutionDate;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $lastExecutionDate;

    /**
     * @ORM\ManyToMany(targetEntity="JobTag", cascade={"persist"})
     */
    protected $tags;

    /**
     * A \DateInterval specification
     * http://www.php.net/manual/fr/dateinterval.construct.php
     *
     * @ORM\Column(type="string", nullable=true)
     */
    protected $repeatEvery;

    /**
     * @ORM\Column(type="integer")
     */
    protected $executionCount = 0;

    /**
     * @ORM\Column(type="string", length=10)
     */
    protected $status = self::STATUS_WAITING;

    /**
     * @var FlattenException
     * @ORM\Column(type="object", nullable=true)
     */
    protected $lastException;

    /**
     * @var boolean
     */
    protected $locked = false;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function lock()
    {
        $this->locked = true;
    }

    public function unlock()
    {
        $this->locked = false;
    }

    public function checkUnlocked()
    {
        if ($this->locked) {
            throw new \RuntimeException('Cannot set values on a locked job');
        }
    }

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    /**
     * Set service
     *
     * @param string $service
     * @return Job
     */
    public function setServiceId($service)
    {
        $this->checkUnlocked();
        $this->serviceId = $service;

        return $this;
    }

    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Get service
     *
     * @return string
     */
    public function getServiceId()
    {
        return $this->serviceId;
    }

    public function prepareForExecution()
    {
        $this->status = self::STATUS_RUNNING;
        $this->lastExecutionDate = new \DateTime('now');
    }

    /**
     * Set params
     *
     * @param string $params
     * @return Job
     */
    public function setProxy($proxy)
    {
        $this->checkUnlocked();
        $this->proxy = $proxy;

        return $this;
    }

    /**
     * Get proxy
     *
     * @return string
     */
    public function getProxy()
    {
        return $this->proxy;
    }

    /**
     * @return DelayedProxy
     */
    public function program()
    {
        return $this->getProxy();
    }

    /**
     * @param ScheduledServiceInterface $service
     */
    public function execute(ScheduledServiceInterface $service)
    {
        if (!$this->status == self::STATUS_RUNNING) {
            throw new \RuntimeException('Cannot execute a job in status other than pending');
        }

        $isFuture = $this->getNextExecutionDate() > new \DateTime('now');

        if (($this->executionCount && !$this->repeatEvery && $isFuture)
                || $isFuture && $this->repeatEvery) {
            if ($this->repeatEvery) {
                $this->status = self::STATUS_WAITING;
            }
        } else {
            $this->checkUnlocked();
            $this->lock();

            try {
                $service->setScheduledJob($this);
                $this->getProxy()->setService($service)->execute($service);
            } catch (\Exception $e) {
                $this->status = self::STATUS_FAILED;
                $this->executionCount++;
                $this->lastException = FlattenException::create($e);
                throw $e;
            }

            $this->unlock();
            $this->executionCount++;

            if ($this->repeatEvery) {
                $this->nextExecutionDate =
                    clone $this->getNextExecutionDate()->add($this->translateIntervalSpec($this->repeatEvery))
                ;

                $this->execute($service);
            } else {
                $this->status = self::STATUS_TERMINATED;
                $this->nextExecutionDate = null;
                return;
            }
        }
    }

    /**
     * Get nextExecutionDate
     *
     * @return \DateTime
     */
    public function getNextExecutionDate()
    {
        return $this->nextExecutionDate;
    }

    /**
     * @param string $intervalSpec Period (strtotime or ISO-8601)
     * @return Job
     */
    public function setScheduledIn($spec)
    {
        $this->checkUnlocked();
        $this->nextExecutionDate = (new \DateTime('now'))->add($this->translateIntervalSpec($spec));
        $this->firstExecutionDate = $this->nextExecutionDate;
        return $this;
    }

    /**
     *
     * @param \DateTime $date
     * @return Job
     * @throws \RuntimeException
     */
    public function setScheduledAt(\DateTime $date = null)
    {
        $this->checkUnlocked();
        if ($this->executionCount) {
            throw new \RuntimeException('Cannot reset execution date');
        }

        $this->nextExecutionDate = $date;
        $this->firstExecutionDate = $date;
        return $this;
    }

    /**
     * Set repeatEvery
     *
     * @param string $repeatEvery Period (strtotime or ISO-8601)
     * @return Job
     */
    public function setRepeatEvery($repeatEvery)
    {
        $this->translateIntervalSpec($repeatEvery);
        $this->repeatEvery = $repeatEvery;

        return $this;
    }

    /**
     * Get repeatEvery
     *
     * @return string
     */
    public function getRepeatEvery()
    {
        return $this->repeatEvery;
    }

    /**
     * @ORM\PrePersist
     */
    public function prePersist()
    {
        $this->insertionDate = new \DateTime('now');

        if (!$this->firstExecutionDate) {
            $this->firstExecutionDate = $this->insertionDate;
            $this->nextExecutionDate = $this->insertionDate;
        }
    }

    /**
     * Get insertionDate
     *
     * @return \DateTime
     */
    public function getInsertionDate()
    {
        return $this->insertionDate;
    }

    /**
     * Get lastExecutionDate
     *
     * @return \DateTime
     */
    public function getLastExecutionDate()
    {
        return $this->lastExecutionDate;
    }

    /**
     * @return int
     */
    public function getExecutionCount()
    {
        return $this->executionCount;
    }

    /**
     * End recurrence of the job
     *
     * @return Job
     */
    public function stopRepetition()
    {
        $this->repeatEvery = null;
        return $this;
    }

    /**
     * @param <JobTag|ArrayCollection|array|string> $tag
     */
    public function addTag($tag)
    {
        if (is_string($tag)) {
            $name = $tag;
            $tag = new JobTag();
            $tag->setName($name);
        } elseif (is_array($tag) || $tag instanceof ArrayCollection) {
            foreach($tag as $single) {
                $this->addTag($single);
            }
            return $this;
        }

        if ($tag instanceof JobTag) {
            foreach($this->tags as $defined) {
                if ($defined->getName() == $tag->getName()) {
                    return $this;
                }
            }
        } else {
            throw new \RuntimeException('$tag must be an array, a string, an ArrayCollection or an instance of JobTag');
        }

        $this->tags->add($tag);

        return $this;
    }

    /**
     * Shorthand method for addTag
     *
     * @param <JobTag|ArrayCollection|array|string> $tags
     * @return Job
     */
    public function tag($tags)
    {
        return $this->addTag($tags);
    }

    /**
     * @param object $entity
     * @return Job
     */
    public function addEntityTag($entity)
    {
        return $this->tag($this->getProxy()->getTagForEntity($entity));
    }

    /**
     * @return ArrayCollection
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * Translate interval specification to a \DateInterval
     * (either strtotime or ISO-8601)
     *
     * @param string $spec
     * @return \DateInterval
     */
    protected function translateIntervalSpec($spec)
    {
        if (strpos($spec, 'P') === 0) {
            return new \DateInterval($spec);
        } else {
            return \DateInterval::createFromDateString($spec);
        }
    }
}
