-- Deux volets séparés : le club (la mère et ses chapitres) et l'événementiel (JaXaJ Productions),
-- chacun avec ses catégories d'adhésion. Exemption de cotisation au cas par cas, sur l'adhésion.

-- Volet de chaque association JaXaJ.
alter table annuaire.organisation add column if not exists volet text
  check (volet in ('club', 'evenementiel'));
update annuaire.organisation
   set volet = case when nom = 'JaXaJ Productions' then 'evenementiel' else 'club' end
 where genre = 'association_jaxaj' and volet is null;

-- Volet et mode de cotisation de chaque catégorie.
alter table adhesion.categorie add column if not exists volet text not null default 'club'
  check (volet in ('club', 'evenementiel'));
alter table adhesion.categorie add column if not exists cotisation text not null default 'periodique'
  check (cotisation in ('periodique', 'ponctuelle', 'aucune'));
insert into adhesion.categorie (code, libelle, membre_club, ordre, volet, cotisation) values
  ('artiste',          'Artiste',                       false, 50, 'evenementiel', 'aucune'),
  ('artiste_adherent', 'Artiste adhérent',              false, 55, 'evenementiel', 'periodique'),
  ('adherent',         'Adhérent',                      false, 60, 'evenementiel', 'periodique')
on conflict (code) do nothing;
update adhesion.categorie set libelle = 'Public (adhésion ponctuelle)', ordre = 70, volet = 'evenementiel', cotisation = 'ponctuelle'
 where code = 'public';
update adhesion.categorie set ordre = 90, cotisation = 'aucune' where code = 'association';

-- Exemption de cotisation (motif) : le calcul crée alors des cotisations gratuites.
alter table adhesion.adhesion add column if not exists exemption text;

-- Le public est toujours accueilli par Productions, même quand un chapitre organise : la reprise
-- l'avait mis sous JaXaJ MC. Ses paiements de reprise sont encaissés par Productions.
update adhesion.paiement p
   set encaisse_par = prod.id
  from annuaire.organisation prod, annuaire.organisation mere
 where prod.nom = 'JaXaJ Productions' and mere.nom = 'JaXaJ MC'
   and p.encaisse_par = mere.id
   and exists (select 1 from adhesion.reglement r
               join adhesion.cotisation c on c.id = r.cotisation_id
               join adhesion.adhesion a on a.id = c.adhesion_id
               where r.paiement_id = p.id and a.categorie = 'public' and a.association_id = mere.id);
update adhesion.adhesion a
   set association_id = prod.id
  from annuaire.organisation prod, annuaire.organisation mere
 where prod.nom = 'JaXaJ Productions' and mere.nom = 'JaXaJ MC'
   and a.association_id = mere.id and a.categorie = 'public'
   and not exists (select 1 from adhesion.adhesion b
                   where b.membre_id = a.membre_id and b.association_id = prod.id and b.periode && a.periode);

-- Une adhésion de club chez Productions devient « adhérent ».
update adhesion.adhesion a
   set categorie = 'adherent'
  from annuaire.organisation prod
 where prod.nom = 'JaXaJ Productions' and a.association_id = prod.id
   and a.categorie in ('full_patch', 'observateur', 'suiveur', 'support');

-- Tarifs de Productions (modifiables dans l'écran Tarifs).
insert into adhesion.tarif (categorie, periodicite, montant, validite, decision)
select v.categorie, 'annuelle', v.montant, '[2009-01-01,)', v.decision
from (values ('adherent', 20, 'Cotisation annuelle des adhérents'),
             ('artiste_adherent', 200, 'Accès à la salle de répétition, montant à confirmer')) as v (categorie, montant, decision)
where exists (select 1 from adhesion.categorie where code = v.categorie)
  and not exists (select 1 from adhesion.tarif t where t.categorie = v.categorie);

-- Depuis le 1er janvier 2026, seules les associations de chapitre adhèrent à la mère : les tarifs
-- du club « toutes associations » à partir de 2026 deviennent ceux des chapitres.
insert into adhesion.tarif (categorie, association_id, periodicite, montant, validite, decision)
select t.categorie, euskadi.id, t.periodicite, t.montant, t.validite, t.decision
from adhesion.tarif t
join adhesion.categorie k on k.code = t.categorie and k.volet = 'club'
cross join annuaire.organisation euskadi
where euskadi.nom = 'JaXaJ MC Euskadi' and t.association_id is null and lower(t.validite) >= '2026-01-01';
update adhesion.tarif t
   set association_id = bearn.id
  from adhesion.categorie k, annuaire.organisation bearn
 where k.code = t.categorie and k.volet = 'club' and bearn.nom = 'JaXaJ MC Béarn'
   and t.association_id is null and lower(t.validite) >= '2026-01-01';
