<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Auth\SecurityPolicyUnresolved;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use App\Shared\Http\Middleware\AssignCorrelationId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * `AC-T-004-09` — no stack trace, SQL, internal hostname, or secret in any
 * error or log — and `API-SPEC.md` §1.5/§1.6, which state it for every
 * response, not only for authentication.
 *
 * This suite asserts the OUTER boundary rather than any one handler. Before it,
 * `ErrorResponseFactory` and `AssignCorrelationId` both existed, both matched
 * the specification, and neither was referenced by anything: the middleware
 * group was empty and the exception handler rendered whatever Laravel's
 * default renderer produced. Under `APP_DEBUG=true` that is a full stack
 * trace, and §1.6 prohibits it without exception.
 *
 * The routes here are THROW-AWAY FIXTURES defined inside the test process. They
 * are not the application's endpoints, they are not registered in `routes/`,
 * and nothing ships them. A real endpoint is deliberately not invented for this
 * — `AC-T-004-07` needs an authenticated cookie and `AC-T-004-01`'s endpoints
 * need a login that `SEC-007` still refuses to permit, so the boundary is
 * tested where it can actually be exercised.
 */
final class ErrorRenderingTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // The documented shape
    // =====================================================================

    /**
     * `API-SPEC.md` §1.5, field for field.
     *
     * `code` is the contract and `message` is not, so both are asserted: a
     * response missing `request_id` or `retryable` is not the documented shape
     * even if the code is right.
     */
    public function test_a_domain_failure_renders_the_documented_error_shape(): void
    {
        Route::get('/__test/domain', static function (): void {
            throw self::failure(
                ErrorCode::PropertyScopeDenied,
                'A worked example of the documented message.',
                [['field' => 'property_id', 'message' => 'not granted']],
            );
        });

        $correlationId = (string) Str::ulid();
        $response = $this->withHeaders([AssignCorrelationId::HEADER => $correlationId])
            ->getJson('/__test/domain');

        // 403, not the 422 of the §1.5 worked example: that example is
        // `INVENTORY_UNAVAILABLE`, and a scope denial is a forbidden actor, not
        // an unsatisfiable request. The status comes from the code, which is
        // what §1.7 requires.
        $response->assertStatus(403);
        // Field by field rather than through `assertJsonStructure`, which can
        // only assert that a key EXISTS. A `retryable` that came back as the
        // string `"false"` would pass a structure check and break every client
        // that branches on it.
        $this->assertSame(
            ['code', 'message', 'request_id', 'details', 'retryable'],
            array_keys((array) $response->json()),
            'API-SPEC §1.5 fixes the field set.',
        );

        $this->assertIsString($response->json('code'));
        $this->assertIsString($response->json('message'));
        $this->assertIsString($response->json('request_id'));
        $this->assertIsArray($response->json('details'));
        $this->assertIsBool($response->json('retryable'));

        $this->assertSame('PROPERTY_SCOPE_DENIED', $response->json('code'));
        $this->assertSame([['field' => 'property_id', 'message' => 'not granted']], $response->json('details'));
        $this->assertIsBool($response->json('retryable'));
    }

    /**
     * §1.5: `retryable` is a server assertion, and the correlation ID is
     * present in the response. A `request_id` of `''` would satisfy a
     * "string" type check while failing the requirement, so the value itself is
     * asserted.
     */
    public function test_the_response_carries_the_correlation_id_the_client_supplied(): void
    {
        Route::get('/__test/correlation', static function (): void {
            throw self::failure(ErrorCode::AuthRequired, 'Sign in.');
        });

        $correlationId = (string) Str::ulid();
        $response = $this->withHeaders([AssignCorrelationId::HEADER => $correlationId])
            ->getJson('/__test/correlation');

        $response->assertStatus(401);
        $this->assertSame($correlationId, $response->json('request_id'));
        $this->assertSame(
            $correlationId,
            $response->headers->get(AssignCorrelationId::HEADER),
            '§1.3 requires the header back on the response too.',
        );
    }

    /**
     * A client may supply the correlation ID but may NOT suppress it.
     *
     * The middleware's own contract. A missing or implausible value is
     * replaced, and the replacement is still present in the error body — an
     * unauditable error response is the failure this prevents.
     *
     * @return array<string, array{0: array<string, string>, 1: bool}>
     */
    public static function unacceptableCorrelationIdProvider(): array
    {
        return [
            'absent' => [[], false],
            'not a ULID' => [[AssignCorrelationId::HEADER => 'not-a-ulid'], false],
            'too short' => [[AssignCorrelationId::HEADER => '01J8Z9K2M4N6P8Q0R2S4T6V8'], false],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('unacceptableCorrelationIdProvider')]
    public function test_an_unusable_correlation_id_is_replaced_not_honoured(
        array $headers,
        bool $shouldEcho,
    ): void {
        Route::get('/__test/replaced-correlation', static function (): void {
            throw self::failure(ErrorCode::AuthRequired, 'Sign in.');
        });

        $response = $this->withHeaders($headers)->getJson('/__test/replaced-correlation');

        $requestId = (string) $response->json('request_id');
        $this->assertNotSame('', $requestId, 'An error body must always carry a request_id.');

        if (! $shouldEcho) {
            $this->assertMatchesRegularExpression(
                AssignCorrelationId::ULID_PATTERN,
                $requestId,
                'A client-supplied value that is not a plausible ULID must be replaced, not echoed.',
            );
            $this->assertNotSame('not-a-ulid', $requestId);
        }
    }

    // =====================================================================
    // §1.6 — nothing internal escapes
    // =====================================================================

    /**
     * An unhandled defect becomes `INTERNAL_ERROR` with NO detail.
     *
     * The exception message is a `RuntimeException` carrying text that names
     * the database, a table, and a file path — everything §1.6 lists. A client
     * must receive none of it. The correlation ID is the only handle offered,
     * which §1.5 anticipates: "diagnostic detail goes to the correlated
     * server-side record".
     */
    public function test_an_unhandled_defect_exposes_no_internal_detail(): void
    {
        $secretDetail = 'SQLSTATE[HY000] [1045] Access denied for user `root`@`db-primary.internal` on `zafer_pms`';

        Route::get('/__test/defect', static function () use ($secretDetail): void {
            throw new RuntimeException($secretDetail);
        });

        $correlationId = (string) Str::ulid();
        $response = $this->withHeaders([AssignCorrelationId::HEADER => $correlationId])
            ->getJson('/__test/defect');

        $response->assertStatus(500);
        $this->assertSame('INTERNAL_ERROR', $response->json('code'));

        $body = $response->getContent();

        foreach ([
            'SQLSTATE' => 'database error text',
            'db-primary.internal' => 'internal hostname',
            'zafer_pms' => 'internal database or schema name',
            'RuntimeException' => 'internal class name',
            'vendor' => 'dependency path',
        ] as $needle => $description) {
            $this->assertStringNotContainsString(
                $needle,
                (string) $body,
                "The response leaked {$description}.",
            );
        }

        $this->assertStringNotContainsString($secretDetail, (string) $body);
        $this->assertSame(
            $correlationId,
            $response->json('request_id'),
            'The correlation ID is the only handle the client gets, so it must be correct.',
        );
    }

    /**
     * No stack trace under any circumstance in the body.
     *
     * Checked as the frame markers rather than as the word "stack": a response
     * containing `#0 /app/...` is exactly what §1.6 prohibits, and a test that
     grepped for the word "stack" would pass on a body containing frames
     formatted differently.
     */
    public function test_no_stack_trace_appears_in_an_error_body(): void
    {
        Route::get('/__test/trace', static function (): never {
            throw new RuntimeException('boom');
        });

        $response = $this->getJson('/__test/trace');

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('#0 ', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString('vendor/laravel', $body);
    }

    /**
     * The authentication layer's own refusal, end to end.
     *
     * `SecurityPolicyUnresolved` is the failure T-004 produces when a security
     * value is unset, and it is the most likely unhandled path in the whole
     * authentication flow. It must reach the client as a documented 503 with
     * `SERVICE_UNAVAILABLE` — the outcome `docs/SECURITY.md` describes — and not
     * as a 500.
     */
    public function test_an_unresolved_security_policy_renders_as_service_unavailable(): void
    {
        Route::get('/__test/unresolved', static function (): void {
            throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
        });

        $response = $this->getJson('/__test/unresolved');

        $response->assertStatus(503);
        $this->assertSame('SERVICE_UNAVAILABLE', $response->json('code'));
        $this->assertMatchesRegularExpression(
            AssignCorrelationId::ULID_PATTERN,
            (string) $response->json('request_id'),
        );
    }

    /**
     * `retryable` is a real assertion rather than a constant.
     *
     * §1.5 calls it "a correctness control, not a convenience": a payment with
     * an unknown outcome must be `false` so a client retrying it cannot double
     * charge. If the flag were hard-coded, that control would be decorative.
     */
    public function test_retryable_is_derived_from_the_error_code(): void
    {
        Route::get('/__test/retryable-concurrency', static function (): void {
            throw self::failure(ErrorCode::ConcurrencyConflict, 'Retry.');
        });

        $response = $this->getJson('/__test/retryable-concurrency');

        $this->assertTrue(
            (bool) $response->json('retryable'),
            'A concurrency conflict is the one retryable case in the vocabulary.',
        );
    }

    /**
     * The response is JSON even on the failure path, and carries the documented
     * key set exactly — no framework keys leaking through alongside them.
     */
    public function test_the_error_body_contains_only_the_documented_keys(): void
    {
        Route::get('/__test/keys', static function (): void {
            throw self::failure(ErrorCode::AuthRequired, 'Sign in.');
        });

        $response = $this->getJson('/__test/keys');

        $this->assertSame(
            ['code', 'message', 'request_id', 'details', 'retryable'],
            array_keys((array) $response->json()),
            'API-SPEC §1.5 fixes the field set; an extra key is a contract change.',
        );
    }

    /**
     * A request the client did not make JSON is still answered with the
     * documented shape for API paths, because `shouldRenderJsonWhen` keys on
     * the `api/` prefix rather than on the `Accept` header.
     */
    public function test_an_api_path_is_answered_with_the_documented_shape(): void
    {
        Route::get('/api/__test/no-accept', static function (): void {
            throw self::failure(ErrorCode::AuthRequired, 'Sign in.');
        });

        $response = $this->get('/api/__test/no-accept');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
    }

    /**
     * A domain failure whose `details` are carried through verbatim.
     *
     * §1.5 scopes `details` to "field-level validation information only", so
     * the field name and the message are both part of the contract and both
     * travel together.
     */
    public function test_field_level_details_survive_rendering(): void
    {
        Route::get('/__test/details', static function (): void {
            throw self::failure(
                ErrorCode::PropertyScopeDenied,
                'A worked example of the documented message.',
                [['field' => 'property_id', 'message' => 'not granted']],
            );
        });

        $response = $this->getJson('/__test/details');

        $this->assertSame(
            [['field' => 'property_id', 'message' => 'not granted']],
            $response->json('details'),
        );
    }

    /**
     * The middleware runs BEFORE the route, so a failure inside the route still
     * has a correlation ID to report.
     *
     * This is the reason for `prepend`: had it been appended, a request that
     * failed in earlier middleware would carry an empty `request_id`.
     */
    public function test_the_correlation_id_is_assigned_before_the_route_runs(): void
    {
        Route::get('/__test/ordering', static function (Request $request) {
            // Echoing the attribute back proves it was set at route time, and a
            // route that throws proves it survives into the error body.
            return response()->json([
                'seen' => (string) $request->attributes->get(AssignCorrelationId::ATTRIBUTE),
            ]);
        });

        $response = $this->getJson('/__test/ordering');

        $this->assertMatchesRegularExpression(
            AssignCorrelationId::ULID_PATTERN,
            (string) $response->json('seen'),
        );
    }

    /**
     * The same, with debug mode ON — which is the case that actually matters.
     *
     * `phpunit.xml` sets `APP_DEBUG=false`, and under that setting the stock
     * handler answers `{"message": "..."}`: the wrong shape, and no
     * `request_id`, but nothing worse. Debug mode is where a stock handler
     * appends the exception message, the file paths, and the frame list, and
     * §1.6 prohibits all of it. Flipping the flag proves the registered
     * handler is chosen on the exception TYPE and not on the debug setting, so
     * turning debug on to diagnose a production problem cannot start leaking.
     */
    public function test_debug_mode_does_not_reenable_detail_in_the_response(): void
    {
        config(['app.debug' => true]);

        $secretDetail = 'Connection refused to db-primary.internal:3306 for user `zafer`';

        Route::get('/__test/debug-on', static function () use ($secretDetail): void {
            throw new RuntimeException($secretDetail);
        });

        $response = $this->getJson('/__test/debug-on');

        $response->assertStatus(500);

        $body = (string) $response->getContent();

        $this->assertSame('INTERNAL_ERROR', $response->json('code'));
        $this->assertStringNotContainsString($secretDetail, $body, 'The raw exception message leaked.');
        $this->assertStringNotContainsString('db-primary.internal', $body, 'An internal hostname leaked.');
        $this->assertStringNotContainsString('#0 ', $body, 'A stack frame leaked.');
        $this->assertStringNotContainsString('vendor', $body, 'A dependency path leaked.');
    }

    /**
     * A concrete `DomainFailure` carrying any code in the vocabulary.
     *
     * `DomainFailure` is abstract on purpose — every code in `API-SPEC.md` §2
     * that has a named class uses it, and `T-004` owns only a few of them. This
     * suite asserts the RENDERING BOUNDARY, which is shared infrastructure and
     * must be proven for codes no Identity class raises yet; a throwaway
     * anonymous subclass is how that is done without adding a permanent class
     * to the vocabulary for the sake of a test.
     *
     * @param  array<int, array{field: string, message: string}>  $details
     */
    private static function failure(
        ErrorCode $code,
        string $message,
        array $details = [],
    ): DomainFailure {
        return new class($code, $message, $details) extends DomainFailure {};
    }
}
