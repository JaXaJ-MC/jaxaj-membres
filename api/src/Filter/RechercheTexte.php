<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Recherche « q » : chaque mot doit apparaître dans la colonne recherche de la personne
 * (nom, prénom et surnom, sans accents ni majuscules, calculée par PostgreSQL).
 */
final class RechercheTexte implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $texte = $context['parameter']->getValue();
        if (!is_string($texte)) {
            return;
        }
        $normalise = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')->transliterate($texte);
        $alias = $queryBuilder->getRootAliases()[0];
        foreach (preg_split('/[^a-z0-9]+/', $normalise, -1, PREG_SPLIT_NO_EMPTY) as $mot) {
            $p = $queryNameGenerator->generateParameterName('mot');
            $queryBuilder->andWhere("$alias.recherche LIKE :$p")->setParameter($p, '%'.$mot.'%');
        }
    }
}
