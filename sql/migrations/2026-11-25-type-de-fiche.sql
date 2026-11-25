-- Type de fiche : une personne, un artiste ou un groupe, un club ou une association. Règle ce qu'on
-- attend de la fiche (complétude) et les champs du formulaire. « Membre du club » n'est pas un type :
-- c'est une personne qui a une adhésion de club.
alter table annuaire.personne add column if not exists type text not null default 'personne'
  check (type in ('personne', 'artiste', 'structure'));
