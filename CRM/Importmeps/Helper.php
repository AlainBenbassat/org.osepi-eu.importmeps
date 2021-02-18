<?php

class CRM_Importmeps_Helper {
  public function importOrg($tableName, $contactSubtype) {
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

  public function checkEmployer($contactId, $jobTitle, $currentEmployerId) {
    $params = [
      'id' => $contactId,
      'job_title' => $jobTitle,
      'employer_id' => $currentEmployerId,
      'sequential' => 1,
    ];
    $result = civicrm_api3('Contact', 'create', $params);
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

  /**
   * TODO Refactor so it's not dependend on tmp_ep_members!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
   */
  public function checkRelationships($contactId, $firstName, $lastName) {
    $config = new CRM_Importmeps_Config();

    $sql = "select * from tmp_ep_members where first_name = %1 and last_name = %2";
    $sqlParams = [
      1 => [$firstName, 'String'],
      2 => [$lastName, 'String'],
    ];

    $dao = CRM_Core_DAO::executeQuery($sql, $sqlParams);
    while ($dao->fetch()) {
      [$nameAB, $labelAB, $nameBA, $labelBA] = $config->generateRelName($dao->role);

      // check if the relationship exists
      $params = [
        'contact_id_a' => $contactId,
        'contact_id_b' => $this->getEpOrgId($dao->name, $dao->type),
        'relationship_type_id' => $config->createOrGetRelationshipType(['name_a_b' => $nameAB, 'name_b_a' => $nameBA])['id'],
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
      $params['source'] = 'DODS Bulk Import - new contact';
      return civicrm_api3('Contact', 'create', $params);
    }
    elseif ($result['count'] == 1) {
      // update the source
      CRM_Core_DAO::executeQuery("update civicrm_contact set source = 'DODS Bulk Import - updated contact' where source not like 'DODS Bulk Import%' and id = " . $result['values'][0]['id']);

      // return the existing contact
      return $result['values'][0];
    }
    else {
      // more than 1
      throw new Exception("Dedupe $firstName $lastName");
    }
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



}
