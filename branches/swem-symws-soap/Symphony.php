<?php
// vi: set et ts=4 sw=4:
/**
 * SirsiDynix Symphony ILS Driver for VuFind 2.x.
 *
 * This driver interfaces with Symphony via Symphony Web Services
 * (SymWS), which customers of SirsiDynix may acquire from the
 * SirsiDynix Support Center at <http://support.sirsidynix.com>,
 * and load with a license obtainable by contacting SirsiDynix.
 *
 * @category VuFind
 * @package  ILS_Drivers
 */
class VF_ILS_Driver_Symphony implements VF_ILS_Driver_Interface {
    protected $config = array();
    protected $cacheManager;

    /**
     *
     */
    public function __construct($configFile = 'Symphony.ini')
    {
        // Find and load the configuration file if it exists.
        $configFilePath = VF_Config_Reader::getConfigPath($configFile);
        if (file_exists($configFilePath)) {
            $this->config = parse_ini_file($configFilePath, true);
            if (!is_array($this->config)) {
                throw new VF_Exception_ILS('Could not parse config file!');
            }
        }

        // Merge in defaults.
        $this->config += array(
            'WebServices' => array(),
            'SessionCache' => array(),
            'PolicyCache' => array(),
            'LibraryFilter' => array(),
            '999Holdings' => array(),
        );

        $this->config['WebServices'] += array(
            'clientID' => 'VuFind',
            'baseURL' => 'http://localhost:8080/symws',
            'soapOptions' => array(),
        );

        $this->config['SessionCache'] += array(
            'backend' => 'apc',
            'backendOptions' => array(),
            'frontendOptions' => array(),
        );
        $this->config['SessionCache']['frontendOptions'] += array(
            'lifetime' => 60 * 30,
        );

        $this->config['PolicyCache'] += array(
            'backend' => 'apc',
            'backendOptions' => array(),
            'frontendOptions' => array(),
        );
        $this->config['PolicyCache']['frontendOptions'] += array(
            'lifetime' => null,
        );

        $this->config['LibraryFilter'] += array(
            'include_only' => array(),
            'exclude' => array(),
        );

        $this->config['999Holdings'] += array(
            'entry_number' => 999,
            'mode' => 'off', // also off, failover
        );

        // Initialize cache manager.
        $this->cacheManager = new Zend_Cache_Manager;
        $this->cacheManager->setCacheTemplate('session', array(
            'frontend' => array(
                'name' => 'Core',
                'options' => $this->config['SessionCache']['frontendOptions'],
            ),
            'backend' => array(
                'name' => $this->config['SessionCache']['backend'],
                'options' => $this->config['SessionCache']['backendOptions'],
            ),
        ));
        $this->cacheManager->setCacheTemplate('policy', array(
            'frontend' => array(
                'name' => 'Core',
                'options' => $this->config['PolicyCache']['frontendOptions'],
            ),
            'backend' => array(
                'name' => $this->config['PolicyCache']['backend'],
                'options' => $this->config['PolicyCache']['backendOptions'],
            ),
        ));
    }

    /**
     * Return a SoapClient for the specified SymWS service.
     *
     * This allows SoapClients to be shared and lazily instantiated.
     */
    protected function getSoapClient($service) {
        static $soapClients = array();

        if (!isset($soapClients[$service])) {
            $soapClients[$service] = new SoapClient(
                $this->config['WebServices']['baseURL']."/soap/$service?wsdl",
                $this->config['WebServices']['soapOptions']
            );
        }

        return $soapClients[$service];
    }

    /**
     * Return a SoapHeader for the specified login and password.
     */
    protected function getSoapHeader($login = null, $password = null, $reset = false) {
        $data = array('clientID' => $this->config['WebServices']['clientID']);
        if (!is_null($login)) {
            $data['sessionToken'] = $this->getSessionToken($login, $password, $reset);
        }
        return new SoapHeader(
            'http://www.sirsidynix.com/xmlns/common/header',
            'SdHeader',
            $data
        );
    }

    /**
     *
     * @param boolean $reset if true, replace the currently cached token
     */
    protected function getSessionToken($login, $password, $reset = false) {
        static $sessionTokens = array();
        $key = hash('sha256', "$login:$password");
        
        if (!isset($sessionTokens[$key]) || $reset) {
            $sessionCache = $this->cacheManager->getCache('session');

            if (!$reset && $token = $sessionCache->load($key)) {
                $sessionTokens[$key] = $token;
            } else {
                $params = array('login' => $login);
                if (isset($password)) $params['password'] = $password;
                $response = $this->makeRequest('security', 'loginUser', $params);
                $sessionTokens[$key] = $response->sessionToken;
                $sessionCache->save($sessionTokens[$key], $key);
            }
        }

        return $sessionTokens[$key];
    }

    /**
     * Make a request to Symphony Web Services using the SOAP protocol.
     *
     * @param string $service    the SymWS service name
     * @param string $operation  the SymWS operation name
     * @param array  $parameters the request parameters for the operation
     * @param array  $options    An associative array of additional options,
     *                           with the following elements:
     *                           - 'login': (optional) login to use for
     *                                      (re)establishing a SymWS session
     *                           - 'password': (optional) password to use for
     *                                         (re)establishing a SymWS session
     *                           - 'header': SoapHeader to use, skipping
     *                                       automatic session management
     * @return mixed the result of the SOAP call
     */
    protected function makeRequest($service, $operation, $parameters = array(), $options = array())
    {
        /* If a header was supplied, just use it, skipping everything else. */
        if (isset($options['header'])) {
            return $this->getSoapClient($service)->soapCall(
                $operation,
                $parameters,
                null,
                array($options['header'])
            );
        }

        /* Determine what credentials to use for the SymWS session, if any.
         *
         * If a login and password are specified in $options, use them.
         * If not, for any operation not exempted from SymWS'
         * "Always Require Authentication" option, use the login and password
         * specified in the configuration. Otherwise, proceed anonymously.
         */
        if (isset($options['login'])) {
            $login = $options['login'];
            $password = isset($options['password'])
                ? $options['password']
                : null;
        } elseif (isset($options['WebServices']['login'])
            && !in_array(
                $operation,
                array('isRestrictedAccess', 'license', 'loginUser', 'version')
            )
        ) {
            $login = $this->config['WebServices']['login'];
            $password = isset($this->config['WebServices']['password'])
                ? $this->config['WebServices']['password']
                : null;
        } else {
            $login = null;
            $password = null;
        }

        /* Attempt the request.
         *
         * If it turns out the SoapHeader's session has expired,
         * get a new one and try again.
         */
        $soapClient = $this->getSoapClient($service);

        try {
            $header = $this->getSoapHeader($login, $password);
            $soapClient->__setSoapHeaders($header);
            return $soapClient->$operation($parameters);
        } catch (SoapFault $e) {
            if ($e->faultcode == 'ns0:com.sirsidynix.symws.service.exceptions.SecurityServiceException.sessionTimedOut') {
                $header = $this->getSoapHeader($login, $password, true);
                $soapClient->__setSoapHeaders($header);
                return $soapClient->$operation($parameters);
            } elseif ($operation == 'logoutUser') {
                return null;
            } else {
                throw $e;
            }
        }
    }

    protected function getStatuses_999Holdings($ids) {
        $items = array();
        foreach (VF_Search_Solr_Results::getRecords($ids) as $record) {
            $results = $record->getFormattedMarcDetails(
                $this->config['999Holdings']['entry_number'],
                array(
                    'call number'            => 'marc|a',
                    'copy number'            => 'marc|c',
                    'barcode number'         => 'marc|i',
                    'library'                => 'marc|m',
                    'current location'       => 'marc|k',
                    'home location'          => 'marc|l',
                    'circulate flag'         => 'marc|r',
                )
            );
            foreach ($results as $result) {
                $library = $this->translatePolicyID('LIBR', $result['library']);
                $curr_loc = $this->translatePolicyID('LOCN', $result['current location']);
                $home_loc = $this->translatePolicyID('LOCN', $result['home location']);

                $available =
                    (empty($curr_loc) || $curr_loc == $home_loc)
                    || $result['circulate flag'] == 'Y';
                $callnumber = $result['call number'];
                $location = $library
                    . ' - '
                    . ($available && !empty($curr_lock)
                        ? $curr_loc : $home_loc);

                $items[] = array(
                    'id' => $result['id'],
                    'availability' => $available,
                    'status' => $curr_loc,
                    'location' => $location,
                    'callnumber' => $callnumber,
                    'barcode' => $result['barcode number'],
                    'number' => $result['copy number'],
                    'reserve' => null,
                );
            }
        }
        return $items;
    }

    protected function lookupTitleInfo($ids) {
        $ids = is_array($ids) ? $ids : array($ids);

        $params = array(
            'titleID' => $ids,
            'includeItemInfo' => 'true',
            'includeBoundTogether' => 'true',
        );

        // If only one library is being exclusively included,
        // filtering can be done within Web Services.
        if (count($this->config['LibraryFilter']['include_only']) == 1) {
            $params['libraryFilter'] = $this->config['LibraryFilter']['include_only'][0];
        }

        return $this->makeRequest('standard', 'lookupTitleInfo', $params);
    }

    protected function parseCallInfo($callInfos, $titleID, $bound_in = null)
    {
        $items = array();

        $callInfos = is_array($callInfos) ? $callInfos : array($callInfos);

        foreach ($callInfos as $callInfo) {
            $libraryID = $callInfo->libraryID;

            if ((!empty($this->config['LibraryFilter']['include_only']) &&
                !in_array($libraryID, $this->config['LibraryFilter']['include_only']))
                || in_array($libraryID, $this->config['LibraryFilter']['exclude']))
                continue;

            $copyNumber = 0; // ItemInfo does not include copy numbers,
                             // so we generate them under the assumption
                             // that items are being listed in order.

            if (!isset($callInfo->ItemInfo)) continue; // no items!

            $itemInfos = is_array($callInfo->ItemInfo)
                ? $callInfo->ItemInfo
                : array($callInfo->ItemInfo);
            foreach ($itemInfos as $itemInfo) {
                $in_transit = isset($itemInfo->transitReason);
                $currentLocationID = $itemInfo->currentLocationID;
                $homeLocationID = $itemInfo->homeLocationID;

                /* I would like to be able to write
                 *      $available = $itemInfo->numberOfCharges == 0;
                 * but SymWS does not appear to provide that information.
                 *
                 * SymWS *will* tell me if an item is "chargeable",
                 * but this is inadequate because reference and internet
                 * materials may be available, but not chargeable.
                 *
                 * I can't rely on the presence of dueDate, because
                 * although "dueDate is only returned if the item is currently
                 * checked out", the converse is not true: due dates of NEVER
                 * are simply omitted.
                 *
                 * TitleAvailabilityInfo would be more helpful per item;
                 * as it is, it tells me only number available and library.
                 *
                 * Hence the following criterion: an available item must not
                 * be in-transit, and if it, like exhibits and reserves,
                 * is not in its home location, it must be chargeable.
                 */
                $available = !$in_transit &&
                    ($itemInfo->currentLocationID == $itemInfo->homeLocationID
                    || $itemInfo->chargeable);

                /* Statuses like "Checked out" and "Missing" are represented
                 * by an item's current location. */
                $status = $in_transit
                    ? 'In transit'
                    : $this->translatePolicyID('LOCN', $currentLocationID);

                /* If an item is available, its current location should be
                 * reported as its location. */
                $location = $available
                    ? $this->translatePolicyID('LOCN', $currentLocationID)
                    : $this->translatePolicyID('LOCN', $homeLocationID);

                /* Locations may be shared among libraries, so unless holdings
                 * are being filtered to just one library, it is insufficient
                 * to provide just the location description as the "location".
                 */
                if (count($this->config['LibraryFilter']['include_only'])!=1) {
                    $location = $this->translatePolicyID('LIBR', $libraryID)
                        . ' - ' . $location;
                }

                $items[] = array(
                    'id' => $titleID,
                    'availability' => $available,
                    'status' => $status,
                    'location' => $location,
                    'callnumber' => $callInfo->callNumber,
                    'duedate' => isset($itemInfo->dueDate)
                        ? $itemInfo->dueDate : null,
                    'reserve' => isset($itemInfo->reserveCollectionID)
                        ? 'Y' : 'N',
                    'number' => ++$copyNumber,
                    'barcode' => $itemInfo->itemID,
                    'bound_in' => $bound_in,
                );
            }
        }
        return $items;
    }

    protected function parseBoundwithLinkInfo($boundwithLinkInfos, $ckey)
    {
        $items = array();

        $boundwithLinkInfos = is_array($boundwithLinkInfos)
            ? $boundwithLinkInfos
            : array($boundwithLinkInfos);

        foreach ($boundwithLinkInfos as $boundwithLinkInfo) {
            // Ignore BoundwithLinkInfos which do not refer to parents
            // or which refer to the record we're already looking at.
            if (!$boundwithLinkInfo->linkedAsParent
             || $boundwithLinkInfo->linkedTitle->titleID == $ckey)
                continue;

            // Fetch the record that contains the parent CallInfo,
            // identify the CallInfo by matching itemIDs,
            // and parse that CallInfo in the items array.
            $parent_ckey = $boundwithLinkInfo->linkedTitle->titleID;
            $linked_itemID = $boundwithLinkInfo->itemID;
            $resp = $this->lookupTitleInfo($parent_ckey);

            $callInfos = is_array($resp->TitleInfo->CallInfo)
                ? $resp->TitleInfo->CallInfo
                : array($resp->TitleInfo->CallInfo);

            foreach ($callInfos as $callInfo) {
                $itemInfos = is_array($callInfo->ItemInfo)
                    ? $callInfo->ItemInfo
                    : array($callInfo->ItemInfo);
                foreach ($itemInfos as $itemInfo) {
                    if ($itemInfo->itemID == $linked_itemID) {
                        $items += $this->parseCallInfo(
                            $callInfo,
                            $ckey,
                            $parent_ckey
                        );
                    }
                }
            }
        }

        return $items;
    }

    protected function getLiveStatuses($ids) {
        foreach($ids as $id) $items[$id] = array();

        /* In Symphony, a title record has at least one "callnum" record,
         * to which are attached zero or more item records. This structure
         * is reflected in the LookupTitleInfoResponse, which contains
         * one or more TitleInfo elements, which contain one or more
         * CallInfo elements, which contain zero or more ItemInfo elements.
         */
        $response = $this->lookupTitleInfo($ids);
        $titleInfos = is_array($response->TitleInfo)
            ? $response->TitleInfo
            : array($response->TitleInfo);

        foreach($titleInfos as $titleInfo) {
            $ckey = $titleInfo->titleID;

            /* In order to have only one item record per item regardless of
             * how many titles are bound within, Symphony handles titles bound
             * with others by linking callnum records in parent-children
             * relationships, where only the parent callnum has item records
             * attached to it. The CallInfo element of a child callnum
             * does not contain any ItemInfo elements, so we must locate the
             * parent CallInfo using BoundwithLinkInfo, in order to parse
             * the ItemInfo.
             */
            if (isset($titleInfo->BoundwithLinkInfo)) {
                $items[$ckey] = $this->parseBoundwithLinkInfo($titleInfo->BoundwithLinkInfo, $ckey);
            }

            /* Callnums that are not bound-with, or are bound-with parents,
             * have item records and can be parsed directly. Since bound-with
             * children do not have item records, parsing them should have no
             * effect. */
            $items[$ckey] += $this->parseCallInfo($titleInfo->CallInfo, $ckey);
        }
        return $items;
    }

    
    /**
     * Translate a Symphony policy ID into a policy description
     * (e.g. VIDEO-COLL => Videorecording Collection).
     *
     * In order to minimize roundtrips with the SymWS server,
     * we fetch more than was requested and cache the results.
     * At time of writing, SymWS did not appear to
     * support retrieving policies of multiple types simultaneously,
     * so we currently fetch only all policies of one type at a time.
     *
     * @param string $policyType The policy type, e.g. Location or Library.
     * @param string $policyID   The policy ID, e.g. VIDEO-COLL or SWEM.
     * @return The policy description, if found, or the policy ID, if not.
     *
     * @todo policy description override 
     */
    protected function translatePolicyID($policyType, $policyID)
    {
        $policyType = strtoupper($policyType); // so Libr/LIBR, sWem/SWEM
        $policyID = strtoupper($policyID);     // get the same cacheKey
        $policyCache = $this->cacheManager->getCache('policy');
        $cacheKey = hash('sha256', "${policyType}_${policyID}");

        if (!$policyDescription = $policyCache->load($cacheKey)) {
            try {
                $response = $this->makeRequest(
                    'admin',
                    'lookupPolicyList',
                    array('policyType' => $policyType)
                );
            } catch (Exception $e) {
                return $policyID;
            }

            foreach ($response->policyInfo as $policyInfo) {
                $saveCacheKey = hash(
                    'sha256',
                    "${policyType}_$policyInfo->policyID"
                );
                    
                $policyCache->save(
                    $policyInfo->policyDescription,
                    $saveCacheKey
                );
            }

            $policyDescription = $policyCache->load($cacheKey);
        }

        return $policyDescription ?: $policyID;
    }

///////////////////////////////////////////////////////////////////////////////

    public function getStatus($id)
    {
        $statuses = $this->getStatuses(array($id));
        return isset($statuses[$id]) ? $statuses[$id] : array();
    }

    public function getStatuses($ids)
    {
        if ($this->config['999Holdings']['mode'] == 'on') {
            return $this->getStatuses_999Holdings($ids);
        } else {
            return $this->getLiveStatuses($ids);
        }
    }

    public function getHolding($id, $patron = false)
    {
        return $this->getStatus($id);
    }

    public function getPurchaseHistory($id)
    {
        return array();
    }

    public function patronLogin($username, $password) {
        $patron = array(
            'cat_username' => $username,
            'cat_password' => $password,
        );

        $resp = $this->makeRequest(
            'patron',
            'lookupMyAccountInfo',
            array(
                'includePatronInfo' => 'true',
                'includePatronAddressInfo' => 'true',
            ),
            array(
                'login' => $username,
                'password' => $password,
            )
        );

        $patron['id'] = $resp->patronInfo->userKey;

        if (preg_match('/([^,]*),\s([^\s]*)/', $resp->patronInfo->displayName, $matches)) {
            $patron['firstname'] = $matches[2];
            $patron['lastname'] = $matches[1];
        }

        // @TODO: email, major, college

        return $patron;
    }
}
