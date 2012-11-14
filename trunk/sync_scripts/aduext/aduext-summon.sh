echo "=== Summon (started `date`) ==="

UPDATES=institution-catalog-updates-$YESTERDAY.mrc
DELETED=institution-catalog-deletes-$YESTERDAY.mrc

catalogdump -om -h -t"999" -ka035 \
  <modified.ckeys \
  >$UPDATES \
  2>summon.err

# include only the catkeys, prefixed with an 'a'.
cut -f1 -d'|' removed.ckeys | sed 's/^/a/' >$DELETED

ftp -i -v ftp.summon.serialssolutions.com <<EOF
  bin
  cd updates 
  put $UPDATES
  cd ../deletes
  put $DELETED
  quit
EOF

