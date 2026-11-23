-- Rôle « vice-trésorier(ère) », un titulaire à la fois par organisation.
alter table annuaire.representation drop constraint if exists representation_role_check;
alter table annuaire.representation add constraint representation_role_check check (role in
  ('president', 'vice_president', 'tresorier', 'vice_tresorier', 'secretaire',
   'sergent_armes', 'chef_de_file', 'representant'));
