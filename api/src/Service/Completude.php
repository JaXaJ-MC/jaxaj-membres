<?php

namespace App\Service;

/**
 * Complétude d'une fiche : ce qui manque, parmi ce qu'on attend selon la personne.
 *
 *   membre du club (une adhésion de club, même passée) : prénom, surnom, date de naissance,
 *       téléphone, e-mail, adresse, une adhésion
 *   les autres (public, artistes…) : prénom, un moyen de contact (téléphone ou e-mail), une adhésion
 *   plus, pour tous, des cotisations sur chaque adhésion qui en demande (cotisation périodique,
 *   sans exemption) : sinon, « Calculer les cotisations » n'a pas été fait
 *
 * Fragment SQL (jointure latérale sur la personne « p ») : colonnes manquants (codes) et total.
 */
final class Completude
{
    public const LATERALE = <<<'SQL'
        left join lateral (
          select array_to_json(array_remove(array[
                   case when p.prenom is null then 'prenom' end,
                   case when x.club and p.surnom is null then 'surnom' end,
                   case when x.club and p.date_naissance is null then 'naissance' end,
                   case when x.club and not x.tel then 'telephone' end,
                   case when x.club and not x.mail then 'email' end,
                   case when not x.club and not x.tel and not x.mail then 'contact' end,
                   case when x.club and not x.adr then 'adresse' end,
                   case when not x.adh then 'adhesion' end,
                   case when x.sans_cotisation then 'cotisations' end
                 ], null)) as manquants,
                 (case when x.club then 7 else 3 end) + (case when x.periodique then 1 else 0 end) as total
          from (select
            exists (select 1 from adhesion.adhesion a join adhesion.categorie k on k.code = a.categorie
                    where a.membre_id = p.id and k.membre_club) as club,
            exists (select 1 from annuaire.contact c where c.entite_id = p.id and c.type = 'telephone') as tel,
            exists (select 1 from annuaire.contact c where c.entite_id = p.id and c.type = 'email') as mail,
            exists (select 1 from annuaire.adresse d where d.entite_id = p.id and coalesce(d.ville, d.ligne1) is not null) as adr,
            exists (select 1 from adhesion.adhesion a where a.membre_id = p.id) as adh,
            exists (select 1 from adhesion.adhesion a join adhesion.categorie k on k.code = a.categorie
                    where a.membre_id = p.id and k.cotisation = 'periodique' and a.exemption is null) as periodique,
            exists (select 1 from adhesion.adhesion a join adhesion.categorie k on k.code = a.categorie
                    where a.membre_id = p.id and k.cotisation = 'periodique' and a.exemption is null
                      and not exists (select 1 from adhesion.cotisation c where c.adhesion_id = a.id)) as sans_cotisation
          ) x
        ) completude on true
        SQL;
}
