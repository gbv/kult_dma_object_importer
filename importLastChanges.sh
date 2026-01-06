#!/bin/bash

# make sure skript ends on error
set -euo pipefail

# yesterday
DATE=$(date -d "yesterday" +%F)

# get latest changes
/usr/bin/php /opt/digiverso/kult_dma_object_importer/run.php --limit=100 --from="$DATE"

# move media files
find /opt/digiverso/viewer/coldfolder/ -maxdepth 1 -type d -name '*_media' \
  -exec mv -t /opt/digiverso/viewer/hotfolder/ {} +

# make tomcat owner to prevent errors on deleting
chown -R tomcat:tomcat /opt/digiverso/viewer/hotfolder/

# start import
find /opt/digiverso/viewer/coldfolder/ -maxdepth 1 -type f -name '*.xml' \
  -exec mv -t /opt/digiverso/viewer/hotfolder/ {} +

# update locate db
updatedb