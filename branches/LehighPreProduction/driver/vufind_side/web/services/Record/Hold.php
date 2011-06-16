<?php
/**
 *
 * Copyright (C) Villanova University 2007.
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
 
require_once 'CatalogConnection.php';

require_once 'Action.php';

require_once 'services/MyResearch/MyResearch.php';
require_once 'services/MyResearch/lib/User.php';

//for place hold email
require_once 'sys/Mailer.php';
//

class Hold extends Action
{
    var $catalog;

    function launch() {
    	global $user;
    	global $interface;
   		if (!$user) {
            $interface->assign('followup', true);
            $interface->assign('followupModule', 'Record/'.$_GET['id'].'/Hold');
            $interface->setPageTitle('To place this item on hold, please log in.');
            $interface->assign('subTemplate', '../MyResearch/login.tpl');
            $interface->setTemplate('view-alt.tpl');
            $interface->display('layout.tpl', 'UserComments' . $_GET['id']);
            exit();
      	}
      	
        $this->placeHold($_GET['id']);
    }
    
    function placeHold($itemId)
    {
        global $interface;
        global $configArray;
        global $user;
        if (!UserAccount::isLoggedIn()) {
            if (PEAR::isError($patron))
                PEAR::raiseError($patron);
        }
        $patron = $user;
        $this->catalog = new CatalogConnection($configArray['Catalog']['driver']);
        //added feature...subsequently removed (delivery) - maybe future
        $deliveryIndicator = false;
        //
        $holdResponseMessage = $this->catalog->placeHold($patron,$itemId,$deliveryIndicator);
			
        $interface->assign('holdMessage',$holdResponseMessage);
		$transList = $this->catalog->getMyHolds($patron);
        $interface->assign('recordList', $transList);
        
        $interface->setTemplate('../MyResearch/holds.tpl');
        $interface->setPageTitle('My Holds');
        $interface->display('layout.tpl');
    }
}

?>