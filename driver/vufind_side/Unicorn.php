<?php
/**
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 */

require_once 'Interface.php';
require_once 'sys/Proxy_Request.php';

class Unicorn implements DriverInterface
{
    private $host;
    private $port;
    private $search_prog;
    private $url;

    protected $db;
    protected $ilsConfigArray;

    function __construct()
    {
        // Load Configuration for this Module
        $this->ilsConfigArray = parse_ini_file('conf/Unicorn.ini', true);
        global $configArray;

        // allow user to specify the full url to the Sirsi side perl script
        $this->url = $this->ilsConfigArray['Catalog']['url'];

        // host/port/search_prog kept for backward compatibility
        $this->host = $this->ilsConfigArray['Catalog']['host'];
        $this->port = $this->ilsConfigArray['Catalog']['port'];
        $this->search_prog = $this->ilsConfigArray['Catalog']['search_prog'];

        $this->show_library = $this->ilsConfigArray['Catalog']['show_library'];
        $this->show_library_format = $this->ilsConfigArray['Catalog']['show_library_format'];
        $class = $configArray['Index']['engine'];
        $this->db = new $class($configArray['Index']['url']);
    }

    public function getStatus($id)
    {
        $params = array('query' => 'single', 'id' => $id);
        $response = $this->querySirsi($params);
        if (empty($response)) {
            return array();
        }

        // separate the item lines and the MARC holdings records
        $marc_marker = '-----BEGIN MARC-----';
        $marc_marker_pos = strpos($response, $marc_marker);
        $lines = ($marc_marker_pos !== false) ? substr($response, 0, $marc_marker_pos) : '';
        $marc = ($marc_marker_pos !== false) 
            ? substr($response, $marc_marker_pos + strlen($marc_marker)) : '';

        $items = array();
        $lines = explode("\n", rtrim($lines));
        foreach($lines as $line) {
            $item = $this->parseStatusLine($line);
            $items[] = $item;
        }
        
        if (!empty($items)) {            
            // sort the items by shelving key in descending order, then ascending by copy number
            // use create_function to create anonymous comparison function for php 5.2 compatibility
            $cmp = create_function('a,b',
            	'if ($a["shelving_key"] == $b["shelving_key"]) '
                .     'return $a["number"] - $b["number"];'
                . 'return $a["shelving_key"] < $b["shelving_key"] ? 1 : -1;');
            usort($items, $cmp);
            
            // put MARC holdings records into 'marc_holdings' field of the first item
            $items[0]['marc_holdings'] = $this->getMarcHoldings($marc);
        }
        return $items;
    }
    
    public function getStatuses($idList)
    {
        $statuses = array();
        $params = array('query' => 'multiple', 'ids' => implode("|", array_unique($idList)));
        $response = $this->querySirsi($params);
        if (empty($response)) {
            return array();
        }
        $lines = explode("\n", $response);

        $currentId = null;
        $group = -1;
        foreach($lines as $line) {
            $item = $this->parseStatusLine($line);
            if ($item['id'] != $currentId) {
                $currentId = $item['id'];
                $statuses[] = array();
                $group++;
            }
            $statuses[$group][] = $item;
        }
        return $statuses;
    }

    public function getPurchaseHistory($id)
    {
        return array();
    }

    // getHolding and getHoldings are here for backwards compatibility with v0.8
    public function getHolding($id)
    {
        return $this->getStatus($id);
    }

    public function getHoldings($idList)
    {
        return $this->getStatuses($idList);
    }

    public function placeHold($itemId, $patronId, $pickupLib)
    {
        $params = array('query' => 'hold', 'itemId' => $itemId, 'patronId' => $patronId, 'lib' => $pickupLib);
        $response = $this->querySirsi($params);

        list($code, $reqnum) = explode('|', $response);
        if ($code == "209") {
            $result = array('hold' => $code, 'reason' => 'Your hold has been placed; Please choose a library for pickup.', 'reqnum' => $reqnum, 'lib' => $pickupLib);
        } elseif ($code == "722") {
            $result = array('hold' => false, 'reason' => 'We could not place the hold. You already have a hold on this item.', 'reqnum' => $reqnum);
        } elseif ($code == "753") {
            $result = array('hold' => false, 'reason' => 'We could not place the hold. You already have this item checked out.', 'reqnum' => $reqnum);
        } elseif ($code == "447") {
            $result = array('hold' => false, 'reason' => 'We could not place the hold. This item may not be available for circulation. Please contact your library for assistance.', 'reqnum' => $reqnum);
        } elseif ($code == "444") {
            $result = array('hold' => false, 'reason' => 'We could not place the hold. You have exceeded the limit for number of holds per user.', 'reqnum' => $reqnum);
        } elseif ($code == "218") {
            $result = array('hold' => false, 'reason' => 'We could not place the hold. Your library card may be blocked. Please contact your library for assistance', 'reqnum' => $reqnum);
        } else {
            $result = array('hold' => false, 'reason' => 'Hold failed. Please contact your library for assistance.');
        }

        return $result;
    }

    public function renewItem($itemId, $patronId)
    {
        $params = array('query' => 'renew', 'itemId' => $itemId, 'patronId' => $patronId);
        $response = $this->querySirsi($params);

        // process the API response
        if ($response == 'not_charged') {
            return array('error' => 'This item is not checked out');
        }
        if ($response == 'not_charged_by_user') {
            return array('error' => 'This item is not checked out by you');
        }

        $matches = array();
        preg_match('/\^MN([0-9][0-9][0-9])/', $response, $matches);
        if(isset($matches[1])) {
            $status = $matches[1];
            if ($status != '214') {
                return array('error' => $this->ilsConfigArray['ApiMessages'][$status]);
            }
        }

        $data = array();
        preg_match('/\^IB([^\^]+)\^/', $response, $matches);
        if(isset($matches[1])) {
            $data['title'] = $this->toUTF8($matches[1]);
        }
        preg_match('/\^CI([^\^]+)\^/', $response, $matches);
        if(isset($matches[1])) {
            $data['duedate'] = $this->formatDateTime($this->parseApiDateTime($matches[1]));
        }
        preg_match('/\^Up([^\^]+)\^/', $response, $matches);
        if(isset($matches[1])) {
            $data['fines'] = $matches[1];
        }
        preg_match('/\^Uq([^\^]+)\^/', $response, $matches);
        if(isset($matches[1])) {
            $data['overdues'] = $matches[1];
        }

        return $data;
    }

    public function patronLogin($username, $password)
    {
        //query sirsi
        $params = array('query' => 'login', 'patronId' => $username, 'pin' => $password);
        $response = $this->querySirsi($params);

        if (empty($response)) {
            return null;
        }

        list($user_key, $alt_id, $barcode, $name, $library, $profile,
        $cat1, $cat2, $cat3, $cat4, $cat5) = explode('|', $response);

        list($last, $first) = explode(',', $name);
        $first = rtrim($first, " ");

        return array(
            'id' => $username,
            'firstname' => $first,
            'lastname' =>  $last,
            'cat_username' => $username,
            'cat_password' => $password,
            'email' => null,
            'major' => null,
            'college' => null
        );
    }

    public function getMyProfile($patron)
    {
        $username = $patron['cat_username'];
        $password = $patron['cat_password'];

        //query sirsi
        $params = array('query' => 'profile', 'patronId' => $username, 'pin' => $password);
        $response = $this->querySirsi($params);

        list($user_key, $alt_id, $barcode, $name, $library, $profile,
        $cat1, $cat2, $cat3, $cat4, $cat5,
        $email, $address1, $zip, $phone, $address2) = explode('|', $response);

        return array(
            'firstname' => $patron['firstname'],
            'lastname' => $patron['lastname'],
            'address1' => $address1,
            'address2' => $address2,
            'zip' => $zip,
            'phone' => $phone,
            'email' => $email,
            'group' => $profile,
            'library' => $library
        );
    }

    public function getMyFines($patron) 
    {
        $username = $patron['cat_username'];
        $password = $patron['cat_password'];

        $params = array('query' => 'fines', 'patronId' => $username, 'pin' => $password);
        $response = $this->querySirsi($params);
        if (empty($response)) {
            return array();
        }
        $lines = explode("\n", $response);
        $items = array();
        foreach ($lines as $item) {
            list($catkey, $amount, $balance, $date_billed, $number_of_payments,
            $with_items, $reason, $date_charged, $duedate, $date_recalled) = explode('|', $item);

            // the amount and balance are in cents, so we need to turn them into dollars
            $amount = (floatval($amount) / 100.00);
            $balance = (floatval($balance) / 100.00);

            $date_billed = $this->parseDateTime($date_billed);
            $date_charged = $this->parseDateTime($date_charged);
            $duedate = $this->parseDateTime($duedate);
            $date_recalled = $this->parseDateTime($date_recalled);
            $items[] = array(
	            'id' => $catkey,
                'amount' => $amount,
                'balance' => $balance,
                'date_billed' => $date_billed,
                'number_of_payments' => $number_of_payments,
                'with_items' => $with_items,
                'fine' => $reason,
            	'checkout' => $this->formatDateTime($date_charged),
	            'duedate' => $this->formatDateTime($duedate),
                'date_recalled' => $this->formatDateTime($date_recalled)
            );
        }

        return $items;
    }

    public function getMyHolds($patron) 
    {
        $username = $patron['cat_username'];
        $password = $patron['cat_password'];

        $params = array('query' => 'getholds', 'patronId' => $username, 'pin' => $password);
        $response = $this->querySirsi($params);
        if (empty($response)) {
            return array();
        }
        $lines = explode("\n", $response);
        $items = array();
        foreach ($lines as $item) {
            list($catkey, $holdkey, $available, $recall_status, $date_expires, $reserve,
            $date_created, $priority, $type, $pickup_library, $suspend_begin, $suspend_end,
            $date_recalled, $special_request, $date_available, $date_available_expires) = explode('|', $item);

            $date_created = $this->parseDateTime($date_created);
            $date_expires = $this->parseDateTime($date_expires);
            $items[] = array(
	            'id' => $catkey,
            	'reqnum' => $holdkey,
                'available' => $available,
                'expire' => $this->formatDateTime($date_expires),
                'create' => $this->formatDateTime($date_created),
                'type' => $type,
                'location' => $pickup_library
            );
        }

        return $items;
    }

    public function editHold($reqnum, $cancel, $expire, $pickupLib, $suspend, $unsuspend) 
    {
        // query sirsi
        $params = array('query' => 'edithold', 'holdId' => $reqnum, 
        'cancel' => $cancel, 'lib' => $pickupLib, 'expire' => $expire, 
        'suspend' => $suspend, 'unsuspend' => $unsuspend);
        
        $response = $this->querySirsi($params);
        
        return $response;
    }

    public function getMyTransactions($patron) 
    {
        $username = $patron['cat_username'];
        $password = $patron['cat_password'];

        $params = array('query' => 'transactions', 'patronId' => $username, 'pin' => $password);
        $response = $this->querySirsi($params);
        if (empty($response)) {
            return array();
        }
        $item_lines = explode("\n", $response);
        $items = array();
        foreach ($item_lines as $item) {
            list($catkey, $date_charged, $duedate, $date_renewed, $accrued_fine,
            $overdue, $number_of_renewals, $date_recalled) = explode('|', $item);

            $duedate = $this->parseDateTime($duedate);
            $date_recalled = $this->parseDateTime($date_recalled);
            if ($date_recalled !== false) {
                $duedate = $this->calculateRecallDueDate($date_recalled);
            }
            $items[] = array(
	            'id' => $catkey,
            	'date_charged' => $this->formatDateTime($this->parseDateTime($date_charged)),
	            'duedate' => $this->formatDateTime($duedate),
	            'date_renewed' => $this->formatDateTime($this->parseDateTime($date_renewed)),
                'accrued_fine' => $accrued_fine,
                'overdue' => $overdue,
                'number_of_renewals' => $number_of_renewals,
                'date_recalled' => $this->formatDateTime($date_recalled),
                'recall_duedate' => ($date_recalled ? $this->formatDateTime($duedate) : '')
            );
        }

        return $items;
    }

    public function getCourses() 
    {
        //query sirsi
        $params = array('query' => 'courses');
        $response = $this->querySirsi($params);

        $response = rtrim($response);
        $course_lines = split("\n", $response);
        $courses = array();

        foreach ($course_lines as $course) {
            list($id, $name) = explode('|', $course);
            $courses[$id] = $name;
        }
        return $courses;
    }

    public function getInstructors() 
    {
        //query sirsi
        $params = array('query' => 'instructors');
        $response = $this->querySirsi($params);

        $response = rtrim($response);
        $user_lines = split("\n", $response);
        $users = array();

        foreach ($user_lines as $user) {
            list($id, $name) = explode('|', $user);
            $users[$id] = $name;
        }
        return $users;
    }

    public function getDepartments() 
    {
        //query sirsi
        $params = array('query' => 'desks');
        $response = $this->querySirsi($params);

        $response = rtrim($response);
        $dept_lines = split("\n", $response);
        $depts = array();

        foreach ($dept_lines as $dept) {
            $dept = rtrim($dept, '\|');
            $depts[$dept] = $dept;
        }
        return $depts;
    }

    public function findReserves($courseId, $instructorId, $departmentId) 
    {
        //query sirsi
        if ($courseId) {
            $params = array('query' => 'reserves', 'course' => $courseId, 'instructor' => '', 'desk' => '');
        } elseif ($instructorId) {
            $params = array('query' => 'reserves', 'course' => '', 'instructor' => $instructorId, 'desk' => '');
        } elseif ($departmentId) {
            $params = array('query' => 'reserves', 'course' => '', 'instructor' => '', 'desk' => $departmentId);
        }

        $response = $this->querySirsi($params);

        $item_lines = split("\n", $response);
        $items = array();
        $count = 0;
        foreach ($item_lines as $item) {
            $id = rtrim($item, "|");
            $record = $this->db->getRecordByCtrl($id);
            $items[$count] = array (
	       'BIB_ID' => $record['id']
            //	       'BIB_ID' => $id
            );
            $count++;
        }
        return $items;
    }

    public function getNewItems($page, $limit, $daysOld, $lib = null, $fundID = null)
    {
        //query sirsi
        //	isset($lib) ? $params = array('query' => 'newItems', 'lib' => array_search($lib, $ilsConfigArray['Libraries'])) : $params = array('query' => 'newItems');
        $params = array('query' => 'newitems', 'lib' => 'PPL');
        $response = $this->querySirsi($params);

        $item_lines = split("\n", rtrim($response));

        $rescount = 0;
        foreach ($item_lines as $item) {
            $item = rtrim($item, '|');
            $items[$item] = array (
	  	'id' => $item
            );
            $rescount++;
        }

        $results = array_slice($items, ($page - 1) * $limit, ($page * limit)-1);
        return array('count' => $rescount, 'results' => $results);
    }

    public function getSuppressed()
    {
        $params = array('query' => 'shadowed');
        $response = $this->querySirsi($params);

        $record_lines = split("\n", rtrim($response));
        $records = array();
        foreach ($record_lines as $record) {
            $record = rtrim($record, '|');
            $records[] = $record;
        }

        return $records;
    }

    /**
     * Parse a pipe-delimited status line received from the script on the Unicorn/Symphony server.
     *
     * @param   string $line    The pipe-delimited status line to parse.
     *
     * @return  array           Associative array of holding information
     */
    protected function parseStatusLine($line)
    {
        list($catkey, $shelving_key, $callnum, $location_code, $reserve,
        $library_code, $barcode, $number_of_charges, $current_location_code,
        $item_type, $recirculate_flag, $holdcount, $itemkey1, $itemkey2, $itemkey3,
        $holdable, $circulation_rule, $duedate, $date_recalled, $format) = explode("|", $line);

        // availability
        $availability = ($number_of_charges == 0) ? 1 : 0;

        // due date (if checked out)
        $duedate = $this->parseDateTime(trim($duedate));

        // date recalled
        $date_recalled = $this->parseDateTime(trim($date_recalled));

        // a recalled item has a new due date, we have to calculate that new due date
        if ($date_recalled !== false) {
            $duedate = $this->calculateRecallDueDate($date_recalled);
        }

        // item status
        $status = ($availability) ? 'Available' : 'Checked Out';

        // even though item is NOT checked out, it still may not be "Available"
        // the following are the special cases
        if (isset($this->ilsConfigArray['UnavailableItemTypes'])
        && isset($this->ilsConfigArray['UnavailableItemTypes'][$item_type])) {
            $availability = 0;
            $status = $this->ilsConfigArray['UnavailableItemTypes'][$item_type];
        } else if (isset($this->ilsConfigArray['UnavailableLocations'])
        && isset($this->ilsConfigArray['UnavailableLocations'][$current_location_code])) {
            $availability = 0;
            $status = $this->ilsConfigArray['UnavailableLocations'][$current_location_code];
        }

        $item = array (
            'status' => $status,
            'availability' => $availability,
            'id' => $catkey,
            'number' => $itemkey3, // copy number
            'duedate' => $this->formatDateTime($duedate),
            'callnumber' => $callnum,
            'reserve' => ($reserve == '0') ? 'N' : 'Y',
        	'location_code' => $location_code,
            'location' => $this->mapLocation($location_code),
            'library_code' => $library_code,
    		'library' => $this->mapLibrary($library_code),
    		'barcode' => trim($barcode),
        	'holdable' => $holdable,
        	'holdcount' => $holdcount,
            'current_location_code' => $current_location_code,
            'current_location' => $this->mapLocation($current_location_code), 
            'item_type' => $item_type,
            'recirculate_flag' => $recirculate_flag,
            'shelving_key' => $shelving_key,
            'circulation_rule' => $circulation_rule,
            'date_recalled' => $this->formatDateTime($date_recalled),
            'item_key' => $itemkey1 . '|' . $itemkey2 . '|' . $itemkey3 . '|',
            'format' => $format
            );

            return $item;
    }

    /**
     * Map the location code to friendly name.
     *
     * @param string $code    The location code from Unicorn/Symphony
     *
     * @return string         The friendly name if defined, otherwise the code is returned.
     */
    protected function mapLocation($code)
    {
        if (isset($this->ilsConfigArray['Locations'])
        && isset($this->ilsConfigArray['Locations'][$code])) {
            return $this->ilsConfigArray['Locations'][$code];
        }
        return $code;
    }

    /**
     * Maps the library code to friendly library name.
     *
     * @param string $code    The library code from Unicorn/Symphony
     *
     * @return string         The library friendly name if defined, otherwise the code is returned.
     */
    protected function mapLibrary($code)
    {
        if (isset($this->ilsConfigArray['Libraries'])
        && isset($this->ilsConfigArray['Libraries'][$code])) {
            return $this->ilsConfigArray['Libraries'][$code];
        }
        return $code;
    }

    /**
     * Send a request to the SIRSI side API script and returns the response.
     *
     * @param array $params Associative array of query parameters to send.
     */
    protected function querySirsi($params)
    {
        $url = $this->url;
        if (empty($url)) {
            $url = $this->host;
            if ($this->port) {
                $url =  "http://" . $url . ":" . $this->port . "/" . $this->search_prog;
            } else {
                $url =  "http://" . $url . "/" . $this->search_prog;
            }
        }

        $httpClient = new Proxy_Request();
        // use HTTP POST so parameters like user id and PIN are NOT logged by web servers
        $httpClient->setMethod(HTTP_REQUEST_METHOD_POST);
        $httpClient->setURL($url);
        $httpClient->setBody(http_build_query($params));
        $result = $httpClient->sendRequest();

        if (!PEAR::isError($result)) {
            // Even if we get a response, make sure it's a 'good' one.
            if ($httpClient->getResponseCode() != 200) {
                PEAR::raiseError("Error response code received from $url");
            }
        } else {
            PEAR::raiseError($result);
        }

        // get the response data
        $response = $httpClient->getResponseBody();

        return rtrim($response);
    }

    /**
     * Take a date/time string from SIRSI seltool and convert it into unix time stamp.
     *
     * @param  string $date  The input date string. Expected format YYYYMMDDHHMM.
     * @return int Unix time stamp if successful, false otherwise.
     */
    private function parseDateTime($date)
    {
        if (strlen($date) >= 8) {
            // format is MM/DD/YYYY HH:MI so it can be passed to strtotime
            $formatted_date = substr($date, 4, 2).'/'.substr($date, 6, 2).'/'.substr($date, 0, 4);
            if (strlen($date) > 8) {
                $formatted_date .= ' ' . substr($date, 8, 2) . ':' . substr($date, 10);
            }
            return strtotime($formatted_date);
        }
        return false;
    }

    /**
     * Take a date/time string from SIRSI API response and convert it into unix time stamp.
     *
     * @param  string $date  The input date string. Expected format 10/6/2011,23:59.
     * @return int Unix time stamp if successful, false otherwise.
     */
    private function parseApiDateTime($date)
    {
        list($date, $time) = explode(',', $date);

        if (!empty($date)) {
            list($day, $month, $year) = explode('/', $date);
            $formatted_date = $month . '/' . $day . '/' . $year . ' ' . $time;
            return strtotime($formatted_date);
        }
        return false;
    }

    /**
     * Given the date recalled, calculate the new due date based on circulation policy.
     *
     * @param int $date_recalled Unix time stamp of when the recall was issued.
     * @return int New due date as unix time stamp.
     */
    private function calculateRecallDueDate($date_recalled)
    {
        if ($date_recalled) {
            $recall_due_period = $this->ilsConfigArray['CirculationPolicies']['recall_due_period'];
            $duedate = $date_recalled + ($recall_due_period * 24 * 60 * 60);
            return $duedate;
        }
        return false;
    }

    /**
     * Format the given unix time stamp to a human readable format. The format is
     * configurable in Unicorn.ini
     *
     * @param int $time Unix time stamp.
     * @return string Formatted date/time.
     */
    private function formatDateTime($time)
    {
        $format = $this->ilsConfigArray['DateTimeFormats']['default'];
        return $time ? strftime($format, $time) : '';
    }

    /**
     * Convert the given ISO-8859-1 string to UTF-8 if it is not already UTF-8.
     *
     * @param string $s The string to convert.
     * @return string   The input string converted to UTF-8
     */
    private function toUTF8($s)
    {
        return (mb_detect_encoding($s, 'UTF-8') == 'UTF-8') ? $s : utf8_encode($s);
    }

    /**
     * Get textual holdings summary.
     *
     * @param $marc string The raw marc holdings records.
     * @return array       Array of holdings data indexed by library.
     * @access private
     */
    private function getMarcHoldings($marc)
    {
        $records = array();
        $count = 0;
        $file = new File_MARC($marc, File_MARC::SOURCE_STRING);
        while($marc = $file->next()) {
            $fields = $marc->getFields('852|866', true);
            foreach ($fields as $field) {
                if ($field->getTag() == '852') {
                    $location = $field->getSubfield('c')->getData();
                    $records[] = array(
                    	'library' => $this->mapLibrary($location),
                        'location_code' => $location,
                        'location' => $this->mapLocation($location),
                        'marc852' => $field,
                        'marc866' => array(),
                        'textual_holdings' => array()
                    );
                    $count++;
                } else {
                    if ($count > 0) {
                        $holdings = '';
                        $subfields = $field->getSubfields();
                        foreach($subfields as $subfield) {
                            if ($subfield->getCode() != 'x') {
                                $holdings .= $subfield->getData() . ' ';
                            }
                        }
                        $records[$count - 1]['marc866'][] = $field;
                        $records[$count - 1]['textual_holdings'][] = trim($holdings);
                    }
                }
            }
        }
        return $records;
    }
}
?>
