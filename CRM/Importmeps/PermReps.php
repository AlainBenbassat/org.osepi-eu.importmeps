<?php

class CRM_Importmeps_PermReps {
  public function importOrgs() {
    $helper = new CRM_Importmeps_Helper();

    $helper->importOrgNoStrictSubtype('tmp_permreps', 'perm_rep');
  }

  public function importPersons() {
    $table = 'tmp_permreps_persons';
    $queue = new CRM_Importmeps_Queue($table);

    // put items in the queue
    $sql = "select id from $table";
    $dao = CRM_Core_DAO::executeQuery($sql);
    $class = 'CRM_Importmeps_PermReps';
    $method = 'importPersonTask';
    while ($dao->fetch()) {
      $queue->addTask($class, $method, [$table, $dao->id]);
    }

    $queue->run();
  }

  public static function importPersonTask(CRM_Queue_TaskContext $ctx, $table, $id) {
    $helper = new CRM_Importmeps_Helper();

    try {
      // get the contact
      $sql = "select * from $table where id = $id";
      $dao = CRM_Core_DAO::executeQuery($sql);
      $dao->fetch();

      // does the contact exist?
      $contact = $helper->createOrGetPerson($dao->prefix, $dao->first_name, $dao->last_name);

      // check additional stuff
      $helper->checkEmployer($contact['id'], $dao->job_title, $dao->employer_id);
      $helper->checkEmail($contact['id'], $dao->email);
      $helper->checkPhone($contact['id'], $dao->phone);
      $helper->checkOsepiDepartment($contact['id'], $dao->osepi_department);
      $helper->checkOsepiDepartment2($contact['id'], $dao->osepi_department2);
      $helper->checkTags($contact['id'], $dao);
    }
    catch (Exception $e) {
      watchdog('importEcPersonTask', $e->getMessage());
    }

    return TRUE;
  }


}
