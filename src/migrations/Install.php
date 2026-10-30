<?php

namespace statikbe\linkchecker\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use statikbe\linkchecker\services\Hosts;
use statikbe\linkchecker\services\Ignores;
use statikbe\linkchecker\services\Index;
use statikbe\linkchecker\services\Urls;
use yii\db\Expression;

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
        // Until 1.3: an index on kind, which no query uses (8 values)
        $migration->dropIndexIfExists(Index::TABLE, ['kind']);

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

        // The domains of external links and whether they exist (Hosts). A row is added when a page with a new domain is read
        // (checkedAt null, due at once); queue jobs take the due rows in order, so they carry no list
        if (!$db->tableExists(Hosts::TABLE)) {
            $migration->createTable(Hosts::TABLE, [
                'id' => $migration->primaryKey(),
                'host' => $migration->string(255)->notNull(),
                'resolves' => $migration->boolean()->notNull(),
                'failures' => $migration->smallInteger()->notNull()->defaultValue(0),
                'refusals' => $migration->smallInteger()->notNull()->defaultValue(0),
                'refusedUntil' => $migration->dateTime(),
                'checkedAt' => $migration->dateTime(),
                'nextCheckAt' => $migration->dateTime()->notNull(),
            ]);
            $migration->createIndex(null, Hosts::TABLE, ['host'], true);
            $migration->createIndex(null, Hosts::TABLE, ['nextCheckAt']);
        } else {
            if (!$db->columnExists(Hosts::TABLE, 'failures')) {
                // Added in 1.2.1
                $migration->addColumn(Hosts::TABLE, 'failures', $migration->smallInteger()->notNull()->defaultValue(0)->after('resolves'));
            }
            // Added in 1.4.0
            if (!$db->columnExists(Hosts::TABLE, 'refusals')) {
                $migration->addColumn(Hosts::TABLE, 'refusals', $migration->smallInteger()->notNull()->defaultValue(0)->after('failures'));
                $migration->addColumn(Hosts::TABLE, 'refusedUntil', $migration->dateTime()->after('refusals'));
            }
            if (!$db->getTableSchema(Hosts::TABLE, true)?->getColumn('checkedAt')?->allowNull) {
                $migration->alterColumn(Hosts::TABLE, 'checkedAt', $migration->dateTime());
            }
            if (!$db->columnExists(Hosts::TABLE, 'nextCheckAt')) {
                $migration->addColumn(Hosts::TABLE, 'nextCheckAt', $migration->dateTime()->after('checkedAt'));
                // When each domain is due again, as Hosts::store() works it out: 7 days after a lookup that found it, an hour after
                // a first failure, a day after it was found missing
                foreach ([[7 * 24, ['and', ['resolves' => true], ['failures' => 0]]], [1, ['and', ['resolves' => true], ['>', 'failures', 0]]], [24, ['resolves' => false]]] as [$hours, $condition]) {
                    $next = $db->getIsPgsql() ? "[[checkedAt]] + interval '$hours hours'" : "DATE_ADD([[checkedAt]], INTERVAL $hours HOUR)";
                    $migration->update(Hosts::TABLE, ['nextCheckAt' => new Expression($next)], $condition);
                }
                $migration->alterColumn(Hosts::TABLE, 'nextCheckAt', $migration->dateTime()->notNull());
                $migration->createIndex(null, Hosts::TABLE, ['nextCheckAt']);
            }
        }

        // External URLs and the result of their last check (Urls). A row is added when a page with a new URL is read (status
        // 'new', checkedAt null, due at once)
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
                'unknowns' => $migration->smallInteger()->notNull()->defaultValue(0),
                'checkedAt' => $migration->dateTime(),
                'nextCheckAt' => $migration->dateTime()->notNull(),
            ]);
            $migration->createIndex(null, Urls::TABLE, ['urlHash'], true);
            $migration->createIndex(null, Urls::TABLE, ['nextCheckAt']);
        } else {
            // Added in 1.4.0
            if (!$db->columnExists(Urls::TABLE, 'unknowns')) {
                $migration->addColumn(Urls::TABLE, 'unknowns', $migration->smallInteger()->notNull()->defaultValue(0)->after('failures'));
            }
            if (!$db->getTableSchema(Urls::TABLE, true)?->getColumn('checkedAt')?->allowNull) {
                $migration->alterColumn(Urls::TABLE, 'checkedAt', $migration->dateTime());
            }
        }

        // Pages saved since they were read: one row per page, read by IndexPagesJob (1.4.0)
        if (!$db->tableExists(Index::PENDING_TABLE)) {
            $migration->createTable(Index::PENDING_TABLE, [
                'id' => $migration->primaryKey(),
                'pageId' => $migration->integer()->notNull(),
                'queuedAt' => $migration->bigInteger()->notNull(),
            ]);
            $migration->createIndex(null, Index::PENDING_TABLE, ['pageId'], true);
            $migration->addForeignKey(null, Index::PENDING_TABLE, ['pageId'], Table::ELEMENTS, ['id'], 'CASCADE');
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

    /**
     * Adds the rows of the domains and external URLs that the links table has but that weren't checked yet (before 1.4.0 a
     * row was only made by the first check), so the queue jobs find them.
     */
    public static function addMissingRows(Migration $migration): void
    {
        $db = $migration->db;
        $now = Db::prepareDateForDb(new \DateTime());
        $hosts = (new Query())
            ->select(['l.host'])
            ->distinct()
            ->from(['l' => Index::TABLE])
            ->leftJoin(['h' => Hosts::TABLE], '[[h.host]] = [[l.host]]')
            ->where(['h.id' => null])
            ->andWhere(['not', ['l.host' => null]])
            ->column($db);
        foreach (array_chunk($hosts, 500) as $chunk) {
            $migration->batchInsert(Hosts::TABLE, ['host', 'resolves', 'failures', 'nextCheckAt'], array_map(fn($host) => [$host, true, 0, $now], $chunk));
        }
        $urls = (new Query())
            ->select(['l.urlHash', 'url' => 'min([[l.url]])', 'host' => 'min([[l.host]])'])
            ->from(['l' => Index::TABLE])
            ->leftJoin(['u' => Urls::TABLE], '[[u.urlHash]] = [[l.urlHash]]')
            ->where(['u.id' => null])
            ->andWhere(['not', ['l.urlHash' => null]])
            ->groupBy(['l.urlHash'])
            ->all($db);
        foreach (array_chunk($urls, 500) as $chunk) {
            $migration->batchInsert(Urls::TABLE, ['urlHash', 'url', 'host', 'status', 'nextCheckAt'], array_map(fn($row) => [$row['urlHash'], $row['url'], $row['host'], Urls::NEW, $now], $chunk));
        }
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Index::PENDING_TABLE);
        $this->dropTableIfExists(Ignores::TABLE);
        $this->dropTableIfExists(Urls::TABLE);
        $this->dropTableIfExists(Hosts::TABLE);
        $this->dropTableIfExists(Index::ANCHORS_TABLE);
        $this->dropTableIfExists(Index::TABLE);
        return true;
    }
}
