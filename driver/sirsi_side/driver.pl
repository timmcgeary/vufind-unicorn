#!/s/sirsi/Unicorn/Bin/perl
# vufind.pl
# Tim McGeary
# 6 February 2008
# updated 20 May 2008
# 
# modified by Dan Wells, Hekman Library, July 2009
# 
# modified by Thomas Johnson, Yavapai Library Network, December 2010
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
$ENV{'TNS_ADMIN'}= "$unicorn_base_path/Config";
$ENV{'ORACLE_HOME'} = "/s/oracle/product/10.2.0/db_1";
$ENV{'ORACLE_SID'} = "unic";
$ENV{'LD_LIBRARY_PATH'} = "$unicorn_base_path/Oracle_client/10.2.0.3";

# enable/disable PIN checking
my $always_check_pin = 1;

# specify what kind of user id is being used
# B => Barcode, E => Alternative ID, K => User key
my $user_id_type = 'B';

my $query = new CGI;

my $queryType = $query->param('query');

print $query->header('text/html'); # type is left as 'html' for easy testing from a browser

if ($queryType eq "single") {
    print get_holdings($query->param('id'), 1);
} elsif ($queryType eq "multiple") {
    print get_holdings($query->param('ids'), 0);
} elsif ($queryType eq "hold") {
    print place_hold($query->param('itemId'),$query->param('patronId'),$query->param('pin'),$query->param('lib'));
} elsif ($queryType eq "login") {
    print login($query->param('patronId'),$query->param('pin'));
} elsif ($queryType eq "renew_items") {
    print renew_items($query->param('charge_keys'),$query->param('patronId'),$query->param('pin'),$query->param('library'));
} elsif ($queryType eq "profile") {
    print get_profile($query->param('patronId'),$query->param('pin'));
} elsif ($queryType eq "getholds") {
    print get_holds($query->param('patronId'),$query->param('pin'));
} elsif ($queryType eq "edithold") {
   print edit_hold($query->param('holdId'),$query->param('cancel'),$query->param('lib'),$query->param('expire'),$query->param('suspend'),$query->param('unsuspend'));
} elsif ($queryType eq "transactions") {
    print get_transactions($query->param('patronId'),$query->param('pin'));
} elsif ($queryType eq "fines") {
    print get_fines($query->param('patronId'),$query->param('pin'));
} elsif ($queryType eq "reserves") {
    print get_reserves($query->param('course'),$query->param('instructor'),$query->param('desk'));
} elsif ($queryType eq "courses") {
    print get_courses();
} elsif ($queryType eq "instructors") {
    print get_instructors();
} elsif ($queryType eq "desks") {
    foreach(get_desks()) {
	print "$_\n";
    }
} elsif ($queryType eq "shadowed") {
    print get_shadowed();
} elsif ($queryType eq "newitems") {
    print get_new_items();
} elsif ($queryType eq "marc_holdings") {
    print get_marc_holdings($query->param('id'));
} else {
    my $whoami = `id -un`;
    chomp($whoami);
    if ($whoami eq "sirsi") {
        print "Congratulations! suEXEC settings are OK. This script is running as: $whoami\n";
    } else {
        print "ERROR: This script is running as: $whoami\n";
    }
    exit 0;
}

sub get_holdings {
    my ($catkey, $is_single) = @_;

    $catkey = clean_input($catkey);
    my @catkeys = split('\|', $catkey);
    my $holdings = '';
    open (API, "echo '$catkey' | tr '|' '\n' | selitem -iC -2N -omNlryBcmtuhK 2>/dev/null | selpolicy -iP -tLOCN -oSF4 2>/dev/null | selcallnum -2N -iK -oCADS 2>/dev/null |");
    while (<API>) {
        if ($is_single) {
            my @fields = split('\|',$_);
            my $itemkey = $fields[12] . '|' . $fields[13] . '|' . $fields[14] . '|' . $fields[15] . '|';

            # get circulation rule if item is on reserve
            chomp($_);
            if ($fields[4] > 0) {
                my $resctl = `echo '$itemkey' | selresctl -iI -or 2>/dev/null | sort -u`;
                chomp($resctl);
                if ($resctl eq '') {
                    $resctl = '|';
                }
                $_ .= $resctl . "\n";
            } else {
                $_ .= "|\n"; 
            }

            # get due date if item is charged out
            chomp($_);
            if ($fields[7] > 0) {
                my $due = `echo '$itemkey' | selcharge -iK -ods 2>/dev/null`;
                chomp($due);
                if($due eq '') {
                    $due = '|0|';
                }
                $_ .= $due . "\n";
            } else {
                $_ .= "|0|\n"; 
            }

            # get catalog format
            $_ = `echo '$_' | selcatalog -iC -oCSf 2>/dev/null`;
        }
        $holdings .= $_;
    }
    close API;

    if ($is_single) {
        # piggy-back MARC holdings records 
        # so VuFind doesn't have to fetch them separately
        # this is done to  avoid the overhead of a second request
        my $marc = get_marc_holdings($catkey);
        $holdings .= "-----BEGIN MARC-----$marc";
    }

    return $holdings;
}

sub get_marc_holdings {
    my $catkey = shift;

    $catkey = clean_input($catkey);

    my $marc = `echo '$catkey' | catalogdump -ka035 -lALL_LIBS -om 2>/dev/null`;

    return $marc;
}

sub place_hold { 
    my ($itemid, $patronid, $pin, $pickup)=@_;

    $itemid = clean_input($itemid);
    $patronid = clean_input($patronid);
    $pin = clean_input($pin);
    $pickup = clean_input($pickup);

    my $opts = "-i$user_id_type -oB";
    if ($always_check_pin) {
        $opts .= " -w '$pin'";
    }
    my $patron_barcode = `echo '$patronid' | seluser $opts 2>/dev/null`;
    if ($patron_barcode =~ /(.*)\|/) {
        $patron_barcode =trim( $1);
    }

    if ($patron_barcode eq "") {
        return "invalid_login";
    }

    my $barcode .= `echo '$itemid' | selitem -iC -oB 2>/dev/null`;
    if ($barcode =~ /(.*)\|/) {
	    $barcode =trim( $1);
    }

    my $transaction = '^S35JZFFSIRSI^FcNONE^FE' . $pickup . '^UO' . $patron_barcode .'^NQ' . $barcode . '^HO' . $pickup . '^HKTITLE^HESYSTEM^HIN^^O';

    my $response = `echo '$transaction' | apiserver -h 2>/dev/null`;

    return $response;
}

sub renew_items {
    my ($charge_keys, $patronid, $pin, $library)=@_;
    my $patron = login($patronid, $pin);
    if ($patron eq "") {
        return "invalid_login";
    }
    $charge_keys = clean_input($charge_keys);
    $library = clean_input($library);
    my @charges = split(',', $charge_keys);
    my $response = "";
    foreach (@charges) {
        my $charge_key = trim($_);
        my $charge = `echo '$charge_key' | selcharge -iK -oUK 2>/dev/null | seluser -iK -oSBEK 2>/dev/null | selitem -iK -oSB 2>/dev/null`;
        my @ids = split('\|', $charge);
        if ($#ids < 4) {
            $response .= "not_charged" . "\n";
        } else {
            # $ids[1] is user id/barcode, $ids[2] is user alt. id,
            # $ids[3] is user key, $ids[4] is the item id/barcode
            my $userid = trim($ids[1]);
            my $altid = trim($ids[2]);
            my $userkey = trim($ids[3]);
            my $itemid = trim($ids[4]);

            # make sure the item is checked out by the same person renewing it
            if ( ($user_id_type eq 'B' && $patronid eq $userid)
              || ($user_id_type eq 'E' && $patronid eq $altid)
              || ($user_id_type eq 'K' && $patronid eq $userkey)) {
                my $api = '^S81RVFFSIRSI^FE' . $library . '^FcNONE^FWSIRSI^NQ' . $itemid . '^^O';
                my $result = `echo '$api' | apiserver -h 2>/dev/null`;
                $response .= $charge_key . '-----API_RESULT-----' . $result;
            }
        }
    }
    return $response;
}

sub login {
    my ($patronid, $pin, $xinfo)=@_;

    $patronid = clean_input($patronid);
    $pin = clean_input($pin);

    my $opts = "-i$user_id_type -oKEBDypqru08";

    # get extended info if required
    if ($xinfo) {
        $opts .= 'V.9007.X.9002.X.9004.X.9026.';
    }

    if ($always_check_pin) {
        $opts .= " -w '$pin'";
    }
    
    my $result = `echo '$patronid' | seluser $opts 2>/dev/null`;

    return $result;
}

sub get_profile {
    my ($patronid, $pin)=@_;
    return login($patronid, $pin, 1);
}

sub get_holds {
    my ($patronid, $pin)=@_;
    
    $patronid = clean_input($patronid);
    $pin = clean_input($pin);

    my $opts = "-i$user_id_type -oU";

    if ($always_check_pin) {
        $opts .= " -w '$pin'";
    }   
            
    my $result = `echo '$patronid' | seluser $opts 2>/dev/null | selhold -iU -jACTIVE -oCKabefpqtw123456 2>/dev/null`;

    return $result;
}

sub edit_hold {
    my ($holdid, $cancel, $lib, $expire, $suspend, $unsuspend)=@_;

    $holdid = clean_input($holdid);
    $cancel = clean_input($cancel);
    $lib = clean_input($lib);
    $expire = clean_input($expire);
    $suspend = clean_input($suspend);
    $unsuspend = clean_input($unsuspend);

    my $result = '';

    if ($cancel eq "y") {
        $result = `echo '$holdid' | delhold -l"PPLCIRC|PCGUI-DISP" 2>/dev/null`;
    } else {
        $result = `echo '$holdid' | edithold -w'$lib' 2>/dev/null`;
    }

    return $result;
}

sub get_transactions {
    my ($patronid, $pin)=@_;

    $patronid = clean_input($patronid);
    $pin = clean_input($pin);

    my $opts = "-i$user_id_type -oU";

    if ($always_check_pin) {
        $opts .= " -w '$pin'";
    }

    my $result = `echo '$patronid' | seluser $opts 2>/dev/null | selcharge -iU -oCcdepqrsK 2>/dev/null`;

    return $result;
}

sub get_fines {
    my ($patronid, $pin)=@_;

    $patronid = clean_input($patronid);
    $pin = clean_input($pin);

    my $opts = "-i$user_id_type -oU";

    if ($always_check_pin) {
        $opts .= " -w '$pin'";
    }

    my $result = `echo '$patronid' | seluser $opts 2>/dev/null | selbill -iU -pN -oCabcmqrstu 2>/dev/null`;

    return $result;
}

sub get_reserves {
    my ($course, $instructor, $desk)=@_;    

    $course = clean_input($course);
    $instructor = clean_input($instructor);
    $desk = clean_input($desk);

    my $result = '';
    if ($course) {
        $result = `echo '$course' | selcourse -iI 2>/dev/null | selreserve -iC -oR 2>/dev/null | selresctl -iR -oC 2>/dev/null`;
    } elsif ($instructor) {
        $result = `echo '$instructor' | selreserve -iU -oR 2>/dev/null | selresctl -iR -oC 2>/dev/null`;
    } elsif ($desk) {
        $result = `selreserve -b'$desk' | selresctl -iR -oC`;
    }

    return $result;
}

sub get_courses {
    my $result = `selcourse -oIn 2>/dev/null`;
    return $result;
}

sub get_instructors {
    my $result = `selreserve -oD 2>/dev/null | seluser -iK -oKD 2>/dev/null`;
    return $result;
}

sub get_desks {
    my $result = `selreserve -ob 2>/dev/null`;
    my @desks = split('\|',$result);

    # strip to unique keys
    my %hash = ();
    $hash{$_} = 1 foreach (@desks);

    my @uniq_desks = keys %hash;
    
    return $result;
}

sub get_shadowed {
    my $result = `selitem -2Y -oC 2>/dev/null`;
    $result .= `selcatalog -6=1 2>/dev/null`;
    $result .= `selcallnum -2Y -oC 2>/dev/null`;
    return $result;
}

sub get_new_items {
    my $date = `date -d last-month +%Y%m%d`;
    my $command = "selitem";
    $command .= " -m~AVAIL_SOON,INPROCESS -oC -f'>'";
    $command .= $date;
    my $result = `$command 2>/dev/null`;
    return $result;
}

sub clean_input {
    my $input = shift;
    # remove potentially dangerous strings from input
    $input =~ s/[\"\'\n]//g;
    return $input;
}

sub trim {
    my $string = shift;
    $string =~ s/^\s+//;
    $string =~ s/\s+$//;
    return $string;
}

