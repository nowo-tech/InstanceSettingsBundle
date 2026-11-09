<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Embed;

use Nowo\DoctrineEncryptBundle\Form\SecretFieldApplier;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Form\SettingImageType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionNoticesInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Ui\ImagePreview;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

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
    /** Token id prefix of the section forms (one id per section; shared by the bundle page and the embed). */
    public const CSRF_TOKEN_PREFIX = 'nowo_instance_settings_section_';

    /**
     * @param bool $portabilityEnabled `portability.web_ui` (the "Import / export" link of the bundle page)
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
    ) {
    }

    /**
     * Shows (GET) or saves (POST) `$section` inside a host route: tabs for the allowlisted sections the user may open,
     * linked to the host routes; after a successful save, a redirect to the host route of `$section`.
     *
     * @throws NotFoundHttpException for an unknown section or one outside the allowlist
     */
    public function handle(Request $request, string $section, SectionEmbedOptions $options): Response
    {
        return $this->process($request, $section, $options);
    }

    /**
     * The bundle's own section page ({@see SettingsController::section()}).
     *
     * @internal
     */
    public function handleBundlePage(Request $request, string $section): Response
    {
        return $this->process($request, $section, null);
    }

    /**
     * Sections the user may open (the UI gate and their own area), in registry order; with `$options`, only the
     * allowlisted ones.
     *
     * @return array<string, SettingsSectionInterface>
     */
    public function accessibleSections(?SectionEmbedOptions $options = null): array
    {
        if ($options instanceof SectionEmbedOptions && !$this->guard->isGranted()) {
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

    private function process(Request $request, string $section, ?SectionEmbedOptions $options): Response
    {
        // The bundle route is gated by InstanceSettingsAccessListener before the controller runs; a host route is not,
        // so the UI gate is checked here, first (the bundle route's order and message): a user without UI access is
        // denied for every section name, allowlisted or not, so the embed cannot be used to probe the registry.
        if ($options instanceof SectionEmbedOptions && !$this->guard->isGranted()) {
            $this->guard->deny('Access denied to the instance settings UI.');
        }
        if (!$this->registry->has($section) || ($options instanceof SectionEmbedOptions && !$options->allows($section))) {
            throw new NotFoundHttpException('Unknown settings section.');
        }
        if (!$this->guard->isGranted($section)) {
            $this->guard->deny('Access denied to this settings section.');
        }

        $definition = $this->registry->get($section);
        $fields     = $this->registry->fields($section);
        $stored     = [];
        $images     = [];
        $previews   = [];
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
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $changed = $this->settings->save($section, $this->submittedValues($form, $fields), SettingsChangedEvent::ORIGIN_FORM);
                $this->addFlash('success', $this->trans($changed === [] ? 'flash.unchanged' : 'flash.saved'));

                return new RedirectResponse($this->sectionUrl($section, $options));
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

        if ($options instanceof SectionEmbedOptions) {
            $tabs = [];
            foreach ($this->accessibleSections($options) as $name => $tab) {
                $tabs[$name] = ['section' => $tab, 'href' => $this->sectionUrl($name, $options)];
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
        ] + ($options instanceof SectionEmbedOptions ? ['embedded' => true] : []) + $extra);

        return new Response($content, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * URL of a section page: the host route of an embed (a route name from host code, generated by the router), else
     * the bundle route.
     */
    private function sectionUrl(string $section, ?SectionEmbedOptions $options): string
    {
        if (!$options instanceof SectionEmbedOptions) {
            return $this->urlGenerator->generate(InstanceSettingsRoutes::SECTION, ['section' => $section]);
        }
        [$route, $parameters] = $options->routeOf($section);

        return $this->urlGenerator->generate($route, $parameters);
    }

    /**
     * Values to save: every field visible for the submitted values (provider-dependent fields hidden for the submitted
     * controlling value are left out, so "empty = delete" and validation only apply to visible fields), plus a null
     * for a hidden secret whose "clear" box was checked.
     *
     * @param FormInterface<mixed> $form
     * @param array<string, SettingField> $fields
     *
     * @return array<string, mixed>
     */
    private function submittedValues(FormInterface $form, array $fields): array
    {
        /** @var array<string, mixed> $data */
        $data   = (array) $form->getData();
        $values = [];
        foreach ($fields as $name => $field) {
            if (!$field->isVisibleFor($data)) {
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
    private function trans(string $key, array $params = []): string
    {
        return $this->translator instanceof TranslatorInterface ? $this->translator->trans($key, $params, SettingsPages::DOMAIN) : $key;
    }
}
