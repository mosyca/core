<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Mosyca\Core\Vault\Repository\OperatorRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * A Mosyca API operator (human user or automation account).
 *
 * Write access (create / password change) is intentionally CLI-only for V0.7.
 * REST endpoints expose read + clearance-patch + delete.
 */
#[ORM\Entity(repositoryClass: OperatorRepository::class)]
#[ORM\Table(name: 'mosyca_operator')]
#[ApiResource(
    shortName: 'VaultOperator',
    description: 'Mosyca Vault operators — accounts that authenticate against the API.',
    operations: [
        new GetCollection(uriTemplate: '/vault/operators'),
        new Get(uriTemplate: '/vault/operators/{id}'),
        new Patch(uriTemplate: '/vault/operators/{id}'),
        new Delete(uriTemplate: '/vault/operators/{id}'),
    ],
    normalizationContext: ['groups' => ['operator:read']],
    denormalizationContext: ['groups' => ['operator:write']],
)]
class Operator implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['operator:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Groups(['operator:read'])]
    private string $username;

    /** Never exposed via the API. */
    #[ORM\Column]
    #[Ignore]
    private string $password;

    /** Built-in or custom clearance name (e.g. 'admin', 'operator', 'readonly'). */
    #[ORM\Column(length: 50)]
    #[Groups(['operator:read', 'operator:write'])]
    private string $clearance;

    #[ORM\Column]
    #[Groups(['operator:read'])]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, McpToken> */
    #[ORM\OneToMany(targetEntity: McpToken::class, mappedBy: 'operator', cascade: ['remove'])]
    private Collection $mcpTokens;

    public function __construct(string $username, string $clearance)
    {
        $this->username = $username;
        $this->clearance = $clearance;
        $this->createdAt = new \DateTimeImmutable();
        $this->mcpTokens = new ArrayCollection();
        $this->password = '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->username, 'Operator username must not be empty.');

        return $this->username;
    }

    public function getRoles(): array
    {
        return ['ROLE_MOSYCA_OPERATOR'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getClearance(): string
    {
        return $this->clearance;
    }

    public function setClearance(string $clearance): void
    {
        $this->clearance = $clearance;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, McpToken> */
    public function getMcpTokens(): Collection
    {
        return $this->mcpTokens;
    }

    public function eraseCredentials(): void
    {
    }
}
