#!/usr/bin/perl -Tw
# vufind-unicorn.pl, by the Library IT / Systems department <libsys@wm.edu>
# of Earl Gregg Swem Library at the College of William & Mary.
#
# This script is part of a VuFind driver for SirsiDynix Unicorn 3.1.2.5.
# It is a Perl CGI script that runs on the Unicorn server,
# allowing the other part of the driver to remotely use the Unicorn API,
# which is otherwise only accessible locally on the Unicorn server.
#
# CONFIGURATION:
# Run 'echo $UPATH' as user sirsi. Place the result between the quotes.
my $upath_dir = '/s/sirsi/Unicorn/Config/upath';
#
# Run 'gpn bincustom' as user sirsi. Place the result between the quotes.
my $bincustom_dir = '/s/sirsi/Unicorn/Bincustom';
#
# Run 'gpn bin' as user sirsi. Place the result between the quotes.
my $bin_dir = '/s/sirsi/Unicorn/Bin';
#
# Use a sufficiently secret string here. It is used for authentication.
# The setting in Unicorn.ini must match.
my $key = "GvPnpKfExTvqjsSbqY2Z7Md7";
#
# INSTALLATION:
# This script should reside on your Unicorn server in the cgi-bin directory
# of your webserver. It should be owned by the user sirsi, the group sirsi,
# and have Unix permissions 4711. E.g. (you may need to be root)
#
# cp vufind-unicorn.pl /path/to/cgi-bin
# chown sirsi:sirsi /path/to/cgi-bin/vufind-unicorn.pl
# chmod 4711 /path/to/cgi-bin/vufind-unicorn.pl
#
################################################################################
################################################################################
################################################################################

use CGI qw/:standard/;
use Switch;
use IPC::Open2;
use FileHandle;

$ENV{'UPATH'} = $upath_dir;
$ENV{'PATH'} = "$bincustom_dir:$bin_dir:/usr/bin:/bin";

my $delim = "%%\n";
my @results = ();
print header('text/plain');

eval {
  die "not running as sirsi. Check ownership and permissions.\n"
    if getpwuid $> ne 'sirsi';
  die "invalid key.\n" if param('k') ne $key;

  switch(param('s')) {
    case 'holdings' {
      my @catkeys = untaint('catkey', split /\|/, param('catkeys'));

      my @callnums = exsel('selcallnum -iC -2N -oNND', @catkeys);
      my @boundwith_callnums = exsel('selbound -iN -oPN', @callnums);
      @boundwith_callnums = exsel('selcallnum -iN -2N -oNSD', @boundwith_callnums)
        if(@boundwith_callnums > 0);

      @callnums = (@callnums, @boundwith_callnums);
      @items = exsel('selitem -iN -9 -oIS5Bclmy', @callnums);

      foreach (@items) {
        my @fields = split /\|/;
        if($fields[8] == 1) {
          $_ = untaint('passthru', $_);
          $_ = exsel('selcharge -iI -oISd', $_);
          chomp($_);
        } else {
          $_ .= '0|';
        }
      }

      push @results, join "\n", @items;
    }

    else { die "invalid subroutine.\n" }
  } # switch

  1;
} or do {
  print "ERROR: $@";
  exit;
};

print join $delim, @results;

############################ SUBROUTINES #######################################

sub exsel {
  my($cmd, @input) = @_;
  my $input = join "\n", @input;

  my $pid = open2(my $RDR, my $WTR, "$cmd 2>/dev/null");
  print $WTR $input . "\n"; close($WTR);
  my @output = <$RDR>; close($RDR);
  waitpid($pid, 0);

  return untaint('passthru', @output);
} # exsel

# If the purpose of this function is not clear to you,
# read the perlsec(perl5) manpage.
sub untaint {
  my ($type, @data) = @_;
  %regexes = ('catkey' => '^(\d+)$',
              'itemkey' => '^(\d+\|\d+\|\d+)$',
              'passthru' => '^(.+)$');
  foreach (@data) {
    unless($_ =~ /$regexes{$type}/) { die "bad $type '$_'\n"; }
    $_ = $1;
  }
  if (@data == 1) { return $data[0] } else { return @data; }
} # untaint
