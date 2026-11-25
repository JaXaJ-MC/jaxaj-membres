<?php

namespace App\Controller;

use App\Security\DroitsMembres;
use App\Security\Visibilite;
use App\Service\EtatDuMois;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Complétude d'une fiche (voir Completude), comme dans la liste :
 *   GET /api/personnes/{id}/completude   →   { "manquants": ["telephone", …], "total": 8 }
 */
final class CompletudeController extends AbstractController
{
    #[Route('/api/personnes/{id}/completude', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function __invoke(string $id, EtatDuMois $etat, Visibilite $visibilite): JsonResponse
    {
        $this->denyAccessUnlessGranted(DroitsMembres::LIRE);
        if (!Uuid::isValid($id) || !$visibilite->voitPersonne($id)) {
            throw $this->createAccessDeniedException();
        }
        $ligne = $etat->liste(new \DateTimeImmutable(), $id)[0] ?? null;

        return $ligne ? $this->json($ligne['completude']) : $this->json(['detail' => 'Personne inconnue.'], 404);
    }
}
