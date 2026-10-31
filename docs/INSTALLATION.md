# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Manual setup (without Flex)](#manual-setup-without-flex)
- [Database schema](#database-schema)
- [Encryption key](#encryption-key)
- [Firewall and access control](#firewall-and-access-control)
- [Twig Extra and assets](#twig-extra-and-assets)
- [Verify](#verify)

## Requirements

- PHP `>=8.2 <8.6` (Symfony 8.x requires PHP 8.4+)
- Symfony 7.4, 8.0 or 8.1: FrameworkBundle, Form, Validator, Security CSRF, TwigBundle, Console; **SecurityBundle** for the admin UI
- Doctrine ORM 3.3+ with DoctrineBundle
- `nowo-tech/doctrine-encrypt-bundle` ^2.10 (secret fields), `nowo-tech/ui-kit-bundle` ^2.17.3 and `nowo-tech/form-kit-bundle` ^2.17 (admin UI), `twig/extra-bundle` + `twig/string-extra` — all installed as dependencies

## Install with Composer

```bash
composer require nowo-tech/instance-settings-bundle
```

## Symfony Flex recipe

The recipe lives in this repository under [`.symfony/recipe/nowo-tech/instance-settings-bundle/1.0/`](../.symfony/recipe/nowo-tech/instance-settings-bundle/1.0/) and installs:

- `config/packages/nowo_instance_settings.yaml` — safe defaults (`ROLE_ADMIN`, `allow_unauthenticated: false`) with commented examples;
- `config/routes/nowo_instance_settings.yaml` — the route loader import (`/admin/settings`);
- `config/packages/security_nowo_instance_settings.yaml` — a commented `access_control` reminder.

## Manual setup (without Flex)

1. Register the bundles (DoctrineEncrypt **before** this bundle):

   ```php
   // config/bundles.php
   return [
       // …
       Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
       Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle::class => ['all' => true],
       Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
       Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
       Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle::class => ['all' => true],
   ];
   ```

2. Import the routes (opt-in; never picked up by `routing.controllers`):

   ```yaml
   # config/routes/nowo_instance_settings.yaml
   nowo_instance_settings:
       resource: .
       type: nowo_instance_settings
   ```

3. Configure the bundle (optional — defaults are safe), see [CONFIGURATION.md](CONFIGURATION.md).

4. Make sure `framework.csrf_protection` and `framework.form.csrf_protection` are on (default in Symfony apps).

## Database schema

The Doctrine mapping is registered automatically. Generate and run a migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Encryption key

Configure DoctrineEncrypt (default profile) and create its key once (never commit it):

```yaml
# config/packages/nowo_doctrine_encrypt.yaml
nowo_doctrine_encrypt:
    default_profile: default
    profiles:
        default:
            encryptor_class: Halite
            secret_directory_path: '%kernel.project_dir%/var/secrets'
```

```bash
php bin/console doctrine:encrypt:generate-secret-key
```

## Firewall and access control

The UI is private (`ROLE_ADMIN`) but the bundle trusts the user only when a firewall matched the request: put the prefix behind your firewall and add an `access_control` rule.

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/settings, roles: ROLE_ADMIN }
```

## Twig Extra and assets

The bundle requires `twig/extra-bundle` and `twig/string-extra` (REQ-TWIG-004): enable `TwigExtraBundle` (Flex does). The pages use UiKit's stylesheet and scripts (asset package `nowo_ui_kit`) through the UiKit layout; run `php bin/console assets:install` once after install / upgrade. The bundle ships no assets of its own.

## Verify

```bash
php bin/console debug:router | grep nowo_instance_settings
php bin/console nowo:instance-settings:export
```

Open `/admin/settings` as an admin: one tab per registered section (an empty state until you contribute one, see [USAGE.md](USAGE.md#contributing-a-settings-section)).
