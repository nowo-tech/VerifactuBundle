#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Validates translation YAML syntax and key parity across locales.
 */
$baseDir = dirname(__DIR__);
$files   = glob($baseDir . '/src/Resources/translations/NowoVerifactuBundle.*.yaml') ?: [];

if ($files === []) {
    fwrite(STDERR, "No translation files found.\n");
    exit(1);
}

/** @var array<string, list<string>> $keysByLocale */
$keysByLocale = [];

foreach ($files as $file) {
    $parsed = Symfony\Component\Yaml\Yaml::parseFile($file);
    if (!is_array($parsed)) {
        fwrite(STDERR, "Invalid YAML structure: {$file}\n");
        exit(1);
    }

    $locale = basename($file, '.yaml');
    $locale = str_replace('NowoVerifactuBundle.', '', $locale);
    $keysByLocale[$locale] = flattenKeys($parsed);
    echo "OK: {$file}\n";
}

$referenceLocale = 'en';
$referenceKeys   = $keysByLocale[$referenceLocale] ?? null;

if ($referenceKeys === null) {
    fwrite(STDERR, "Reference locale 'en' not found.\n");
    exit(1);
}

sort($referenceKeys);
$failed = false;

foreach ($keysByLocale as $locale => $keys) {
    sort($keys);
    if ($keys === $referenceKeys) {
        continue;
    }

    $failed = true;
    $missing = array_diff($referenceKeys, $keys);
    $extra   = array_diff($keys, $referenceKeys);
    fwrite(STDERR, "Key mismatch in locale '{$locale}':\n");
    foreach ($missing as $key) {
        fwrite(STDERR, "  missing: {$key}\n");
    }
    foreach ($extra as $key) {
        fwrite(STDERR, "  extra: {$key}\n");
    }
}

exit($failed ? 1 : 0);

/**
 * @param array<string, mixed> $data
 *
 * @return list<string>
 */
function flattenKeys(array $data, string $prefix = ''): array
{
    $keys = [];

    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys = array_merge($keys, flattenKeys($value, $path));
        } else {
            $keys[] = $path;
        }
    }

    return $keys;
}
