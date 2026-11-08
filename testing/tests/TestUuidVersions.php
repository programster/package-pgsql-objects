<?php

/*
 * Test that a UUID table generates v7 UUIDs by default, v4 ones when its getUuidVersion() says so, and that its
 * normalizeId() accepts IDs of every version, so tables that already hold other versions still work.
 */

use Programster\PgsqlObjects\UuidVersion;
use Ramsey\Uuid\Uuid;

class TestUuidVersions
{
    public function run() : void
    {
        $defaultTable = UserTable::getInstance();

        // a table that overrides the version. The constructor is protected, so the anonymous class makes it public.
        $v4Table = new class() extends UserTable {
            public function __construct()
            {
                parent::__construct();
            }


            public function getUuidVersion() : UuidVersion
            {
                return UuidVersion::V4;
            }
        };

        $this->assertGeneratesVersion('The default', $defaultTable, 7);
        $this->assertGeneratesVersion('A table returning UuidVersion::V4', $v4Table, 4);

        $otherVersions = [
            'v1' => '9b2f1c4e-0b6a-11ef-9262-0242ac120002',
            'v4' => Uuid::uuid4()->toString(),
            'v7' => Uuid::uuid7()->toString(),
        ];

        foreach ($otherVersions as $version => $id)
        {
            if ($defaultTable->normalizeId($id) !== $id)
            {
                throw new \Exception("normalizeId() didn't accept a {$version} UUID on a table that generates v7.");
            }
        }
    }


    /**
     * Check that a table's generateId() makes UUIDs of the given version.
     *
     * @param string $context - which table it is, for the failure message.
     *
     * @param UserTable $table
     *
     * @param int $expectedVersion
     *
     * @return void
     */
    private function assertGeneratesVersion(string $context, UserTable $table, int $expectedVersion) : void
    {
        for ($i = 0; $i < 20; $i++)
        {
            $id = $table->generateId();
            $version = Uuid::fromString($id)->getFields()->getVersion();

            if ($version !== $expectedVersion || $id !== strtolower($id))
            {
                throw new \Exception("{$context} generated {$id}, which isn't a lower case v{$expectedVersion} UUID.");
            }
        }
    }
}
