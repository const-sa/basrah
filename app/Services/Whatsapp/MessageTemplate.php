<?php

namespace App\Services\Whatsapp;

/**
 * Fills {key} placeholders in a notification template body.
 */
final class MessageTemplate
{
    /** @param array<string, string|int|float|null> $vars */
    public static function render(string $template, array $vars): string
    {
        $replacements = [];

        foreach ($vars as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
