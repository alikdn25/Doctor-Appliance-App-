<?php

namespace App\Support\Database;

/**
 * Migrations that drop tables or columns, or delete rows, run only with the owner's permission: such a migration
 * must be listed in database/approved-destructive-migrations.php. In production "migrate" refuses to start while a
 * pending migration is destructive and not listed (AppServiceProvider), and a test fails in CI.
 */
class MigrationGuard
{
    /** What counts as destructive inside up(). */
    private const PATTERNS = [
        'drop table' => '/Schema::drop(IfExists)?\s*\(|\bDROP\s+TABLE\b/i',
        'drop column' => '/->drop(Column|Columns|Morphs|NullableMorphs|SoftDeletes|SoftDeletesTz|Timestamps|TimestampsTz|RememberToken)\s*\(|\bDROP\s+COLUMN\b/i',
        'rename column' => '/->renameColumn\s*\(|\bRENAME\s+COLUMN\b/i',
        'rename table' => '/Schema::rename\s*\(|\bRENAME\s+TO\b/i',
        'delete rows' => '/\bTRUNCATE\b|\bDELETE\s+FROM\b|->truncate\s*\(|->delete\s*\(\s*\)|->forceDelete\s*\(/i',
    ];

    /**
     * Destructive operations found in the up() method of a migration file.
     *
     * @return list<string>
     */
    public static function destructiveOperations(string $file): array
    {
        $source = (string) file_get_contents($file);

        // Only up() matters: down() undoes the migration and is expected to drop what up() added.
        if (preg_match('/function\s+up\s*\(\)[^{]*\{(.*?)\n    \}\n/s', $source, $match)) {
            $source = $match[1];
        }

        // Comments may mention "drop" freely.
        $source = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $source);

        return array_keys(array_filter(self::PATTERNS, fn (string $pattern) => preg_match($pattern, $source) === 1));
    }

    /**
     * @return list<string> Migration names (file names without .php) the owner approved.
     */
    public static function approved(): array
    {
        $file = database_path('approved-destructive-migrations.php');

        return is_file($file) ? array_keys((array) require $file) : [];
    }

    /**
     * Destructive migrations among the given files that are not approved: name => operations.
     *
     * @param  iterable<string>  $files
     * @return array<string, list<string>>
     */
    public static function unapproved(iterable $files): array
    {
        $approved = self::approved();
        $found = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $operations = self::destructiveOperations($file);

            if ($operations !== [] && ! in_array($name, $approved, true)) {
                $found[$name] = $operations;
            }
        }

        return $found;
    }
}
