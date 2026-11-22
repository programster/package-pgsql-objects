<?php

/*
 * Test that loadAll(), loadWhereAnd(), loadWhereOr() and loadRange() can be sorted by a column, in either direction,
 * with the ID as a tiebreaker, and that a mistake in what they're asked to sort by is refused before any query runs.
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;
use Programster\PgsqlObjects\Exceptions\ExceptionUnknownColumn;
use Programster\PgsqlObjects\SortBy;
use Programster\PgsqlObjects\SortOrder;

class TestLoadOrdering
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("ticket")} RESTART IDENTITY");
        TicketTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = TicketTable::getInstance();

        // titles deliberately in a different order to the IDs, and with a duplicate to exercise the tiebreaker.
        foreach (['c', 'a', 'b', 'a'] as $title)
        {
            $table->insert(['title' => $title]);
        }

        $this->testSortBy();
        $this->testLoadAll();
        $this->testLoadWhere();
        $this->testLoadRange();
        $this->testUnknownColumnsAreRefused();
    }


    private function testSortBy() : void
    {
        $sortBy = new SortBy('title', SortOrder::DESC);

        if ($sortBy->getColumn() !== 'title' || $sortBy->getDirection() !== SortOrder::DESC)
        {
            throw new \Exception("SortBy didn't keep its column and direction.");
        }

        $createEmpty = function () : void {
            new SortBy('', SortOrder::ASC);
        };

        $this->assertThrows('SortBy with no column', ExceptionMissingRequiredData::class, $createEmpty);
    }


    private function testLoadAll() : void
    {
        $table = TicketTable::getInstance();
        $cache = CacheMode::IGNORE_CACHE;
        $ascending = new SortBy('title', SortOrder::ASC);
        $descending = new SortBy('title', SortOrder::DESC);
        $this->assertIds('loadAll() ascending', [2, 4, 3, 1], $table->loadAll($cache, $ascending));
        $this->assertIds('loadAll() descending', [1, 3, 4, 2], $table->loadAll($cache, $descending));
        $byId = new SortBy('id', SortOrder::DESC);
        $this->assertIds('loadAll() by ID, descending', [4, 3, 2, 1], $table->loadAll($cache, $byId));
    }


    private function testLoadWhere() : void
    {
        $table = TicketTable::getInstance();
        $cache = CacheMode::IGNORE_CACHE;
        $ascending = new SortBy('title', SortOrder::ASC);
        $descending = new SortBy('title', SortOrder::DESC);
        $matchingAnd = $table->loadWhereAnd(['title' => 'a'], $cache, $descending);
        $this->assertIds('loadWhereAnd() descending', [4, 2], $matchingAnd);
        $matchingOr = $table->loadWhereOr(['title' => ['b', 'c']], $cache, $descending);
        $this->assertIds('loadWhereOr() descending', [1, 3], $matchingOr);
        $matchingOr = $table->loadWhereOr(['title' => ['b', 'c']], $cache, $ascending);
        $this->assertIds('loadWhereOr() ascending', [3, 1], $matchingOr);
    }


    private function testLoadRange() : void
    {
        $table = TicketTable::getInstance();
        $cache = CacheMode::IGNORE_CACHE;
        $byTitle = $table->loadRange(1, 2, $cache, new SortBy('title', SortOrder::DESC));
        $this->assertIds('loadRange() by title, descending', [3, 4], $byTitle);
        $byId = $table->loadRange(0, 2, $cache, new SortBy('id', SortOrder::DESC));
        $this->assertIds('loadRange() by ID, descending', [4, 3], $byId);
        $this->assertIds('loadRange() by default', [1, 2], $table->loadRange(0, 2, $cache));
    }


    private function testUnknownColumnsAreRefused() : void
    {
        $table = TicketTable::getInstance();
        $cache = CacheMode::IGNORE_CACHE;
        $typo = new SortBy('titel', SortOrder::ASC);
        $injection = new SortBy('title"; DROP TABLE "ticket"; --', SortOrder::ASC);

        $loadAll = function () use ($table, $cache, $typo) : void {
            $table->loadAll($cache, $typo);
        };

        $loadWhereAnd = function () use ($table, $cache, $typo) : void {
            $table->loadWhereAnd(['title' => 'a'], $cache, $typo);
        };

        $loadWhereOr = function () use ($table, $cache, $injection) : void {
            $table->loadWhereOr(['title' => 'a'], $cache, $injection);
        };

        $this->assertThrows('loadAll() with a typo', ExceptionUnknownColumn::class, $loadAll);
        $this->assertThrows('loadWhereAnd() with a typo', ExceptionUnknownColumn::class, $loadWhereAnd);
        $this->assertThrows('loadWhereOr() with SQL', ExceptionUnknownColumn::class, $loadWhereOr);
    }


    /**
     * Check that the tickets are the ones with these IDs, in this order.
     * @param string $label - what's being checked.
     * @param array<int> $expectedIds - the IDs expected.
     * @param array<TicketRecord> $tickets - the tickets loaded.
     * @return void
     */
    private function assertIds(string $label, array $expectedIds, array $tickets) : void
    {
        $getId = function (TicketRecord $ticket) : int {
            return RecordIds::getInt($ticket);
        };

        $ids = array_map($getId, $tickets);

        if ($ids !== $expectedIds)
        {
            $expected = implode(", ", $expectedIds);
            throw new \Exception("{$label} should give IDs {$expected}, but gave: " . implode(", ", $ids));
        }
    }


    /**
     * Check that some code throws an exception of the given class.
     * @param string $label - what's being checked.
     * @param class-string<\Throwable> $exceptionClass - the exception expected.
     * @param \Closure $code - the code.
     * @return void
     */
    private function assertThrows(string $label, string $exceptionClass, \Closure $code) : void
    {
        $isThrown = false;

        try
        {
            $code();
        }
        catch (\Throwable $exception)
        {
            $isThrown = ($exception instanceof $exceptionClass);
        }

        if ($isThrown === false)
        {
            throw new \Exception("{$label} should have thrown {$exceptionClass}.");
        }
    }
}
