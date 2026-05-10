<?php

declare(strict_types=1);

namespace Mosyca\Core\Depot\Controller;

use Mosyca\Core\Depot\DepotInterface;
use Mosyca\Core\Vault\Entity\Operator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * REST API for Depot management.
 *
 * All endpoints are scoped to the authenticated operator — no cross-operator access.
 *
 * GET    /api/depot                     — list keys
 * GET    /api/depot/{key}               — fetch an entry
 * DELETE /api/depot/{key}               — delete an entry
 * DELETE /api/depot?older-than=<sec>    — purge old entries
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/api/depot', name: 'mosyca_depot_')]
final class DepotController extends AbstractController
{
    public function __construct(private readonly ?DepotInterface $depot = null)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[CurrentUser] Operator $operator): JsonResponse
    {
        if (null === $this->depot) {
            return $this->notConfigured();
        }

        $keys = $this->depot->listKeys($operator->getUsername());

        return $this->json(['keys' => $keys, 'count' => \count($keys)]);
    }

    #[Route('/{key}', name: 'get', methods: ['GET'], requirements: ['key' => '.+'])]
    public function get(string $key, #[CurrentUser] Operator $operator): JsonResponse
    {
        if (null === $this->depot) {
            return $this->notConfigured();
        }

        // Enforce operator scope — key must start with the operator's prefix
        if (!str_starts_with($key, $operator->getUsername().'/')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = $this->depot->get($key);
        if (null === $data) {
            return $this->json(['error' => 'Key not found or expired.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['key' => $key, 'data' => $data]);
    }

    #[Route('', name: 'purge', methods: ['DELETE'])]
    public function purge(Request $request, #[CurrentUser] Operator $operator): JsonResponse
    {
        if (null === $this->depot) {
            return $this->notConfigured();
        }

        $olderThan = $request->query->get('older-than');
        $maxAge = is_numeric($olderThan) ? (int) $olderThan : 0;

        $deleted = $this->depot->purgeOlderThan($maxAge);

        return $this->json(['deleted' => $deleted]);
    }

    #[Route('/{key}', name: 'delete', methods: ['DELETE'], requirements: ['key' => '.+'])]
    public function delete(string $key, #[CurrentUser] Operator $operator): JsonResponse
    {
        if (null === $this->depot) {
            return $this->notConfigured();
        }

        // Enforce operator scope
        if (!str_starts_with($key, $operator->getUsername().'/')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $this->depot->delete($key);

        return $this->json(['deleted' => $key]);
    }

    private function notConfigured(): JsonResponse
    {
        return $this->json(
            ['error' => 'Depot is not configured on this installation.'],
            Response::HTTP_NOT_IMPLEMENTED,
        );
    }
}
