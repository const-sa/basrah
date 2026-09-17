<?php

namespace App\Services\Whatsapp;

use RuntimeException;

/**
 * Minimal read/write access to the project's .env file.
 * A key keeps its place and its comment; only the value changes.
 */
final class EnvFile
{
    public function __construct(private string $path) {}

    public static function make(?string $path = null): self
    {
        return new self($path ?? app()->environmentFilePath());
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function writable(): bool
    {
        return $this->exists() && is_writable($this->path);
    }

    /**
     * Current value of a key, ignoring commented out lines.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        if (! $this->exists()) {
            return $default;
        }

        if (! preg_match($this->pattern($key, false), (string) file_get_contents($this->path), $matches)) {
            return $default;
        }

        return $this->unquote(trim($matches['value']));
    }

    /**
     * Write the given key => value pairs back to the file.
     *
     * A key that only exists as a comment is uncommented in place; a key that is
     * missing altogether is appended. Values are written verbatim, quoted only
     * when they contain characters the dotenv parser would choke on.
     *
     * @param  array<string, string|null>  $values
     */
    public function set(array $values): void
    {
        if (! $this->writable()) {
            throw new RuntimeException('The .env file is not writable: '.$this->path);
        }

        $contents = (string) file_get_contents($this->path);

        // Append with whatever the file already uses, so a Windows editor does
        // not end up with a lone CRLF in an otherwise LF file (or the reverse).
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->quote((string) $value);

            // Prefer an active line, then a commented one, so the key keeps its place.
            foreach ([false, true] as $allowCommented) {
                $pattern = $this->pattern($key, $allowCommented);

                if (preg_match($pattern, $contents)) {
                    $contents = preg_replace_callback(
                        $pattern,
                        fn (array $matches) => $matches['indent'].$line,
                        $contents,
                        1
                    );

                    continue 2;
                }
            }

            $contents = rtrim($contents, "\r\n").$eol.$line.$eol;
        }

        if (file_put_contents($this->path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Could not write to the .env file: '.$this->path);
        }
    }

    private function pattern(string $key, bool $allowCommented): string
    {
        return '/^(?<indent>[ \t]*)'.($allowCommented ? '#[ \t]*' : '')
            .preg_quote($key, '/').'[ \t]*=(?<value>.*)$/m';
    }

    /**
     * Quote a value when it is empty or carries characters dotenv treats specially.
     */
    private function quote(string $value): string
    {
        // A newline would silently turn the rest of the value into a new key.
        $value = str_replace(["\r", "\n"], '', $value);

        if ($value === '' || preg_match('/^[A-Za-z0-9_.\-\/:@]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
    }

    private function unquote(string $value): string
    {
        $quote = $value[0] ?? '';

        if (strlen($value) > 1 && ($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
            $value = substr($value, 1, -1);

            return $quote === '"' ? str_replace(['\"', '\\\\'], ['"', '\\'], $value) : $value;
        }

        return $value;
    }
}
