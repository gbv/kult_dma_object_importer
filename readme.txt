# command to get import files from fylr api and store it
#
# you have to start it as root
# available options, listed with defaults
#
# no other mode exists yet
# --mode=full
#
# number of obcekts script will get at max
# it is also the offset limit
# --results=1000000
#
# you can store import files direct in hotfolder (hot)
# use only if you no need to store images first
# --folder=cold|hot
#
# hit number you like to start with
# hit are delivered by date, oldest first
# can change, when object dates change
# --offset=0
# number of hit api will deliver, max limit is 1000
# lower limit will make export more stable but take more time
# --limit=1000
#
# switch to warning to reduve log output
# --level=debug|warning
#
# set without value to get only objects stored by system id in code
# --preselect

sudo /usr/bin/php /opt/digiverso/kult_dma_object_importer/run.php