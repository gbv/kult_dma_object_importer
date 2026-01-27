<?php

namespace Denkmalatlas;

use Psr\Log\LoggerInterface;

class BatchProcessor
{
    private $settings;
    private array $commonSettings;
    private LoggerInterface $logger;
    private ApiRequester $apiRequester;
    private ImageDownloader $imageDownloader;
    private MappingWriter $mappingWriter;
    private MonumentXmlProcessor $monumentXmlProcessor;
    private array $updateList;

    public function __construct(
        $settings,
        LoggerInterface $logger,
        ApiRequester $apiRequester,
        ImageDownloader $imageDownloader,
        MappingWriter $mappingWriter,
        MonumentXmlProcessor $monumentXmlProcessor,
        array $updateList
    ) {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->apiRequester = $apiRequester;
        $this->imageDownloader = $imageDownloader;
        $this->mappingWriter = $mappingWriter;
        $this->monumentXmlProcessor = $monumentXmlProcessor;
        $this->updateList = $updateList;
    }

    public function processBatches(
      int $startIndex,
      int $monumentsCounter,
      int $monumentsCounterPutToTargetFolder,
      float $startTime): array
    {
        $batchFinished = false;
        while (!$batchFinished) {
            $endIndex = $startIndex + $this->settings->batchSize;
            $url = sprintf(
                '%s?limit=%d&offset=%d',
                $this->settings->exportAllObjectsUrl,
                $this->settings->batchSize,
                $startIndex

            );

            if (!empty($this->settings->startFrom)) {
                $url .= '&fromDate=' . urlencode($this->settings->startFrom);
            }

            $this->logger->debug('Startindex is now ' . $startIndex);
            $this->logger->debug('Request to ' . $url);

            $maxRetries = 3;
            $attempt = 0;
            $delaySeconds = 2;
            $response = null;
            while ($attempt < $maxRetries) {
                $attempt++;
                try {
                    $response = $this->apiRequester->request($url);
                    break;
                } catch (\Throwable $t) {
                    $this->logger->warning("Request attempt {$attempt} failed: " . $t->getMessage());
                    if ($attempt >= $maxRetries) {
                        $this->logger->error("All {$maxRetries} attempts failed for URL: {$url}");
                        $startIndex += $this->settings->batchSize;
                        $this->logger->warning('Skipping to next batch due to repeated request errors. New startIndex: ' . $startIndex);
                        continue 2;
                    }
                    sleep($delaySeconds);
                    $delaySeconds *= 2;
                    continue;
                }
            }

            $xmlString = $response->getBody()->getContents();
            $xmlBatchFileName = sprintf(
                '%s/batch_%d_to_%d.xml',
                $this->settings->originalXMLPath,
                $startIndex,
                $endIndex
            );
            file_put_contents($xmlBatchFileName, $xmlString);
            $xmlBatchDirName = sprintf(
                '%s/%d_to_%d',
                $this->settings->splittedXMLPath,
                $startIndex,
                $endIndex
            );
            mkdir($xmlBatchDirName, 0777, true);
            $xmlString = preg_replace('/\bdebug:/', '', $xmlString);
            $xml = simplexml_load_string($xmlString);
            $xml->registerXPathNamespace('dma', 'http://www.rjm.de/denkxweb/denkxml');
            $monuments = $xml->xpath('//dma:monument');
            $countOfRecordsInXMLFile = count($monuments);
            $this->logger->debug('We count ' . $countOfRecordsInXMLFile . ' monuments in Response File.');

            foreach ($monuments as $monument) {
                $monumentsCounter++;
                $uuId = (string) $monument->uuId;
                if ($monument->error) {
                    $this->logger->error("Fehler in Objekt: " . $uuId);
                    $this->logger->error($monument->error->message);
                    $this->logger->error($monument->error->stack);
                    continue;
                }
                $objectId = (string) $monument->recId;
                $fylrId = (string) $monument->fylrId;
                $adabwebId = (string) $monument->adabwebId;
                if (!$adabwebId) {
                    $adabwebId = "0";
                }
                $id = $objectId;
                $validId = $this->settings->updateById ? in_array($fylrId, $this->updateList) : true;
                if ($validId) {
                    if($this->settings->missingImagesOnly) {
                      $needUpdate = false;
                    } else {
                      $needUpdate = true;
                    }
                    if ($this->settings->idMapping) {
                        $this->mappingWriter->writeMapping($uuId, $fylrId, $adabwebId);
                        $this->mappingWriter->writeRedirect($adabwebId, $uuId);
                    }
                    if ($monument->images) {
                        $this->logger->debug('XML enthält Bilder.');
                        $downloadImageDirPath = $this->settings->targetFolder . $id . '_media';
                        foreach ($monument->images->image as $image) {
                            $filename = (string) $image->filename;
                            $saveFilename = FileNameSanitizer::sanitizeStatic($filename);
                            $this->logger->debug('Bildname: ' . $saveFilename . ' Im Verzeichnis: ' . $id . ' Unterhalb von: ' . $this->settings->dataFolderPath);
                            if (!$this->settings->skipImage) {
                                $located = $this->imageDownloader->locateImage($saveFilename, $id);
                                $immageNotLocated = $located === null;
                                if ($immageNotLocated || $this->settings->forceImage) {
                                    $needUpdate = true;
                                    $this->logger->debug('Existiert noch nicht oder soll ersetzt werden. Starte download.');
                                    if (!file_exists($downloadImageDirPath)) {
                                        mkdir($downloadImageDirPath, 0777, true);
                                    }
                                    $imageUrl = (string) $image->standard->attributes()->{'url'};
                                    $success = $this->imageDownloader->downloadImage($imageUrl, $downloadImageDirPath . '/' . $saveFilename);
                                } else {
                                    $this->logger->debug('Existiert. Gehe zum nächsten Bild.');
                                }
                            }
                            $image->standard['url'] = $id . '_media/' . $saveFilename;
                        }
                    }
                    $xmlStrMonument = $this->monumentXmlProcessor->transformMonumentXml($monument, $id);
                    if ($xmlStrMonument !== null) {
                        if ($id && $needUpdate) {
                            $monumentsCounterPutToTargetFolder++;
                            file_put_contents($this->settings->splittedXMLPath . '/' . $startIndex . '_to_' . ($endIndex) . '/' . $id . '.xml', $xmlStrMonument);
                            file_put_contents($this->settings->targetFolder . $id . '.xml', $xmlStrMonument);
                        }
                    } else {
                        $m = 'Could not store results in XML. Error logged. Try next object now.';
                        $this->logger->error($m);
                        continue;
                    }
                    if ($monumentsCounter >= $this->settings->maxCount) {
                        $loggerMessage = sprintf(
                            'Stopping export. Result limit of %d reached. Processed %d monuments.',
                            $this->settings->maxCount,
                            $monumentsCounter
                        );
                        $this->logger->info($loggerMessage);
                        $batchFinished = true;
                        break;
                    }
                }
            }
            $elapsed = microtime(true) - $startTime;
            $startIndex += $this->settings->batchSize;
            $this->logger->info('Reached ' . $monumentsCounter . ' Objects. Offset is now: ' . $startIndex . ' This export took ' . DurationFormatter::format($elapsed) . ' so far.');
            $maxOffset = (int) $this->settings->maxCount;
            if ($maxOffset > 0 && $startIndex >= $maxOffset) {
                $this->logger->info('Stopping export. Offset limit of ' . $maxOffset . ' reached. Current start index is: ' . $startIndex);
                $batchFinished = true;
            }
            if ($countOfRecordsInXMLFile < $this->settings->batchSize) {
                $batchFinished = true;
            }
        }
        return [$monumentsCounter, $monumentsCounterPutToTargetFolder];
    }
}
