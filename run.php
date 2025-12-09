#!/usr/bin/php
<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/local_settings.php';
require __DIR__ . '/config/common_settings.php';
require __DIR__ . '/lib/functions.php';
require __DIR__ . '/classes/TokenManager.php';
require __DIR__ . '/classes/FileTokenStorage.php';

use Monolog\Logger;
use Monolog\ErrorHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\FingersCrossedHandler;

use GuzzleHttp\Client;

$now = date('Y.m.d__h:i:s', time());

/*
sudo /usr/bin/php /opt/denkmalatlas/kult_dma_object_importer/run.php

--mode=full
--results=1000000
--folder=hot
--offset=0
--limit=1000
--level=warning

*/
$startParameter = getopt("", [
  "mode:",
  "results:",
  "folder:",
  "offset:",
  "limit:",
  "level:",
  "preselect"
]);

$skriptMode = isset($startParameter["mode"]) ? $startParameter["mode"] : 'all';
$maxExportNumber = isset($startParameter["results"]) ? $startParameter["results"] : 1000000;
$startIndex = isset($startParameter["offset"]) ? $startParameter["offset"] : 0;
$batchSize = isset($startParameter["limit"]) ? $startParameter["limit"] : 1000;
$preselect = isset($startParameter["preselect"]) ? true : false;

$destinationPath = $local_settings['cold_folder_path'];
if (isset($startParameter["folder"])) {
  switch ($startParameter["folder"]) {
    case 'hot':
      $destinationPath = $local_settings['hot_folder_path'];
      break;
  }
}

$logLevel = Logger::DEBUG;
if (isset($startParameter["level"])) {
  switch ($startParameter["level"]) {
    case 'warning':
      $logLevel = Logger::WARNING;
      break;
  }
}

$settings = [
  'logger' => [
    'name' => 'wfs_auto_pull',
    'path' => __DIR__ . '/logs/' . $now . '/' . $now . '.log',
    'originalXMLPath' => __DIR__ . '/logs/' . $now . '/' . 'original_xml',
    'splittedXMLPath' => __DIR__ . '/logs/' . $now . '/' . 'splitted_xml',
    'defaultLogLevel' => $logLevel,
    'mailTriggerLevel' => Logger::WARNING,
    'mailRecipient' => $local_settings['recipient'],
    'mailSender' => $local_settings['sender']
  ],
  'updater' => [
    'authUser' => $local_settings['username'],
    'authPwd' => $local_settings['password'],
    'type' => $skriptMode,
    'batchSize' => $batchSize,
    'maxCount' => $maxExportNumber,
    'hotfolder' => $destinationPath,
    'exportUrl' => $local_settings['api_export_url'],
    'preselect' => $preselect
  ]
];

set_exception_handler('exception_routine');
$logger = new Logger($settings['logger']['name']);

// log to stdout
$logger->pushHandler(new StreamHandler('php://stdout', $settings['logger']['defaultLogLevel'])); // <<< uses a stream
// log to file
$logger->pushHandler(new StreamHandler($settings['logger']['path'], $settings['logger']['defaultLogLevel']));
// send logs via mail
$mailHandler = new Monolog\Handler\NativeMailerHandler(
  $settings['logger']['mailRecipient'],
  '[Denkmalatlas] WFS Import Status',
  $settings['logger']['mailSender'],
  $settings['logger']['defaultLogLevel'],
  true,
  2000
);

//$logger->pushHandler(new Monolog\Handler\FingersCrossedHandler($mailHandler, $settings['logger']['mailTriggerLevel']));

$logger->info('Denkmalatlas-WebFeatureService-Auto-Pull-Mechanism-Script startet');
$logger->debug('Settings: ', $settings);
$logger->debug('Parameter: ', $startParameter);


$logger->info('Type is ' . $settings['updater']['type'] . ': Start ' . $settings['updater']['type'] . ' update');

$logger->debug('init guzzle client');

// init guzzle client
$client = new Client([
  'base_uri' => $local_settings['project_url'],
  'timeout' => 600,
  'headers' => ['Accept-Encoding' => 'gzip']
]);

$tokenStorage = new FileTokenStorage(__DIR__ . '/token.json');
$tokenManager = new TokenManager($client, $tokenStorage, $local_settings, $logger);

$ready = false;

// create dir for original xml-batches
mkdir($settings['logger']['originalXMLPath'], 0777, true);

$logger->info('Batchsize: ' . $settings['updater']['batchSize']);

require_once(__DIR__ . '/lib/getImportFiles.php');

$logger->warning('FINALLY: END OF SCRIPT');
exit(0);
?>