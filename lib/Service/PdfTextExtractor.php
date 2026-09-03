<?php

namespace FriendsOfRedaxo\AiChat\Service;

use Smalot\PdfParser\Parser;

/**
 * Extrahiert reinen Text aus PDF-Dateien fuer die Indexierung
 * (MediaPoolContentProvider). Nutzt, falls auf dem Server vorhanden, das
 * externe `pdftotext` (poppler-utils) fuer bessere Extraktionsqualitaet bei
 * komplexem Layout, faellt sonst auf die reine PHP-Bibliothek
 * smalot/pdfparser zurueck - die laeuft ueberall (kein Systembinary noetig),
 * ist aber bei manchen PDFs weniger praezise als poppler. Kein OCR: gescannte/
 * reine Bild-PDFs liefern leeren Text.
 */
class PdfTextExtractor
{
    public function extract(string $absoluteFilePath): string
    {
        if (!is_file($absoluteFilePath)) {
            return '';
        }

        $viaPdftotext = $this->extractViaPdftotext($absoluteFilePath);
        if ('' !== $viaPdftotext) {
            return $this->sanitizeUtf8($viaPdftotext);
        }

        return $this->sanitizeUtf8($this->extractViaPdfparser($absoluteFilePath));
    }

    private function extractViaPdftotext(string $absoluteFilePath): string
    {
        $binary = self::resolvePdftotext();
        if (null === $binary) {
            return '';
        }

        // -enc UTF-8: erzwingt konsistente Ausgabe-Kodierung statt der sonst
        // Locale-abhaengigen Voreinstellung von poppler.
        $output = shell_exec($binary . ' -enc UTF-8 -layout ' . escapeshellarg($absoluteFilePath) . ' - 2>/dev/null');

        return trim((string) $output);
    }

    /**
     * Sowohl pdftotext (bei ungewoehnlichen PDF-internen Font-/Encoding-
     * Deklarationen) als auch smalot/pdfparser koennen Byte-Sequenzen liefern,
     * die kein gueltiges UTF-8 sind - der anschliessende json_encode() beim
     * Embedding-Request bricht damit sonst komplett ab (siehe
     * IndexerService::indexPreparedDocument()). Ungueltige Sequenzen werden
     * entfernt statt das ganze Dokument zu verwerfen.
     */
    private function sanitizeUtf8(string $text): string
    {
        if ('' === $text || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character('none');
        $cleaned = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);

        return $cleaned;
    }

    private function extractViaPdfparser(string $absoluteFilePath): string
    {
        if (!class_exists(Parser::class)) {
            return '';
        }

        try {
            $parser = new Parser();
            $document = $parser->parseFile($absoluteFilePath);

            return trim($document->getText());
        } catch (\Throwable) {
            // Verschluesselte/korrupte/nicht unterstuetzte PDFs sollen die
            // Indexierung nicht abbrechen - Aufrufer ueberspringt das Dokument
            // bei leerem Rueckgabewert.
            return '';
        }
    }

    private static function resolvePdftotext(): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) {
            return null;
        }

        return SystemCheckService::resolveBinary('pdftotext');
    }
}
