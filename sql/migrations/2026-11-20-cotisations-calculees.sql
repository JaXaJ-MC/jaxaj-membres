-- Cotisations calculées depuis les adhésions et les tarifs (bouton « Calculer les cotisations ») :
-- marquées pour pouvoir être recalculées après la correction d'un tarif.
alter table adhesion.cotisation add column if not exists calculee boolean not null default false;

-- La base Free ne notait aucun montant : la reprise avait mis 5 € le mois et 20 € l'année à tout
-- le monde. Faux pour les adhésions de club (full patch, suiveur, observateur…) : leurs cotisations
-- reprises et les paiements de reprise qui les réglaient sont retirés, à recalculer au tarif.
-- Celles du public des concerts restent. Sans effet sur une base neuve.
delete from adhesion.paiement p
where p.reference = 'Reprise de la base Free 2009-2020'
  and exists (select 1 from adhesion.reglement r where r.paiement_id = p.id)
  and not exists (
    select 1 from adhesion.reglement r
    join adhesion.cotisation c on c.id = r.cotisation_id
    join adhesion.adhesion a on a.id = c.adhesion_id
    where r.paiement_id = p.id
      and (a.categorie = 'public' or a.commentaire is distinct from 'Reprise de la base Free 2009-2020'
           or upper(c.periode) > '2021-01-01'));

delete from adhesion.cotisation c
using adhesion.adhesion a
where a.id = c.adhesion_id
  and a.commentaire = 'Reprise de la base Free 2009-2020'
  and a.categorie <> 'public'
  and upper(c.periode) <= '2021-01-01'
  and not exists (select 1 from adhesion.reglement r join adhesion.paiement p on p.id = r.paiement_id
                  where r.cotisation_id = c.id and p.reference is distinct from 'Reprise de la base Free 2009-2020');
