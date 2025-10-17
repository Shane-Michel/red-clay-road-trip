<?php

namespace Dotenv;

final class Dotenv
{
    /** @var string */
    private $path;

    /** @var string */
    private $filename;

    private function __construct(string $path, string $filename)
    {
        $this->path = rtrim($path, DIRECTORY_SEPARATOR);
        $this->filename = $filename;
    }

    public static function createImmutable(string $path, ?string $filename = null): self
    {
        $filename = $filename ?? '.env';

        return new self($path, $filename);
    }

    public function safeLoad(): void
    {
        $envPath = $this->path . DIRECTORY_SEPARATOR . $this->filename;
        if (!is_readable($envPath)) {
            return;
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            return;
        }

        $lines = preg_split('/(\r\n|\r|\n)/', $contents);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }

            $separatorPosition = strpos($trimmed, '=');
            if ($separatorPosition === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $separatorPosition));
            if ($key === '') {
                continue;
            }

            $value = trim(substr($trimmed, $separatorPosition + 1));
            if ($value !== '') {
                $quoteChar = $value[0];
                if (($quoteChar === '"' || $quoteChar === "'") && substr($value, -1) === $quoteChar) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($this->isVariableSet($key)) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function isVariableSet(string $key): bool
    {
        if (getenv($key) !== false) {
            return true;
        }

        if (isset($_ENV[$key]) || isset($_SERVER[$key])) {
            return true;
        }

        return false;
    }
}
