#!/bin/bash

# make sure skript ends on error
set -euo pipefail

#define directories
LOGDIR="/opt/digiverso/kult_dma_object_importer/logs"
HOTDIR="/opt/digiverso/viewer/hotfolder/"
COLDDIR="/opt/digiverso/viewer/coldfolder/"

# log results
LOGDATE=$(date +%F)
LOGFILE="$LOGDIR/import-$LOGDATE.log"
exec >>"$LOGFILE" 2>&1
echo "[$(date)] Logfile created."

# send logfile per mail when script ends (success or error)
#LOG_RECIPIENT="${LOG_RECIPIENT:-goobi-viewer-support@lists.gbv.de}"
LOG_RECIPIENT="${LOG_RECIPIENT:-tilo.neumann@gbv.de}"
LOG_SENDER="${LOG_SENDER:-no-reply@gbv.de}"
SENDMAIL_BIN="${SENDMAIL_BIN:-/usr/sbin/sendmail}"

send_log_mail() {
  local exit_code=$?
  local subject="[Denkmalatlas] importLastChanges.sh ${LOGDATE} (exit=${exit_code})"

  if [ -x "$SENDMAIL_BIN" ]; then
    {
      echo "To: $LOG_RECIPIENT"
      echo "From: $LOG_SENDER"
      echo "Subject: $subject"
      echo "MIME-Version: 1.0"
      echo "Content-Type: text/plain; charset=UTF-8"
      echo
      cat "$LOGFILE"
    } | "$SENDMAIL_BIN" -t
  else
    echo "[$(date)] Warning: sendmail not found/executable at '$SENDMAIL_BIN' - cannot mail log."
  fi
}
trap send_log_mail EXIT

# import only when hotfolder is empty
if [ -z "$(ls -A "$HOTDIR")" ]; then

  updatedb
  echo "[$(date)] Locate updated."

  DATE=$(date -d "yesterday" +%F)
  echo "[$(date)] Set Date to yesterday: $DATE"

  /usr/bin/php /opt/digiverso/kult_dma_object_importer/run.php --limit=100 --from="$DATE"
  echo "[$(date)] Got latest changes."

  find "$COLDDIR" -maxdepth 1 -type d -name '*_media' \
    -exec mv -t "$HOTDIR" {} +
  echo "[$(date)] Media files moved."

  chown -R tomcat:tomcat "$HOTDIR"
  echo "[$(date)] Tomcat own media files now."

  find "$COLDDIR" -maxdepth 1 -type f -name '*.xml' \
    -exec mv -t "$HOTDIR" {} +
  echo "[$(date)] Hotfolder filled with import documents."

else
  echo "[$(date)] Error. Hotfolder not empty. Import stoped."
fi
