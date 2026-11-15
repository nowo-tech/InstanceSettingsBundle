<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use LogicException;
use Nowo\InstanceSettingsBundle\Exception\SettingsConflictException;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Transaction\StorageFailureListener;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * 1.19 (Beacon spec 239): 503 / 409 on the bundle's routes only.
 */
final class StorageFailureListenerTest extends TestCase
{
    private const CONTROLLER = 'Nowo\\InstanceSettingsBundle\\Controller\\SettingsController::section';

    public function testATransientFailureOfAPageIsA503WithRetryAfter(): void
    {
        $event = $this->handle(new TransientStorageFailure(5, previous: new RuntimeException('SQLSTATE[40001] secret-value')));

        $answer = $event->getThrowable();
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $answer);
        self::assertSame(['Retry-After' => 5], $answer->getHeaders());
        self::assertStringNotContainsString('SQLSTATE', $answer->getMessage());
        self::assertNull($event->getResponse());
    }

    public function testADbalLockWaitWrappedByTheHostIsA503OfOneSecond(): void
    {
        $event = $this->handle(new LogicException('host wrapper', 0, new LockWaitTimeoutException($this->driverException(), null)));

        $answer = $event->getThrowable();
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $answer);
        self::assertSame(['Retry-After' => 1], $answer->getHeaders());
    }

    public function testATransientFailureWithoutHintOrZeroIsOneSecond(): void
    {
        $answer = $this->handle(new TransientStorageFailure(0))->getThrowable();
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $answer);
        self::assertSame(['Retry-After' => 1], $answer->getHeaders());

        $answer = $this->handle(new TransientStorageFailure())->getThrowable();
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $answer);
        self::assertSame(['Retry-After' => 1], $answer->getHeaders());
    }

    public function testTheJsonRoutesAnswerJson(): void
    {
        $event    = $this->handle(new TransientStorageFailure(2), InstanceSettingsRoutes::REVEAL);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('2', $response->headers->get('Retry-After'));
        self::assertSame('{"error":"unavailable"}', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $response = $this->handle(new UniqueConstraintViolationException($this->driverException(), null), InstanceSettingsRoutes::TEST)->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('{"error":"conflict"}', $response->getContent());
    }

    public function testAClientAskingForJsonGetsJson(): void
    {
        $request = $this->request();
        $request->headers->set('Accept', 'application/json');
        $response = $this->handle(new TransientStorageFailure(1), null, $request)->getResponse();

        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
    }

    public function testAConflictOfAPageIsA409(): void
    {
        $answer = $this->handle(new SettingsConflictException('general', ['site_name']))->getThrowable();
        self::assertInstanceOf(ConflictHttpException::class, $answer);
        self::assertStringNotContainsString('site_name', $answer->getMessage());

        self::assertInstanceOf(ConflictHttpException::class, $this->handle(new UniqueConstraintViolationException($this->driverException(), null))->getThrowable());
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

    private function handle(Throwable $failure, ?string $route = null, ?Request $request = null): ExceptionEvent
    {
        $request ??= $this->request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        (new StorageFailureListener())->onException($event);

        return $event;
    }

    private function request(): Request
    {
        $request = Request::create('/admin/settings/sections/general', 'POST');
        $request->attributes->set('_controller', self::CONTROLLER);

        return $request;
    }

    private function driverException(): DriverException
    {
        return new class('driver') extends Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        };
    }
}
