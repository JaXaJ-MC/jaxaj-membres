<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Pas de chevauchement de périodes, avec un message qui nomme ce qui gêne : le mandat d'un autre
 * titulaire du même rôle (rôles), l'autre adhésion de la personne à la même association
 * (adhésions). PostgreSQL garantit la règle ; ceci dit pourquoi.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SansChevauchement extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
