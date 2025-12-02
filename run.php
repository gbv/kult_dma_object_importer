#!/usr/bin/php
<?php

function sanitizeFilename(string $filename, string $replacement = '_'): string
{
    // 1. Unicode Normalisierung (falls intl verfügbar)
    if (class_exists('Normalizer')) {
        $filename = Normalizer::normalize($filename, Normalizer::FORM_KD);
    }

    // 2. Umlaute & andere Sonderzeichen ersetzen
    $map = [
        'ä' => 'ae', 'Ä' => 'Ae',
        'ö' => 'oe', 'Ö' => 'Oe',
        'ü' => 'ue', 'Ü' => 'Ue',
        'ß' => 'ss',
        'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'á' => 'a', 'à' => 'a', 'â' => 'a',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ç' => 'c'
    ];

    $filename = strtr($filename, $map);

    // 3. Leerzeichen ersetzen
    $filename = str_replace(' ', $replacement, $filename);

    // 4. Illegalen Zeichenblock ersetzen (Windows + allgemein sicher)
    $filename = preg_replace('/[\/\\\\\?\%\*\:\|\\"<>\x00]/u', $replacement, $filename);

    // 5. Alles entfernen, was keine Buchstaben, Zahlen, -, _ oder . ist
    // (z.B. Emojis, sonderbare Unicode-Zeichen)
    $filename = preg_replace('/[^A-Za-z0-9\-\_\.]/u', $replacement, $filename);

    // 6. Mehrfache Zeichen wie ___ oder -- reduzieren
    $filename = preg_replace('/' . preg_quote($replacement, '/') . '+/', $replacement, $filename);

    // 7. Führende / abschließende Trenner entfernen
    $filename = trim($filename, $replacement);

    return $filename;
}

// /usr/bin/php /opt/denkmalatlas/kult_dma_object_importer/run.php full 10000000 cold

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/local_settings.php';
require __DIR__ . '/config/common_settings.php';

use Monolog\Logger;
use Monolog\ErrorHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\FingersCrossedHandler;

use GuzzleHttp\Client;

$now = date('Y.m.d__h:i:s', time());

$allowedTypes = array('full', 'incremental', 'required', 'delete');
if( isset($argv[1]) && in_array($argv[1], $allowedTypes)) {
  $type = $argv[1];
}
else {
  $type = 'full';
}

if( isset($argv[3]) && $argv[3] == 'cold') {
  $destinationPath = $local_settings['cold_folder_path'];
}
else {
  $destinationPath = $local_settings['hot_folder_path'];
}

$settings = [
  'logger' => [
      'name' => 'wfs_auto_pull',
      'path' => __DIR__ . '/logs/' . $now . '/' . $now . '.log',
      'originalXMLPath' => __DIR__ . '/logs/' . $now . '/' . 'original_xml',
      'splittedXMLPath' => __DIR__ . '/logs/' . $now . '/' . 'splitted_xml',
      'defaultLogLevel' => Logger::DEBUG,
      'mailTriggerLevel' => Logger::WARNING,
      'mailRecipient' => $local_settings['recipient'],
      'mailSender' => $local_settings['sender']
  ],
  'updater' => [
    'authUser' => $local_settings['username'],
    'authPwd' => $local_settings['password'],
    'type' => $type,
    'batchSize' => 1000,
    'maxCount' => (isset($argv[2]) == true ? $argv[2] : '10000000000'),
    'hotfolder' => $destinationPath,
    'exportUrl' => $local_settings['api_export_url'],
    'bearer' => 'ory_at_lTzmLbV2eLX59duQXKA7kc_xRttUm2txC5PnSI3Wlz8.H4zsYX_xyeh-yizwmYV_a2TFb_me6WeY7VIO5jb7a6M'
  ],
  'deleter' => [
    'indexedDenkxwebFolder' => '/opt/digiverso/viewer/indexed_denkxweb/',
    'hotfolder' => $destinationPath
  ]
];

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
    '[Denkmalatlas] WFS Import Status',
    $settings['logger']['mailSender'],
    $settings['logger']['defaultLogLevel'],
    true,
    2000
);


//$logger->pushHandler(new Monolog\Handler\FingersCrossedHandler($mailHandler, $settings['logger']['mailTriggerLevel']));

$logger->info('Denkmalatlas-WebFeatureService-Auto-Pull-Mechanism-Script startet');
$logger->debug('Settings:', $settings);

$logger->info('Type is ' . $settings['updater']['type'] . ': Start ' . $settings['updater']['type'] . ' update');

$logger->debug('init guzzle client');

// init guzzle client
$client = new Client([
    'base_uri' => $local_settings['project_url'],
    'timeout'  => 600,
    'headers'  => ['Accept-Encoding' => 'gzip']
]);


  try {
    $response = $client->request('POST', $local_settings['api_token_url'], ['form_params' => ['username'=>'denkxweb', 'password' => 'vFt#!rAkdCe4bm', 'client_id' => 'web-client', 'client_secret' => 'foo','grant_type' => 'password','scope' => 'offline'] ]);
  } catch (Throwable $t) {
      // Handle exception
      $m = $testURL . ' not available';
      $logger->error($m);
      throw new Exception($m);
  }
    $body = $response->getBody()->getContents();
    $data = json_decode($body, true);
    $settings['updater']['bearer'] = 'Bearer ' . $data['access_token'];


// basic availability-tests
/*
$tests = [
  'Schema' => 'http://geoportal.geodaten.niedersachsen.de/adabweb/schema/ogc/wfs/2.0/wfs.xsd',
  'GetCapabilities' => $settings['updater']['baseUrl'] . '?SERVICE=WFS&VERSION=2.0.0&REQUEST=GetCapabilities',
  'DescribeStoredQueries' => $settings['updater']['baseUrl'] . '?SERVICE=WFS&VERSION=2.0.0&REQUEST=DescribeStoredQueries'
];
*/
/* skip tests
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
  */

$ready = false;
$startIndex = 0;

// create dir for original xml-batches
mkdir($settings['logger']['originalXMLPath'], 0777, true);

$logger->info('Batchsize: ' . $settings['updater']['batchSize']);

switch ($settings['updater']['type']) {
    case 'full':
        require_once('/opt/denkmalatlas/kult_dma_object_importer/lib/getFullUpdate.inc.php');
        break;
    case 'incremental':
        require_once('/opt/denkmalatlas/kult_dma_object_importer/lib/getIncrementalUpdate.inc.php');
        break;
    case 'required':
      require_once('/opt/denkmalatlas/kult_dma_object_importer/lib/getRequiredUpdate.inc.php');
      break;
    case 'delete':
        require_once('/opt/denkmalatlas/kult_dma_object_importer/lib/deleteAllIndexedRecords.inc.php');
        break;
}

$logger->warning('FINALLY: END OF SCRIPT');
exit;
?>
