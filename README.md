README
======

What is SchedulerBundle ?
-------------------------

It allows to schedule and execute jobs.

A job is the combination of:
  * a service ID
  * a list of methods applications along with its parameters, that can be
    either scalars or Doctrine entities. Doctrine entities are serialized
    as a class/ID pair, so as to be found later.

Features:
  * Remote programming
  * Logging
  * Repetition management
  * Tagging management
  * Fault tolerant

``` php
<?php
$scheduler = $this->container->get('jerive_scheduler.scheduler');
$myJob     = $scheduler->createJob('my_company.my_scheduled_service');
$myJob
    ->setScheduledIn('+2 days')  // ->setScheduledAt((new \DateTime('now'))->modify('+2 days'))
    ->tag('my.first.job')
    ->program()
        // Any method call and parameters will be recorded
        ->myMethod1(true)
        ->myMethod2(array(1, 2))
        ->sendReminderIfHasNotConfirmed($user)
;

$scheduler->schedule($myJob);
```
