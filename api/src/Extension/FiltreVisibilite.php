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
 * Listes de personnes, adhésions, cotisations et rôles limitées aux personnes que voit une
 * gestion d'association, avec tout leur historique (voir Visibilite). Sans effet pour qui voit tout le monde.
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
        // Personnes visibles ; leur historique (adhésions, cotisations, rôles) se voit avec elles.
        $visible = fn (string $personne) => sprintf(
            '%1$s in (select identity(va.membre) from %2$s va where va.association in (:%4$s))
             or %1$s in (select identity(vr.personne) from %3$s vr where vr.organisation in (:%4$s))',
            $personne, Adhesion::class, Representation::class, $p);
        match ($resourceClass) {
            Personne::class => $qb->andWhere($visible("$o.id")),
            Adhesion::class => $qb->andWhere($visible("identity($o.membre)")),
            Cotisation::class => $qb->join("$o.adhesion", $j = $noms->generateJoinAlias('adhesion'))->andWhere($visible("identity($j.membre)")),
            Representation::class => $qb->andWhere($visible("identity($o.personne)")),
        };
        $qb->setParameter($p, $perimetre);
    }
}
