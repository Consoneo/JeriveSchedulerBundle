<?php

namespace Jerive\Bundle\SchedulerBundle\Controller;

use Jerive\Bundle\SchedulerBundle\Entity\Job;
use Sonata\AdminBundle\Controller\CRUDController as Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CRUDJobController extends Controller
{
	public function executeAction()
	{
		$id = $this->get('request')->get($this->admin->getIdParameter());

		/** @var $object Job */
		$object = $this->admin->getObject($id);

		if (!$object) {
			throw new NotFoundHttpException(sprintf('unable to find the object with id : %s', $id));
		}

		$object->prepareForExecution();
		$this->container->get('doctrine')->getManager()->persist($object);
		$this->container->get('doctrine')->getManager()->flush($object);

		try {
			$object->getProxy()->setDoctrine($this->container->get('doctrine'));
			$object->execute($this->container->get($object->getServiceId()));
			$message = sprintf('SUCCESS [%s] in job [%s]#%s', $object->getServiceId(), $object->getName(), $object->getId());
			$type = 'sonata_flash_success';
		} catch (\Exception $e) {
			$message = sprintf('FAILURE [%s] in job [%s]#%s - message : %s', $object->getServiceId(), $object->getName(), $object->getId(), $e->getMessage());
			$type = 'sonata_flash_error';
		}

		$this->container->get('doctrine')->getManager()->persist($object);
		$this->container->get('doctrine')->getManager()->flush($object);

		$this->addFlash($type, $message);
		return new RedirectResponse($this->admin->generateUrl('list'));
	}
}