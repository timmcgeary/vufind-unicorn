<?php
/* vim: set expandtab tabstop=4 shiftwidth=4: */
/**
 * SirsiDynix Symphony ILS Driver for VuFind 2.x.
 *
 * The driver interfaces with Symphony via Symphony Web Services
 * (SymWS) 3.2, which customers of SirsiDynix may acquire at
 * <http://support.sirsidynix.com/kA57000000000b8>, after first logging in
 * to the SirsiDynix Support Center at <http://support.sirsidynix.com>.
 *
 * The driver assumes that it is on the same server as SymWS,
 * and that it can use the clientID "VuFind".
 * If not, you will want a Symphony.ini of a form similar to:
 *     [symws]
 *     baseURL = "http://lion.wm.edu:8080/symws"
 *     clientID = "VuFind"
 *
 * @category VuFind
 * @package  ILS_Drivers
 */
class VF_ILS_Driver_Symphony implements VF_ILS_Driver_Interface {
    private $config = array();
    private $policies;
    private $sessions = array();

    /**
     * Initialize the driver.
     */
    public function __construct($configFile = 'Symphony.ini') {
        /* Find and load the configuration file if it exists. */
        $configFilePath = VF_Config_Reader::getConfigPath($configFile);
        if (file_exists($configFilePath)) {
            $this->config = parse_ini_file($configFilePath, true);
            if (!is_array($this->config)) {
                throw new VF_Exception_ILS('Could not parse config file!');
            }
        }

        /* Merge in defaults. */
        $this->config += array(
            'symws' => array(),
        );
        $this->config['symws'] += array(
            'clientID' => 'VuFind',
            'baseURL' => 'http://localhost:8080/symws',
        );
    }

    /**
     * Query SirsiDynix Symphony Web Services using the REST protocol.
     */
    private function makeRequest($service, $operation, $parameters = array()) {
        /*
         * Use the configured clientID by default.
         */
        if (!isset($parameters['clientID'])) {
            $parameters['clientID'] = $this->config['symws']['clientID'];
        }

        /*
         * Though clientID, sessionToken, and locale can be passed in the
         * query string, SirsiDynix recommend passing them in the HTTP header.
         */
        $header_fields = array();
        foreach (array('clientID', 'sessionToken', 'locale') as $p) {
            if (isset($parameters[$p])) {
                $header_fields[] = 'x-sirs-' . $p . ': ' . $parameters[$p];
                unset($parameters[$p]);
            }
        }

        /*
         * Since Symphony Web Services do not seem to accept multi-valued URL
         * parameters as titleID[0]=1&titleID[1]=2, but as titleID=1&titleID=2,
         * we're using a foreach loop instead of PHP's http_build_query.
         */
        $params = array();
        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $params[] = urlencode($key) . '=' . urlencode($item);
                }
            } else {
                $params[] = urlencode($key) . '=' . urlencode($value);
            }
        }
        $query = join('&', $params);
        $url = $this->config['symws']['baseURL'] .
               "/rest/$service/$operation?" .
               $query;

        /*
         * Send the request; parse the response.
         */
        $client = new VF_Http_Client();
        $client->setUri($url);
        $client->setHeaders($header_fields);
        $result = $client->request();

        if ($result->isError()) {
            throw new VF_Exception_ILS('SymWS request failed.');
        }

        $xml = simplexml_load_string($result->getBody());

        /*
         * Generally speaking, not getting a SimpleXMLElement is an error.
         * However, quoth SirsiDynix: "logoutUser is a one-way operation;
         * there is no response defined." The author is unaware of any other
         * operation that has no response, but they may exist.
         *
         * If we do get a SimpleXMLElement, and it's a Fault, report it.
         */
        if ($xml === false) {
            if ($service == 'security' && $operation == 'logoutUser') {
                return '';
            } else {
                throw new VF_Exception_ILS('Received unexpected SymWS response.');
            }
        } elseif ($xml->getName() == 'Fault') {
            $msg = $xml->string ? $xml->string : $xml->code;
            throw new VF_Exception_ILS('SymWS Fault: ' . $msg);
        }

        return $xml;
    }

    /**
     * Get SymWS session information. If necessary, first establish a session.
     */
    private function getSession($username, $password) {
        if(empty($this->sessions[$username])) {
            $xml = $this->makeRequest(
                'security',
                'loginUser',
                array(
                    'login' => $username,
                    'password' => $password,
                )
            );

            $this->sessions[$username] = array(
                'userID' => (string) $xml->userID,
                'token' => (string) $xml->sessionToken,
            );
        }
        return $this->sessions[$username];
    }

    /**
     * Deinitialize the driver.
     */
    public function __destruct() {
        /*
         * Terminate any Web Services sessions that this object created.
         */
        foreach($this->sessions as $token) {
            try {
                $this->makeRequest(
                    'security',
                    'logoutUser',
                    array('sessionToken' => $token)
                );
            } catch (Exception $e) {
                // The operation may fail, e.g. if the session timed out,
                // but failure can probably be safely ignored.
                // TODO?: Decide if it's worth trying again
                //        based on the particular error.
            }
        }
    }

    /**
     * Translate a Symphony policy ID into a policy description
     * (e.g. VIDEO-COLL => Videorecording Collection).
     *
     * In order to minimize the number of queries, we fetch more than we need
     * and cache the results. At time of writing, SymWS did not appear to
     * support retrieving policies of multiple types simultaneously,
     * so we currently fetch only all policies of one type at a time.
     *
     * @param string $policyType The policy type, e.g. Location or Library.
     * @param string $policyID   The policy ID, e.g. VIDEO-COLL or SWEM.
     * @return The policy description, if found, or the policy ID, if not.
     */
    private function translatePolicyID($policyType, $policyID) {
        if (!isset($this->policies[$policyType][$policyID])) {
            $response = $this->makeRequest(
                'admin',
                "lookup${policyType}PolicyList",
                array(),
                "Lookup${policyType}PolicyListResponse"
            );
            foreach ($response->policyInfo as $policyInfo) {
                $this->policies[$policyType][(string) $policyInfo->policyID] =
                    (string) $policyInfo->policyDescription;
            }
        }
        return isset($this->policies[$policyType][$policyID])
            ? $this->policies[$policyType][$policyID]
            : $policyID;
    }

    /**
     * Convert CallInfo structures provided by Symphony Web Services
     * into a VuFind item array.
     */
    private function parseCallInfo($callInfos, $ckey, $boundwith = NULL) {
        $items = array();
        foreach($callInfos as $callInfo) {
            $copynum = 0; // We will increment this value as we loop through
                          // the ItemInfo structures, making the assumption
                          // that, since Web Services is not providing item
                          // number, it is returning the items in order.
            $library_id = (string)$callInfo->libraryID;

            /* Allow returned holdings information to be
             * limited to a whitelist of library names. */
            if (isset($this->config['holdings']['include_libraries']) &&
                !in_array(
                    $library_id,
                    $this->config['holdings']['include_libraries']
                )) {
                continue;
            }

            /* Allow libraries to be excluded by name
             * from returned holdings information. */
            if (isset($this->config['holdings']['exclude_libraries']) &&
                in_array(
                    $library_id,
                    $this->config['holdings']['exclude_libraries']
                )) {
                continue;
            }

            $library = $this->translatePolicyID('Library', $library_id);
            $callnumber = (string)$callInfo->callNumber;

            foreach ($callInfo->ItemInfo as $itemInfo) {
                $copynum++; // See the note about $copynum above.
                $chargeable = (string)$itemInfo->chargeable == 'true';
                $curr_loc_id = (string)$itemInfo->currentLocationID;
                $home_loc_id = (string)$itemInfo->homeLocationID;
                $curr_loc = $this->translatePolicyID('Location', $curr_loc_id);
                $home_loc = $this->translatePolicyID('Location', $home_loc_id);
                $being_reshelved = isset($itemInfo->reshelvingLocationID);
                $in_transit = isset($itemInfo->transitReason);

                /* I would like to be able to write
                 *      $available = $nr_charges == 0;
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
                 * Hence the following criterion:
                 */
                $available = !$in_transit &&
                    ($curr_loc == $home_loc || $chargeable);
                /* "|| $chargeable" accounts for reserved items: they may not
                 * be in their home location, but you may be able to check them
                 * out at their current location. */

                $items[] = array(
                    'id' => $ckey,
                    'availability' => $available,
                    'status' => $in_transit
                        ? 'In transit'
                        : $curr_loc,
                    'location' => $library
                        . ' - '
                        . ($available ? $curr_loc : $home_loc),
                    'callnumber' => $callnumber,
                    'duedate' => isset($itemInfo->dueDate)
                        ? date('Y-m-d', strtotime((string)$itemInfo->dueDate))
                        : null,
                    'reserve' => isset($itemInfo->reserveCollectionID)
                        ? 'Y'
                        : 'N',
                    'number' => $copynum,
                    'barcode' => (string)$itemInfo->itemID,
                    'bound_in' => isset($boundwith) ? $boundwith : null,
                );
            }
        }
        return $items;
    }

    /**
     *
     */
    public function getConfig($func) {
        switch ($func) {
            case 'Renewals': return array();
            default: return false;
        }
    }

    /**
     * @see VF_ILS_Driver_Interface::getStatus()
     */
    public function getStatus($id) {
        $statuses = $this->getStatuses(array($id));
        return isset($statuses[$id]) ? $statuses[$id] : array();
    }

    /**
     * @see VF_ILS_Driver_Interface::getStatuses()
     */
    public function getStatuses($ids) {
        foreach($ids as $id) $items[$id] = array();

        $response = $this->makeRequest('standard', 'lookupTitleInfo', array(
            'titleID' => $ids,
            'includeItemInfo' => 'true',
            'includeBoundTogether' => 'true',
        ), 'LookupTitleInfoResponse');

        /* In Symphony, a title record has at least one "callnum" record,
         * to which are attached zero or more item records. This structure
         * is reflected in the LookupTitleInfoResponse, which contains
         * one or more TitleInfo elements, which contain one or more
         * CallInfo elements, which contain zero or more ItemInfo elements.
         */
        foreach ($response->TitleInfo as $titleInfo) {
            $ckey = (string)$titleInfo->titleID;

            /* In order to have only one item record per item regardless of how
             * many titles are bound within, Symphony handles titles bound with
             * others by linking callnum records in parent-children
             * relationships, where only the parent callnum has item records
             * attached to it. The CallInfo element of a child callnum
             * does not contain any ItemInfo elements, so we must locate the
             * parent CallInfo using BoundwithLinkInfo, in order to parse
             * the ItemInfo.
             *
             * First, find BoundwithLinkInfo elements that refer to bound-with
             * parents that are not the current title.
             */
            $namespaces = $titleInfo->getDocNamespaces(); 
            $titleInfo->registerXPathNamespace('def', $namespaces['']);
            $boundwithLinkInfos = $titleInfo->xpath(
                "def:BoundwithLinkInfo[def:linkedAsParent = \"true\" and"
                . "def:linkedTitle/def:titleID != \"$ckey\"]");

            foreach ($boundwithLinkInfos as $boundwithLinkInfo) {
                /* Look up the title referred to by the BoundwithLinkInfo. */
                $parent_ckey = (string)$boundwithLinkInfo->linkedTitle->titleID;
                $parent_xml = $this->makeRequest(
                    'standard',
                    'lookupTitleInfo',
                    array(
                        'titleID' => $parent_ckey,
                        'includeItemInfo' => 'true',
                    ),
                    'LookupTitleInfoResponse'
                );

                /* Find the CallInfo that contains the ItemInfo with the itemID
                 * referenced by the BoundwithLinkInfo, and parse it. */
                $itemID = (string)$boundwithLinkInfo->itemID;

                $namespaces = $parent_xml->getDocNamespaces(); 
                $parent_xml->registerXPathNamespace('def', $namespaces['']);
                $callInfos = $parent_xml->xpath(
                    "def:TitleInfo[def:titleID = \"$parent_ckey\"]/"
                    . "def:CallInfo[def:ItemInfo/def:itemID = \"$itemID\"]");

                $items[$ckey] += $this->parseCallInfo(
                    $callInfos,
                    $ckey,
                    $parent_ckey
                );
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
     * @see VF_ILS_Driver_Interface::getHolding()
     */
    public function getHolding($id, $patron = false) {
        return $this->getStatus($id);
    }

    /**
     * @see VF_ILS_Driver_Interface::getPurchaseHistory()
     * @todo
     */
    public function getPurchaseHistory($id) {
        return array();
    }

    public function patronLogin($username, $password) {
        $patron = array(
            'cat_username' => $username,
            'cat_password' => $password,
        );
        $session = $this->getSession($username, $password);

        $xml = $this->makeRequest(
            'security',
            'lookupUserInfo',
            array(
                'userID' => $session['userID'],
                'sessionToken' => $session['token'],
            )
        );

        $patron['id'] = (string) $xml->userID;

        $displayName = (string)$xml->displayName;
        if (preg_match('/([^,]*), ([^ ]*)/', $displayName, $matches)) {
            $patron['firstname'] = $matches[2];
            $patron['lastname'] = $matches[1];
        }

        // TODO: email, major, college from lookupMyAccountInfo?
        // It would require access to the patron service.

        return $patron;
    }

    public function getMyTransactions($patron) {
        $session = $this->getSession($patron['cat_username'], $patron['cat_password']);

        $xml = $this->makeRequest(
            'patron',
            'lookupMyAccountInfo',
            array(
                'sessionToken' => $session['token'],
                'includePatronCheckoutInfo' => 'ALL',
            )
        );

        $items = array();

        foreach($xml->children('ns3', TRUE)->patronCheckoutInfo as $checkout) {
            $items[] = array(
                'duedate' => !empty($checkout->dueDate)
                    ? date('Y-m-d g:ia', strtotime((string)$checkout->dueDate))
                    : 'Never',
                'id' => (string) $checkout->titleKey,
                'barcode' => (string) $checkout->itemID,
                'item_id' => (string) $checkout->itemID,
                'renew' => (string) $checkout->renewals,
                'request' => (string) $checkout->recallNoticesSent,
                'volume' => null,
                'publication_year' => null,
                'renewable' => ((string) $checkout->renewalsRemaining > 0),
                'message' => null,
                'title' => (string) $checkout->title,
            );
        }

        return $items;
    }

    public function getRenewDetails($checkoutDetails) {
        return $checkoutDetails['item_id'];
    }

    public function renewMyItems($renewDetails) {
        $session = $this->getSession($renewDetails['patron']['cat_username'], $renewDetails['patron']['cat_password']);

        $details = array();
        $blocks = array();

        foreach($renewDetails['details'] as $item_id) {
            try {
                $xml = $this->makeRequest(
                    'patron',
                    'renewMyCheckout',
                    array(
                        'sessionToken' => $session['token'],
                        'itemID' => $item_id,
                    )
                );
            } catch (Exception $e) {
                $details[] = array(
                    'success' => false,
                    'sysMessage' => $e->getMessage(),
                );
                $blocks[] = $e->getMessage();
                continue;
            }

            $details[] = array(
                'success' => true,
                'new_date' => date('Y-m-d', strtotime((string)$xml->dueDate)),
                'new_time' => date('g:ia', strtotime((string)$xml->dueDate)),
                'item_id' => (string) $xml->itemID,
                'sysMessage' => (string) $xml->message,
            );
        }

        return array(
            'block' => $blocks,
            'blocks' => $blocks,
            'details' => $details,
        );
    }

    public function getMyFines($patron) {
        $session = $this->getSession($patron['cat_username'], $patron['cat_password']);

        $xml = $this->makeRequest(
            'patron',
            'lookupMyAccountInfo',
            array(
                'sessionToken' => $session['token'],
                'includeFeeInfo' => 'UNPAID_FEES',
            )
        );

        $fines = array();

        foreach($xml->children('ns3', TRUE)->feeInfo as $feeInfo) {
            $reasonDescription = trim((string)$feeInfo->billReasonDescription);

            $fines[] = array(
                'amount' => (string)$feeInfo->amount * 100,
                'checkout' => (string)$feeInfo->feeItemInfo->checkoutDate,
                'fine' => empty($reasonDescription)
                    ? (string)$feeInfo->billReasonID
                    : $reasonDescription,
                'balance' => (string)$feeInfo->amountOutstanding * 100,
                'createdate' => (string)$feeInfo->dateBilled,
                'duedate' => (string)$fee->dueDate,
                'id' => (string)$fee->feeItemInfo->titleKey,
            );
        }

        return $fines;
    }

    public function getMyProfile($patron) {
        $session = $this->getSession($patron['cat_username'], $patron['cat_password']);
        $profile = array();

        $xml = $this->makeRequest(
            'patron',
            'lookupMyAccountInfo',
            array(
                'sessionToken' => $session['token'],
                'includePatronInfo' => 'true',
                'includePatronAddressInfo' => 'true',
            )
        );

        /*
         * Address information
         */
        $primaryAddress = $xml->children('ns3', TRUE)->patronAddressInfo->primaryAddress;
        $primaryAddressInfo = 'Address' . $primaryAddress . 'Info';

        foreach ($xml->children('ns3', TRUE)->patronAddressInfo->$primaryAddressInfo as $addressInfo) {
            $addressInfo = $addressInfo->children('ns2', TRUE);

            switch ($addressInfo->addressPolicyID) {
                case 'LINE':
                    if(empty($profile['address1'])) {
                        $profile['address1'] = (string) $addressInfo->addressValue;
                    } else {
                        $profile['address1'] .= ' / ' . (string) $addressInfo->addressValue;
                    }
                    break;
                case 'CITY/STATE':
                    $profile['address2'] = (string) $addressInfo->addressValue;
                    break;
                case 'ZIP':
                    $profile['zip'] = (string) $addressInfo->addressValue;
                    break;
                case 'PHONE':
                    $profile['phone'] = (string) $addressInfo->addressValue;
                    break;
            }
        }

        /*
         * The name
         */
        $displayName = (string) $xml->children('ns3', TRUE)->patronInfo->displayName;
        if (preg_match('/([^,]*), ([^ ]*)/', $displayName, $matches)) {
            $profile['firstname'] = $matches[2];
            $profile['lastname'] = $matches[1];
        }

        return $profile;
    }

    public function getMyHolds($patron) {
        $session = $this->getSession($patron['cat_username'], $patron['cat_password']);
        $holds = array();

        $xml = $this->makeRequest(
            'patron',
            'lookupMyAccountInfo',
            array(
                'sessionToken' => $session['token'],
                'includePatronHoldInfo' => 'ACTIVE',
            )
        );

        foreach($xml->children('ns3', TRUE)->patronHoldInfo as $patronHoldInfo) {
            $status = trim((string)$patronHoldInfo->holdStatus);
            /**
             * Quoth the SymWS docs:
             * "1 means the hold is active. 2 means the hold is inactive."
             * This does not appear to be true.
             */
            if ($status == '2') {
                $type = 'Active';
            } elseif ($status == '1') {
                $type = 'Inactive: ' . (string)$patronHoldInfo->holdInactiveReasonDescription;
            }

            $holds[] = array(
                'type' => $type,
                'id' => (string)$patronHoldInfo->titleKey,
                'location' => (string)$patronHoldInfo->pickupLibraryDescription,
                'reqnum' => (string)$patronHoldInfo->holdKey,
                'expire' => date('Y-m-d', strtotime((string)$patronHoldInfo->expiresDate)),
                'create' => date('Y-m-d', strtotime((string)$patronHoldInfo->placedDate)),
                'position' => (string)$patronHoldInfo->queuePosition,
                'available' => (string)$patronHoldInfo->available == 'true'
                    ? true
                    : false,
                'item_id' => (string)$patronHoldInfo->itemID,
                'title' => (string)$patronHoldInfo->title,
            );
        }

        return $holds;
    }

    public function getPickupLocations($patron) {
        return array(); // TODO
    }

    /**
     * Quoth Matt Anderson, Product Manager, SirsiDynix (11/30/2011):
     * "[Academic reserves] functionality is currently planned for Symphony
     * Web Services 3.3, which will release soon with Enterprise 4.2."
     */

    public function getCourses() {
        return array(); // TODO
    }

    public function getDepartments() {
        return array(); // TODO
    }

    public function getInstructors() {
        return array(); // TODO
    }

    public function findReserves($courseID, $instructorID, $departmentID) {
        return array(); // TODO
    }
}

