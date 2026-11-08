<?php

/*
 * Provides the tests with a single shared connection to the test database.
 */

use Programster\PgsqlLib\PgSqlConnection;

class ConnectionHandler
{
    private static ?PgSqlConnection $s_db = null;


    /**
     * Get the connection to the database and create it if it doesn't already exist.
     *
     * Each connection detail comes from an environment variable of the same name with a TEST_ prefix (e.g.
     * TEST_DB_PORT), if it's set, otherwise from the constant in settings.php. This lets run-all-versions.sh point the
     * tests at each of its PostgreSQL containers in turn.
     *
     * @return PgSqlConnection
     */
    public static function getDb() : PgSqlConnection
    {
        if (self::$s_db === null)
        {
            self::$s_db = self::createNewConnection();
        }

        return self::$s_db;
    }


    /**
     * Create a new connection to the test database, separate from the shared one getDb() returns, e.g. to test what
     * happens with tables that use different connections.
     *
     * @return PgSqlConnection
     */
    public static function createNewConnection() : PgSqlConnection
    {
        $connection = PgSqlConnection::create(
            self::getSetting('DB_HOST'),
            self::getSetting('DB_NAME'),
            self::getSetting('DB_USER'),
            self::getSetting('DB_PASSWORD'),
            true,
            (int)self::getSetting('DB_PORT', '5432')
        );

        return $connection;
    }


    /**
     * Get the major version of the PostgreSQL server the tests are running against, e.g. 18, so tests can check what
     * should happen on older servers.
     *
     * @return int
     */
    public static function getServerMajorVersion() : int
    {
        // the server's version, e.g. "18.6 (Debian 18.6-1)". Not the client library's, which may differ.
        $version = pg_version(self::getDb()->getResource());
        return (int)explode('.', $version['server'])[0];
    }


    /**
     * Get a connection detail, from its TEST_ environment variable if that's set, or its settings.php constant.
     *
     * @param string $name - the name of the setting's constant, e.g. "DB_PORT".
     *
     * @param string|null $default - the value to use if it's set in neither place, or null if it must be set.
     *
     * @return string
     *
     * @throws \RuntimeException - if the setting has no value and no default.
     */
    private static function getSetting(string $name, ?string $default = null) : string
    {
        $environmentValue = getenv("TEST_{$name}");

        if ($environmentValue !== false)
        {
            $value = $environmentValue;
        }
        elseif (defined($name))
        {
            $value = (string)constant($name);
        }
        elseif ($default !== null)
        {
            $value = $default;
        }
        else
        {
            throw new \RuntimeException(
                "The {$name} test setting isn't set. Define it in testing/settings.php or set TEST_{$name}."
            );
        }

        return $value;
    }
}
