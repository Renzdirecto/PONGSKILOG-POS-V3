<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Driver-aware SQL fragments for reporting in Asia/Manila time.
 *
 * Timestamps are stored as UTC wall-clock values. Asia/Manila has been a fixed UTC+08:00 with no daylight saving time
 * since 1978, so a constant eight-hour shift yields the exact Manila clock hour on PostgreSQL, SQLite and MySQL alike and
 * never depends on the database session or browser time zone.
 */
final class ManilaSql
{
    public const UTC_OFFSET_HOURS = 8;

    /**
     * The Manila clock hour (0–23) of a UTC timestamp column.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function hour(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%H', {$column}, '+8 hours') AS INTEGER)",
            'mysql', 'mariadb' => "HOUR(DATE_ADD({$column}, INTERVAL 8 HOUR))",
            default => "CAST(EXTRACT(HOUR FROM ({$column} + INTERVAL '8 hours')) AS INTEGER)",
        };
    }

    /**
     * Elapsed seconds from one timestamp column to another.
     *
     * @param  literal-string  $start
     * @param  literal-string  $end
     * @return literal-string
     */
    public static function secondsBetween(string $start, string $end): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "((julianday({$end}) - julianday({$start})) * 86400.0)",
            'mysql', 'mariadb' => "TIMESTAMPDIFF(SECOND, {$start}, {$end})",
            default => "EXTRACT(EPOCH FROM ({$end} - {$start}))",
        };
    }
}
