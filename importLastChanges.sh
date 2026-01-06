#!/bin/bash

# make sure skript ends on error
set -euo pipefail

# log results
LOGDIR="/opt/digiverso/kult_dma_object_importer/logs"
LOGDATE=$(date +%F)
LOGFILE="$LOGDIR/import-$LOGDATE.log"
exec >>"$LOGFILE" 2>&1

echo "Logfile created."

updatedb
echo "Locate updated."

DATE=$(date -d "yesterday" +%F)
echo "Set Date to yesterday: $DATE"

/usr/bin/php /opt/digiverso/kult_dma_object_importer/run.php --limit=100 --from="$DATE"
echo "Got latest changes."

find /opt/digiverso/viewer/coldfolder/ -maxdepth 1 -type d -name '*_media' \
  -exec mv -t /opt/digiverso/viewer/hotfolder/ {} +
echo "Media files moved."

chown -R tomcat:tomcat /opt/digiverso/viewer/hotfolder/
echo "Tomcat own media files now."

find /opt/digiverso/viewer/coldfolder/ -maxdepth 1 -type f -name '*.xml' \
  -exec mv -t /opt/digiverso/viewer/hotfolder/ {} +
echo "Hotfolder filled with import documents."
