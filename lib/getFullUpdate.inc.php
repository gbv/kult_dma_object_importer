<?php
  $monumentsCounter = 0;
  $monumentsCounterPutToHotfolder = 0;
  $startTime = microtime(true);

  //////////////////////////////////////////////////////////////////////
  // 1. Download and parse images
  //////////////////////////////////////////////////////////////////////
  require_once('getCompleteImageData.inc.php');
  $allImages = getCompleteImageData();

  while (!$ready) {

    //////////////////////////////////////////////////////////////////////
    // 2. Download and parse records
    //////////////////////////////////////////////////////////////////////

    // einen einzelnen Datensatz abfragen:
    // https://www.adabweb.niedersachsen.de/adabweb/denkmalatlas/wfs?SERVICE=WFS&VERSION=2.0&REQUEST=GetFeature&STOREDQUERY_ID=http://www.opengis.net/def/query/OGC-WFS/0/GetFeatureById&ID=monument.34672634

    // alle ohne paging:
    // https://www.adabweb.niedersachsen.de/adabweb/denkmalatlas/wfs?SERVICE=WFS&VERSION=2.0&REQUEST=GetFeature&STOREDQUERY_ID=http://inspire.ec.europa.eu/operation/download/GetSpatialDataSet&CRS=http://www.opengis.net/def/crs/EPSG/0/4326&DataSetIdCode=ADABObjekte&DataSetIdNamespace=NI&Language=ger&count=20000&startIndex=0

    //$url = 'https://services.interactive-instruments.de/adab-ni-xs/dda-wfs?SERVICE=WFS&VERSION=2.0&REQUEST=GetFeature&STOREDQUERY_ID=http://inspire.ec.europa.eu/operation/download/GetSpatialDataSet&CRS=http://www.opengis.net/def/crs/EPSG/0/4326&DataSetIdCode=ADABObjekte&DataSetIdNamespace=NI&Language=ger&count=' . $settings['updater']['batchSize'] . '&startIndex=' . $startIndex;

    $url = $settings['updater']['baseUrl'];
    $url += '?SERVICE=WFS';
    $url += '&VERSION=2.0.0';
    $url += '&REQUEST=GetFeature';
    $url += '&STOREDQUERY_ID=http://inspire.ec.europa.eu/operation/download/GetSpatialDataSet';
    $url += '&CRS=http://www.opengis.net/def/crs/EPSG/0/4326';
    $url += '&DataSetIdCode=ADABObjekte';
    $url += '&DataSetIdNamespace=NI';
    $url += '&Language=ger';
    $url += '&count=' . $settings['updater']['batchSize'];
    $url += '&startIndex=' . $startIndex;

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

        // modify xml to fit intranda viewer-configurations
        $xmlStrMonument = $monument->asXML();

        // add matching images
        $hasImage = false;
        if(isset($allImages[$id])) {
          $hasImage = true;
          $imageStr = '<images>' . $allImages[$id] . '</images>';
          $xmlStrMonument = str_replace('</monument>', $imageStr . '</monument>', $xmlStrMonument);
        }
        else {
          // only records with images!!
          //continue; // -- new Ordner: Also use records without image!!
        }

        $xmlStrMonument = str_replace('gml:id', 'gml_id', $xmlStrMonument);
        $xmlStrMonument = str_replace('<monument ', '<?xml version="1.0" encoding="utf-8" ?><monuments xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns="http://denkxweb.de/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://www.rjm.de/denkxweb/denkxml https://services.interactive-instruments.de/adab-ni-xs/schema/denkgml/0.9/denkgml.xsd http://www.opengis.net/wfs/2.0 https://services.interactive-instruments.de/adab-ni-xs/schema/ogc/wfs/2.0/wfs.xsd http://www.opengis.net/gml/3.2 https://services.interactive-instruments.de/adab-ni-xs/schema/ogc/gml/3.2.1/gml.xsd"><monument ', $xmlStrMonument);
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

    $logger->info('This batch took ' . (microtime(true) - $startTime) . ' seconds');
    $startIndex += $settings['updater']['batchSize'];
    if($countOfRecordsInXMLFile < $settings['updater']['batchSize']) {
      $ready = true;
    }
  }
  $logger->info('Finished parsing data. Parsed ' . $monumentsCounter . ' monuments!');
  $logger->info('Added ' . $monumentsCounterPutToHotfolder . ' to hotfolder!');

?>
