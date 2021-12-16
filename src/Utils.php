<?php

/*
 * A library to help with using UUIDs.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


class Utils
{
    /**
     * Generates a v4 UUID that is in sequential form for database performance.
     * @return string - the generated UUID string.
     */
    public static function generateUuid() : string
    {
        static $factory = null;

        if ($factory == null)
        {
            $factory = new \Ramsey\Uuid\UuidFactory();

            $generator = new \Ramsey\Uuid\Generator\CombGenerator(
                $factory->getRandomGenerator(),
                $factory->getNumberConverter()
            );

            $codec = new \Ramsey\Uuid\Codec\TimestampFirstCombCodec($factory->getUuidBuilder());

            $factory->setRandomGenerator($generator);
            $factory->setCodec($codec);
        }

        \Ramsey\Uuid\Uuid::setFactory($factory);
        $uuidString = \Ramsey\Uuid\Uuid::uuid4()->toString();
        return $uuidString;
    }
}