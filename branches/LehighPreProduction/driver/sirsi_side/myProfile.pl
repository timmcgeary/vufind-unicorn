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


my $lookupid = $query->param('id');


my $holdings = '';
my $api_results = 0;
open (API, "echo '$lookupid' |  seluser -iB -oDpY.9022.Y.9003.Y.9004.Y.9007.Y.9009. 2>/dev/null|");

while (<API>) {
        $api_results = 1;
		 chomp;
		$holdings .= $_;
}
print $holdings;
close API;
