-- Personne décédée, artiste ou structure qui n'existe plus : « disparu », avec la date si on la
-- connaît. La fiche et son historique restent ; elle sort de la liste courante.
alter table annuaire.personne add column if not exists statut text not null default 'actif'
  check (statut in ('actif', 'disparu'));
alter table annuaire.personne add column if not exists disparu_le date;
alter table annuaire.personne drop constraint if exists personne_disparu_le_check;
alter table annuaire.personne add constraint personne_disparu_le_check check (statut = 'disparu' or disparu_le is null);
