<?php

namespace statikbe\linkchecker\migrations;

use craft\db\Migration;
use craft\db\Table;
use statikbe\linkchecker\services\Hosts;
use statikbe\linkchecker\services\Ignores;
use statikbe\linkchecker\services\Index;
use statikbe\linkchecker\services\Urls;

/**
 * The links table (one row per link per page and site) and the tables around it. Whether a link is broken isn't stored:
 * it's worked out when the report is read, from the target's current state (Report).
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        self::createTables($this);
        return true;
    }

    /**
     * Creates the tables and columns that are missing, so it also upgrades an older install (m261030_000000_missing_tables).
     * Returns whether the links table needs to be read again (it was created, or got the urlHash column).
     */
    public static function createTables(Migration $migration): bool
    {
        $db = $migration->db;
        $rebuild = false;
        if (!$db->tableExists(Index::TABLE)) {
            $migration->createTable(Index::TABLE, [
                'id' => $migration->primaryKey(),
                'sourceId' => $migration->integer()->notNull(),
                'siteId' => $migration->integer()->notNull(),
                'elementId' => $migration->integer()->notNull(),
                'fieldId' => $migration->integer(),
                'location' => $migration->string(1000)->notNull()->defaultValue(''),
                'kind' => $migration->string(20)->notNull(),
                'targetId' => $migration->integer(),
                'targetSiteId' => $migration->integer(),
                'uri' => $migration->string(500),
                'url' => $migration->text()->notNull(),
                'suffix' => $migration->string(255),
                'label' => $migration->string(255),
                'issue' => $migration->string(50),
                'fallbackUri' => $migration->string(500),
                'fallbackSiteId' => $migration->integer(),
                'host' => $migration->string(255),
                'urlHash' => $migration->char(40),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Index::TABLE, ['sourceId', 'siteId']);
            $migration->createIndex(null, Index::TABLE, ['targetId']);
            $migration->createIndex(null, Index::TABLE, ['kind']);
            $migration->createIndex(null, Index::TABLE, ['host']);
            $migration->createIndex(null, Index::TABLE, ['urlHash']);
            $migration->addForeignKey(null, Index::TABLE, ['sourceId'], Table::ELEMENTS, ['id'], 'CASCADE');
            $migration->addForeignKey(null, Index::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE');
            $rebuild = true;
        } elseif (!$db->columnExists(Index::TABLE, 'urlHash')) {
            // Added in 1.2.0
            $migration->addColumn(Index::TABLE, 'urlHash', $migration->char(40)->after('host'));
            $migration->createIndex(null, Index::TABLE, ['urlHash']);
            $rebuild = true;
        }

        // The anchors each page defines (ids in its content, Entry Anchors fields); '' marks a page that was read
        if (!$db->tableExists(Index::ANCHORS_TABLE)) {
            $migration->createTable(Index::ANCHORS_TABLE, [
                'id' => $migration->primaryKey(),
                'pageId' => $migration->integer()->notNull(),
                'siteId' => $migration->integer()->notNull(),
                'anchor' => $migration->string(255)->notNull(),
            ]);
            $migration->createIndex(null, Index::ANCHORS_TABLE, ['pageId', 'siteId', 'anchor']);
            $migration->addForeignKey(null, Index::ANCHORS_TABLE, ['pageId'], Table::ELEMENTS, ['id'], 'CASCADE');
            $migration->addForeignKey(null, Index::ANCHORS_TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE');
            $rebuild = true;
        }

        // The domains of external links and whether they exist (Hosts)
        if (!$db->tableExists(Hosts::TABLE)) {
            $migration->createTable(Hosts::TABLE, [
                'id' => $migration->primaryKey(),
                'host' => $migration->string(255)->notNull(),
                'resolves' => $migration->boolean()->notNull(),
                'failures' => $migration->smallInteger()->notNull()->defaultValue(0),
                'checkedAt' => $migration->dateTime()->notNull(),
            ]);
            $migration->createIndex(null, Hosts::TABLE, ['host'], true);
        } elseif (!$db->columnExists(Hosts::TABLE, 'failures')) {
            // Added in 1.2.1
            $migration->addColumn(Hosts::TABLE, 'failures', $migration->smallInteger()->notNull()->defaultValue(0)->after('resolves'));
        }

        // External URLs and the result of their last check (Urls)
        if (!$db->tableExists(Urls::TABLE)) {
            $migration->createTable(Urls::TABLE, [
                'id' => $migration->primaryKey(),
                'urlHash' => $migration->char(40)->notNull(),
                'url' => $migration->text()->notNull(),
                'host' => $migration->string(255)->notNull(),
                'status' => $migration->string(20)->notNull(),
                'httpCode' => $migration->smallInteger(),
                'error' => $migration->string(255),
                'finalUrl' => $migration->text(),
                'failures' => $migration->smallInteger()->notNull()->defaultValue(0),
                'checkedAt' => $migration->dateTime()->notNull(),
                'nextCheckAt' => $migration->dateTime()->notNull(),
            ]);
            $migration->createIndex(null, Urls::TABLE, ['urlHash'], true);
            $migration->createIndex(null, Urls::TABLE, ['nextCheckAt']);
        }

        // Links an editor chose to ignore (Ignores)
        if (!$db->tableExists(Ignores::TABLE)) {
            $migration->createTable(Ignores::TABLE, [
                'id' => $migration->primaryKey(),
                'url' => $migration->text()->notNull(),
                'urlHash' => $migration->char(40)->notNull(),
                'userId' => $migration->integer(),
                'dateCreated' => $migration->dateTime()->notNull(),
            ]);
            $migration->createIndex(null, Ignores::TABLE, ['urlHash'], true);
            $migration->addForeignKey(null, Ignores::TABLE, ['userId'], Table::USERS, ['id'], 'SET NULL');
        }
        return $rebuild;
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
