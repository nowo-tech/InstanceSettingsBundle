<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Embed;

use LogicException;
use Nowo\InstanceSettingsBundle\Embed\SectionEmbedOptions;
use Nowo\InstanceSettingsBundle\Embed\SettingsSectionEmbedder;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\PageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * IS-1 (1.6): the sections a host may list in its own menu are the allowlisted ones the user may open, and none when
 * the UI gate denies; the UI gate on every path, host routes checked up front, local redirects only, no GET fragment of
 * a POST (audit JE).
 */
final class SettingsSectionEmbedderTest extends TestCase
{
    public function testAccessibleSectionsNarrowAndRespectTheUiGate(): void
    {
        $options = SectionEmbedOptions::routes(['general' => 'host_general', 'mailer' => 'host_mailer', 'unknown' => 'host_unknown']);

        $all = $this->embedder(new AllowAllInstanceSettingsAccessChecker());
        self::assertSame(['mailer', 'general'], array_keys($all->accessibleSections()));
        self::assertSame(['mailer', 'general'], array_keys($all->accessibleSections($options)), 'registry order, unknown names ignored');
        self::assertSame(['general'], array_keys($all->accessibleSections(SectionEmbedOptions::routes(['general' => 'host_general']))));

        $noUi = $this->embedder($this->areaOnly());
        self::assertSame([], $noUi->accessibleSections($options), 'the UI gate denies: nothing to list');
        self::assertSame([], $noUi->accessibleSections(), 'audit JE-L1: without options too');
    }

    public function testTheBundlePageRunsTheUiGateItself(): void
    {
        // Audit JE-L1: a user with an area role but no UI role, calling the bundle page outside the access listener.
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Access denied to the instance settings UI.');
        $this->embedder($this->areaOnly())->handleBundlePage(new Request(), 'general');
    }

    public function testAnUnknownHostRouteFailsForEveryUser(): void
    {
        // Audit JE-L3: checked before the access checks and for every allowlisted section, not only the visible tabs.
        $routes = new RouteCollection();
        $routes->add('host_general', new Route('/host/general'));
        $embedder = $this->embedder($this->areaOnly(), $routes);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The host route "host_mailr" of the embedded section "mailer" cannot be generated');
        $embedder->handle(new Request(), 'general', SectionEmbedOptions::routes(['general' => 'host_general', 'mailer' => 'host_mailr']));
    }

    public function testAMissingRouteParameterIsALogicError(): void
    {
        $routes = new RouteCollection();
        $routes->add('host_tenant', new Route('/host/{tenant}/{section}'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The host route "host_tenant" of the embedded section "general" cannot be generated');
        $this->embedder(new AllowAllInstanceSettingsAccessChecker(), $routes)->handle(new Request(), 'general', SectionEmbedOptions::route('host_tenant', ['general']));
    }

    /**
     * @return iterable<string, array{0: Route}>
     */
    public static function offHost(): iterable
    {
        yield 'host placeholder' => [new Route('/x', host: '{tenant}.example.test')];
        yield 'other host' => [new Route('/x', host: 'evil.example')];
        yield 'other scheme' => [new Route('/x', schemes: ['https'])];
    }

    #[DataProvider('offHost')]
    public function testTheRedirectMustStayOnTheCurrentHost(Route $route): void
    {
        // Audit JE-I1: a host route that generates a network path or an absolute URL is refused.
        $routes = new RouteCollection();
        $routes->add('host_off', $route);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must generate a local path');
        $this->embedder(new AllowAllInstanceSettingsAccessChecker(), $routes)->handle(new Request(), 'general', SectionEmbedOptions::routes(['general' => 'host_off'], ['tenant' => 'evil']));
    }

    public function testAGetFragmentOfAPostIsRefused(): void
    {
        // Audit JE-L2: Twig render(controller()) runs a GET sub-request, so a save on the page would be dropped.
        $stack = new RequestStack();
        $stack->push(Request::create('/host/page', 'POST'));
        $fragment = Request::create('/_fragment');
        $stack->push($fragment);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('runs in a GET sub-request of a POST');
        $this->embedder(new AllowAllInstanceSettingsAccessChecker(), null, $stack)->handle($fragment, 'general', SectionEmbedOptions::routes(['general' => 'host_general']));
    }

    private function areaOnly(): InstanceSettingsAccessCheckerInterface
    {
        return new class implements InstanceSettingsAccessCheckerInterface {
            public function canAccess(?object $user, ?string $area = null): bool
            {
                return $area !== null;
            }
        };
    }

    private function embedder(InstanceSettingsAccessCheckerInterface $checker, ?RouteCollection $routes = null, ?RequestStack $stack = null): SettingsSectionEmbedder
    {
        $registry = PageFixtures::registry();

        return new SettingsSectionEmbedder(
            $registry,
            new InstanceSettings(new InMemorySettingValueRepository(), $registry),
            new AccessGuard($checker),
            PageFixtures::factory(),
            Forms::createFormFactory(),
            new Environment(new ArrayLoader()),
            new UrlGenerator($routes ?? new RouteCollection(), new RequestContext()),
            $stack,
        );
    }
}
