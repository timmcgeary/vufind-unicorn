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

# enable/disable logging API transactions that write to the database
# if enabled, transactions are logged in /s/sirsi/Unicorn/Logs/VuFindDriver.hist
my $log_database_changing_transactions = 0;

# enable/disable PIN checking
my $always_check_pin = 1;

# specify what kind of user id is being used
# B => Barcode, E => Alternative ID, K => User key
my $user_id_type = 'B';

# Specify the user id to run API transactions - default is SIRSI
my $api_user = 'SIRSI';

my $query = new CGI;

my $queryType = $query->param('query');

print $query->header('text/html'); # type is left as 'html' for easy testing from a browser

if ($queryType eq "single") {
    print get_holdings($query->param('id'), 1);
} elsif ($queryType eq "multiple") {
    print get_holdings($query->param('ids'), 0);
} elsif ($queryType eq "hold") {
    print place_hold(
        $query->param('itemId'), $query->param('patronId'), $query->param('pin'), 
        $query->param('pickup'), $query->param('expire'), $query->param('comments'), 
        $query->param('holdType'), $query->param('callnumber'), $query->param('override')
    );
} elsif ($queryType eq "login") {
    print login($query->param('patronId'), $query->param('pin'));
} elsif ($queryType eq "renew_items") {
    print renew_items(
        $query->param('chargeKeys'), $query->param('patronId'),
        $query->param('pin'), $query->param('library')
    );
} elsif ($queryType eq "profile") {
    print get_profile($query->param('patronId'), $query->param('pin'));
} elsif ($queryType eq "getholds") {
    print get_holds($query->param('patronId'), $query->param('pin'));
} elsif ($queryType eq "cancelHolds") {
    print cancel_holds($query->param('patronId'), $query->param('pin'), $query->param('holdId'));
} elsif ($queryType eq "transactions") {
    print get_transactions($query->param('patronId'), $query->param('pin'));
} elsif ($queryType eq "fines") {
    print get_fines($query->param('patronId'), $query->param('pin'));
} elsif ($queryType eq "reserves") {
    print get_reserves($query->param('course'), $query->param('instructor'), $query->param('desk'));
} elsif ($queryType eq "courses") {
    print get_courses();
} elsif ($queryType eq "instructors") {
    print get_instructors();
} elsif ($queryType eq "desks") {
    print get_desks();
} elsif ($queryType eq "shadowed") {
    print get_shadowed();
} elsif ($queryType eq "newitems") {
    print get_new_items();
} elsif ($queryType eq "marc_holdings") {
    print get_marc_holdings($query->param('id'));
} elsif ($queryType eq "marc_holdings_flat") {
    print get_marc_holdings_flat($query->param('id'));
} elsif ($queryType eq "libraries") {
    print get_libraries();
} elsif ($queryType eq "get_patron_by_alt_id") {
    print get_patron($query->param('patronId'), 0, 1);
} elsif ($queryType eq "get_patron") {
    print get_patron($query->param('patronId'), 0, 0);
} elsif ($queryType eq "get_charge_history") {
    print get_charge_history($query->param('patronId'),$query->param('pin'));
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
    my @holdings = ();
    my $itemkeys = '';
    open (API, "echo '$catkey' | tr '|' '\n' | selitem -iC -2N -oylmNKBrctuh 2>/dev/null | selpolicy -iP -tLIBR -oSPF22 2>/dev/null | selpolicy -iP -tLOCN -oSPF7 2>/dev/null | selpolicy -iP -tLOCN -oSPF7F4 2>/dev/null | selcallnum -2N -iK -oCADS 2>/dev/null |");
    while (<API>) {
            my @fields = split('\|',$_);
            my $itemkey = $fields[3] . '|' . $fields[4] . '|' . $fields[5] . '|';
            $itemkeys .= $itemkey . "\n";

            # get circulation rule if item is on reserve
            chomp($_);
            if ($fields[7] > 0) {
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
            if ($fields[8] > 0) {
                my $due = `echo '$itemkey' | selcharge -iI -oads 2>/dev/null |  selpolicy -iP -oSF9 -tCIRC 2>/dev/null`;
                chomp($due);
                if($due eq '') {
                    $due = '|0|0|';
                }
                $_ .= $due . "\n";
            } else {
                $_ .= "|0|0|\n"; 
            }
            chomp($_);
            $_ .= 'MARC|';
            push(@holdings, $_);
    }
    close API;
    
    # get hold counts
    my @counts = get_hold_count($itemkeys);

    # append hold count to each holding line
    for my $i (0 .. $#holdings) {
        $holdings[$i] .= $counts[$i] . '|';
    }

    my $result = join("\n", @holdings);
    if ($is_single) {
        # piggy-back MARC holdings records 
        # so VuFind doesn't have to fetch them separately
        # this is done to  avoid the overhead of a second request
        my $marc = get_marc_holdings($catkey);
        $result .= "-----BEGIN MARC-----$marc";
    }

    return $result;
}

sub get_marc_holdings {
    my $catkey = shift;

    $catkey = clean_input($catkey);

    my $marc = `echo '$catkey' | catalogdump -ka035 -lALL_LIBS -om 2>/dev/null`;

    return $marc;
}

sub get_marc_holdings_flat {
    my $catkey = shift;

    $catkey = clean_input($catkey);

    my $marc = `echo '$catkey' | catalogdump -ka035 -lALL_LIBS -of 2>/dev/null`;

    return $marc;
}

sub place_hold { 
    my ($itemid, $patronid, $pin, $pickup, $expire, $comments, $holdtype, $callnumber, $override)=@_;

    $itemid = clean_input($itemid);
    $patronid = clean_input($patronid);
    $pin = clean_input($pin);
    $pickup = clean_input($pickup);
    $expire = clean_input($expire);
    $comments = clean_input($comments);
    $holdtype = clean_input($holdtype);
    $callnumber = clean_input($callnumber);
    $override = clean_input($override);

    my $opts = "-i$user_id_type -oBy";
    if ($always_check_pin) {
        $opts .= " -w '$pin'";
    }
    my $patron = `echo '$patronid' | seluser $opts 2>/dev/null`;
    my @fields = split('\|', $patron);
    my $patron_barcode = trim($fields[0]);
    my $patron_library = trim($fields[1]);
    if ($patron_barcode eq "") {
        return "invalid_login";
    }

    my $item_library = `echo '$itemid' | selitem -iB -oy 2>/dev/null`;
    $item_library =~ s/[\|\s]+$//;

    my $transaction = '^S35JZFF' . $api_user . '^FcNONE^FE' . $item_library 
        . '^UO' . $patron_barcode . '^NQ' . $itemid . '^IQ' . $callnumber 
        . '^HB' . $expire . '^HG' . $comments . '^HIN' . '^HK' . $holdtype
        . '^HO' . $pickup;
    if ($override) {
        $transaction .= '^ON' . $override;
    }
    $transaction .= '^^O';
    if ($log_database_changing_transactions) {
        `echo $transaction >> /s/sirsi/Unicorn/Logs/VuFindDriver.hist`;
    }
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
                my $api = '^S81RVFF'. $api_user . '^FE' . $library . '^FcNONE^FW' . $api_user . '^NQ' . $itemid . '^UO' . $userid . '^^O';
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

    my $opts = "-i$user_id_type -oKEBDypqru08eh";

    # get extended info if required
    if ($xinfo) {
        $opts .= 'V.9007.X.9002.X.9004.X.9026.';
    }

    if ($always_check_pin) {
        $opts .= " -w '$pin'";
    }
    
    my $result = `echo '$patronid' | seluser $opts 2>/dev/null | seluserstatus -iU -oUSt 2>/dev/null`;

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
            
    my $result = `echo '$patronid' | seluser $opts 2>/dev/null | selhold -iU -jACTIVE -oICKabefpqtw123456 2>/dev/null | selitem -iK -oNSB 2>/dev/null | selcallnum -iK -oSD 2>/dev/null`;

    return $result;
}

sub cancel_holds {
    my ($patronid, $pin, $holdid)=@_;
    my $patron = login($patronid, $pin);
    if ($patron eq "") {
        return "invalid_login";
    }
    $holdid = clean_input($holdid);
    my $result = `echo '$holdid' | tr '|' '\n' | delhold -l"$api_user|PCGUI-DISP" 2>&1 | grep -P '\\*\\*|\\(1418\\)'`;
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

    my $result = `echo '$patronid' | seluser $opts 2>/dev/null | selcharge -iU -oaICcdepqrsK 2>/dev/null |  selpolicy -iP -oSF9 -tCIRC 2>/dev/null |selitem -iK -oNS 2>/dev/null|selcallnum -iK -oSD 2>/dev/null`;

    return $result;
}

sub get_charge_history {
    my ($patronid, $pin)=@_;

    $patronid = clean_input($patronid);
    $pin = clean_input($pin);

    my $opts = "-i$user_id_type -oU";

    if ($always_check_pin) {
        $opts .= " -w '$pin'";
    }

    my $result = `echo '$patronid' | seluser $opts 2>/dev/null | selchargehist -iU -oNcv 2>/dev/null | selcallnum -iN -oCADS 2>/dev/null | selcatalog -iK -oCVRS 2>/dev/null`;

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
        $result = `echo '$course' | selreserve -iC -oRUE 2>/dev/null | selresctl -iR -oSC 2>/dev/null`;
    } elsif ($instructor) {
        $result = `echo '$instructor' | selreserve -iU -oRUE 2>/dev/null |selresctl -iR -oSC 2>/dev/null`;
    } elsif ($desk) {
        $result = `selreserve -b'$desk' -oRUE 2>/dev/null | selresctl -iR -oSC 2>/dev/null`;
    } else {
        $result = `selreserve -oRUE 2>/dev/null |selresctl -iR -oSC 2>/dev/null`;
    }

    return $result;
}

sub get_courses {
    my $result = `selcourse -oKIn 2>/dev/null`;
    return $result;
}

sub get_instructors {
    my $result = `selreserve -oU 2>/dev/null | sort -u | seluser -iK -oKD 2>/dev/null`;
    return $result;
}

sub get_desks {
    my $result = `selreserve -ob 2>/dev/null | sort -u |selpolicy -iP -oF3F4 -tRSRV  2>/dev/null`;
    return $result;
}

sub get_shadowed {
    my $result = `selitem -2Y -oC 2>/dev/null`;
    $result .= `selcatalog -6=1 2>/dev/null`;
    $result .= `selcallnum -2Y -oC 2>/dev/null`;
    return $result;
}

sub get_libraries {
    my $result = `selpolicy -tLIBR -oF3F22 2>/dev/null`;
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

sub get_patron {
    my ($patronid, $xinfo, $by_alt_id)=@_;

    $patronid = clean_input($patronid);

    my $opts = $by_alt_id ? "-iE" : "-iB"; 
    $opts .= " -oKEBDypqru08w";

    # get extended info if required
    if ($xinfo) {
        $opts .= 'V.9007.X.9002.X.9004.X.9026.';
    }

    my $result = `echo '$patronid' | seluser $opts 2>/dev/null`;

    return $result;
}

sub get_hold_count {
    my ($itemkeys)=@_;
    my $result = `echo '$itemkeys' | prtitem -iI -oh -z 2>/dev/null | grep holds:3u`;
    my @lines = split("\n", $result);
    my @counts = ();
    foreach (@lines) {
        my @parts = split('>', $_);
        push(@counts, trim($parts[1]));
    }
    return @counts;
}
