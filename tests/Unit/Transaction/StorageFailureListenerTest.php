<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use LogicException;
use Nowo\InstanceSettingsBundle\Exception\SettingsConflictException;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Transaction\StorageFailureListener;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * 1.19 (Beacon spec 239), 1.22 (REQ-RUNTIME-003 rule 6): 409 + CONFLICT_001 / CONFLICT_002 (503 per host) and the
 * domain 409 on the bundle's routes only; unique violations and lost connections are never mapped.
 */
final class StorageFailureListenerTest extends TestCase
{
    private const CONTROLLER = 'Nowo\\InstanceSettingsBundle\\Controller\\SettingsController::section';

    public function testATransientFailureOfAPageIsA409RetrySafeWithRetryAfter(): void
    {
        $event = $this->handle(new TransientStorageFailure(5, previous: new RuntimeException('SQLSTATE[40001] secret-value')));

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame([StorageFailureListener::CODE_HEADER => ConflictCode::RETRY_SAFE, 'Retry-After' => '5'], $answer->getHeaders());
        self::assertStringNotContainsString('SQLSTATE', $answer->getMessage());
        self::assertSame(StorageFailureListener::RETRY_SAFE_MESSAGE, $answer->getMessage());
        self::assertNull($event->getResponse());
    }

    public function testADbalLockWaitWrappedByTheHostIsA409OfOneSecond(): void
    {
        $event = $this->handle(new LogicException('host wrapper', 0, new LockWaitTimeoutException($this->driverException(), null)));

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame('1', $answer->getHeaders()['Retry-After']);
        self::assertSame(ConflictCode::RETRY_SAFE, $answer->getHeaders()[StorageFailureListener::CODE_HEADER]);
    }

    public function testATransientFailureWithoutHintOrZeroGetsThePolicyRetryAfter(): void
    {
        $answer = $this->handle(new TransientStorageFailure(0))->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame('1', $answer->getHeaders()['Retry-After']);

        $answer = $this->handle(new TransientStorageFailure(), policy: new ConflictResponsePolicy(409, 7))->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame('7', $answer->getHeaders()['Retry-After']);
    }

    public function testTheHostCanKeepThe503(): void
    {
        $answer = $this->handle(new TransientStorageFailure(2), policy: new ConflictResponsePolicy(503))->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(503, $answer->getStatusCode());
        self::assertSame('2', $answer->getHeaders()['Retry-After']);

        $response = $this->handle(new TransientStorageFailure(2), InstanceSettingsRoutes::REVEAL, policy: new ConflictResponsePolicy(503))->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"error":"unavailable","code":"CONFLICT_001"}', $response->getContent());
    }

    public function testTheJsonRoutesAnswerJsonWithTheCode(): void
    {
        $event    = $this->handle(new TransientStorageFailure(2), InstanceSettingsRoutes::REVEAL);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('2', $response->headers->get('Retry-After'));
        self::assertSame(ConflictCode::RETRY_SAFE, $response->headers->get(StorageFailureListener::CODE_HEADER));
        self::assertSame('{"error":"conflict","code":"CONFLICT_001"}', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $response = $this->handle(new SettingsConflictException('general', ['site_name']), InstanceSettingsRoutes::TEST)->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertNull($response->headers->get('Retry-After'));
        self::assertSame('{"error":"conflict","code":"SETTINGS_CHANGED"}', $response->getContent());
    }

    public function testAClientAskingForJsonGetsJson(): void
    {
        $request = $this->request();
        $request->headers->set('Accept', 'application/json');
        $response = $this->handle(new TransientStorageFailure(1), null, $request)->getResponse();

        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
    }

    public function testAConflictAfterACommittedUnitOfTheRequestIsCommittedWithoutRetryAfter(): void
    {
        $ledger   = new CommitLedger();
        $listener = new StorageFailureListener(null, $ledger);
        $request  = $this->request();
        $listener->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(0, $request->attributes->get(StorageFailureListener::COMMITS_ATTRIBUTE));

        // Nothing committed yet: CONFLICT_001.
        $answer = $this->handle(new TransientStorageFailure(3), null, $request, listener: $listener)->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(ConflictCode::RETRY_SAFE, $answer->getHeaders()[StorageFailureListener::CODE_HEADER]);

        $ledger->record();
        $answer = $this->handle(new TransientStorageFailure(3), null, $request, listener: $listener)->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame([StorageFailureListener::CODE_HEADER => ConflictCode::COMMITTED], $answer->getHeaders());
        self::assertSame(StorageFailureListener::COMMITTED_MESSAGE, $answer->getMessage());

        $request->attributes->set('_route', InstanceSettingsRoutes::REVEAL);
        $response = $this->handle(new TransientStorageFailure(3), null, $request, listener: $listener)->getResponse();
        self::assertNotNull($response);
        self::assertNull($response->headers->get('Retry-After'));
        self::assertSame('{"error":"conflict","code":"CONFLICT_002"}', $response->getContent());
    }

    public function testACommitOfTheHostEarlierInTheRequestIsCommittedWithoutRetryAfter(): void
    {
        // Request-level detection: the mark is the host's (transaction-doctrine's watermark), the bundle's ledger saw nothing.
        $hostLedger = new CommitLedger();
        $request    = $this->request();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($hostLedger));
        $listener = new StorageFailureListener(null, new CommitLedger());

        $answer = $this->handle(new TransientStorageFailure(3), null, $request, listener: $listener)->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame([StorageFailureListener::CODE_HEADER => ConflictCode::RETRY_SAFE, 'Retry-After' => '3'], $answer->getHeaders());

        $hostLedger->record();
        $answer = $this->handle(new TransientStorageFailure(3), null, $request, listener: $listener)->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame([StorageFailureListener::CODE_HEADER => ConflictCode::COMMITTED], $answer->getHeaders());
        self::assertSame(StorageFailureListener::COMMITTED_MESSAGE, $answer->getMessage());

        $request->attributes->set('_route', InstanceSettingsRoutes::REVEAL);
        $response = $this->handle(new TransientStorageFailure(3), null, $request, listener: $listener)->getResponse();
        self::assertNotNull($response);
        self::assertNull($response->headers->get('Retry-After'));
        self::assertSame('{"error":"conflict","code":"CONFLICT_002"}', $response->getContent());
    }

    public function testACommitOfTheHostIsSeenWithoutTheBundlesLedger(): void
    {
        $hostLedger = new CommitLedger();
        $request    = $this->request();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($hostLedger));
        $hostLedger->record();

        $answer = $this->handle(new TransientStorageFailure(3), null, $request)->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame([StorageFailureListener::CODE_HEADER => ConflictCode::COMMITTED], $answer->getHeaders());
    }

    public function testARequestMarkWithNoCommitKeepsCode001(): void
    {
        $request = $this->request();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark(new CommitLedger()));

        $answer = $this->handle(new TransientStorageFailure(2), null, $request)->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame([StorageFailureListener::CODE_HEADER => ConflictCode::RETRY_SAFE, 'Retry-After' => '2'], $answer->getHeaders());
    }

    public function testWithoutTheRequestCountNothingCountsAsCommitted(): void
    {
        $ledger = new CommitLedger();
        $ledger->record();
        $answer = $this->handle(new TransientStorageFailure(3), listener: new StorageFailureListener(null, $ledger))->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(ConflictCode::RETRY_SAFE, $answer->getHeaders()[StorageFailureListener::CODE_HEADER]);

        $request = $this->request();
        (new StorageFailureListener())->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($request->attributes->has(StorageFailureListener::COMMITS_ATTRIBUTE));
    }

    public function testAWriteUnitThatCommittedIsCommitted(): void
    {
        $conflict = new ConcurrencyConflict(2, TransientFailureKind::Deadlock, 1, new RuntimeException('deadlock'), committed: true);
        $answer   = $this->handle($conflict)->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame([StorageFailureListener::CODE_HEADER => ConflictCode::COMMITTED], $answer->getHeaders());
    }

    public function testALostSavepointWithNoDeadlockIsCommitted(): void
    {
        $answer = $this->handle(new LogicException('wrapper', 0, $this->driverException(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist')))->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame([StorageFailureListener::CODE_HEADER => ConflictCode::COMMITTED], $answer->getHeaders());
    }

    public function testASettingsConflictOfAPageIsADomain409(): void
    {
        $answer = $this->handle(new SettingsConflictException('general', ['site_name']))->getThrowable();
        self::assertInstanceOf(ConflictHttpException::class, $answer);
        self::assertStringNotContainsString('site_name', $answer->getMessage());
        self::assertSame([StorageFailureListener::CODE_HEADER => StorageFailureListener::SETTINGS_CHANGED], $answer->getHeaders());
    }

    public function testAUniqueViolationOrALostConnectionIsNeverMapped(): void
    {
        $unique = new UniqueConstraintViolationException($this->driverException(), null);
        self::assertSame($unique, $this->handle($unique)->getThrowable());
        self::assertNull($this->handle($unique, InstanceSettingsRoutes::TEST)->getResponse());

        $lost = new ConnectionLost($this->driverException(2006, 'HY000'), null);
        self::assertSame($lost, $this->handle($lost)->getThrowable());
    }

    public function testAnyOtherFailureAndAnyOtherRouteAreLeftAlone(): void
    {
        $failure = new RuntimeException('a bug');
        self::assertSame($failure, $this->handle($failure)->getThrowable());

        $transient = new TransientStorageFailure(1);
        $host      = Request::create('/host');
        $host->attributes->set('_controller', 'App\\Controller\\HostController::index');
        self::assertSame($transient, $this->handle($transient, null, $host)->getThrowable());

        $closure = Request::create('/host');
        $closure->attributes->set('_controller', static fn (): null => null);
        self::assertSame($transient, $this->handle($transient, null, $closure)->getThrowable());
    }

    private function handle(Throwable $failure, ?string $route = null, ?Request $request = null, ?ConflictResponsePolicy $policy = null, ?StorageFailureListener $listener = null): ExceptionEvent
    {
        $request ??= $this->request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        ($listener ?? new StorageFailureListener($policy))->onException($event);

        return $event;
    }

    private function request(): Request
    {
        $request = Request::create('/admin/settings/sections/general', 'POST');
        $request->attributes->set('_controller', self::CONTROLLER);

        return $request;
    }

    private function driverException(int $code = 0, ?string $sqlState = null, string $message = 'driver'): DriverException
    {
        return new class($message, $code, $sqlState) extends Exception implements DriverException {
            public function __construct(string $message, int $code, private readonly ?string $sqlState)
            {
                parent::__construct($message, $code);
            }

            public function getSQLState(): ?string
            {
                return $this->sqlState;
            }
        };
    }
}
