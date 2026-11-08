<?php

/*
 * Test a table whose identifier is a natural key, a subscriber's email address: it must always be given, its table's
 * normalizeId() checks and lower cases it, and it works with every way of loading, saving and deleting, including
 * changing it, which moves the rows that reference it too (ON UPDATE CASCADE).
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionInvalidId;
use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;
use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;
use Programster\PgsqlObjects\Transaction;

class TestNaturalKey
{
    public function __construct()
    {
        SubscriberTable::getInstance()->enableCache(); // this test checks the cache follows a changed key.
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("subscription")}, {$db->escapeIdentifier("subscriber")}");
        SubscriberTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $this->testCreateAndLoad();
        $this->testKeyMustBeGiven();
        $this->testChangingTheKey();
        $this->testBatchSaveAndTransaction();
    }


    /**
     * A subscriber is created with their email as the ID, which is lower cased, and found by it in any case.
     */
    private function testCreateAndLoad() : void
    {
        $table = SubscriberTable::getInstance();
        $ada = SubscriberRecord::createNewFromArray(['email' => 'Ada@Example.com', 'name' => 'Ada']);
        $ada->save();

        if ($ada->getEmail() !== 'ada@example.com' || !$ada->isPersisted())
        {
            throw new \Exception("A subscriber wasn't saved with their lower cased email as the ID.");
        }

        if ($table->load('ADA@example.com') !== $ada)
        {
            throw new \Exception("Loading a subscriber by their email in another case didn't find them.");
        }

        $ada->update(['name' => 'Ada Lovelace']);

        if ($table->load('ada@example.com', CacheMode::IGNORE_CACHE)->getName() !== 'Ada Lovelace')
        {
            throw new \Exception("Updating a subscriber didn't save their new name.");
        }

        $inserted = $table->insert(['email' => 'grace@example.com', 'name' => 'Grace']);

        if ($inserted->getEmail() !== 'grace@example.com')
        {
            throw new \Exception("insert() didn't use the email it was given as the ID.");
        }
    }


    /**
     * An email must be given, as nothing can make one up, and it must be an email address.
     */
    private function testKeyMustBeGiven() : void
    {
        $table = SubscriberTable::getInstance();

        $calls = [
            'createNewFromArray() without an email' => [
                ExceptionMissingRequiredData::class,
                function () : void { SubscriberRecord::createNewFromArray(['name' => 'Nobody']); },
            ],
            'insert() without an email'             => [
                ExceptionMissingRequiredData::class,
                function () use ($table) : void { $table->insert(['name' => 'Nobody']); },
            ],
            'createNewFromArray() with a non-email' => [
                ExceptionInvalidId::class,
                function () : void { SubscriberRecord::createNewFromArray(['email' => 'nope', 'name' => 'x']); },
            ],
            'load() with a non-email'               => [
                ExceptionInvalidId::class,
                function () use ($table) : void { $table->load('nope'); },
            ],
            'load() with no such email'             => [
                ExceptionNoSuchIdException::class,
                function () use ($table) : void { $table->load('nobody@example.com'); },
            ],
        ];

        foreach ($calls as $description => [$exceptionClass, $call])
        {
            $threw = false;

            try
            {
                $call();
            }
            catch (\Throwable $exception)
            {
                if (!($exception instanceof $exceptionClass))
                {
                    throw $exception;
                }

                $threw = true;
            }

            if ($threw === false)
            {
                throw new \Exception("{$description} didn't throw {$exceptionClass}.");
            }
        }
    }


    /**
     * Changing a subscriber's email, through update(), moves their row, the rows that reference it, and the cache.
     */
    private function testChangingTheKey() : void
    {
        $table = SubscriberTable::getInstance();
        $ada = $table->load('ada@example.com');
        $subscriptionTable = SubscriptionTable::getInstance();
        $subscription = $subscriptionTable->insert(['subscriber_email' => 'ada@example.com', 'list' => 'news']);
        $ada->update(['email' => 'Ada@New.Example']);

        if ($ada->getEmail() !== 'ada@new.example' || $table->load('ada@new.example') !== $ada)
        {
            throw new \Exception("Changing a subscriber's email didn't move them to their new, lower cased email.");
        }

        $movedSubscription = SubscriptionTable::getInstance()->load($subscription->getId(), CacheMode::IGNORE_CACHE);

        if ($movedSubscription->getSubscriberEmail() !== 'ada@new.example')
        {
            throw new \Exception("Changing a subscriber's email didn't move the rows that reference it.");
        }

        $threw = false;

        try
        {
            $table->load('ada@example.com', CacheMode::IGNORE_CACHE);
        }
        catch (ExceptionNoSuchIdException)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("A subscriber could still be loaded by their old email.");
        }
    }


    /**
     * New and changed subscribers can be saved in a batch, and with a transaction, which can also save a subscription
     * before its new subscriber, as the foreign key is deferrable, and delete them.
     */
    private function testBatchSaveAndTransaction() : void
    {
        $table = SubscriberTable::getInstance();
        $grace = $table->load('grace@example.com');
        $grace->setName('Grace Hopper');
        $alan = SubscriberRecord::createNewFromArray(['email' => 'alan@example.com', 'name' => 'Alan']);
        $table->batchSave([$grace, $alan]);

        if ($table->load('alan@example.com', CacheMode::IGNORE_CACHE)->getName() !== 'Alan')
        {
            throw new \Exception("batchSave() didn't insert a new subscriber.");
        }

        if ($table->load('grace@example.com', CacheMode::IGNORE_CACHE)->getName() !== 'Grace Hopper')
        {
            throw new \Exception("batchSave() didn't update an existing subscriber.");
        }

        $edsger = SubscriberRecord::createNewFromArray(['email' => 'edsger@example.com', 'name' => 'Edsger']);
        $data = ['subscriber_email' => $edsger->getEmail(), 'list' => 'news'];
        $subscription = SubscriptionRecord::createNewFromArray($data);
        $saves = new Transaction();
        $saves->save($subscription);
        $saves->save($edsger);
        $saves->commit();

        $deletes = new Transaction();
        $deletes->delete($edsger);
        $deletes->delete($subscription);
        $deletes->commit();

        if (count($table->loadIds(['edsger@example.com'], CacheMode::IGNORE_CACHE)) !== 0 || !$edsger->isDeleted())
        {
            throw new \Exception("A transaction didn't delete a subscriber.");
        }
    }
}
