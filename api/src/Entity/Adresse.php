<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'adresse', schema: 'annuaire')]
class Adresse
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(nullable: true)]
    private ?string $libelle = 'Domicile';
    #[ORM\Column(nullable: true)]
    private ?string $ligne1 = null;
    #[ORM\Column(nullable: true)]
    private ?string $ligne2 = null;
    #[ORM\Column(name: 'code_postal', nullable: true)]
    private ?string $codePostal = null;
    #[ORM\Column(nullable: true)]
    private ?string $ville = null;
    #[ORM\Column(length: 2, options: ['fixed' => true])]
    private string $pays = 'FR';
    #[ORM\Column]
    private bool $principale = true;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'adresses')]
        #[ORM\JoinColumn(name: 'entite_id', nullable: false, onDelete: 'CASCADE')]
        private Personne $personne,
    ) {
        $this->id = Uuid::v7();
    }

    public function estPrincipale(): bool { return $this->principale; }

    public function versTableau(): array
    {
        return ['ligne1' => $this->ligne1, 'ligne2' => $this->ligne2, 'code_postal' => $this->codePostal,
            'ville' => $this->ville, 'pays' => $this->pays];
    }

    public function depuisTableau(array $a): void
    {
        $v = fn (string $cle, ?string $actuel) => array_key_exists($cle, $a) ? (trim((string) $a[$cle]) ?: null) : $actuel;
        $this->ligne1 = $v('ligne1', $this->ligne1);
        $this->ligne2 = $v('ligne2', $this->ligne2);
        $this->codePostal = $v('code_postal', $this->codePostal);
        $this->ville = $v('ville', $this->ville);
        $this->pays = strtoupper($v('pays', $this->pays) ?? 'FR');
    }
}
