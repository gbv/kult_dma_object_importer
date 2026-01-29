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

    public function generatePurgeFiles(): int
    {
        $this->logger->info('Starting purge marker generation.');
        $purgeList = "";

        // Load public IDs
        $this->logger->info('Loading public IDs for membership test...');
        $publicData = $this->getPublicIds();
        $publicIds = $publicData['publicIds'] ?? [];
        $publicReportedTotal = $publicData['total'] ?? null;
        $publicSet = array_flip($publicIds);
        $this->logger->info('Public IDs loaded: ' . count($publicIds) . '. Reported total: ' . ($publicReportedTotal ?? 'unknown'));

        if ( count($publicIds) != $publicReportedTotal && count($publicIds) > 100000 ) {
          $this->logger->error('Something is badly wrong. Purge stoped.');
          return 0;
        }

        // Load Solr known IDs
        $this->logger->info('Loading known IDs from Solr...');
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
                        $this->logger->warning('Failed to create purge marker for ' . $id . ' at ' . $filePath);
                    } else {
                        $purgeList .= $id . "\n";
                        $written++;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Exception while creating purge marker for ' . $id . ': ' . $e->getMessage());
                }
            }
        }

        $this->logger->info('Purge markers generation completed. Solr count: ' . count($knownIds) . ', public count: ' . count($publicIds) . ', markers created: ' . $written . ' (folder: ' . $this->settings->targetFolder . ')');
        $this->logger->info($purgeList);
        if ($publicReportedTotal !== null && $publicReportedTotal != count($publicIds)) {
            $this->logger->warning('Mismatch between reported public total (' . $publicReportedTotal . ') and collected public IDs (' . count($publicIds) . ').');
        }

        return 0;
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
            $maxAttempts = 3;
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
                $this->logger->error('Failed to fetch/parse public listing at offset ' . $offset . ' after ' . $maxAttempts . ' attempts. Aborting public fetch.');
                break;
            }

            if ($reportedTotal === null) {
                $reportedTotal = $data['total'] ?? null;
                $this->logger->info('Public listing reported total: ' . ($reportedTotal ?? 'unknown'));
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
        $this->logger->debug('Loading list of known IDs from Solr system.');

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

    public function getKnownIdsAsJson(): string
    {
        $knownIdsData = $this->getKnownIds();
        return json_encode($knownIdsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
