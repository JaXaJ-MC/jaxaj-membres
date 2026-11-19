-- Gestion des membres JaXaJ MC : schéma PostgreSQL (13 ou plus).
--
-- Deux schémas, propriété de l'application « membres » :
--   annuaire  : les personnes physiques et morales, leurs coordonnées, qui représente qui
--   adhesion  : les adhésions aux associations, les tarifs, les cotisations et les paiements
--
-- Conventions communes à toutes les applications du club :
--   - chaque entité a un identifiant UUID stable, que les autres applications utilisent
--     pour y faire référence (jamais un identifiant interne ou un nom) ;
--   - on historise par des périodes (daterange) au lieu d'écraser : statut, tarif, fonction ;
--   - les tables portent cree_le et modifie_le.

create extension if not exists btree_gist;  -- contraintes d'exclusion sur (uuid, daterange)
create extension if not exists unaccent;    -- recherche sans accents

create schema annuaire;
create schema adhesion;

-- Texte normalisé pour la recherche : minuscules, sans accents (« José » → « jose »).
create function annuaire.normaliser(t text) returns text
language sql immutable parallel safe as $$
  select lower(public.unaccent('public.unaccent'::regdictionary, coalesce(t, '')))
$$;

create function annuaire.maj_modifie_le() returns trigger
language plpgsql as $$
begin
  new.modifie_le := now();
  return new;
end
$$;

-- ---------------------------------------------------------------- annuaire

-- Toute personne, physique ou morale. Les adhésions et les coordonnées pointent ici, ce qui
-- permet à une association d'être membre d'une autre (les chapitres membres de la mère).
create table annuaire.entite (
  id           uuid primary key default gen_random_uuid(),
  nature       text not null check (nature in ('physique', 'morale')),
  cree_le      timestamptz not null default now(),
  modifie_le   timestamptz not null default now(),
  anonymise_le timestamptz,  -- RGPD : données personnelles effacées, l'historique reste
  unique (id, nature)
);

create table annuaire.personne (
  id             uuid primary key default gen_random_uuid(),
  nature         text not null default 'physique' check (nature = 'physique'),
  nom            text not null,
  prenom         text,
  surnom         text,  -- nom de route
  date_naissance date,
  commentaire    text,
  recherche      text generated always as (annuaire.normaliser(
                   nom || ' ' || coalesce(prenom, '') || ' ' || coalesce(surnom, ''))) stored,
  cree_le        timestamptz not null default now(),
  modifie_le     timestamptz not null default now(),
  foreign key (id, nature) references annuaire.entite (id, nature) on delete cascade
);

create table annuaire.organisation (
  id          uuid primary key default gen_random_uuid(),
  nature      text not null default 'morale' check (nature = 'morale'),
  nom         text not null,
  sigle       text,
  genre       text not null check (genre in
                ('association_jaxaj', 'club_moto', 'association', 'entreprise', 'autre')),
  parent_id   uuid references annuaire.organisation (id),  -- association mère d'un chapitre
  rna         text,  -- numéro du répertoire national des associations
  siret       text,
  commentaire text,
  cree_le     timestamptz not null default now(),
  modifie_le  timestamptz not null default now(),
  foreign key (id, nature) references annuaire.entite (id, nature) on delete cascade
);

create table annuaire.adresse (
  id          uuid primary key default gen_random_uuid(),
  entite_id   uuid not null references annuaire.entite (id) on delete cascade,
  libelle     text,  -- domicile, siège, local…
  ligne1      text,
  ligne2      text,
  code_postal text,  -- texte : les zéros de tête comptent (01000, 08001)
  ville       text,
  pays        char(2) not null default 'FR' check (pays ~ '^[A-Z]{2}$'),  -- ISO 3166-1
  principale  boolean not null default true,
  cree_le     timestamptz not null default now(),
  modifie_le  timestamptz not null default now()
);
create unique index adresse_une_principale on annuaire.adresse (entite_id) where principale;

create table annuaire.contact (
  id         uuid primary key default gen_random_uuid(),
  entite_id  uuid not null references annuaire.entite (id) on delete cascade,
  type       text not null check (type in ('telephone', 'email', 'site', 'reseau_social')),
  valeur     text not null,
  principal  boolean not null default true,
  cree_le    timestamptz not null default now(),
  modifie_le timestamptz not null default now(),
  check (type <> 'email' or valeur ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$')
);
create unique index contact_un_principal on annuaire.contact (entite_id, type) where principal;

-- Qui représente quelle organisation, à quel titre et quand : président et trésorier d'une
-- association JaXaJ, ou personne venue sous l'égide de son club ou de son association.
create table annuaire.representation (
  id              uuid primary key default gen_random_uuid(),
  personne_id     uuid not null references annuaire.personne (id) on delete cascade,
  organisation_id uuid not null references annuaire.organisation (id) on delete cascade,
  role            text not null constraint representation_role_check check (role in
                    ('president', 'vice_president', 'tresorier', 'secretaire',
                     'sergent_armes', 'chef_de_file', 'representant')),
  periode         daterange not null default daterange(current_date, null),
  cree_le         timestamptz not null default now(),
  modifie_le      timestamptz not null default now(),
  -- un seul titulaire à la fois par organisation, sauf pour « représentant »
  exclude using gist (organisation_id with =, role with =, periode with &&)
    where (role <> 'representant')
);

-- ---------------------------------------------------------------- adhesion

-- Catégories de membre : full patch, observateur, suiveur, support, public…
create table adhesion.categorie (
  code        text primary key,
  libelle     text not null,
  membre_club boolean not null,  -- fait partie du club, par opposition au public
  ordre       int not null
);

-- Tarifs votés en AG, par catégorie et périodicité. association_id vide : même tarif dans
-- toutes les associations de chapitre.
create table adhesion.tarif (
  id             uuid primary key default gen_random_uuid(),
  categorie      text not null references adhesion.categorie (code),
  association_id uuid references annuaire.organisation (id),
  periodicite    text not null check (periodicite in ('mensuelle', 'annuelle', 'ponctuelle')),
  montant        numeric(8, 2) not null check (montant >= 0),
  validite       daterange not null,
  decision       text,  -- « AG du 17 janvier 2026 »
  cree_le        timestamptz not null default now(),
  exclude using gist (
    categorie with =,
    (coalesce(association_id, '00000000-0000-0000-0000-000000000000'::uuid)) with =,
    periodicite with =,
    validite with &&
  )
);

-- Une adhésion : tel membre (personne ou organisation), à telle association, dans telle
-- catégorie, sur telle période. Passer de suiveur à full patch = clore une adhésion et en
-- ouvrir une autre : l'historique est conservé.
create table adhesion.adhesion (
  id             uuid primary key default gen_random_uuid(),
  membre_id      uuid not null references annuaire.entite (id),
  association_id uuid not null references annuaire.organisation (id),
  categorie      text not null references adhesion.categorie (code),
  periode        daterange not null,
  au_titre_de    uuid references annuaire.organisation (id),  -- club ou association représenté
  commentaire    text,
  cree_le        timestamptz not null default now(),
  modifie_le     timestamptz not null default now(),
  check (not isempty(periode)),
  exclude using gist (membre_id with =, association_id with =, periode with &&)
);

-- Ce qui est dû pour une période (un mois, une année, un événement). Gratuite : rien à payer.
create table adhesion.cotisation (
  id             uuid primary key default gen_random_uuid(),
  adhesion_id    uuid not null references adhesion.adhesion (id) on delete cascade,
  periode        daterange not null,
  montant        numeric(8, 2) not null check (montant >= 0),
  tarif_id       uuid references adhesion.tarif (id),
  gratuite       boolean not null default false,
  motif_gratuite text,
  calculee       boolean not null default false,  -- créée par « Calculer les cotisations », recalculable
  cree_le        timestamptz not null default now(),
  check (not gratuite or montant = 0),
  check (not isempty(periode)),
  exclude using gist (adhesion_id with =, periode with &&)
);

create table adhesion.paiement (
  id            uuid primary key default gen_random_uuid(),
  payeur_id     uuid references annuaire.entite (id),
  encaisse_par  uuid not null references annuaire.organisation (id),  -- association qui encaisse
  montant       numeric(8, 2) not null check (montant > 0),
  date_paiement date not null default current_date,
  moyen         text not null check (moyen in
                  ('especes', 'cheque', 'virement', 'carte', 'en_ligne', 'autre')),
  reference     text,  -- numéro de chèque, identifiant de transaction…
  cree_le       timestamptz not null default now()
);

-- Affectation d'un paiement à une ou plusieurs cotisations (trois mois payés d'un coup…).
create table adhesion.reglement (
  paiement_id   uuid not null references adhesion.paiement (id) on delete cascade,
  cotisation_id uuid not null references adhesion.cotisation (id) on delete cascade,
  montant       numeric(8, 2) not null check (montant > 0),
  primary key (paiement_id, cotisation_id)
);

-- ---------------------------------------------------------------- entité créée d'office

-- Insérer une personne ou une organisation crée sa ligne dans annuaire.entite : une appli
-- n'a pas à s'en soucier.
create function annuaire.creer_entite() returns trigger
language plpgsql as $$
begin
  insert into annuaire.entite (id, nature) values (new.id, new.nature) on conflict (id) do nothing;
  return new;
end
$$;

create trigger entite before insert on annuaire.personne
  for each row execute function annuaire.creer_entite();
create trigger entite before insert on annuaire.organisation
  for each row execute function annuaire.creer_entite();

-- ---------------------------------------------------------------- dates de modification

create trigger maj before update on annuaire.entite
  for each row execute function annuaire.maj_modifie_le();
create trigger maj before update on annuaire.personne
  for each row execute function annuaire.maj_modifie_le();
create trigger maj before update on annuaire.organisation
  for each row execute function annuaire.maj_modifie_le();
create trigger maj before update on annuaire.adresse
  for each row execute function annuaire.maj_modifie_le();
create trigger maj before update on annuaire.contact
  for each row execute function annuaire.maj_modifie_le();
create trigger maj before update on annuaire.representation
  for each row execute function annuaire.maj_modifie_le();
create trigger maj before update on adhesion.adhesion
  for each row execute function annuaire.maj_modifie_le();

-- ---------------------------------------------------------------- création simplifiée

create function annuaire.creer_personne(p_nom text, p_prenom text default null,
                                        p_date_naissance date default null)
returns uuid language plpgsql as $$
declare v_id uuid;
begin
  insert into annuaire.entite (nature) values ('physique') returning id into v_id;
  insert into annuaire.personne (id, nom, prenom, date_naissance)
    values (v_id, p_nom, p_prenom, p_date_naissance);
  return v_id;
end
$$;

create function annuaire.creer_organisation(p_nom text, p_genre text,
                                            p_parent uuid default null)
returns uuid language plpgsql as $$
declare v_id uuid;
begin
  insert into annuaire.entite (nature) values ('morale') returning id into v_id;
  insert into annuaire.organisation (id, nom, genre, parent_id) values (v_id, p_nom, p_genre, p_parent);
  return v_id;
end
$$;

-- ---------------------------------------------------------------- lecture

-- État de chaque cotisation : montant réglé et solde.
create view adhesion.cotisation_etat as
select c.*,
       a.membre_id,
       a.association_id,
       a.categorie,
       coalesce(r.regle, 0) as regle,
       c.montant - coalesce(r.regle, 0) as solde,
       c.gratuite or coalesce(r.regle, 0) >= c.montant as a_jour
from adhesion.cotisation c
join adhesion.adhesion a on a.id = c.adhesion_id
left join (select cotisation_id, sum(montant) as regle
           from adhesion.reglement group by cotisation_id) r on r.cotisation_id = c.id;

-- Contrôle d'accès : le membre a-t-il, à cette date, une cotisation réglée (ou gratuite)
-- auprès de l'une des associations données (toutes si vide) ?
create function adhesion.droit_acces(p_membre uuid, p_jour date default current_date,
                                     p_associations uuid[] default null)
returns boolean language sql stable as $$
  select exists (
    select 1 from adhesion.cotisation_etat
    where membre_id = p_membre
      and periode @> p_jour
      and a_jour
      and (p_associations is null or association_id = any (p_associations))
  )
$$;

-- Tarif applicable à une date.
create function adhesion.tarif_applicable(p_categorie text, p_periodicite text,
                                          p_jour date default current_date,
                                          p_association uuid default null)
returns adhesion.tarif language sql stable as $$
  select * from adhesion.tarif
  where categorie = p_categorie
    and periodicite = p_periodicite
    and validite @> p_jour
    and (association_id = p_association or association_id is null)
  order by association_id nulls last  -- un tarif propre à l'association l'emporte
  limit 1
$$;
