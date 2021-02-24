<?php

class CRM_Importmeps_EuroCommission {
  public function importOrgs() {
    $helper = new CRM_Importmeps_Helper();

    $helper->importOrg('tmp_ec_dgs', 'ec_directorate_general');
    $helper->importOrg('tmp_ec_cabinets', 'ec_cabinet_college');
  }

  public function importEcPersons($table) {
    $queue = new CRM_Importmeps_Queue($table);

    // put items in the queue
    $sql = "select id from $table";
    $dao = CRM_Core_DAO::executeQuery($sql);
    $class = 'CRM_Importmeps_EuroCommission';
    $method = 'importEcPersonTask';
    while ($dao->fetch()) {
      $queue->addTask($class, $method, [$table, $dao->id]);
    }

    $queue->run();
  }

  public static function importEcPersonTask(CRM_Queue_TaskContext $ctx, $table, $id) {
    $helper = new CRM_Importmeps_Helper();

    try {
      // get the contact
      $sql = "select * from $table where id = $id";
      $dao = CRM_Core_DAO::executeQuery($sql);
      $dao->fetch();

      // does the contact exist?
      $contact = $helper->createOrGetPerson($dao->prefix, $dao->first_name, $dao->last_name, $dao->email);

      // check additional stuff
      $helper->checkEmployer($contact['id'], $dao->job_title, $dao->employer_id);
      $helper->checkEmail($contact['id'], $dao->email);
      $helper->checkPhone($contact['id'], $dao->phone);

      if (property_exists($dao, 'osepi_department')) {
        $helper->checkOsepiDepartment($contact['id'], $dao->osepi_department);
      }

      if (property_exists($dao, 'role')) {
        $helper->checkRelationships($table, $contact['id'], $dao->first_name, $dao->last_name);
      }

      if (property_exists($dao, 'street_address')) {
        $helper->checkWorkAddress($contact['id'], $dao->street_address, $dao->supplemental_address1, $dao->postal_code, $dao->city, $dao->country);
      }

      if (property_exists($dao, 'tags_1')) {
        $helper->checkTags($contact['id'], $dao);
      }
    }
    catch (Exception $e) {
      watchdog('importEcPersonTask', $e->getMessage());
    }

    return TRUE;
  }
}
