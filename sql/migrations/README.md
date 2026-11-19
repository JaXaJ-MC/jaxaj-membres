# Migrations du schéma

`01_schema.sql` décrit toujours le schéma complet, à jour : une base neuve n'a besoin que de lui. Une base existante reçoit, dans l'ordre, les fichiers de ce dossier qu'elle n'a pas encore reçus ; la table `public.migration_membres` garde la liste de ceux déjà appliqués.

Chaque migration doit pouvoir s'appliquer sur une base neuve sans erreur (elle est alors sans effet), parce qu'une base créée depuis `01_schema.sql` est marquée comme les ayant toutes reçues.

Nom : `AAAA-MM-JJ-description.sql`.
