<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Fiches en double : détection et fusion.
 *
 * Deux fiches se ressemblent si elles ont le même nom et le même prénom (sans accents ni
 * majuscules) sans dates de naissance contradictoires, ou le même e-mail, ou le même téléphone.
 * Les ressemblances s'enchaînent en groupes. Une paire déclarée « pas un doublon » est écartée.
 *
 * Fusionner : la fiche gardée reçoit les adhésions (et leurs cotisations), les rôles, les
 * paiements, les coordonnées et les champs qui lui manquaient de l'autre, qui est supprimée.
 */
final class Doublons
{
    public function __construct(private Connection $base) {}

    /** @return list<array{raisons: list<string>, fiches: list<array>}> */
    public function groupes(): array
    {
        $paires = $this->base->fetchAllAssociative(<<<'SQL'
            with p as (
              select id, annuaire.normaliser(nom) as nn, annuaire.normaliser(coalesce(prenom, '')) as np, date_naissance as dn
              from annuaire.personne
            ),
            c as (
              select k.entite_id as id, k.type,
                     case k.type when 'email' then lower(trim(k.valeur)) else regexp_replace(k.valeur, '\D', '', 'g') end as v
              from annuaire.contact k join annuaire.personne x on x.id = k.entite_id
              where k.type in ('email', 'telephone')
            ),
            paires as (
              select a.id as a, b.id as b, 'nom' as raison
              from p a join p b on a.nn = b.nn and a.np = b.np and a.id < b.id
              where a.dn is null or b.dn is null or a.dn = b.dn
              union
              select a.id, b.id, a.type
              from c a join c b on a.type = b.type and a.v = b.v and a.v <> '' and a.id < b.id
            )
            select paires.* from paires
            where not exists (select 1 from annuaire.non_doublon n where n.a = paires.a and n.b = paires.b)
            SQL);

        // Groupes : ressemblances enchaînées (A ~ B, B ~ C → A, B, C).
        $parent = [];
        $racine = function (string $x) use (&$parent, &$racine): string {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $racine($parent[$x]));
        };
        $raisons = [];
        foreach ($paires as $p) {
            $parent[$racine($p['a'])] = $racine($p['b']);
        }
        $groupes = [];
        foreach (array_keys($parent) as $id) {
            $groupes[$racine($id)][] = $id;
        }
        foreach ($paires as $p) {
            $raisons[$racine($p['a'])][$p['raison']] = true;
        }

        $resultat = [];
        foreach ($groupes as $r => $ids) {
            $resultat[] = ['raisons' => array_keys($raisons[$r] ?? []), 'fiches' => $this->resumes($ids)];
        }
        usort($resultat, fn ($x, $y) => strcmp($x['fiches'][0]['nom'], $y['fiches'][0]['nom']));

        return $resultat;
    }

    /** Ce qu'il faut voir pour choisir la fiche à garder. */
    private function resumes(array $ids): array
    {
        return $this->base->fetchAllAssociative(<<<'SQL'
            select p.id, p.nom, p.prenom, p.surnom, p.type, p.statut, p.date_naissance as naissance, p.cree_le::date as cree_le,
                   (select valeur from annuaire.contact k where k.entite_id = p.id and k.type = 'telephone' order by k.principal desc limit 1) as telephone,
                   (select valeur from annuaire.contact k where k.entite_id = p.id and k.type = 'email' order by k.principal desc limit 1) as email,
                   (select ville from annuaire.adresse d where d.entite_id = p.id order by d.principale desc limit 1) as ville,
                   (select count(*) from adhesion.adhesion a where a.membre_id = p.id) as adhesions,
                   (select count(*) from adhesion.cotisation c join adhesion.adhesion a on a.id = c.adhesion_id where a.membre_id = p.id) as cotisations,
                   (select count(*) from annuaire.representation r where r.personne_id = p.id) as roles,
                   (select coalesce(json_agg(distinct o.nom), '[]') from adhesion.adhesion a join annuaire.organisation o on o.id = a.association_id
                     where a.membre_id = p.id) as associations
            from annuaire.personne p
            where p.id = any (cast(:ids as uuid[]))
            order by p.cree_le
            SQL, ['ids' => '{'.implode(',', $ids).'}']);
    }

    /** Ces fiches ne sont pas des doublons : toutes leurs paires sont écartées. */
    public function ecarter(array $ids): void
    {
        sort($ids);
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $this->base->executeStatement('insert into annuaire.non_doublon (a, b) values (:a, :b) on conflict do nothing', ['a' => $a, 'b' => $b]);
            }
        }
    }

    /** Fusionne la fiche $absorbee dans $gardee, qui reste. */
    public function fusionner(string $gardee, string $absorbee): void
    {
        if ($gardee === $absorbee) {
            throw new \DomainException('Une fiche ne se fusionne pas avec elle-même.');
        }
        $b = $this->base;
        $b->beginTransaction();
        try {
            $params = ['g' => $gardee, 'a' => $absorbee];
            if (2 !== (int) $b->fetchOne('select count(*) from annuaire.personne where id in (:g, :a)', $params)) {
                throw new \DomainException('Fiche inconnue.');
            }
            // Champs manquants de la fiche gardée, complétés par l'autre ; commentaires mis bout à bout.
            $b->executeStatement(
                "update annuaire.personne g set
                   prenom = coalesce(g.prenom, a.prenom), surnom = coalesce(g.surnom, a.surnom),
                   date_naissance = coalesce(g.date_naissance, a.date_naissance),
                   commentaire = case when a.commentaire is null or a.commentaire = g.commentaire then g.commentaire
                                      else concat_ws(E'\\n', g.commentaire, a.commentaire) end
                 from annuaire.personne a where g.id = :g and a.id = :a", $params);
            $this->fusionnerAdhesions($gardee, $absorbee);
            $this->deplacer('update adhesion.adhesion set membre_id = :g where membre_id = :a', $params,
                'Les deux fiches ont des adhésions à la même association sur des dates qui se recouvrent : corrigez-les d\'abord.');
            $b->executeStatement('update annuaire.representation set personne_id = :g where personne_id = :a', $params);
            $b->executeStatement('update adhesion.paiement set payeur_id = :g where payeur_id = :a', $params);
            // Coordonnées : les doublons exacts disparaissent, les autres passent en secondaires.
            $b->executeStatement(
                "delete from annuaire.contact k where k.entite_id = :a and exists (
                   select 1 from annuaire.contact x where x.entite_id = :g and x.type = k.type and lower(x.valeur) = lower(k.valeur))", $params);
            $b->executeStatement(
                'update annuaire.contact k set entite_id = :g,
                   principal = k.principal and not exists (select 1 from annuaire.contact x where x.entite_id = :g and x.type = k.type and x.principal)
                 where k.entite_id = :a', $params);
            $b->executeStatement(
                'update annuaire.adresse d set entite_id = :g,
                   principale = d.principale and not exists (select 1 from annuaire.adresse x where x.entite_id = :g and x.principale)
                 where d.entite_id = :a', $params);
            // Ce qui n'était pas un doublon de l'absorbée ne l'est pas non plus de la gardée.
            $b->executeStatement(
                'insert into annuaire.non_doublon (a, b)
                 select least(:g, x), greatest(:g, x) from (
                   select case when n.a = :a then n.b else n.a end as x from annuaire.non_doublon n where :a in (n.a, n.b)
                 ) autres where x <> :g
                 on conflict do nothing', $params);
            try {
                $b->executeStatement('delete from annuaire.entite where id = :a', $params);
            } catch (DriverException $e) {
                // Encore référencée ailleurs : dire par quoi (compte de connexion, ou autre table).
                if ('23503' !== $e->getSQLState()) {
                    throw $e;
                }
                preg_match('/table "(?:[a-z_]+\.)?([a-z_]+)"/', $e->getMessage(), $m);
                throw new \DomainException('compte' === ($m[1] ?? null)
                    ? 'La fiche à fusionner a un compte de connexion : rattachez-le d\'abord à la fiche gardée.'
                    : sprintf('La fiche à fusionner est encore utilisée (%s) : fusion impossible pour l\'instant.', $m[1] ?? 'référence inconnue'), 0, $e);
            }
            $b->commit();
        } catch (\Throwable $e) {
            $b->rollBack();
            throw $e;
        }
    }

    /**
     * Une même adhésion saisie sur les deux fiches (même association, dates qui se recouvrent) :
     * si le niveau est le même, les deux n'en font plus qu'une, de la première à la dernière date ;
     * leurs cotisations la suivent, et une cotisation présente des deux côtés sur la même période
     * n'est gardée qu'une fois, avec tous ses paiements.
     */
    private function fusionnerAdhesions(string $gardee, string $absorbee): void
    {
        $b = $this->base;
        $paires = $b->fetchAllAssociative(
            'select a1.id as garde, a2.id as absorbe, k1.libelle as niveau1, k2.libelle as niveau2, o.nom as association
             from adhesion.adhesion a2
             join adhesion.adhesion a1 on a1.membre_id = :g and a1.association_id = a2.association_id and a1.periode && a2.periode
             join adhesion.categorie k1 on k1.code = a1.categorie
             join adhesion.categorie k2 on k2.code = a2.categorie
             join annuaire.organisation o on o.id = a1.association_id
             where a2.membre_id = :a',
            ['g' => $gardee, 'a' => $absorbee]);
        foreach ($paires as $p) {
            if ($p['niveau1'] !== $p['niveau2']) {
                throw new \DomainException(sprintf('À %s, les deux fiches ont des adhésions de niveaux différents (%s et %s) sur des dates qui se recouvrent : corrigez-les d\'abord.',
                    $p['association'], $p['niveau1'], $p['niveau2']));
            }
            $ids = ['garde' => $p['garde'], 'absorbe' => $p['absorbe']];
            $this->deplacer('update adhesion.adhesion a1 set periode = range_merge(a1.periode, a2.periode)
                 from adhesion.adhesion a2 where a1.id = :garde and a2.id = :absorbe', $ids,
                sprintf('À %s, réunir les deux adhésions en recouvrirait une autre : corrigez les dates d\'abord.', $p['association']));
            foreach ($b->fetchAllAssociative('select id, periode::text as periode from adhesion.cotisation where adhesion_id = :absorbe', $ids) as $c) {
                $jumelle = $b->fetchAssociative(
                    'select id, periode = cast(:periode as daterange) as identique from adhesion.cotisation
                     where adhesion_id = :garde and periode && cast(:periode as daterange) limit 1',
                    ['garde' => $p['garde'], 'periode' => $c['periode']]);
                if (!$jumelle) {
                    $b->executeStatement('update adhesion.cotisation set adhesion_id = :garde where id = :c', ['garde' => $p['garde'], 'c' => $c['id']]);
                    continue;
                }
                if (!$jumelle['identique']) {
                    throw new \DomainException(sprintf('À %s, les deux fiches ont des cotisations qui se chevauchent sans couvrir la même période : corrigez-les d\'abord.', $p['association']));
                }
                // Même période : une seule cotisation, qui reçoit les paiements de l'autre.
                $b->executeStatement(
                    'update adhesion.reglement r set cotisation_id = :j where r.cotisation_id = :c
                       and not exists (select 1 from adhesion.reglement x where x.cotisation_id = :j and x.paiement_id = r.paiement_id)',
                    ['j' => $jumelle['id'], 'c' => $c['id']]);
                $b->executeStatement('delete from adhesion.cotisation where id = :c', ['c' => $c['id']]);
            }
            $b->executeStatement('delete from adhesion.adhesion where id = :absorbe', ['absorbe' => $p['absorbe']]);
        }
    }

    private function deplacer(string $sql, array $params, string $message): void
    {
        try {
            $this->base->executeStatement($sql, $params);
        } catch (DriverException $e) {
            if (in_array($e->getSQLState(), ['23P01', '23503', '23505'], true)) {
                throw new \DomainException($message, 0, $e);
            }
            throw $e;
        }
    }
}
