<?php

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Qui voit qui, et avec quel détail.
 *
 *   admin, gestion (toutes associations)   tout le monde, tout le détail
 *   accueil                                 tout le monde (pour identifier qui se présente à
 *                                           l'entrée) ; le détail des seules fiches créées le
 *                                           jour même, pour relire sa saisie
 *   gestion d'une association (ou l'une de   les personnes
 *   ses capacités : inscription, historique,
 *   trésorerie) qui ont ou ont eu une adhésion ou un
 *                                           rôle dans cette association, avec leur détail et
 *                                           tout leur historique (la mère, d'autres chapitres :
 *                                           pour le vérifier avec elles) ; elle n'écrit que dans
 *                                           son association
 *
 * Les droits se cumulent : accueil + gestion du Béarn voit tout le monde, avec le détail des
 * personnes du Béarn et des fiches du jour.
 */
final class Visibilite
{
    public function __construct(private Security $securite, private Connection $base) {}

    private function utilisateur(): ?Utilisateur
    {
        $u = $this->securite->getUser();

        return $u instanceof Utilisateur ? $u : null;
    }

    /** Toutes associations : admin ou gestion sans périmètre. */
    public function toutDetail(): bool
    {
        $u = $this->utilisateur();

        return $u && ([] === $u->perimetre('admin') || [] === $u->equipe());
    }

    /** Voit tout le monde (sans forcément le détail) : toutes associations, ou accueil. */
    public function toutLeMonde(): bool
    {
        return $this->toutDetail() || null !== $this->utilisateur()?->perimetre('accueil');
    }

    /**
     * Associations de la gestion limitée, quand c'est elle qui borne ce que voit l'utilisateur :
     * null si l'utilisateur voit tout le monde (ou n'a pas de gestion limitée).
     */
    public function perimetre(): ?array
    {
        if ($this->toutLeMonde()) {
            return null;
        }

        return $this->utilisateur()?->equipe() ?: [];  // [] : ne voit personne
    }

    /** Associations dont l'utilisateur voit le détail des membres, hors « tout le détail ». */
    private function associationsDetail(): array
    {
        return $this->toutDetail() ? [] : ($this->utilisateur()?->equipe() ?? []);
    }

    public function voitPersonne(string $id): bool
    {
        return $this->toutLeMonde() || $this->dansAssociations($id, $this->perimetre() ?? []);
    }

    public function voitDetail(string $id): bool
    {
        if ($this->toutDetail()) {
            return true;
        }
        if ($this->dansAssociations($id, $this->associationsDetail())) {
            return true;
        }

        return null !== $this->utilisateur()?->perimetre('accueil') && $this->creeAujourdhui($id);
    }


    private function dansAssociations(string $id, array $associations): bool
    {
        if (!$associations) {
            return false;
        }

        return (bool) $this->base->fetchOne(
            'select 1 from adhesion.adhesion where membre_id = :id and association_id = any (cast(:a as uuid[]))
             union all
             select 1 from annuaire.representation where personne_id = :id and organisation_id = any (cast(:a as uuid[]))
             limit 1',
            ['id' => $id, 'a' => '{'.implode(',', $associations).'}']);
    }

    private function creeAujourdhui(string $id): bool
    {
        return (bool) $this->base->fetchOne(
            'select 1 from annuaire.personne where id = :id and cree_le >= current_date', ['id' => $id]);
    }

    /** Associations où l'utilisateur a cette capacité : null = toutes (admin, ou capacité sans périmètre), [] = aucune. */
    public function associationsPour(string $capacite): ?array
    {
        $u = $this->utilisateur();
        if (!$u) {
            return [];
        }
        if ([] === $u->perimetre('admin') || [] === $u->capacite($capacite)) {
            return null;
        }

        return $u->capacite($capacite) ?? [];
    }

    /** Condition SQL « personne visible » sur une colonne d'identifiant de personne, pour les listes. */
    public function conditionPersonne(string $colonne): array
    {
        $p = $this->perimetre();
        if (null === $p) {
            return ['true', []];
        }

        return ["($colonne in (select membre_id from adhesion.adhesion where association_id = any (cast(:perimetre as uuid[])))
                 or $colonne in (select personne_id from annuaire.representation where organisation_id = any (cast(:perimetre as uuid[]))))",
            ['perimetre' => '{'.implode(',', $p).'}']];
    }
}
