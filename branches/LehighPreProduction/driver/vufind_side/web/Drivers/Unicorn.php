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

class Unicorn implements DriverInterface
{
    private $host;
    private $port;
    private $search_prog;
    private $renewal_prog;
    private $fines_prog;
    private $recall_prog;
    private $holds_prog;
    private $placehold_prog;
    private $removehold_prog;
    private $serial_holdings;
    private $hashKey;

    function __construct()
    {
        // Load Configuration for this Module
        $configArray = parse_ini_file('conf/Unicorn.ini', true);

        $this->host = $configArray['Catalog']['host'];
        $this->port = $configArray['Catalog']['port'];
        $this->search_prog = $configArray['Catalog']['search_prog'];
        $this->charges_prog = $configArray['Catalog']['charges_prog'];
        $this->holds_prog = $configArray['Catalog']['holds_prog'];
        $this->placehold_prog = $configArray['Catalog']['placehold_prog'];
        $this->removehold_prog = $configArray['Catalog']['removehold_prog'];
        $this->profile_prog = $configArray['Catalog']['profile_prog'];
        $this->renewal_prog = $configArray['Catalog']['renewal_prog'];
        $this->fines_prog = $configArray['Catalog']['fines_prog'];
        $this->recall_prog = $configArray['Catalog']['recall_prog'];
        $this->serial_holdings =  $configArray['Catalog']['serialHoldings_prog'];
        $this->show_library = $configArray['Catalog']['show_library'];
        $this->show_library_format = $configArray['Catalog']['show_library_format'];
        $this->hashKey = $configArray['Catalog']['hashKey'];
    }
    
    
    //YOUR ACCONT - PROFILE
    
    public function getMyProfile($patron) {
    	 $userid = $this->getPlainTextUserId($patron);
    	 $params = array('id'=>$userid);
    	 $profile = $this->callProfileScript($params);
    	 return $this->parseProfile($profile,$id);
    }
    
 	private function callProfileScript($params) {
    	$url = $this->build_query($params,$this->profile_prog);
    	$response = file_get_contents($url);
    	return $response;
    }
    
	private function parseProfile($profile,$id) {
    	$profileArray = @explode("|",$profile);
    	$nameArray = @explode(",",$profileArray[0]);
    	$profile= array('lastname' => $nameArray[0],
                                'firstname' => $nameArray[1],
                                'address1' => $profileArray[2],
                                'address2' => $profileArray[3],
                                'zip' => $profileArray[4],
                                'phone' => $profileArray[6],
                                'group' => $profileArray[1]);

    	return $profile;
    }
    
    //END YOUR ACCOUNT - PROFILE
    
    
    //YOUR ACCOUNT - FINES
    
    public function getMyFines($patron) {
    	$userid = $this->getPlainTextUserId($patron);
    	$params = array('id'=>$userid);
    	$resultMessage = $this->callFinesScript($params);
    	return $resultMessage;
    }
    
	private function callFinesScript($params) {
    	$url = $this->build_query($params, $this->fines_prog);
    	$response = file_get_contents($url);
    	$response = $this->parseFines($response);
    	return $response;
    }
    
    private function parseFines($response) {
    	$responseArray = explode("^", $response);
    	$counter = 0;
    	foreach ($responseArray as $fine) {
    	   //don't look at the first row of results.
    	   //The first row consists only of labels
    		if ($counter > 0) {
    			$fineInfoArray = explode("|",$fine);
    			if ($fineInfoArray[0]!="") {
    			  $balance = $fineInfoArray[4];
    			  $itemID = $fineInfoArray[0];
    			  $reason = $fineInfoArray[6];
    			  $unparsed_date = $fineInfoArray[5];
				  if ($unparsed_date != 0) {
    			  		$billDate = substr($unparsed_date, 4, 2).'/'.substr($unparsed_date, 6, 2).'/'.substr($unparsed_date, 0, 4);
				  }
				  else {
				  	$billDate = "";
				  }
    			  $fine = $fineInfoArray[3];
    			  $fineList[] = array('id' => $itemID,
                                    'reason' => $reason,
                                    'balance' => $balance,
                                    'billdate' => $billDate,
                                    'fine' => $fine);
    			}
    		}
    		else $counter++;
    	}
    	return $fineList;
    }
    
    //END YOUR ACCOUNT - FINES
    
    
    //YOUR ACCOUNT - RENEW AN ITEM
    public function renewMyItem($patron,$itemId) {
    	$userid = $this->getPlainTextUserId($patron);
    	$params = array('id'=>$userid,'itemid'=>$itemId);
    	$resultMessage = $this->callRenewScript($params);
    	return $resultMessage;
    }
    
	private function callRenewScript($params) {
    	$url = $this->build_query($params, $this->renewal_prog);
    	$response = file_get_contents($url);
    	$response = $this->parseRenewal($response);
    	return $response;
    }
    
    private function parseRenewal($response) {
    	$responseArray = explode("^", $response);
    	$responseCode = $responseArray[2];
    	$responseCode = substr($responseCode,2);
    	$title = $responseArray [10];
    	$title = substr($title, 2);
    	$responseString = "";
    	//TODO:
    	//MAKE THIS CODE MORE DYNAMIC - CONFIG.
    	if ($responseCode=="214") $responseString = "$title has been renewed.";
    	else if ($responseCode="11") $responseString = "The item you specificed is not charged.";
    	else if ($responseCode="7") $responseString = "The item you selected was not found in the catalog";
    	else $responseString = "UNKNOWN ERROR CODE: $responseCode"; 
    	return $responseString;
    }
    
    //END YOUR ACCOUNT - RENEW
    
    
    
    //RECALL
 
    public function recall($patron,$itemId) {
    	$userid = $this->getPlainTextUserId($patron);
    	$params = array('id'=>$userid,'itemid'=>$itemId);
    	$resultMessage = $this->callRecallScript($params);
    	return $resultMessage;
    }
    
    private function callRecallScript($params) {
    	$url = $this->build_query($params, $this->recall_prog);
    	$response = file_get_contents($url);
    	$responseMessage = $this->parseRecall($response);
    	return $responseMessage;
    }
    
    private function parseRecall($response) {
    	$responseArray = explode("^", $response);
    	$responseCode = $responseArray[2];
    	$title = $responseArray [10];
    	$title = substr($title, 2);
    	$responseString = "";
    	//TODO:
    	//MAKE THIS CODE MORE DYNAMIC - CONFIG.
    	if ($responseCode=="MN722") $responseString = "You already have this item placed on hold or recalled.  You may not place a duplicate hold.";
    	if ($responseCode=="MN209") $responseString = "The item has been recalled.";
    	return $responseString;
    }
    
    //END RECALL
    
   
    //YOUR ACCOUNT - CHECKED OUT ITEMS
    public function getMyTransactions($patron) {
    	$userid = $this->getPlainTextUserId($patron);
    	$params = array('id'=>$userid);
    	$charges = split("\n", rtrim($this->callChargesScript($params)));
    	return $this->parseCharges($charges,$id);
    }
    
	private function callChargesScript($params) {
    	$url = $this->build_query($params,$this->charges_prog);
    	$response = file_get_contents($url);
    	return $response;
    }
    
    private function parseCharges($charges,$id) {
    	$count = 0;
    	foreach ($charges as $charge) {
    	   //don't look at the first row of results.
    	   //The first row consists only of labels
    	   if ($count > 0) {
    	   	    $chargedArray = explode("|",$charge);
    	   	    //TODO:
    	   	    //HAVE TO BREAK THE ISBN DOWN FROM
    	   	    //EXAMPLE: 2938472398: 24.95
    	   	    //IS THERE A BETTER WAY TO GET ISBN
    	   	    //IF WE HAVE ISBN - A CHANCE FOR IMAGE TO BE ON PAGE
    	   	    $isbnPlus = $chargedArray[9];
    	   	    $isbnArray = explode(":",$isbnPlus);
    			$transList[] = array('id'     =>$chargedArray[0],
        					 'author' =>$chargedArray[8],
        					 'title'  =>$chargedArray[7],
        					 'itemid' =>$chargedArray[10],
    						 'isbn' =>$isbnArray[0],
        					 'duedate' =>date("m/d/Y", strtotime($chargedArray[3])));
    	   }
    	$count++;
    	}
    	return $transList;
    }
    //END YOUR ACCOUNT - CHECKED OUT ITEMS
    
    
    //YOUR ACCOUNT - HOLDS AND RECALLS
    public function getMyHolds($patron) {
    	$userid = $this->getPlainTextUserId($patron);
    	$params = array('id'=>$userid);
    	$holds = split("\n", rtrim($this->callHoldsScript($params)));
    	return $this->parseHolds($holds,$id);
    }
    
     
 	public function callHoldsScript($params) {
    	$url = $this->build_query($params,$this->holds_prog);
    	$response = file_get_contents($url);
    	return $response;
    }
    
    public function parseHolds($holds,$id) {
    	$count = 0;
    	foreach ($holds as $hold) {
    	   //don't look at the first row of results.
    	   //The first row consists only of labels
    	   if ($count!=0) {
    	   	    $holdsArray = explode("|",$hold);
    	   	    $expireDate = $holdsArray[8];
    	   	    if ($expireDate != "NEVER") {
    	   	    	$expireDate = date("m/d/Y", strtotime($expireDate));
    	   	    }
    	   	    $isbnPlus = $holdsArray[9];
    	   	    $isbnArray = explode(":",$isbnPlus);
    			$transList[] = array('id'     =>$holdsArray[0],
        					 'author' =>$holdsArray[4],
        					 'title'  =>$holdsArray[3],
    						 'holdid' =>$holdsArray[13],
    					  	 'barcode' =>$holdsArray[14],
        					 'expiredate' =>$expireDate);
    	   }
    	$count++;
    	}
    	return $transList;
    }
    
    //END YOUR ACCOUNT - HOLDS/RECALLS
    
    //SERIAL HOLDINGS:
 	public function getSerialHoldings($id) {
    	$params = array('id' => $id);
    	$url = $this->build_query($params, $this->serial_holdings);
    	$response = file_get_contents($url);
    	$response = $this->parseSerialHoldings($response);
    	return $response;
    }
    
    
    private function parseSerialHoldings($responseString) {
     	$returnArray = array();
  	    $serialHoldingsArray = explode("*** DOCUMENT BOUNDARY ***FORM=BIBHOLD.", $responseString);
  	    foreach ($serialHoldingsArray as $value) {
  		   $recordPosition = strpos($value, "866.");
  		   $valueWeNeed = substr($value, $recordPosition);
  		   $zValue = strpos($valueWeNeed, "|z");
  		   $aValue = strpos($valueWeNeed, "|a");
  		   if ($zValue !== false) {
  			   $z = substr($valueWeNeed, $zValue+2);
  			   array_push($returnArray, $z);
  		    }
  		    else if ($aValue !== false) {
  			     $a = substr($valueWeNeed, $aValue+2);
  			     array_push($returnArray, $a);
  		   }
     	}
  	return $returnArray;
    }
    
    //END SERIAL HOLDSINGS
    
    
    //PLACE HOLD
    public function placeHold($patron,$itemId,$deliveryIndicator) {
  		$userid = $this->getPlainTextUserId($patron);
    	$params = array('id'=>$userid,'itemid'=>$itemId,'delivery'=>$deliveryIndicator,'firstName'=>$patron->firstname,'lastName'=>$patron->lastname);
    	$resultMessage = $this->callHoldScript($params);
    	return $resultMessage;
    }
    
	private function callHoldScript($params) {
    	$url = $this->build_query($params,$this->placehold_prog);
    	$response = file_get_contents($url);
    	$response = $this->parseHoldResponse($response);
    	return $response;
    }
    
    private function parseHoldResponse($response) {
    	$responseArray = explode("^", $response);
    	$responseCode = $responseArray[2];
    	$responseCode = substr($responseCode,2);
    	$title = $responseArray [10];
    	$title = substr($title, 2);
    	$responseString = "";
    	//TODO:
    	//MAKE THIS CODE MORE DYNAMIC -- CONFIG.
    	//response codes:
    	if ($responseCode=="9267") { 
    	    $responseString = "A hold request has been made for $title.";
    	}
    	else $responseString = "UNKNOWN ERROR CODE: $responseCode"; 
    	return $responseString;
    }
    //END PLACE HOLD
    
    
    
    //REMOVE HOLD
    public function removeHold($patron,$itemId,$holdId) {
    	$userid = $this->getPlainTextUserId($patron);
    	$params = array('uid'=>$userid,'itemid'=>$itemId,'holdid'=>$holdId);
    	$resultMessage = $this->callRemoveHoldScript($params);
    	return $resultMessage;
    }
    
    private function callRemoveHoldScript($params) {
    	$url = $this->build_query($params, $this->removehold_prog);
    	$response = file_get_contents($url);
    	$responseMessage = $this->parseRemoveHold($response);
    	return $responseMessage;
    }
    
    private function parseRemoveHold($response) {
    	//LOOKING FOR RESPONSE CODE 17292
    	$responseArray = explode("^", $response);
    	$responseCode = $responseArray[2];
    	$responseCode = substr($responseCode,2);
    	//TODO: RESPONSE CODES SHOULD BE IN CONFIGURATION
    	if ($responseCode=="17292") $responseString = "The hold has been removed.";
        else if ($responseCode=="17291") $responseString = "The hold has already been removed.";
        else $responseString = "UNKNOWN ERROR CODE: $responseCode"; 
    	return $responseString;
    }
    
    //END REMOVE HOLD
   

 
    //DYNAMIC STATUS/LOCATION
    public function getStatus($id) {
        $params = array('search' => 'single', 'id' => $id);
        $status_lines = split("\n", rtrim($this->search_sirsi($params)));
        return $this->fillStatus($status_lines, $id);
    }

    public function getStatuses($idList) {
        $statuses = array();
        $params = array('search' => 'multiple', 'ids' => implode("|",$idList));
        $status_groups = split("\n\n", rtrim($this->search_sirsi($params)));
        for ($i = 0; $i < count($status_groups); $i++) {
            $status_lines = split("\n", $status_groups[$i]);
            $statuses[] = $this->fillStatus($status_lines, $idList[$i]);
        }
        return $statuses;
    }

    /* fillStatus
     * 
     * Arguments
     *   $status_lines - array of pipe-delimited status lines
     *                   from a script on the Unicorn server
     *   $id - ID number which was sent to Unicorn
     *         and which resulted in the above status lines
     * 
     * Returns
     *   $holdings - array of holdings information as needed
     *               for VUFind display
     */
    public function fillStatus($status_lines, $id)
    {
        $count = 0;
        foreach ($status_lines as $line) {
            $line = rtrim($line, "|");
            $lineparts = split("\|", $line);
            $call_num = $lineparts[2];
			$location = $lineparts[4];
			$homeLocation = $lineparts[5];
			$holdid = trim($lineparts[9]);
            $status = "Available";
			$availability = 1 - $lineparts[7];
            if ($this->show_library == 1) {
                $library = $lineparts[8];
                $full_location = $this->show_library_format;
                $full_location = str_replace('#library#', $library, $full_location);
                $full_location = str_replace('#location#', $location, $full_location);
                $location = $full_location;
            }
			$unparsed_date = $lineparts[10];
			$date = date("m/d/Y", strtotime($unparsed_date));
            $reserve = $lineparts[6];

            if ($availability == 0) {
                if ($location == "Not available") {
                    $status = "Not available"; // API lookup failed
                } else {
                    $status = "Checked Out";
                }
            }

			if ($availability == 0 && $unparsed_date == 0) {
				$status = $homeLocation;
			}

            $count++;
            $holdings[] = array (
                    'status' => $status,
                    'availability' => $availability,
                    'id' => $id,
                    'number' => $count,
                    'duedate' => $date,
                    'callnumber' => utf8_encode($call_num),
                    'reserve' => $reserve,
                    'location' => $location,
					#new
					'holdid' => $holdid,
					'homelocation' => $homeLocation,
                    );
        }
        return $holdings;

    } // end fillStatus


    public function search_sirsi($params)
    {
        $url = $this->build_query($params,$this->search_prog);
        $response = file_get_contents($url);
        return $response;
    }
    
    //END DYNAMIC STATUS/LOCATION
    
    
    public function build_query($params,$script)  {
        $url = $this->host;

        if ($this->port) {
            $url =  "http://" . $url . ":" . $this->port . "/" . $script;
        } else {
            $url =  "http://" . $url . "/" . $script;
        }

        $url = $url . '?' . http_build_query($params);
		
        return $url;
    }
    
    /**
	* @param Patron 
    *
	* @return string  Translated (plain text) Userid from encrypted userid in the database
	* @access private
    */
    private function getPlainTextUserId($patron) {
    	$useridEncrypted = $patron->cat_username;
    	$key = $this->hashKey;
    	$userid = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($key), base64_decode($useridEncrypted), MCRYPT_MODE_CBC, md5(md5($key))), "\0");
    	return $userid;
    }
    
    
    
    //CODE FROM ORIGINAL DRIVER:
    
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
    
    //END CODE FROM ORIGINAL DRIVER
}

?>
