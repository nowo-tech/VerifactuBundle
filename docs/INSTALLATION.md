# Installation

## Table of contents

- [Requirements](#requirements)
- [Install via Composer](#install-via-composer)
- [Enable the bundle](#enable-the-bundle)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Optional configuration](#optional-configuration)
- [Next steps](#next-steps)

## Requirements

- PHP >= 8.1, < 8.6
- Symfony ^6.0 || ^7.0 || ^8.0
- Composer 2

## Install via Composer

```bash
composer require nowo-tech/verifactu-bundle
```

## Enable the bundle

Register the bundle in `config/bundles.php`:

```php
<?php

return [
    // ...
    Nowo\VerifactuBundle\NowoVerifactuBundle::class => ['all' => true],
];
```

## Symfony Flex recipe

When using [Symfony Flex](https://symfony.com/doc/current/setup/flex.html), the bundle recipe is applied automatically:

- Registers `NowoVerifactuBundle` in `config/bundles.php`
- Copies `config/packages/nowo_verifactu.yaml` with sensible defaults

Recipe path: `.symfony/recipe/nowo-tech/verifactu-bundle/1.0/`

Manual installs can copy the recipe file from that directory.

## Optional configuration

Copy or create `config/packages/nowo_verifactu.yaml`. See [Configuration](CONFIGURATION.md).

## Next steps

- [Configuration](CONFIGURATION.md)
- [Usage](USAGE.md)
- [Demo (Symfony 8)](../demo/symfony8/)
