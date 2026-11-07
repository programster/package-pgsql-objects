<?php

/*
 * Test that loadRange() pages through rows in a stable order, by the ID column by default or by a chosen column,
 * without skipping or repeating rows when rows are updated between pages.
 */

class TestLoadRange
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
        foreach (['f', 'e', 'd', 'c', 'b', 'b'] as $title)
        {
            $table->insert(['title' => $title]);
        }

        $this->testDefaultOrderSurvivesUpdates();
        $this->testOrderByColumn();
    }


    private function testDefaultOrderSurvivesUpdates() : void
    {
        $table = TicketTable::getInstance();
        $firstPage = $table->loadRange(0, 3);

        // updating a row moves it physically in PostgreSQL, which used to make later pages skip/repeat rows.
        $firstPage[0]->update(['title' => 'updated']);

        $secondPage = $table->loadRange(3, 3);
        $getId = function (TicketRecord $ticket) : int { return $ticket->getId(); };
        $ids = array_map($getId, array_merge($firstPage, $secondPage));

        if ($ids !== [1, 2, 3, 4, 5, 6])
        {
            throw new \Exception("loadRange() did not page through the rows in ID order, got: " . implode(", ", $ids));
        }
    }


    private function testOrderByColumn() : void
    {
        $table = TicketTable::getInstance();
        $firstPage = $table->loadRange(0, 3, 'title');
        $secondPage = $table->loadRange(3, 3, 'title');
        $tickets = array_merge($firstPage, $secondPage);

        $getTitle = function (TicketRecord $ticket) : ?string { return $ticket->getTitle(); };
        $getId = function (TicketRecord $ticket) : int { return $ticket->getId(); };
        $describe = function (TicketRecord $ticket) : string { return "{$ticket->getId()}:{$ticket->getTitle()}"; };
        $titles = array_map($getTitle, $tickets);
        $ids = array_map($getId, $tickets);

        // ticket 1 was renamed to "updated", and the two "b" tickets are ordered by their IDs as a tiebreaker.
        if ($titles !== ['b', 'b', 'c', 'd', 'e', 'updated'] || $ids !== [5, 6, 4, 3, 2, 1])
        {
            $actual = implode(", ", array_map($describe, $tickets));
            throw new \Exception("loadRange() ordered by title did not return the expected rows, got: {$actual}");
        }
    }
}
