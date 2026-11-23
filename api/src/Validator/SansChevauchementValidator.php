<?php

namespace App\Validator;

use App\Entity\Adhesion;
use App\Entity\Representation;
use Doctrine\DBAL\Connection;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class SansChevauchementValidator extends ConstraintValidator
{
    private const ROLES = ['president' => 'président(e)', 'vice_president' => 'vice-président(e)', 'tresorier' => 'trésorier(ère)',
        'secretaire' => 'secrétaire', 'sergent_armes' => 'sergent d\'armes', 'chef_de_file' => 'chef de file'];

    public function __construct(private Connection $base) {}

    public function validate(mixed $valeur, Constraint $contrainte): void
    {
        if ($valeur instanceof Representation) {
            $this->role($valeur);
        } elseif ($valeur instanceof Adhesion) {
            $this->adhesion($valeur);
        }
    }

    private static function plage(?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin): ?string
    {
        return $debut && (!$fin || $fin >= $debut)
            ? '['.$debut->format('Y-m-d').','.($fin ? $fin->modify('+1 day')->format('Y-m-d') : '').')' : null;
    }

    private static function dates(array $l): string
    {
        $debut = (new \DateTimeImmutable($l['debut']))->format('d/m/Y');

        return $l['fin'] ? "du $debut au ".(new \DateTimeImmutable($l['fin']))->format('d/m/Y') : "depuis le $debut, sans date de fin";
    }

    private function role(Representation $r): void
    {
        $plage = self::plage($r->getDebut(), $r->getFin());
        if (!$plage || !$r->getOrganisation() || !isset(self::ROLES[$r->getRole()])) {
            return;  // représentant : plusieurs à la fois
        }
        $autre = $this->base->fetchAssociative(
            "select concat_ws(' ', p.prenom, p.nom) as nom, p.surnom, lower(r.periode) as debut, upper(r.periode) - 1 as fin
             from annuaire.representation r join annuaire.personne p on p.id = r.personne_id
             where r.organisation_id = :o and r.role = :role and r.periode && cast(:plage as daterange) and r.id <> :id
             order by lower(r.periode) limit 1",
            ['o' => (string) $r->getOrganisation()->getId(), 'role' => $r->getRole(), 'plage' => $plage, 'id' => (string) $r->getId()]);
        if ($autre) {
            $this->context->buildViolation(sprintf(
                'Un seul %s à la fois à %s : %s%s l\'est déjà %s. Ajustez les dates de l\'un des deux mandats (le crayon sur sa fiche).',
                self::ROLES[$r->getRole()], $r->getOrganisation()->getNom(), $autre['nom'],
                $autre['surnom'] ? ' « '.$autre['surnom'].' »' : '', self::dates($autre)))->addViolation();
        }
    }

    private function adhesion(Adhesion $a): void
    {
        $plage = self::plage($a->getDebut(), $a->getFin());
        if (!$plage || !$a->getMembre() || !$a->getAssociation()) {
            return;
        }
        $autre = $this->base->fetchAssociative(
            'select k.libelle, lower(a.periode) as debut, upper(a.periode) - 1 as fin
             from adhesion.adhesion a join adhesion.categorie k on k.code = a.categorie
             where a.membre_id = :m and a.association_id = :asso and a.periode && cast(:plage as daterange) and a.id <> :id
             order by lower(a.periode) limit 1',
            ['m' => (string) $a->getMembre()->getId(), 'asso' => (string) $a->getAssociation()->getId(), 'plage' => $plage, 'id' => (string) $a->getId()]);
        if ($autre) {
            $this->context->buildViolation(sprintf(
                'Cette personne a déjà une adhésion %s à %s %s. Corrigez ses dates (le crayon) ou choisissez d\'autres dates.',
                $autre['libelle'], $a->getAssociation()->getNom(), self::dates($autre)))->addViolation();
        }
    }
}
