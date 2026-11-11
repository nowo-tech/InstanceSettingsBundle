<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\EventListener;

use Nowo\InstanceSettingsBundle\EventListener\WorkerBoundaryResetListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

final class WorkerBoundaryResetListenerTest extends TestCase
{
    public function testBoundaryResetsEveryInitializedService(): void
    {
        $first    = $this->resettable(2);
        $second   = $this->resettable(2);
        $listener = new WorkerBoundaryResetListener([$first, $second]);

        $listener->onBoundary();
        $listener->onKernelRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST));
    }

    public function testSubRequestsAreNotABoundary(): void
    {
        $listener = new WorkerBoundaryResetListener([$this->resettable(0)]);

        $listener->onKernelRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::SUB_REQUEST));
    }

    public function testNothingInitializedIsANoOp(): void
    {
        (new WorkerBoundaryResetListener([]))->onBoundary();
        $this->addToAssertionCount(1);
    }

    private function resettable(int $times): ResetInterface
    {
        $mock = $this->createMock(ResetInterface::class);
        $mock->expects(self::exactly($times))->method('reset');

        return $mock;
    }
}
