<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault;

use Mosyca\Core\Vault\Cipher\DummySecretCipher;
use Mosyca\Core\Vault\Entity\VaultSecret;
use Mosyca\Core\Vault\Exception\SecretNotFoundException;
use Mosyca\Core\Vault\Repository\VaultSecretRepository;
use Mosyca\Core\Vault\VaultManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mosyca\Core\Vault\VaultManager
 *
 * Uses DummySecretCipher for deterministic, key-free encryption (Vault Rule V3)
 * and a PHPUnit mock for VaultSecretRepository (QA Rule 2: Deterministic Isolation).
 */
final class VaultManagerTest extends TestCase
{
    /** @var VaultSecretRepository&MockObject */
    private VaultSecretRepository $repository;

    private VaultManager $manager;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(VaultSecretRepository::class);
        $this->manager = new VaultManager($this->repository, new DummySecretCipher());
    }

    // ── storeSecret — create ──────────────────────────────────────────────────

    public function testStoreSecretCreatesNewEntityWhenNoneExists(): void
    {
        $this->repository
            ->method('findByContext')
            ->willReturn(null);

        $this->repository
            ->expects(self::once())
            ->method('save')
            ->with(
                self::isInstanceOf(VaultSecret::class),
                true,
            );

        $this->manager->storeSecret('tenant-1', 'shopware6', ['client_id' => 'abc']);
    }

    public function testStoreSecretSetsCorrectTenantAndIntegration(): void
    {
        $this->repository->method('findByContext')->willReturn(null);

        $captured = null;
        $this->repository
            ->method('save')
            ->willReturnCallback(static function (VaultSecret $secret) use (&$captured): void {
                $captured = $secret;
            });

        $this->manager->storeSecret('my-tenant', 'spotify', ['access_token' => 'tok']);

        self::assertInstanceOf(VaultSecret::class, $captured);
        self::assertSame('my-tenant', $captured->getTenantId());
        self::assertSame('spotify', $captured->getIntegrationType());
    }

    public function testStoreSecretSetsUserId(): void
    {
        $this->repository->method('findByContext')->willReturn(null);

        $captured = null;
        $this->repository
            ->method('save')
            ->willReturnCallback(static function (VaultSecret $secret) use (&$captured): void {
                $captured = $secret;
            });

        $this->manager->storeSecret('tenant', 'spotify', ['token' => 'x'], userId: 'user-karim');

        self::assertInstanceOf(VaultSecret::class, $captured);
        self::assertSame('user-karim', $captured->getUserId());
    }

    public function testStoreSecretSetsExpiresAt(): void
    {
        $this->repository->method('findByContext')->willReturn(null);

        $expiresAt = new \DateTimeImmutable('+30 days');
        $captured = null;
        $this->repository
            ->method('save')
            ->willReturnCallback(static function (VaultSecret $secret) use (&$captured): void {
                $captured = $secret;
            });

        $this->manager->storeSecret('t', 'shopware6', ['k' => 'v'], expiresAt: $expiresAt);

        self::assertInstanceOf(VaultSecret::class, $captured);
        self::assertSame($expiresAt, $captured->getExpiresAt());
    }

    // ── storeSecret — upsert (update) ─────────────────────────────────────────

    public function testStoreSecretUpdatesExistingEntity(): void
    {
        $existing = new VaultSecret('tenant', 'shopware6', 'old-payload');

        $this->repository
            ->method('findByContext')
            ->willReturn($existing);

        $this->repository
            ->expects(self::once())
            ->method('save')
            ->with($existing, true);

        $this->manager->storeSecret('tenant', 'shopware6', ['client_id' => 'new-id']);

        // Verify the payload changed (DummySecretCipher uses base64, so it's not 'old-payload').
        self::assertNotSame('old-payload', $existing->getCredentialPayload());
    }

    public function testStoreSecretDoesNotCallRepositoryCreateOnUpsert(): void
    {
        $existing = new VaultSecret('t', 'shopware6', 'payload');
        $this->repository->method('findByContext')->willReturn($existing);

        // save() must be called exactly once (update path, not double-create).
        $this->repository->expects(self::once())->method('save');

        $this->manager->storeSecret('t', 'shopware6', ['key' => 'val']);
    }

    // ── retrieveSecret ────────────────────────────────────────────────────────

    public function testRetrieveSecretReturnsDecodedPayload(): void
    {
        $payload = ['client_id' => 'abc', 'client_secret' => 'xyz'];
        $cipher = new DummySecretCipher();
        $encrypted = $cipher->encrypt((string) json_encode($payload));

        $secret = new VaultSecret('tenant', 'shopware6', $encrypted);
        $this->repository->method('findByContext')->willReturn($secret);

        $result = $this->manager->retrieveSecret('tenant', 'shopware6');

        self::assertSame($payload, $result);
    }

    public function testRetrieveSecretThrowsWhenNotFound(): void
    {
        $this->repository->method('findByContext')->willReturn(null);

        $this->expectException(SecretNotFoundException::class);
        $this->manager->retrieveSecret('tenant', 'shopware6');
    }

    public function testRetrieveSecretThrowsWhenExpired(): void
    {
        $cipher = new DummySecretCipher();
        $encrypted = $cipher->encrypt((string) json_encode(['k' => 'v']));

        $expired = new VaultSecret(
            'tenant', 'shopware6', $encrypted,
            expiresAt: new \DateTimeImmutable('-1 second'),
        );

        $this->repository->method('findByContext')->willReturn($expired);

        $this->expectException(SecretNotFoundException::class);
        $this->manager->retrieveSecret('tenant', 'shopware6');
    }

    public function testRetrieveSecretExceptionMessageContainsContext(): void
    {
        $this->repository->method('findByContext')->willReturn(null);

        try {
            $this->manager->retrieveSecret('bluevendsand', 'shopware6', 'user-abc');
            self::fail('Expected SecretNotFoundException');
        } catch (SecretNotFoundException $e) {
            self::assertStringContainsString('bluevendsand', $e->getMessage());
            self::assertStringContainsString('shopware6', $e->getMessage());
            self::assertStringContainsString('user-abc', $e->getMessage());
        }
    }

    public function testRetrieveSecretThrowsRuntimeExceptionOnNonArrayPayload(): void
    {
        // Store a scalar JSON value (not an object) — structurally invalid vault entry.
        $cipher = new DummySecretCipher();
        $encrypted = $cipher->encrypt('"just a string"'); // valid JSON, but not an array

        $secret = new VaultSecret('t', 'shopware6', $encrypted);
        $this->repository->method('findByContext')->willReturn($secret);

        $this->expectException(\RuntimeException::class);
        $this->manager->retrieveSecret('t', 'shopware6');
    }

    // ── deleteSecret ─────────────────────────────────────────────────────────

    public function testDeleteSecretCallsRepositoryDelete(): void
    {
        $secret = new VaultSecret('tenant', 'shopware6', 'payload');
        $this->repository->method('findByContext')->willReturn($secret);

        $this->repository
            ->expects(self::once())
            ->method('delete')
            ->with($secret, true);

        $this->manager->deleteSecret('tenant', 'shopware6');
    }

    public function testDeleteSecretThrowsWhenNotFound(): void
    {
        $this->repository->method('findByContext')->willReturn(null);

        $this->expectException(SecretNotFoundException::class);
        $this->manager->deleteSecret('tenant', 'shopware6');
    }

    // ── JSON payload encoding ─────────────────────────────────────────────────

    public function testStoreAndRetrieveRoundtripPreservesAllPayloadFields(): void
    {
        $payload = [
            'client_id' => 'sw6-client-123',
            'client_secret' => 's3cr3t!',
            'shop_url' => 'https://shop.example.com',
            'api_version' => 'v7',
        ];

        $cipher = new DummySecretCipher();
        $manager = new VaultManager($this->repository, $cipher);

        // Capture what save() receives, then return it on findByContext.
        $stored = null;
        $this->repository
            ->method('save')
            ->willReturnCallback(static function (VaultSecret $s) use (&$stored): void {
                $stored = $s;
            });

        $manager->storeSecret('t', 'shopware6', $payload);

        self::assertInstanceOf(VaultSecret::class, $stored);

        $this->repository
            ->method('findByContext')
            ->willReturn($stored);

        $retrieved = $manager->retrieveSecret('t', 'shopware6');

        self::assertSame($payload, $retrieved);
    }
}
