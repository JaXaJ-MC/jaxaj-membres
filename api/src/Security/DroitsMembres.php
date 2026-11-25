<?php

namespace App\Security;

use App\Entity\Adhesion;
use App\Entity\Cotisation;
use App\Entity\Personne;
use App\Entity\Representation;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits de l'application membres :
 *   membres:admin               tout
 *   membres:gestion[:asso]      personnes, adhésions, cotisations et rôles (de l'association donnée) :
 *                               les trois capacités suivantes, qu'on peut aussi donner une par une
 *   membres:inscription[:asso]  créer des fiches et de nouvelles adhésions
 *   membres:historique[:asso]   corriger les adhésions (dates, niveau, exemption), les rôles ;
 *                               calculer les cotisations
 *   membres:tresorerie[:asso]   encaisser, créer des cotisations
 *   membres:accueil[:asso]      à l'entrée d'un événement : rechercher, inscrire une personne,
 *                               lui faire adhérer comme « adhérent événement » (de l'association)
 *
 * Ce que chacun voit (personnes, détail, historique) : voir Visibilite.
 */
final class DroitsMembres extends Voter
{
    public const LIRE = 'MEMBRES_LIRE';               // rechercher, consulter
    public const ECRIRE_PERSONNE = 'MEMBRES_ECRIRE_PERSONNE';
    public const CREER_ADHESION = 'MEMBRES_CREER_ADHESION';     // sujet : Adhesion (nouvelle)
    public const MODIFIER_ADHESION = 'MEMBRES_MODIFIER_ADHESION'; // sujet : Adhesion
    public const ENCAISSER = 'MEMBRES_ENCAISSER';               // sujet : Adhesion ou Cotisation
    public const ECRIRE_ROLE = 'MEMBRES_ECRIRE_ROLE';     // sujet : Representation
    public const ADMIN = 'MEMBRES_ADMIN';
    public const VOIR = 'MEMBRES_VOIR';               // sujet : Personne, Adhesion, Cotisation, Representation
    public const MODIFIER_PERSONNE = 'MEMBRES_MODIFIER_PERSONNE'; // sujet : Personne

    public function __construct(private Visibilite $visibilite) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, 'MEMBRES_');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $u = $token->getUser();
        if (!$u instanceof Utilisateur) {
            return false;
        }
        if ([] === $u->perimetre('admin')) {
            return true;
        }
        $equipe = $u->equipe();
        $accueil = $u->perimetre('accueil');

        return match ($attribute) {
            self::LIRE, self::ECRIRE_PERSONNE => null !== $equipe || null !== $accueil,
            self::CREER_ADHESION => $this->adhesion($subject, $u->capacite('inscription'), $accueil),
            self::MODIFIER_ADHESION => $this->adhesion($subject, $u->capacite('historique'), null),
            self::ENCAISSER => $this->adhesion($subject, $u->capacite('tresorerie'), $accueil),
            self::ECRIRE_ROLE => $this->role($subject, $u->capacite('historique')),
            self::VOIR => (null !== $equipe || null !== $accueil) && $this->voir($subject),
            self::MODIFIER_PERSONNE => (null !== $equipe || null !== $accueil)
                && $subject instanceof Personne && $this->visibilite->voitDetail((string) $subject->getId()),
            default => false,
        };
    }

    private function voir(mixed $sujet): bool
    {
        return match (true) {
            $sujet instanceof Personne => $this->visibilite->voitPersonne((string) $sujet->getId()),
            // L'historique d'une personne se voit avec elle.
            $sujet instanceof Adhesion => $this->visibilite->voitPersonne((string) $sujet->getMembre()?->getId()),
            $sujet instanceof Cotisation => $this->visibilite->voitPersonne((string) $sujet->getAdhesion()?->getMembre()?->getId()),
            $sujet instanceof Representation => $this->visibilite->voitPersonne((string) $sujet->getPersonne()?->getId()),
            default => false,
        };
    }

    /** Rôles : la capacité « historique », dans son périmètre (l'organisation du rôle, ou toutes). */
    private function role(mixed $sujet, ?array $gestion): bool
    {
        if (null === $gestion) {
            return false;
        }
        $organisation = $sujet instanceof Representation ? $sujet->getOrganisation() : null;

        return [] === $gestion || ($organisation && in_array((string) $organisation->getId(), $gestion, true));
    }

    /** Adhésion ou cotisation : la capacité dans l'association, ou l'accueil pour le public. */
    private function adhesion(mixed $sujet, ?array $capacite, ?array $accueil): bool
    {
        $adhesion = $sujet instanceof Cotisation ? $sujet->getAdhesion() : $sujet;
        if (!$adhesion instanceof Adhesion || !$adhesion->getAssociation()) {
            return null !== $capacite;
        }
        $asso = (string) $adhesion->getAssociation()->getId();
        $couvre = fn (?array $p) => null !== $p && ([] === $p || in_array($asso, $p, true));

        return $couvre($capacite)
            || ($couvre($accueil) && 'public' === $adhesion->getCategorie()?->getCode());
    }
}
