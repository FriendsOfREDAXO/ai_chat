<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Profile;

/**
 * Datenobjekt für eine Zeile aus `ai_chat_theme` - ein benanntes, wiederverwendbares
 * Farb-/Avatar-/Eckenradius-Bündel, das global als Standard oder gezielt von einzelnen
 * Profilen ausgewählt werden kann (siehe ChatProfile::$themeId, ProfileTheme). Bewusst
 * OHNE Position: die Widget-Position ist kein Theme-Bestandteil, sondern bleibt ein
 * eigenes, unabhängiges Override je Profil (siehe ChatProfile::$themePosition).
 */
final class ChatTheme
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $primaryColor,
        public readonly ?string $followupColor,
        public readonly ?string $headerBgColor,
        public readonly ?string $chatBgColor,
        public readonly ?string $textColor,
        public readonly ?string $botMessageBgColor,
        public readonly ?string $botMessageTextColor,
        public readonly ?string $userMessageTextColor,
        public readonly ?string $inputBgColor,
        public readonly ?string $inputTextColor,
        public readonly ?string $inputBorderColor,
        public readonly ?string $borderRadius,
        public readonly ?string $avatar,
    ) {
    }

    /**
     * @param array<string, mixed> $row Rohe Zeile aus rex_sql (Spaltennamen wie in install.php)
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            primaryColor: self::nullableString($row['primary_color'] ?? null),
            followupColor: self::nullableString($row['followup_color'] ?? null),
            headerBgColor: self::nullableString($row['header_bg_color'] ?? null),
            chatBgColor: self::nullableString($row['chat_bg_color'] ?? null),
            textColor: self::nullableString($row['text_color'] ?? null),
            botMessageBgColor: self::nullableString($row['bot_message_bg_color'] ?? null),
            botMessageTextColor: self::nullableString($row['bot_message_text_color'] ?? null),
            userMessageTextColor: self::nullableString($row['user_message_text_color'] ?? null),
            inputBgColor: self::nullableString($row['input_bg_color'] ?? null),
            inputTextColor: self::nullableString($row['input_text_color'] ?? null),
            inputBorderColor: self::nullableString($row['input_border_color'] ?? null),
            borderRadius: self::nullableString($row['border_radius'] ?? null),
            avatar: self::nullableString($row['avatar'] ?? null),
        );
    }

    private static function nullableString(mixed $raw): ?string
    {
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        return $raw;
    }
}
