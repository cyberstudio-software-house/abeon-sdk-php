<?php

declare(strict_types=1);

namespace Abeon\SDK\Http;

use Abeon\SDK\DTO\ProblemDetails;
use Abeon\SDK\Exceptions\AbeonException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProblemDetailsRenderer
{
    /** ADR-0004: `type` is a stable URI identifying the error class, not a URL to follow. */
    public const TYPE_PREFIX = 'https://api.abeon.pl/errors/';

    public function __construct(private readonly bool $debug = false)
    {
    }

    /**
     * Make an API-only service answer every error as RFC 7807, which ADR-0004 requires
     * of all of them and which none of them did.
     *
     * **Opt-in, and it must stay that way.** Registering this globally in
     * `AbeonServiceProvider` would break the two applications that have a frontend:
     * Inertia's form handling in `abeon-boilerplate-inertia` depends on a validation
     * failure coming back as a redirect with the errors in the session, and
     * `abeon-auth-ui` posts Blade forms. A redirect is the right answer there and the
     * wrong answer in a service that has no `web` group, no session and no page to
     * redirect to — `POST /api/v1/auth/login` with a malformed body answered **302 to
     * `/`** until this existed.
     *
     * Gating on `$request->expectsJson()` would fix nothing: the redirect happens
     * exactly when the caller omits `Accept: application/json`.
     *
     * Order matters twice over. Within this method, `renderViaCallbacks()` returns the
     * first callback whose first parameter type matches, so these run most specific
     * first. And **this method must be called last** in `withExceptions()`: Laravel
     * appends callbacks in registration order, so anything an application registers
     * after the catch-all below would never be reached in production.
     */
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(fn (AbeonException $e, $request): JsonResponse => app(self::class)
            ->render($e, self::instanceOf($request)));

        $exceptions->render(fn (ValidationException $e, $request): JsonResponse => app(self::class)
            ->renderValidation($e, self::instanceOf($request)));

        // Laravel's default redirects to a route named `login`, which an API-only
        // service does not have — the failure would surface as a routing error rather
        // than as "you are not authenticated".
        $exceptions->render(fn (AuthenticationException $e, $request): JsonResponse => app(self::class)
            ->renderStatus(401, $e->getMessage(), self::instanceOf($request)));

        // Covers `NotFoundHttpException` from `firstOrFail()` and from route model
        // binding, 405 from a wrong method, and anything else the framework raises as
        // an HTTP error — all of which answer Laravel's `{"message": "..."}` shape
        // otherwise, which is not the platform's error contract.
        $exceptions->render(fn (HttpExceptionInterface $e, $request): JsonResponse => app(self::class)
            ->renderStatus($e->getStatusCode(), self::safeDetail($e), self::instanceOf($request)));

        $exceptions->render(function (Throwable $e, $request): ?JsonResponse {
            // `HttpResponseException` carries a response somebody already built —
            // `abort($response)`, `throwResponse()`, Precognition. It is a plain
            // `RuntimeException`, so none of the callbacks above match it, and this one
            // runs *before* the branch in `Handler::render()` that would have returned
            // that prepared response. Swallowing it turned a deliberate answer into a
            // blank 500 — and only in production, because in debug this returns null and
            // the chain carries on, so local development never saw it.
            if ($e instanceof HttpResponseException) {
                return null;
            }

            // In debug mode this returns null so the callback chain falls through and
            // local development keeps Ignition. In production every error is a problem
            // document, including the ones nobody anticipated.
            if (config('app.debug')) {
                return null;
            }

            return app(self::class)->render($e, self::instanceOf($request));
        });
    }

    /**
     * The message on an HTTP exception, but only when a person wrote it.
     *
     * `prepareException()` maps `ModelNotFoundException` to `NotFoundHttpException`
     * carrying its message, which reads `No query results for model [App\Models\Organisation] 42`.
     * That names an internal class and confirms whether somebody else's record exists,
     * and it went out in `detail` on every 404 in production — while `render()` was
     * carefully hiding exception messages two methods away. The asymmetry was an
     * oversight, not a decision.
     *
     * A message written at a call site (`new NotFoundHttpException('Notification not
     * found')`) has no previous exception and is meant for the caller, so it survives.
     */
    private static function safeDetail(HttpExceptionInterface&Throwable $e): string
    {
        if ($e->getPrevious() instanceof RecordsNotFoundException) {
            return '';
        }

        return $e->getMessage();
    }

    public function render(Throwable $exception, ?string $instance = null): JsonResponse
    {
        $problem = $exception instanceof AbeonException
            ? $exception->problem
            : new ProblemDetails(
                type:   'about:blank',
                title:  'Internal Server Error',
                status: 500,
                detail: $this->debug ? $exception->getMessage() : null,
            );

        return $this->respond($problem, $instance);
    }

    /**
     * The shape ADR-0004 specifies and `schemas/fixtures/problem-details.json`
     * pins: field errors ride as the `errors` extension member, and `detail` carries
     * the first message so a client with no interest in fields still has something to
     * show.
     */
    public function renderValidation(ValidationException $exception, ?string $instance = null): JsonResponse
    {
        $errors = $exception->errors();
        $first  = null;

        foreach ($errors as $messages) {
            $first = is_array($messages) ? ($messages[0] ?? null) : $messages;

            break;
        }

        return $this->respond(new ProblemDetails(
            type:       self::TYPE_PREFIX.'validation',
            title:      'Validation Error',
            status:     422,
            detail:     is_string($first) ? $first : 'The given data was invalid.',
            extensions: ['errors' => $errors],
        ), $instance);
    }

    public function renderStatus(int $status, string $detail = '', ?string $instance = null): JsonResponse
    {
        $title = SymfonyResponse::$statusTexts[$status] ?? 'Error';

        return $this->respond(new ProblemDetails(
            type:   self::TYPE_PREFIX.$this->slug($title),
            title:  $title,
            status: $status,
            // Framework HTTP exceptions frequently carry an empty message; a `detail`
            // that merely repeats the title tells the caller nothing.
            detail: $detail !== '' && $detail !== $title ? $detail : null,
        ), $instance);
    }

    private function respond(ProblemDetails $problem, ?string $instance): JsonResponse
    {
        // Rebuilt rather than patched into the serialised array afterwards: `toArray()`
        // emits the RFC's own member order and appends extensions last, so injecting
        // `instance` after the fact left it *behind* `errors`. Nothing parses by
        // position, but the golden fixture is read by people too.
        if ($instance !== null && $problem->instance === null) {
            $problem = new ProblemDetails(
                type:       $problem->type,
                title:      $problem->title,
                status:     $problem->status,
                detail:     $problem->detail,
                instance:   $instance,
                extensions: $problem->extensions,
            );
        }

        return response()->json($problem->toArray(), $problem->status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }

    /**
     * RFC 7807 types `instance` as a URI reference, and the golden fixture writes it
     * with a leading slash. `$request->path()` returns `api/v1/auth/login` — a relative
     * reference that resolves against whatever base the reader assumes.
     */
    private static function instanceOf(\Illuminate\Http\Request $request): string
    {
        return '/'.ltrim($request->path(), '/');
    }

    private function slug(string $title): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? '', '-');
    }
}
