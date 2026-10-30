<?php

namespace statikbe\linkchecker\migrations;

use craft\db\Migration;
use craft\db\Table;
use statikbe\linkchecker\services\Hosts;
use statikbe\linkchecker\services\Ignores;
use statikbe\linkchecker\services\Index;
use statikbe\linkchecker\services\Urls;

/**
 * The links table: one row per link per page and site. Whether a link is broken isn't stored: it's worked out when the
 * report is read, from the target's current state (Report).
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Index::TABLE)) {
            return true;
        }
        $this->createTable(Index::TABLE, [
            'id' => $this->primaryKey(),
            'sourceId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'fieldId' => $this->integer(),
            'location' => $this->string(1000)->notNull()->defaultValue(''),
            'kind' => $this->string(20)->notNull(),
            'targetId' => $this->integer(),
            'targetSiteId' => $this->integer(),
            'uri' => $this->string(500),
            'url' => $this->text()->notNull(),
            'suffix' => $this->string(255),
            'label' => $this->string(255),
            'issue' => $this->string(50),
            'fallbackUri' => $this->string(500),
            'fallbackSiteId' => $this->integer(),
            'host' => $this->string(255),
            'urlHash' => $this->char(40),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, Index::TABLE, ['sourceId', 'siteId']);
        $this->createIndex(null, Index::TABLE, ['targetId']);
        $this->createIndex(null, Index::TABLE, ['kind']);
        $this->createIndex(null, Index::TABLE, ['host']);
        $this->createIndex(null, Index::TABLE, ['urlHash']);
        $this->addForeignKey(null, Index::TABLE, ['sourceId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Index::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE');

        // The anchors each page defines (ids in its content, Entry Anchors fields); '' marks a page that was read
        $this->createTable(Index::ANCHORS_TABLE, [
            'id' => $this->primaryKey(),
            'pageId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'anchor' => $this->string(255)->notNull(),
        ]);
        $this->createIndex(null, Index::ANCHORS_TABLE, ['pageId', 'siteId', 'anchor']);
        $this->addForeignKey(null, Index::ANCHORS_TABLE, ['pageId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Index::ANCHORS_TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE');

        self::createHostsTable($this);

        // External URLs and the result of their last check (Urls)
        $this->createTable(Urls::TABLE, [
            'id' => $this->primaryKey(),
            'urlHash' => $this->char(40)->notNull(),
            'url' => $this->text()->notNull(),
            'host' => $this->string(255)->notNull(),
            'status' => $this->string(20)->notNull(),
            'httpCode' => $this->smallInteger(),
            'error' => $this->string(255),
            'finalUrl' => $this->text(),
            'failures' => $this->smallInteger()->notNull()->defaultValue(0),
            'checkedAt' => $this->dateTime()->notNull(),
            'nextCheckAt' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex(null, Urls::TABLE, ['urlHash'], true);
        $this->createIndex(null, Urls::TABLE, ['nextCheckAt']);

        // Links an editor chose to ignore (Ignores)
        $this->createTable(Ignores::TABLE, [
            'id' => $this->primaryKey(),
            'url' => $this->text()->notNull(),
            'urlHash' => $this->char(40)->notNull(),
            'userId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex(null, Ignores::TABLE, ['urlHash'], true);
        $this->addForeignKey(null, Ignores::TABLE, ['userId'], Table::USERS, ['id'], 'SET NULL');
        return true;
    }

    /**
     * The domains of external links and whether they exist (Hosts).
     */
    public static function createHostsTable(Migration $migration): void
    {
        if ($migration->db->tableExists(Hosts::TABLE)) {
            return;
        }
        $migration->createTable(Hosts::TABLE, [
            'id' => $migration->primaryKey(),
            'host' => $migration->string(255)->notNull(),
            'resolves' => $migration->boolean()->notNull(),
            'checkedAt' => $migration->dateTime()->notNull(),
        ]);
        $migration->createIndex(null, Hosts::TABLE, ['host'], true);
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Ignores::TABLE);
        $this->dropTableIfExists(Urls::TABLE);
        $this->dropTableIfExists(Hosts::TABLE);
        $this->dropTableIfExists(Index::ANCHORS_TABLE);
        $this->dropTableIfExists(Index::TABLE);
        return true;
    }
}
