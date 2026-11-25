<?php

namespace App\Controller;

use App\Security\DroitsMembres;
use App\Service\Doublons;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Fiches en double (administration) :
 *   GET  /api/doublons                         groupes de fiches qui se ressemblent
 *   POST /api/doublons/ecarter   { ids }       ce ne sont pas des doublons
 *   POST /api/personnes/{id}/fusion { absorber }  fusionne une fiche dans celle-ci
 */
final class DoublonsController extends AbstractController
{
    public function __construct(private Doublons $doublons) {}

    #[Route('/api/doublons', methods: ['GET'])]
    public function liste(): JsonResponse
    {
        $this->denyAccessUnlessGranted(DroitsMembres::ADMIN);

        return $this->json(array_map(fn ($g) => array_merge($g, ['fiches' => array_map(
            fn ($f) => array_merge($f, ['associations' => json_decode($f['associations'], true)]), $g['fiches'])]), $this->doublons->groupes()));
    }

    #[Route('/api/doublons/ecarter', methods: ['POST'])]
    public function ecarter(Request $requete): JsonResponse
    {
        $this->denyAccessUnlessGranted(DroitsMembres::ADMIN);
        $ids = (json_decode($requete->getContent(), true) ?: [])['ids'] ?? [];
        if (!is_array($ids) || count($ids) < 2 || array_filter($ids, fn ($i) => !is_string($i) || !Uuid::isValid($i))) {
            return $this->json(['detail' => 'ids : au moins deux identifiants de fiches.'], 400);
        }
        $this->doublons->ecarter(array_values(array_unique($ids)));

        return $this->json(null, 204);
    }

    #[Route('/api/personnes/{id}/fusion', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function fusionner(string $id, Request $requete): JsonResponse
    {
        $this->denyAccessUnlessGranted(DroitsMembres::ADMIN);
        $absorber = (json_decode($requete->getContent(), true) ?: [])['absorber'] ?? '';
        if (!Uuid::isValid($id) || !is_string($absorber) || !Uuid::isValid($absorber)) {
            return $this->json(['detail' => 'absorber : identifiant de la fiche à fusionner.'], 400);
        }
        $this->doublons->fusionner($id, $absorber);

        return $this->json(['id' => $id]);
    }
}
