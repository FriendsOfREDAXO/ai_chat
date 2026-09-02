<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Profile;

use rex;
use rex_sql;

/**
 * CRUD für `ai_chat_profile`. Reine Datenzugriffs-Schicht - Matching-Logik lebt
 * in ProfileResolver, Formular-/JSON-Aufbereitung in pages/profile.edit.php.
 */
class ProfileRepository
{
    /**
     * @return list<ChatProfile>
     */
    public function getAll(): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('ai_chat_profile') . ' ORDER BY priority DESC, id ASC');

        return $this->mapRows($sql);
    }

    /**
     * @return list<ChatProfile>
     */
    public function getEnabled(): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('ai_chat_profile') . ' WHERE status = 1 ORDER BY priority DESC, id ASC');

        return $this->mapRows($sql);
    }

    public function find(int $id): ?ChatProfile
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('ai_chat_profile') . ' WHERE id = ?', [$id]);

        if (0 === $sql->getRows()) {
            return null;
        }

        return ChatProfile::fromRow($this->rowAsArray($sql));
    }

    /**
     * @param array<string, mixed> $values Spaltenwerte wie in install.php (JSON-Felder bereits als String)
     */
    public function save(array $values, ?int $id = null): int
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_chat_profile'));

        foreach ($values as $column => $value) {
            $sql->setValue($column, $value);
        }

        $sql->setDateTimeValue('updatedate', time());

        if (null === $id) {
            $sql->setDateTimeValue('createdate', time());
            $sql->insert();

            return (int) $sql->getLastId();
        }

        $sql->setWhere(['id' => $id]);
        $sql->update();

        return $id;
    }

    public function delete(int $id): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_chat_profile'));
        $sql->setWhere(['id' => $id]);
        $sql->delete();
    }

    /**
     * @return list<ChatProfile>
     */
    private function mapRows(rex_sql $sql): array
    {
        $profiles = [];
        foreach ($sql as $row) {
            $profiles[] = ChatProfile::fromRow($this->rowAsArray($row));
        }

        return $profiles;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAsArray(rex_sql $sql): array
    {
        $row = [];
        foreach ($sql->getFieldnames() as $field) {
            $row[$field] = $sql->getValue($field);
        }

        return $row;
    }
}
