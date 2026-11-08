<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function preg_match;
use function str_replace;

/**
 * Serves the image of an image setting field (spec 220 §5.5–§5.7): route {@see InstanceSettingsRoutes::FILE},
 * `{files.path_prefix}/{section}/{field}/{hash12}[-{variant}].{ext}`, outside the admin prefix and not guarded by the
 * admin access listener (it lives outside the controller namespace).
 *
 * - No path is ever built from input: the section and field are looked up in the registry, the file in the store by
 *   `(section, field, hash prefix, variant)`. An unknown section / field / hash, a variant the field does not declare,
 *   a wrong extension or a non-public field the viewer may not open all answer the same empty 404.
 * - Any image the field holds or held is served while its row exists (a replaced image stays for
 *   `files.orphan_retention`, so e-mails already sent keep their images), never one of another field.
 * - Headers: stored type, `nosniff`, inline disposition with an ASCII name, `default-src 'none'; sandbox`, strong
 *   `ETag` (`If-None-Match` answers 304 before the bytes are read), immutable public cache and cross-origin CORP for
 *   public fields, private cache and same-origin CORP otherwise, `no-referrer`, `noindex`. `HEAD` is supported, `Range`
 *   is not.
 *
 * Stateless (worker-safe): the bytes live only in the response of the current request.
 */
final readonly class SettingFileController
{
    /** CSP of a served file. */
    public const CSP = "default-src 'none'; sandbox";

    /** Route requirement of the `file` segment: 12 hex characters, optionally `-<variant>`. */
    public const FILE_REQUIREMENT = '[0-9a-f]{12}(?:-[1-9][0-9]{0,3})?';

    /** Route requirement of the extension. */
    public const EXT_REQUIREMENT = 'png|jpg|webp';

    /** Cache lifetime of a public file (one year, immutable URL). */
    public const PUBLIC_MAX_AGE = 31_536_000;

    /** Cache lifetime of a private file. */
    public const PRIVATE_MAX_AGE = 3600;

    /**
     * @param SettingsSectionRegistry $registry sections and fields
     * @param SettingFileStoreInterface $store file store
     * @param AccessGuard $guard section areas of non-public fields
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private SettingFileStoreInterface $store,
        private AccessGuard $guard,
    ) {
    }

    /**
     * Answers the file, a 304, or a 404.
     */
    public function __invoke(Request $request, string $section, string $field, string $file, string $ext): Response
    {
        if (preg_match('/^([0-9a-f]{12})(?:-([1-9][0-9]{0,3}))?$/', $file, $match) !== 1 || !$this->registry->has($section)) {
            return $this->notFound();
        }
        $definition = $this->registry->fields($section)[$field] ?? null;
        $spec       = $definition?->image;
        $variant    = (int) ($match[2] ?? 0);
        if ($spec === null || ($variant !== 0 && !$spec->hasVariant($variant)) || (!$spec->public && !$this->guard->isGranted($section))) {
            return $this->notFound();
        }

        $record = $this->store->find($section, $field, $match[1], $variant);
        if (!$record instanceof SettingFileRecord || SettingFileUrls::EXTENSIONS[$record->mimeType] !== $ext) {
            return $this->notFound();
        }

        $response = new Response();
        $response->setEtag($record->sha256 . ($variant === 0 ? '' : '-' . $variant));
        if ($spec->public) {
            $response->setPublic();
            $response->setMaxAge(self::PUBLIC_MAX_AGE);
            $response->setImmutable();
        } else {
            $response->setPrivate();
            $response->setMaxAge(self::PRIVATE_MAX_AGE);
        }
        $response->headers->add([
            'Content-Type'                 => $record->mimeType,
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Disposition'          => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, str_replace('_', '-', $section . '-' . $field) . '.' . $ext),
            'Content-Security-Policy'      => self::CSP,
            'Cross-Origin-Resource-Policy' => $spec->public ? 'cross-origin' : 'same-origin',
            'Referrer-Policy'              => 'no-referrer',
            'X-Robots-Tag'                 => 'noindex',
        ]);
        if ($response->isNotModified($request)) {
            return $response;
        }

        $response->headers->set('Content-Length', (string) $record->byteSize);
        if (!$request->isMethod('HEAD')) {
            $response->setContent($this->store->read($record));
        }

        return $response;
    }

    private function notFound(): Response
    {
        $response = new Response('', Response::HTTP_NOT_FOUND, ['X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex']);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
