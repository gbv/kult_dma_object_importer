#!/usr/bin/php
<?php

// get composer libararies and project classes
require __DIR__ . '/vendor/autoload.php';

// declare class namespaces
use Denkmalatlas\ApiRequester;
use Denkmalatlas\BatchProcessor;
use Denkmalatlas\DurationFormatter;
use Denkmalatlas\ExceptionHandler;
use Denkmalatlas\FileTokenStorage;
use Denkmalatlas\ImageDownloader;
use Denkmalatlas\LoggerFactory;
use Denkmalatlas\MappingWriter;
use Denkmalatlas\MonumentXmlProcessor;
use Denkmalatlas\SettingsManager;
use Denkmalatlas\TokenManager;
use GuzzleHttp\Client;

// build settings
$settings = new SettingsManager($argv);

$logger = LoggerFactory::create($settings);
$logger->debug(
  'Logger initialized. Script is running with those settings: ',
  (array)
  $settings
);
$logger->info(
  'Export started with those parameters: ',
$settings->startParameter
);

$logger->debug('Initialize guzzle as request client.');
$client = new Client([
  'base_uri' => $settings->projectUrl,
  'timeout' => 600,
  'headers' => ['Accept-Encoding' => 'gzip']
]);

$logger->debug('Initialize the auth token manager.');
$tokenStorage = new FileTokenStorage(__DIR__ . '/token.json');
$tokenManager = new TokenManager(
  $client,
  $tokenStorage,
  $settings,
  $logger
);

$logger->debug('Initialize a exception handler.');
ExceptionHandler::setLogger($logger);
set_exception_handler([ExceptionHandler::class, 'handle']);

$logger->debug('Initialize api request handler.');
$apiRequester = new ApiRequester(
  $client,
  $tokenManager,
  $logger
);

$logger->debug('Initialize the image downloader.');
$imageDownloader = new ImageDownloader(
  $settings->dataFolderPath,
  $logger
);

$logger->debug('Initialize our id mapping writer.');
$mappingWriter = new MappingWriter($settings);

$logger->debug('Initialize xml processor.');
$monumentXmlProcessor = new MonumentXmlProcessor($settings);

// create dir for original xml-batches
$logger->debug('Create directory to store request XML.');
mkdir($settings->originalXMLPath, 0777, true);

// set batch variables
$monumentsCounter = 0;
$monumentsCounterPutToTargetFolder = 0;
$startTime = microtime(true);

if ( !empty($settings->uuid) ) {
    $logger->debug('Starting single object import for uuid: ' . $settings->uuid);
} else {
    $logger->debug('Starting the batch process. Batchsize: ' . ($settings->batchSize));
}

$batchProcessor = new BatchProcessor(
  $settings,
  $logger,
  $apiRequester,
  $imageDownloader,
  $mappingWriter,
  $monumentXmlProcessor,
  updateList: $settings->updateList
);
[$monumentsCounter, $monumentsCounterPutToTargetFolder] = $batchProcessor->processBatches(
  $settings->startIndex,
  $monumentsCounter,
  $monumentsCounterPutToTargetFolder,
  $startTime
);

$elapsed = microtime(true) - $startTime;

$loggerMessage = sprintf(
  'Export finished after %s. Got %d objects. Added %d objects to folder: %s',
  DurationFormatter::format($elapsed),
  $monumentsCounter,
  $monumentsCounterPutToTargetFolder,
  $settings->targetFolder
);
$logger->info($loggerMessage);
exit(0);
?>