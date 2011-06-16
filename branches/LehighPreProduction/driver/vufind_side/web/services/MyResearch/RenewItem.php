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

require_once 'services/MyResearch/MyResearch.php';

class RenewItem extends MyResearch
{
    function launch()
    {
        global $interface;
		

        //GET ID OF THE ITEM 
        $id = $_GET['id'];
        
    
        $patron = $this->catalogLogin();
        $transList = array();
        $results = $this->catalog->renewMyItem($patron,$id);
        $interface->assign('renewalMessage',$results);
        $transList = $this->catalog->getMyTransactions($patron);
        $interface->assign('transList', $transList);
        $interface->setTemplate('checkedout.tpl');        
        $interface->setPageTitle('Checked Out Items');
        $interface->display('layout.tpl');
    }
    
   
}

?>