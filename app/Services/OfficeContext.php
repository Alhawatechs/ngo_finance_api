<?php

namespace App\Services;

use App\Models\Office;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and caches the current office and its database connection for the request.
 * Used by financial models to target the correct office DB.
 */
class OfficeContext
{
    protected static ?Office $currentOffice = null;

    protected static ?string $connectionName = null;

    /**
     * Set the current office for this request (called by middleware).
     */
    public static function setOffice(?Office $office): void
    {
        self::$currentOffice = $office;
        self::$connectionName = null;
    }

    /**
     * Get the current office (from header/user default).
     */
    public static function getOffice(): ?Office
    {
        return self::$currentOffice;
    }

    /**
     * Get the office ID if set.
     */
    public static function getOfficeId(): ?int
    {
        return self::$currentOffice?->id;
    }

    /**
     * Connection name to use for financial data in the current office.
     * Returns default (central) connection if no office or office has no DB provisioned.
     */
    public static function connection(): string
    {
        if (self::$connectionName !== null) {
            return self::$connectionName;
        }
        $office = self::$currentOffice;
        if (!$office || !$office->database_connection || !$office->database_name) {
            self::$connectionName = config('database.default');
            return self::$connectionName;
        }
        $name = $office->database_connection;
        if (!Config::get("database.connections.{$name}")) {
            self::registerOfficeConnection($office);
        }
        self::$connectionName = $name;
        return self::$connectionName;
    }

    /**
     * Register a dynamic database connection for the office.
     */
    public static function registerOfficeConnection(Office $office): void
    {
        if (!$office->database_name) {
            return;
        }
        $name = $office->database_connection ?: 'office_' . $office->id;
        $default = config('database.connections.mysql');
        Config::set('database.connections.' . $name, [
            'driver' => 'mysql',
            'host' => $default['host'],
            'port' => $default['port'],
            'database' => $office->database_name,
            'username' => $default['username'],
            'password' => $default['password'],
            'unix_socket' => $default['unix_socket'] ?? '',
            'charset' => $default['charset'],
            'collation' => $default['collation'],
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => $default['strict'],
            'engine' => $default['engine'] ?? null,
            'options' => $default['options'] ?? [],
        ]);
        // Only persist if this office is already saved and connection was auto-generated
        if ($office->exists && !$office->database_connection && $office->getRawOriginal('database_connection') === null) {
            $office->update(['database_connection' => $name]);
        }
    }

    /**
     * Run a callback using a specific office's connection.
     */
    public static function runWithOffice(Office $office, callable $callback): mixed
    {
        $previous = self::$currentOffice;
        self::setOffice($office);
        if (!Config::get('database.connections.' . ($office->database_connection ?: 'office_' . $office->id))) {
            self::registerOfficeConnection($office);
        }
        try {
            return $callback();
        } finally {
            self::$currentOffice = $previous;
            self::$connectionName = null;
        }
    }

    /**
     * Clear context (e.g. end of request).
     */
    public static function clear(): void
    {
        self::$currentOffice = null;
        self::$connectionName = null;
    }
}
