<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Security;

use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\ConfigurableInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function in_array;

final class AccessTest extends TestCase
{
    public function testConfigurableChecker(): void
    {
        $authorization = new class implements AuthorizationCheckerInterface {
            /** @var list<string> */
            public array $granted = ['ROLE_ADMIN'];

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return in_array($attribute, $this->granted, true);
            }
        };
        $checker = new ConfigurableInstanceSettingsAccessChecker($authorization, ['ROLE_ADMIN'], ['mailer' => ['ROLE_SUPER_ADMIN'], 'locked' => []]);
        $user    = new stdClass();

        self::assertTrue($checker->canAccess($user));
        self::assertTrue($checker->canAccess($user, 'general'));
        self::assertFalse($checker->canAccess($user, 'mailer'));
        self::assertFalse($checker->canAccess($user, 'locked'), 'empty list denies');
        self::assertFalse($checker->canAccess(null), 'anonymous denied');

        $authorization->granted = ['ROLE_SUPER_ADMIN'];
        self::assertTrue($checker->canAccess($user, 'mailer'));
        self::assertFalse($checker->canAccess($user));

        self::assertFalse((new ConfigurableInstanceSettingsAccessChecker($authorization, []))->canAccess($user));
        self::assertTrue((new AllowAllInstanceSettingsAccessChecker())->canAccess(null, 'x'));
    }

    public function testGuardTrustsTheTokenOnlyWithAFirewallContext(): void
    {
        $checker = new class implements InstanceSettingsAccessCheckerInterface {
            /** @var list<array{?object, ?string}> */
            public array $calls = [];

            public function canAccess(?object $user, ?string $area = null): bool
            {
                $this->calls[] = [$user, $area];

                return $user !== null;
            }
        };
        $tokens = new TokenStorage();
        $user   = new InMemoryUser('admin', null, ['ROLE_ADMIN']);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_ADMIN']));
        $stack = new RequestStack();
        $guard = new AccessGuard($checker, false, $tokens, $stack);

        self::assertNull($guard->user(), 'no request');
        $stack->push(new Request());
        self::assertFalse($guard->isGranted(), 'no firewall context: stale token ignored');

        $stack->pop();
        $request = new Request();
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');
        $stack->push($request);
        self::assertTrue($guard->isGranted('mailer'));
        self::assertSame([$user, 'mailer'], $checker->calls[1]);

        $tokens->setToken(null);
        self::assertNull($guard->user());

        self::assertTrue((new AccessGuard($checker, true))->isGranted());
        self::assertNull((new AccessGuard($checker))->user());
    }
}
