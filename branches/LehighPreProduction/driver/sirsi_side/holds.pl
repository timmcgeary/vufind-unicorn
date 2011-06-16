#!/s/sirsi/Unicorn/Bin/perl
# holds.pl
# Updated: 
# 
# 
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
my $Position;
my $UserKey;
my $CatalogKey;
my $Location;
my $Copy;
my $Available;
my $Recalled;
my $ExpireDate;
my $Reserved;
my $Active;
my $PickupLocation;
my $HoldKey;
my $barcode;
my $Title;
my $Author;
my $CallNum;
my @HoldPriorities;
my $i;
my $nUserKey;
my $Priority;
my @Holds;
my @CallNum;
my @barcode;



print $query->header('text/html'); # type is left as 'html' for easy testing from a browser

chomp(@Holds = `echo "$in" | seluser -iB -oK 2>/dev/null | selhold -iU -j ACTIVE -oUIabefjwKB 2>/dev/null`);
foreach (@Holds){
	($UserKey, $CatalogKey, $Location, $Copy, $Available, $Recalled, $ExpireDate, $Reserved, $Active, $PickupLocation, $HoldKey) = split(/\|/, $_);

	if ($ExpireDate == 0){ $ExpireDate = 'NEVER'; }

	chomp($Title = `echo '$CatalogKey' | selcatalog -iC -ota 2>/dev/null`);
	($Title, $Author) = split(/\|/, $Title);

	chomp(@CallNum = `echo '$CatalogKey' | selcallnum -iC -oD 2>/dev/null`);
	@CallNum = split(/\|/, @CallNum[0]);
	$CallNum = @CallNum[0];

	chomp(@HoldPriorities= `echo '$CatalogKey' | selhold -iC -oqU 2>/dev/null`);
	@HoldPriorities = sort {
		(split ':', $a, 2)[0] cmp
		(split ':', $b, 2)[0]
	} @HoldPriorities;

	$i = 0;
	foreach (@HoldPriorities){
		$i++;
		($Priority, $nUserKey) = split(/\|/);
		if ($UserKey == $nUserKey){ $Position = $i; }
	}

	chomp(@barcode = `echo '$CatalogKey' | selitem -iC -oB 2>/dev/null`);
	@barcode = split(/\|/, @barcode[0]);
	$barcode = @barcode[0];

	
	push(@Output, "$CatalogKey|$Location|$Copy|$Title|$Author|$CallNum|$Available|$Recalled|$ExpireDate|$Reserved|$Active|$PickupLocation|$Position|$HoldKey|$barcode");
}

if (@Output){
	print "CatalogKey|Location|Copy|Title|Author|CallNum|Available|Recalled|ExpireDate|Reserved|Active|PickupLocation|Position|HoldKey|barcode\n";
	print join("\n", @Output) . "\n";
}

##END

