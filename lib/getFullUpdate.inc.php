<?php
  $monumentsCounter = 0;
  $monumentsCounterPutToHotfolder = 0;
  $startTime = microtime(true);

  $updatePerID = $settings['updater']['preselect'];
  $updateList = ["61705230","61708599","61708445","61289553","61261402","61342431","61625590","61625561","61624856","61358838","61610581","61395930","61386845","61261993","61365560","61344992","61284815","61261345","61475923","61762366","61755861","61485283","61311282"];

  while (!$ready) {

    //////////////////////////////////////////////////////////////////////
    // 2. Download and parse records
    //////////////////////////////////////////////////////////////////////

    ///api/v1/plugin/extension/nfis-denkxweb-export/export?limit=1&offset=100005
    $url = $settings['updater']['exportUrl'];
    $url .= '?limit=' . $settings['updater']['batchSize'];
    $url .= '&offset=' . $startIndex;
    //$url .= '&fromDate=' . '2025-08-12';

    $logger->info('Startindex is now ' . $startIndex);
    $logger->info('Request to ' . $url);

    // Attempt request with retries + exponential backoff. Use apiRequest to leverage token refresh.
    $maxRetries = 3;
    $attempt = 0;
    $delaySeconds = 2;
    $response = null;
    while ($attempt < $maxRetries) {
      $attempt++;
      try {
        $response = apiRequest($client, $tokenManager, $logger, $url);
        break;
      } catch (Throwable $t) {
        $logger->warning("Request attempt {$attempt} failed: " . $t->getMessage());
        if ($attempt >= $maxRetries) {
          $logger->error("All {$maxRetries} attempts failed for URL: {$url}");
          $startIndex += $settings['updater']['batchSize'];
          $logger->warning('Skipping to next batch due to repeated request errors. New startIndex: ' . $startIndex);
          // continue the outer batch loop
          continue 2;
        }
        sleep($delaySeconds);
        $delaySeconds *= 2;
        continue;
      }
    }

    $xmlString = $response->getBody()->getContents();

    // write this original batch to file
    file_put_contents($settings['logger']['originalXMLPath'] . '/batch_' . $startIndex . '_to_' . ($startIndex + $settings['updater']['batchSize']) . '.xml', $xmlString);

    // split xml and write it to single files
    // create dir for splitted xml-batches
    mkdir($settings['logger']['splittedXMLPath'] . '/' . $startIndex . '_to_' . ($startIndex + $settings['updater']['batchSize']) , 0777, true);

    // remove the wfs namespace for easier handling...
    //$xmlString = str_replace('wfs:', 'wfs_', $xmlString);
    // remove debug namespace if there is one
    $xmlString = preg_replace('/\bdebug:/', '', $xmlString);
    $xml = simplexml_load_string( $xmlString );
    //$logger->info('XML: ' . $xmlString);

    /*
    <monument> hat ein default namespace
    <monument xmlns="http://www.rjm.de/denkxweb/denkxml" ...>
    In XML bede0utet xmlns="...", dass alle untergeordneten Knoten diesen
    Namespace erben, sofern nicht anders angegeben.
    $monuments = $xml->xpath('//monument') bleibt somit leer.
    Lösung: Registriere das Default-Namespace unter einem Prefix
    */
    $xml->registerXPathNamespace('dma', 'http://www.rjm.de/denkxweb/denkxml');
    $monuments = $xml->xpath('//dma:monument');
    //$logger->info('XML: ' . var_dump($monuments));

    // count monuments in xml-file
    $countOfRecordsInXMLFile = count($monuments);
    $logger->info('We count ' . $countOfRecordsInXMLFile . ' monuments in Response File.');

    $idMapping = "";
    $idMappingHtml = "";
    $importQuery = "";

    // MONUMENTS
    foreach($monuments as $monument) {
        $monumentsCounter++;
        //$monument = $monument->monument;

        if ($monument->error) {
          $uuid = (string) $monument->uuid;
          $logger->error("Fehler in Objekt: ".$uuid);
          $logger->error($monument->error->message);
          $logger->error($monument->error->stack);
          continue;
        }

        // get identifier
        $objectid = (string) $monument->recId;
        $fylrid = (string) $monument->fylrId;
        $uuid = (string) $monument->uuid;
        $adabwebid = (string) $monument->adabwebId;
        if (!$adabwebid) { $adabwebid = "0"; }

        // set id for filename
        $id = $objectid;

        // import from given id list
        if ( $updatePerID ) {
          $givenId = in_array($fylrid, $updateList);
        } else {
          $givenId = true;
        }

        if ( $givenId ) {

          // mapping contains all types of ids for each object
          $idMapping = $uuid . ';' . $fylrid . ';' . $adabwebid . "\n";
          file_put_contents($settings['updater']['hotfolder'] . 'mapping.cvs' , $idMapping, FILE_APPEND);

          // clickable mapping
          $idMappingHtml  = '<a href="https://atlas2.gbv.de/viewer/resources/themes/denkmalatlas/update/orig_denkxweb/';
          $idMappingHtml .= $uuid.'.xml">'. $uuid . '</a> | ';
                  $idMappingHtml .= "\n";
          $idMappingHtml .= '<a href="https://nfis.gbv.de#/detail/';
          $idMappingHtml .= $uuid.'">'. $fylrid . ' (nfis)</a> | ';
                  $idMappingHtml .= "\n";
          if ($adabwebid != "0") {
            $idMappingHtml .= '<a href="https://denkmalatlas.niedersachsen.de/viewer/resources/themes/denkmalatlas/update/orig_denkxweb/';
            $idMappingHtml .= $adabwebid.'.xml">'. $adabwebid . '</a>';
            $idMappingHtml .= "\n";
          } else {
            $idMappingHtml .= 'keine adabweb id';
            $idMappingHtml .= "\n";
          }
          $idMappingHtml .= "</br>\n";
          file_put_contents($settings['updater']['hotfolder'] . 'mapping.html' , $idMappingHtml, FILE_APPEND);

          // mapping for apache redirect
          if ($adabwebid != "0") {
            $idMappingRedirect =  $adabwebid . " " . $uuid . "\n";
            file_put_contents($settings['updater']['hotfolder'] . 'redirects.txt' , $idMappingRedirect, FILE_APPEND);
          }

          // add matching images
          if ($monument->images) {
            $downloadImageDirPath = $settings['updater']['hotfolder'] . $id . '_media';
            if (!file_exists($downloadImageDirPath)) {
                mkdir($downloadImageDirPath);
            }
            foreach($monument->images->image as $image) {
            //foreach($monument->images as $image) {
              $imageUrl = (string) $image->standard->attributes()->{'url'};
              $filename = (string) $image->filename;
              //$imageUrl = (string) $image->image->standard->attributes()->{'url'};
              //$filename = (string) $image->image->filename;
              //preg_match('/\?filename=([^&]+)/', $imageUrl, $treffer);
              //$filename = $treffer[1];
              $saveFilename = sanitizeFilename($filename);
              $command = "wget '" . $imageUrl . "' -O " . $downloadImageDirPath . "/" . $saveFilename;
              exec($command . " 2>&1", $output, $return_var);
              $image->standard['url'] = $id . '_media/' . $saveFilename;
              //$image->image->standard['url'] = $id . '_media/' . $saveFilename;
            }
          }

          // modify xml to fit intranda viewer-configurations
          $xmlStrMonument = $monument->asXML();

          $xmlStrMonument = str_replace('gml:id', 'gml_id', $xmlStrMonument);
          $xmlStrMonument = str_replace('<monument ', $common_settings['xmlHeader'] . '<monuments ' .  $common_settings['monumentsNameSpace'] . '><monument ', $xmlStrMonument);
          $xmlStrMonument = preg_replace('/<monument\b[^>]*>/', '<monument>', $xmlStrMonument);
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


              // put xml to hotfolder
              file_put_contents($settings['updater']['hotfolder'] . $id . '.xml' , $xmlStrMonument);
            }
          }
          else {
            $m = '<h1>ERROR beim einlesen des monumentXML</h1>';
            $logger->error($m);
            //throw new Exception($m);
            //exit;
            continue;
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

    // stop if startIndex reached configured maximum results (prevents infinite loop on repeated errors)
    $maxOffset = (int)$settings['updater']['maxCount'];
    if ($maxOffset > 0 && $startIndex >= $maxOffset) {
      $logger->info('Reached configured max offset (' . $maxOffset . '), stopping. Current startIndex: ' . $startIndex);
      $ready = true;
    }

    // break if the last iteration contain less objects than our batchsize has
    // it means, we have everything now
    if($countOfRecordsInXMLFile < $settings['updater']['batchSize']) {
      $ready = true;
    }
  }
  $logger->info('Finished parsing data. Parsed ' . $monumentsCounter . ' monuments!');
  $logger->info('Added ' . $monumentsCounterPutToHotfolder . ' to folder: ' . $settings['updater']['hotfolder'] );

?>
