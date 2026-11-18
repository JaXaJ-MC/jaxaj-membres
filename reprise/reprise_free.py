#!/usr/bin/env python3
"""Reprise de l'ancienne base des membres (MySQL chez Free, 2009-2020).

Lit l'export phpMyAdmin (tables prod_membres et prod_cotisations) et produit :
  - un script SQL à charger après sql/01_schema.sql et sql/02_reference.sql ;
  - un rapport des corrections et des anomalies.

Les deux contiennent des données personnelles : à écrire hors du dépôt.

Usage : reprise_free.py <export.sql> <dossier de sortie>

Les identifiants sont déterministes (UUID v5 dérivés des anciens ID) : relancer la
reprise donne les mêmes UUID.
"""

import collections
import datetime
import re
import sys
import uuid

ESPACE = uuid.UUID("6f2b2a0e-3c1d-4d6e-9a51-0a7a3f1c2b10")  # espace de noms de la reprise
REFERENCE = "Reprise de la base Free 2009-2020"

PAYS = {
    "france": "FR", "espagne": "ES", "portugal": "PT", "grece": "GR", "grèce": "GR",
    "bulgarie": "BG", "belgique": "BE", "suisse": "CH", "italie": "IT", "allemagne": "DE",
    "royaume-uni": "GB", "angleterre": "GB", "pays-bas": "NL", "irlande": "IE",
}

# Ancien « Type » → catégorie. Club et Association : personnes venues au nom de leur club
# ou association, enregistrées comme public (l'organisation n'est pas connue).
CATEGORIES = {
    "support": "public", "club": "public", "association": "public",
    "membre": "full_patch", "suiveur": "suiveur", "observateur": "observateur",
}


def uid(*parties):
    return uuid.uuid5(ESPACE, "/".join(str(p) for p in parties))


# ---------------------------------------------------------------- lecture de l'export

def lire_inserts(sql, table):
    """Lignes des INSERT d'une table, sous forme de dictionnaires (None pour NULL)."""
    lignes = []
    for m in re.finditer(r"INSERT INTO `%s` \(([^)]*)\) VALUES\s*(.*?);\n" % table, sql, re.S):
        colonnes = [c.strip(" `") for c in m.group(1).split(",")]
        for valeurs in decouper_tuples(m.group(2)):
            lignes.append(dict(zip(colonnes, valeurs)))
    return lignes


def decouper_tuples(corps):
    """Découpe « (1,'a\\'b',NULL),(2,...) » en listes de valeurs, avec les échappements MySQL."""
    echappements = {"n": "\n", "r": "\r", "t": "\t", "0": "\0", "Z": "\x1a"}
    tuples, valeurs, valeur = [], [], []
    dans_tuple = chaine = echappe = cite = False
    for c in corps:
        if chaine:
            if echappe:
                valeur.append(echappements.get(c, c))
                echappe = False
            elif c == "\\":
                echappe = True
            elif c == "'":
                chaine = False
            else:
                valeur.append(c)
        elif c == "'":
            chaine = cite = True
        elif c == "(" and not dans_tuple:
            dans_tuple, valeurs, valeur, cite = True, [], [], False
        elif dans_tuple and c in ",)":
            texte = "".join(valeur).strip()
            valeurs.append(None if (texte == "NULL" and not cite) else texte)
            valeur, cite = [], False
            if c == ")":
                tuples.append(valeurs)
                dans_tuple = False
        elif dans_tuple:
            valeur.append(c)
    return tuples


# ---------------------------------------------------------------- nettoyage

def texte(v):
    if v is None:
        return None
    v = re.sub(r"\s+", " ", v.replace("\r", " ").replace("\n", " ")).strip()
    return v or None


def multiligne(v):
    if v is None:
        return None
    v = v.replace("\r\n", "\n").replace("\r", "\n").strip()
    return v or None


def date(v):
    if not v or v.startswith("0000"):
        return None
    try:
        return datetime.date.fromisoformat(v)
    except ValueError:
        return None


def code_postal(v, pays):
    if v is None or v.strip() in ("", "0"):
        return None
    v = v.strip()
    if pays in ("FR", "ES") and v.isdigit() and len(v) < 5:
        v = v.zfill(5)
    return v


def telephone(v):
    v = texte(v)
    if not v:
        return None
    chiffres = re.sub(r"[^\d+]", "", v)
    return chiffres or None


def mois_suivant(d):
    return datetime.date(d.year + (d.month == 12), d.month % 12 + 1, 1)


# ---------------------------------------------------------------- SQL

def lit(v):
    if v is None:
        return "null"
    if isinstance(v, bool):
        return "true" if v else "false"
    if isinstance(v, (int, float)):
        return str(v)
    if isinstance(v, (datetime.date, uuid.UUID)):
        return "'%s'" % v
    return "'" + str(v).replace("'", "''") + "'"


def ligne_sql(valeurs):
    return "(" + ", ".join(lit(v) for v in valeurs) + ")"


def insert(table, colonnes, lignes):
    if not lignes:
        return ""
    corps = ",\n".join(ligne_sql(l) for l in lignes)
    return "insert into %s (%s) values\n%s;\n\n" % (table, ", ".join(colonnes), corps)


# ---------------------------------------------------------------- reprise

def main(chemin_export, sortie):
    sql = open(chemin_export, encoding="utf-8").read()
    membres = lire_inserts(sql, "prod_membres")
    cotisations = lire_inserts(sql, "prod_cotisations")
    rapport = collections.defaultdict(list)
    compte = collections.Counter()

    par_membre = collections.defaultdict(list)
    for c in cotisations:
        par_membre[c["ID_membre"]].append(c)

    entites, personnes, adresses, contacts = [], [], [], []
    adhesions, cots, paiements, reglements, correspondance = [], [], [], [], []
    doublons = collections.defaultdict(list)

    for m in membres:
        ancien = int(m["ID_membre"])
        eid = uid("membre", ancien)
        nom = texte(m["Nom"]) or "(sans nom)"
        prenom = texte(m["Prenom"])
        naissance = date(m["Date_naiss"])
        pays_txt = (texte(m["Pays"]) or "France").lower()
        pays = PAYS.get(pays_txt)
        if not pays:
            pays = "FR"
            rapport["Pays inconnu, mis à FR"].append("%d : %s" % (ancien, m["Pays"]))
        categorie = CATEGORIES.get((texte(m["Type"]) or "support").lower())
        if not categorie:
            categorie = "public"
            rapport["Type inconnu, mis à public"].append("%d : %s" % (ancien, m["Type"]))
        compte["catégorie " + categorie] += 1

        commentaire = multiligne(m["Commentaire"])
        type_ancien = texte(m["Type"])
        if type_ancien and type_ancien.lower() in ("club", "association"):
            commentaire = ("[Reprise : venu(e) au titre d'un(e) %s] " % type_ancien.lower()) + (commentaire or "")

        entites.append((eid, "physique"))
        personnes.append((eid, nom, prenom, naissance, commentaire))
        correspondance.append((ancien, eid))
        doublons[(nom.lower(), (prenom or "").lower(), naissance)].append(ancien)

        if any(texte(m[k]) for k in ("Adresse", "Ville")) or code_postal(m["Code_post"], pays):
            adresses.append((uid("adresse", ancien), eid, "Domicile", texte(m["Adresse"]),
                             code_postal(m["Code_post"], pays), texte(m["Ville"]), pays))
        tel = telephone(m["Num_tel"])
        if tel:
            contacts.append((uid("telephone", ancien), eid, "telephone", tel))

        # Cotisations : une période par ligne, sans doublon ni chevauchement.
        periodes = {}
        for c in par_membre.get(m["ID_membre"], []):
            annee = int(c["Annee"] or 0)
            mois = (c["Mois"] or "").strip()
            gratuite = c["Gratuit"] == "1"
            if annee < 2000:
                rapport["Cotisation sans année, ignorée"].append("%d : cotisation %s" % (ancien, c["ID_Cotisation"]))
                continue
            if mois in ("", "0"):
                debut, fin, periodicite = datetime.date(annee, 1, 1), datetime.date(annee + 1, 1, 1), "annuelle"
            else:
                debut = datetime.date(annee, int(mois), 1)
                fin, periodicite = mois_suivant(debut), "mensuelle"
            if (debut, fin) in periodes:
                rapport["Cotisation en double (même période), fusionnée"].append(
                    "%d : %s→%s (cotisations %s et %s)" % (ancien, debut, fin, periodes[(debut, fin)][2], c["ID_Cotisation"]))
                continue
            periodes[(debut, fin)] = (periodicite, gratuite, c["ID_Cotisation"])
        retenues = []
        for (debut, fin), info in sorted(periodes.items(), key=lambda p: (p[0][0], -(p[0][1] - p[0][0]).days)):
            if retenues and debut < retenues[-1][1]:
                rapport["Cotisation recouverte par une autre, ignorée"].append(
                    "%d : %s→%s (cotisation %s)" % (ancien, debut, fin, info[2]))
                continue
            retenues.append((debut, fin, info))

        insc = date(m["Date_insc"])
        bornes = [d for d in [insc] + [r[0] for r in retenues] if d]
        debut_adh = min(bornes) if bornes else datetime.date(2009, 1, 1)
        if not insc:
            rapport["Date d'inscription absente, déduite des cotisations"].append(str(ancien))
        fin_adh = max([r[1] for r in retenues] + [debut_adh + datetime.timedelta(days=1)])
        aid = uid("adhesion", ancien)
        adhesions.append((aid, eid, "mere", categorie, "[%s,%s)" % (debut_adh, fin_adh)))

        total = 0
        for debut, fin, (periodicite, gratuite, ancien_cot) in retenues:
            montant = 0 if gratuite else (5 if periodicite == "mensuelle" else 20)
            cid = uid("cotisation", ancien_cot)
            cots.append((cid, aid, "[%s,%s)" % (debut, fin), montant, periodicite, gratuite, debut))
            compte["cotisation " + periodicite + (" gratuite" if gratuite else "")] += 1
            if montant:
                reglements.append((uid("paiement", ancien), cid, montant))
                total += montant
        if total:
            paiements.append((uid("paiement", ancien), eid, total, max(r[0] for r in retenues)))

    for cle, ids in doublons.items():
        if len(ids) > 1:
            rapport["Doublons possibles (nom, prénom, date de naissance) : non fusionnés"].append(
                "%s %s (%s) : ID %s" % (cle[1], cle[0], cle[2], ", ".join(map(str, ids))))

    # ------------------------------------------------------------ script SQL
    out = ["-- %s : généré par reprise_free.py le %s. Données personnelles.\n" % (REFERENCE, datetime.date.today()),
           "begin;\n\n",
           "create schema if not exists reprise;\n",
           "create table if not exists reprise.free_membre (ancien_id int primary key, entite_id uuid not null references annuaire.entite (id));\n\n",
           "create temp table mere on commit drop as select id from annuaire.organisation where nom = 'JaXaJ MC' and parent_id is null;\n\n"]
    out.append(insert("annuaire.entite", ["id", "nature"], entites))
    out.append(insert("annuaire.personne", ["id", "nom", "prenom", "date_naissance", "commentaire"], personnes))
    out.append(insert("annuaire.adresse", ["id", "entite_id", "libelle", "ligne1", "code_postal", "ville", "pays"], adresses))
    out.append(insert("annuaire.contact", ["id", "entite_id", "type", "valeur"], contacts))
    out.append(insert("reprise.free_membre", ["ancien_id", "entite_id"], correspondance))
    out.append("insert into adhesion.adhesion (id, membre_id, association_id, categorie, periode, commentaire)\n"
               "select v.id::uuid, v.membre::uuid, (select id from mere), v.categorie, v.periode::daterange, %s\n"
               "from (values\n%s\n) as v (id, membre, asso, categorie, periode);\n\n"
               % (lit(REFERENCE), ",\n".join(ligne_sql(a) for a in adhesions)))
    out.append("insert into adhesion.cotisation (id, adhesion_id, periode, montant, tarif_id, gratuite, motif_gratuite)\n"
               "select v.id::uuid, v.adhesion::uuid, v.periode::daterange, v.montant::numeric,\n"
               "       (adhesion.tarif_applicable('public', v.periodicite, v.debut::date)).id,\n"
               "       v.gratuite::boolean, case when v.gratuite::boolean then %s end\n"
               "from (values\n%s\n) as v (id, adhesion, periode, montant, periodicite, gratuite, debut);\n\n"
               % (lit(REFERENCE), ",\n".join(ligne_sql(c) for c in cots)))
    out.append("insert into adhesion.paiement (id, payeur_id, encaisse_par, montant, date_paiement, moyen, reference)\n"
               "select v.id::uuid, v.payeur::uuid, (select id from mere), v.montant::numeric, v.jour::date, 'autre', %s\n"
               "from (values\n%s\n) as v (id, payeur, montant, jour);\n\n"
               % (lit(REFERENCE), ",\n".join(ligne_sql(p) for p in paiements)))
    out.append(insert("adhesion.reglement", ["paiement_id", "cotisation_id", "montant"], reglements))
    out.append("commit;\n")
    open(sortie + "/reprise_free.sql", "w", encoding="utf-8").write("".join(out))

    # ------------------------------------------------------------ rapport
    r = ["# Rapport de reprise de la base Free\n",
         "Généré le %s depuis `%s`.\n" % (datetime.date.today(), chemin_export),
         "## Volumes\n",
         "| | |", "|---|---|",
         "| Membres lus | %d |" % len(membres),
         "| Cotisations lues | %d |" % len(cotisations),
         "| Personnes créées | %d |" % len(personnes),
         "| Adresses | %d |" % len(adresses),
         "| Téléphones | %d |" % len(contacts),
         "| Adhésions | %d |" % len(adhesions),
         "| Cotisations reprises | %d |" % len(cots),
         "| Paiements de reprise | %d |" % len(paiements)]
    r += ["| %s | %d |" % (k, v) for k, v in sorted(compte.items())]
    for titre, lignes in rapport.items():
        r += ["", "## %s (%d)\n" % (titre, len(lignes))] + ["- " + l for l in lignes]
    open(sortie + "/rapport_reprise.md", "w", encoding="utf-8").write("\n".join(r) + "\n")
    print("\n".join(r[3:16]))
    for titre, lignes in rapport.items():
        print("%s : %d" % (titre, len(lignes)))


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
