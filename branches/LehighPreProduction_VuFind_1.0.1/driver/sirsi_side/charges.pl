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
my $null;
my $in = $query->param('id');
my @Output;

print $query->header('text/html'); # type is left as 'html' for easy testing from a browser

chomp(my @Charges = `echo "$in" | seluser -iB -oK $null | selcharge -iU -oIdqre $null | selcatalog -iC -oCStae -e020 $null | selitem -iI -oNISB $null | selcallnum -iN -oSA $null`);
foreach (@Charges){
	my @Columns = split(/\|/);

	chomp(my $Fines = `echo '$Columns[0]|$Columns[1]|$Columns[2]' | selbill -iI -ob $null`);
	($Fines) = split(/\|/, $Fines);

	if ($Fines){ push(@Columns, $Fines); }

	push(@Output, join('|', @Columns));
}

if (@Output){
	print "CatalogKey|LibraryNum|Copy|DueDate|Overdue|RenewedTimes|RenewedDate|Title|Author|ISBN|Barcode|CallNum|Fines\n";
	print join("\n", @Output) . "\n";
}
else {
	print "nothing found";
}