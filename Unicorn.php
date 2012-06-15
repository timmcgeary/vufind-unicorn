<?php

require_once 'PEAR.php';
require_once 'Interface.php';
require_once 'File/MARCXML.php';

class Unicorn implements DriverInterface{
  private $config;
  public $debug_cgi = false;

	public function __construct() {
    		$this->config = parse_ini_file('conf/Unicorn.ini', true);
	} // __construct


	private function translate_location($loc_code) {
		$loc_code = str_replace('&', '-', $loc_code);
		return $this->config['locations'][$loc_code];
 	}

	private function translate_library($libr) {
		return $this->config['libraries'][$libr];
	}

	  /**
   * The universal method for interacting with the Perl CGI script.
   */
  private function curlperl($params) {
    $url = $this->config['server']['cgi_url'];
    $params['k'] = $this->config['server']['cgi_key'];
    $curlopt = array(
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $params,
    );

    # If cURL and/or the Perl script is failing,
    # we have major issues and should stop immediately.
    if(!$ch = curl_init($url))
      throw new Exception('curl_init');
    if(!curl_setopt_array($ch, $curlopt))
      throw new Exception('curl_setopt: ' . curl_error($ch));
    if(($response = curl_exec($ch)) === FALSE)
      throw new Exception('curl_exec: ' . curl_error($ch));
    if(substr($response, 0, 5) == "ERROR")
      throw new Exception('Perl script failed, ' . $response);

    if($this->debug_cgi) {
      print "---DEBUG---\n";
      print "$response\n";
      print "----END----\n";
    } // if
    return $response;
  } // curlperl

	public function getStatus($id) {
	  $statuses = $this->getStatuses(array($id));
	  return $statuses[$id];
	} // getStatus

  public function getStatuses($ids) {
    $catkeys = implode('|', $ids);
    try {
      $response = $this->curlperl(array('s' => 'holdings', 'catkeys' => $catkeys));
    } catch (Exception $e) {
      $response = '';
    } // try

    $items = array();
    foreach($ids as $id) {
      $items[$id] = array();
    } // foreach

    $item_lines = explode("\n", $response);  

    foreach($item_lines as $item_line) {
      list($boundwith_catkey, $boundwith_seq, $copynum,
           $catkey, $seq,
           $callnum, $reserve_status, $barcode,
           $nr_charges, $home_loc, $curr_loc_raw, $library, $duedate) = explode("|", $item_line);

      if($duedate == '0') {
        $duedate = NULL;
      } elseif($duedate == 'NEVER') {
        $duedate = 'Never';
      } else {
        $duedate = preg_replace('/(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)/', '$1-$2-$3', $duedate);   
      } // if

      $reserve = $reserve_status == 'NOT_ON_RES' || $reserve_status == 'KEEP_DESK' ? 'N' : 'Y';

	$offsite = $library == 'OFFSITE' ? true : false;

      $home_loc = $this->translate_location($home_loc);
      $curr_loc = $this->translate_location($curr_loc_raw);
      $library = $this->translate_library($library);   
      $location = "$library - $home_loc";

      if($home_loc == $curr_loc){
            $avail = TRUE;
      } else if($duedate == NULL && ($reserve == "Y" || $curr_loc_raw == "OPEN-EXHIB")) {
            $avail = TRUE;
            $location = "$library - $curr_loc";
      } else {
            $avail = FALSE;
      }

      $item = array(
        'id' => $catkey,
        'availability' => $avail,
        'status' => $curr_loc,
        'location' => $location,
        'reserve' => $reserve,
        'callnumber' => $callnum,
        'duedate' => $duedate,
        'number' => $copynum,
        'barcode' => trim($barcode),
        'unicorn_boundwith' => $boundwith_catkey,
        'unicorn_callseq' => $seq,
	  'offsite' => $offsite,
      );

      $items[$catkey][] = $item;
    } // foreach    

    return $items;
  } // getStatuses

  public function getHolding($id, $patron=false) {
    return $this->getStatus($id);    
  } // getHolding

  public function getPurchaseHistory($id) {
    return array();
  } // getPurchaseHistory
} // Unicorn
?>
