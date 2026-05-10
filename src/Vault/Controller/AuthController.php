<?php

declare(strict_types=1);

namespace Mosyca\Core\Vault\Controller;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Mosyca\Core\Vault\Entity\Operator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Vault auth endpoints.
 */
final class AuthController extends AbstractController
{
    /**
     * Login endpoint — handled entirely by the json_login firewall.
     * This action is never reached; the route must exist so the router
     * does not throw 404 before the security layer can intercept.
     *
     * POST /api/auth/login
     * Body: { "username": "...", "password": "..." }
     */
    #[Route('/api/auth/login', name: 'mosyca_auth_login', methods: ['POST'])]
    public function login(): never
    {
        // Symfony's json_login firewall intercepts this route at priority 8
        // on kernel.request — the router (priority 32) resolves the route first,
        // then security takes over. This body is unreachable.
        throw new \LogicException('The json_login firewall must have intercepted this request.');
    }

    /**
     * Returns the currently authenticated operator's profile.
     *
     * GET /api/auth/me
     */
    #[Route('/api/auth/me', name: 'mosyca_auth_me', methods: ['GET'])]
    public function me(#[CurrentUser] Operator $operator): JsonResponse
    {
        return $this->json([
            'username' => $operator->getUserIdentifier(),
            'clearance' => $operator->getClearance(),
            'roles' => $operator->getRoles(),
            'createdAt' => $operator->getCreatedAt()->format(\DateTimeInterface::RFC3339),
        ]);
    }

    /**
     * Issues a fresh JWT for the current operator without re-authenticating.
     *
     * POST /api/auth/refresh
     */
    #[Route('/api/auth/refresh', name: 'mosyca_auth_refresh', methods: ['POST'])]
    public function refresh(
        #[CurrentUser] Operator $operator,
        JWTTokenManagerInterface $tokenManager,
    ): JsonResponse {
        return $this->json([
            'token' => $tokenManager->create($operator),
        ]);
    }
}
