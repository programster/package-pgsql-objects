<?php

/*
 * A library to help with using UUIDs.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


use Ramsey\Uuid\Uuid;

class Utils
{
    /**
     * Generates a v7 UUID, which is time-ordered (sequential) for database index performance.
     * @return string - the generated UUID string.
     */
    public static function generateUuid() : string
    {
        return Uuid::uuid7()->toString();
    }
}