# Membres JaXaJ MC

Annuaire, adhésions, cotisations et contrôle d'accès du club JaXaJ MC. Première application de l'outil de gestion du club ; les autres (agenda, événements…) s'appuieront sur ses données.

- `docs/modele.md` : le modèle de données, les choix et les questions ouvertes.
- `sql/01_schema.sql` : schémas `annuaire` et `adhesion` (PostgreSQL 13 ou plus).
- `sql/02_reference.sql` : associations JaXaJ, catégories de membre, tarifs.
- `reprise/reprise_free.py` : reprise de l'ancienne base MySQL (Free, 2009-2020). Usage : `reprise_free.py <export.sql> <dossier de sortie>` ; la sortie contient des données personnelles et ne doit jamais être versionnée.

Pour créer une base de test : `createdb jaxaj && psql -d jaxaj -f sql/01_schema.sql -f sql/02_reference.sql`.
