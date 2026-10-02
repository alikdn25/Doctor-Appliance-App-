<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Version and approximate age of the IANA time zone database PHP uses.
 *
 * The database does not record its release date, only a version such as "2026b"
 * (year + release letter). The release date is estimated as two months per
 * letter into the year (IANA publishes a few releases a year), never later
 * than December 1. Good enough to spot a server that missed a year of updates.
 */
class TimezoneDatabase
{
    public function __construct(
        private readonly string $phpVersion,
        private readonly ?string $zoneinfoFile = null,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self((string) timezone_version_get(), config('fieldservice.tzdata.zoneinfo_file'));
    }

    /**
     * Version like "2026b", or null when it cannot be determined.
     */
    public function version(): ?string
    {
        // PHP's bundled or PECL timezonedb: "2025.2" means 2025b.
        if (preg_match('/^(\d{4})\.(\d+)$/', $this->phpVersion, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 26) {
            return $m[1].chr(ord('a') + (int) $m[2] - 1);
        }

        if (preg_match('/^\d{4}[a-z]$/', $this->phpVersion) === 1) {
            return $this->phpVersion;
        }

        // "0.system": PHP reads the operating system's tzdata.
        return $this->systemVersion();
    }

    public function estimatedReleaseDate(): ?CarbonImmutable
    {
        $version = $this->version();

        if ($version === null) {
            return null;
        }

        $year = (int) substr($version, 0, 4);
        $index = ord(substr($version, -1)) - ord('a');

        return CarbonImmutable::create($year, min(1 + $index * 2, 12), 1)->startOfDay();
    }

    /**
     * True when older than the configured age, null when the version is unknown.
     */
    public function isOutdated(?CarbonInterface $now = null): ?bool
    {
        $released = $this->estimatedReleaseDate();

        if ($released === null) {
            return null;
        }

        $now = CarbonImmutable::instance($now ?? now());

        return $released->addMonths((int) config('fieldservice.tzdata.max_age_months', 6))->lessThan($now);
    }

    private function systemVersion(): ?string
    {
        if ($this->zoneinfoFile === null || ! is_readable($this->zoneinfoFile)) {
            return null;
        }

        $handle = fopen($this->zoneinfoFile, 'r');

        if ($handle === false) {
            return null;
        }

        try {
            for ($i = 0; $i < 5 && ($line = fgets($handle)) !== false; $i++) {
                if (preg_match('/^#\s*version\s+(\d{4}[a-z])\b/', $line, $m) === 1) {
                    return $m[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
