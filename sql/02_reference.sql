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
  -- Volet événementiel, séparé du club : reçoit les adhésions « adhérent événement ».
  perform annuaire.creer_organisation('JaXaJ Productions', 'association_jaxaj');
end
$$;

-- ---------------------------------------------------------------- catégories

insert into adhesion.categorie (code, libelle, membre_club, ordre) values
  ('full_patch',   'Full patch',                          true,  10),
  ('observateur',  'Observateur',                         true,  20),
  ('suiveur',      'Suiveur',                             true,  30),
  ('support',      'Support (validé par le club)',        true,  40),
  ('public',       'Adhérent événement',                  false, 50),
  ('association',  'Association de chapitre',             false, 60);  -- membres de la mère

-- ---------------------------------------------------------------- tarifs

-- 2026 : mensuels, identiques dans les deux chapitres (montants à confirmer).
insert into adhesion.tarif (categorie, periodicite, montant, validite, decision) values
  ('full_patch',  'mensuelle', 60, '[2026-01-01,2027-01-01)', 'AG de janvier 2026'),
  ('observateur', 'mensuelle', 60, '[2026-01-01,2027-01-01)', 'AG de janvier 2026'),
  ('suiveur',     'mensuelle', 30, '[2026-01-01,2027-01-01)', 'AG de janvier 2026'),
  ('support',     'mensuelle', 20, '[2026-01-01,2027-01-01)', 'AG de janvier 2026');

-- 2027 : reprise de 2026 en attendant l'AG de janvier 2027.
insert into adhesion.tarif (categorie, periodicite, montant, validite, decision)
select categorie, periodicite, montant, '[2027-01-01,2028-01-01)', 'Reprise de 2026, à confirmer à l''AG de janvier 2027'
from adhesion.tarif where validite = '[2026-01-01,2027-01-01)';

-- Public des concerts, période de l'ancienne base (2009-2020) : 5 € pour le mois, 20 € pour
-- l'année. Sert à rattacher les cotisations reprises.
insert into adhesion.tarif (categorie, periodicite, montant, validite, decision) values
  ('public', 'mensuelle', 5,  '[2009-01-01,2021-01-01)', 'Tarif historique des concerts'),
  ('public', 'annuelle',  20, '[2009-01-01,2021-01-01)', 'Tarif historique des concerts');
