#!/s/sirsi/Unicorn/Bin/perl
# fines.pl
# 
# 8 February 2011
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
my $null;
my $in = $query->param('id');
my @Output;

print $query->header('text/html'); # type is left as 'html' for easy testing from a browser

chomp(my @Output = `echo "$in" | seluser -iB -oK 2>/dev/null | selbill -pN -iU -oIabcr 2>/dev/null | selcatalog -iC -oCSta 2>/dev/null`);

if (@Output){
	print "CatalogKey|Library|Copy|BilledAmt|Balance|BillDate|Reason|Title|Author^";
	print join("^", @Output) . "^";
}
else {
	print "nothing found";
}