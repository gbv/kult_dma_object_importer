<?php
namespace Denkmalatlas;

class MappingWriter
{
    private string $targetFolder;

    public function __construct($settings)
    {
        $this->targetFolder = rtrim($settings->targetFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function writeMapping(string $uuId, string $fylrId, string $adabwebId): void
    {
        $idMappingRow = $uuId . ';' . $fylrId . ';' . $adabwebId . "\n";
        file_put_contents($this->targetFolder . 'mapping.cvs', $idMappingRow, FILE_APPEND);
    }

    public function writeRedirect(string $adabwebId, string $uuId): void
    {
        if ($adabwebId != "0") {
            $idMappingRedirect = $adabwebId . " " . $uuId . "\n";
            file_put_contents($this->targetFolder . 'redirects.txt', $idMappingRedirect, FILE_APPEND);
        }
    }
}
