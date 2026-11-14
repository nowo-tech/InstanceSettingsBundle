<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Controller;

use JsonException;
use LogicException;
use Nowo\InstanceSettingsBundle\Embed\SettingsSectionEmbedder;
use Nowo\InstanceSettingsBundle\Form\PortabilityExportType;
use Nowo\InstanceSettingsBundle\Form\PortabilityImportType;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\JsonUploadReader;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Secret\SecretRevealer;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

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
    /** CSRF token id prefix of the reveal endpoint (1.15): one id per section. */
    public const REVEAL_TOKEN_PREFIX = 'nowo_instance_settings_secret_reveal_';

    /** Request body parameter carrying the CSRF token of a reveal call (1.15). */
    public const REVEAL_TOKEN_FIELD = '_token';

    /**
     * @param InstanceSettingsInterface $settings used only to build the section page when no embedder is given
     * @param bool $portabilityEnabled `portability.web_ui` (import / export page)
     * @param SettingsSectionEmbedder|null $embedder section page (1.6: shared with host embeds; wired by the extension).
     *                                               Null (a controller built by hand, 1.5 signature): section() builds
     *                                               one from this controller's arguments and its service locator
     * @param SecretRevealer|null $revealer secret reveal (1.15; wired by the extension). Null (a controller built by
     *                                      hand with an older signature): {@see self::revealSecret()} refuses to run
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
        private readonly ?SettingsSectionEmbedder $embedder = null,
        private readonly ?SecretRevealer $revealer = null,
    ) {
    }

    /**
     * Redirects to the first section the user may open (or renders the empty state).
     *
     * 1.16: the first one that is not in `web_ui.navigation_hidden`; when every section the user may open is hidden,
     * the first of them (as before 1.16). Hiding never changes which sections the user may open.
     */
    #[Route(path: '', name: InstanceSettingsRoutes::INDEX, methods: ['GET'])]
    public function index(): Response
    {
        $navigation = $this->pages->navigation();
        $navigation->reportUnknown($this->registry->names());
        $first = $navigation->firstSection($this->accessibleSections());
        if ($first === null) {
            return $this->render($this->pages->template('section'), [
                'page' => $this->pages->empty($this->canUsePortability()),
            ]);
        }

        return $this->redirectToRoute(InstanceSettingsRoutes::SECTION, ['section' => $first]);
    }

    /**
     * Shows and saves one section (the same code as a host embed: {@see SettingsSectionEmbedder}).
     */
    #[Route(path: '/sections/{section}', name: InstanceSettingsRoutes::SECTION, requirements: ['section' => '[a-z][a-z0-9_]{0,63}'], methods: ['GET', 'POST'])]
    public function section(Request $request, string $section): Response
    {
        return ($this->embedder ?? $this->fallbackEmbedder())->handleBundlePage($request, $section);
    }

    /**
     * Reveals one stored secret of a section (1.15, POST + CSRF): the plaintext of the single named field, in the
     * response body only.
     *
     * The checks are {@see SecretRevealer}'s (UI gate, section area, reveal area, `revealable: true`, byte-exact field
     * name, rate limit) plus this section's CSRF token, and they all run here, on the server: the page itself only
     * carries a button, never the value.
     * The answer is `{"key": "<section>.<field>", "value": "<secret>"}`, never cacheable (`no-store`, private) and
     * never in a URL; a revealable field without a stored secret answers 404 `{"key": …, "error": "not_stored"}`, and a
     * stored secret that is not UTF-8 text answers 409 `{"key": …, "error": "not_text"}` (audit F2: a secret is never
     * handed over mangled, and the audit trail then records the refusal, not a reveal).
     */
    #[Route(path: '/sections/{section}/secrets/{field}/reveal', name: InstanceSettingsRoutes::REVEAL, requirements: ['section' => '[a-z][a-z0-9_]{0,63}', 'field' => '[a-z][a-z0-9_]{0,63}'], methods: ['POST'])]
    public function revealSecret(Request $request, string $section, string $field): JsonResponse
    {
        if (!$this->revealer instanceof SecretRevealer) {
            throw new LogicException('SettingsController::revealSecret() needs the SecretRevealer service (pass it to the constructor).');
        }
        // The roles and the opt-in first, so a call without them is refused and audited whatever else it carries;
        // then the CSRF token of this section, so a forged request never reaches the secret itself.
        $this->revealer->assertRevealable($section, $field);
        if (!$this->isCsrfTokenValid(self::REVEAL_TOKEN_PREFIX . $section, (string) $request->request->get(self::REVEAL_TOKEN_FIELD))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $key = $section . '.' . $field;

        try {
            $value = $this->revealer->reveal($section, $field);
        } catch (ConflictHttpException) {
            // Audit F2: the stored secret is not UTF-8 text, so it cannot be delivered as JSON. Answer the same
            // never-cacheable shape instead of a 500 error page, and the audit trail records the refusal.
            return $this->noStore(new JsonResponse(['key' => $key, 'error' => SecretRevealer::REASON_NOT_TEXT], Response::HTTP_CONFLICT));
        }

        return $this->noStore($value === null
            ? new JsonResponse(['key' => $key, 'error' => 'not_stored'], Response::HTTP_NOT_FOUND)
            : new JsonResponse(['key' => $key, 'value' => $value]));
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
        $withFiles   = $form->has('include_files') && $form->get('include_files')->getData() === true;

        try {
            // Area-aware: only the sections this user may open (plus the providers, part of the `_portability` area).
            $only = [...array_keys($this->accessibleSections()), ...$this->portability->providerNames()];
            $json = json_encode($this->portability->export($only, $withSecrets, $withFiles), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
     * The section page of a controller built without the embedder (1.5 constructor signature): the same class, built
     * from this controller's arguments and the AbstractController service locator (form factory, Twig, router, request
     * stack), so the checks are the same as with the wired service.
     */
    private function fallbackEmbedder(): SettingsSectionEmbedder
    {
        $formFactory = $this->container->get('form.factory');
        $twig        = $this->container->get('twig');
        $router      = $this->container->get('router');
        $stack       = $this->container->get('request_stack');
        if (!$formFactory instanceof FormFactoryInterface || !$twig instanceof Environment || !$router instanceof UrlGeneratorInterface || !$stack instanceof RequestStack) {
            throw new LogicException('SettingsController::section() needs the form factory, Twig and the router (or the SettingsSectionEmbedder service).');
        }

        return new SettingsSectionEmbedder($this->registry, $this->settings, $this->guard, $this->pages, $formFactory, $twig, $router, $stack, $this->portabilityEnabled, $this->translator, revealer: $this->revealer);
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
            'allow_files'   => $this->portability->hasImageFields(),
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
     * Marks a response as never cacheable and never indexed: a revealed secret must not be stored anywhere
     * (`no-store`, private, `nosniff`, no referrer).
     */
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'no-referrer',
            'X-Robots-Tag'           => 'noindex',
        ]);

        return $response;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator instanceof TranslatorInterface ? $this->translator->trans($key, $params, SettingsPages::DOMAIN) : $key;
    }
}
