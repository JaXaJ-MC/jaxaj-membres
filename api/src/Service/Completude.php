<?php

namespace App\Service;

/**
 * Complétude d'une fiche : ce qui manque, parmi ce qu'on attend selon la personne.
 *
 *   personne membre du club (une adhésion de club, même passée) : nom, prénom, date de
 *       naissance, téléphone, e-mail, adresse, une adhésion
 *   autre personne : nom, prénom, e-mail, une adhésion
 *   club ou association (type structure) : nom, e-mail, adresse, une adhésion
 *   artiste ou groupe : nom, e-mail, téléphone, une adhésion
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
                   case when p.prenom is null and x.profil in ('club', 'personne') then 'prenom' end,
                   case when p.date_naissance is null and x.profil = 'club' then 'naissance' end,
                   case when not x.tel and x.profil in ('club', 'artiste') then 'telephone' end,
                   case when not x.mail then 'email' end,
                   case when not x.adr and x.profil in ('club', 'structure') then 'adresse' end,
                   case when not x.adh then 'adhesion' end,
                   case when x.sans_cotisation then 'cotisations' end
                 ], null)) as manquants,
                 -- nom (toujours renseigné), e-mail, adhésion + ce que demande le profil
                 3 + case x.profil when 'club' then 4 else 1 end + (case when x.periodique then 1 else 0 end) as total
          from (select case when p.type <> 'personne' then p.type
                            when y.club then 'club' else 'personne' end as profil, y.*
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
          ) y) x
        ) completude on true
        SQL;
}
