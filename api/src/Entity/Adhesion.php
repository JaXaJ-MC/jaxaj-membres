<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
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
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adhésion d'une personne à une association, dans une catégorie, sur une période (début et
 * fin inclus, fin vide = en cours). Passer de suiveur à full patch : clore l'une, ouvrir l'autre.
 */
#[ORM\Entity]
#[ORM\Table(name: 'adhesion', schema: 'adhesion')]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('MEMBRES_LIRE')",
            parameters: [
                'membre' => new QueryParameter(filter: new Egal('membre'), description: 'UUID de la personne'),
                'association' => new QueryParameter(filter: new Egal('association'), description: 'UUID de l\'association'),
            ],
        ),
        new Get(security: "is_granted('MEMBRES_LIRE')"),
        new Post(securityPostDenormalize: "is_granted('MEMBRES_ECRIRE_ADHESION', object)"),
        new Patch(
            security: "is_granted('MEMBRES_ECRIRE_ADHESION', object)",
            securityPostDenormalize: "is_granted('MEMBRES_ECRIRE_ADHESION', object)",
            denormalizationContext: ['groups' => ['adhesion:modifier']],
        ),
    ],
    normalizationContext: ['groups' => ['adhesion:lire']],
    denormalizationContext: ['groups' => ['adhesion:ecrire', 'adhesion:modifier']],
)]
class Adhesion
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups(['adhesion:lire'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'membre_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['adhesion:lire', 'adhesion:ecrire'])]
    private ?Personne $membre = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'association_id', nullable: false)]
    #[Assert\NotNull]
    #[Assert\Expression("value === null or value.getGenre() === 'association_jaxaj'", message: 'L\'adhésion se fait à une association JaXaJ.')]
    #[Groups(['adhesion:lire', 'adhesion:ecrire'])]
    private ?Organisation $association = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'categorie', referencedColumnName: 'code', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['adhesion:lire', 'adhesion:ecrire', 'adhesion:modifier'])]
    private ?Categorie $categorie = null;

    #[ORM\Column(type: 'daterange')]
    private ?Periode $periode = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'au_titre_de')]
    #[Groups(['adhesion:lire', 'adhesion:ecrire', 'adhesion:modifier'])]
    private ?Organisation $auTitreDe = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['adhesion:lire', 'adhesion:ecrire', 'adhesion:modifier'])]
    private ?string $commentaire = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->periode = new Periode(new \DateTimeImmutable('today'));
    }

    public function getId(): Uuid { return $this->id; }
    public function getMembre(): ?Personne { return $this->membre; }
    public function setMembre(Personne $membre): void { $this->membre = $membre; }
    public function getAssociation(): ?Organisation { return $this->association; }
    public function setAssociation(Organisation $association): void { $this->association = $association; }
    public function getCategorie(): ?Categorie { return $this->categorie; }
    public function setCategorie(Categorie $categorie): void { $this->categorie = $categorie; }
    public function getAuTitreDe(): ?Organisation { return $this->auTitreDe; }
    public function setAuTitreDe(?Organisation $o): void { $this->auTitreDe = $o; }
    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $c): void { $this->commentaire = $c; }
    public function getPeriode(): ?Periode { return $this->periode; }

    #[Groups(['adhesion:lire'])]
    public function getDebut(): ?\DateTimeImmutable { return $this->periode?->debut; }

    #[Groups(['adhesion:ecrire', 'adhesion:modifier'])]
    public function setDebut(\DateTimeImmutable $debut): void { $this->periode = new Periode($debut, $this->periode?->fin); }

    /** Dernier jour inclus ; vide tant que l'adhésion est en cours. */
    #[Groups(['adhesion:lire'])]
    public function getFin(): ?\DateTimeImmutable { return $this->periode?->fin; }

    #[Groups(['adhesion:ecrire', 'adhesion:modifier'])]
    public function setFin(?\DateTimeImmutable $fin): void { $this->periode = new Periode($this->periode?->debut, $fin); }
}
