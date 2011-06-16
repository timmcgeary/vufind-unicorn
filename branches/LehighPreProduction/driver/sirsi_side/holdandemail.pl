#!/s/sirsi/Unicorn/Bin/perl
# holdandemail.pl
# 16 February 2011
# 

# 
# Arguments:

#
# Returns:

#


use CGI;
use strict;

# change these paths to match your system!
my $unicorn_base_path = '/s/sirsi/Unicorn';
$ENV{'UPATH'} = "$unicorn_base_path/Config/upath";
$ENV{'PATH'} = "$unicorn_base_path/Search/Bin:$unicorn_base_path/Bincustom:$unicorn_base_path/Bin:$ENV{'PATH'}";
$ENV{'BRSConfig'}= "$unicorn_base_path/Config/brspath";
$ENV{'SIRSI_LANG'} = "";
$ENV{'KRB_CONF'} = "";

my $query = new CGI;



print $query->header('text/html'); 

my $null = '2>/dev/null';
my $uid = $query->param('id');
my $itemid = $query->param('itemid');
my $deliveryIndicator = $query->param('delivery');
my $firstName = $query->param('firstName');
my $lastName = $query->param('lastName');
my $holdings;
my $comments = "Hold Request from the VuFind System";

if ($deliveryIndicator eq "1") {
  $comments = "**Please deliver to " . $firstName ." ".$lastName."**";
}

#STEP #1 - LOOK UP ITEM FROM CAT KEY 
chomp(my $item = `echo "$itemid" | selitem -iC -oB $null`);
chop($item);

#STEP #2 - CHECK OUT THIS ITEM WITH OUR 'FAKE' VUFIND USER
#***IMPORTANT****REPLACE OL WITH YOUR OVERRIDE
open (API, "echo '^S32CVFFSIRSI^FELEHIGH^FcNONE^FWSIRSI^NQ". $itemid . "^UOVUFIND^dC3^rsY^jz1^OLXXXXXX^Fv3000000^^O' | apiserver -e' `gpn errorlog`' -h -s | ");
close API;

#STEP #2 - PUT THIS ITEM ON HOLD FOR THE CURRENT VUFIND USER
#***IMPORTANT***REPLACE ON WITH YOUR OVERRIDE
open (API, "echo '^S44JZFFSIRSI^FELEHIGH^FcNONE^FWSIRSI^UO" . $uid . "^Uk" . $uid . "^NQ" . $itemid .     "^IS1^DHNO^HFN^HG". $comments ."^HIN^HKTITLE^dC3^ONXXXXXX^Fv3000000^^O' | apiserver -e' `gpn errorlog`' -h -s | ");
close API;


#STEP #4 - DISCHARGE THE ITEM (CHECK IN THE ITEM - PREVIOUSLY CHECKED OUT BY 'FAKE' VUFIND USER
open (API, "echo '^S57EVFFSIRSI^FELEHIGH^FcNONE^FWSIRSI^NQ". $itemid ."^YPN^dC3^jz1^BEAUTOREFUND^z2AUTOREFUND^z7AUTOPAY^z1NONE^Fv3000000^^O' | apiserver -e' `gpn errorlog`' -h -s | ");
close API;

#STEP #5
#CAUSES EMAIL TO BE GENERATED
open (API, "echo '^S59fZFFSIRSI^FELEHIGH^FcNONE^FWSIRSI^NQ" . $itemid . "^Fv3000000^^O' | apiserver -e' `gpn errorlog`' -h -s | ");


while (<API>) {
        chomp;
		$holdings .= $_;
}
print $holdings;
close API;