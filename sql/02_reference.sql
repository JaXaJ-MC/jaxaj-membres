-- Données de référence : associations JaXaJ, catégories de membre, tarifs.
-- Les noms et montants marqués « à confirmer » sont des hypothèses.

-- ---------------------------------------------------------------- associations JaXaJ

do $$
declare
  v_mere uuid;
begin
  v_mere := annuaire.creer_organisation('JaXaJ MC', 'association_jaxaj');  -- nom à confirmer
  update annuaire.organisation
     set commentaire = 'Association historique, devenue association mère : centrale d''achat, paie les loyers des chapitres.'
   where id = v_mere;
  perform annuaire.creer_organisation('JaXaJ MC Béarn', 'association_jaxaj', v_mere);
  perform annuaire.creer_organisation('JaXaJ MC Euskadi', 'association_jaxaj', v_mere);  -- en création
  -- Volet événementiel, séparé du club : artistes, adhérents et public des événements, même
  -- quand un chapitre organise.
  perform annuaire.creer_organisation('JaXaJ Productions', 'association_jaxaj');
  update annuaire.organisation
     set volet = case when nom = 'JaXaJ Productions' then 'evenementiel' else 'club' end
   where genre = 'association_jaxaj';
end
$$;

-- ---------------------------------------------------------------- catégories

insert into adhesion.categorie (code, libelle, membre_club, ordre, volet, cotisation) values
  ('full_patch',       'Full patch',                    true,  10, 'club',         'periodique'),
  ('observateur',      'Observateur',                   true,  20, 'club',         'periodique'),
  ('suiveur',          'Suiveur',                       true,  30, 'club',         'periodique'),
  ('support',          'Support (validé par le club)',  true,  40, 'club',         'periodique'),
  ('artiste',          'Artiste',                       false, 50, 'evenementiel', 'aucune'),      -- venu se produire
  ('artiste_adherent', 'Artiste adhérent',              false, 55, 'evenementiel', 'periodique'),  -- salle de répétition
  ('adherent',         'Adhérent',                      false, 60, 'evenementiel', 'periodique'),
  ('public',           'Public (adhésion ponctuelle)',  false, 70, 'evenementiel', 'ponctuelle'),
  ('association',      'Association de chapitre',       false, 90, 'club',         'aucune');  -- membres de la mère

-- ---------------------------------------------------------------- tarifs

-- 2026 : mensuels, identiques dans les deux chapitres. Depuis 2026, seuls les chapitres adhèrent
-- à la mère : pas de tarif du club pour JaXaJ MC.
insert into adhesion.tarif (categorie, association_id, periodicite, montant, validite, decision)
select v.categorie, o.id, 'mensuelle', v.montant, '[2026-01-01,2027-01-01)', 'AG de janvier 2026'
from (values ('full_patch', 60), ('observateur', 60), ('suiveur', 30), ('support', 20)) as v (categorie, montant)
cross join annuaire.organisation o
where o.nom in ('JaXaJ MC Béarn', 'JaXaJ MC Euskadi');

-- 2027 : reprise de 2026 en attendant l'AG de janvier 2027.
insert into adhesion.tarif (categorie, association_id, periodicite, montant, validite, decision)
select categorie, association_id, periodicite, montant, '[2027-01-01,2028-01-01)', 'Reprise de 2026, à confirmer à l''AG de janvier 2027'
from adhesion.tarif where validite = '[2026-01-01,2027-01-01)';

-- Productions : cotisations annuelles (modifiables dans l'écran Tarifs).
insert into adhesion.tarif (categorie, periodicite, montant, validite, decision) values
  ('adherent',         'annuelle', 20,  '[2009-01-01,)', 'Cotisation annuelle des adhérents'),
  ('artiste_adherent', 'annuelle', 200, '[2009-01-01,)', 'Accès à la salle de répétition, montant à confirmer');

-- Public des concerts, période de l'ancienne base (2009-2020) : 5 € pour le mois, 20 € pour
-- l'année. Sert à rattacher les cotisations reprises.
insert into adhesion.tarif (categorie, periodicite, montant, validite, decision) values
  ('public', 'mensuelle', 5,  '[2009-01-01,2021-01-01)', 'Tarif historique des concerts'),
  ('public', 'annuelle',  20, '[2009-01-01,2021-01-01)', 'Tarif historique des concerts');
