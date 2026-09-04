<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Service;

use rex_addon;

/**
 * Baut System-/User-Prompt aus Scope, Personalisierung und RAG-Kontext zusammen -
 * die gleiche Logik, die GeminiService/OpenAiCompatibleService/CloudflareService
 * aktuell jeweils inline in ihrer eigenen buildGenerationPayload()-Methode
 * dupliziert. Neue Provider-Integrationen (z.B. AiPlatformService) nutzen diese
 * gemeinsame Stelle statt eine fünfte Kopie zu erstellen; die drei bestehenden
 * Services bleiben unangetastet (siehe TODO.md fuer die Nachziehen-Empfehlung).
 */
class PromptBuilder
{
    /**
     * @param array<string, mixed>|null $personalization
     */
    public static function buildSystemPrompt(?array $personalization, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?string $answerLanguageOverride = null): string
    {
        $systemPrompt = 'Wichtiger Zeit-Kontext: ' . SystemToolService::getDateTimeContext() . "\n\n";

        $addon = rex_addon::get('ai_chat');
        $customPrompt = $systemPromptOverride ?? (string) $addon->getConfig('frontend_prompt');
        $systemPrompt .= '' !== $customPrompt
            ? $customPrompt
            : 'Du bist ein hilfreicher Assistent für diese Website. Nutze den folgenden Kontext, um die Frage des Nutzers zu beantworten.';

        // Jedes Profil traegt addressingMode als echten, eigenstaendigen Wert (kein globaler
        // Fallback mehr seit der Hauptprofil-Entflechtung) - 'auto' bleibt der Default nur fuer
        // den seltenen Fall, dass gar kein Profil aufgeloest werden konnte.
        $addressingMode = $addressingModeOverride ?? 'auto';
        if ('formal' === $addressingMode) {
            $systemPrompt .= "\n- Sprich den Nutzer durchgehend förmlich mit 'Sie' an.";
        } elseif ('informal' === $addressingMode) {
            $systemPrompt .= "\n- Sprich den Nutzer durchgehend mit 'Du' an.";
        } elseif ('neutral' === $addressingMode) {
            $systemPrompt .= "\n- Verwende eine neutrale Sprache ohne direkte Anrede mit 'Du' oder 'Sie'.";
        } elseif ($personalization) {
            if ('informal' === ($personalization['mode'] ?? 'formal')) {
                $systemPrompt .= "\n- Sprich den Nutzer mit 'Du' an (Duzen).";
                if (!empty($personalization['name'])) {
                    $systemPrompt .= "\n- Der Name des Nutzers ist: " . $personalization['name'];
                }
            } else {
                $systemPrompt .= "\n- Sprich den Nutzer förmlich mit 'Sie' an (Siezen).";
            }
        }

        $systemPrompt .= "\n- Stelle keine Rückfrage zur Anrede (Du/Sie), frage nicht nach dem Namen und frage nicht 'Wer bist du?', außer der Nutzer fragt ausdrücklich danach.";
        $systemPrompt .= "\n- Starte ohne Smalltalk und ohne reine Begrüßungsfloskel, sondern antworte direkt inhaltlich auf die Frage.";
        $systemPrompt .= "\n- Ein Kontext-Abschnitt kann mit \"[Bereich: Name]\" markiert sein - das ist der Themenbereich, aus dem der Abschnitt stammt (z.B. \"Allgemein\" vs. \"News\"). Ist ein Bereich zusätzlich mit \"(aktuell)\" gekennzeichnet, enthält er die neuesten/zeitkritischen Inhalte - bevorzuge ihn, wenn der Nutzer nach dem aktuellen/neuesten Stand fragt.";

        $additionalContext = (string) $addon->getConfig('frontend_additional_context');
        if ('' !== $additionalContext) {
            $systemPrompt .= "\n\nZusätzliche Informationen:\n" . $additionalContext;
        }

        $systemPrompt .= "\n\n" . self::markdownFormattingInstruction();
        $systemPrompt .= "\n\nWenn die Antwort nicht im Kontext enthalten ist, sage, dass du es nicht weißt. " . self::answerLanguageInstruction($answerLanguageOverride);

        return $systemPrompt;
    }

    /**
     * Markdown-Formatierungsregel, angehaengt am Ende jedes System-Prompts alle vier Provider-
     * Implementierungen (OpenAiCompatibleService, GeminiService, CloudflareService, diese Klasse
     * fuer AiPlatformService) - ohne sie liefern LLMs mehrzeilige Code-Beispiele oft mit
     * einzelnen Backticks auf einer Zeile statt in einem dreifach-umschlossenen Codeblock, und
     * trennen Listen/Absaetze nur mit einem einzelnen statt einem doppelten Zeilenumbruch.
     * Parsedown (parseMarkdown()) und das Frontend-Markdown (ai-chat.js formatMessage()) werten
     * beides nach CommonMark-Regeln aus: ein einzelner Zeilenumbruch reicht dort nicht, um einen
     * neuen Absatz/eine neue Liste zu beginnen, wodurch die ganze Antwort als ein einziger,
     * unformatierter Absatz landet.
     */
    public static function markdownFormattingInstruction(): string
    {
        return 'Formatiere die Antwort als sauberes Markdown: Trenne Absätze, Listen und Code-Blöcke immer durch eine Leerzeile (nicht nur einen einzelnen Zeilenumbruch). Nutze für Code-Beispiele - auch kurze oder einzeilige - immer einen Codeblock mit dreifachen Backticks und Sprachangabe (z.B. ```html ... ```), niemals nur einzelne Backticks.';
    }

    /**
     * "Antworte immer auf Deutsch."/eine deutlich nachdruecklichere Variante mit Override -
     * geteilt zwischen dieser Klasse und den drei aelteren Provider-Services, die ihre eigene
     * System-Prompt-Zeile bauen (siehe formatContextPrefix() fuer dasselbe Muster). Ohne
     * $override unveraendertes Verhalten (Deutsch) - ChatProfile::$answerLanguage ist bewusst
     * optional.
     *
     * Bewusst nachdruecklicher formuliert als die milde Deutsch-Variante: ein LLM antwortet per
     * Standardverhalten sehr zuverlaessig in der Sprache der Nutzerfrage/des Kontexts - diese
     * Tendenz ist staerker als eine einzeilige Anweisung ("Antworte auf Englisch" reichte im
     * Test nicht, das Modell blieb trotzdem beim Deutsch von Frage/Kontext). Die explizite
     * Gegenueberstellung "AUCH WENN ... auf Deutsch sind" adressiert genau diesen Default.
     */
    public static function answerLanguageInstruction(?string $answerLanguageOverride): string
    {
        $language = trim((string) $answerLanguageOverride);

        if ('' === $language) {
            return 'Antworte immer auf Deutsch.';
        }

        return 'WICHTIG: Antworte ausschließlich auf ' . $language . ' - AUCH WENN die Frage des Nutzers und der bereitgestellte Kontext auf Deutsch sind. Übersetze den Inhalt vollständig in dieser Sprache, unabhängig von der Sprache der Frage.';
    }

    /**
     * @param array<int, array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool}> $context
     */
    public static function buildUserPrompt(string $prompt, array $context): string
    {
        $contextText = '';
        foreach ($context as $item) {
            $contextText .= self::formatContextPrefix($item) . $item['content'] . "\n\n";
        }

        return $contextText . 'Frage des Nutzers: ' . $prompt;
    }

    /**
     * "Context [Bereich: Name (aktuell) — Beschreibung]: "-Praefix fuer einen einzelnen
     * Kontext-Abschnitt, geteilt zwischen dieser Klasse und den drei aelteren Provider-
     * Services (Gemini/Cloudflare/OpenAiCompatible), die ihre eigene buildXPayload()-Methode
     * haben, aber dieselbe Praefix-Logik brauchen (source_label/_description/_is_timely
     * kommen alle aus ChatQueryService::findSimilarContent(), siehe dort).
     *
     * @param array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool} $item
     */
    public static function formatContextPrefix(array $item): string
    {
        $bracket = self::formatSourceLabelBracket($item);

        return '' !== $bracket ? 'Context ' . $bracket . ': ' : 'Context: ';
    }

    /**
     * " [Bereich: Name (aktuell) — Beschreibung]" oder '' ohne Label - der Teil von
     * formatContextPrefix(), den OpenAiCompatibleService fuer sein abweichendes
     * "DOKUMENT N [Bereich: ...]:"-Format separat braucht (siehe dort).
     *
     * @param array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool} $item
     */
    public static function formatSourceLabelBracket(array $item): string
    {
        $label = trim((string) ($item['source_label'] ?? ''));
        if ('' === $label) {
            return '';
        }

        $timelySuffix = !empty($item['source_label_is_timely']) ? ' (aktuell)' : '';
        $description = trim((string) ($item['source_label_description'] ?? ''));
        $descriptionSuffix = '' !== $description ? ' — ' . $description : '';

        return '[Bereich: ' . $label . $timelySuffix . $descriptionSuffix . ']';
    }
}
