<?php

//////////////////////////////////////////////////////////////////////
// Download all images from service and remember all image-info
//////////////////////////////////////////////////////////////////////

function getCompleteImageData() {
    global $settings, $logger, $client;
    $allImages = array();

    $url = $settings['updater']['baseUrl'];
    $url .= '?SERVICE=WFS';
    $url .= '&VERSION=2.0.0';
    $url .= '&REQUEST=GetFeature';
    $url .= '&TYPENAMES=denkxml:Image';

    $logger->info('Downloading all image-information');
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

    $xmlString = str_replace('https://www.adabweb.niedersachsen.de/data/01/&lt;html&gt;&lt;head&gt;&lt;title&gt;Zur�ckgewiesene Anfrage&lt;/title&gt;&lt;/head&gt;&lt;body&gt;&lt;table border=0 cellpadding="20"&gt;&lt;tr&gt;&lt;td&gt;&lt;a href="http://www.niedersachsen.de"&gt;&lt;img src="/error_path/17813.jpg" width="100" border=0&gt;&lt;/a&gt;&lt;/td&gt;&lt;td valign="top"&gt;&lt;h1&gt;Zur�ckgewiesene Anfrage&lt;/h1&gt;Ihre Anfrage konnte leider nicht bearbeitet werden.&lt;br&gt;Wir bitten dies zu entschuldigen.&lt;br&gt;&lt;br&gt;Die ID ihrer Anfrage lautete: Xl5SI@N2s7yYBq6nGH6ZxAAAAIU&lt;/p&gt; &lt;td&gt;&lt;/tr&gt;&lt;/table&gt;&lt;/body&gt;&lt;/html&gt;" type="image/jpeg', 'https://denkmalatlas.niedersachsen.de/viewer/resources/themes/denkmalatlas/images/access_denied.png', $xmlString);

    $xmlString = str_replace('https://www.adabweb.niedersachsen.de/data/01/... Datei wird hochgeladen ...', 'https://denkmalatlas.niedersachsen.de/viewer/resources/themes/denkmalatlas/images/access_denied.png', $xmlString);

    $logger->info('Finished download of image-information');

    $logger->info('Writing image-info to logfolder');
    file_put_contents($settings['logger']['originalXMLPath'] . '/imageInfoFull.xml', $xmlString);

    $xml = simplexml_load_string( $xmlString );
    if(!$xml) {
      $logger->error('ImageData-XML is invalid XML!');
      throw new Exception($m);
    }
    $images = $xml->xpath('//wfs:FeatureCollection/wfs:member');

    $logger->info('Start parsing image-information');
    foreach($images as $image) {
      $image = $image->asXML();
      $image = str_replace('xlink:', 'xlink_', $image);
      $image = str_replace('gml:', 'gml_', $image);
      $image = str_replace('wfs:', 'wfs_', $image);
      $image = simplexml_load_string($image);
      $image = $image->Image;
      // get adabIdentifier and the other data
      $belongingADABRecord = (string) $image->depicts->attributes()->{'xlink_href'};
      $belongingADABRecord = str_replace('urn:x-adabweb:', '', $belongingADABRecord);
      $prefStatus = $image->isPreferred;
      $imageUrl = (string) $image->standard->attributes()->{'url'};
      //$imageUrl = str_replace('.JPG', '.jpg', $imageUrl);
      $description = (string) $image->description;
      $creator = '';
      if(isset($image->creator)) {
        $creator = $image->creator;
      }
      $rights = '';
      if(isset($image->rights)) {
        $rights = $image->rights;
      }
      $licence = '';
      if(isset($image->licence)) {
        $licence = $image->licence;
      }
      if(!isset($allImages[$belongingADABRecord])) {
        $allImages[$belongingADABRecord] = '';
      }
      $imageString = '<image preferred="' . $prefStatus . '"><licence>' . $licence . '</licence><rights>' . $rights . '</rights><creator>' . $creator . '</creator><standard url="' . $imageUrl . '" type="image/jpeg"/><description>' . $description . '</description></image>';
      // put preferred image to start
      if($prefStatus) {
        $allImages[$belongingADABRecord] =  $imageString . $allImages[$belongingADABRecord];
      }
      else {
        $allImages[$belongingADABRecord] .= $imageString;
      }
    }

    $logger->info('Finished parsing image-info');

    return $allImages;
}
 ?>
