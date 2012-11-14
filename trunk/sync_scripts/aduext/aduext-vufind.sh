echo "=== VuFind (started `date`) ==="

UPDATES=updates-$YESTERDAY.mrc
DELETED=deleted-$YESTERDAY.ckeys

catalogdump -om  -h -i -t999 -ka002 -lALL_MARCS \
  <modified.ckeys \
  >$UPDATES \
  2>vufind.err

# include just the catkey
cut -f1 -d'|' removed.ckeys >$DELETED

if scp $UPDATES sirsi@catalog.library.institution.edu:updates; then
  echo Update files transferred successfully - VuFind-VM.
else
  echo Update file transfer failed - VuFind-VM.
fi
if scp $DELETED sirsi@catalog.library.institution.edu:updates; then
  echo Delete list transferred successfully - VuFind-VM.
else
  echo Delete list transfer failed - VuFind-VM.
fi

