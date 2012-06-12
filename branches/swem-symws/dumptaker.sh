#!/bin/bash
# vi: set et ts=2 sw=2:

UNSUPPRESSED="-60 -30 -z>0"

rsrv() {
  # Says Swem's reserves supervisor in 2012: "[Reserves in the PREPARE stage
  # should not be included] b/c in the prepare stage - they are not available
  # to our patrons and we are waiting for them to be returned (from a recall)".
  #
  # Since Unicorn course records have an ID (e.g. LAW 305), and a name 
  # (e.g. TRUSTS AND ESTATES), an awk script combines them to to produce the
  # course name provided to VuFind, including both if they are not redundant.
  selreserve -aACTIVE $DESKFILTER -oRCUb \
    | selresctl  -iR -oSC                \
    | selcourse  -iC -oSIn               \
    | seluser    -iU -oSD                \
    | selpolicy  -tRSRV -iP -oSF4        \
    | selcatalog -iC $UNSUPPRESSED -oCS  \
    | awk -F '|' '
      $2 == substr($3, 0, length($2)) { print $1 "|" $3 "|" $4 "|" $5 ; next }
      { print $1 "|" $2 ": " $3 "|" $4 "|" $5 }
    '
}

auth() {
  selauthority | authdump | flatskip -aTOPICAL -om
}

bib() {
  echo "Not implemented."
  exit -1
}


# Save the first argument, then allow default options to be overridden.
OPERATION=$1; shift;
DESKFILTER='';
while getopts 'b:' opt; do
  case $opt in
    b) if [ "$OPTARG" != "ALL" ]; then DESKFILTER="-b$OPTARG"; fi;;
  esac
done

$OPERATION
