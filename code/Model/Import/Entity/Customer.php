<?php

/*
 * Copyright 2011 Daniel Sloof
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class Danslo_ApiImport_Model_Import_Entity_Customer
    extends Mage_ImportExport_Model_Import_Entity_Customer
{

    /**
     * Prepended to all events fired in this class.
     *
     * @var string
     */
    protected $_eventPrefix = 'api_import_entity_customer';

    /**
     * Sets the proper data source model and adress model.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->_dataSourceModel = Danslo_ApiImport_Model_Import::getDataSourceModel();
        $this->_addressEntity   = Mage::getModel('api_import/import_entity_customer_address', $this);
    }

    /**
     * Adds events before and after importing.
     *
     * @return boolean
     */
    public function _importData()
    {
        Mage::dispatchEvent($this->_eventPrefix . '_before_import', array(
            'data_source_model' => $this->_dataSourceModel,
            'entity_model'      => $this
        ));

        if (Mage_ImportExport_Model_Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            $this->_deleteCustomers();
        } else if (Danslo_ApiImport_Model_Import::BEHAVIOR_CUSTOMER) {
            $this->_saveCustomers();
        } else if (Danslo_ApiImport_Model_Import::BEHAVIOR_ADDRESS){
            $this->_addressEntity->importData();
        } else {
            $this->_saveCustomers();
            $this->_addressEntity->importData();
        }

        Mage::dispatchEvent($this->_eventPrefix . '_after_import', array(
            'entities'      => $this->_newCustomers,
            'entity_model'  => $this
        ));

        return $this->_dataSourceModel->getEntities();
    }

    /**
     * Get old customers.
     *
     * @return array
     */
    public function getOldCustomers()
    {
        return $this->_oldCustomers;
    }

    /**
     * Validate data row.
     *
     * @param array $rowData
     * @param int $rowNum
     * @return boolean
     */
    public function validateRow(array $rowData, $rowNum)
    {
        static $email   = null; // e-mail is remembered through all customer rows
        static $website = null; // website is remembered through all customer rows

        if (isset($this->_validatedRows[$rowNum])) { // check that row is already validated
            return !isset($this->_invalidRows[$rowNum]);
        }
        $this->_validatedRows[$rowNum] = true;

        $rowScope = $this->getRowScope($rowData);

        if (self::SCOPE_DEFAULT == $rowScope) {
            $this->_processedEntitiesCount ++;
        }

        $email        = $rowData[self::COL_EMAIL];
        $emailToLower = strtolower($rowData[self::COL_EMAIL]);
        $website      = $rowData[self::COL_WEBSITE];

        $oldCustomersToLower = array_change_key_case($this->_oldCustomers, CASE_LOWER);
        $newCustomersToLower = array_change_key_case($this->_newCustomers, CASE_LOWER);

        // BEHAVIOR_DELETE use specific validation logic
        if (Mage_ImportExport_Model_Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            if (self::SCOPE_DEFAULT == $rowScope
                && !isset($oldCustomersToLower[$emailToLower][$website])) {
                $this->addRowError(self::ERROR_EMAIL_SITE_NOT_FOUND, $rowNum);
            }
        } elseif (self::SCOPE_DEFAULT == $rowScope) { // row is SCOPE_DEFAULT = new customer block begins
            if (!Zend_Validate::is($email, 'EmailAddress')) {
                $this->addRowError(self::ERROR_INVALID_EMAIL, $rowNum);
            } elseif (!isset($this->_websiteCodeToId[$website])) {
                $this->addRowError(self::ERROR_INVALID_WEBSITE, $rowNum);
            } else {
                if (isset($newCustomersToLower[$emailToLower][$website])) {
                    $this->addRowError(self::ERROR_DUPLICATE_EMAIL_SITE, $rowNum);
                }
                $this->_newCustomers[$email][$website] = false;

                if (!empty($rowData[self::COL_STORE]) && !isset($this->_storeCodeToId[$rowData[self::COL_STORE]])) {
                    $this->addRowError(self::ERROR_INVALID_STORE, $rowNum);
                }

                // check password
                if (isset($rowData['password']) && strlen($rowData['password'])
                    && Mage::helper('core/string')->strlen($rowData['password']) < self::MAX_PASSWD_LENGTH
                ) {
                    $this->addRowError(self::ERROR_PASSWORD_LENGTH, $rowNum);
                }

                // check simple attributes
                foreach ($this->_attributes as $attrCode => $attrParams) {
                    if (in_array($attrCode, $this->_ignoredAttributes)) {
                        continue;
                    }
                    if (isset($rowData[$attrCode]) && strlen($rowData[$attrCode])) {
                        $this->isAttributeValid($attrCode, $attrParams, $rowData, $rowNum);
                    } elseif ($attrParams['is_required'] && !isset($oldCustomersToLower[$emailToLower][$website])) {
                        $this->addRowError(self::ERROR_VALUE_IS_REQUIRED, $rowNum, $attrCode);
                    }
                }
            }
            if (isset($this->_invalidRows[$rowNum])) {
                $email = false; // mark row as invalid for next address rows
            }
        } elseif (self::SCOPE_OPTIONS != $rowScope) {
            if (null === $email) { // first row is not SCOPE_DEFAULT
                $this->addRowError(self::ERROR_EMAIL_IS_EMPTY, $rowNum);
            } elseif (false === $email) { // SCOPE_DEFAULT row is invalid
                $this->addRowError(self::ERROR_ROW_IS_ORPHAN, $rowNum);
            }
        }
        // skip address entities check
        return !isset($this->_invalidRows[$rowNum]);
    }
}
