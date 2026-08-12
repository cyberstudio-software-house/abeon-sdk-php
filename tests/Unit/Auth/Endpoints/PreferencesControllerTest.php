<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth\Endpoints;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\Endpoints\PreferencesController;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use PHPUnit\Framework\TestCase;

/**
 * Preferences are per user **per organisation** (ADR-0009 as amended by
 * ADR-0016). The failure this guards against is two organisations sharing one
 * row: the same person's pinned CRM links leaking into an organisation that has
 * no CRM, and each switch silently overwriting the other's layout.
 */
final class PreferencesControllerTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        // ApiResponse uses the `response()` helper, which resolves a ResponseFactory
        // from the container. A real app always has one; a bare unit test does not.
        // json() touches neither collaborator, so stubs are enough.
        $container = Container::getInstance();
        $container->bind(ResponseFactoryContract::class, fn () => new ResponseFactory(
            $this->createMock(ViewFactory::class),
            $this->createMock(Redirector::class),
        ));

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $this->capsule->getConnection()->getSchemaBuilder()->create(
            PreferencesController::TABLE,
            function ($table): void {
                $table->increments('id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('org_id');
                $table->text('preferences');
                $table->timestamps();
                $table->unique(['user_id', 'org_id']);
            },
        );
    }

    private function controller(?int $orgId, string $userId = '42'): PreferencesController
    {
        $auth = new AuthContext();
        $auth->set(new User(
            id: $userId, email: 'a@b.c', name: null,
            roles: [], permissions: [], orgId: $orgId,
        ));

        return new PreferencesController($auth, $this->capsule->getConnection());
    }

    private function patch(PreferencesController $controller, array $body): array
    {
        $request = Request::create('/api/v1/auth/me/preferences', 'PATCH', $body);

        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode((string) $controller->update($request)->getContent(), true);

        return $decoded['data'];
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_defaults_are_returned_when_nothing_is_stored(): void
    {
        $prefs = $this->controller(1)->read(42, 1);

        $this->assertSame(1, $prefs['version']);
        $this->assertSame('system', $prefs['chrome']['theme']);
    }

    public function test_preferences_are_isolated_between_organisations(): void
    {
        $this->patch($this->controller(1), ['chrome' => ['theme' => 'dark']]);

        // Same user, different organisation — must not see organisation 1's theme.
        $inOrgTwo = $this->controller(2)->read(42, 2);

        $this->assertSame('system', $inOrgTwo['chrome']['theme']);
        $this->assertSame('dark', $this->controller(1)->read(42, 1)['chrome']['theme']);
    }

    public function test_writing_in_one_organisation_does_not_clobber_the_other(): void
    {
        $this->patch($this->controller(1), ['chrome' => ['theme' => 'dark', 'appOrder' => ['crm']]]);
        $this->patch($this->controller(2), ['chrome' => ['theme' => 'light', 'appOrder' => ['cms']]]);

        $one = $this->controller(1)->read(42, 1);
        $two = $this->controller(2)->read(42, 2);

        $this->assertSame('dark', $one['chrome']['theme']);
        $this->assertSame(['crm'], $one['chrome']['appOrder']);
        $this->assertSame('light', $two['chrome']['theme']);
        $this->assertSame(['cms'], $two['chrome']['appOrder']);
    }

    public function test_different_users_in_the_same_organisation_stay_separate(): void
    {
        $this->patch($this->controller(1, '42'), ['chrome' => ['theme' => 'dark']]);
        $this->patch($this->controller(1, '43'), ['chrome' => ['theme' => 'light']]);

        $this->assertSame('dark', $this->controller(1)->read(42, 1)['chrome']['theme']);
        $this->assertSame('light', $this->controller(1)->read(43, 1)['chrome']['theme']);
    }

    public function test_reading_without_an_organisation_is_refused(): void
    {
        // Not "fall back to a shared row" — a missing tenant is an error (ADR-0018).
        $this->expectException(AuthException::class);
        $this->controller(null)->show();
    }

    public function test_writing_without_an_organisation_is_refused(): void
    {
        $this->expectException(AuthException::class);
        $this->patch($this->controller(null), ['chrome' => ['theme' => 'dark']]);
    }

    public function test_patch_merges_rather_than_replaces(): void
    {
        $controller = $this->controller(1);

        $this->patch($controller, ['chrome' => ['theme' => 'dark']]);
        $merged = $this->patch($controller, ['custom' => ['x' => 1]]);

        $this->assertSame('dark', $merged['chrome']['theme']);
        $this->assertSame(['x' => 1], $merged['custom']);
    }
}
