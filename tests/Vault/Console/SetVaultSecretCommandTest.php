<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Console;

use Mosyca\Core\Vault\Console\SetVaultSecretCommand;
use Mosyca\Core\Vault\VaultManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Mosyca\Core\Vault\Console\SetVaultSecretCommand
 */
final class SetVaultSecretCommandTest extends TestCase
{
    /** @var VaultManager&MockObject */
    private VaultManager $vaultManager;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->vaultManager = $this->createMock(VaultManager::class);
        $command = new SetVaultSecretCommand($this->vaultManager);
        $this->tester = new CommandTester($command);
    }

    // ── command metadata ──────────────────────────────────────────────────────

    public function testCommandName(): void
    {
        self::assertSame('mosyca:vault:set', (new SetVaultSecretCommand($this->vaultManager))->getName());
    }

    // ── successful execution ──────────────────────────────────────────────────

    public function testSuccessfulStorageWithValidJson(): void
    {
        $this->vaultManager
            ->expects(self::once())
            ->method('storeSecret')
            ->with(
                'bluevendsand',
                'shopware6',
                ['client_id' => 'abc', 'client_secret' => 'xyz'],
                null,
                null,
            );

        $this->tester->setInputs(['{"client_id": "abc", "client_secret": "xyz"}']);
        $exitCode = $this->tester->execute([
            'tenant_id' => 'bluevendsand',
            'integration_type' => 'shopware6',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('bluevendsand', $this->tester->getDisplay());
        self::assertStringContainsString('shopware6', $this->tester->getDisplay());
    }

    public function testSuccessfulStorageWithUserId(): void
    {
        $this->vaultManager
            ->expects(self::once())
            ->method('storeSecret')
            ->with(
                'tenant',
                'spotify',
                ['access_token' => 'tok'],
                'user-karim',
                null,
            );

        $this->tester->setInputs(['{"access_token": "tok"}']);
        $exitCode = $this->tester->execute([
            'tenant_id' => 'tenant',
            'integration_type' => 'spotify',
            '--user-id' => 'user-karim',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testSuccessfulStorageWithExpiresInDays(): void
    {
        $this->vaultManager
            ->expects(self::once())
            ->method('storeSecret')
            ->with(
                'tenant',
                'shopware6',
                ['k' => 'v'],
                null,
                self::callback(static function (?\DateTimeImmutable $expiresAt): bool {
                    if (null === $expiresAt) {
                        return false;
                    }
                    // Should be approximately 30 days from now.
                    $diff = $expiresAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();

                    return $diff > 29 * 86400 && $diff < 31 * 86400;
                }),
            );

        $this->tester->setInputs(['{"k": "v"}']);
        $exitCode = $this->tester->execute([
            'tenant_id' => 'tenant',
            'integration_type' => 'shopware6',
            '--expires-in-days' => '30',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testSuccessMessageContainsStoredContext(): void
    {
        $this->vaultManager->method('storeSecret');

        $this->tester->setInputs(['{"key": "value"}']);
        $this->tester->execute([
            'tenant_id' => 'my-tenant',
            'integration_type' => 'shopware6',
        ]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('my-tenant', $display);
        self::assertStringContainsString('shopware6', $display);
    }

    // ── validation failures ───────────────────────────────────────────────────

    public function testFailsOnInvalidJson(): void
    {
        $this->vaultManager->expects(self::never())->method('storeSecret');

        $this->tester->setInputs(['{not valid json}']);
        $exitCode = $this->tester->execute([
            'tenant_id' => 'tenant',
            'integration_type' => 'shopware6',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Invalid JSON', $this->tester->getDisplay());
    }

    public function testFailsOnEmptyPayload(): void
    {
        $this->vaultManager->expects(self::never())->method('storeSecret');

        $this->tester->setInputs(['']);
        $exitCode = $this->tester->execute([
            'tenant_id' => 'tenant',
            'integration_type' => 'shopware6',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('empty', $this->tester->getDisplay());
    }

    public function testFailsOnJsonScalarPayload(): void
    {
        // JSON scalars (strings, numbers) are not valid credential payloads.
        $this->vaultManager->expects(self::never())->method('storeSecret');

        $this->tester->setInputs(['"just-a-string"']);
        $exitCode = $this->tester->execute([
            'tenant_id' => 'tenant',
            'integration_type' => 'shopware6',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testFailsOnNonPositiveExpiresInDays(): void
    {
        $this->vaultManager->expects(self::never())->method('storeSecret');

        $this->tester->setInputs(['{"k": "v"}']);
        $exitCode = $this->tester->execute([
            'tenant_id' => 'tenant',
            'integration_type' => 'shopware6',
            '--expires-in-days' => '0',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('positive integer', $this->tester->getDisplay());
    }

    public function testFailsOnNegativeExpiresInDays(): void
    {
        $this->vaultManager->expects(self::never())->method('storeSecret');

        $this->tester->setInputs(['{"k": "v"}']);
        $exitCode = $this->tester->execute([
            'tenant_id' => 'tenant',
            'integration_type' => 'shopware6',
            '--expires-in-days' => '-5',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testFailsOnNonIntegerExpiresInDays(): void
    {
        $this->vaultManager->expects(self::never())->method('storeSecret');

        $this->tester->setInputs(['{"k": "v"}']);
        $exitCode = $this->tester->execute([
            'tenant_id' => 'tenant',
            'integration_type' => 'shopware6',
            '--expires-in-days' => 'thirty',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    // ── security: no --payload argument exists ────────────────────────────────

    public function testCommandHasNoPayloadArgument(): void
    {
        $command = new SetVaultSecretCommand($this->vaultManager);

        // The command must NOT offer --payload as an option (shell history prevention).
        self::assertFalse($command->getDefinition()->hasOption('payload'));
    }

    public function testCommandHasNoPayloadPositionalArgument(): void
    {
        $command = new SetVaultSecretCommand($this->vaultManager);
        $argNames = array_keys($command->getDefinition()->getArguments());

        self::assertNotContains('payload', $argNames);
    }
}
