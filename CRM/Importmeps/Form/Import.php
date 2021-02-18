<?php

use CRM_Importmeps_ExtensionUtil as E;

class CRM_Importmeps_Form_Import extends CRM_Core_Form {

  public function buildQuickForm() {
    $tasks = $this->getTasks();
    $this->addRadio('import_tasks', 'Task', $tasks, [], '<br>', TRUE);

    $buttons = $this->getButtons();
    $this->addButtons($buttons);

    $this->assign('elementNames', $this->getRenderableElementNames());

    parent::buildQuickForm();
  }

  public function postProcess() {
    $task = $this->getSubmittedTask();
    if ($task == 'config') {
      $config = new CRM_Importmeps_Config();
      $config->create();
    }
    elseif ($task == 'import_ep_orgs') {
      $ep = new CRM_Importmeps_EuroParliament();
      $ep->importOrgs();
    }
    elseif ($task == 'import_ep_persons') {
      $ep = new CRM_Importmeps_EuroParliament();
      $ep->importPersons();
    }

    CRM_Core_Session::setStatus('Done', $task, 'status');
  }

  private function getSubmittedTask() {
    $values = $this->exportValues();
    return $values['import_tasks'];
  }

  private function getTasks() {
    $tasks = [
      'config' => 'Create config items',
      'import_ep_orgs' => 'Import EP Organizations',
      'import_ep_persons' => 'Import persons',
    ];

    return $tasks;
  }

  private function getButtons() {
    $buttons = [
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
    ];

    return $buttons;
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
