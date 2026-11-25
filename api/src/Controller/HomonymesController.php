<?php

namespace App\Controller;

use App\Security\DroitsMembres;
use App\Security\Visibilite;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Avant de créer une fiche : des fiches portent-elles déjà ce nom (et ce prénom) ? Sans accents ni
 * majuscules. Rend leur nombre, et celles que l'utilisateur voit ; des autres, rien d'autre.
 *   GET /api/homonymes?nom=Dupont&prenom=Jean   →   { "total": 2, "visibles": [{ id, nom, prenom, surnom }] }
 */
final class HomonymesController extends AbstractController
{
    #[Route('/api/homonymes', methods: ['GET'])]
    public function __invoke(Request $requete, Connection $base, Visibilite $visibilite): JsonResponse
    {
        $this->denyAccessUnlessGranted(DroitsMembres::ECRIRE_PERSONNE);
        $nom = trim((string) $requete->query->get('nom', ''));
        $prenom = trim((string) $requete->query->get('prenom', ''));
        if ('' === $nom) {
            return $this->json(['total' => 0, 'visibles' => []]);
        }
        $lignes = $base->fetchAllAssociative(
            "select id, nom, prenom, surnom from annuaire.personne
             where annuaire.normaliser(nom) = annuaire.normaliser(:nom)
               and (:prenom = '' or annuaire.normaliser(coalesce(prenom, '')) = annuaire.normaliser(:prenom))
             order by prenom limit 20",
            ['nom' => $nom, 'prenom' => $prenom]);

        return $this->json([
            'total' => count($lignes),
            'visibles' => array_values(array_filter($lignes, fn ($l) => $visibilite->voitPersonne($l['id']))),
        ]);
    }
}
