<?php

namespace App\Controller;

use App\Entity\Adhesion;
use App\Entity\Categorie;
use App\Security\DroitsMembres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Changer de niveau (suiveur → full patch…) : clôt l'adhésion la veille de la date donnée et
 * ouvre la nouvelle à cette date, même association, en une seule transaction.
 *   POST /api/adhesions/{id}/changement   { "categorie": "full_patch", "date": "2027-04-01" }
 */
final class ChangementNiveauController extends AbstractController
{
    #[Route('/api/adhesions/{id}/changement', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function __invoke(string $id, Request $requete, EntityManagerInterface $em): JsonResponse
    {
        $donnees = json_decode($requete->getContent(), true) ?: [];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($donnees['date'] ?? ''));
        $categorie = $em->find(Categorie::class, (string) ($donnees['categorie'] ?? ''));
        $ancienne = Uuid::isValid($id) ? $em->find(Adhesion::class, Uuid::fromString($id)) : null;
        if (!$ancienne) {
            return $this->json(['detail' => 'Adhésion inconnue.'], 404);
        }
        if (!$date || !$categorie) {
            return $this->json(['detail' => 'Attendu : categorie (code) et date (AAAA-MM-JJ).'], 400);
        }
        $this->denyAccessUnlessGranted(DroitsMembres::ECRIRE_ADHESION, $ancienne);
        $periode = $ancienne->getPeriode();
        if ($categorie->getCode() === $ancienne->getCategorie()->getCode()) {
            return $this->json(['detail' => 'C\'est déjà le niveau de cette adhésion.'], 422);
        }
        if ($date <= $periode->debut || ($periode->fin && $date > $periode->fin)) {
            return $this->json(['detail' => sprintf('La date doit tomber après le début de l\'adhésion (%s)%s.',
                $periode->debut->format('d/m/Y'), $periode->fin ? ' et avant sa fin ('.$periode->fin->format('d/m/Y').')' : '')], 422);
        }

        $nouvelle = new Adhesion();
        $nouvelle->setMembre($ancienne->getMembre());
        $nouvelle->setAssociation($ancienne->getAssociation());
        $nouvelle->setCategorie($categorie);
        $nouvelle->setAuTitreDe($ancienne->getAuTitreDe());
        $nouvelle->setDebut($date);
        $nouvelle->setFin($periode->fin);
        $nouvelle->setExemption($ancienne->getExemption());  // l'exemption suit le changement de niveau
        $this->denyAccessUnlessGranted(DroitsMembres::ECRIRE_ADHESION, $nouvelle);
        if (!$nouvelle->isCategorieDuVolet()) {
            return $this->json(['detail' => 'Ce niveau n\'existe pas dans cette association (club ou événementiel).'], 422);
        }

        $em->wrapInTransaction(function () use ($em, $ancienne, $nouvelle, $date) {
            // D'abord clore l'ancienne, puis créer la nouvelle : la base refuse tout chevauchement.
            $ancienne->setFin($date->modify('-1 day'));
            $em->flush();
            $em->persist($nouvelle);
            $em->flush();
        });

        $decrire = fn (Adhesion $a) => ['id' => (string) $a->getId(), 'categorie' => $a->getCategorie()->getCode(),
            'debut' => $a->getDebut()?->format('Y-m-d'), 'fin' => $a->getFin()?->format('Y-m-d')];

        return $this->json(['ancienne' => $decrire($ancienne), 'nouvelle' => $decrire($nouvelle)], 201);
    }
}
