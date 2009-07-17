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

    function __construct()
    {
        // Load Configuration for this Module
        $configArray = parse_ini_file('conf/Unicorn.ini', true);

        $this->host = $configArray['Catalog']['host'];
        $this->port = $configArray['Catalog']['port'];
        $this->search_prog = $configArray['Catalog']['search_prog'];
    }


    public function getStatus($id)
    {
        $params = array('search' => 'single', 'id' => $id);
        $status_lines = split("\n", rtrim($this->search_sirsi($params)));
        return $this->fillStatus($status_lines, $id);
    }

    public function getStatuses($idList)
    {
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
            $call_num = $lineparts[1];
            $location = $lineparts[2];
            $status = "Available";
            $availability = 1 - $lineparts[4];
            $unparsed_date = $lineparts[5];
            if ($unparsed_date != 0) {
                $date = substr($unparsed_date, 4, 2).'/'.substr($unparsed_date, 6, 2).'/'.substr($unparsed_date, 0, 4);
            } else {
                // TODO: What does VuFind expect when there is no due date?
                $date = '';
            }
            $reserve = $lineparts[3];

            if ($availability == 0) {
                if ($location == "Not available") {
                    $status = "Not available"; // API lookup failed
                    // Custom lost location processing here
                } else {
                    $status = "Checked Out";
                }
            }

            $count++;
            $holdings[] = array (
                    'status' => $status,
                    'availability' => $availability,
                    'id' => $id,
                    'number' => $count,
                    'duedate' => $date,
                    'callnumber' => $call_num,
                    'reserve' => $reserve,
                    'location' => $location,
                    );
        }
        return $holdings;

    } // end fillStatus


    public function search_sirsi($params)
    {
        $url = $this->build_query($params);
        $response = file_get_contents($url);
        return $response;
    }

    public function build_query($params)
    {
        $url = $this->host;

        if ($this->port) {
            $url =  "http://" . $url . ":" . $this->port . "/" . $this->search_prog;
        } else {
            $url =  "http://" . $url . "/" . $this->search_prog;
        }

        $url = $url . '?' . http_build_query($params);

        return $url;
    }

    public function getHolding($id)
    {
        return $this->getStatus($id);
    }

    public function getPurchaseHistory($id)
    {
        return array();
    }
}

?>
