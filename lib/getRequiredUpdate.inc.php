<?php
  $monumentsCounter = 0;
  $monumentsCounterPutToHotfolder = 0;
  $startTime = microtime(true);

  $updatePerID = false;
  $updateList = ["36237284","47696153","47982634","34574742","42005179","25086788","26801463","30685542","34252857","35917551","37417764","18289551","25077102","25078402","25078416","25078444","25078753","25079458","25080066","25082124","25082726","25085454","25089520","25089739","25090299","26802445","26806057","26809077","26809311","28798655","28801752","28820503","28825920","28942443","28949265","28951235","28955243","30599215","30650001","30654519","30735527","30748806","30755410","30760291","30825986","30826214","30828179","30829271","30831078","30832355","30832469","30834166","30836263","30836320","30838349","30857197","30865426","30868027","30869349","30869558","30871865","30885666","30886966","30888338","30894611","30898092","30898439","30899291","30899871","30901028","30901186","30926356","30928541","30952278","30953728","30980357","31008193","31015699","31015981","31026498","31026709","31028519","31032829","31033557","31085145","31088261","31088831","31093093","31098093","31124047","31251799","31255977","31257468","31264111","31264243","31266585","31272537","31340794","31344877","31346022"];

  // download complete image data
  require_once('getCompleteImageData.inc.php');
  $allImages = getCompleteImageData();

  // get a list of objects by id that were already indexed
  $logger->info('Get indexed objects from solr.');
  $url = "https://denkmalatlas.niedersachsen.de/solr/collection1/select?q=PI%3A*&fl=PI%2C+MD_NLD_RECLASTCHANGEDATETIME&rows=1000000&wt=json&indent=true";
  try {
    $response = $client->request('GET', $url);
  } catch (Throwable $t) {
      $m = 'Was not able to retrieve index objects from solr: ' . $url;
      $logger->error($m);
      throw new Exception($m);
  }
  $logger->info('Transform json response to php object.');
  $jResponse = json_decode($response->getBody()->getContents());
  $indexedObjects = array();
  $logger->info('Build array of objects: PI -> MD_NLD_RECLASTCHANGEDATETIME');
  foreach ( $jResponse->response->docs as $entry) {
    $indexedObjects[$entry->PI] = $entry->MD_NLD_RECLASTCHANGEDATETIME[0];
  }
  $logger->info('Got ' . count($indexedObjects) . ' indexed objects.');

  while (!$ready) {

    //////////////////////////////////////////////////////////////////////
    // 2. Download and parse records
    //////////////////////////////////////////////////////////////////////

    $url = $settings['updater']['baseUrl'];
    $url .= '?SERVICE=WFS';
    $url .= '&VERSION=2.0.0';
    $url .= '&REQUEST=GetFeature';
    $url .= '&STOREDQUERY_ID=http://inspire.ec.europa.eu/operation/download/GetSpatialDataSet';
    $url .= '&CRS=http://www.opengis.net/def/crs/EPSG/0/4326';
    $url .= '&DataSetIdCode=ADABObjekte';
    $url .= '&DataSetIdNamespace=NI';
    $url .= '&Language=ger';
    $url .= '&count=' . $settings['updater']['batchSize'];
    $url .= '&startIndex=' . $startIndex;

    $logger->info('Startindex is now ' . $startIndex);
    $logger->info('Request to ' . $url);

    try {
      $response = $client->request('GET', $url, ['auth' => [$settings['updater']['authUser'], $settings['updater']['authPwd']]]);
    } catch (Throwable $t) {
        // Handle exception
        $m = $url . ' not available';
        $logger->error($m);
        throw new Exception($m);
    }

    $xmlString = $response->getBody()->getContents();

    // write this original batch to file
    file_put_contents($settings['logger']['originalXMLPath'] . '/batch_' . $startIndex . '_to_' . ($startIndex + $settings['updater']['batchSize']) . '.xml', $xmlString);

    // split xml and write it to single files
    // create dir for splitted xml-batches
    mkdir($settings['logger']['splittedXMLPath'] . '/' . $startIndex . '_to_' . ($startIndex + $settings['updater']['batchSize']) , 0777, true);

    // remove the wfs namespace for easier handling...
    //$xmlString = str_replace('wfs:', 'wfs_', $xmlString);
    $xml = simplexml_load_string( $xmlString );

    $monuments = $xml->xpath('//wfs:FeatureCollection/wfs:member[1]/wfs:FeatureCollection[1]/wfs:member');

    // count monuments in xml-file
    $countOfRecordsInXMLFile = count($monuments);

    // MONUMENTS
    foreach($monuments as $monument) {
        $monumentsCounter++;
        $monument = $monument->monument;
        // get identifier
        $id = (string) $monument->recId;
        // get date of last change
        $lastChanged = (string) $monument->recLastChangeDateTime;
        // prepare conditions for indexing an object
        $objectWasIndexed = array_key_exists($id, $indexedObjects);
        $equalChangeDate = false;
        if ($objectWasIndexed) {
          $equalChangeDate = $lastChanged === $indexedObjects[$id];
        }
        // import from given id list
        $givenId = false;
        if ($updatePerID) {
          $givenId = in_array($id, $updateList);
        }
        // only index object when it us unknown for solr
        // or date of change has .. changed
        // or id is given
        // isset($monument->groupMembers) || isset($monument->groups)
        if ( !$objectWasIndexed || !$equalChangeDate || $givenId ) {

            // modify xml to fit intranda viewer-configurations
            $xmlStrMonument = $monument->asXML();

            // add matching images
            $hasImage = false;
            if(isset($allImages[$id])) {
              $hasImage = true;
              $imageStr = '<images>' . $allImages[$id] . '</images>';
              $xmlStrMonument = str_replace('</monument>', $imageStr . '</monument>', $xmlStrMonument);
            }

            $xmlStrMonument = str_replace('gml:id', 'gml_id', $xmlStrMonument);
            $xmlStrMonument = str_replace('<monument ', $common_settings['xmlHeader'] . '<monuments ' .  $common_settings['monumentsNameSpace'] . '><monument ', $xmlStrMonument);
            $xmlStrMonument = str_replace('</monument>', '</monument></monuments>', $xmlStrMonument);
            $monument = simplexml_load_string( $xmlStrMonument );
            if($monument !== false) {
              unset($monument->attributes()->{'gml_id'});
              $xmlStrMonument = $monument->asXML();
              $xmlStrMonument = str_replace('gml_id', 'gml:id', $xmlStrMonument);
              if($id) {
                $monumentsCounterPutToHotfolder++;
                // put to log-folder
                file_put_contents($settings['logger']['splittedXMLPath'] . '/' . $startIndex . '_to_' . ($startIndex + $settings['updater']['batchSize']) . '/' . $id . '.xml' , $xmlStrMonument);
                // put image to hotfolder
                if($hasImage) {
                  $downloadImageDirPath = $settings['updater']['hotfolder'] . $id . '_downloadimages';
                  if (!file_exists($downloadImageDirPath)) {
                      mkdir($downloadImageDirPath);
                  }
                }
                // put xml to hotfolder
                file_put_contents($settings['updater']['hotfolder'] . $id . '.xml' , $xmlStrMonument);
              }
            }
            else {
              $m = '<h1>ERROR beim einlesen des monumentXML</h1>';
              $logger->error($m);
              throw new Exception($m);
              exit;
            }

            // break if maxCount is given in second parameter
            if($monumentsCounter >= $settings['updater']['maxCount']) {
              $logger->info('Settings -> updater -> maxCount is: ' . $settings['updater']['maxCount']);
              $logger->info('StartIndex is: ' . $startIndex);
              $logger->info('--> break download');
              $ready = true;
              break;
            }
        }
    }

    $logger->info('This batch took ' . (microtime(true) - $startTime) . ' seconds');
    $startIndex += $settings['updater']['batchSize'];
    // break if the last iteration contain less objects than our batchsize has
    // it means, we have everything now
    if($countOfRecordsInXMLFile < $settings['updater']['batchSize']) {
      $ready = true;
    }
  }
  $logger->info('Finished parsing data. Parsed ' . $monumentsCounter . ' monuments!');
  $logger->info('Added ' . $monumentsCounterPutToHotfolder . ' to folder: ' . $settings['updater']['hotfolder'] );

?>
