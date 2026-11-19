<?php

namespace App\Serializer;

use ApiPlatform\State\SerializerContextBuilderInterface;
use App\Entity\Personne;
use App\Security\DroitsMembres;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\SecurityBundle\Security;

/** Coordonnées et date de naissance d'une personne : seulement pour qui a le droit DETAIL. */
#[AsDecorator('api_platform.serializer.context_builder')]
final class GroupesSelonDroits implements SerializerContextBuilderInterface
{
    public function __construct(
        #[AutowireDecorated] private SerializerContextBuilderInterface $decore,
        private Security $securite,
    ) {}

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $contexte = $this->decore->createFromRequest($request, $normalization, $extractedAttributes);
        if ($normalization && Personne::class === ($contexte['resource_class'] ?? null)
            && $this->securite->isGranted(DroitsMembres::DETAIL)) {
            $contexte['groups'][] = 'personne:detail';
        }

        return $contexte;
    }
}
