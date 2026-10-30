<?php

namespace statikbe\linkchecker\migrations;

use craft\db\Migration;

/**
 * 1.4.0: the queue jobs take their work from the tables instead of carrying a list. Adds the pending pages table, the
 * domains' nextCheckAt (and the refusals of hosts that block link checkers), the URLs' count of unknown results, drops the
 * unused index on the links' kind, and adds the rows of the domains and URLs that weren't checked yet.
 */
class m261101_000000_job_tables extends Migration
{
    public function safeUp(): bool
    {
        Install::createTables($this);
        Install::addMissingRows($this);
        return true;
    }

    public function safeDown(): bool
    {
        echo "m261101_000000_job_tables cannot be reverted.\n";
        return false;
    }
}
