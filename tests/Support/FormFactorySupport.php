<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Nowo\DoctrineEncryptBundle\Form\Type\SecretType;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\Form\PortabilityExportType;
use Nowo\InstanceSettingsBundle\Form\PortabilityImportType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

/**
 * Form factory with the bundle FormKit types (merger injected), CSRF and validation, without a kernel.
 */
final class FormFactorySupport
{
    public static function merger(): FormOptionsMerger
    {
        $profile = NowoInstanceSettingsExtension::formKitProfile();
        unset($profile['alias']);
        /** @var array<string, array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>}> $profiles */
        $profiles = [NowoInstanceSettingsBundle::FORM_KIT_PROFILE => $profile + ['by_form' => []]];

        return new FormOptionsMerger($profiles, NowoInstanceSettingsBundle::FORM_KIT_PROFILE, new ConstraintDefinitionFactory());
    }

    public static function factory(bool $validCsrf = true): FormFactoryInterface
    {
        $merger = self::merger();
        $types  = [new SettingsSectionType(), new PortabilityImportType(), new PortabilityExportType()];
        foreach ($types as $type) {
            $type->setFormOptionsMerger($merger);
        }

        return Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([...$types, new SecretType()], []))
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new CsrfExtension(new class($validCsrf) implements CsrfTokenManagerInterface {
                public function __construct(private readonly bool $valid)
                {
                }

                public function getToken(string $tokenId): CsrfToken
                {
                    return new CsrfToken($tokenId, 'token-' . $tokenId);
                }

                public function refreshToken(string $tokenId): CsrfToken
                {
                    return $this->getToken($tokenId);
                }

                public function removeToken(string $tokenId): ?string
                {
                    return null;
                }

                public function isTokenValid(CsrfToken $token): bool
                {
                    return $this->valid;
                }
            }))
            ->getFormFactory();
    }
}
