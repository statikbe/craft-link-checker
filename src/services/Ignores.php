<?php

namespace statikbe\linkchecker\services;

use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;

/**
 * Links an editor chose to ignore: by their stored value (a URL, a reference tag, mailto:), everywhere they're used. They
 * no longer show in the widget, the panel or the report, and never block publishing.
 */
class Ignores extends Component
{
    public const TABLE = '{{%linkchecker_ignores}}';

    /** @var array<string, true>|null */
    private ?array $hashes = null;

    public static function hash(string $url): string
    {
        return sha1(trim($url));
    }

    public function add(string $url, ?int $userId): void
    {
        Db::upsert(self::TABLE, [
            'url' => trim($url),
            'urlHash' => self::hash($url),
            'userId' => $userId,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
        ]);
        $this->hashes = null;
        Report::invalidate();
    }

    public function remove(int $id): void
    {
        Db::delete(self::TABLE, ['id' => $id]);
        $this->hashes = null;
        Report::invalidate();
    }

    public function isIgnored(string $url): bool
    {
        if ($this->hashes === null) {
            $this->hashes = array_fill_keys((new Query())->select(['urlHash'])->from(self::TABLE)->column(), true);
        }
        return isset($this->hashes[self::hash($url)]);
    }

    /**
     * Every ignored link, newest first, with who ignored it.
     *
     * @return array<array{id: int, url: string, dateCreated: string, user: string|null}>
     */
    public function all(): array
    {
        return (new Query())
            ->select(['i.id', 'i.url', 'i.dateCreated', 'user' => 'u.username'])
            ->from(['i' => self::TABLE])
            ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[i.userId]]')
            ->orderBy(['i.dateCreated' => SORT_DESC])
            ->all();
    }
}
