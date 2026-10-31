<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Controller;

use JsonException;
use Nowo\DoctrineEncryptBundle\Form\SecretFieldApplier;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Form\PortabilityExportType;
use Nowo\InstanceSettingsBundle\Form\PortabilityImportType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\JsonUploadReader;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_first;
use function array_keys;
use function count;
use function date;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Admin UI of the instance settings: one page per section and the import / export page.
 *
 * Routes are declared with #[Route] (REQ-SF-004) and loaded by the `nowo_instance_settings` route loader, which
 * prepends `web_ui.path_prefix`; the service is not autoconfigured, so a host `routing.controllers` import never mounts
 * them (rule 7). Access: the request subscriber guards the whole UI, every action re-checks its area (section name or
 * import / export). Mutations are Symfony Forms with CSRF (REQ-SEC-005).
 */
final class SettingsController extends AbstractController
{
    /**
     * @param bool $portabilityEnabled `portability.web_ui` (import / export page)
     */
    public function __construct(
        private readonly SettingsSectionRegistry $registry,
        private readonly InstanceSettingsInterface $settings,
        private readonly AccessGuard $guard,
        private readonly SettingsPageFactory $pages,
        private readonly ConfigPortability $portability,
        private readonly JsonUploadReader $reader,
        private readonly bool $portabilityEnabled = true,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Redirects to the first section the user may open (or renders the empty state).
     */
    #[Route(path: '', name: InstanceSettingsRoutes::INDEX, methods: ['GET'])]
    public function index(): Response
    {
        $sections = $this->accessibleSections();
        $first    = array_key_first($sections);
        if ($first === null) {
            return $this->render($this->pages->template('section'), [
                'page' => $this->pages->empty($this->canUsePortability()),
            ]);
        }

        return $this->redirectToRoute(InstanceSettingsRoutes::SECTION, ['section' => $first]);
    }

    /**
     * Shows and saves one section.
     */
    #[Route(path: '/sections/{section}', name: InstanceSettingsRoutes::SECTION, requirements: ['section' => '[a-z][a-z0-9_]{0,63}'], methods: ['GET', 'POST'])]
    public function section(Request $request, string $section): Response
    {
        if (!$this->registry->has($section)) {
            throw new NotFoundHttpException('Unknown settings section.');
        }
        if (!$this->guard->isGranted($section)) {
            $this->guard->deny('Access denied to this settings section.');
        }

        $definition = $this->registry->get($section);
        $fields     = $this->registry->fields($section);
        $stored     = [];
        foreach ($fields as $name => $field) {
            if ($field->secret) {
                $stored[$name] = $this->settings->hasSecret($section . '.' . $name);
            }
        }

        $form = $this->createForm(SettingsSectionType::class, $this->settings->getSection($section), [
            'section'        => $definition,
            'fields'         => $fields,
            'stored_secrets' => $stored,
            'csrf_token_id'  => 'nowo_instance_settings_section_' . $section,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $changed = $this->settings->save($section, $this->submittedValues($form, $fields), SettingsChangedEvent::ORIGIN_FORM);
                $this->addFlash('success', $this->trans($changed === [] ? 'flash.unchanged' : 'flash.saved'));

                return $this->redirectToRoute(InstanceSettingsRoutes::SECTION, ['section' => $section]);
            } catch (InvalidSettingValueException $e) {
                foreach ($e->violations as $name => $messages) {
                    foreach ($messages as $message) {
                        $form->get($name)->addError(new FormError($message));
                    }
                }
            }
        }

        $actions = $definition instanceof SettingsSectionActionsInterface ? $definition->getActions(new SectionActionContext($section, $request, $this->guard->user())) : [];

        return $this->render($this->pages->template('section'), [
            'page'    => $this->pages->section($this->accessibleSections(), $section, $form->createView(), $actions, $this->canUsePortability()),
            'section' => $section,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Import / export page.
     */
    #[Route(path: '/portability', name: InstanceSettingsRoutes::PORTABILITY, methods: ['GET'])]
    public function portability(): Response
    {
        $this->denyPortabilityUnlessAllowed();

        return $this->renderPortability($this->importForm());
    }

    /**
     * Downloads the export envelope (POST + CSRF; `no-store`).
     */
    #[Route(path: '/portability/export', name: InstanceSettingsRoutes::EXPORT, methods: ['POST'])]
    public function export(Request $request): Response
    {
        $this->denyPortabilityUnlessAllowed();

        $form = $this->exportForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $withSecrets = $form->has('include_secrets') && $form->get('include_secrets')->getData() === true;

        try {
            // Area-aware: only the sections this user may open (plus the providers, part of the `_portability` area).
            $only = [...array_keys($this->accessibleSections()), ...$this->portability->providerNames()];
            $json = json_encode($this->portability->export($only, $withSecrets), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (PortabilityException|JsonException) {
            $this->addFlash('error', $this->trans('portability.error.export_failed'));

            return $this->redirectToRoute(InstanceSettingsRoutes::PORTABILITY);
        }

        $file     = $this->portability->schema() . ($withSecrets ? '-sealed' : '') . '-' . date('Ymd-His') . '.json';
        $response = new Response($json . "\n", Response::HTTP_OK, [
            'Content-Type'           => 'application/json; charset=UTF-8',
            'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * Validates and applies an uploaded envelope (all-or-nothing), or only validates it (dry run).
     */
    #[Route(path: '/portability/import', name: InstanceSettingsRoutes::IMPORT, methods: ['POST'])]
    public function import(Request $request): Response
    {
        $this->denyPortabilityUnlessAllowed();

        $form = $this->importForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderPortability($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file   = $form->get('file')->getData();
        $dryRun = $form->get('dry_run')->getData() === true;

        try {
            $result = $this->portability->import(
                $this->reader->fromUploadedFile($file instanceof UploadedFile ? $file : null),
                $dryRun,
                array_keys($this->accessibleSections()),
            );
        } catch (PortabilityException $e) {
            $this->addFlash('error', $this->trans('portability.error.' . $e->reason(), ['%key%' => $e->key() ?? '']));

            return $this->redirectToRoute(InstanceSettingsRoutes::PORTABILITY);
        }

        $count = count($result->changedKeys());
        $this->addFlash('success', $this->trans($dryRun ? 'portability.import.dry_run_ok' : 'portability.import.done', ['%count%' => $count]));

        return $this->redirectToRoute(InstanceSettingsRoutes::PORTABILITY);
    }

    /**
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
     * @return array<string, SettingsSectionInterface>
     */
    private function accessibleSections(): array
    {
        $sections = [];
        foreach ($this->registry->all() as $name => $section) {
            if ($this->guard->isGranted($name)) {
                $sections[$name] = $section;
            }
        }

        return $sections;
    }

    private function canUsePortability(): bool
    {
        return $this->portabilityEnabled && $this->guard->isGranted(InstanceSettingsAccessCheckerInterface::AREA_PORTABILITY);
    }

    private function denyPortabilityUnlessAllowed(): void
    {
        if (!$this->portabilityEnabled) {
            throw new NotFoundHttpException('Instance settings import / export is disabled.');
        }
        if (!$this->guard->isGranted(InstanceSettingsAccessCheckerInterface::AREA_PORTABILITY)) {
            $this->guard->deny('Access denied to the instance settings import / export.');
        }
    }

    /**
     * @param FormInterface<mixed> $import
     */
    private function renderPortability(FormInterface $import, int $status = Response::HTTP_OK): Response
    {
        return $this->render($this->pages->template('portability'), [
            'page' => $this->pages->portability($this->exportForm()->createView(), $import->createView(), $this->portability->supportsSealedSecrets()),
        ], new Response(status: $status));
    }

    /**
     * @return FormInterface<mixed>
     */
    private function exportForm(): FormInterface
    {
        return $this->createForm(PortabilityExportType::class, null, [
            'action'        => $this->generateUrl(InstanceSettingsRoutes::EXPORT),
            'allow_secrets' => $this->portability->supportsSealedSecrets(),
        ]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function importForm(): FormInterface
    {
        return $this->createForm(PortabilityImportType::class, null, [
            'action'    => $this->generateUrl(InstanceSettingsRoutes::IMPORT),
            'max_bytes' => $this->reader->maxBytes(),
        ]);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator instanceof TranslatorInterface ? $this->translator->trans($key, $params, SettingsPages::DOMAIN) : $key;
    }
}
