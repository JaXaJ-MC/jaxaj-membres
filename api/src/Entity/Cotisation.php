<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Periode;
use App\Filter\Egal;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ce qui est dû pour une période (un mois, une année, un événement). Sans montant, le tarif en
 * vigueur s'applique (voir TarifAutomatique).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotisation', schema: 'adhesion')]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('MEMBRES_LIRE')",
            parameters: [
                'adhesion' => new QueryParameter(filter: new Egal('adhesion'), description: 'UUID de l\'adhésion'),
                'membre' => new QueryParameter(filter: new Egal('adhesion.membre'), description: 'UUID de la personne'),
            ],
        ),
        new Get(security: "is_granted('MEMBRES_VOIR', object)"),
        new Post(securityPostDenormalize: "is_granted('MEMBRES_ECRIRE_ADHESION', object)"),
    ],
    normalizationContext: ['groups' => ['cotisation:lire']],
    denormalizationContext: ['groups' => ['cotisation:ecrire']],
)]
class Cotisation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups(['cotisation:lire'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'adhesion_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['cotisation:lire', 'cotisation:ecrire'])]
    private ?Adhesion $adhesion = null;

    #[ORM\Column(type: 'daterange')]
    private ?Periode $periode = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['cotisation:lire', 'cotisation:ecrire'])]
    private ?string $montant = null;

    #[ORM\Column(name: 'tarif_id', type: 'uuid', nullable: true)]
    private ?Uuid $tarif = null;

    #[ORM\Column]
    #[Groups(['cotisation:lire', 'cotisation:ecrire'])]
    private bool $gratuite = false;

    #[ORM\Column(name: 'motif_gratuite', nullable: true)]
    #[Groups(['cotisation:lire', 'cotisation:ecrire'])]
    private ?string $motifGratuite = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid { return $this->id; }
    public function getAdhesion(): ?Adhesion { return $this->adhesion; }
    public function setAdhesion(Adhesion $a): void { $this->adhesion = $a; }
    public function getPeriode(): ?Periode { return $this->periode; }
    public function getMontant(): ?string { return $this->montant; }
    public function setMontant(?string $m): void { $this->montant = $m; }
    public function appliquerTarif(Uuid $tarif, string $montant): void { $this->tarif = $tarif; $this->montant = $montant; }
    public function isGratuite(): bool { return $this->gratuite; }
    public function setGratuite(bool $g): void { $this->gratuite = $g; }
    public function getMotifGratuite(): ?string { return $this->motifGratuite; }
    public function setMotifGratuite(?string $m): void { $this->motifGratuite = $m; }

    #[Groups(['cotisation:lire'])]
    public function getDebut(): ?\DateTimeImmutable { return $this->periode?->debut; }

    #[Groups(['cotisation:ecrire'])]
    public function setDebut(\DateTimeImmutable $debut): void
    {
        // Par défaut, une cotisation couvre le mois qui commence à cette date.
        $this->periode = new Periode($debut, $this->periode?->fin ?? $debut->modify('+1 month -1 day'));
    }

    #[Groups(['cotisation:lire'])]
    public function getFin(): ?\DateTimeImmutable { return $this->periode?->fin; }

    #[Groups(['cotisation:ecrire'])]
    public function setFin(\DateTimeImmutable $fin): void { $this->periode = new Periode($this->periode?->debut, $fin); }
}
