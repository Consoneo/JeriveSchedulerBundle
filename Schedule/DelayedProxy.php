<?php

namespace Jerive\Bundle\SchedulerBundle\Schedule;

use Doctrine\ORM\EntityManager;

/**
 * Description of DelayedProxy
 *
 * @author jerome
 */
class DelayedProxy implements \Serializable
{
    const PARAM_TYPE_STANDARD   = 0;

    const PARAM_TYPE_ENTITY     = 1;

    /**
     * @var array
     */
    protected $actions = array();

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var object
     */
    protected $service;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function serialize()
    {
        return serialize($this->actions);
    }

    public function unserialize($serialized)
    {
        $this->actions = unserialize($serialized);
    }

    public function reset()
    {
        $this->actions = array();
        return $this;
    }

    /**
     * @param ScheduledServiceInterface $service
     * @return DelayedProxy
     */
    public function setService(ScheduledServiceInterface $service)
    {
        $this->service = $service;
        return $this;
    }

    public function execute()
    {
        foreach($this->actions as $action) {
            list($function, $params) = $action;
            foreach($params as &$param) {
                list($type, $realparam) = $param;
                if ($type == self::PARAM_TYPE_ENTITY) {
                    list($id, $class) = $realparam;
                    $param = $this->em->find($class, $id);
                } else {
                    $param = $realparam;
                }
            }

            call_user_func_array(array($this->service, $function), $params);
        }
    }

    /**
     *
     * @param string $function
     * @param array $params
     * @return \Jerive\Bundle\SchedulerBundle\Schedule\DelayedProxy
     * @throws \RuntimeException
     */
    public function __call($function, $params)
    {
        if (!method_exists($this->service, $function) && !method_exists($this->service, '__call')) {
            throw new \RuntimeException(sprintf('Service %s does not support method "%s"',  get_class($this->service), $function));
        }

        foreach($params as &$param) {
            if (is_resource($param)) {
                throw new \RuntimeException('Can not store resources');
            }

            if (is_object($param) && $this->em->contains($param)) {
                $class    = get_class($param);
                $metadata = $this->em->getClassMetadata($class);
                $param    = array(self::PARAM_TYPE_ENTITY, array(
                    $metadata->getIdentifierValues($param),
                    $class
                ));
            } else {
                $param = array(self::PARAM_TYPE_STANDARD, $param);
            }
        }

        $this->actions[] = array($function, $params);

        return $this;
    }

    /**
     * @param object $entity
     * @return string
     */
    public function getTagForEntity($entity)
    {
        $entity     = $this->em->merge($entity);
        $metadata   = $this->em->getClassMetadata(get_class($entity));
        $reflection = $metadata->getReflectionClass();

        return $reflection->getName() . '_' . implode('_', $metadata->getIdentifierValues($entity));
    }
}
