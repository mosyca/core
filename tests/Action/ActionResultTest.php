<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Action;

use Mosyca\Core\Action\ActionResult;
use PHPUnit\Framework\TestCase;

final class ActionResultTest extends TestCase
{
    public function testOkResult(): void
    {
        $result = ActionResult::ok(['key' => 'value'], 'Success summary');

        self::assertTrue($result->success);
        self::assertSame(['key' => 'value'], $result->data);
        self::assertSame('Success summary', $result->summary);
        self::assertEmpty($result->links);
        self::assertEmpty($result->embedded);
    }

    public function testErrorResult(): void
    {
        $result = ActionResult::error('Something went wrong', ['code' => 404]);

        self::assertFalse($result->success);
        self::assertSame('Something went wrong', $result->summary);
        self::assertSame(['code' => 404], $result->data);
    }

    public function testErrorWithoutContextDefaults(): void
    {
        $result = ActionResult::error('Oops');

        self::assertFalse($result->success);
        self::assertIsArray($result->data);
        self::assertEmpty($result->data);
    }

    public function testWithLinksIsImmutable(): void
    {
        $original = ActionResult::ok([], 'ok');
        $withLinks = $original->withLinks(['self' => '/api/test']);

        self::assertNotSame($original, $withLinks);
        self::assertEmpty($original->links);
        self::assertSame(['self' => '/api/test'], $withLinks->links);
    }

    public function testWithEmbeddedIsImmutable(): void
    {
        $original = ActionResult::ok([], 'ok');
        $withEmbedded = $original->withEmbedded(['order' => ['id' => 1]]);

        self::assertNotSame($original, $withEmbedded);
        self::assertEmpty($original->embedded);
        self::assertSame(['order' => ['id' => 1]], $withEmbedded->embedded);
    }

    public function testToArrayContainsRequiredKeys(): void
    {
        $result = ActionResult::ok(['x' => 1], 'summary');
        $array = $result->toArray();

        self::assertArrayHasKey('success', $array);
        self::assertArrayHasKey('summary', $array);
        self::assertArrayHasKey('data', $array);
    }

    public function testToArrayOmitsEmptyLinksAndEmbedded(): void
    {
        $array = ActionResult::ok([], 'ok')->toArray();

        self::assertArrayNotHasKey('_links', $array);
        self::assertArrayNotHasKey('_embedded', $array);
        self::assertArrayNotHasKey('_meta', $array);
    }

    public function testToArrayIncludesLinksWhenPresent(): void
    {
        $array = ActionResult::ok([], 'ok')
            ->withLinks(['self' => '/api'])
            ->toArray();

        self::assertArrayHasKey('_links', $array);
        self::assertSame(['self' => '/api'], $array['_links']);
    }

    public function testToArrayIncludesEmbeddedWhenPresent(): void
    {
        $array = ActionResult::ok([], 'ok')
            ->withEmbedded(['item' => ['id' => 42]])
            ->toArray();

        self::assertArrayHasKey('_embedded', $array);
        self::assertSame(['item' => ['id' => 42]], $array['_embedded']);
    }

    public function testChaining(): void
    {
        $result = ActionResult::ok(['data' => true], 'ok')
            ->withLinks(['self' => '/api/resource'])
            ->withEmbedded(['related' => []]);

        self::assertTrue($result->success);
        self::assertNotEmpty($result->links);
        self::assertNotEmpty($result->embedded);
    }

    // ── V0.8: Depot builder ───────────────────────────────────────────────────

    public function testDefaultDepotEligibilityIsFalse(): void
    {
        $result = ActionResult::ok([], 'ok');

        self::assertFalse($result->depotEligible);
        self::assertSame(3600, $result->depotTtl);
    }

    public function testWithDepotSetsEligibility(): void
    {
        $original = ActionResult::ok([], 'ok');
        $cached = $original->withDepot(ttl: 7200);

        self::assertNotSame($original, $cached);
        self::assertFalse($original->depotEligible); // immutable
        self::assertTrue($cached->depotEligible);
        self::assertSame(7200, $cached->depotTtl);
    }

    public function testWithDepotDefaultTtl(): void
    {
        $result = ActionResult::ok([], 'ok')->withDepot();

        self::assertTrue($result->depotEligible);
        self::assertSame(3600, $result->depotTtl);
    }

    public function testWithoutDepotStripsEligibility(): void
    {
        $eligible = ActionResult::ok([], 'ok')->withDepot(ttl: 9000);
        $stripped = $eligible->withoutDepot();

        self::assertNotSame($eligible, $stripped);
        self::assertTrue($eligible->depotEligible);   // original unchanged
        self::assertFalse($stripped->depotEligible);
        self::assertSame(9000, $stripped->depotTtl);  // TTL preserved (only eligibility stripped)
    }

    public function testWithDepotPreservesOtherFields(): void
    {
        $result = ActionResult::error('oops')
            ->withLedger(level: 'warning', payload: ['reason' => 'test'])
            ->withDepot(ttl: 600);

        self::assertFalse($result->success);
        self::assertTrue($result->depotEligible);
        self::assertSame(['reason' => 'test'], $result->ledgerPayload);
    }

    // ── V0.8: Ledger builder ─────────────────────────────────────────────────

    public function testDefaultLedgerPayloadIsNull(): void
    {
        $result = ActionResult::ok([], 'ok');

        self::assertNull($result->ledgerPayload);
        self::assertSame('info', $result->ledgerLevel);
    }

    public function testWithLedgerSetsPayload(): void
    {
        $original = ActionResult::ok([], 'ok');
        $logged = $original->withLedger(level: 'warning', payload: ['count' => 42]);

        self::assertNotSame($original, $logged);
        self::assertNull($original->ledgerPayload); // immutable
        self::assertSame(['count' => 42], $logged->ledgerPayload);
        self::assertSame('warning', $logged->ledgerLevel);
    }

    public function testWithLedgerDefaultLevel(): void
    {
        $result = ActionResult::ok([], 'ok')->withLedger(payload: ['x' => 1]);

        self::assertSame('info', $result->ledgerLevel);
    }

    public function testWithLedgerPreservesDepot(): void
    {
        $result = ActionResult::ok([], 'ok')
            ->withDepot(ttl: 1800)
            ->withLedger(level: 'info', payload: ['items' => 5]);

        self::assertTrue($result->depotEligible);
        self::assertSame(1800, $result->depotTtl);
        self::assertSame(['items' => 5], $result->ledgerPayload);
    }

    public function testScaffoldGuardPattern(): void
    {
        // Simulates what ActionRunProcessor does for scaffold actions
        $result = ActionResult::ok(['pii' => 'data'], 'raw')->withDepot(ttl: 3600);
        self::assertTrue($result->depotEligible);

        $guarded = $result->withoutDepot();
        self::assertFalse($guarded->depotEligible);
        self::assertSame(['pii' => 'data'], $guarded->data); // data unchanged
    }

    // ── AC 6: ActionResult::failure() ────────────────────────────────────────

    /**
     * AC 6: failure() must return errorCode non-null.
     */
    public function testFailureReturnsNonNullErrorCode(): void
    {
        $result = ActionResult::failure(
            'Access denied.',
            'ERROR_ACL_DENIED',
            'Provide the correct security_pin.',
        );

        self::assertNotNull($result->errorCode);
        self::assertSame('ERROR_ACL_DENIED', $result->errorCode);
    }

    /**
     * AC 6: failure() must return correctionHint non-null.
     */
    public function testFailureReturnsNonNullCorrectionHint(): void
    {
        $result = ActionResult::failure(
            'Access denied.',
            'ERROR_ACL_DENIED',
            'Provide the correct security_pin.',
        );

        self::assertNotNull($result->correctionHint);
        self::assertSame('Provide the correct security_pin.', $result->correctionHint);
    }

    public function testFailureIsNotSuccess(): void
    {
        $result = ActionResult::failure(
            'Something failed.',
            'ERROR_DOMAIN',
            'Check your input.',
        );

        self::assertFalse($result->success);
        self::assertSame('Something failed.', $result->summary);
    }

    public function testFailureAcceptsContextArray(): void
    {
        $result = ActionResult::failure(
            'Invalid PIN.',
            'ERROR_INVALID_PIN',
            'Provide valid security_pin in payload field "pin".',
            ['attempts_left' => 2],
        );

        self::assertSame(['attempts_left' => 2], $result->data);
    }

    public function testFailureWithEmptyContextDefaultsToEmptyArray(): void
    {
        $result = ActionResult::failure(
            'Denied.',
            'ERROR_ACL_DENIED',
            'Contact administrator.',
        );

        self::assertIsArray($result->data);
        self::assertEmpty($result->data);
    }

    /**
     * Backward-compat: legacy error() must still return a result with success=false.
     */
    public function testErrorBackwardCompatStillWorks(): void
    {
        $result = ActionResult::error('Something went wrong', ['code' => 404]);

        self::assertFalse($result->success);
        self::assertSame('Something went wrong', $result->summary);
    }

    /**
     * error() delegates to failure() internally — errorCode is 'ERROR_LEGACY'.
     */
    public function testErrorSetsErrorLegacyCode(): void
    {
        $result = ActionResult::error('Oops');

        self::assertSame('ERROR_LEGACY', $result->errorCode);
    }

    // ── V0.14a: ActionResult::authRequired() ─────────────────────────────────

    public function testAuthRequiredIsNotSuccess(): void
    {
        $result = ActionResult::authRequired('spotify');

        self::assertFalse($result->success);
    }

    public function testAuthRequiredErrorCode(): void
    {
        $result = ActionResult::authRequired('shopware6');

        self::assertSame('AUTH_REQUIRED', $result->errorCode);
    }

    public function testAuthRequiredSummaryContainsIntegrationType(): void
    {
        $result = ActionResult::authRequired('spotify');

        self::assertStringContainsString('spotify', $result->summary);
    }

    public function testAuthRequiredCorrectionHintContainsIntegrationType(): void
    {
        $result = ActionResult::authRequired('firebase');

        self::assertNotNull($result->correctionHint);
        self::assertStringContainsString('firebase', $result->correctionHint);
    }

    public function testAuthRequiredDataContainsIntegrationType(): void
    {
        $result = ActionResult::authRequired('shopware6');

        self::assertIsArray($result->data);
        /** @var array<string, mixed> $data */
        $data = $result->data;
        self::assertArrayHasKey('integration_type', $data);
        self::assertSame('shopware6', $data['integration_type']);
    }

    public function testAuthRequiredDataContainsRequiredScopes(): void
    {
        $result = ActionResult::authRequired('spotify', ['playlist-modify-public', 'user-read-email']);

        /** @var array<string, mixed> $data */
        $data = $result->data;
        self::assertArrayHasKey('required_scopes', $data);
        self::assertSame(['playlist-modify-public', 'user-read-email'], $data['required_scopes']);
    }

    public function testAuthRequiredWithoutScopesDefaultsToEmptyArray(): void
    {
        $result = ActionResult::authRequired('spotify');

        /** @var array<string, mixed> $data */
        $data = $result->data;
        self::assertSame([], $data['required_scopes']);
    }

    public function testAuthRequiredDataContainsNoSecretValues(): void
    {
        // The data array MUST only contain integration type and scopes — no credentials.
        $result = ActionResult::authRequired('shopware6', ['write:orders']);

        /** @var array<string, mixed> $data */
        $data = $result->data;
        self::assertCount(2, $data, 'data must contain exactly integration_type and required_scopes');
        self::assertArrayHasKey('integration_type', $data);
        self::assertArrayHasKey('required_scopes', $data);
    }
}
