<?php

namespace Jerive\Bundle\SchedulerBundle\Admin;

use Sonata\AdminBundle\Admin\Admin;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Jerive\Bundle\SchedulerBundle\Entity\Job;
use Sonata\AdminBundle\Route\RouteCollection;

class JobAdmin extends Admin
{
    /**
     * Default Datagrid values
     *
     * @var array
     */
    protected $datagridValues = array(
        '_page' => 1,
        '_sort_order' => 'DESC',
        '_sort_by' => 'id'
    );

    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper
            ->add('name')
            ->add('serviceId')
            ->add('tags', null, array(), null, array(
                'multiple' => true,
            ))
            ->add('nextExecutionDate', 'doctrine_orm_date_range')
            ->add('status', 'doctrine_orm_choice', array(), 'choice',array(
                'choices' => array(
                    Job::STATUS_FAILED => 'Failed',
                    Job::STATUS_RUNNING => 'Running',
                    Job::STATUS_WAITING => 'Waiting',
                    Job::STATUS_TERMINATED => 'Ended',
                ),
            ))

        ;
    }

    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper
            ->addIdentifier('name')
            ->add('insertionDate')
            ->add('nextExecutionDate')
            ->add('executionCount')
            ->add('status')
            ->add('_action', 'actions', array(
                'actions' => array(
                    'execute' => array(
                        'template'  => 'JeriveSchedulerBundle:Sonata:list__action_execute.html.twig',
                    ),
                )
            ))
        ;
    }

    protected function configureRoutes(RouteCollection $collection)
    {
        $collection
            ->add('execute', $this->getRouterIdParameter().'/execute')
            ->remove('show')
            ->remove('create')
            ->remove('edit')
            ->remove('batch')
        ;
    }
}
