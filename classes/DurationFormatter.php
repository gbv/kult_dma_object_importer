<?php
namespace Denkmalatlas;
class DurationFormatter
{
    /**
     * Konvertiert Sekunden in ein lesbares Format (z.B. "1h 23m 45s")
     */
    public static function format(float $seconds): string
    {
        $seconds = (int)$seconds;

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }

        return implode(' ', $parts);
    }
}
?>