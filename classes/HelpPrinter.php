<?php

namespace Denkmalatlas;

class HelpPrinter
{
    public static function printHelp(SettingsManager $settings): void
    {
        if (!($settings->helpRequested ?? false)) {
            return;
        }
        $readmePath = __DIR__ . '/../readme.md';
            if (file_exists($readmePath)) {
                $content = file_get_contents($readmePath);
                // Extract 'Overview' section (## Overview) and 'Examples' section (# Examples or ## Examples)
                $overview = '';
                $examples = '';
                // find Overview
                if (preg_match('/^#{1,2}\s*Overview\b/im', $content, $m1, PREG_OFFSET_CAPTURE)) {
                    $start = $m1[0][1] + strlen($m1[0][0]);
                    // find next H2 or H1 after overview
                    if (preg_match('/(^#{1,2}\s+.*$)/im', $content, $mnext, PREG_OFFSET_CAPTURE, $start)) {
                        $end = $mnext[0][1];
                    } else {
                        $end = strlen($content);
                    }
                    $overview = trim(substr($content, $start, $end - $start));
                }
                // find Examples (either '# Examples' or '## Examples')
                if (preg_match('/^#{1,2}\s*Examples\b/im', $content, $m2, PREG_OFFSET_CAPTURE)) {
                    $start2 = $m2[0][1] + strlen($m2[0][0]);
                    if (preg_match('/(^#{1,2}\s+.*$)/im', $content, $mnext2, PREG_OFFSET_CAPTURE, $start2)) {
                        $end2 = $mnext2[0][1];
                    } else {
                        $end2 = strlen($content);
                    }
                    $examples = trim(substr($content, $start2, $end2 - $start2));
                }
                // Clean up markdown fences and trailing backslashes
                $clean = function ($text) {
                    $text = preg_replace('/^```.*$/m', '', $text);
                    $text = preg_replace('/\\\s*$/m', '', $text);
                    return trim($text);
                };
                $overview = $clean($overview);
                $examples = $clean($examples);
                if ($overview === '' && $examples === '') {
                    echo "Help not available: 'Overview' and 'Examples' sections not found in readme.md. Exiting." . PHP_EOL;
                    exit(0);
                }
                if ($overview !== '') {
                    echo $overview . PHP_EOL . PHP_EOL;
                }
                if ($examples !== '') {
                    echo $examples . PHP_EOL;
                }
                exit(0);
            }
            echo "Help not available: 'Parameter' section missing in readme.md. Exiting." . PHP_EOL;
            exit(0);
    }
}
