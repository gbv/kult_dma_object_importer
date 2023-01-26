#!/usr/bin/php
<?php

require '/opt/digiverso/kult_dma_object_importer/vendor/autoload.php';

use Monolog\Logger;
use Monolog\ErrorHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\FingersCrossedHandler;

use GuzzleHttp\Client;

$now = date('Y.m.d__h:i:s', time());

/*
function rrmdir($dir) {
   if (is_dir($dir)) {
     $objects = scandir($dir);
     foreach ($objects as $object) {
       if ($object != "." && $object != "..") {
         if (is_dir($dir."/".$object) && !is_link($dir."/".$object))
           rrmdir($dir."/".$object);
         else
           unlink($dir."/".$object);
       }
     }
     rmdir($dir);
   }
 }
rrmdir('logs/test');
$now = "test";
*/

$allowedTypes = array('full', 'incremental', 'delete');
if(in_array($argv[1], $allowedTypes)) {
  $type = $argv[1];
}
else {
  $type = 'full';
}

require '/opt/digiverso/kult_dma_object_importer/config/project_settings.php';

include('lib/exception_routine.inc.php');
set_exception_handler('exception_routine');

$logger = new Logger($settings['logger']['name']);

// log to stdout
$logger->pushHandler(new StreamHandler('php://stdout', $settings['logger']['defaultLogLevel'])); // <<< uses a stream
// log to file
$logger->pushHandler(new StreamHandler($settings['logger']['path'], $settings['logger']['defaultLogLevel']));
// send logs via mail
$mailHandler = new Monolog\Handler\NativeMailerHandler(
    $settings['logger']['mailRecipient'],
    '[Denkmalatlas] WFS Auto Pull Results',
    $settings['logger']['mailSender'],
    $settings['logger']['defaultLogLevel'],
    true,
    2000
);
$logger->pushHandler(new Monolog\Handler\FingersCrossedHandler($mailHandler, $settings['logger']['mailTriggerLevel']));

$logger->info('Denkmalatlas-WebFeatureService-Auto-Pull-Mechanism-Script startet');
$logger->debug('Settings:', $settings);

$logger->info('Type is ' . $settings['updater']['type'] . ': Start ' . $settings['updater']['type'] . ' update');

$logger->debug('init guzzle client');

// init guzzle client
$client = new Client([
    'base_uri' => 'https://denkmalatlas.gbv.de',
    'timeout'  => 600,
    'headers'  => ['Accept-Encoding' => 'gzip']
]);

// basic availability-tests
$tests = [
  'Schema' => 'http://geoportal.geodaten.niedersachsen.de/adabweb/schema/ogc/wfs/2.0/wfs.xsd',
  'GetCapabilities' => $settings['updater']['baseUrl'] . '?SERVICE=WFS&VERSION=2.0.0&REQUEST=GetCapabilities',
  'DescribeStoredQueries' => $settings['updater']['baseUrl'] . '?SERVICE=WFS&VERSION=2.0.0&REQUEST=DescribeStoredQueries'
];
foreach($tests as $testKey=>$testURL) {
  $logger->debug('ask for ' . $testKey . ' @ ' . $testURL);
  try {
    $response = $client->request('GET', $testURL, ['auth' => [$settings['updater']['authUser'], $settings['updater']['authPwd']]]);
  } catch (Throwable $t) {
      // Handle exception
      $m = $testURL . ' not available';
      $logger->error($m);
      throw new Exception($m);
  }

  $logger->debug('Status ' . $response->getStatusCode());
  $logger->debug('Reason ' . $response->getReasonPhrase());

  if($response->getStatusCode() != 200) {
    $m = $testURL . ' not available';
    $logger->error($m);
    throw new Exception($m);

  }
  if($response->getReasonPhrase() != 'OK') {
    $m = 'Reason is not OK, but: ' . $response->getReasonPhrase();
    $logger->error($m);
    throw new Exception($m);
  }
}

// MAIN-Routine
// - 1. Hole alle
// - 2. Hole alle ab einem bestimmten Datum
// - 3. Leere den Index

$ready = false;
$startIndex = 0;

// create dir for original xml-batches
mkdir($settings['logger']['originalXMLPath'], 0777, true);

$logger->info('Batchsize: ' . $settings['updater']['batchSize']);

switch ($settings['updater']['type']) {
    case 'full':
        require_once('/opt/digiverso/kult_dma_object_importer/lib/getFullUpdate.inc.php');
        break;
    case 'incremental':
        require_once('/opt/digiverso/kult_dma_object_importer/lib/getIncrementalUpdate.inc.php');
        break;
    case 'delete':
        require_once('/opt/digiverso/kult_dma_object_importer/lib/deleteAllIndexedRecords.inc.php');
        break;
}

$logger->warning('FINALLY: END OF SCRIPT');
exit;
?>
