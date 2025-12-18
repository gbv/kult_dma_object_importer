<?php
namespace Denkmalatlas;

use Psr\Log\LoggerInterface;

class ImageDownloader
{
    private string $dataFolder;
    private LoggerInterface $logger;

    public function __construct(string $dataFolder, LoggerInterface $logger)
    {
        $this->dataFolder = $dataFolder;
        $this->logger = $logger;
    }

    public function locateImage(string $filename, string $id): ?string
    {
        $command = sprintf(
            'locate --existing --basename %s | grep %s | head -n 1',
            escapeshellarg($filename),
            escapeshellarg('/' . $id . '/')
        );
        $output = shell_exec($command);
        return trim($output) !== '' ? trim($output) : null;
    }

    public function downloadImage(string $url, string $savePath): bool
    {
        $command = "wget " . escapeshellarg($url) . " -O " . escapeshellarg($savePath);
        exec($command . " 2>&1", $output, $return_var);
        if ($return_var !== 0) {
            $this->logger->error('Image download failed', [
                'command' => $command,
                'output' => $output,
                'return_var' => $return_var,
                'imageUrl' => $url,
                'savePath' => $savePath
            ]);
            return false;
        }
        return true;
    }
}
