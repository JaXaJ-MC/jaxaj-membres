<?php

namespace App\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Adhesion;
use App\Entity\Cotisation;
use App\Entity\Personne;
use App\Entity\Representation;
use App\Security\Visibilite;
use Doctrine\ORM\QueryBuilder;

/**
 * Listes de personnes, adhésions, cotisations et rôles limitées à ce que voit une gestion
 * d'association (voir Visibilite). Sans effet pour qui voit tout le monde.
 */
final class FiltreVisibilite implements QueryCollectionExtensionInterface
{
    public function __construct(private Visibilite $visibilite) {}

    public function applyToCollection(QueryBuilder $qb, QueryNameGeneratorInterface $noms, string $resourceClass,
        ?Operation $operation = null, array $context = []): void
    {
        $classes = [Personne::class, Adhesion::class, Cotisation::class, Representation::class];
        $perimetre = $this->visibilite->perimetre();
        if (null === $perimetre || !in_array($resourceClass, $classes, true)) {
            return;
        }
        if ([] === $perimetre) {
            $qb->andWhere('1 = 0');

            return;
        }
        $o = $qb->getRootAliases()[0];
        $p = $noms->generateParameterName('perimetre');
        match ($resourceClass) {
            Personne::class => $qb->andWhere(sprintf(
                '%1$s.id in (select identity(va.membre) from %2$s va where va.association in (:%4$s))
                 or %1$s.id in (select identity(vr.personne) from %3$s vr where vr.organisation in (:%4$s))',
                $o, Adhesion::class, Representation::class, $p)),
            Adhesion::class => $qb->andWhere("$o.association in (:$p)"),
            Cotisation::class => $qb->join("$o.adhesion", $j = $noms->generateJoinAlias('adhesion'))->andWhere("$j.association in (:$p)"),
            Representation::class => $qb->andWhere("$o.organisation in (:$p)"),
        };
        $qb->setParameter($p, $perimetre);
    }
}
