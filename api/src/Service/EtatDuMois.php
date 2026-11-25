<?php

namespace App\Service;

use App\Security\Visibilite;
use Doctrine\DBAL\Connection;

/**
 * Liste des personnes avec, pour un mois donné, leur adhésion principale et l'état de la
 * cotisation de ce mois. Une seule requête : la liste complète tient en quelques dizaines de Ko.
 *
 * Adhésion principale : celle qui couvre une partie du mois, en préférant une catégorie du
 * club (full patch, suiveur…) au public, puis une adhésion qui paie à une adhésion exemptée
 * (membre de plusieurs chapitres, on cotise à un seul), puis la plus récente. C'est elle que
 * « Payé ce mois-ci » encaisse.
 */
final class EtatDuMois
{
    public const AUCUNE_ADHESION = 'aucune_adhesion';
    public const A_PAYER = 'a_payer';
    public const PARTIEL = 'partiel';
    public const PAYE = 'paye';
    public const GRATUIT = 'gratuit';
    public const SANS_TARIF = 'sans_tarif';

    public function __construct(private Connection $base, private Visibilite $visibilite) {}

    /** @return list<array> une ligne par personne, triée par nom puis prénom (sans accents) */
    public function liste(\DateTimeImmutable $mois, ?string $personne = null): array
    {
        $debut = $mois->modify('first day of this month')->setTime(0, 0);
        // Gestion d'une association : ses personnes, et d'elles seulement ce qui la concerne.
        [$visible, $parametres] = $this->visibilite->conditionPersonne('p.id');
        $perimetre = $this->visibilite->perimetre();
        $dansPerimetre = fn (string $colonne) => null === $perimetre ? 'true' : "$colonne = any (cast(:perimetre as uuid[]))";
        $sql = <<<'SQL'
            with m as (select cast(:debut as date) as d, cast(:fin as date) as f),
            adh as (
              select distinct on (a.membre_id) a.membre_id, a.id, a.association_id, a.categorie
              from adhesion.adhesion a
              join adhesion.categorie k on k.code = a.categorie
              cross join m
              where a.periode && daterange(m.d, m.f) and {asso_adh}
              order by a.membre_id, k.membre_club desc, a.exemption is null desc, lower(a.periode) desc
            ),
            derniere as (
              select membre_id, max(upper(periode)) - 1 as fin
              from adhesion.cotisation_etat where a_jour group by membre_id
            )
            select p.id, p.nom, p.prenom, p.surnom, p.type,
                   adh.id as adhesion, adh.association_id as association, adh.categorie,
                   ce.montant, ce.regle, ce.gratuite, ce.a_jour, t.montant as tarif,
                   derniere.fin as derniere_cotisation, ro.roles, ad.adhesions, fp.premier as premier_full_patch,
                   completude.manquants, completude.total
            from annuaire.personne p
            left join adh on adh.membre_id = p.id
            left join adhesion.cotisation_etat ce
              on ce.adhesion_id = adh.id and ce.periode @> (select d from m)
            left join lateral (
              select montant from adhesion.tarif_applicable(adh.categorie, 'mensuelle', (select d from m), adh.association_id)
            ) t on adh.id is not null
            left join derniere on derniere.membre_id = p.id
            left join lateral (
              select json_agg(json_build_object('role', r.role, 'organisation', r.organisation_id) order by r.role) as roles
              from annuaire.representation r
              where r.personne_id = p.id and r.periode @> current_date and {asso_role}
            ) ro on true
            left join lateral (
              select json_agg(json_build_object('association', a.association_id, 'categorie', a.categorie) order by k.ordre) as adhesions
              from adhesion.adhesion a join adhesion.categorie k on k.code = a.categorie
              where a.membre_id = p.id and a.periode && daterange((select d from m), (select f from m)) and {asso_adh}
            ) ad on true
            left join lateral (
              select min(lower(a.periode)) as premier
              from adhesion.adhesion a
              where a.membre_id = p.id and a.categorie = 'full_patch' and {asso_adh}
            ) fp on true
            {completude}
            where (cast(:personne as uuid) is null or p.id = cast(:personne as uuid)) and {visible}
            order by p.recherche
            SQL;
        $sql = strtr($sql, ['{completude}' => Completude::LATERALE, '{asso_adh}' => $dansPerimetre('a.association_id'), '{asso_role}' => $dansPerimetre('r.organisation_id'), '{visible}' => $visible]);
        $lignes = $this->base->fetchAllAssociative($sql,
            ['debut' => $debut->format('Y-m-d'), 'fin' => $debut->modify('+1 month')->format('Y-m-d'), 'personne' => $personne] + $parametres,
        );

        return array_map(fn (array $l) => [
            'id' => $l['id'],
            'nom' => $l['nom'],
            'prenom' => $l['prenom'],
            'surnom' => $l['surnom'],
            'type' => $l['type'],  // personne, artiste ou structure
            'adhesion' => $l['adhesion'],
            'association' => $l['association'],
            'categorie' => $l['categorie'],
            'mois' => $this->statut($l),
            'derniere_cotisation' => $l['derniere_cotisation'],
            'roles' => $l['roles'] ? json_decode($l['roles'], true) : [],  // rôles en cours aujourd'hui
            'adhesions' => $l['adhesions'] ? json_decode($l['adhesions'], true) : [],  // toutes celles du mois
            'premier_full_patch' => $l['premier_full_patch'],  // date du premier passage full patch
            'completude' => ['manquants' => json_decode($l['manquants'], true), 'total' => (int) $l['total']],  // voir Completude
        ], $lignes);
    }

    private function statut(array $l): array
    {
        $statut = match (true) {
            null === $l['adhesion'] => self::AUCUNE_ADHESION,
            null !== $l['montant'] && $l['gratuite'] => self::GRATUIT,
            null !== $l['montant'] && $l['a_jour'] => self::PAYE,
            null !== $l['montant'] && (float) $l['regle'] > 0 => self::PARTIEL,
            null !== $l['montant'] || null !== $l['tarif'] => self::A_PAYER,
            default => self::SANS_TARIF,
        };
        $du = $l['montant'] ?? $l['tarif'];

        return [
            'statut' => $statut,
            'du' => null === $du ? null : number_format((float) $du, 2, '.', ''),
            'regle' => null === $l['regle'] ? null : number_format((float) $l['regle'], 2, '.', ''),
            'reste' => null === $du ? null : number_format(max(0, (float) $du - (float) ($l['regle'] ?? 0)), 2, '.', ''),
        ];
    }
}
