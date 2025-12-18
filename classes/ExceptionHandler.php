<?php
namespace Denkmalatlas;

use Psr\Log\LoggerInterface;

class ExceptionHandler
{
    private static ?LoggerInterface $logger = null;

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public static function handle($exception): void
    {
        if (self::$logger instanceof LoggerInterface) {
            self::$logger->error('Unhandled exception', ['exception' => $exception]);
        } else {
            fwrite(STDERR, PHP_EOL . 'Unhandled exception: ' . $exception->getMessage() . PHP_EOL);
        }
        exit(1);
    }
}
?>