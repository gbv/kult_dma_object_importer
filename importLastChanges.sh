#!/bin/bash

# make sure skript ends on error
set -euo pipefail

#define directories
SCRIPT_DIR=$(dirname "$0")
#BASE_DIR="/opt/denkmalatlas/digiverso/viewer"
BASE_DIR="/opt/digiverso/viewer"
LOGDIR="$SCRIPT_DIR/logs"
HOTDIR="$BASE_DIR/hotfolder/"
COLDDIR="$BASE_DIR/coldfolder/"

# log results
LOGDATE=$(date +%F)
LOGFILE="$LOGDIR/import-$LOGDATE.log"
exec >>"$LOGFILE" 2>&1
echo "[$(date)] importLastChanges.sh started."
echo "[$(date)] Logfile created."

# send logfile per mail when script ends (success or error)
LOG_RECIPIENT="${LOG_RECIPIENT:-goobi-viewer-support@lists.gbv.de}"
#LOG_RECIPIENT="${LOG_RECIPIENT:-tilo.neumann@gbv.de}"
LOG_SENDER="${LOG_SENDER:-no-reply@gbv.de}"
SENDMAIL_BIN="${SENDMAIL_BIN:-/usr/sbin/sendmail}"
SENDMAIL_ARGS="${SENDMAIL_ARGS:--t -oi}"

send_log_mail() {
  local exit_code=$?
  # Do not let mail sending change/interrupt the script shutdown.
  set +e
  if [ "${exit_code}" -eq 0 ]; then
    local script_status="success"
  else
    local script_status="error"
  fi
  local subject="[Denkmalatlas] Import last changes: ${script_status}"

  echo "[$(date)] Sending log mail."

  if [ -x "$SENDMAIL_BIN" ]; then
    {
      echo "To: $LOG_RECIPIENT"
      echo "From: $LOG_SENDER"
      echo "Subject: $subject"
      echo "MIME-Version: 1.0"
      echo "Content-Type: text/plain; charset=UTF-8"
      echo
      echo "Status: ${script_status}"
      echo "Exit-Code: ${exit_code}"
      echo "Timestamp: $(date)"
      echo
      cat "$LOGFILE"
    } | "$SENDMAIL_BIN" $SENDMAIL_ARGS
  else
    echo "[$(date)] Warning: sendmail not found/executable at '$SENDMAIL_BIN' - cannot mail log."
  fi

  return 0
}
trap send_log_mail EXIT

# import only when hotfolder and coldfolder are empty
if [ -z "$(ls -A "$HOTDIR")" ] && [ -z "$(ls -A "$COLDDIR")" ]; then

  updatedb
  echo "[$(date)] Locate updated."

  DATE=$(date -d "yesterday" +%F)
  echo "[$(date)] Set Date to yesterday: $DATE"

  #/usr/bin/php "$SCRIPT_DIR/run.php" --limit=100 --from="$DATE" --skip-images --no-purge --level=debug
  /usr/bin/php "$SCRIPT_DIR/run.php" --limit=10 --from="$DATE" --force-images
  echo "[$(date)] Got latest changes."

  dependent_objects_file="$SCRIPT_DIR/config/dependentObjects.txt"
  if [ -s "$dependent_objects_file" ]; then
    echo "[$(date)] Objects found. Will make a copy before processing."
    cp "$dependent_objects_file" "$SCRIPT_DIR/config/dependentObjects.$(date +"%y-%m-%d_%H:%M:%S").txt"
    echo "[$(date)] Processing dependent objects from $dependent_objects_file."
    mapfile -t uuids < "$dependent_objects_file"
    for uuid in "${uuids[@]}"; do
      if [ -n "$uuid" ]; then
        echo "[$(date)] Running dependent import for uuid: $uuid"
        /usr/bin/php "$SCRIPT_DIR/run.php" --skip-images --no-purge --uuid="$uuid"
      fi
    done
  else
    echo "[$(date)] No dependent objects found in $dependent_objects_file."
  fi

  find "$COLDDIR" -maxdepth 1 -type d -name '*_media' \
    -exec mv -t "$HOTDIR" {} +
  echo "[$(date)] Media files moved."

  chown -R tomcat:tomcat "$HOTDIR"
  echo "[$(date)] Tomcat own media files now."

  find "$COLDDIR" -maxdepth 1 -mindepth 1 -type f -print0 | xargs -0 -I {} mv {} "$HOTDIR"

  echo "[$(date)] Hotfolder filled with exported documents."

else
  echo "[$(date)] Error. Target directories are not empty. Import stopped."
  exit 3
fi
