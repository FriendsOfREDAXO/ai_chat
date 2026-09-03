<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Profile;

use rex;
use rex_addon;
use rex_sql;

/**
 * CRUD für `ai_chat_theme` (zentrale Theme-Verwaltung, siehe ChatTheme). Das Anlegen/
 * Bearbeiten selbst läuft über ein normales rex_form (siehe pages/themes.php, gleiches
 * Muster wie pages/profiles.php) - diese Klasse deckt Lesezugriffe (Dropdown-Optionen,
 * Aufloesung des effektiven Themes in ProfileTheme) sowie das Löschen ab, das eine
 * Sonderregel braucht (siehe delete()).
 */
class ThemeRepository
{
    /**
     * @return list<ChatTheme>
     */
    public function getAll(): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('ai_chat_theme') . ' ORDER BY name ASC');

        return $this->mapRows($sql);
    }

    public function find(int $id): ?ChatTheme
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('ai_chat_theme') . ' WHERE id = ?', [$id]);

        if (0 === $sql->getRows()) {
            return null;
        }

        return ChatTheme::fromRow($this->rowAsArray($sql));
    }

    /**
     * Löscht ein Theme, sofern es nicht das globale Standard-Theme ist (davon muss immer
     * genau eines existieren). Profile, die genau dieses Theme gewählt hatten, fallen
     * automatisch auf das globale Standard-Theme zurück (theme_id = NULL) statt den
     * Löschvorgang zu blockieren oder eine kaputte Fremdschlüssel-Referenz zu hinterlassen.
     *
     * @return bool false, wenn das Theme das aktuelle Standard-Theme ist und deshalb NICHT gelöscht wurde.
     */
    public function delete(int $id): bool
    {
        $defaultThemeId = (int) rex_addon::get('ai_chat')->getConfig('default_theme_id', 0);
        if ($id === $defaultThemeId) {
            return false;
        }

        $resetSql = rex_sql::factory();
        $resetSql->setTable(rex::getTable('ai_chat_profile'));
        $resetSql->setWhere(['theme_id' => $id]);
        $resetSql->setValue('theme_id', null);
        $resetSql->update();

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_chat_theme'));
        $sql->setWhere(['id' => $id]);
        $sql->delete();

        return true;
    }

    /**
     * @return list<ChatTheme>
     */
    private function mapRows(rex_sql $sql): array
    {
        $themes = [];
        foreach ($sql as $row) {
            $themes[] = ChatTheme::fromRow($this->rowAsArray($row));
        }

        return $themes;
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
