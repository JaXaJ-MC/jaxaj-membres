<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'contact', schema: 'annuaire')]
class Contact
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column]
    private bool $principal = true;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'contacts')]
        #[ORM\JoinColumn(name: 'entite_id', nullable: false, onDelete: 'CASCADE')]
        private Personne $personne,
        #[ORM\Column]
        private string $type,
        #[ORM\Column]
        private string $valeur,
    ) {
        $this->id = Uuid::v7();
    }

    public function getType(): string { return $this->type; }
    public function getValeur(): string { return $this->valeur; }
    public function setValeur(string $valeur): void { $this->valeur = $valeur; }
    public function estPrincipal(): bool { return $this->principal; }
}
