#!/s/sirsi/Unicorn/Bin/perl
# removeHold.pl
# 28 February 2011
# 

# 
# Arguments:
# itemid
# holdid
# uid
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
my $uid = $query->param('uid');
my $itemid = $query->param('itemid');
my $holdid = $query->param('holdid');
my $results;


#STEP #1 - REMOVE HOLD
open (API, "echo '^S60FZFFSIRSI^FELEHIGH^FcNONE^FWSIRSI^UO" . $uid . "^HKTITLE^HIN^HH" . $holdid . "^NQ". $itemid. "^Fv3000000^^O' | apiserver -e' `gpn errorlog`' -h -s | ");
close API;

#STEP #2 - CALL UNAVAILABLE HOLD
open (API, "echo '^S62hMFFSIRSI^FELEHIGH^FcNONE^FWSIRSI^HH" . $holdid . "^Fv3000000^^O' | apiserver -e' `gpn errorlog`' -h -s | ");



while (<API>) {
        chomp;
		$results .= $_;
}
print $results;
close API;