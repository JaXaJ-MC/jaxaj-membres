<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Periode;
use App\Filter\Egal;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use App\Validator\SansChevauchement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rôle d'une personne dans une organisation, sur une période : président, trésorier… d'une
 * association JaXaJ, sergent d'armes, chef de file, ou représentant d'un club ou d'une
 * association. La base garantit un seul titulaire à la fois par organisation, sauf pour
 * « représentant ».
 */
#[ORM\Entity]
#[ORM\Table(name: 'representation', schema: 'annuaire')]
#[ApiResource(
    shortName: 'Role',
    operations: [
        new GetCollection(
            security: "is_granted('MEMBRES_LIRE')",
            parameters: [
                'personne' => new QueryParameter(filter: new Egal('personne'), description: 'UUID de la personne'),
                'organisation' => new QueryParameter(filter: new Egal('organisation'), description: 'UUID de l\'organisation'),
            ],
        ),
        new Get(security: "is_granted('MEMBRES_VOIR', object)"),
        new Post(securityPostDenormalize: "is_granted('MEMBRES_ECRIRE_ROLE', object)"),
        new Patch(
            security: "is_granted('MEMBRES_ECRIRE_ROLE', object)",
            denormalizationContext: ['groups' => ['role:modifier']],
        ),
        new Delete(security: "is_granted('MEMBRES_ADMIN')"),  // saisie erronée ; une fin de mandat se note avec « fin »
    ],
    normalizationContext: ['groups' => ['role:lire']],
    denormalizationContext: ['groups' => ['role:ecrire', 'role:modifier']],
)]
#[SansChevauchement]
class Representation
{
    public const ROLES = ['president', 'vice_president', 'tresorier', 'vice_tresorier', 'secretaire', 'sergent_armes', 'chef_de_file', 'representant'];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups(['role:lire'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'personne_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['role:lire', 'role:ecrire'])]
    private ?Personne $personne = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'organisation_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['role:lire', 'role:ecrire'])]
    private ?Organisation $organisation = null;

    #[ORM\Column]
    #[Assert\Choice(choices: self::ROLES, message: 'Rôle inconnu : president, vice_president, tresorier, vice_tresorier, secretaire, sergent_armes, chef_de_file ou representant.')]
    #[Groups(['role:lire', 'role:ecrire'])]
    private string $role = 'representant';

    #[ORM\Column(type: 'daterange')]
    private ?Periode $periode = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->periode = new Periode(new \DateTimeImmutable('today'));
    }

    public function getId(): Uuid { return $this->id; }
    public function getPersonne(): ?Personne { return $this->personne; }
    public function setPersonne(Personne $p): void { $this->personne = $p; }
    public function getOrganisation(): ?Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $o): void { $this->organisation = $o; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $role): void { $this->role = $role; }

    #[Groups(['role:lire'])]
    public function getDebut(): ?\DateTimeImmutable { return $this->periode?->debut; }

    #[Groups(['role:ecrire', 'role:modifier'])]
    public function setDebut(\DateTimeImmutable $debut): void { $this->periode = new Periode($debut, $this->periode?->fin, false); }

    /** Dernier jour du mandat, inclus ; vide tant qu'il est en cours. */
    #[Groups(['role:lire'])]
    public function getFin(): ?\DateTimeImmutable { return $this->periode?->fin; }

    #[Groups(['role:ecrire', 'role:modifier'])]
    public function setFin(?\DateTimeImmutable $fin): void { $this->periode = new Periode($this->periode?->debut, $fin, false); }

    #[Assert\IsTrue(message: 'La date de fin précède la date de début.')]
    public function isPeriodeCoherente(): bool { return $this->periode?->coherente() ?? true; }
}
