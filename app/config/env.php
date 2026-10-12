<?php
declare(strict_types=1);

function load_environment_file(): void
{
    $envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

    if (!is_file($envFile) || !is_readable($envFile)) {
        throw new RuntimeException('Application environment file is missing or unreadable.');
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Application environment file could not be read.');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            throw new RuntimeException('Application environment file contains an invalid entry.');
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            throw new RuntimeException('Application environment file contains an invalid variable name.');
        }

        if (
            strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

load_environment_file();
