<?php

namespace statikbe\linkchecker\migrations;

use craft\db\Migration;
use craft\helpers\Queue;
use statikbe\linkchecker\jobs\RebuildJob;

/**
 * Creates the tables and columns that 1.1.0, 1.2.0 and 1.2.1 added (domains, external URLs, ignored links, the links'
 * urlHash, the domains' failures), for sites that installed an earlier version. The links are read again when the links
 * table changed, so external links get their hash.
 */
class m261030_000000_missing_tables extends Migration
{
    public function safeUp(): bool
    {
        if (Install::createTables($this)) {
            Queue::push(new RebuildJob());
        }
        return true;
    }

    public function safeDown(): bool
    {
        echo "m261030_000000_missing_tables cannot be reverted.\n";
        return false;
    }
}
