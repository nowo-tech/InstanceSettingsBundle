<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Embed;

use LogicException;
use Nowo\DoctrineEncryptBundle\Form\SecretFieldApplier;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Form\SettingImageCrop;
use Nowo\InstanceSettingsBundle\Form\SettingImageType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\HiddenSecrets;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionHiddenSecretsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionNoticesInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Ui\ImagePreview;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_push;
use function sprintf;
use function str_starts_with;

/**
 * Shows and saves one settings section: the bundle's own section page and, since 1.6 (IS-1), the same page embedded
 * in a host route and layout ({@see self::handle()}).
 *
 * Both paths run the same code, so they enforce the same rules: the UI gate (`security.access_roles` or the custom
 * checker, area null) and the section area (`security.area_roles.<section>`) through {@see AccessGuard}, the
 * per-section CSRF token id, secrets that are never rendered back ("blank = keep", explicit "clear"), uploads through
 * the image pipeline of {@see InstanceSettingsInterface::save()}, and provider-dependent fields.
 *
 * Embedding only narrows: the nav shows the sections that are both allowlisted and open to the user, a section outside
 * the allowlist is a 404, and one the user may not open is denied exactly as on the bundle route. After a save the
 * user is redirected to the host route of the section, generated from its route name (never a URL from the request).
 *
 * Stateless (worker-safe): every dependency is a service; nothing is kept between calls.
 */
final readonly class SettingsSectionEmbedder
{
    /** Translation key (bundle domain) of the 1.9 notice shown while hidden secrets are dropped; override its text in the host translations. */
    public const HIDDEN_SECRETS_NOTICE = 'notice.hidden_secrets_dropped';

    /** Translation key (bundle domain) of the notice naming an invalid secret typed into a hidden row (1.9, keep_typed). */
    public const HIDDEN_SECRET_INVALID_NOTICE = 'notice.hidden_secret_invalid';

    /** Token id prefix of the section forms (one id per section; shared by the bundle page and the embed). */
    public const CSRF_TOKEN_PREFIX = 'nowo_instance_settings_section_';

    /**
     * @param bool $portabilityEnabled `portability.web_ui` (the "Import / export" link of the bundle page)
     * @param HiddenSecrets $hiddenSecrets `web_ui.hidden_secrets` (1.9): a secret typed into a hidden row is dropped or saved
     * @param bool $hiddenSecretsNotice `web_ui.hidden_secrets_notice` (1.9): a notice above the form of a section with
     *                                  provider-dependent secrets while they are dropped
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private InstanceSettingsInterface $settings,
        private AccessGuard $guard,
        private SettingsPageFactory $pages,
        private FormFactoryInterface $formFactory,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private ?RequestStack $requestStack = null,
        private bool $portabilityEnabled = true,
        private ?TranslatorInterface $translator = null,
        private HiddenSecrets $hiddenSecrets = HiddenSecrets::Drop,
        private bool $hiddenSecretsNotice = false,
    ) {
    }

    /**
     * Shows (GET) or saves (POST) `$section` inside a host route: tabs for the allowlisted sections the user may open,
     * linked to the host routes; after a successful save, a redirect to the host route of `$section`.
     *
     * Call it from the host route's own controller (or a `forward()`), not from a Twig `render(controller())`
     * fragment: fragments are GET sub-requests, so a save would be dropped (refused with a LogicException).
     *
     * @throws NotFoundHttpException for an unknown section or one outside the allowlist
     * @throws LogicException for a fragment sub-request of a POST, or a host route that cannot be generated as a
     *                        local path (unknown route, missing parameter, another host or scheme)
     */
    public function handle(Request $request, string $section, SectionEmbedOptions $options): Response
    {
        $main = $this->requestStack?->getMainRequest();
        if ($main instanceof Request && $main !== $request && $main->isMethod(Request::METHOD_POST) && !$request->isMethod(Request::METHOD_POST)) {
            throw new LogicException('SettingsSectionEmbedder::handle() runs in a GET sub-request of a POST (Twig render(controller()) fragment?): the save would be dropped. Call it from the host route\'s controller or forward() to it.');
        }

        return $this->process($request, $section, $options, $this->hostUrls($options));
    }

    /**
     * The bundle's own section page ({@see SettingsController::section()}).
     *
     * @internal
     */
    public function handleBundlePage(Request $request, string $section): Response
    {
        return $this->process($request, $section, null, []);
    }

    /**
     * Sections the user may open (the UI gate and their own area), in registry order; with `$options`, only the
     * allowlisted ones. Empty when the UI gate denies the user.
     *
     * @return array<string, SettingsSectionInterface>
     */
    public function accessibleSections(?SectionEmbedOptions $options = null): array
    {
        if (!$this->guard->isGranted()) {
            return [];
        }
        $sections = [];
        foreach ($this->registry->all() as $name => $section) {
            if ((!$options instanceof SectionEmbedOptions || $options->allows($name)) && $this->guard->isGranted($name)) {
                $sections[$name] = $section;
            }
        }

        return $sections;
    }

    /**
     * @param array<string, string> $hostUrls section => host URL of an embed (checked by {@see self::hostUrls()})
     */
    private function process(Request $request, string $section, ?SectionEmbedOptions $options, array $hostUrls): Response
    {
        // The UI gate first, on every path (audit JE-L1). On the bundle route InstanceSettingsAccessListener has already
        // passed it (same check, same message), so its output and order do not change; a host route or a direct call
        // is not behind the listener. A user without UI access is denied for every section name, allowlisted or not,
        // so the embed cannot be used to probe the registry.
        if (!$this->guard->isGranted()) {
            $this->guard->deny('Access denied to the instance settings UI.');
        }
        if (!$this->registry->has($section) || ($options instanceof SectionEmbedOptions && !$options->allows($section))) {
            throw new NotFoundHttpException('Unknown settings section.');
        }
        if (!$this->guard->isGranted($section)) {
            $this->guard->deny('Access denied to this settings section.');
        }

        $definition    = $this->registry->get($section);
        $fields        = $this->registry->fields($section);
        $hiddenSecrets = $definition instanceof SettingsSectionHiddenSecretsInterface ? $definition->getHiddenSecrets() : $this->hiddenSecrets;
        $stored        = [];
        $images        = [];
        $previews      = [];
        foreach ($fields as $name => $field) {
            if ($field->secret) {
                $stored[$name] = $this->settings->hasSecret($section . '.' . $name);
            }
            if ($field->image !== null) {
                $file          = $this->settings->getFile($section . '.' . $name);
                $images[$name] = $file instanceof StoredSettingFile;
                // 1.5 (audit IO Q3): opt-in preview of the stored image, by its file route URL only.
                $preview = $file instanceof StoredSettingFile && ($field->formOptions['preview'] ?? false) === true ? ImagePreview::of($file, $field, $definition->getTranslationDomain()) : null;
                if ($preview instanceof ImagePreview) {
                    $previews[] = $preview;
                }
            }
        }

        $form = $this->formFactory->create(SettingsSectionType::class, $this->settings->getSection($section), [
            'section'        => $definition,
            'fields'         => $fields,
            'stored_secrets' => $stored,
            'stored_images'  => $images,
            'csrf_token_id'  => self::CSRF_TOKEN_PREFIX . $section,
            'hidden_secrets' => $hiddenSecrets,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $changed = $this->settings->save($section, $this->submittedValues($form, $fields, $hiddenSecrets), SettingsChangedEvent::ORIGIN_FORM);
                $this->addFlash('success', $this->trans($changed === [] ? 'flash.unchanged' : 'flash.saved'));

                return new RedirectResponse($options instanceof SectionEmbedOptions ? $hostUrls[$section] : $this->urlGenerator->generate(InstanceSettingsRoutes::SECTION, ['section' => $section]));
            } catch (InvalidSettingValueException $e) {
                foreach ($e->violations as $name => $messages) {
                    foreach ($messages as $message) {
                        $form->get($name)->addError(new FormError($message));
                    }
                }
            }
        }

        $context = new SectionActionContext($section, $request, $this->guard->user());
        $actions = $definition instanceof SettingsSectionActionsInterface ? $definition->getActions($context) : [];
        $notices = $definition instanceof SettingsSectionNoticesInterface ? $this->pages->notices($definition->getNotices($context), $definition->getTranslationDomain()) : [];
        if ($this->hiddenSecretsNotice && $hiddenSecrets === HiddenSecrets::Drop && $this->hasHiddenSecrets($fields)) {
            // 1.9 (Beacon spec 222): say that a secret typed for a provider that is not selected is not saved.
            $notices[] = new Label(self::HIDDEN_SECRETS_NOTICE, NowoInstanceSettingsBundle::TRANSLATION_DOMAIN);
        }
        if ($form->isSubmitted() && $hiddenSecrets === HiddenSecrets::KeepTyped) {
            // 1.9 (audit KB-I-L1): an invalid secret typed into a hidden row refuses the save with an error the user
            // cannot see; name it above the form.
            array_push($notices, ...$this->hiddenSecretErrors($form, $fields, $definition->getTranslationDomain()));
        }

        if ($options instanceof SectionEmbedOptions) {
            $tabs = [];
            foreach ($this->accessibleSections($options) as $name => $tab) {
                $tabs[$name] = ['section' => $tab, 'href' => $hostUrls[$name]];
            }
            $page     = $this->pages->embeddedSection($tabs, $section, $form->createView(), $actions, $options->header, $options->layout);
            $template = $options->template ?? $this->pages->template('section');
            $extra    = $options->context;
        } else {
            $page     = $this->pages->section($this->accessibleSections(), $section, $form->createView(), $actions, $this->canUsePortability());
            $template = $this->pages->template('section');
            $extra    = [];
        }

        $content = $this->twig->render($template, [
            'page'           => $page,
            'section'        => $section,
            'notices'        => $notices,
            'image_previews' => $previews,
            // 1.8: an image field with the crop step: the page loads UiKit's image-crop script.
            'image_crop' => SettingImageCrop::inForm($form),
        ] + ($options instanceof SectionEmbedOptions ? ['embedded' => true] : []) + $extra);

        return new Response($content, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * Host URL of every allowlisted section, generated once per {@see self::handle()} before any check (audit JE-L3:
     * a typo, a missing parameter or a wrong section parameter fails for every user, not only for those who see that
     * tab). Each URL must be a local absolute path, `/` not followed by `/` or `\` (audit JE-I1): a route on another
     * host or scheme (network path or absolute URL, e.g. from a host placeholder) is refused, so the redirect after a
     * save never leaves the current host.
     *
     * @return array<string, string>
     */
    private function hostUrls(SectionEmbedOptions $options): array
    {
        $urls = [];
        foreach ($options->sections as $section) {
            [$route, $parameters] = $options->routeOf($section);
            try {
                $url = $this->urlGenerator->generate($route, $parameters);
            } catch (RoutingException $e) {
                throw new LogicException(sprintf('The host route "%s" of the embedded section "%s" cannot be generated: %s', $route, $section, $e->getMessage()), 0, $e);
            }
            if (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
                throw new LogicException(sprintf('The host route "%s" of the embedded section "%s" must generate a local path (got "%s"): no other host or scheme.', $route, $section, $url));
            }
            $urls[$section] = $url;
        }

        return $urls;
    }

    /**
     * Values to save: every field visible for the submitted values (provider-dependent fields hidden for the submitted
     * controlling value are left out, so "empty = delete" and validation only apply to visible fields), plus a null
     * for a hidden secret whose "clear" box was checked (with {@see HiddenSecrets::KeepTyped}, 1.9: a hidden secret is
     * handled as a visible one: a typed value is written too, blank keeps).
     *
     * @param FormInterface<mixed> $form
     * @param array<string, SettingField> $fields
     *
     * @return array<string, mixed>
     */
    private function submittedValues(FormInterface $form, array $fields, HiddenSecrets $hiddenSecrets): array
    {
        /** @var array<string, mixed> $data */
        $data   = (array) $form->getData();
        $values = [];
        foreach ($fields as $name => $field) {
            if (!$field->isVisibleFor($data) && (!$field->secret || $hiddenSecrets !== HiddenSecrets::KeepTyped)) {
                // Hidden for the submitted provider (1.3): neither validated nor written; its stored row is kept,
                // except an explicit "clear" on a secret, which deletes it (never writes a value).
                if ($field->secret) {
                    SecretFieldApplier::applyData($form->get($name)->getData(), static function (?string $secret) use (&$values, $name): void {
                        if ($secret === null) {
                            $values[$name] = null;
                        }
                    });
                }

                continue;
            }
            if ($field->image !== null) {
                // Image (1.4): a new upload replaces, "remove" deletes the row, nothing keeps the stored image.
                $child  = $form->get($name);
                $upload = $child->get(SettingImageType::FILE)->getData();
                if ($upload instanceof UploadedFile) {
                    $values[$name] = $upload;
                } elseif ($child->has(SettingImageType::REMOVE) && $child->get(SettingImageType::REMOVE)->getData() === true) {
                    $values[$name] = null;
                }

                continue;
            }
            if (!$field->secret) {
                $values[$name] = $data[$name] ?? null;

                continue;
            }
            SecretFieldApplier::applyData($form->get($name)->getData(), static function (?string $secret) use (&$values, $name): void {
                $values[$name] = $secret;
            });
        }

        return $values;
    }

    /**
     * One notice per provider-dependent secret hidden for the submitted values that carries an error (keep_typed: the
     * typed value was validated but its row is hidden): `%field%` is its translated label, `%message%` its first error.
     *
     * @param FormInterface<mixed> $form
     * @param array<string, SettingField> $fields
     *
     * @return list<Label>
     */
    private function hiddenSecretErrors(FormInterface $form, array $fields, string $domain): array
    {
        /** @var array<string, mixed> $data */
        $data    = (array) $form->getData();
        $notices = [];
        foreach ($fields as $name => $field) {
            if (!$field->secret || $field->visibleWhen === null || $field->isVisibleFor($data)) {
                continue;
            }
            foreach ($form->get($name)->getErrors(true) as $error) {
                // The first error only: one notice per field.
                $notices[] = new Label(self::HIDDEN_SECRET_INVALID_NOTICE, NowoInstanceSettingsBundle::TRANSLATION_DOMAIN, [
                    '%field%'   => $this->trans($field->labelKey(), [], $domain),
                    '%message%' => $error->getMessage(),
                ]);

                break;
            }
        }

        return $notices;
    }

    /**
     * Whether a section has a provider-dependent secret (a row that may be hidden while a secret is typed into it).
     *
     * @param array<string, SettingField> $fields
     */
    private function hasHiddenSecrets(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->secret && $field->visibleWhen !== null) {
                return true;
            }
        }

        return false;
    }

    private function canUsePortability(): bool
    {
        return $this->portabilityEnabled && $this->guard->isGranted(InstanceSettingsAccessCheckerInterface::AREA_PORTABILITY);
    }

    /**
     * Like AbstractController::addFlash(): the session of the request stack (a missing session is the host's error).
     */
    private function addFlash(string $type, string $message): void
    {
        $session = $this->requestStack?->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            // @igor-ignore - The session belongs to the current request (request stack); nothing is kept on the service.
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * @param array<string, int|string> $params
     */
    private function trans(string $key, array $params = [], string $domain = SettingsPages::DOMAIN): string
    {
        return $this->translator instanceof TranslatorInterface ? $this->translator->trans($key, $params, $domain) : $key;
    }
}
