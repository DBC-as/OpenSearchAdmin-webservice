<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright � 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


/**
 * Objects are updated via the ES-database whereas relations are updated directly in Fedora
 */

require_once("OLS_class_lib/webServiceServer_class.php");
require_once("OLS_class_lib/oci_class.php");

define("PID_NAMESPACE", "http://biblstandard.dk/ac/namespace/");
define("TING_NAMESPACE", "http://www.dbc.dk/ting");

class openSearchAdmin extends webServiceServer {

  protected $curl;


  public function __construct(){
    webServiceServer::__construct('opensearchadmin.ini');

    if (!$timeout = $this->config->get_value("timeout", "setup"))
      $timeout = 10;
    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, $timeout);
  }



 /** \brief 
  *
  * errorType:
  * - service_unavailable
  * - error_in_request
  * - operation_not_allowed_on_object
  * - relation_cannot_be_created
  * - relation_cannot_be_deleted
  */

 /** \brief createObject - Create object request. For creation of new data in the object respository.
  *
  *
  * Request:
  * - localIdentifier
  * - record
  * - theme
  * - - themeIdentifier
  * - - themeName
  * 
  * Response:
  * - status - values: object_created
  * - objectIdentifier
  */
  public function createObject($param) {
    $cor = &$ret->createObjectResponse->_value;
    if (!$this->is_local_identifier($param->localIdentifier->_value))
      $cor->error->_value = "error_in_local_identifier";
    else {
// ting container
      $ting->container->_value->record = &$param->record;
      $ting->container->_namespace = TING_NAMESPACE;
      if ($this->validate["dkabm"]) {
        $xml = $this->objconvert->obj2xmlNS($ting->container->_value);
        if (!$this->validate_xml($xml, $this->validate["dkabm"]))
          $err = "error_validating_record";
      }
// make/change ac-identifier to new one
      if (empty($err)) {
        $this->set_record_identifier(&$param->record->_value->identifier, $param->localIdentifier->_value);
        $xml = $this->objconvert->obj2xmlNS($ting);
        $control_xml = html_entity_decode(sprintf($this->config->get_value("xml_control","setup"), $this->get_agency($param->localIdentifier->_value), 'dan', 'katalog'));
      } 
      if ( $err || ($err = $this->ship_to_ES($xml, $control_xml, $this->config->get_value("es_update", "setup"))))
        $cor->error->_value = $err;
      else {
        $cor->status->_value = "object_created";
        $cor->objectIdentifier->_value = $param->localIdentifier->_value;
      }
    }
    //var_dump($param); die();
    return $ret;
  }


 /** \brief copyObject - Copy object request. 
  * Creates a copy of the object in the object repository and adds it to submitters collection
  *
  * Request:
  * - localIdentifier
  * - objectIdentifier
  * 
  * Response:
  * - status - values: object_copied
  * - status
  * - objectIdentifier
  *
  */
  public function copyObject($param) {
    $cor = &$ret->copyObjectResponse->_value;
    if (!$this->is_local_identifier($param->localIdentifier->_value))
      $cor->error->_value = "error_in_local_identifier";
    elseif (!$this->is_identifier($param->objectIdentifier->_value))
      $cor->error->_value = "error_in_object_identifier";
    elseif ($copyobj = $this->object_get($param->objectIdentifier->_value)) {
      $xmlobj = $this->xmlconvert->soap2obj($copyobj);
      $record = &$xmlobj->container->_value->record;
      if (empty($record)) 
        $cor->error->_value = "no_record_in_object";
      elseif (empty($record->_value->identifier)) 
        $cor->error->_value = "no_identifier_in_object_record";
      else {
        $this->set_record_identifier(&$record->_value->identifier, $param->localIdentifier->_value);
        $ting->container->_value->record = &$record;
        $ting->container->_namespace = TING_NAMESPACE;
        $xml = $this->objconvert->obj2xmlNS($ting);
        $control_xml = html_entity_decode(sprintf($this->config->get_value("xml_control","setup"), $this->get_agency($param->localIdentifier->_value), 'dan', 'katalog'));
        if ($err = $this->ship_to_ES($xml, $control_xml, $this->config->get_value("es_update", "setup")))
          $cor->error->_value = $err;
        else {
          $cor->status->_value = "object_copied";
          $cor->objectIdentifier->_value = $param->localIdentifier->_value;
        }
      }
    } else
      $cor->error->_value = "error_fetching_object_record";
    //var_dump($ret); var_dump($param); die();
    return $ret;
  }


 /** \brief updateObject - Update object request. For updating data in the object respository. 
  * Creates a copy of the object in the object repository with local changes, and adds it to 
  * submitters collection.
  *
  * Request:
  * - localIdentifier
  * - objectIdentifier
  * - record
  * - theme
  * - - themeIdentifier
  * - - themeName
  * 
  * Response:
  * - status - values: object_updated
  * - objectIdentifier
  *
  */
  public function updateObject($param) {
    $uor = &$ret->updateObjectResponse->_value;
    if (!$this->is_local_identifier($param->localIdentifier->_value))
      $uor->error->_value = "error_in_local_identifier";
    else {
// ting container
      $ting->container->_value->record = &$param->record;
      $ting->container->_namespace = TING_NAMESPACE;
      if ($this->validate["dkabm"]) {
        $xml = $this->objconvert->obj2xmlNS($ting->container->_value);
        if (!$this->validate_xml($xml, $this->validate["dkabm"]))
          $err = "error_validating_record";
      }
// make/change ac-identifier to new one
      if (empty($err)) {
        $this->set_record_identifier(&$param->record->_value->identifier, $param->localIdentifier->_value);
        $xml = $this->objconvert->obj2xmlNS($ting);
        $control_xml = html_entity_decode(sprintf($this->config->get_value("xml_control","setup"), $this->get_agency($param->localIdentifier->_value), 'dan', 'katalog'));
      } 
      if ( $err || ($err = $this->ship_to_ES($xml, $control_xml, $this->config->get_value("es_update", "setup"))))
        $uor->error->_value = $err;
      else {
        $uor->status->_value = "object_updated";
        $uor->objectIdentifier->_value = $param->localIdentifier->_value;
      }
    }
    //var_dump($param); die();
    return $ret;
  }



 /** \brief deleteObject - Delete object request
  *
  * Request:
  * - objectIdentifier
  * 
  * Response:
  * - status - values: object_deleted
  *
  */
  public function deleteObject($param) {
    $dor = &$ret->deleteObjectResponse->_value;
    if (!$this->is_identifier($param->objectIdentifier->_value))
      $cor->error->_value = "error_in_object_identifier";
    elseif ($copyobj = $this->object_get($param->objectIdentifier->_value)) {
      $xmlobj = $this->xmlconvert->soap2obj($copyobj);
      $record = &$xmlobj->container->_value->record;
      if (empty($record)) 
        $cor->error->_value = "no_record_in_object";
      elseif (empty($record->_value->identifier)) 
        $cor->error->_value = "no_identifier_in_object_record";
      else {
        $this->set_record_identifier(&$record->_value->identifier, $param->localIdentifier->_value);
        $ting->container->_value->record = &$record;
        $ting->container->_namespace = TING_NAMESPACE;
        $xml = $this->objconvert->obj2xmlNS($ting);
        $control_xml = html_entity_decode(sprintf($this->config->get_value("xml_control","setup"), $this->get_agency($param->localIdentifier->_value), 'dan', 'katalog'));
        if ($err = $this->ship_to_ES($xml, $control_xml, $this->config->get_value("es_delete", "setup")))
          $cor->error->_value = $err;
        else
          $cor->status->_value = "object_deleted";
      }
    } else
      $cor->error->_value = "error_fetching_object_record";
    //var_dump($ret); var_dump($param); die();
    return $ret;
  }



 /** \brief createRelation
  *
  * Fedoraparms:
  * - String pid The PID of the object. 
  * - String relationship The predicate. 
  * - String object The object (target). 
  * - boolean isLiteral A boolean value indicating whether the object is a literal. Set to true
  * - String datatype The datatype of the literal. Optional. Set to null
  *
  * Request:
  * - relationSubject
  * - relation - values: rev:hasReview 
  *                      fedora:isMemberOfCollection 
  *                      oss:isMemberOfTheme 
  *                      oss:hasCover 
  *                      isMemberOfWork
  * - relationObject
  * 
  * Response:
  * - status - values: relation_created
  *
  */
  public function createRelation($param) {
    $crr = &$ret->createRelationResponse->_value;
    if (($from = $param->relationSubject->_value) &&
        ($rel = $param->relation->_value) &&
        ($to = $param->relationObject->_value)) {
      $valid_relation = $this->config->get_value("relation", "setup");
      if (!$this->object_exists($from))
        $crr->error->_value = "unknown_relationSubject";
      elseif (!$this->object_exists($to))
        $crr->error->_value = "unknown_relationObject";
      elseif (!in_array($rel, $valid_relation["create"]))
        $crr->error->_value = "unknown_relation";
      else {
        $fed_req = sprintf($this->config->get_value("xml_create_relation"), $from, $rel, $to);
        $this->curl->set_soap_action('createRelation');
        $this->curl->set_post_xml(html_entity_decode($fed_req));
        $this->curl->set_authentication($this->config->get_value("fedora_user"), 
                                        $this->config->get_value("fedora_passwd"));
        $result = $this->curl->get($this->config->get_value("fedora_API_M", "setup"));
				if ($this->curl->get_status('http_code') >= 300)
          $crr->error->_value = "error_reaching_fedora";
        else {
          $dom = new DomDocument();
          $dom->preserveWhiteSpace = false;
          if (!$dom->loadXML($result)) 
            $crr->error->_value = "error_parsing_fedora_result";
          else {
            if ($dom->getElementsByTagName("added")->item(0)->nodeValue == "true")
              $crr->status->_value = "relation_created";
            else
              $crr->error->_value = "relation_cannot_be_created";
          }
        }
//var_dump($result);
//var_dump($this->curl->get_status());
//var_dump(html_entity_decode($fed_req));
//var_dump($this->curl->get_status());
      }
    } else 
      $crr->error->_value = "error_in_request";
//var_dump($param);
//var_dump($ret); die();
    return $ret;
  }



 /** \brief deleteRelation
  *
  * Fedoraparms:
  * - String pid The PID of the object. 
  * - String relationship The predicate. 
  * - String object The object (target). 
  * - boolean isLiteral A boolean value indicating whether the object is a literal. Set to true
  * - String datatype The datatype of the literal. Optional. Set to null
  *
  * Request:
  * - relationSubject
  * - relation - values: see createRelation above
  * - relationObject
  * 
  * Response:
  * - status - values: relation_deleted
  *
  */
  public function deleteRelation($param) {
    $drr = &$ret->deleteRelationResponse->_value;
    if (($from = $param->relationSubject->_value) &&
        ($rel = $param->relation->_value) &&
        ($to = $param->relationObject->_value)) {
      $valid_relation = $this->config->get_value("relation", "setup");
      if (!$this->object_exists($from))
        $drr->error->_value = "unknown_relationSubject";
      elseif (!$this->object_exists($to))
        $drr->error->_value = "unknown_relationObject";
      elseif (!in_array($rel, $valid_relation["create"]))
        $drr->error->_value = "unknown_relation";
      elseif (!$this->relation_exists($from, $to, $rel))
        $drr->error->_value = "relation_not_found";
      else {
        $fed_req = sprintf($this->config->get_value("xml_delete_relation"), $from, $rel, $to);
        $this->curl->set_soap_action('purgeRelation');
        $this->curl->set_post_xml(html_entity_decode($fed_req));
        $this->curl->set_authentication($this->config->get_value("fedora_user"), 
                                        $this->config->get_value("fedora_passwd"));
        $result = $this->curl->get($this->config->get_value("fedora_API_M", "setup"));
				if ($this->curl->get_status('http_code') >= 300)
          $drr->error->_value = "error_reaching_fedora";
        else {
          $dom = new DomDocument();
          $dom->preserveWhiteSpace = false;
          if (!$dom->loadXML($result)) 
            $drr->error->_value = "error_parsing_fedora_result";
          else {
            if ($dom->getElementsByTagName("purged")->item(0)->nodeValue == "true")
              $drr->status->_value = "relation_deleted";
            else
              $drr->error->_value = "relation_cannot_be_deleted";
          }
        }
//var_dump($result);
//var_dump($this->curl->get_status());
//var_dump(html_entity_decode($fed_req));
//var_dump($this->curl->get_status());
      }
    } else 
      $drr->error->_value = "error_in_request";
//var_dump($param);
//var_dump($ret); die();
    return $ret;
  }



  /**************** private functions ****************/


 /** \brief create taskpackage in ES
  */
  private function ship_to_ES(&$rec, &$rec_control, $es_action) {
    $oci = new Oci($this->config->get_value("es_credentials","setup"));
    $oci->set_charset("UTF8");
    $oci->connect();
    if ($err = $oci->get_error_string()) {
      $this->verbose->log(FATAL, "OpenSearchAdmin:: OCI connect error: " . $err);
      return "error_reaching_es_database";
    } else {
      $oci->set_query("SELECT taskpackageRefSeq.nextval FROM dual");
      $val = $oci->fetch_into_assoc();
      if ($tgt_ref = $val["NEXTVAL"]) {
        $pck_type = 5;
        $pck_name = $tgt_ref;
        $userid = $this->config->get_value("es_userid", "setup");
        $creator = $this->config->get_value("es_creator", "setup");
        $oci->bind("bind_pck_type", &$pck_type);
        $oci->bind("bind_pck_name", &$pck_name);
        $oci->bind("bind_tgt_ref", &$tgt_ref);
        $oci->bind("bind_userid", &$userid);
        $oci->bind("bind_creator", &$creator);
        $oci->set_query("INSERT INTO taskpackage 
                           (packagetype, packageName, userid, targetReference, creator)
                         VALUES (:bind_pck_type, :bind_pck_name, :bind_userid, :bind_tgt_ref, :bind_creator)");
        if ($err = $oci->get_error_string()) {
          $this->verbose->log(FATAL, "OpenSearchAdmin:: OCI insert into taskpackage error: " . $err);
          $oci->rollback();
          return "error_writing_es_databse";
        }

        $databaseName = $this->config->get_value("es_databaseName", "setup");
        $schema = $this->config->get_value("es_schema", "setup");
        $elementSetName = $this->config->get_value("es_elementSetName", "setup");
        $oci->bind("bind_tgt_ref", &$tgt_ref);
        $oci->bind("bind_action", &$es_action);
        $oci->bind("bind_databaseName", &$databaseName);
        $oci->bind("bind_schema", &$schema);
        $oci->bind("bind_elementSetName", &$elementSetName);
        $oci->set_query("INSERT INTO taskspecificUpdate
                           (targetReference, action, databaseName, schema, elementSetName)
                         VALUES (:bind_tgt_ref, :bind_action, :bind_databaseName, :bind_schema, :bind_elementSetName)");
        if ($err = $oci->get_error_string()) {
          $this->verbose->log(FATAL, "OpenSearchAdmin:: OCI insert into taskspecificUpdate error: " . $err);
          $oci->rollback();
          return "error_writing_es_databse";
        }

        $lbnr = 0;
        $oci->bind("bind_tgt_ref", &$tgt_ref);
        $oci->bind("bind_lbnr", &$lbnr);
        $oci->bind("bind_supplementalid3", $rec_control);
        if (!$rec_lob = $oci->create_lob()) die("cannot create LOB");
        $oci->bind("bind_rec_lob", &$rec_lob, -1, OCI_B_BLOB);
        $oci->set_query("INSERT INTO suppliedrecords
                           (targetreference, lbnr, supplementalid3, record)
                         VALUES (:bind_tgt_ref, :bind_lbnr, :bind_supplementalid3, EMPTY_BLOB())
                         RETURNING record into :bind_rec_lob");
        if ($err = $oci->get_error_string()) {
          $this->verbose->log(FATAL, "OpenSearchAdmin:: OCI insert into suppliedrecords error: " . $err);
          $oci->rollback();
          return "error_writing_es_databse";
        }
        if ($rec_lob->save($rec))
          $oci->commit();
        else {
          $this->verbose->log(FATAL, "OpenSearchAdmin:: OCI save blob into suppliedrecords error: " . $err);
          $oci->rollback();
          return "error_writing_es_databse";
        }
        if ($err = $oci->get_error_string()) {
          $this->verbose->log(FATAL, "OpenSearchAdmin:: OCI commit error: " . $err);
          return "error_writing_es_databse";
        }
      } else {
        $this->verbose->log(FATAL, "OpenSearchAdmin:: OCI nextval error: " . $err);
        return "error_fetching_taskpackage_number";
      }
    }
  }


 /** \brief Correct or create record identifier
  */
  private function set_record_identifier(&$id_obj, $local_id) {
    if (!is_array($id_obj))
      $id_obj = array($id_obj);
    list($agency, $rec_id) = explode(":", $local_id);
    $ac_id->_namespace = PID_NAMESPACE;
    $ac_id->_value = $rec_id . "|" . $agency;
    foreach ($id_obj as $key => $id)
      if ($id->_namespace == PID_NAMESPACE) {
        $id_obj[$key]->_value = $ac_id->_value;
        return;
      }
    $id_obj[] = $ac_id;
  }


 /** \brief 
  */
  private function get_agency($local_id) {
    list($agency, $rec_id) = explode(":", $local_id);
    return $agency;
  }


 /** \brief 
  */
  private function relation_exists($from, $to, $rel) {
    //$this->set_curl();
    $rels_ext = $this->curl->get(sprintf($this->config->get_value("fedora_get_rels_ext"), $from));
    $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->loadXML($rels_ext)) 
      return FALSE;
    else {
      if (strpos($rel, ":"))
        list($rel_ns, $rel) = explode(":", $rel);
//var_dump($rel);
//var_dump($dom->getElementsByTagName($rel)->item(0)->nodeValue);
//print_r($rels_ext); die();
      return ($dom->getElementsByTagName($rel)->item(0)->nodeValue == $to);
    }
  }


 /** \brief 
  */
  private function is_local_identifier($id) {
    list($agency, $rec_id) = explode(":", $id);
    return ((strlen($agency) == 6) && is_numeric($agency) && is_numeric($rec_id));
  }


 /** \brief 
  */
  private function is_identifier($id) {
    list($agency, $rec_id) = explode(":", $id);
    return (!empty($agency) && is_numeric($rec_id));
  }


 /** \brief 
  */
  private function object_get($obj_id) {
    //$this->set_curl();
    $f_req = sprintf($this->config->get_value("fedora_get_raw"), $obj_id);
    return $this->curl->get($f_req);
  }


 /** \brief 
  */
  private function object_exists($obj_id) {
    //$this->set_curl();
    $f_req = sprintf($this->config->get_value("fedora_get"), $obj_id);
    $result = $this->curl->get($f_req);
    return $this->curl->get_status('http_code') < 300;
  }


}

/*
 * MAIN
 */

$ws=new openSearchAdmin();
$ws->handle_request();

?>
