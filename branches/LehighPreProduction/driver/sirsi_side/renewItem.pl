#!/s/sirsi/Unicorn/Bin/perl


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


my $uid = $query->param('id');
my $itemid = $query->param('itemid');


my $holdings = '';
my $api_results = 0;
open (API, "echo '^S87RVFFCIRC^FELEHIGH^FcNONE^FWCIRC^NQ" . $itemid . "^UO" . $uid . "^Fv200000^^O' |apiserver -e'`gpn  errorlog`' -h -s |");

while (<API>) {
        $api_results = 1;
		 chomp;
		$holdings .= $_;
}
print $holdings;
close API;