<?php

/*
 * Reads the version of a UUID, for the tests that check which version was generated.
 */

use Programster\PgsqlLib\PgsqlLib;

final class UuidVersions
{
    /**
     * Get the version of a UUID, from the 13th hex digit (the first of its third group).
     * @throws \Exception - if it isn't a UUID, or it isn't an RFC 9562 one, the only kind that has a version.
     */
    public static function get(string $uuid) : int
    {
        // the variant is in the top bits of the 17th hex digit: 8, 9, a or b for an RFC 9562 UUID.
        if (!PgsqlLib::isValidUuid($uuid) || !in_array(strtolower($uuid[19]), ['8', '9', 'a', 'b'], true))
        {
            throw new \Exception(var_export($uuid, true) . " isn't an RFC 9562 UUID, so it has no version.");
        }

        return (int)hexdec($uuid[14]);
    }
}
