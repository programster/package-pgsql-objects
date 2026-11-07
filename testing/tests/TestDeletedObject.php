<?php

/*
 * Test that once an object has been deleted, it can't be saved, updated or batch saved (which would re-insert its row),
 * but its data can still be read, deleting it again does nothing, and a clone of it can be saved as a new row.
 */

use Programster\PgsqlObjects\Exceptions\ExceptionObjectDeleted;

class TestDeletedObject
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        UserTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = UserTable::getInstance();
        $user = $table->insert(['name' => 'deleted', 'email' => 'deleted@gmail.com']);
        $user->delete();
        $user->delete(); // deleting again does nothing, rather than throwing.

        $calls = [
            'save'         => function () use ($user) : void { $user->save(); },
            'update'       => function () use ($user) : void { $user->update(['name' => 'renamed']); },
            'getSaveQuery' => function () use ($user) : void { $user->getSaveQuery(); },
            'markSaved'    => function () use ($user) : void { $user->markSaved(); },
            'batchSave'    => function () use ($table, $user) : void { $table->batchSave([$user]); },
        ];

        foreach ($calls as $methodName => $call)
        {
            $threw = false;

            try
            {
                $call();
            }
            catch (ExceptionObjectDeleted)
            {
                $threw = true;
            }

            if ($threw === false)
            {
                throw new \Exception("{$methodName}() did not throw an ExceptionObjectDeleted for a deleted object.");
            }
        }

        if (count($table->loadAll()) !== 0)
        {
            throw new \Exception("A deleted object's row was re-inserted.");
        }

        if ($user->getName() !== 'deleted')
        {
            throw new \Exception("A deleted object's data could no longer be read.");
        }

        $clone = clone $user;
        $clone->save();
        $loaded = $table->load($clone->getId(), false);

        if ($loaded->getName() !== 'deleted' || $clone->getId() === $user->getId())
        {
            throw new \Exception("The clone of a deleted object was not saved as a new row.");
        }
    }
}
