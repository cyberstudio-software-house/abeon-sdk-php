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
 * Proves the PHP DTOs serialize to exactly the shape declared in `schemas/`, and that
 * the golden fixtures validate against those same schemas. This is the PHP half of the
 * cross-language contract: schemas are the single source of truth, both packages
 * conform.
 *
 * **The fixtures are byte-identical to `@abeon/sdk-ts`'s because they are the same
 * files.** Until 2026-08-15 that was a claim in this docblock held up by nothing:
 * the fixtures lived in `tests/fixtures/contract/`, `sync-schemas.ts` only copies
 * `schemas/`, and its orphan detection explicitly skipped `fixtures/` — so the two
 * copies could drift with `sync-schemas:check` still reporting `orphaned: 0`. They had
 * not drifted, but the sets were not even equal: six here against eleven there, five
 * validated on one side only.
 *
 * They now live under `schemas/fixtures/`, inside the tree the sync copies and checks.
 * The claim is a mechanism.
 */
final class SchemaContractTest extends TestCase
{
    private const SCHEMA_ROOT  = __DIR__.'/../../../schemas';
    private const FIXTURE_ROOT = __DIR__.'/../../../schemas/fixtures';
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

    // --- golden fixtures are valid against their schemas (parity with @abeon/sdk-ts) ---

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

    public function test_pagination_fixture_matches_schema(): void
    {
        // These five were validated only by `@abeon/sdk-ts` — the PHP side had no copy
        // to check, which is precisely how a fixture drifts without anything going red.
        $this->assertValid('dto/pagination.json', $this->fixture('pagination.json'));
    }

    public function test_permission_fixture_matches_schema(): void
    {
        $this->assertValid('dto/permission.json', $this->fixture('permission.json'));
    }

    public function test_rest_envelope_fixture_matches_schema(): void
    {
        $this->assertValid('http/envelope.json', $this->fixture('envelope-rest.json'));
    }

    public function test_decoded_user_token_fixture_matches_schema(): void
    {
        $this->assertValid('auth/jwt-user.json', $this->fixture('jwt-user-decoded.json'));
    }

    public function test_decoded_service_token_fixture_matches_schema(): void
    {
        $this->assertValid('auth/jwt-service.json', $this->fixture('jwt-service-decoded.json'));
    }

    public function test_tenant_fixture_matches_schema(): void
    {
        $this->assertValid('dto/tenant.json', $this->fixture('tenant.json'));
    }

    /**
     * ADR-0031 §2. A host is a bare DNS name: the switcher builds `https://{host}/...`
     * from it, so a scheme, a path or a port smuggled in here would become part of the
     * address the browser is sent to.
     */
    public function test_a_tenant_host_is_a_bare_hostname(): void
    {
        foreach (['https://acme.abeon.pl', 'acme.abeon.pl/cms', 'acme.abeon.pl:8443', 'ACME.abeon.pl', 'acme'] as $host) {
            $this->assertInvalid('dto/tenant.json', ['host' => $host], 'tenant.json');
        }

        $this->assertValid('dto/tenant.json', ['id' => 1, 'name' => 'Acme', 'slug' => 'acme', 'host' => 'panel.acme.com']);
    }

    public function test_organisation_member_fixture_matches_schema(): void
    {
        $this->assertValid('dto/organisation-member.json', $this->fixture('organisation-member.json'));
    }

    public function test_an_organisation_member_carries_no_permissions(): void
    {
        // Same reasoning as the tenant list above. An administrative listing shows who
        // holds which *role*; permissions are resolved at token issue and a client that
        // read them from here would be deriving authorisation from a response.
        $bad = $this->fixtureArray('organisation-member.json');
        $bad['permissions'] = ['core.users.manage'];

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/organisation-member.json',
        );

        $this->assertFalse($result->isValid());
    }

    public function test_an_organisation_member_status_is_a_membership_status(): void
    {
        // `users.status` has values a membership does not. Accepting one here would let
        // a global decision be presented as an organisation's own, which is the exact
        // confusion the whole administration surface is built to avoid.
        $bad = $this->fixtureArray('organisation-member.json');
        $bad['status'] = 'deleted';

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/organisation-member.json',
        );

        $this->assertFalse($result->isValid());
    }

    public function test_organisation_fixture_matches_schema(): void
    {
        $this->assertValid('dto/organisation.json', $this->fixture('organisation.json'));
    }

    public function test_an_organisation_is_not_a_tenant(): void
    {
        // `dto/tenant.json` is what a browser sees in the switcher, and `current` is a
        // property of the caller's token rather than of the organisation. A service
        // reading the record is not scoped to one, so the field has no meaning here —
        // and a projection that stored it would be storing somebody else's session.
        $bad = $this->fixtureArray('organisation.json');
        $bad['current'] = true;

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/organisation.json',
        );

        $this->assertFalse($result->isValid());
    }

    public function test_an_organisation_status_has_its_own_vocabulary(): void
    {
        // Three states, and none of them is a membership's. `suspended` appears in both
        // enums meaning different things — one organisation-wide, one per person — which
        // is precisely why the two schemas may not be merged.
        $bad = $this->fixtureArray('organisation.json');
        $bad['status'] = 'invited';

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/organisation.json',
        );

        $this->assertFalse($result->isValid());
    }

    public function test_role_fixture_matches_schema(): void
    {
        $this->assertValid('dto/role.json', $this->fixture('role.json'));
    }

    public function test_preferences_fixture_matches_schema(): void
    {
        $this->assertValid('dto/preferences.json', $this->fixture('preferences.json'));
    }

    public function test_a_pin_says_which_application_it_belongs_to(): void
    {
        // Pins are stored once per user per organisation and shown by every application
        // of it. Without the owning application, a pin to `/settings` opens whichever
        // application happens to be rendering the sidebar.
        $bad = $this->fixtureArray('preferences.json');
        unset($bad['chrome']['pinned'][0]['app']);

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/preferences.json',
        );

        $this->assertFalse($result->isValid());
    }

    public function test_a_pinned_section_needs_a_label(): void
    {
        // A section without a name renders as a header with nothing in it — the MVP
        // accepted that and showed an empty row.
        $bad = $this->fixtureArray('preferences.json');
        unset($bad['chrome']['pinnedSections'][1]['label']);

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/preferences.json',
        );

        $this->assertFalse($result->isValid());
    }

    public function test_notification_requested_fixture_matches_schema(): void
    {
        $this->assertValid('events/notification-requested.json', $this->fixture('notification-requested.json'));
    }

    public function test_a_notification_request_without_channels_is_valid(): void
    {
        $request = $this->fixtureArray('notification-requested.json');
        unset($request['channels']);

        $this->assertValid('events/notification-requested.json', $request);
    }

    public function test_a_notification_request_must_keep_in_app(): void
    {
        $this->assertInvalid('events/notification-requested.json', ['channels' => ['email']], 'notification-requested.json');
    }

    public function test_a_notification_request_refuses_an_unknown_channel(): void
    {
        $this->assertInvalid('events/notification-requested.json', ['channels' => ['in_app', 'sms']], 'notification-requested.json');
    }

    public function test_notification_preferences_fixture_matches_schema(): void
    {
        $this->assertValid('dto/notification-preferences.json', $this->fixture('notification-preferences.json'));
    }

    public function test_in_app_is_not_a_configurable_preference(): void
    {
        $this->assertInvalid(
            'dto/notification-preferences.json',
            ['preferences' => [['type' => '*', 'channels' => ['in_app' => false]]]],
            'notification-preferences.json',
        );
    }

    public function test_a_preference_rule_must_set_a_channel(): void
    {
        $this->assertInvalid(
            'dto/notification-preferences.json',
            ['preferences' => [['type' => 'crm.deal.won', 'channels' => []]]],
            'notification-preferences.json',
        );
    }

    public function test_message_request_fixture_matches_schema(): void
    {
        $this->assertValid('events/message-requested.json', $this->fixture('message-requested.json'));
    }

    public function test_message_fixture_matches_schema(): void
    {
        $this->assertValid('dto/message.json', $this->fixture('message.json'));
    }

    public function test_a_message_needs_a_template_and_an_idempotency_key(): void
    {
        foreach (['template', 'idempotency_key'] as $field) {
            $bad = $this->fixtureArray('message-requested.json');
            unset($bad[$field]);

            $result = $this->validator->validate(
                json_decode((string) json_encode($bad)),
                self::SCHEMA_NS.'events/message-requested.json',
            );

            $this->assertFalse($result->isValid(), "message without {$field} was accepted");
        }
    }

    public function test_a_message_template_names_the_service_that_owns_it(): void
    {
        // `auth.invitation`, never a bare `invitation`: two services would otherwise
        // fight over one template name in AbeonUnified.
        $this->assertInvalid('events/message-requested.json', ['template' => 'invitation'], 'message-requested.json');
    }

    public function test_a_message_locale_is_one_of_the_two_we_render(): void
    {
        $this->assertInvalid('events/message-requested.json', ['locale' => 'de'], 'message-requested.json');
    }

    public function test_the_user_dto_carries_the_verification_flag(): void
    {
        $this->assertValid('dto/user.json', $this->fixture('user.json'));

        // Optional on the wire: a producer that predates FR-4 still validates.
        $legacy = $this->fixtureArray('user.json');
        unset($legacy['email_verified']);
        $this->assertValid('dto/user.json', $legacy);

        $this->assertInvalid('dto/user.json', ['email_verified' => 'yes'], 'user.json');
    }

    public function test_a_role_is_addressed_by_name_not_by_id(): void
    {
        // A role id is internal and organisation-scoped. Putting one on the wire would
        // let a caller name a row belonging to somebody else's tenant and learn whether
        // it exists; the name is also what the token carries.
        $bad = $this->fixtureArray('role.json');
        $bad['id'] = 7;

        $result = $this->validator->validate(
            json_decode((string) json_encode($bad)),
            self::SCHEMA_NS.'dto/role.json',
        );

        $this->assertFalse($result->isValid());
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

    /**
     * @param  array<string, mixed>  $override
     */
    private function assertInvalid(string $schemaRelPath, array $override, string $fixture): void
    {
        $data = array_replace($this->fixtureArray($fixture), $override);
        $payload = json_decode((string) json_encode($data, JSON_THROW_ON_ERROR));

        $this->assertFalse($this->validator->validate($payload, self::SCHEMA_NS.$schemaRelPath)->isValid());
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
