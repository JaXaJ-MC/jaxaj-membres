<?php

namespace App\EventListener;

use App\Entity\Cotisation;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

/**
 * Cotisation sans montant : le tarif en vigueur s'applique (fonction adhesion.tarif_applicable),
 * selon la catégorie de l'adhésion, l'association et la durée (un mois, un an, sinon ponctuelle).
 */
#[AsEntityListener(event: Events::prePersist, entity: Cotisation::class)]
final class TarifAutomatique
{
    public function prePersist(Cotisation $cotisation, PrePersistEventArgs $args): void
    {
        if ($cotisation->isGratuite()) {
            $cotisation->setMontant('0');

            return;
        }
        if (null !== $cotisation->getMontant() || !$cotisation->getPeriode()?->debut || !$cotisation->getAdhesion()) {
            return;
        }
        $p = $cotisation->getPeriode();
        $periodicite = match (true) {
            $p->fin == $p->debut->modify('+1 month -1 day') => 'mensuelle',
            $p->fin == $p->debut->modify('+1 year -1 day') => 'annuelle',
            default => 'ponctuelle',
        };
        $adhesion = $cotisation->getAdhesion();
        $tarif = $args->getObjectManager()->getConnection()->fetchAssociative(
            'select id, montant from adhesion.tarif_applicable(:categorie, :periodicite, :jour, :association) where id is not null',
            ['categorie' => $adhesion->getCategorie()->getCode(), 'periodicite' => $periodicite,
             'jour' => $p->debut->format('Y-m-d'), 'association' => (string) $adhesion->getAssociation()->getId()],
        );
        if (!$tarif) {
            throw new \DomainException(sprintf('Aucun tarif %s en vigueur le %s pour la catégorie « %s » : indiquer le montant.',
                $periodicite, $p->debut->format('d/m/Y'), $adhesion->getCategorie()->getCode()));
        }
        $cotisation->appliquerTarif(Uuid::fromString($tarif['id']), $tarif['montant']);
    }
}
