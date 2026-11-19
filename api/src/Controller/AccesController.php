<?php

namespace App\Controller;

use App\Security\DroitsMembres;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Contrôle d'accès à l'entrée : la personne a-t-elle, ce jour-là, une cotisation réglée ou
 * gratuite (auprès des associations données, toutes sinon) ?
 *   GET /api/personnes/{id}/acces?jour=2027-03-13&association=<uuid>  (ou association[]=… répété)
 */
final class AccesController extends AbstractController
{
    #[Route('/api/personnes/{id}/acces', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted(DroitsMembres::LIRE)]
    public function __invoke(string $id, Request $requete, Connection $base): JsonResponse
    {
        $jour = $requete->query->get('jour', date('Y-m-d'));
        // ?association=<uuid>, répétable sous la forme ?association[]=<uuid>&association[]=<uuid>
        $valeur = $requete->query->all()['association'] ?? [];
        $associations = array_values(array_filter(is_array($valeur) ? $valeur : [$valeur],
            fn ($v) => is_string($v) && Uuid::isValid($v)));
        if (!Uuid::isValid($id) || !\DateTimeImmutable::createFromFormat('!Y-m-d', $jour)) {
            return $this->json(['detail' => 'Identifiant ou date (AAAA-MM-JJ) invalide.'], 400);
        }
        if (!$base->fetchOne('select 1 from annuaire.personne where id = :id', ['id' => $id])) {
            return $this->json(['detail' => 'Personne inconnue.'], 404);
        }
        $filtre = $associations ? 'and association_id = any(cast(:assos as uuid[]))' : '';
        $cotisations = $base->fetchAllAssociative(
            "select c.id, c.association_id as association, c.categorie, lower(c.periode) as debut,
                    upper(c.periode) - 1 as fin, c.montant, c.regle, c.gratuite, c.a_jour
             from adhesion.cotisation_etat c
             where c.membre_id = :id and c.periode @> cast(:jour as date) $filtre",
            ['id' => $id, 'jour' => $jour, 'assos' => '{'.implode(',', $associations).'}']);

        return $this->json([
            'personne' => $id,
            'jour' => $jour,
            'acces' => (bool) array_filter($cotisations, fn ($c) => $c['a_jour']),
            'cotisations' => $cotisations,
        ]);
    }
}
