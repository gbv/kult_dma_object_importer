<?php
namespace Denkmalatlas;

use Psr\Log\LoggerInterface;

class PurgeFileGenerator
{
    private $settings;
    private LoggerInterface $logger;
    private ApiRequester $apiRequester;

    public function __construct(
        $settings,
        LoggerInterface $logger,
        ApiRequester $apiRequester
    ) {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->apiRequester = $apiRequester;
    }

    public function generatePurgeFiles(): void
    {
        $this->logger->info('Starting purge files generation.');
        $purgeList = "";

        $this->logger->info('Get public IDs from NFIS to compare with Solr index.');
        $publicData = $this->getPublicIds();
        if ( empty($publicData) ) {
          $this->logger->error('Purge stoped. At least one API request to NFIS faild.');
          return;
        }

        $publicIds = $publicData['publicIds'] ?? [];
        $publicReportedTotal = $publicData['total'] ?? null;
        $publicSet = array_flip($publicIds);
        $this->logger->info(
          'Public IDs loaded: '
          . count($publicIds) . PHP_EOL
          . 'Reported total: '
          . ($publicReportedTotal ?? 'unknown'));

        $countedPublicIds = count($publicIds);

        if ( $countedPublicIds != $publicReportedTotal ) {
          $loggerMessage = sprintf(
              'Purge stoped. Reported IDs from API does not fit counted IDs by script.'. PHP_EOL
              . 'Reported: %d' . PHP_EOL
              . 'Counted: %d',
              $publicReportedTotal,
              $countedPublicIds
          );
          $this->logger->error($loggerMessage);
          return;
        }

        if ( $countedPublicIds < $this->settings->minExpectedPublicIds ) {
          $loggerMessage = sprintf(
              'Purge stoped. Counted IDs does not fit expected min number.' . PHP_EOL
              . 'Counted: %d' . PHP_EOL
              . 'Expected: %d',
              $countedPublicIds,
              $this->settings->minExpectedPublicIds
          );
          $this->logger->error($loggerMessage);
          return;
        }

        $this->logger->info('Loading known IDs from Solr Index.');
        $knownData = $this->getKnownIds();
        $knownIds = $knownData['knownIds'] ?? [];
        $this->logger->info('Solr IDs loaded: ' . count($knownIds));
        $written = 0;
        $processed = 0;

        foreach ($knownIds as $id) {
            $processed++;
            $inSolr = 1;
            $inPublic = isset($publicSet[$id]) ? 1 : 0;

            if (!$inPublic) {
                $filePath = rtrim($this->settings->targetFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . '.purge';
                try {
                    if (@touch($filePath) === false) {
                        $this->logger->warning('Failed to create purge file for ' . $id . ' at ' . $filePath);
                    } else {
                        $purgeList .= $id . "\n";
                        $written++;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Exception while creating purge file for ' . $id . ': ' . $e->getMessage());
                }
            }
        }

        $this->logger->info(
          'Purge files generation completed.' . PHP_EOL
          . 'NFIS: ' . count($publicIds) . PHP_EOL
          . 'Solr: ' . count($knownIds) . PHP_EOL
          . 'Files: ' . $written . PHP_EOL
          . 'Target: ' . $this->settings->targetFolder );
        $this->logger->info(
          'Objects marked to remove: '. PHP_EOL .
          print_r($purgeList, true)
        );
        return;
    }
    private function getPublicIds(): array
    {
        $this->logger->debug('Fetching public IDs from: ' . $this->settings->exportAllObjectIdsUrl);

        $publicIds = [];
        $offset = 0;
        $limit = 1000;
        $reportedTotal = null;

        while (true) {
            $url = sprintf('%s?limit=%d&offset=%d', $this->settings->exportAllObjectIdsUrl, $limit, $offset);
            $this->logger->debug('Fetching public batch: ' . $url);

            $attempt = 0;
            $maxAttempts = 5;
            $data = null;

            while ($attempt < $maxAttempts) {
                try {
                    $response = $this->apiRequester->request($url);
                    $jsonString = $response->getBody()->getContents();
                    $data = json_decode($jsonString, true);
                    if ($data !== null) break;
                } catch (\Throwable $e) {
                    $this->logger->warning('Public batch request failed (attempt ' . ($attempt + 1) . '): ' . $e->getMessage());
                }
                $attempt++;
                sleep($attempt);
            }

            if ($data === null) {
                $loggerMessage = sprintf(
                    'Fetching public IDs stoped.' . PHP_EOL
                    . 'Failed to get listing at offset %d' . PHP_EOL
                    . 'after %d attempts.',
                    $offset,
                    $maxAttempts
                );
                $this->logger->error($loggerMessage);
                return [];
            }

            if ($reportedTotal === null) {
                $reportedTotal = $data['total'] ?? null;
                $this->logger->info('Public objects reported by API: ' . ($reportedTotal ?? 'unknown'));
            }

            $batch = $data['data'] ?? [];
            foreach ($batch as $id) {
                $publicIds[] = $id;
            }

            if (count($batch) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return ['publicIds' => $publicIds, 'total' => $reportedTotal];
    }

    private function getKnownIds(): array
    {
        $this->logger->debug('Loading list of known IDs from Solr.');

        try {
            $knownIds = [];
            $totalInSolr = null;
            $offset = 0;
            $limit = 1000;

            while (true) {
                $url = sprintf(
                    '%s?q=PI:*&rows=%d&start=%d&fl=PI&wt=json',
                    $this->settings->solrBaseUrl,
                    $limit,
                    $offset
                );

                $this->logger->debug('Fetching known IDs from Solr: ' . $url);

                $response = $this->apiRequester->request($url);
                $jsonString = $response->getBody()->getContents();
                $data = json_decode($jsonString, true);

                if ($data === null) {
                    $this->logger->error('Failed to decode JSON response from Solr');
                    break;
                }

                if ($totalInSolr === null) {
                    $totalInSolr = $data['response']['numFound'] ?? 0;
                    $this->logger->info('Total objects in Solr: ' . $totalInSolr);
                }

                $docs = $data['response']['docs'] ?? [];
                foreach ($docs as $doc) {
                    if (isset($doc['PI'])) {
                        $knownIds[] = $doc['PI'];
                    }
                }

                $this->logger->debug('Fetched ' . count($docs) . ' documents from Solr. Total IDs collected so far: ' . count($knownIds));

                if (count($knownIds) >= $totalInSolr || count($docs) < $limit) {
                    break;
                }

                $offset += $limit;
            }

            $result = [
                'knownIds' => $knownIds,
                'total' => count($knownIds),
                'timestamp' => date('Y-m-d H:i:s')
            ];

            $this->logger->debug('Successfully retrieved ' . count($knownIds) . ' known IDs from Solr.');

            return $result;
        } catch (\Throwable $t) {
            $this->logger->error('Error loading known IDs from Solr: ' . $t->getMessage());
            return [
                'knownIds' => [],
                'total' => 0,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
}
