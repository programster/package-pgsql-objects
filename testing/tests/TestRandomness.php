<?php

/*
 * Test that we can generate a large number of unique UUIDs without clashes.
 * This is slow, so it only runs when RUN_SLOW_TEST is set to true.
 */

class TestRandomness
{
    private const RUN_SLOW_TEST = false;


    public function __construct()
    {
    }


    public function run() : void
    {
        if (self::RUN_SLOW_TEST)
        {
            $this->checkForClashes();
        }
    }


    private function checkForClashes() : void
    {
        $uuids = [];
        $million = 1000000;
        $table = UserTable::getInstance();

        for ($i = 0; $i < $million; $i++)
        {
            $uuid = $table->generateId();

            if (isset($uuids[$uuid]))
            {
                throw new \Exception("Clash found. Did not generate unique UUID");
            }
            else
            {
                $uuids[$uuid] = 1;
            }
        }
    }
}
