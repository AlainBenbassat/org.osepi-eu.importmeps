<?php

class CRM_Importmeps_EuroParliament {

  public function importOrgs() {
    $helper = new CRM_Importmeps_Helper();

    $helper->importOrg('tmp_ep_delegations', 'ep_delegation');
    $helper->importOrg('tmp_ep_committees', 'ep_committee');
    $helper->importOrg('tmp_ep_groups', 'ep_group');
    $helper->importOrg('tmp_ep_intergroups', 'ep_intergroup');
  }

  public function importPersons() {
    $queue = new CRM_Importmeps_Queue('osepi_ep_persons');

    // put items in the queue
    $sql = "select contact_id from tmp_ep_persons order by last_name";
    $dao = CRM_Core_DAO::executeQuery($sql);
    $class = 'CRM_Importmeps_EuroParliament';
    $method = 'importPersonTask';
    while ($dao->fetch()) {
      $queue->addTask($class, $method, [$dao->contact_id]);
    }

    $queue->run();
  }

  public static function importPersonTask(CRM_Queue_TaskContext $ctx, $id) {
    $helper = new CRM_Importmeps_Helper();

    try {
      // get the contact
      $sql = "select * from tmp_ep_persons where contact_id = $id";
      $dao = CRM_Core_DAO::executeQuery($sql);
      $dao->fetch();

      // does the contact exist?
      $contact = $helper->createOrGetPerson($dao->prefix, $dao->first_name, $dao->last_name, $dao->email);

      // check additional stuff
      $helper->checkEmployer($contact['id'], 'MEP', 414);
      $helper->checkEmail($contact['id'], $dao->email);
      $helper->checkPhone($contact['id'], $dao->phone);
      $helper->checkWorkAddress($contact['id'], $dao->street_address, $dao->supplemental_address1, $dao->postal_code, $dao->city, 'Belgium');
      $helper->checkCountryOfRepresentation($contact['id'], $dao->country_of_representation);
      $helper->checkTwitter($contact['id'], $dao->twitter);

      // add relationships
      $helper->checkRelationships('tmp_ep_members', $contact['id'], $dao->first_name, $dao->last_name);
    }
    catch (Exception $e) {
      watchdog('import MEPs', $e->getMessage());
    }

    return TRUE;
  }

}

