<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Transaction;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\InstanceSettingsBundle\Exception\SettingsConflictException;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\TransactionContract\TransientStorageFailure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function in_array;
use function is_string;
use function max;
use function str_starts_with;

/**
 * 1.19 (Beacon spec 239): a storage failure of a route of the bundle answers 503 or 409 instead of 500.
 *
 * - transient ({@see TransientStorageFailure} of the bundle's runner, or a DBAL {@see RetryableException}: deadlock, lock
 *   wait timeout): `503 Service Unavailable` with `Retry-After` (the runner's hint, else 1 s);
 * - conflict (a unique key refused the write of a concurrent request, or {@see SettingsConflictException}): `409 Conflict`.
 *
 * The chain of previous exceptions is walked, so a host decorator that wraps the failure keeps the answer. The JSON
 * endpoints (secret reveal, section test) answer `{"error": "unavailable" | "conflict"}`, never cacheable; the pages get a
 * {@see ServiceUnavailableHttpException} / {@see ConflictHttpException} with a fixed message, rendered by the
 * application's error page. The storage error stays the previous exception (for the logs): no SQL, no parameter and no
 * secret reaches the client.
 *
 * Only the bundle's routes: a controller of the bundle (route paths and names are the host's to configure). A host route
 * that embeds a section keeps the host's own error handling. Stateless.
 */
final readonly class StorageFailureListener
{
    /** kernel.exception priority: after the application's own listeners, before the framework's error listener (-128). */
    public const PRIORITY = -64;

    /** Controller namespace of the bundle (pages, reveal, test, files). */
    private const CONTROLLER_PREFIX = 'Nowo\\InstanceSettingsBundle\\';

    /** Routes that answer JSON. */
    private const JSON_ROUTES = [InstanceSettingsRoutes::REVEAL, InstanceSettingsRoutes::TEST];

    /** Retry-After when the failure carries no hint. */
    private const DEFAULT_RETRY_AFTER = 1;

    /**
     * Turns a transient failure into 503 and a conflict into 409 on the bundle's routes.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isBundleRequest($request)) {
            return;
        }

        $failure = $event->getThrowable();
        $retry   = $this->retryAfter($failure);
        if ($retry !== null) {
            if ($this->wantsJson($request)) {
                $event->setResponse($this->json('unavailable', Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => (string) $retry]));

                return;
            }
            $event->setThrowable(new ServiceUnavailableHttpException($retry, 'The service is temporarily unavailable. Retry later.', $failure));

            return;
        }

        if ($this->isConflict($failure)) {
            if ($this->wantsJson($request)) {
                $event->setResponse($this->json('conflict', Response::HTTP_CONFLICT));

                return;
            }
            $event->setThrowable(new ConflictHttpException('A concurrent request changed the same settings. Reload and retry.', $failure));
        }
    }

    /**
     * The Retry-After seconds of a transient failure, anywhere down the chain; null when the failure is not transient.
     */
    private function retryAfter(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return max(1, $current->retryAfterSeconds() ?? self::DEFAULT_RETRY_AFTER);
            }
            if ($current instanceof RetryableException) {
                return self::DEFAULT_RETRY_AFTER;
            }
        }

        return null;
    }

    /** Whether a unique key refused the write or a section form conflicted, anywhere down the chain. */
    private function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException || $current instanceof SettingsConflictException) {
                return true;
            }
        }

        return false;
    }

    private function isBundleRequest(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        return is_string($controller) && str_starts_with($controller, self::CONTROLLER_PREFIX);
    }

    private function wantsJson(Request $request): bool
    {
        return in_array($request->attributes->get('_route'), self::JSON_ROUTES, true) || $request->getPreferredFormat() === 'json';
    }

    /**
     * @param array<string, string> $headers
     */
    private function json(string $error, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $error], $status, $headers + ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
