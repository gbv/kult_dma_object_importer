<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Formatter\LineFormatter;

class LoggerFactory
{
    public static function create(array $settings): Logger
    {
        $logger = new Logger($settings['name']);

        // custom date format
        $dateFormat = "d.m.Y H:i:s";
        $output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
        $formatter = new LineFormatter($output, $dateFormat);

        // define log handler
        // set handler for stdout
        $stdOutHandler = new StreamHandler('php://stdout', $settings['defaultLogLevel']);
        $stdOutHandler->setFormatter($formatter);
        $logger->pushHandler($stdOutHandler);

        // set handler for file
        $fileHandler = new StreamHandler($settings['path'], $settings['defaultLogLevel']);
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);

        // send handler for mail
        $mailHandler = new NativeMailerHandler(
            $settings['mailRecipient'],
            '[Denkmalatlas] DenkXport Status',
            $settings['mailSender'],
            $settings['defaultLogLevel'],
            true,
            2000
        );
        $mailHandler->setFormatter(new HtmlFormatter($dateFormat));
        $logger->pushHandler(new FingersCrossedHandler($mailHandler, $settings['mailTriggerLevel']));

        return $logger;
    }
}
?>