<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Vault\Console;

use Mosyca\Core\Action\ActionInterface;
use Mosyca\Core\Action\ActionRegistry;
use Mosyca\Core\Vault\Console\VaultCleanupCommand;
use Mosyca\Core\Vault\Entity\VaultSecret;
use Mosyca\Core\Vault\Repository\VaultSecretRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(VaultCleanupCommand::class)]
final class VaultCleanupCommandTest extends TestCase
{
    /** Real ActionRegistry — final class, cannot be mocked; populate via register(). */
    private ActionRegistry $actionRegistry;
    private MockObject&VaultSecretRepository $repository;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->actionRegistry = new ActionRegistry();
        $this->repository = $this->createMock(VaultSecretRepository::class);

        $this->rebuildTester();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function rebuildTester(): void
    {
        $command = new VaultCleanupCommand($this->actionRegistry, $this->repository);
        $this->tester = new CommandTester($command);
    }

    /**
     * Register stub actions for the given integration namespaces.
     *
     * @param string[] $activeNamespaces e.g. ['shopware6', 'core']
     */
    private function registerNamespaces(array $activeNamespaces): void
    {
        foreach ($activeNamespaces as $ns) {
            $action = $this->createMock(ActionInterface::class);
            $action->method('getName')->willReturn($ns.':resource:action');
            $this->actionRegistry->register($action);
        }
        // Rebuild tester so the command sees the populated registry.
        $this->rebuildTester();
    }

    /**
     * Build a VaultSecret with the given tenant and integration type.
     */
    private function makeSecret(string $tenantId, string $integrationType, ?string $userId = null): VaultSecret
    {
        return new VaultSecret($tenantId, $integrationType, 'encrypted-payload', $userId);
    }

    // ── Scenario 1: no orphaned secrets (dry-run) ─────────────────────────────

    #[Test]
    public function noOrphanedSecretsPrintsSuccessAndSkipsDeletion(): void
    {
        $this->registerNamespaces(['shopware6', 'core']);
        $this->repository->method('findOrphaned')->willReturn([]);

        $this->repository->expects(self::never())->method('deleteMany');

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Vault is clean', $this->tester->getDisplay());
    }

    // ── Scenario 2: orphaned found, dry-run shows table, no deletion ──────────

    #[Test]
    public function orphanedFoundDryRunShowsTableWithoutDeleting(): void
    {
        $this->registerNamespaces(['core']);

        $orphaned = [
            $this->makeSecret('tenant-a', 'legacy-plugin'),
            $this->makeSecret('tenant-b', 'old-connector', 'user-123'),
        ];
        $this->repository->method('findOrphaned')->willReturn($orphaned);

        $this->repository->expects(self::never())->method('deleteMany');

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('tenant-a', $display);
        self::assertStringContainsString('legacy-plugin', $display);
        self::assertStringContainsString('tenant-b', $display);
        self::assertStringContainsString('old-connector', $display);
        self::assertStringContainsString('--force', $display);
    }

    // ── Scenario 3: orphaned found, --force deletes them ─────────────────────

    #[Test]
    public function forceDeletesOrphanedSecrets(): void
    {
        $this->registerNamespaces(['core']);

        $orphaned = [$this->makeSecret('tenant-a', 'gone-plugin')];
        $this->repository->method('findOrphaned')->willReturn($orphaned);

        $this->repository
            ->expects(self::once())
            ->method('deleteMany')
            ->with($orphaned);

        $exitCode = $this->tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('deleted', $this->tester->getDisplay());
    }

    // ── Scenario 4: --tenant scopes findOrphaned call (dry-run) ──────────────

    #[Test]
    public function tenantOptionIsPassedToRepositoryInDryRun(): void
    {
        $this->registerNamespaces(['shopware6', 'core']);

        $this->repository
            ->expects(self::once())
            ->method('findOrphaned')
            ->with(['shopware6', 'core'], 'tenant-x')
            ->willReturn([]);

        $this->tester->execute(['--tenant' => 'tenant-x']);
    }

    // ── Scenario 5: --tenant scopes findOrphaned call (--force) ──────────────

    #[Test]
    public function tenantOptionIsPassedToRepositoryWithForce(): void
    {
        $this->registerNamespaces(['core']);

        $orphaned = [$this->makeSecret('tenant-x', 'gone-plugin')];

        $this->repository
            ->expects(self::once())
            ->method('findOrphaned')
            ->with(['core'], 'tenant-x')
            ->willReturn($orphaned);

        $this->repository->expects(self::once())->method('deleteMany')->with($orphaned);

        $exitCode = $this->tester->execute(['--tenant' => 'tenant-x', '--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    // ── Scenario 6: empty ActionRegistry aborts immediately ──────────────────

    #[Test]
    public function emptyActionRegistryAbortsWithFailure(): void
    {
        // ActionRegistry starts empty from setUp() — no registerNamespaces() call.

        $this->repository->expects(self::never())->method('findOrphaned');
        $this->repository->expects(self::never())->method('deleteMany');

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('no registered actions', strtolower($this->tester->getDisplay()));
    }

    // ── Scenario 7: table content is correct ─────────────────────────────────

    #[Test]
    public function tableShowsCorrectColumnsAndData(): void
    {
        $this->registerNamespaces(['core']);

        $orphaned = [$this->makeSecret('acme-corp', 'shopware6', 'user-42')];
        $this->repository->method('findOrphaned')->willReturn($orphaned);

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Tenant', $display);
        self::assertStringContainsString('Integration Type', $display);
        self::assertStringContainsString('User', $display);
        self::assertStringContainsString('Updated At', $display);
        self::assertStringContainsString('acme-corp', $display);
        self::assertStringContainsString('shopware6', $display);
        self::assertStringContainsString('user-42', $display);
    }

    // ── Scenario 8: credential payload is never shown ─────────────────────────

    #[Test]
    public function credentialPayloadNeverAppearsInOutput(): void
    {
        $this->registerNamespaces(['core']);

        $orphaned = [$this->makeSecret('tenant-a', 'old-plugin')];
        $this->repository->method('findOrphaned')->willReturn($orphaned);

        $this->tester->execute([]);

        // 'encrypted-payload' is the value passed to VaultSecret constructor above.
        self::assertStringNotContainsString('encrypted-payload', $this->tester->getDisplay());
    }

    // ── Scenario 9: dry-run count message ────────────────────────────────────

    #[Test]
    public function dryRunOutputIncludesCountMessage(): void
    {
        $this->registerNamespaces(['core']);

        $orphaned = [
            $this->makeSecret('t1', 'legacy'),
            $this->makeSecret('t2', 'legacy'),
        ];
        $this->repository->method('findOrphaned')->willReturn($orphaned);

        $this->tester->execute([]);

        self::assertStringContainsString('2 orphaned secret(s) found', $this->tester->getDisplay());
    }

    // ── Scenario 10: tenant scoped success message ────────────────────────────

    #[Test]
    public function noOrphanedSecretsScopedMessageIncludesTenant(): void
    {
        $this->registerNamespaces(['core']);
        $this->repository->method('findOrphaned')->willReturn([]);

        $this->tester->execute(['--tenant' => 'staging']);

        self::assertStringContainsString('staging', $this->tester->getDisplay());
    }

    // ── Scenario 11: empty --tenant string treated as null ───────────────────

    #[Test]
    public function emptyTenantStringIsCoercedToNull(): void
    {
        $this->registerNamespaces(['core']);

        // Expect findOrphaned called with tenantId = null (not empty string).
        $this->repository
            ->expects(self::once())
            ->method('findOrphaned')
            ->with(['core'], null)
            ->willReturn([]);

        // Simulate --tenant= (no value provided).
        $this->tester->execute(['--tenant' => '']);
    }
}
