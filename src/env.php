<?php

declare(strict_types=1);

function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($name !== '' && getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}

function env(string $key, ?string $default = null): string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Missing required environment variable: {$key}");
    }

    return (string) $value;
}

function bootstrapApp(): void
{
    $envPath = dirname(__DIR__) . '/.env';
    if (is_readable($envPath)) {
        loadEnvFile($envPath);
    }
}
