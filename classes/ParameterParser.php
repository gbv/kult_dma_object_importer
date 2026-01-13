<?php

namespace Denkmalatlas;
class ParameterParser
{
    /**
     * reads start parameters and return them as array
     */
    public static function parse(array $argv): array
    {
        $startParameter = getopt("", [
            "results:",
            "folder:",
            "offset:",
            "limit:",
            "level:",
            "preselect",
            "skip-images",
            "force-images",
            "id-mapping",
            "missing-images-only",
            "from:"
        ]);

        return $startParameter;
    }
}
