<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Calcule toutes les cotisations d'une personne d'après ses adhésions et les tarifs.
 *
 * Pour chaque adhésion, de son début à sa fin (au plus tard le mois en cours) : le tarif en
 * vigueur pour sa catégorie et son association donne la période due, l'année civile pour un
 * tarif annuel, le mois sinon (l'annuel l'emporte si les deux existent). Une période déjà
 * couverte par une cotisation de la personne est laissée telle quelle ; sans tarif, elle est
 * signalée.
 *
 * Les cotisations créées sont marquées « calculée » : un nouveau calcul les remplace, sauf
 * celles qu'un vrai paiement a réglées. En option, celles qui se terminent au plus tard à une
 * date donnée sont considérées comme réglées par un paiement « Historique reconstitué », que les
 * statistiques peuvent écarter.
 */
final class CalculCotisations
{
    public const HISTORIQUE = 'Historique reconstitué';

    public function __construct(private readonly Connection $base) {}

    /**
     * @return array{creees: int, total: string, reglees: int, conservees: int, sans_tarif: list<string>}
     */
    public function calculer(string $personne, ?\DateTimeImmutable $regleesJusquau, bool $simuler): array
    {
        $this->base->beginTransaction();
        try {
            $resultat = $this->executer($personne, $regleesJusquau);
            $simuler ? $this->base->rollBack() : $this->base->commit();

            return $resultat;
        } catch (\Throwable $e) {
            $this->base->rollBack();
            throw $e;
        }
    }

    private function executer(string $personne, ?\DateTimeImmutable $regleesJusquau): array
    {
        $b = $this->base;
        // 1. Retirer le calcul précédent, sauf ce qu'un vrai paiement a réglé.
        $historiques = $b->fetchFirstColumn(
            'select distinct p.id from adhesion.paiement p join adhesion.reglement r on r.paiement_id = p.id
             join adhesion.cotisation c on c.id = r.cotisation_id join adhesion.adhesion a on a.id = c.adhesion_id
             where a.membre_id = :m and c.calculee and p.reference = :h', ['m' => $personne, 'h' => self::HISTORIQUE]);
        $b->executeStatement(
            'delete from adhesion.cotisation c using adhesion.adhesion a
             where a.id = c.adhesion_id and a.membre_id = :m and c.calculee
               and not exists (select 1 from adhesion.reglement r join adhesion.paiement p on p.id = r.paiement_id
                               where r.cotisation_id = c.id and p.reference is distinct from :h)',
            ['m' => $personne, 'h' => self::HISTORIQUE]);
        if ($historiques) {
            $b->executeStatement('delete from adhesion.paiement p where p.id = any (cast(:ids as uuid[]))
                and not exists (select 1 from adhesion.reglement r where r.paiement_id = p.id)',
                ['ids' => '{'.implode(',', $historiques).'}']);
        }

        // 2. Parcourir les adhésions, la plus ancienne d'abord : un mois de passage d'une adhésion
        //    à l'autre est dû au titre de la première.
        $limite = new \DateTimeImmutable('last day of this month');
        $resultat = ['creees' => 0, 'total' => 0.0, 'reglees' => 0, 'conservees' => 0, 'sans_tarif' => []];
        $adhesions = $b->fetchAllAssociative(
            'select id, association_id, categorie, lower(periode) as debut, upper(periode) - 1 as fin
             from adhesion.adhesion where membre_id = :m order by lower(periode)', ['m' => $personne]);
        foreach ($adhesions as $a) {
            $fin = $a['fin'] ? min(new \DateTimeImmutable($a['fin']), $limite) : $limite;
            $jour = new \DateTimeImmutable($a['debut']);
            while ($jour <= $fin) {
                $tarif = $b->fetchAssociative(
                    "select id, montant, periodicite from adhesion.tarif
                     where categorie = :c and periodicite in ('mensuelle', 'annuelle') and validite @> cast(:j as date)
                       and (association_id = :a or association_id is null)
                     order by association_id nulls last, periodicite = 'annuelle' desc limit 1",
                    ['c' => $a['categorie'], 'j' => $jour->format('Y-m-d'), 'a' => $a['association_id']]);
                $annuelle = 'annuelle' === ($tarif['periodicite'] ?? null);
                $debut = $jour->modify($annuelle ? 'first day of january' : 'first day of this month');
                $finPeriode = $annuelle ? $jour->modify('last day of december') : $jour->modify('last day of this month');
                $jour = $finPeriode->modify('+1 day');
                if (!$tarif) {
                    $resultat['sans_tarif'][] = $a['categorie'].' '.$debut->format('m/Y');
                    continue;
                }
                $deja = $b->fetchOne(
                    'select 1 from adhesion.cotisation c join adhesion.adhesion a on a.id = c.adhesion_id
                     where a.membre_id = :m and c.periode && daterange(cast(:d as date), cast(:f as date), \'[]\')',
                    ['m' => $personne, 'd' => $debut->format('Y-m-d'), 'f' => $finPeriode->format('Y-m-d')]);
                if ($deja) {
                    ++$resultat['conservees'];
                    continue;
                }
                $cotisation = (string) Uuid::v7();
                $b->executeStatement(
                    "insert into adhesion.cotisation (id, adhesion_id, periode, montant, tarif_id, calculee)
                     values (:id, :a, daterange(cast(:d as date), cast(:f as date), '[]'), :montant, :t, true)",
                    ['id' => $cotisation, 'a' => $a['id'], 'd' => $debut->format('Y-m-d'), 'f' => $finPeriode->format('Y-m-d'),
                     'montant' => $tarif['montant'], 't' => $tarif['id']]);
                ++$resultat['creees'];
                $resultat['total'] += (float) $tarif['montant'];
                if ($regleesJusquau && $finPeriode <= $regleesJusquau && (float) $tarif['montant'] > 0) {
                    $paiement = (string) Uuid::v7();
                    $b->insert('adhesion.paiement', ['id' => $paiement, 'payeur_id' => $personne,
                        'encaisse_par' => $a['association_id'], 'montant' => $tarif['montant'],
                        'date_paiement' => $debut->format('Y-m-d'), 'moyen' => 'autre', 'reference' => self::HISTORIQUE]);
                    $b->insert('adhesion.reglement', ['paiement_id' => $paiement, 'cotisation_id' => $cotisation, 'montant' => $tarif['montant']]);
                    ++$resultat['reglees'];
                }
            }
        }
        $resultat['total'] = number_format($resultat['total'], 2, '.', '');
        $resultat['sans_tarif'] = $this->regrouper($resultat['sans_tarif']);

        return $resultat;
    }

    /** « full_patch 01/2009 », « full_patch 02/2009 »… → « full_patch 01/2009 → 12/2014 ». */
    private function regrouper(array $mois): array
    {
        $plages = [];
        foreach ($mois as $m) {
            [$categorie, $date] = explode(' ', $m);
            $dernier = array_key_last($plages);
            $suivant = null !== $dernier ? \DateTimeImmutable::createFromFormat('!m/Y', $plages[$dernier][2])->modify('+1 month')->format('m/Y') : null;
            if (null !== $dernier && $plages[$dernier][0] === $categorie && $suivant === $date) {
                $plages[$dernier][2] = $date;
            } else {
                $plages[] = [$categorie, $date, $date];
            }
        }

        return array_map(fn ($p) => $p[1] === $p[2] ? "$p[0] $p[1]" : "$p[0] $p[1] → $p[2]", $plages);
    }
}
