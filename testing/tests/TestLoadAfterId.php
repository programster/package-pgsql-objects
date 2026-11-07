<?php

/*
 * Test that loadAfterId() pages through every row exactly once, in ID order, even when rows are inserted and deleted
 * between pages.
 */

class TestLoadAfterId
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

        for ($i = 1; $i <= 7; $i++)
        {
            $table->insert(['title' => "ticket {$i}"]);
        }

        $loadedIds = [];
        $afterId = null;
        $pageNumber = 0;

        do
        {
            $page = $table->loadAfterId($afterId, 3);
            $pageNumber++;

            foreach ($page as $ticket)
            {
                $loadedIds[] = $ticket->getId();
                $afterId = $ticket->getId();
            }

            if ($pageNumber === 1)
            {
                // deleting an already-loaded row would shift every later page with OFFSET, but not with loadAfterId().
                $table->deleteById(1);
                $table->insert(['title' => 'added while paging']); // gets ID 8, so should appear on the last page.
            }
        }
        while (count($page) > 0);

        if ($loadedIds !== [1, 2, 3, 4, 5, 6, 7, 8])
        {
            $loadedIdsList = implode(", ", $loadedIds);

            throw new \Exception(
                "loadAfterId() did not load every row exactly once in ID order, got: {$loadedIdsList}"
            );
        }
    }
}
