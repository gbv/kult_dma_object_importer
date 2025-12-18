# command to get import files from fylr api and store it
#
# you have to start it as root
# available options, listed with defaults
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
#
# number of hit api will deliver, max limit is 1000
# lower limit will make export more stable but take more time
# --limit=1000
#
# switch to warning to reduve log output
# --level=info|debug|warning
#
# set without value to get only objects stored by system id in code
# --preselect
#
# do not get _media just create denkxweb files
# --skip-images
#
# do not create mapping and redirect files for old to new ids
# --id-mapping
#
# save xml only, when image was not stored in data directories yet
# --missing-images-only
#
# get only objects that have a changed date after the given one
# --from=YYYY-MM-DD

sudo /usr/bin/php /opt/digiverso/kult_dma_object_importer/run.php