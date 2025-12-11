#!/usr/bin/php
<?php

// include libs
require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\ErrorHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Formatter\LineFormatter;
use GuzzleHttp\Client;

// include settings
require __DIR__ . '/config/local_settings.php';
require __DIR__ . '/config/common_settings.php';

// include classes
require __DIR__ . '/classes/ApiRequester.php';
require __DIR__ . '/classes/ExceptionHandler.php';
require __DIR__ . '/classes/FileNameSanitizer.php';
require __DIR__ . '/classes/FileTokenStorage.php';
require __DIR__ . '/classes/TokenManager.php';
require __DIR__ . '/classes/DurationFormatter.php';
require __DIR__ . '/classes/LoggerFactory.php';

// get current date
$now = date('Y.m.d__h:i:s', time());

// fetch start parameter
$startParameter = getopt("", [
  "mode:",
  "results:",
  "folder:",
  "offset:",
  "limit:",
  "level:",
  "preselect",
  "skipimage"
]);

// compute start parameter
$skriptMode = isset($startParameter["mode"]) ? $startParameter["mode"] : 'all';
$maxExportNumber = isset($startParameter["results"]) ? $startParameter["results"] : 1000000;
$startIndex = isset($startParameter["offset"]) ? $startParameter["offset"] : 0;
$batchSize = isset($startParameter["limit"]) ? $startParameter["limit"] : 1000;
$preselect = isset($startParameter["preselect"]) ? true : false;
$skipimage = isset($startParameter["skipimage"]) ? true : false;

$destinationPath = $local_settings['cold_folder_path'];
if (isset($startParameter["folder"])) {
  switch ($startParameter["folder"]) {
    case 'hot':
      $destinationPath = $local_settings['hot_folder_path'];
      break;
  }
}

$logLevel = Logger::INFO;
if (isset($startParameter["level"])) {
  switch ($startParameter["level"]) {
    case 'debug':
      $logLevel = Logger::DEBUG;
      break;
    case 'info':
      $logLevel = Logger::INFO;
      break;
    case 'warning':
      $logLevel = Logger::WARNING;
      break;
  }
}

$settings = [
  'logger' => [
    'name' => 'DenkXport',
    'path' => __DIR__ . '/logs/' . $now . '/' . $now . '.log',
    'originalXMLPath' => __DIR__ . '/logs/' . $now . '/' . 'original_xml',
    'splittedXMLPath' => __DIR__ . '/logs/' . $now . '/' . 'splitted_xml',
    'defaultLogLevel' => $logLevel,
    'mailTriggerLevel' => Logger::INFO,
    'mailRecipient' => $local_settings['recipient'],
    'mailSender' => $local_settings['sender']
  ],
  'updater' => [
    'type' => $skriptMode,
    'batchSize' => $batchSize,
    'maxCount' => $maxExportNumber,
    'hotfolder' => $destinationPath,
    'exportUrl' => $local_settings['api_export_url'],
    'preselect' => $preselect
  ]
];

// init logger
$logger = LoggerFactory::create($settings['logger']);

// log status
$logger->info('Export started.');
$logger->debug('Given parameter: ', $startParameter);
$logger->debug('Current settings: ', $settings);

// init guzzle client
$logger->debug('Initialize guzzle client.');
$client = new Client([
  'base_uri' => $local_settings['project_url'],
  'timeout' => 600,
  'headers' => ['Accept-Encoding' => 'gzip']
]);

// init token management
$logger->debug('Initialize token management.');
$tokenStorage = new FileTokenStorage(__DIR__ . '/token.json');
$tokenManager = new TokenManager($client, $tokenStorage, $local_settings, $logger);

// init (own) exception handler
ExceptionHandler::setLogger($logger);
set_exception_handler([ExceptionHandler::class, 'handle']);

// create dir for original xml-batches
mkdir($settings['logger']['originalXMLPath'], 0777, true);

// init request handler
$apiRequester = new ApiRequester($client, $tokenManager, $logger);

$logger->debug('Batchsize: ' . $settings['updater']['batchSize']);

// set up loop variables
$monumentsCounter = 0;
$monumentsCounterPutToHotfolder = 0;
$startTime = microtime(true);
// list of system-ids
$updateList = ["61705230", "61708599", "61708445", "61289553", "61261402", "61342431", "61625590", "61625561", "61624856", "61358838", "61610581", "61395930", "61386845", "61261993", "61365560", "61344992", "61284815", "61261345", "61475923", "61762366", "61755861", "61485283", "61311282"];
// use id list
$updatePerID = $settings['updater']['preselect'];
// set loop base condition
$ready = false;

// start request loop
while (!$ready) {

  // set end of batch
  $endIndex = $startIndex + $settings['updater']['batchSize'];

  // build request url
  $url = $settings['updater']['exportUrl'];
  $url .= '?limit=' . $settings['updater']['batchSize'];
  $url .= '&offset=' . $startIndex;
  // optional date filter
  // todo: make parameter
  //$url .= '&fromDate=' . '2025-08-12';

  // write status to log
  $logger->debug('Startindex is now ' . $startIndex);
  $logger->debug('Request to ' . $url);

  // attempt request with retries + exponential backoff
  // use apiRequest to leverage token refresh
  $maxRetries = 3;
  $attempt = 0;
  $delaySeconds = 2;
  $response = null;
  // start request
  while ($attempt < $maxRetries) {
    $attempt++;
    try {
      $response = $apiRequester->request($url);
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

  // store results
  $xmlString = $response->getBody()->getContents();

  // write this original batch results to file
  // build file name
  $xmlBatchFileName = sprintf(
    '%s/batch_%d_to_%d.xml',
    $settings['logger']['originalXMLPath'],
    $startIndex,
    $endIndex
  );
  file_put_contents($xmlBatchFileName, $xmlString);

  // split xml and write it to single files
  // create dir for splitted xml-batches
  $xmlBatchDirName = sprintf(
    '%s/%d_to_%d',
    $settings['logger']['splittedXMLPath'],
    $startIndex,
    $endIndex
  );
  mkdir($xmlBatchDirName, 0777, true);

  // remove the wfs namespace for easier handling...
  //$xmlString = str_replace('wfs:', 'wfs_', $xmlString);
  // remove debug namespace if there is one
  $xmlString = preg_replace('/\bdebug:/', '', $xmlString);
  // store batch result in xml oject
  $xml = simplexml_load_string($xmlString);

  // there are no result for xpath //monument
  // because all child nodes from monuments will inherit its default name space
  // to solve this, we have to register that name space
  $xml->registerXPathNamespace('dma', 'http://www.rjm.de/denkxweb/denkxml');
  $monuments = $xml->xpath('//dma:monument');

  // count monuments in xml-file
  $countOfRecordsInXMLFile = count($monuments);
  $logger->debug('We count ' . $countOfRecordsInXMLFile . ' monuments in Response File.');

  $idMapping = "";
  $idMappingHtml = "";
  $importQuery = "";

  // loop through monuments
  foreach ($monuments as $monument) {

    // increase gloabl monument counter
    $monumentsCounter++;

    // get uuid of current monument
    $uuId = (string) $monument->uuId;

    // check if we have an error element in this monument
    if ($monument->error) {
      // report error
      $logger->error("Fehler in Objekt: " . $uuId);
      $logger->error($monument->error->message);
      $logger->error($monument->error->stack);
      // go to the next monument
      continue;
    }

    // get and store all other identifiers
    $objectId = (string) $monument->recId;
    $fylrId = (string) $monument->fylrId;
    $adabwebId = (string) $monument->adabwebId;
    if (!$adabwebId) {
      // this is not a number, but a string with zero as value
      $adabwebId = "0";
    }

    // set id for filename
    $id = $objectId;

    // import from given id list
    if ($updatePerID) {
      $validId = in_array($fylrId, $updateList);
    } else {
      $validId = true;
    }

    if ($validId) {

      // mapping contains all types of ids for each object
      $idMapping = $uuId . ';' . $fylrId . ';' . $adabwebId . "\n";
      file_put_contents($settings['updater']['hotfolder'] . 'mapping.cvs', $idMapping, FILE_APPEND);

      // clickable mapping
      $idMappingHtml = '<a href="https://atlas2.gbv.de/viewer/resources/themes/denkmalatlas/update/orig_denkxweb/';
      $idMappingHtml .= $uuId . '.xml">' . $uuId . '</a> | ';
      $idMappingHtml .= "\n";
      $idMappingHtml .= '<a href="https://nfis.gbv.de#/detail/';
      $idMappingHtml .= $uuId . '">' . $fylrId . ' (nfis)</a> | ';
      $idMappingHtml .= "\n";
      if ($adabwebId != "0") {
        $idMappingHtml .= '<a href="https://denkmalatlas.niedersachsen.de/viewer/resources/themes/denkmalatlas/update/orig_denkxweb/';
        $idMappingHtml .= $adabwebId . '.xml">' . $adabwebId . '</a>';
        $idMappingHtml .= "\n";
      } else {
        $idMappingHtml .= 'keine adabweb id';
        $idMappingHtml .= "\n";
      }
      $idMappingHtml .= "</br>\n";
      file_put_contents($settings['updater']['hotfolder'] . 'mapping.html', $idMappingHtml, FILE_APPEND);

      // mapping for apache redirect
      if ($adabwebId != "0") {
        $idMappingRedirect = $adabwebId . " " . $uuId . "\n";
        file_put_contents($settings['updater']['hotfolder'] . 'redirects.txt', $idMappingRedirect, FILE_APPEND);
      }

      // add matching images
      if ($monument->images) {

        $downloadImageDirPath = $settings['updater']['hotfolder'] . $id . '_media';
        if (!$skipimage && !file_exists($downloadImageDirPath ) ) {
          mkdir($downloadImageDirPath);
        }
        foreach ($monument->images->image as $image) {
          $filename = (string) $image->filename;
          $saveFilename = FileNameSanitizer::sanitizeStatic($filename);
          if (!$skipimage) {
            $imageUrl = (string) $image->standard->attributes()->{'url'};
            $command = "wget '" . $imageUrl . "' -O " . $downloadImageDirPath . "/" . $saveFilename;
            exec($command . " 2>&1", $output, $return_var);
          }
          $image->standard['url'] = $id . '_media/' . $saveFilename;
        }
      }

      // modify xml to fit intranda viewer-configurations
      $xmlStrMonument = $monument->asXML();

      $xmlStrMonument = str_replace('gml:id', 'gml_id', $xmlStrMonument);
      $xmlStrMonument = str_replace('<monument ', $common_settings['xmlHeader'] . '<monuments ' . $common_settings['monumentsNameSpace'] . '><monument ', $xmlStrMonument);
      $xmlStrMonument = preg_replace('/<monument\b[^>]*>/', '<monument>', $xmlStrMonument);
      $xmlStrMonument = str_replace('</monument>', '</monument></monuments>', $xmlStrMonument);
      $monument = simplexml_load_string($xmlStrMonument);
      if ($monument !== false) {
        unset($monument->attributes()->{'gml_id'});
        $xmlStrMonument = $monument->asXML();
        $xmlStrMonument = str_replace('gml_id', 'gml:id', $xmlStrMonument);
        if ($id) {
          // object stored
          $monumentsCounterPutToHotfolder++;
          // put to log-folder
          file_put_contents($settings['logger']['splittedXMLPath'] . '/' . $startIndex . '_to_' . ($endIndex) . '/' . $id . '.xml', $xmlStrMonument);
          // put xml to hotfolder
          file_put_contents($settings['updater']['hotfolder'] . $id . '.xml', $xmlStrMonument);
        }
      } else {
        $m = 'Could not store results in XML. Error logged. Try next object now.';
        $logger->error($m);
        continue;
      }

      // break if maxCount is given in second parameter
      if ($monumentsCounter >= $settings['updater']['maxCount']) {
        $loggerMessage = sprintf(
          'Stopping export. Result limit of %d reached. Processed %d monuments.',
          $settings['updater']['maxCount'],
          $monumentsCounter
        );
        $logger->info($loggerMessage);
        // stop loop
        $ready = true;
        break;
      }
    }
  }

  $elapsed = microtime(true) - $startTime;
  $logger->debug('This export took ' . DurationFormatter::format($elapsed) . 'so far.');
  $startIndex += $settings['updater']['batchSize'];

  // stop if startIndex reached configured maximum results
  // prevents infinite loop on repeated errors
  $maxOffset = (int) $settings['updater']['maxCount'];
  if ($maxOffset > 0 && $startIndex >= $maxOffset) {
    $logger->info('Stopping export. Offset limit of ' . $maxOffset . ' reached. Current start index is: ' . $startIndex);
    // stop loop
    $ready = true;
  }

  // break if the last iteration contain less objects than our batchsize has
  // it means, we have everything now
  if ($countOfRecordsInXMLFile < $settings['updater']['batchSize']) {
    // stop loop
    $ready = true;
  }
}

// write results to log and terminate script
$loggerMessage = sprintf(
  'Export finished after %s. Got %d objects. Added %d objects to folder: %s',
  DurationFormatter::format($elapsed),
  $monumentsCounter,
  $monumentsCounterPutToHotfolder,
  $settings['updater']['hotfolder']
);
$logger->info($loggerMessage);
exit(0);
?>