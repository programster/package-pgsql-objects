<?php

/*
 * Test that createFromDatabaseRow() refuses a row with no ID, rather than generating one, as it would then be an
 * object for a row that doesn't exist, which it would try to update when saved.
 */

use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;

class TestCreateFromRowWithoutId
{
    public function __construct()
    {
    }


    public function run() : void
    {
        // users' IDs can be generated in code, so an ID would previously have been made up for these rows.
        $rows = [
            'a row without the ID column' => ['name' => 'no id', 'email' => 'no-id@gmail.com'],
            'a row with a null ID' => ['id' => null, 'name' => 'null id', 'email' => 'null-id@gmail.com'],
        ];

        foreach ($rows as $description => $row)
        {
            $threw = false;

            try
            {
                UserRecord::createFromDatabaseRow($row);
            }
            catch (ExceptionMissingRequiredData)
            {
                $threw = true;
            }

            if ($threw === false)
            {
                throw new \Exception("createFromDatabaseRow() did not reject {$description}.");
            }
        }
    }
}
