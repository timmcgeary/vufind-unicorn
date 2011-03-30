#!/s/sirsi/Unicorn/Bin/perl
# vufind.pl
# Tim McGeary
# 6 February 2008
# updated 20 May 2008
# 
# modified by Dan Wells, Hekman Library, July 2009
# modified by Geoff Sinclair, Nipissing University, March 2011
# 
# Arguments:
#   search - type of search ('single' or 'multiple')
#   id - single id (use with 'search=single')
#   ids - pipe-delimited list of ids (use with 'search=multiple')
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
   print get_holdings($query->param('ids'));
} else {
   print 'Invalid request: Missing searchType parameter';
}

sub get_holdings {
   my $ids = shift;
   my $id;
   my $catkey;
   my %item_holdings;
   my $holdings = '';
   my $item;
   my @ids = split(/\|/,$ids);
   my @untainted_ids;

   # sanity check for the IDs. May need fine tuning!!
   foreach $id (@ids) {
       if ($id =~ /^(\w{1}[\w-.]*)$/) {
           $catkey = $1;
          $catkey =~ s/^u//;
           push @untainted_ids, $catkey;
       } else {
           # catkey failed sanity check
           exit;
       }
   }

   my $untainted_catkeys = '\n' . join('|\n',@untainted_ids);
   open (API, "echo -e '$untainted_catkeys' | selitem -iC -oCmrcy 2>/dev/null | selcallnum -iC -oCDS 2>/dev/null |");
  while (<API>) {
      $item = $_;
       chomp($item);
       my $item_id = $item;
       $item_id =~ s/\|.*//;
       # check for charges, get dates
       if (substr($item, -3) eq "|1|") {
           $item = `echo '$item' | selcharge -iC -oCSd 2>/dev/null`;
       } else {
           $item .= "0|\n";
       }
       $item_holdings{$item_id} .= $item;
   }
  close API;

   foreach $id (@untainted_ids) {
       if ($item_holdings{$id}) {
           $holdings .= $item_holdings{$id} . "\n";
       } else {
           $holdings .= "2345|Not available|Not available|0|1|0|\n\n";
       }
   }
   chop $holdings;
   return $holdings;
}
