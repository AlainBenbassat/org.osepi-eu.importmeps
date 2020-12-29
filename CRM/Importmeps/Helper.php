<?php

class CRM_Importmeps_Helper {
  public function createConfig() {
    $this->createContactType('Organization', 'ep_delegation', 'EP Delegation');
    $this->createContactType('Organization', 'ep_committee', 'EP Committee');
    $this->createContactType('Organization', 'ep_group', 'EP Group');
    $this->createContactType('Organization', 'ep_intergroup', 'EP Intergroup');

    $this->createCountryGroups();
    $this->createRelationships();

    $this->importEpOrg('tmp_ep_delegations', 'ep_delegation');
    $this->importEpOrg('tmp_ep_committees', 'ep_committee');
    $this->importEpOrg('tmp_ep_groups', 'ep_group');
    $this->importEpOrg('tmp_ep_intergroups', 'ep_intergroup');
  }

  public function importEpOrg($tableName, $contactSubtype) {
    $sql = "select * from $tableName";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      // check if the contact exists
      $params = [
        'contact_type' => 'Organization',
        'contact_sub_type' => $contactSubtype,
        'organization_name' => $dao->name,
        'nick_name' => $dao->abbr,
        'sequential' => 1,
      ];
      $result = civicrm_api3('Contact', 'get', $params);
      if ($result['count'] == 0) {
        // create it
        civicrm_api3('Contact', 'create', $params);
      }
    }
  }

  public function analyze() {
    $txt = [];

    $sql = "select * from tmp_persons";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $sqlPerson = "select count(id) from civicrm_contact where is_deleted = 0 and first_name = %1 and last_name = %2";
      $sqlPersonParams = [
        1 => [$dao->first_name, 'String'],
        2 => [$dao->last_name, 'String'],
      ];
      $n = CRM_Core_DAO::singleValueQuery($sqlPerson, $sqlPersonParams);
      if ($n > 0) {
        $txt[] = $n . ': ' . $dao->first_name . ' ' . $dao->last_name . ($n > 1 ? ' - DEDUPE THIS CONTACT!!!' : '');
      }
    }

    sort($txt);
    return implode('<br>', $txt);
  }

  public static function importPersons(CRM_Queue_TaskContext $ctx, $id) {
    $helper = new CRM_Importmeps_Helper();

    try {
      // get the contact
      $sql = "select * from tmp_persons where contact_id = $id";
      $dao = CRM_Core_DAO::executeQuery($sql);
      $dao->fetch();

      // does the contact exist?
      $contact = $helper->createOrGetPerson($dao->prefix, $dao->first_name, $dao->last_name);

      // check additional stuff
      $helper->checkEmail($contact['id'], $dao->email);
      $helper->checkPhone($contact['id'], $dao->phone);
      $helper->checkWorkAddress($contact['id'], $dao->street_address, $dao->supplemental_address1, $dao->postal_code, $dao->city);
      $helper->checkCountryOfRepresentation($contact['id'], $dao->country_of_representation);
      $helper->checkTwitter($contact['id'], $dao->twitter);

      // add relationships
      $helper->checkRelationships($contact['id'], $dao->first_name, $dao->last_name);
    }
    catch (Exception $e) {
      watchdog('import MEPs', $e->getMessage());
    }

    return TRUE;
  }

  public function checkCountryOfRepresentation($contactId, $countryOfRepresentation) {
    if ($countryOfRepresentation) {
      // get group
      $group = $this->createOrGetGroup($countryOfRepresentation, 0);

      // add contact to group
      civicrm_api3('GroupContact', 'create', [
        'contact_id' => $contactId,
        'group_id' => $group['id'],
      ]);
    }
  }

  public function checkEmail($contactId, $email) {
    if ($email) {
      $params = [
        'contact_id' => $contactId,
        'email' => $email,
      ];
      $result = civicrm_api3('Email', 'get', $params);
      if ($result['count'] == 0) {
        $params['is_primary'] = 1;
        civicrm_api3('Email', 'create', $params);
      }
    }
  }

  public function checkTwitter($contactId, $twitter) {
    if ($twitter) {
      $params = [
        'contact_id' => $contactId,
        'website_type_id' => 4, // twitter
      ];
      $result = civicrm_api3('Website', 'get', $params);
      if ($result['count'] == 0) {
        $params['url'] = $twitter;
        civicrm_api3('Website', 'create', $params);
      }
    }
  }

  public function checkPhone($contactId, $phone) {
    if ($phone) {
      $params = [
        'contact_id' => $contactId,
        'phone' => $phone,
      ];
      $result = civicrm_api3('Phone', 'get', $params);
      if ($result['count'] == 0) {
        $params['is_primary'] = 1;
        $params['location_type_id'] = 3; // main
        $params['phone_type_id'] = 1;
        civicrm_api3('Phone', 'create', $params);
      }
    }
  }

  public function checkWorkAddress($contactId, $streetAddress, $supplementalAddress1, $postalCode, $city) {
    if ($streetAddress) {
      $params = [
        'contact_id' => $contactId,
        'location_type_id' => 3, // main
        'sequential' => 1,
      ];
      $result = civicrm_api3('Address', 'get', $params);
      if ($result['count'] == 0 || $result['values'][0]['street_address'] == '') {
        if ($result['count'] > 0) {
          $params['id'] = $result['values'][0]['id'];
        }
        $params['is_primary'] = 1;
        $params['street_address'] = $streetAddress;
        $params['supplemental_address_1'] = $supplementalAddress1;
        $params['postal_code'] = $postalCode;
        $params['city'] = $city;
        $params['country_id'] = 1020;
        civicrm_api3('Address', 'create', $params);
      }
    }
  }

  public function checkRelationships($contactId, $firstName, $lastName) {
    $sql = "select * from tmp_ep_members where first_name = %1 and last_name = %2";
    $sqlParams = [
      1 => [$firstName, 'String'],
      2 => [$lastName, 'String'],
    ];

    $dao = CRM_Core_DAO::executeQuery($sql, $sqlParams);
    while ($dao->fetch()) {
      list($nameAB, $labelAB, $nameBA, $labelBA) = $this->generateRelName($dao->role);

      // check if the relationship exists
      $params = [
        'contact_id_a' => $contactId,
        'contact_id_b' => $this->getEpOrgId($dao->name, $dao->type),
        'relationship_type_id' => $this->createOrGetRelationshipType(['name_a_b' => $nameAB, 'name_b_a' => $nameBA])['id'],
        'is_active' => 1,
      ];
      $result = civicrm_api3('Relationship', 'get', $params);
      if ($result['count'] == 0) {
        civicrm_api3('Relationship', 'create', $params);
      }
    }
  }

  public function getEpOrgId($name, $type) {
    $sql = "select id from civicrm_contact where organization_name = %1 and contact_sub_type like %2";
    $sqlParams = [
      1 => [$name, 'String'],
      2 => ['%ep_' . $type . '%', 'String'],
    ];
    return CRM_Core_DAO::singleValueQuery($sql, $sqlParams);
  }

  public function createOrGetPerson($prefix, $firstName, $lastName) {
    $params = [
      'first_name' => $firstName,
      'last_name' => $lastName,
      'contact_type' => 'Individual',
      'sequential' => 1,
    ];
    $result = civicrm_api3('Contact', 'get', $params);
    if ($result['count'] == 0) {
      // create the contact
      $params['source'] = 'IMPORT December 2020';
      $params['job_title'] = 'MEP';
      $params['employer_id'] = 414;
      return civicrm_api3('Contact', 'create', $params);
    }
    elseif ($result['count'] == 1) {
      // return the existing contact
      return $result['values'][0];
    }
    else {
      // more than 1
      throw new Exception("Dedupe $firstName $lastName");
    }
  }

  public function createCountryGroups() {
    $parentId = $this->createOrGetGroup('Countries of Representation', 0)['id'];
    $sql = "select distinct  country_of_representation FROM tmp_persons order by 1";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $this->createOrGetGroup($dao->country_of_representation, $parentId);
    }
  }

  public function createRelationships() {
    $sql = "select role from tmp_ep_roles order by 1";
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

  public function generateRelName($role) {
    $labelAB = 'is ' . $role . ' of';
    $nameAB = strtolower(CRM_Utils_String::munge($labelAB));
    $labelBA = 'has as ' . $role;
    $nameBA = strtolower(CRM_Utils_String::munge($labelBA));
    return [$nameAB, $labelAB, $nameBA, $labelBA];
  }

  public function createContactType($baseContact, $name, $label) {
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

  public function createOrGetGroup($title, $parentId) {
    try {
      $ret = civicrm_api3('Group', 'getsingle', [
        'title' => $title,
      ]);
    }
    catch (Exception $e) {
      $ret = civicrm_api3('Group', 'create', [
        'title' => $title,
        'is_active' => 1,
        'parents' => ($parentId > 0) ? $parentId : '',
      ]);
    }

    return $ret;
  }

  private function createOrGetRelationshipType($params) {
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

}
