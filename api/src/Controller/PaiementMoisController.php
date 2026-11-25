<?php

namespace App\Controller;

use App\Entity\Adhesion;
use App\Entity\Cotisation;
use App\Security\DroitsMembres;
use App\Service\EtatDuMois;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * « Payé ce mois-ci » : crée si besoin la cotisation du mois (au tarif en vigueur) sur
 * l'adhésion principale de la personne, puis enregistre le paiement du reste dû.
 *   POST /api/personnes/{id}/paiement-mois   { "mois": "2026-11", "moyen": "especes" }
 */
final class PaiementMoisController extends AbstractController
{
    private const MOYENS = ['especes', 'cheque', 'virement', 'carte', 'en_ligne', 'autre'];

    #[Route('/api/personnes/{id}/paiement-mois', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function __invoke(string $id, Request $requete, EtatDuMois $etat, EntityManagerInterface $em): JsonResponse
    {
        $donnees = json_decode($requete->getContent(), true) ?: [];
        $mois = \DateTimeImmutable::createFromFormat('!Y-m', (string) ($donnees['mois'] ?? date('Y-m')));
        $moyen = $donnees['moyen'] ?? 'especes';
        if (!Uuid::isValid($id) || !$mois || !in_array($moyen, self::MOYENS, true)) {
            return $this->json(['detail' => 'Attendu : mois (AAAA-MM) et moyen ('.implode(', ', self::MOYENS).').'], 400);
        }
        $ligne = $etat->liste($mois, $id)[0] ?? null;
        if (!$ligne) {
            return $this->json(['detail' => 'Personne inconnue.'], 404);
        }
        if (!$ligne['adhesion']) {
            return $this->json(['detail' => 'Aucune adhésion en cours ce mois-là : créer d\'abord une adhésion.'], 422);
        }
        $adhesion = $em->find(Adhesion::class, Uuid::fromString($ligne['adhesion']));
        $this->denyAccessUnlessGranted(DroitsMembres::ENCAISSER, $adhesion);

        $em->wrapInTransaction(function () use ($em, $adhesion, $mois, $moyen, $id) {
            $base = $em->getConnection();
            $debut = $mois->format('Y-m-01');
            $cotisation = $base->fetchOne('select id from adhesion.cotisation where adhesion_id = :a and periode @> cast(:d as date)',
                ['a' => (string) $adhesion->getId(), 'd' => $debut]);
            if (!$cotisation) {
                $c = new Cotisation();
                $c->setAdhesion($adhesion);
                $c->setDebut(new \DateTimeImmutable($debut));  // couvre le mois entier, au tarif en vigueur
                $em->persist($c);
                $em->flush();
                $cotisation = (string) $c->getId();
            }
            $e = $base->fetchAssociative('select montant, regle, gratuite, a_jour from adhesion.cotisation_etat where id = :id', ['id' => $cotisation]);
            if ($e['gratuite'] || $e['a_jour']) {
                throw new \DomainException('La cotisation de ce mois est déjà réglée.');
            }
            $reste = number_format((float) $e['montant'] - (float) $e['regle'], 2, '.', '');
            $paiement = (string) Uuid::v7();
            $base->insert('adhesion.paiement', ['id' => $paiement, 'payeur_id' => $id,
                'encaisse_par' => (string) $adhesion->getAssociation()->getId(), 'montant' => $reste,
                'date_paiement' => date('Y-m-d'), 'moyen' => $moyen]);
            $base->insert('adhesion.reglement', ['paiement_id' => $paiement, 'cotisation_id' => $cotisation, 'montant' => $reste]);
        });

        return $this->json($etat->liste($mois, $id)[0], 201);
    }
}
