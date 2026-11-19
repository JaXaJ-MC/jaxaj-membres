<?php

namespace App\Security;

use App\Entity\Adhesion;
use App\Entity\Cotisation;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits de l'application membres :
 *   membres:admin               tout
 *   membres:gestion[:asso]      personnes, adhésions et cotisations (de l'association donnée)
 *   membres:accueil[:asso]      à l'entrée d'un événement : rechercher, inscrire une personne,
 *                               lui faire adhérer comme « adhérent événement » (de l'association)
 */
final class DroitsMembres extends Voter
{
    public const LIRE = 'MEMBRES_LIRE';               // rechercher, consulter
    public const DETAIL = 'MEMBRES_DETAIL';           // adresse, téléphone, date de naissance…
    public const ECRIRE_PERSONNE = 'MEMBRES_ECRIRE_PERSONNE';
    public const ECRIRE_ADHESION = 'MEMBRES_ECRIRE_ADHESION'; // sujet : Adhesion ou Cotisation
    public const ADMIN = 'MEMBRES_ADMIN';

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
            self::DETAIL => null !== $gestion,
            self::ECRIRE_ADHESION => $this->adhesion($subject, $gestion, $accueil),
            default => false,
        };
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
