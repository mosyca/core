<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Mosyca\Core\Vault\Repository\OperatorRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: OperatorRepository::class)]
#[ORM\Table(name: 'mosyca_operator')]
class Operator implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $username;

    #[ORM\Column]
    private string $password;

    /** Built-in or custom clearance name (e.g. 'admin', 'operator', 'readonly'). */
    #[ORM\Column(length: 50)]
    private string $clearance;

    #[ORM\Column]
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
