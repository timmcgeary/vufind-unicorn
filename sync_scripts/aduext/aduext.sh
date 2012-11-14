#!/bin/bash
# vi: set et ts=2 sw=2 sts=2:
#
# aduext provides catalog updates to *ext*ernal services (e.g. VuFind, Summon).
# It is designed to be run as a cronjob shortly after midnight, submitting
# updates for the previous day.
#

VISIBILITY_CRITERIA='-60 -30 -z>0'
SIRSI_ENVIRON=/opt/sirsi/Unicorn/Config/environ

############################################################################
trim_wcl() { echo `wc -l <$1`; }

# Include Sirsi's environment variables, so seltools will function properly. 
cd `dirname $0`
source $SIRSI_ENVIRON
for i in `cut -f1 -d= $SIRSI_ENVIRON`; do
  export $i
done

# The last dump of all visible catkeys is stored in a file all.YYYYMMDD.ckeys.
# We determine the previous dump date from the latest such file.
PREV_ALLCKEYS=`ls all.*.ckeys | sort | tail -n1`
PREV_DUMPDATE=`echo $PREV_ALLCKEYS | awk -F. '{print $2}'`
if [ -z "$PREV_DUMPDATE" ]; then
  echo "Could not determine previous run date!"
  exit 1
fi

# Prepare and enter a temporary/run directory; halt if it already exists.
YESTERDAY=`perl -MPOSIX -le 'print strftime("%Y%m%d",localtime(time-(24*60*60)))'`
TEMPDIR=Temp$YESTERDAY
if ! mkdir $TEMPDIR 2>/dev/null; then
  echo "Temporary directory already exists for $YESTERDAY."
  echo "Perhaps I have already run or am running?"
  exit 1
fi
cd $TEMPDIR

echo === Started `date` ===
echo "Since last run ($PREV_DUMPDATE)..."

# Generate a current all.ckeys file (standard-sorted to work with comm(1)).
selcatalog $VISIBILITY_CRITERIA 2>all.err | sort >all.ckeys

# Generate removed.ckeys as what's missing from the previous all.ckeys.
# Do a sanity check and abort if the result seems absurd.
comm -23 ../$PREV_ALLCKEYS all.ckeys >removed.ckeys
NR_REMOVED=`trim_wcl removed.ckeys`
if [ $NR_REMOVED -gt 1000000 ]; then
  echo "  $NR_REMOVED records probably weren't removed from the visible set. Aborting."
  exit 1;
else
  echo "  $NR_REMOVED records were removed from the visible set,"
fi

# Generate modified.ckeys as those in the visible since that have been modified
# after the previous run date.
selcatalog $VISIBILITY_CRITERIA -r">$PREV_DUMPDATE" \
  2>modified.err \
  >modified.ckeys
echo "  `trim_wcl modified.ckeys` records in the visible set were modified,"

# Generate added.ckeys as what's in current all.ckeys but not in the previous.
comm -13 ../$PREV_ALLCKEYS all.ckeys >added.ckeys
echo "  and `trim_wcl added.ckeys` records were added to the visible set."

# Merge added.ckeys and modified.ckeys to produce the list of records
# that we're going to need to dump and send.
sort -u added.ckeys modified.ckeys >added-or-modified.ckeys
echo "In total, `trim_wcl added-or-modified.ckeys` records in the visible set were added or modified."

# Run service-specific scripts.
source ../aduext-vufind.sh
source ../aduext-summon.sh
source ../aduext-coutts.sh

# Provide current all.ckeys to the next invocation of the script,
# since we can't rely on the existence of directories labeled "temporary".
cp all.ckeys ../all.$YESTERDAY.ckeys
# Remove the old all.ckeys, to avoid duplicating what's
# available in any unremoved temporary directories.
rm ../$PREV_ALLCKEYS

echo === Finished `date` ===
