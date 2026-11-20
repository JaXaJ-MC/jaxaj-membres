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
 *   membres:gestion[:asso]      personnes, adhésions, cotisations et rôles (de l'association donnée)
 *   membres:accueil[:asso]      à l'entrée d'un événement : rechercher, inscrire une personne,
 *                               lui faire adhérer comme « adhérent événement » (de l'association)
 *
 * Ce que chacun voit (personnes, détail, historique) : voir Visibilite.
 */
final class DroitsMembres extends Voter
{
    public const LIRE = 'MEMBRES_LIRE';               // rechercher, consulter
    public const ECRIRE_PERSONNE = 'MEMBRES_ECRIRE_PERSONNE';
    public const ECRIRE_ADHESION = 'MEMBRES_ECRIRE_ADHESION'; // sujet : Adhesion ou Cotisation
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
        $gestion = $u->perimetre('gestion');
        $accueil = $u->perimetre('accueil');

        return match ($attribute) {
            self::LIRE, self::ECRIRE_PERSONNE => null !== $gestion || null !== $accueil,
            self::ECRIRE_ADHESION => $this->adhesion($subject, $gestion, $accueil),
            self::ECRIRE_ROLE => $this->role($subject, $gestion),
            self::VOIR => (null !== $gestion || null !== $accueil) && $this->voir($subject),
            self::MODIFIER_PERSONNE => (null !== $gestion || null !== $accueil)
                && $subject instanceof Personne && $this->visibilite->voitDetail((string) $subject->getId()),
            default => false,
        };
    }

    private function voir(mixed $sujet): bool
    {
        return match (true) {
            $sujet instanceof Personne => $this->visibilite->voitPersonne((string) $sujet->getId()),
            $sujet instanceof Adhesion => $this->visibilite->voitAssociation($sujet->getAssociation()?->getId()?->toRfc4122()),
            $sujet instanceof Cotisation => $this->visibilite->voitAssociation($sujet->getAdhesion()?->getAssociation()?->getId()?->toRfc4122()),
            $sujet instanceof Representation => $this->visibilite->voitAssociation($sujet->getOrganisation()?->getId()?->toRfc4122()),
            default => false,
        };
    }

    /** Rôles : la gestion, dans son périmètre (l'organisation du rôle, ou toutes). */
    private function role(mixed $sujet, ?array $gestion): bool
    {
        if (null === $gestion) {
            return false;
        }
        $organisation = $sujet instanceof Representation ? $sujet->getOrganisation() : null;

        return [] === $gestion || ($organisation && in_array((string) $organisation->getId(), $gestion, true));
    }

    private function adhesion(mixed $sujet, ?array $gestion, ?array $accueil): bool
    {
        $adhesion = $sujet instanceof Cotisation ? $sujet->getAdhesion() : $sujet;
        if (!$adhesion instanceof Adhesion || !$adhesion->getAssociation()) {
            return null !== $gestion;
        }
        $asso = (string) $adhesion->getAssociation()->getId();
        $couvre = fn (?array $p) => null !== $p && ([] === $p || in_array($asso, $p, true));

        return $couvre($gestion)
            || ($couvre($accueil) && 'public' === $adhesion->getCategorie()?->getCode());
    }
}
