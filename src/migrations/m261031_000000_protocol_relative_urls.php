<?php

namespace statikbe\linkchecker\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Queue;
use statikbe\linkchecker\jobs\RebuildJob;
use statikbe\linkchecker\services\Index;

/**
 * Protocol-relative links (//example.org/…) are checked over https since 1.3.0: their pages are read again, so they get the
 * new URL hash.
 */
class m261031_000000_protocol_relative_urls extends Migration
{
    public function safeUp(): bool
    {
        $found = $this->db->tableExists(Index::TABLE) && (new Query())
            ->from(Index::TABLE)
            ->where(['kind' => 'external'])
            ->andWhere(['like', 'url', '//%', false])
            ->exists();
        if ($found) {
            Queue::push(new RebuildJob());
        }
        return true;
    }

    public function safeDown(): bool
    {
        echo "m261031_000000_protocol_relative_urls cannot be reverted.\n";
        return false;
    }
}
