echo "=== EDS (started `date`) ==="

UPDATES=institution-eds-updates-$YESTERDAY.mrc
DELFLAT=institution-eds-deletes-$YESTERDAY.mrk
DELETED=institution-eds-deletes-$YESTERDAY.mrc

catalogdump -om -h -t"999" -ka001 \
  <added-or-modified.ckeys \
  >$UPDATES \
  2>edscatdump.err

# EDS wants records, not keys, so make some up.
rm -f $DELFLAT
cut -f1 -d'|' removed.ckeys | \
  while read ckey; do
    echo "*** DOCUMENT BOUNDARY ***" >>$DELFLAT
    echo "FORM=MARC"                 >>$DELFLAT
    echo ".000.  |amm3u0d a"         >>$DELFLAT
    echo ".001.  |a$ckey"            >>$DELFLAT
done
flatskip -aMARC -if -om <$DELFLAT >$DELETED 2>edsunflat.err

ftp -i -v ftp.epnet.com <<EOF
  bin
  cd update
  put $UPDATES
  cd ../update
  put $DELETED
  quit
EOF

