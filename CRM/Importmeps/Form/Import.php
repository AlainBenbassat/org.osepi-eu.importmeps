<?php

use CRM_Importmeps_ExtensionUtil as E;

class CRM_Importmeps_Form_Import extends CRM_Core_Form {
  private $queue;
  private $queueName = 'osepimeps';

  public function __construct() {
    // create the queue
    $this->queue = CRM_Queue_Service::singleton()->create([
      'type' => 'Sql',
      'name' => $this->queueName,
      'reset' => TRUE, // flush queue upon creation
    ]);

    parent::__construct();
  }

  public function buildQuickForm() {
    $tasks = [
      'config' => 'Create config items',
      'check_persons' => 'Check if persons exists',
      'import_persons' => 'Import persons',
      'import_rels' => 'Import relationships',
    ];
    $this->addRadio('import_tasks', 'Task', $tasks, [], '<br>', TRUE);

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $helper = new CRM_Importmeps_Helper();

    $values = $this->exportValues();
    if ($values['import_tasks'] == 'config') {
      $helper->createConfig();
    }
    elseif ($values['import_tasks'] == 'check_persons') {
      $txt = $helper->analyze();
      CRM_Core_Session::setStatus($txt, '', 'no-popup');
    }
    elseif ($values['import_tasks'] == 'import_persons') {
      // put items in the queue
      $sql = "select contact_id from tmp_persons order by last_name";
      $dao = CRM_Core_DAO::executeQuery($sql);
      $class = 'CRM_Importmeps_Helper';
      $method = 'importPersons';
      while ($dao->fetch()) {
        $task = new CRM_Queue_Task([$class, $method], [$dao->contact_id]);
        $this->queue->createItem($task);
      }

      // run the queue
      $runner = new CRM_Queue_Runner([
        'title' => 'Import MEPs',
        'queue' => $this->queue,
        'errorMode'=> CRM_Queue_Runner::ERROR_CONTINUE,
        'onEndUrl' => CRM_Utils_System::url('civicrm/importmeps', 'reset=1'),
      ]);
      $runner->runAllViaWeb();
    }

    CRM_Core_Session::setStatus('Done', '', 'status');
  }


  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
