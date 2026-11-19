# API membres JaXaJ MC

Symfony 7.4 LTS, API Platform 5, Doctrine ORM, PHP 8.4. Les tables sont définies par `../sql/` : Doctrine les lit, il ne les crée pas. Documentation interactive : `/api/docs`.

## Authentification

L'API vérifie le JWT délivré par l'appli comptes (`jaxaj-comptes`), avec la clé publique de celle-ci (`JWT_PUBLIC_KEY`, par défaut `config/jwt/comptes-public.pem`), sans l'appeler. Elle n'a ni comptes ni mots de passe à elle. En-tête : `Authorization: Bearer <jwt>`.

## Droits

| Droit (dans le JWT) | Permet |
|---|---|
| `membres:admin` | tout |
| `membres:gestion` ou `membres:gestion:<association>` | consulter et modifier les personnes avec leurs coordonnées ; adhésions et cotisations (de l'association) |
| `membres:accueil` ou `membres:accueil:<association>` | à l'entrée : rechercher, consulter sans coordonnées ni date de naissance, inscrire une personne, la faire adhérer comme adhérent événement (à l'association) |

## Ressources

| Chemin | Opérations | Remarques |
|---|---|---|
| `/api/personnes` | liste, lecture, création, modification | `?q=` : mots cherchés dans nom, prénom et surnom, sans accents ni majuscules ; adresse principale, téléphone et e-mail intégrés à la fiche |
| `/api/liste` | lecture | `?mois=AAAA-MM` (mois en cours par défaut) : toutes les personnes, triées par nom, avec leur adhésion principale du mois et l'état de la cotisation (`aucune_adhesion`, `a_payer`, `partiel`, `paye`, `gratuit`, `sans_tarif`), le montant dû et la date de la dernière cotisation réglée |
| `/api/personnes/{id}/paiement-mois` | création | `{"mois": "AAAA-MM", "moyen": "especes"}` : crée si besoin la cotisation du mois au tarif en vigueur sur l'adhésion principale, puis enregistre le paiement du reste dû ; refusé si déjà réglée |
| `/api/personnes/{id}/acces` | lecture | `?jour=AAAA-MM-JJ&association=<uuid>` : a-t-elle une cotisation réglée ou gratuite ce jour-là ? |
| `/api/adhesions` | liste, lecture, création, modification | `?membre=`, `?association=` ; `debut` et `fin` inclus, fin vide = en cours ; chevauchement refusé (409) |
| `/api/cotisations` | liste, lecture, création | `?adhesion=`, `?membre=` ; sans `fin`, couvre le mois ; sans `montant`, le tarif en vigueur s'applique |
| `/api/organisations` | liste, lecture | associations JaXaJ, clubs… |
| `/api/categories` | liste, lecture | full_patch, observateur, suiveur, support, public (adhérent événement), association |

Formats : JSON-LD (`application/ld+json`) ou JSON (`application/json`) ; modification partielle en `application/merge-patch+json`.

Les règles garanties par PostgreSQL (périodes qui se chevauchent, doublons) répondent 409, les règles métier 422 (tarif introuvable, par exemple).

## Tests

```sh
PGHOST=127.0.0.1 PGPORT=5432 PGUSER=postgres bin/preparer-tests   # clés de test et base membres_test
php bin/phpunit
```

Les tests fabriquent des jetons avec une paire de clés propre aux tests ; chaque test est annulé à la fin.
