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
 * couverte par une cotisation de la personne dans le même volet (club ou événementiel) est
 * laissée telle quelle (une cotisation gratuite ne couvre rien pour les autres adhésions) ; sans
 * tarif, elle est signalée, sauf pour une adhésion exemptée. Seules les catégories à cotisation périodique sont calculées ; une adhésion exemptée
 * reçoit des cotisations gratuites portant le motif de l'exemption.
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
     * @return array{creees: int, total: string, reglees: int, exemptees: int, conservees: int,
     *               sans_tarif: list<array{association: string, categorie: string, debut: string, fin: string}>}
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
        $resultat = ['creees' => 0, 'total' => 0.0, 'reglees' => 0, 'exemptees' => 0, 'conservees' => 0, 'sans_tarif' => []];
        $adhesions = $b->fetchAllAssociative(
            "select a.id, a.association_id, a.categorie, a.exemption, k.volet, lower(a.periode) as debut, upper(a.periode) - 1 as fin
             from adhesion.adhesion a join adhesion.categorie k on k.code = a.categorie
             where a.membre_id = :m and k.cotisation = 'periodique' order by lower(a.periode)", ['m' => $personne]);
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
                    if (!$a['exemption']) {  // exempté : rien à payer, le tarif manquant ne gêne pas
                        $resultat['sans_tarif'][] = [$a['association_id'], $a['categorie'], $debut->format('Y-m')];
                    }
                    continue;
                }
                // Période déjà couverte : par cette adhésion, ou, pour une adhésion qui paie, par une
                // cotisation payante d'une autre adhésion du même volet (on ne cotise qu'une fois au club).
                // Une cotisation gratuite (exemption) ne couvre rien pour les autres.
                $deja = $b->fetchOne(
                    'select 1 from adhesion.cotisation c join adhesion.adhesion a on a.id = c.adhesion_id
                     join adhesion.categorie k on k.code = a.categorie
                     where c.periode && daterange(cast(:d as date), cast(:f as date), \'[]\')
                       and (a.id = :adh or (not cast(:exempte as boolean) and a.membre_id = :m and k.volet = :v and not c.gratuite))',
                    ['adh' => $a['id'], 'exempte' => $a['exemption'] ? 'true' : 'false', 'm' => $personne, 'v' => $a['volet'],
                     'd' => $debut->format('Y-m-d'), 'f' => $finPeriode->format('Y-m-d')]);
                if ($deja) {
                    ++$resultat['conservees'];
                    continue;
                }
                $cotisation = (string) Uuid::v7();
                $b->executeStatement(
                    "insert into adhesion.cotisation (id, adhesion_id, periode, montant, tarif_id, gratuite, motif_gratuite, calculee)
                     values (:id, :a, daterange(cast(:d as date), cast(:f as date), '[]'), :montant, :t, :g, :motif, true)",
                    ['id' => $cotisation, 'a' => $a['id'], 'd' => $debut->format('Y-m-d'), 'f' => $finPeriode->format('Y-m-d'),
                     'montant' => $a['exemption'] ? '0' : $tarif['montant'], 't' => $tarif['id'],
                     'g' => $a['exemption'] ? 'true' : 'false', 'motif' => $a['exemption']]);
                ++$resultat['creees'];
                if ($a['exemption']) {
                    ++$resultat['exemptees'];
                    continue;
                }
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

    /** Mois sans tarif regroupés en plages : même association, même catégorie, mois consécutifs. */
    private function regrouper(array $mois): array
    {
        $plages = [];
        foreach ($mois as [$association, $categorie, $m]) {
            $d = array_key_last($plages);
            $suivant = null !== $d ? (new \DateTimeImmutable($plages[$d]['fin'].'-01'))->modify('+1 month')->format('Y-m') : null;
            if (null !== $d && $plages[$d]['association'] === $association && $plages[$d]['categorie'] === $categorie && $suivant === $m) {
                $plages[$d]['fin'] = $m;
            } else {
                $plages[] = ['association' => $association, 'categorie' => $categorie, 'debut' => $m, 'fin' => $m];
            }
        }

        return $plages;
    }
}
