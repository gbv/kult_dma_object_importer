<?php
// returns a given filename cleaned up
function sanitizeFilename(string $filename, string $replacement = '_'): string
{
    // 1. unicode normalisation (if intl available)
    if (class_exists('Normalizer')) {
        $filename = Normalizer::normalize($filename, Normalizer::FORM_KD);
    }

    // 2. replace special chars
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

    // 3. replace white space
    $filename = str_replace(' ', $replacement, $filename);

    // 4. replace system chars
    $filename = preg_replace('/[\/\\\\\?\%\*\:\|\\"<>\x00]/u', $replacement, $filename);

    // 5. remove all but chars, numbers, -, _ or .
    $filename = preg_replace('/[^A-Za-z0-9\-\_\.]/u', $replacement, $filename);

    // 6. replace multiple chars like ___ or --
    $filename = preg_replace('/' . preg_quote($replacement, '/') . '+/', $replacement, $filename);

    // 7. remove separators
    $filename = trim($filename, $replacement);

    return $filename;
}

?>
