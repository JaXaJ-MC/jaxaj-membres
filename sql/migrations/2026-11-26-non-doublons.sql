-- Paires de fiches qui se ressemblent mais ne sont pas des doublons (deux homonymes) : la page des
-- doublons ne les propose plus.
create table if not exists annuaire.non_doublon (
  a          uuid not null references annuaire.personne (id) on delete cascade,
  b          uuid not null references annuaire.personne (id) on delete cascade,
  cree_le    timestamptz not null default now(),
  primary key (a, b),
  check (a < b)
);
