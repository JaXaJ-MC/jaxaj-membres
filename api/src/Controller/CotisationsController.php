<?php

namespace App\Controller;

use App\Security\DroitsMembres;
use App\Security\Visibilite;
use App\Service\CalculCotisations;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Cotisations d'une personne avec ce qui en est réglé, et calcul depuis ses adhésions et les tarifs.
 *   GET  /api/personnes/{id}/cotisations
 *   POST /api/personnes/{id}/cotisations/calcul   { "regleesJusquau": "2025-12-31" | null, "simuler": true }
 */
final class CotisationsController extends AbstractController
{
    #[Route('/api/personnes/{id}/cotisations', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function etat(string $id, Connection $base, Visibilite $visibilite): JsonResponse
    {
        $this->denyAccessUnlessGranted(DroitsMembres::LIRE);
        if (!$visibilite->voitPersonne($id)) {
            throw $this->createAccessDeniedException();
        }
        $lignes = $base->fetchAllAssociative(
            "select c.id, c.adhesion_id as adhesion, lower(c.periode) as debut, upper(c.periode) - 1 as fin,
                    c.montant, c.gratuite, c.motif_gratuite as \"motifGratuite\", c.calculee,
                    coalesce(sum(r.montant), 0) as regle,
                    bool_or(p.reference = :h) as historique
             from adhesion.cotisation c
             join adhesion.adhesion a on a.id = c.adhesion_id
             left join adhesion.reglement r on r.cotisation_id = c.id
             left join adhesion.paiement p on p.id = r.paiement_id
             where a.membre_id = :m
             group by c.id order by lower(c.periode) desc",
            ['m' => $id, 'h' => CalculCotisations::HISTORIQUE]);

        return $this->json(array_map(fn ($l) => $l + [
            'aJour' => $l['gratuite'] || (float) $l['regle'] >= (float) $l['montant'],
        ], $lignes));
    }

    #[Route('/api/personnes/{id}/cotisations/calcul', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function calcul(string $id, Request $requete, CalculCotisations $calcul, Connection $base, Visibilite $visibilite): JsonResponse
    {
        // Administration, ou capacité « historique » pour les personnes qu'on voit : seulement les
        // adhésions de ses associations.
        $associations = $visibilite->associationsPour('historique');
        if (!$this->isGranted(DroitsMembres::ADMIN) && ([] === $associations || !$visibilite->voitPersonne($id))) {
            throw $this->createAccessDeniedException();
        }
        $donnees = json_decode($requete->getContent(), true) ?: [];
        $jusquau = null;
        if (!empty($donnees['regleesJusquau'])) {
            $jusquau = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $donnees['regleesJusquau'], 0, 10)) ?: null;
            if (!$jusquau) {
                return $this->json(['detail' => 'regleesJusquau : date attendue (AAAA-MM-JJ).'], 400);
            }
        }
        if (!Uuid::isValid($id) || !$base->fetchOne('select 1 from annuaire.personne where id = :id', ['id' => $id])) {
            return $this->json(['detail' => 'Personne inconnue.'], 404);
        }

        return $this->json($calcul->calculer($id, $jusquau, (bool) ($donnees['simuler'] ?? false), $associations));
    }
}
