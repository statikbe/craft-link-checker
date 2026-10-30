<?php

namespace statikbe\linkchecker\migrations;

use craft\db\Migration;
use craft\db\Query;
use statikbe\linkchecker\LinkChecker;
use statikbe\linkchecker\services\Index;

/**
 * Protocol-relative links (//example.org/…) are checked over https since 1.3.0: the pages with such links are read again,
 * so they get the new URL hash. Only those pages (1.3.0 read every page again).
 */
class m261031_000000_protocol_relative_urls extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Index::TABLE)) {
            return true;
        }
        // The pending table is new in 1.4.0
        Install::createTables($this);
        $ids = (new Query())
            ->select(['sourceId'])
            ->distinct()
            ->from(Index::TABLE)
            ->where(['kind' => 'external'])
            ->andWhere(['like', 'url', '//%', false])
            ->column($this->db);
        if ($ids) {
            LinkChecker::getInstance()->getIndex()->queuePages(array_map('intval', $ids));
        }
        return true;
    }

    public function safeDown(): bool
    {
        echo "m261031_000000_protocol_relative_urls cannot be reverted.\n";
        return false;
    }
}
