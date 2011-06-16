#!/s/sirsi/Unicorn/Bin/perl
# recall.pl
# 2 February 2011
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
my $holdings;

chomp(my $Response = `echo "$itemid" | selitem -iC -oB $null`);
chop($Response);


open (API, "echo '^S99JZFFSIRSI^FELEHIGH^FcNONE^FWSIRSI^UO" . $uid . "^Uk" . $uid . "^NQ" . $Response . "^IS1^DHRUSH^HFN^HIN^HKCOPY^dC3^h4NEVER^h5NEVER^Fv3000000^^O' | apiserver -e' `gpn errorlog`' -h -s | ");

while (<API>) {
        chomp;
		$holdings .= $_;
}
print $holdings;
close API;