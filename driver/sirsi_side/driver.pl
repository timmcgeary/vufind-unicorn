#!/s/sirsi/Unicorn/Bin/perl
# vufind.pl
# Tim McGeary
# 6 February 2008
# updated 20 May 2008
# 
# modified by Dan Wells, Hekman Library, July 2009
# 
# Arguments:
#	search - type of search ('single' or 'multiple')
#  id - single id (use with 'search=single')
#	ids - pipe-delimited list of ids (use with 'search=multiple')
#
# Returns:
#	multiple lines of pipe-delimited output from the Unicorn API
#
# This code is designed to as simple and lightweight as possible, so no XML is used

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

my $searchType = $query->param('search');

print $query->header('text/html'); # type is left as 'html' for easy testing from a browser

if ($searchType eq "single") {
	print get_holdings($query->param('id'));
} elsif ($searchType eq "multiple") {
# TODO(?): you could pass all IDs at once to get_holding for selitem efficiency reasons, but you would need to account for invalid item ids some other way (one way would be to pass through the keys, check for missing lines based on key, then fill in dummy lines as needed)
	my $holdings = '';
	foreach my $id (split(/\|/, $query->param('ids'))) {
		$holdings .= get_holdings($id) if ($id ne "");
		$holdings .= "\n";
	}
	chop $holdings;
	print $holdings;
}

sub get_holdings {
	my $catkey = shift;
	my $holdings = '';
	my $api_results = 0;
	open (API, "echo '$catkey' | selitem -iC -oCmrc 2>/dev/null | selcallnum -iC -oCDS 2>/dev/null |");
	while (<API>) {
		$api_results = 1;
		chomp;
# check for charges, get dates
		if (substr($_, -3) eq "|1|") {
			$_ = `echo '$_' | selcharge -iC -oCSd 2>/dev/null`;
		} else {
			$_ .= "0|\n";
		}
		$holdings .= $_;
	}
	close API;
	if (!$api_results) {
		$holdings .= "1234|Not available|Not available|0|1|0|\n";
	}
	return $holdings;
}
