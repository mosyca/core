<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Identity\Provider;

use Mosyca\Core\Identity\Dto\TenantDto;
use Mosyca\Core\Identity\Provider\YamlTenantProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rule ID1 — Fallback Provider Integrity (ADR 1.5).
 *
 * Verifies that YamlTenantProvider correctly translates the compiled
 * configuration array into immutable TenantDto objects.
 */
final class YamlTenantProviderTest extends TestCase
{
    /** @var array<string, array{name: string, metadata?: array<string, mixed>}> */
    private array $fixture = [
        'demecan_gmbh' => ['name' => 'Demecan GmbH', 'metadata' => ['region' => 'EU']],
        'theranova_digital' => ['name' => 'Theranova Digital Solutions'],
    ];

    public function testHydratesTenantDtoFromConfig(): void
    {
        $provider = new YamlTenantProvider($this->fixture);
        $dto = $provider->getTenant('demecan_gmbh');

        self::assertInstanceOf(TenantDto::class, $dto);
        self::assertSame('demecan_gmbh', $dto->slug);
        self::assertSame('Demecan GmbH', $dto->name);
        self::assertSame(['region' => 'EU'], $dto->metadata);
    }

    public function testHydratesTenantDtoWithEmptyMetadata(): void
    {
        $provider = new YamlTenantProvider($this->fixture);
        $dto = $provider->getTenant('theranova_digital');

        self::assertInstanceOf(TenantDto::class, $dto);
        self::assertSame('theranova_digital', $dto->slug);
        self::assertSame('Theranova Digital Solutions', $dto->name);
        self::assertSame([], $dto->metadata);
    }

    public function testReturnsNullForUnknownSlug(): void
    {
        $provider = new YamlTenantProvider($this->fixture);

        self::assertNull($provider->getTenant('does_not_exist'));
    }

    public function testGetTenantsReturnsList(): void
    {
        $provider = new YamlTenantProvider($this->fixture);
        $tenants = $provider->getTenants();

        self::assertCount(2, $tenants);

        $slugs = array_map(static fn (TenantDto $t): string => $t->slug, $tenants);
        self::assertContains('demecan_gmbh', $slugs);
        self::assertContains('theranova_digital', $slugs);
    }

    public function testGetTenantsRespectsOffset(): void
    {
        $provider = new YamlTenantProvider($this->fixture);
        $tenants = $provider->getTenants(offset: 1);

        self::assertCount(1, $tenants);
    }

    public function testGetTenantsRespectsLimit(): void
    {
        $provider = new YamlTenantProvider($this->fixture);
        $tenants = $provider->getTenants(limit: 1);

        self::assertCount(1, $tenants);
    }

    public function testGetTenantsReturnsEmptyArrayForEmptyConfig(): void
    {
        $provider = new YamlTenantProvider([]);

        self::assertSame([], $provider->getTenants());
    }

    public function testDtoIsReadonly(): void
    {
        $provider = new YamlTenantProvider($this->fixture);
        $dto = $provider->getTenant('demecan_gmbh');

        self::assertInstanceOf(TenantDto::class, $dto);

        // Verify readonly enforcement — writing to a readonly property throws an Error.
        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $dto->slug = 'modified'; // @phpstan-ignore-line
    }
}
