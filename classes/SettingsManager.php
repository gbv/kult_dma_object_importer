<?php

namespace Denkmalatlas;

use Denkmalatlas\ParameterParser;

class SettingsManager
{
  public array $updateList;
  public string $name;
  public string $path;
  public string $originalXMLPath;
  public string $splittedXMLPath;
  public int $mailBufferLimit;
  public $logLevel;
  public string $targetFolder;
  public int $batchSize;
  public int $maxCount;
  public int $startIndex;
  public string $mailRecipient;
  public string $mailSender;
  public string $dataFolderPath;
  public string $exportAllObjectsUrl;
  public string $exportAllObjectIdsUrl;
  public string $exportSingleObjectUrl;
  public string $tokenUrl;
  public string $apiUsername;
  public string $apiPassword;
  public string $projectUrl;
  public string $xmlHeader;
  public string $monumentsNameSpace;
  public bool $updateById;
  public bool $skipImage;
  public bool $forceImage;
  public bool $idMapping;
  public bool $missingImagesOnly;
  public array $startParameter;
  public string $startFrom;
  public string $uuid;

  public function __construct($argv)
  {
    // get settings from files
    require __DIR__ . '/../config/localSettings.php';
    require __DIR__ . '/../config/commonSettings.php';

    // read updateList from file (one ID per line)
    $updateListFile = __DIR__ . '/../config/updateList.txt';
    if (file_exists($updateListFile)) {
      $this->updateList = file($updateListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    } else {
      $this->updateList = [];
    }

    // get params from script start
    $parameterSettings = ParameterParser::parse($argv);

    // store current time
    $now = date('Y-m-d__h-i-s', time());

    // set properties
    $this->name = 'DenkXport';
    $this->path = __DIR__ . '/../logs/' . $now . '/' . $now . '.log';
    $this->originalXMLPath = __DIR__ . '/../logs/' . $now . '/' . 'original_xml';
    $this->splittedXMLPath = __DIR__ . '/../logs/' . $now . '/' . 'splitted_xml';
    $this->mailBufferLimit = 100;

    // set properties from local file
    $this->mailRecipient = $localSettings['mailRecipient'] ?? '';
    $this->mailSender = $localSettings['mailSender'] ?? '';
    $this->dataFolderPath = $localSettings['dataFolderPath'] ?? '';
    $this->exportAllObjectsUrl = ($localSettings['apiBaseUrl'] ?? '') . ($localSettings['apiPaths']['allObjects'] ?? '');
    $this->exportAllObjectIdsUrl = ($localSettings['apiBaseUrl'] ?? '') . ($localSettings['apiPaths']['allObjectIds'] ?? '');
    $this->exportSingleObjectUrl = ($localSettings['apiBaseUrl'] ?? '') . ($localSettings['apiPaths']['singleObject'] ?? '');
    $this->tokenUrl = $localSettings['apiTokenUrl'] ?? '';
    $this->apiUsername = $localSettings['apiUsername'] ?? '';
    $this->apiPassword = $localSettings['apiPassword'] ?? '';
    $this->projectUrl = $localSettings['projectUrl'] ?? '';

    // set properties from common settings file
    $this->xmlHeader = $commonSettings['xmlHeader'] ?? '';
    $this->monumentsNameSpace = $commonSettings['monumentsNameSpace'] ?? '';

    // set properties from script start
    // todo: validate parameters and stop script on error
    // invert flags - a given flag parameter has false as value
    $this->updateById = isset($parameterSettings["preselect"]);
    $this->skipImage = isset($parameterSettings["skip-images"]);
    $this->forceImage = isset($parameterSettings["force-images"]);
    $this->idMapping = isset($parameterSettings["id-mapping"]);
    $this->missingImagesOnly = isset($parameterSettings["missing-images-only"]);
    $this->startParameter = $parameterSettings ?? [];
    $this->batchSize = $parameterSettings["limit"] ?? 1000;
    $this->maxCount = $parameterSettings["results"] ?? 1000000;
    $this->startIndex = $parameterSettings["offset"] ?? 0;
    $this->startFrom = $parameterSettings["from"] ?? '';
    $this->uuid = $parameterSettings["uuid"] ?? '';
    $this->targetFolder = $localSettings['coldFolderPath'] ?? '';
    if (isset($parameterSettings["folder"])) {
      switch ($parameterSettings["folder"]) {
        case 'hot':
          $this->targetFolder = $localSettings['hotFolderPath'] ?? '';
          break;
      }
    }
    if (isset($parameterSettings["level"])) {
      $this->logLevel = match ($parameterSettings["level"]) {
        'debug' => \Monolog\Logger::DEBUG,
        'warning' => \Monolog\Logger::WARNING,
        default => \Monolog\Logger::INFO,
      };
    } else {
      $this->logLevel = \Monolog\Logger::INFO;
    }
  }
}
