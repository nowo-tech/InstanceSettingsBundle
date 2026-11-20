<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Transaction;

use Nowo\InstanceSettingsBundle\Exception\SettingsConflictException;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function in_array;
use function is_int;
use function is_string;
use function str_starts_with;

/**
 * 1.19 (Beacon spec 239), 1.22 (REQ-RUNTIME-003 rule 6), request-level detection since the next release: a storage failure of a route of the bundle answers a conflict
 * instead of a 500.
 *
 * - lock conflict (a {@see TransientStorageFailure} of the bundle's runner, or a deadlock, lock wait timeout or
 *   serialization failure anywhere down the chain): the conflict status of the {@see ConflictResponsePolicy} (**409**
 *   by default, 503 when the host configures it) with a conflict code ({@see ConflictCode}):
 *   - `CONFLICT_001` with `Retry-After` (the runner's hint, else the policy's) when nothing of the request was committed;
 *   - `CONFLICT_002` without `Retry-After` when part of it may already be committed: an earlier write unit of the same
 *     request committed (any commit since the request's `CommitMark`, {@see ConflictCode::forRequest()}, whoever made it;
 *     or the bundle runner's {@see CommitLedger} moved since the request started), the failure is a ConcurrencyConflict
 *     that committed, or a lost savepoint with no deadlock behind it (outcome unknown);
 * - domain conflict ({@see SettingsConflictException}: another editor changed the same fields): `409` with the
 *   bundle's own code {@see self::SETTINGS_CHANGED}, never a `CONFLICT_*` code.
 *
 * A unique constraint violation or a lost connection is never mapped: the bundle catches its expected "only one" races
 * where they happen (seeds, expiry dates, reminders, section test markers) or serialises them under the section lock,
 * so one that reaches the kernel is a real error (500).
 *
 * The chain of previous exceptions is walked, so a host decorator that wraps the failure keeps the answer. The JSON
 * endpoints (secret reveal, section test) answer `{"error": "conflict" | "unavailable", "code": "…"}` (`unavailable`
 * when the policy status is 503), never cacheable; the pages get an {@see HttpException} with a fixed message, rendered
 * by the application's error page. Every answer carries the code in the `X-Conflict-Code` header. The storage error
 * stays the previous exception (for the logs): no SQL, no parameter and no secret reaches the client.
 *
 * Only the bundle's routes: a controller of the bundle (route paths and names are the host's to configure). A host route
 * that embeds a section keeps the host's own error handling. Stateless apart from the shared ledger, a monotonic
 * counter of the process read by difference only (FrankenPHP worker mode, kernel reset off).
 */
final readonly class StorageFailureListener
{
    /** kernel.exception priority: after the application's own listeners, before the framework's error listener (-128). */
    public const PRIORITY = -64;

    /** kernel.request priority: before the router (32), so the count is taken before any controller writes. */
    public const REQUEST_PRIORITY = 256;

    /** Request attribute holding the ledger count when the request started. */
    public const COMMITS_ATTRIBUTE = '_nowo_instance_settings_commits';

    /** Response header carrying the conflict code (pages and JSON). */
    public const CODE_HEADER = 'X-Conflict-Code';

    /** Code of a domain conflict: another editor changed the same settings (not a lock conflict). */
    public const SETTINGS_CHANGED = 'SETTINGS_CHANGED';

    /** Message of a CONFLICT_001 page (nothing committed). */
    public const RETRY_SAFE_MESSAGE = 'A concurrent request was changing the same settings. Retry later.';

    /** Message of a CONFLICT_002 page (part of the request may be committed). */
    public const COMMITTED_MESSAGE = 'Part of the change may already be saved. Reload before trying again.';

    /** Controller namespace of the bundle (pages, reveal, test, files). */
    private const CONTROLLER_PREFIX = 'Nowo\\InstanceSettingsBundle\\';

    /** Routes that answer JSON. */
    private const JSON_ROUTES = [InstanceSettingsRoutes::REVEAL, InstanceSettingsRoutes::TEST];

    private ConflictResponsePolicy $policy;

    private TransientFailureClassifier $classifier;

    /**
     * @param ConflictResponsePolicy|null $policy the conflict status and default Retry-After (null = 409, 1 s)
     * @param CommitLedger|null $ledger outermost commits of the bundle's runner (null = no commit tracking)
     */
    public function __construct(?ConflictResponsePolicy $policy = null, private ?CommitLedger $ledger = null)
    {
        $this->policy     = $policy ?? new ConflictResponsePolicy();
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Remembers how many units the bundle's runner had committed when the request started.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::REQUEST_PRIORITY)]
    public function onRequest(RequestEvent $event): void
    {
        if ($this->ledger instanceof CommitLedger) {
            $event->getRequest()->attributes->set(self::COMMITS_ATTRIBUTE, $this->ledger->count());
        }
    }

    /**
     * Turns a lock conflict into the policy's conflict answer and a domain conflict into 409 on the bundle's routes.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isBundleRequest($request)) {
            return;
        }

        $failure = $event->getThrowable();
        $code    = $this->lockConflictCode($failure, $request);
        if ($code instanceof ConflictCode) {
            $headers = [self::CODE_HEADER => $code->value];
            if ($code->retrySafe()) {
                $headers['Retry-After'] = (string) $this->policy->retryAfter($this->hint($failure));
            }
            if ($this->wantsJson($request)) {
                $event->setResponse($this->json($this->policy->status === Response::HTTP_CONFLICT ? 'conflict' : 'unavailable', $code->value, $this->policy->status, $headers));

                return;
            }
            $event->setThrowable(new HttpException($this->policy->status, $code->retrySafe() ? self::RETRY_SAFE_MESSAGE : self::COMMITTED_MESSAGE, $failure, $headers));

            return;
        }

        if ($this->isSettingsConflict($failure)) {
            $headers = [self::CODE_HEADER => self::SETTINGS_CHANGED];
            if ($this->wantsJson($request)) {
                $event->setResponse($this->json('conflict', self::SETTINGS_CHANGED, Response::HTTP_CONFLICT, $headers));

                return;
            }
            $event->setThrowable(new ConflictHttpException('A concurrent request changed the same settings. Reload and retry.', $failure, 0, $headers));
        }
    }

    /**
     * The conflict code of a lock conflict, or null when the failure is not one.
     */
    private function lockConflictCode(Throwable $failure, Request $request): ?ConflictCode
    {
        $transient = $this->classifier->isTransient($failure);
        for ($current = $failure; !$transient && $current instanceof Throwable; $current = $current->getPrevious()) {
            $transient = $current instanceof TransientStorageFailure;
        }

        if (!$transient && !$this->classifier->isCommitOutcomeUnknown($failure)) {
            return null;
        }

        // Request-level detection (transaction-doctrine >= 1.6): forRequest() also sees a commit made earlier in the same
        // request by the host or another bundle (the request's CommitMark, with `commit_detection` on). The bundle's own
        // ledger stays as a second source, so its own commits count even when the host turned detection off.
        $code = ConflictCode::forRequest($request, $failure);

        return $code->retrySafe() && $this->committedEarlier($request) ? ConflictCode::Committed : $code;
    }

    /** Whether a unit of the bundle's runner committed since the request started. */
    private function committedEarlier(Request $request): bool
    {
        $start = $request->attributes->get(self::COMMITS_ATTRIBUTE);

        return $this->ledger instanceof CommitLedger && is_int($start) && $this->ledger->count() > $start;
    }

    /** The first Retry-After hint of the chain (a transient failure of the runner); null for none or zero. */
    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint !== null && $hint > 0 ? $hint : null;
            }
        }

        return null;
    }

    /** Whether a section form conflicted, anywhere down the chain. */
    private function isSettingsConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof SettingsConflictException) {
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
    private function json(string $error, string $code, int $status, array $headers): JsonResponse
    {
        return new JsonResponse(['error' => $error, 'code' => $code], $status, $headers + ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
