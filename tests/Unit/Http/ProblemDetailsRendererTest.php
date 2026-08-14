<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Http;

use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Http\ProblemDetailsRenderer;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ADR-0004 requires every error response to be RFC 7807. Until 2026-08-14 only
 * `AbeonException` was rendered that way, so a validation failure came back in
 * Laravel's own shape — or, when the caller omitted `Accept: application/json`, as a
 * **302 to `/`** from a service that has no `web` group and no page to redirect to.
 */
final class ProblemDetailsRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Facades cache the instances they resolve, and another test file in the same
        // process may have left one bound to a container that is gone. Clearing first
        // is what makes this suite order-independent.
        Facade::clearResolvedInstances();

        // `response()` needs a ResponseFactory in the container. `json()` touches
        // neither collaborator, so stubs suffice.
        $container = new Container();
        $container->bind(ResponseFactoryContract::class, fn () => new ResponseFactory(
            $this->createMock(ViewFactory::class),
            new Redirector($this->createMock(UrlGenerator::class)),
        ));

        // `ValidationException` summarises its own message through `trans_choice()`,
        // so the exception cannot even be constructed without a translator.
        $container->instance('translator', new Translator(new ArrayLoader(), 'en'));

        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_an_abeon_exception_keeps_its_own_problem_details(): void
    {
        $response = (new ProblemDetailsRenderer())
            ->render(AuthException::forbidden('Insufficient permissions'), '/api/v1/auth/store');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $body = $this->body($response);
        $this->assertSame('https://api.abeon.pl/errors/forbidden', $body['type']);
        $this->assertSame('/api/v1/auth/store', $body['instance']);
    }

    public function test_a_validation_failure_matches_the_shared_golden_fixture(): void
    {
        // `tests/fixtures/contract/problem-details.json` is shared byte-for-byte with
        // `@abeon/shared`. It described a validation error that no service had ever
        // emitted; this asserts the shape is now actually produced.
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/../../fixtures/contract/problem-details.json'),
            true,
        );

        $response = (new ProblemDetailsRenderer())->renderValidation(
            $this->validationException(['email' => ['The email field is required.', 'The email must be valid.']]),
            '/api/v1/contacts',
        );

        $body = $this->body($response);

        $this->assertSame(array_keys($fixture), array_keys($body));
        $this->assertSame($fixture['type'], $body['type']);
        $this->assertSame($fixture['title'], $body['title']);
        $this->assertSame($fixture['status'], $body['status']);
        $this->assertSame($fixture['instance'], $body['instance']);
        $this->assertSame($fixture['errors'], $body['errors']);
    }

    public function test_the_detail_carries_the_first_message(): void
    {
        // A client that does not care about individual fields still needs something to
        // show, so `detail` is not left to repeat the title.
        $response = (new ProblemDetailsRenderer())->renderValidation(
            $this->validationException(['password' => ['The password field is required.']]),
        );

        $this->assertSame('The password field is required.', $this->body($response)['detail']);
    }

    public function test_every_field_survives_not_only_the_first(): void
    {
        $response = (new ProblemDetailsRenderer())->renderValidation($this->validationException([
            'email'    => ['The email field is required.'],
            'password' => ['The password field is required.'],
        ]));

        $this->assertSame(['email', 'password'], array_keys($this->body($response)['errors']));
    }

    public function test_a_not_found_becomes_a_problem_document(): void
    {
        // `firstOrFail()` and route model binding raise these, and they answered
        // Laravel's `{"message": "..."}` shape — not the platform's error contract.
        $response = (new ProblemDetailsRenderer())
            ->renderStatus(404, 'Notification not found', '/api/v1/notifications/abc');

        $body = $this->body($response);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('https://api.abeon.pl/errors/not-found', $body['type']);
        $this->assertSame('Not Found', $body['title']);
        $this->assertSame('Notification not found', $body['detail']);
    }

    public function test_a_framework_exception_without_a_message_omits_detail(): void
    {
        // `NotFoundHttpException` is usually raised with an empty message, and a
        // `detail` that merely repeats the title tells the caller nothing.
        $exception = new NotFoundHttpException();

        $body = $this->body(
            (new ProblemDetailsRenderer())->renderStatus($exception->getStatusCode(), $exception->getMessage()),
        );

        $this->assertArrayNotHasKey('detail', $body);
        $this->assertSame('Not Found', $body['title']);
    }

    public function test_status_slugs_are_stable_identifiers(): void
    {
        $renderer = new ProblemDetailsRenderer();

        $this->assertSame(
            'https://api.abeon.pl/errors/method-not-allowed',
            $this->body($renderer->renderStatus((new MethodNotAllowedHttpException(['GET']))->getStatusCode()))['type'],
        );
        $this->assertSame(
            'https://api.abeon.pl/errors/unauthorized',
            $this->body($renderer->renderStatus(401))['type'],
        );
    }

    public function test_an_unexpected_throwable_hides_its_message_unless_debugging(): void
    {
        $quiet = $this->body((new ProblemDetailsRenderer())->render(new RuntimeException('SQLSTATE[HY000] near "users"')));
        $loud  = $this->body((new ProblemDetailsRenderer(debug: true))->render(new RuntimeException('SQLSTATE[HY000] near "users"')));

        $this->assertSame(500, $quiet['status']);
        $this->assertArrayNotHasKey('detail', $quiet, 'A database error is not for the caller to read.');
        $this->assertSame('SQLSTATE[HY000] near "users"', $loud['detail']);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationException(array $errors): ValidationException
    {
        // `ValidationException` summarises its own message from the validator's *own*
        // translator — not from the facade — and only reaches for it when there is more
        // than one message, which is why a mock returning null passes the single-error
        // cases and fails the interesting ones.
        $validator = $this->createMock(Validator::class);
        $validator->method('errors')->willReturn(new \Illuminate\Support\MessageBag($errors));
        $validator->method('getTranslator')->willReturn(new Translator(new ArrayLoader(), 'en'));

        return new ValidationException($validator);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(\Illuminate\Http\JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true);

        return $decoded;
    }
}
