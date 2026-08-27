<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Human-readable file size, shared by every place this plugin displays a
 * backup's size. Previously each caller pre-divided raw bytes by 1_000_000
 * and formatted with a fixed "%.1f MB" — a real reported bug: any file under
 * ~50 KB (a small partial-preset backup) rounded to "0.0 MB", which is
 * exactly what an admin saw and flagged as broken. This picks whichever unit
 * keeps the number readable instead of always forcing MB.
 */
final class SizeFormatter
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function human(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $value = (float) $bytes;
        $unitIndex = 0;
        while ($value >= 1024.0 && $unitIndex < \count(self::UNITS) - 1) {
            $value /= 1024.0;
            $unitIndex++;
        }

        // Bytes are always a whole number; every larger unit gets one
        // decimal so small-but-not-tiny sizes (e.g. a few hundred KB) stay
        // precise enough to be useful.
        $decimals = $unitIndex === 0 ? 0 : 1;

        return \sprintf('%.' . $decimals . 'f %s', $value, self::UNITS[$unitIndex]);
    }
}
