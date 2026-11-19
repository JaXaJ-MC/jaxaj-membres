<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/** Égalité sur l'UUID d'une relation, éventuellement à travers une jointure (« adhesion.membre »). */
final class Egal implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function __construct(private string $chemin) {}

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $valeur = $context['parameter']->getValue();
        if (!is_string($valeur)) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0];
        $parties = explode('.', $this->chemin);
        $champ = array_pop($parties);
        foreach ($parties as $relation) {
            $jointure = $queryNameGenerator->generateJoinAlias($relation);
            $queryBuilder->join("$alias.$relation", $jointure);
            $alias = $jointure;
        }
        $p = $queryNameGenerator->generateParameterName($champ);
        if (!Uuid::isValid($valeur)) {
            $queryBuilder->andWhere('1 = 0');  // UUID invalide : aucun résultat

            return;
        }
        $queryBuilder->andWhere("IDENTITY($alias.$champ) = :$p")->setParameter($p, $valeur);
    }
}
