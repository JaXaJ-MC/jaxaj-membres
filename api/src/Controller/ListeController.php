<?php

namespace App\Controller;

use App\Security\DroitsMembres;
use App\Service\EtatDuMois;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Liste complète des personnes, avec l'état de la cotisation du mois :
 *   GET /api/liste?mois=2026-11   (mois en cours par défaut)
 */
final class ListeController extends AbstractController
{
    #[Route('/api/liste', methods: ['GET'])]
    #[IsGranted(DroitsMembres::LIRE)]
    public function __invoke(Request $requete, EtatDuMois $etat): JsonResponse
    {
        $mois = \DateTimeImmutable::createFromFormat('!Y-m', (string) $requete->query->get('mois', date('Y-m')));
        if (!$mois) {
            return $this->json(['detail' => 'mois : AAAA-MM attendu.'], 400);
        }

        return $this->json(['mois' => $mois->format('Y-m'), 'personnes' => $etat->liste($mois)]);
    }
}
