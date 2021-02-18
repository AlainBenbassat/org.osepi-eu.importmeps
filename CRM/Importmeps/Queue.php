<?php

class CRM_Importmeps_Queue {
  private $queue;

  public function __construct($name) {
    $this->queue = CRM_Queue_Service::singleton()->create([
      'type' => 'Sql',
      'name' => $name,
      'reset' => TRUE, // flush queue upon creation
    ]);
  }

  public function addTask($class, $method, $data) {
    $task = new CRM_Queue_Task([$class, $method], $data);
    $this->queue->createItem($task);
  }

  public function run() {
    $runner = new CRM_Queue_Runner([
      'title' => 'Import MEPs',
      'queue' => $this->queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_CONTINUE,
      'onEndUrl' => CRM_Utils_System::url('civicrm/importmeps', 'reset=1'),
    ]);
    $runner->runAllViaWeb();
  }

}
