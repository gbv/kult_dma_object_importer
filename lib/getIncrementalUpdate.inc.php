<?php
  $monumentsCounter = 0;
  $monumentsCounterPutToHotfolder++;
  $startTime = microtime(true);

  //////////////////////////////////////////////////////////////////////
  // Download complete image-data
  //////////////////////////////////////////////////////////////////////

  require_once('getCompleteImageData.inc.php');
  $allImages = getCompleteImageData();

  while (!$ready) {

    //////////////////////////////////////////////////////////////////////
    // Download record-data
    //////////////////////////////////////////////////////////////////////

    $now = date('Y-m-d\Th:i:s.v', time());
    $EightDaysAgo = date("Y-m-d", strtotime('-8 day', time()));

    $url = $settings['updater']['baseUrl'] . '?SERVICE=WFS&VERSION=2.0.0&REQUEST=GetFeature&STOREDQUERY_ID=GetMonumentByChangeDate&CRS=http://www.opengis.net/def/crs/epsg/0/4326&minDateTime=' . $EightDaysAgo . '&maxDateTime=' . $now . '&count=' . $settings['updater']['batchSize'] . '&startIndex=' . $startIndex;
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
    $xmlString = str_replace('wfs:', 'wfs_', $xmlString);
    $xml = simplexml_load_string( $xmlString );

    // count monuments in xml-file
    $countOfRecordsInXMLFile = count($xml->wfs_member);

    foreach($xml->wfs_member as $monument) {
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
          //echo "KEIN BILD" . PHP_EOL;
          // only records with images!!
          //continue; // -- new Ordner: Also use records without image!!
        }

        $xmlStrMonument = str_replace('gml:id', 'gml_id', $xmlStrMonument);
        $xmlStrMonument = str_replace('<monument ', '<?xml version="1.0" encoding="utf-8" ?><monuments xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns="http://denkxweb.de/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.rjm.de/denkxweb/denkxml https://services.interactive-instruments.de/adab-ni-xs/schema/adabweb/denkgml.xsd http://www.opengis.net/wfs/2.0 https://services.interactive-instruments.de/adab-ni-xs/schema/ogc/wfs/2.0/wfs.xsd http://www.opengis.net/gml/3.2 https://services.interactive-instruments.de/adab-ni-xs/schema/ogc/gml/3.2.1/gml.xsd"><monument ', $xmlStrMonument);
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
