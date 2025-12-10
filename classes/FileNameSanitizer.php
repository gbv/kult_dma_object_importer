<?php

class FileNameSanitizer
{
    public function sanitize(string $filename, string $replacement = '_'): string
    {
        if (class_exists('Normalizer')) {
            $filename = Normalizer::normalize($filename, Normalizer::FORM_KD);
        }

        $map = [
            'ä' => 'ae','Ä' => 'Ae','ö' => 'oe','Ö' => 'Oe',
            'ü' => 'ue','Ü' => 'Ue','ß' => 'ss','é' => 'e',
            'è' => 'e','ê' => 'e','á' => 'a','à' => 'a',
            'â' => 'a','ó' => 'o','ò' => 'o','ô' => 'o',
            'ú' => 'u','ù' => 'u','û' => 'u','ç' => 'c'
        ];

        $filename = strtr($filename, $map);
        $filename = str_replace(' ', $replacement, $filename);
        $filename = preg_replace('/[\/\\\\\?\%\*\:\|\\"<>\x00]/u', $replacement, $filename);
        $filename = preg_replace('/[^A-Za-z0-9\-\_\.]/u', $replacement, $filename);
        $filename = preg_replace('/' . preg_quote($replacement, '/') . '+/', $replacement, $filename);
        $filename = trim($filename, $replacement);

        return $filename;
    }

    public static function sanitizeStatic(string $filename, string $replacement = '_'): string
    {
        return (new self())->sanitize($filename, $replacement);
    }
}