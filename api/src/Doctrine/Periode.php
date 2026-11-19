<?php

namespace App\Doctrine;

/**
 * Période de dates, pour les colonnes daterange de PostgreSQL. Début et fin sont INCLUS ici ;
 * PostgreSQL stocke une fin exclue ([debut, fin+1 jour)). Fin vide : période ouverte.
 */
final class Periode
{
    public function __construct(
        public readonly ?\DateTimeImmutable $debut,
        public readonly ?\DateTimeImmutable $fin = null,
        bool $controler = true,  // false : saisie en cours (début puis fin), contrôlée par la validation
    ) {
        if ($controler && !$this->coherente()) {
            throw new \DomainException('La fin d\'une période précède son début.');  // 422 lisible
        }
    }

    public function coherente(): bool
    {
        return !$this->debut || !$this->fin || $this->fin >= $this->debut;
    }

    public function contient(\DateTimeImmutable $jour): bool
    {
        $jour = $jour->setTime(0, 0);

        return (!$this->debut || $jour >= $this->debut) && (!$this->fin || $jour <= $this->fin);
    }

    public function versPostgres(): string
    {
        return '['.$this->debut?->format('Y-m-d').','.$this->fin?->modify('+1 day')->format('Y-m-d').')';
    }

    public static function depuisPostgres(string $valeur): ?self
    {
        if ('empty' === $valeur) {
            return null;
        }
        if (!preg_match('/^([\[(])(\d{4}-\d{2}-\d{2})?,(\d{4}-\d{2}-\d{2})?([\])])$/', $valeur, $m)) {
            throw new \UnexpectedValueException("Période illisible : $valeur");
        }
        $debut = '' !== $m[2] ? new \DateTimeImmutable($m[2]) : null;
        $fin = ($m[3] ?? '') !== '' ? new \DateTimeImmutable($m[3]) : null;
        if ($debut && '(' === $m[1]) {
            $debut = $debut->modify('+1 day');
        }
        if ($fin && ')' === $m[4]) {
            $fin = $fin->modify('-1 day');
        }

        return new self($debut, $fin);
    }
}
