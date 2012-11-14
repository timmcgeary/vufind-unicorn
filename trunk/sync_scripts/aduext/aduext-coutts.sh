echo "=== Coutts (started `date`) ==="

UPDATES=institution_holdings_$YESTERDAY.ecwmz

selcatalog -iC -e020 -oCe \
  <added-or-modified.ckeys \
  2>coutts-selcatalog.err \
  | fgrep -v '|-|' \
  | fgrep -v '|$' \
  >coutts.ckeys

echo "`trim_wcl coutts.ckeys` records are to be dumped for Coutts."

catalogdump -om -kf035 \
  <coutts.ckeys \
  >$UPDATES \
  2>coutts-catalogdump.err

ftp -i -v files.couttsinfo.com <<EOF
  bin
  cd history
  put $UPDATES
  quit
EOF

