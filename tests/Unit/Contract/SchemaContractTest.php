<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Contract;

use Abeon\SDK\DTO\AppDescriptor;
use Abeon\SDK\DTO\ProblemDetails;
use Abeon\SDK\DTO\SearchResult;
use Abeon\SDK\DTO\Tenant;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Events\Event;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Proves the PHP DTOs serialize to exactly the shape declared in `schemas/`,
 * and that the golden fixtures (byte-identical to `@abeon/shared`'s) validate
 * against those same schemas. This is the PHP half of the cross-language
 * contract: schemas are the single source of truth, both packages conform.
 */
final class SchemaContractTest extends TestCase
{
    private const SCHEMA_ROOT  = __DIR__.'/../../../schemas';
    private const FIXTURE_ROOT = __DIR__.'/../../fixtures/contract';
    private const SCHEMA_NS    = 'https://schemas.abeon.pl/';

    private Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $root = realpath(self::SCHEMA_ROOT);
        $this->assertIsString($root, 'schemas/ directory must exist');

        $this->validator = new Validator();
        $this->validator->resolver()?->registerPrefix(self::SCHEMA_NS, $root);
    }

    // --- golden fixtures are valid against their schemas (parity with @abeon/shared) ---

    public function test_user_fixture_matches_schema(): void
    {
        $this->assertValid('dto/user.json', $this->fixture('user.json'));
    }

    public function test_app_descriptor_fixture_matches_schema(): void
    {
        $this->assertValid('dto/app-descriptor.json', $this->fixture('app-descriptor.json'));
    }

    public function test_problem_details_fixture_matches_schema(): void
    {
        $this->assertValid('http/problem-details.json', $this->fixture('problem-details.json'));
    }

    public function test_search_result_fixture_matches_schema(): void
    {
        $this->assertValid('dto/search-result.json', $this->fixture('search-result.json'));
    }

    public function test_envelope_fixture_matches_schema(): void
    {
        $this->assertValid('events/_envelope.json', $this->fixture('envelope.json'));
    }

    public function test_tenant_fixture_matches_schema(): void
    {
        $this->assertValid('dto/tenant.json', $this->fixture('tenant.json'));
    }

    public function test_tenant_dto_conforms_and_roundtrips(): void
    {
        $fixture = $this->fixtureArray('tenant.json');
        $out     = Tenant::fromArray($fixture)->toArray();

        $this->assertValid('dto/tenant.json', $out);
        $this->assertEquals($fixture, $out);
    }

    public function test_tenant_carries_no_permissions(): void
    {
        // The switcher list must not be a source of authorisation — a client that
        // could read its own roles from it would be deriving authorisation from a
        // response it can influence. Roles arrive in the re-issued JWT (ADR-0017).
        $bad = $this->fixtureArray('tenant.json');
        $bad['permissions'] = ['crm.contacts.read'];

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/tenant.json',
        );

        $this->assertFalse($result->isValid());
    }

    // --- DTO serialization conforms to the schema and round-trips the fixture ---

    public function test_user_dto_conforms_and_roundtrips(): void
    {
        $fixture = $this->fixtureArray('user.json');
        $out     = User::fromArray($fixture)->toArray();

        $this->assertValid('dto/user.json', $out);
        $this->assertEquals($fixture, $out);
    }

    public function test_app_descriptor_dto_conforms_and_roundtrips(): void
    {
        $fixture = $this->fixtureArray('app-descriptor.json');
        $out     = AppDescriptor::fromArray($fixture)->toArray();

        $this->assertValid('dto/app-descriptor.json', $out);
        $this->assertEquals($fixture, $out);
    }

    public function test_search_result_dto_conforms_and_roundtrips(): void
    {
        $fixture = $this->fixtureArray('search-result.json');
        $out     = SearchResult::fromArray($fixture)->toArray();

        $this->assertValid('dto/search-result.json', $out);
        $this->assertEquals($fixture, $out);
    }

    public function test_problem_details_dto_conforms_and_roundtrips(): void
    {
        $fixture = $this->fixtureArray('problem-details.json');
        $out     = ProblemDetails::fromArray($fixture)->toArray();

        $this->assertValid('http/problem-details.json', $out);
        $this->assertEquals($fixture, $out);
    }

    public function test_event_dto_conforms_to_envelope_schema(): void
    {
        $out = Event::fromEnvelope($this->fixtureArray('envelope.json'))->toEnvelope();

        $this->assertValid('events/_envelope.json', $out);
    }

    // --- the schema actually rejects malformed data (guards against a no-op validator) ---

    public function test_schema_rejects_user_missing_required_field(): void
    {
        $bad = $this->fixtureArray('user.json');
        unset($bad['email']);

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/user.json',
        );

        $this->assertFalse($result->isValid());
    }

    public function test_schema_rejects_app_descriptor_invalid_mode(): void
    {
        $bad = $this->fixtureArray('app-descriptor.json');
        $bad['mode'] = 'bogus';

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/app-descriptor.json',
        );

        $this->assertFalse($result->isValid());
    }

    public function test_schema_rejects_search_result_missing_required_field(): void
    {
        $bad = $this->fixtureArray('search-result.json');
        unset($bad['url']);

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/search-result.json',
        );

        $this->assertFalse($result->isValid());
    }

    // --- multi-tenancy: org_id is required, and must stay required (ADR-0016) ---

    public function test_schema_rejects_envelope_without_org_id(): void
    {
        $bad = $this->fixtureArray('envelope.json');
        unset($bad['org_id']);

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'events/_envelope.json',
        );

        $this->assertFalse(
            $result->isValid(),
            'The envelope must carry org_id — it is an event consumer\'s only source of tenant (ADR-0018).',
        );
    }

    public function test_envelope_org_id_may_be_null_for_platform_events(): void
    {
        $platformEvent = $this->fixtureArray('envelope.json');
        $platformEvent['org_id'] = null;

        // Null is "no organisation" (registry self-registration, maintenance) — it is
        // NOT "all organisations". Consumers requiring a tenant must refuse it, but
        // the envelope itself is well-formed.
        $this->assertValid('events/_envelope.json', $platformEvent);
    }

    public function test_schema_rejects_user_dto_without_org_id(): void
    {
        $bad = $this->fixtureArray('user.json');
        unset($bad['org_id']);

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/user.json',
        );

        $this->assertFalse($result->isValid());
    }

    public function test_schema_rejects_user_jwt_without_org_id(): void
    {
        $claims = [
            'sub'         => '42',
            'exp'         => 1893456000,
            'iat'         => 1893452400,
            'iss'         => 'abeon-auth',
            'aud'         => 'abeon',
            'type'        => 'user',
            'email'       => 'jan@example.com',
            'roles'       => ['admin'],
            'permissions' => ['crm.contacts.read'],
            'jti'         => 'jti-1',
        ];

        $withOrg = $claims + ['org_id' => 1];
        $this->assertValid('auth/jwt-user.json', $withOrg);

        $result = $this->validator->validate(
            json_decode((string) json_encode($claims)),
            self::SCHEMA_NS.'auth/jwt-user.json',
        );

        $this->assertFalse(
            $result->isValid(),
            'org_id is required on user tokens — it is the authorization dimension (ADR-0001/ADR-0016).',
        );
    }

    public function test_schema_rejects_null_org_id_on_a_user_jwt(): void
    {
        $claims = [
            'sub'         => '42',
            'exp'         => 1893456000,
            'iat'         => 1893452400,
            'iss'         => 'abeon-auth',
            'aud'         => 'abeon',
            'type'        => 'user',
            'email'       => 'jan@example.com',
            'roles'       => ['admin'],
            'permissions' => ['crm.contacts.read'],
            'org_id'      => null,
            'jti'         => 'jti-1',
        ];

        $result = $this->validator->validate(
            json_decode((string) json_encode($claims)),
            self::SCHEMA_NS.'auth/jwt-user.json',
        );

        $this->assertFalse(
            $result->isValid(),
            'A user token is always scoped to exactly one organisation — null is not a valid scope.',
        );
    }

    // --- helpers ---

    /**
     * @param  array<string, mixed>|object  $data
     */
    private function assertValid(string $schemaRelPath, array|object $data): void
    {
        $payload = json_decode((string) json_encode($data, JSON_THROW_ON_ERROR));
        $result  = $this->validator->validate($payload, self::SCHEMA_NS.$schemaRelPath);

        if (! $result->isValid()) {
            $error = $result->error();
            $detail = $error !== null ? (new ErrorFormatter())->format($error) : [];
            $this->fail("{$schemaRelPath} rejected data: ".json_encode($detail, JSON_PRETTY_PRINT));
        }

        $this->assertTrue($result->isValid());
    }

    private function fixture(string $name): object
    {
        return (object) json_decode($this->read($name), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureArray(string $name): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->read($name), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function read(string $name): string
    {
        return (string) file_get_contents(self::FIXTURE_ROOT.'/'.$name);
    }
}
