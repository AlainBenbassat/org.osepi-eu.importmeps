<?php

class CRM_Importmeps_Config {
  public function create() {
    // European Parliament
    $this->createContactType('Organization', 'ep_delegation', 'EP Delegation');
    $this->createContactType('Organization', 'ep_committee', 'EP Committee'); // e.g. Committee on Agriculture - AGRI
    $this->createContactType('Organization', 'ep_group', 'EP Group');
    $this->createContactType('Organization', 'ep_intergroup', 'EP Intergroup');

    // European Commission
    $this->createContactType('Organization', 'ec_directorate_general', 'EC Directorate General'); // e.g. DG AGRI
    $this->createContactType('Organization', 'ec_cabinet_college', 'EC Cabinet / College'); // e.g. Cabinet of Elisa Ferreira - Cohesion and Reforms
    $this->createContactType('Organization', 'ec_service', 'EC Service'); //e.g. EEAS

    // PermReps
    $this->createContactType('Organization', 'perm_rep', 'Perm Rep');

    $this->createCountryGroups();
    $this->createRelationshipTypes('tmp_ep_roles');
    $this->createRelationshipTypes('tmp_ec_roles');
  }

  private function createContactType($baseContact, $name, $label) {
    try {
      $ret = civicrm_api3('ContactType', 'getsingle', [
        'name' => $name,
      ]);
    }
    catch (Exception $e) {
      if ($baseContact == 'Organization') {
        $parentId = 3;
      }
      else {
        $parentId = 1;
      }

      $ret = civicrm_api3('ContactType', 'create', [
        'name' => $name,
        'label' => $label,
        'is_active' => 1,
        'parent_id' => $parentId,
      ]);
    }

    return $ret;
  }

  private function createCountryGroups() {
    $helper = new CRM_Importmeps_Helper();
    $parentId = $helper->createOrGetGroup('Countries of Representation', 0)['id'];
    $sql = "select distinct  country_of_representation FROM tmp_ep_persons order by 1";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $helper->createOrGetGroup($dao->country_of_representation, $parentId);
    }
  }

  private function createRelationshipTypes($table) {
    $sql = "select role from $table order by 1";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      list($nameAB, $labelAB, $nameBA, $labelBA) = $this->generateRelName($dao->role);
      $params = [
        'name_a_b' => $nameAB,
        'label_a_b' => $labelAB,
        'name_b_a' => $nameBA,
        'label_b_a' => $labelBA,
        'contact_type_a' => 'Individual',
        'contact_type_b' => 'Organization',
        'is_reserved' => '0',
        'is_active' => '1'
      ];
      $this->createOrGetRelationshipType($params);
    }
  }

  public function createOrGetRelationshipType($params) {
    try {
      $relType = civicrm_api3('RelationshipType', 'getsingle', [
        'name_a_b' => $params['name_a_b'],
        'name_b_a' => $params['name_b_a'],
      ]);
    }
    catch (Exception $e) {
      $relType = civicrm_api3('RelationshipType', 'create', $params);
    }

    return $relType;
  }

  public function generateRelName($role) {
    $labelAB = 'is ' . $role . ' of';
    $nameAB = strtolower(CRM_Utils_String::munge($labelAB));
    $labelBA = 'has as ' . $role;
    $nameBA = strtolower(CRM_Utils_String::munge($labelBA));
    return [$nameAB, $labelAB, $nameBA, $labelBA];
  }
}
