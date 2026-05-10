<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Mosyca\Core\Vault\Entity\McpToken;
use Mosyca\Core\Vault\Entity\Operator;
use Mosyca\Core\Vault\Repository\McpTokenRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * REST endpoints for Vault management that require custom logic
 * beyond what API Platform's standard CRUD provides.
 */
final class VaultController extends AbstractController
{
    /**
     * Generate a long-lived MCP Bearer token for the current operator.
     *
     * POST /api/vault/mcp-tokens/generate
     * Body: { "name": "Claude Desktop – Laptop", "ttlDays": 365 }
     *
     * The raw JWT is returned **once** here. It is not stored and cannot be retrieved later.
     */
    #[Route('/api/vault/mcp-tokens/generate', name: 'mosyca_vault_mcp_token_generate', methods: ['POST'])]
    public function generateMcpToken(
        Request $request,
        #[CurrentUser] Operator $operator,
        JWTTokenManagerInterface $tokenManager,
        EntityManagerInterface $em,
    ): JsonResponse {
        $data = $request->getPayload();
        $name = $data->getString('name');
        $ttlDays = $data->getInt('ttlDays', 365);

        if ('' === $name) {
            throw new BadRequestHttpException('"name" is required.');
        }
        if ($ttlDays < 1 || $ttlDays > 3650) {
            throw new BadRequestHttpException('"ttlDays" must be between 1 and 3650.');
        }

        $expiresAt = new \DateTimeImmutable(\sprintf('+%d days', $ttlDays));
        $jti = bin2hex(random_bytes(16));

        $jwt = $tokenManager->createFromPayload($operator, [
            'jti' => $jti,
            'token_name' => $name,
            'exp' => $expiresAt->getTimestamp(),
        ]);

        $token = new McpToken($operator, $name, $jti, $expiresAt);
        $em->persist($token);
        $em->flush();

        return $this->json([
            'token' => $jwt,
            'name' => $name,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::RFC3339),
            'tokenId' => $token->getId(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Soft-revoke an MCP token (sets revoked=true, does not delete).
     *
     * POST /api/vault/mcp-tokens/{id}/revoke
     */
    #[Route('/api/vault/mcp-tokens/{id}/revoke', name: 'mosyca_vault_mcp_token_revoke', methods: ['POST'])]
    public function revokeMcpToken(
        int $id,
        McpTokenRepository $repo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $token = $repo->find($id);

        if (null === $token) {
            throw $this->createNotFoundException(\sprintf('Token #%d not found.', $id));
        }

        if ($token->isRevoked()) {
            return $this->json(['message' => 'Token was already revoked.'], Response::HTTP_OK);
        }

        $token->revoke();
        $em->flush();

        return $this->json(['message' => \sprintf('Token #%d revoked.', $id)], Response::HTTP_OK);
    }
}
