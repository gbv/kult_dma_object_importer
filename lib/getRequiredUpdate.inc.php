<?php
  $monumentsCounter = 0;
  $monumentsCounterPutToHotfolder = 0;
  $startTime = microtime(true);

  //////////////////////////////////////////////////////////////////////
  // 1. Download and parse images
  //////////////////////////////////////////////////////////////////////
  //require_once('getCompleteImageData.inc.php');
  //$allImages = getCompleteImageData();

  // get a list of objects by id that were already indexed

  $logger->info('Get indexed objects from solr.');
  $url = "https://denkmalatlas.niedersachsen.de/solr/collection1/select?q=PI%3A*&fl=PI%2C+MD_NLD_RECLASTCHANGEDATETIME&rows=100000&wt=json&indent=true";
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

        // only index object when it us unknown for solr or date of change has .. changed
        if ( !$objectWasIndexed || !$equalChangeDate ) {

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
  $logger->info('Added ' . $monumentsCounterPutToHotfolder . ' to hotfolder!');

?>
