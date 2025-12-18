<?php
namespace Denkmalatlas;

use Denkmalatlas\SettingsManager;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class LoggerFactory
{
    public static function create(SettingsManager $settings): Logger
    {
        $logger = new Logger($settings->name);

        // custom date format
        $dateFormat = "d.m.Y H:i:s";
        $output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
        $formatter = new LineFormatter($output, $dateFormat);

        // define log handler
        // set handler for stdout
        $stdOutHandler = new StreamHandler('php://stdout', $settings->logLevel);
        $stdOutHandler->setFormatter($formatter);
        $logger->pushHandler($stdOutHandler);

        // set handler for file
        $fileHandler = new StreamHandler($settings->path, $settings->logLevel);
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);

        // send handler for mail
        $mailHandler = new NativeMailerHandler(
            $settings->mailRecipient,
            '[Denkmalatlas] DenkXport Status',
            $settings->mailSender,
            $settings->logLevel,
            true,
            2000
        );
        $mailHandler->setFormatter(new HtmlFormatter($dateFormat));

        $bufferHandler = new BufferHandler(
            $mailHandler,
            $settings->mailBufferLimit,
            $settings->logLevel,
            true,
            true
        );
        $logger->pushHandler($bufferHandler);
        return $logger;
    }
}
?>