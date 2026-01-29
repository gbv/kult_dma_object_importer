# Description

- removes objects which are no longer public (purge)
- get import files from fylr api and store it (export)

# Notes

You have to start the script as root.
```
sudo /usr/bin/php /opt/digiverso/kult_dma_object_importer/run.php
```

# Parameter
## Overview
```
--results
--folder
--offset
--limit
--level

--uuid
--preselect
--skip-images
--force-images
--id-mapping

--missing-images-only
--from
--no-purge
--purge-only
```
##   #Details
### --results=1000000
max number of object script will get \
it is also the offset limit \
if not set, default is 1.000.000

### --folder=cold|hot
you can store import files direct in hotfolder =hot \
use only if you dont need to store images first \
if not set, default is cold

### --offset=0
hit number you like to start with \
hits are delivered by date, oldest first \
position can change, when object dates changes
if not set, default is 0

### --limit=1000
number of hits api will deliver at once \
max limit is 1000 given by export plugin \
lower limit will make export more stable but take more time
if not set, default is 1000

### --level=info|debug|warning
switch to debug, to get more Details \
if not set, info is dafault

### --uuid=3ed1a71b-2620-4c8a-9537-f5f49d9f6467
define to get just a single object \
if not set, all public objects will be requested

### --preselect
set without value to get only objects stored by system id in code \
if not set, all public objects will be requested

### --skip-images
do not get _media files, just create pure denkxweb files \
if not set, images will requested, if referenced in object and \
not found by name in data store

### --force-images
do not check if image exist, download again \
if not set, image will not get downloaded when already exists

### --id-mapping
create mapping and redirect files for old to new ids \
if not set, no mapping will be created

### --missing-images-only
save xml only, when image was not stored in data directories yet \
if not set, all objects will we requested

### --from=YYYY-MM-DD
get only objects that have a changed date after the given one \
if not set, all objects will be requested

### --no-purge
you can skip the check, if object has to get removed from index \
if not set, every import checks for no longer published objects

### --purge-only
do nothing other that creating purge files \
for objects that has to get removed from index \
if not set, purge and export will be done
