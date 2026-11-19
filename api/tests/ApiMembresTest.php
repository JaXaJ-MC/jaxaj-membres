<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API membres avec les jetons de l'appli comptes : droits par profil, recherche, coordonnées
 * masquées pour l'accueil, adhésions, cotisations au tarif en vigueur, contrôle d'accès.
 * Chaque test est annulé à la fin (DAMA).
 */
class ApiMembresTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** Jeton tel que l'appli comptes le délivre. */
    private function jeton(array $droits): array
    {
        $jwt = static::getContainer()->get(JWTEncoderInterface::class)->encode([
            'username' => 'test@example.org', 'compte' => '0190f1d2-0000-7000-8000-000000000001',
            'personne' => '0190f1d2-0000-7000-8000-000000000002', 'droits' => $droits,
        ]);

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$jwt, 'HTTP_ACCEPT' => 'application/json'];
    }

    private function association(string $nom): string
    {
        return static::getContainer()->get(Connection::class)
            ->fetchOne('select id from annuaire.organisation where nom = :n', ['n' => $nom]);
    }

    private function envoyer(string $methode, string $chemin, array $corps, array $jeton): array
    {
        $type = 'PATCH' === $methode ? 'application/merge-patch+json' : 'application/json';
        $this->client->request($methode, $chemin, server: $jeton + ['CONTENT_TYPE' => $type], content: json_encode($corps));

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function lire(string $chemin, array $jeton): array
    {
        $this->client->request('GET', $chemin, server: $jeton);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testAuthentificationEtDroits(): void
    {
        $this->client->request('GET', '/api/personnes');
        self::assertResponseStatusCodeSame(401);

        $this->lire('/api/personnes', $this->jeton(['calendrier:admin']));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/personnes', server: ['HTTP_AUTHORIZATION' => 'Bearer faux.jeton.signe']);
        self::assertResponseStatusCodeSame(401);

        $this->lire('/api/personnes', $this->jeton(['membres:admin']));
        self::assertResponseIsSuccessful();
    }

    public function testFichePersonneEtRecherche(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $fiche = $this->envoyer('POST', '/api/personnes', [
            'nom' => 'Garçon', 'prenom' => 'José', 'surnom' => 'Jojo', 'dateNaissance' => '1985-06-15',
            'telephone' => '06 12 34 56 78', 'email' => 'jose@example.org',
            'adresse' => ['ligne1' => '1 rue du Port', 'code_postal' => '64100', 'ville' => 'Bayonne', 'pays' => 'FR'],
        ], $admin);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('0612345678', $fiche['telephone']);
        self::assertSame('Bayonne', $fiche['adresse']['ville']);

        // Recherche sans accents ni majuscules, plusieurs mots.
        foreach (['jose', 'GARCON', 'jojo garc', 'José Garçon'] as $q) {
            $trouves = $this->lire('/api/personnes?q='.urlencode($q), $admin);
            self::assertSame(['José'], array_column($trouves, 'prenom'), "recherche « $q »");
        }
        self::assertSame([], $this->lire('/api/personnes?q=inconnu', $admin));

        // Modification partielle.
        $modifiee = $this->envoyer('PATCH', '/api/personnes/'.$fiche['id'], ['telephone' => null, 'adresse' => ['ville' => 'Anglet']], $admin);
        self::assertResponseIsSuccessful();
        self::assertNull($modifiee['telephone']);
        self::assertSame(['ligne1' => '1 rue du Port', 'ligne2' => null, 'code_postal' => '64100', 'ville' => 'Anglet', 'pays' => 'FR'], $modifiee['adresse']);

        // Validation.
        $this->envoyer('POST', '/api/personnes', ['nom' => 'X', 'email' => 'pas-un-mail'], $admin);
        self::assertResponseStatusCodeSame(422);
        $this->envoyer('POST', '/api/personnes', ['nom' => ''], $admin);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAccueilVoitPeuEtNInscritQueDuPublic(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $prod = $this->association('JaXaJ Productions');
        $accueil = $this->jeton(['membres:accueil:'.$prod]);
        $id = $this->envoyer('POST', '/api/personnes', ['nom' => 'Durand', 'dateNaissance' => '1990-01-01',
            'telephone' => '0600000000'], $admin)['id'];

        // L'accueil lit la fiche sans les coordonnées ni la date de naissance.
        $fiche = $this->lire('/api/personnes/'.$id, $accueil);
        self::assertSame('Durand', $fiche['nom']);
        self::assertArrayNotHasKey('telephone', $fiche);
        self::assertArrayNotHasKey('dateNaissance', $fiche);

        // Il inscrit une personne sur place, et la fait adhérer comme adhérent événement à Productions.
        $nouveau = $this->envoyer('POST', '/api/personnes', ['nom' => 'Martin', 'email' => 'martin@example.org'], $accueil);
        self::assertResponseStatusCodeSame(201);
        $adhesion = ['membre' => '/api/personnes/'.$nouveau['id'], 'association' => '/api/organisations/'.$prod,
            'categorie' => '/api/categories/public', 'debut' => '2027-03-13'];
        $this->envoyer('POST', '/api/adhesions', $adhesion, $accueil);
        self::assertResponseStatusCodeSame(201);

        // Mais pas en full patch, ni auprès d'une autre association.
        $this->envoyer('POST', '/api/adhesions', ['categorie' => '/api/categories/full_patch', 'debut' => '2027-06-01'] + $adhesion, $accueil);
        self::assertResponseStatusCodeSame(403);
        $this->envoyer('POST', '/api/adhesions', ['association' => '/api/organisations/'.$this->association('JaXaJ MC Béarn'), 'debut' => '2027-06-01'] + $adhesion, $accueil);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdhesionsCotisationsEtAcces(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $bearn = $this->association('JaXaJ MC Béarn');
        $id = $this->envoyer('POST', '/api/personnes', ['nom' => 'Lacoste', 'prenom' => 'Pierre'], $admin)['id'];
        $base = ['membre' => '/api/personnes/'.$id, 'association' => '/api/organisations/'.$bearn];

        // Suiveur de janvier à mars, puis full patch : l'historique est conservé.
        $suiveur = $this->envoyer('POST', '/api/adhesions', $base + ['categorie' => '/api/categories/suiveur', 'debut' => '2027-01-01', 'fin' => '2027-03-31'], $admin);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2027-03-31', substr($suiveur['fin'], 0, 10));
        $patch = $this->envoyer('POST', '/api/adhesions', $base + ['categorie' => '/api/categories/full_patch', 'debut' => '2027-04-01'], $admin);
        self::assertResponseStatusCodeSame(201);

        // Chevauchement refusé par la base, rendu en 409.
        $conflit = $this->envoyer('POST', '/api/adhesions', $base + ['categorie' => '/api/categories/support', 'debut' => '2027-03-15'], $admin);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Chevauchement', $conflit['detail']);

        // Association qui n'est pas une association JaXaJ : refusée.
        $this->envoyer('POST', '/api/adhesions', ['association' => '/api/organisations/'.$this->association('JaXaJ MC').'-x'] + $base + ['categorie' => '/api/categories/support'], $admin);
        self::assertResponseStatusCodeSame(400);

        self::assertCount(2, $this->lire('/api/adhesions?membre='.$id, $admin));

        // Cotisation d'avril sans montant : tarif full patch 2027 (repris de 2026), 60 €.
        $avril = $this->envoyer('POST', '/api/cotisations', ['adhesion' => '/api/adhesions/'.$patch['id'], 'debut' => '2027-04-01'], $admin);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('60.00', $avril['montant']);
        self::assertSame('2027-04-30', substr($avril['fin'], 0, 10));

        // Pas de tarif : message clair.
        $sans = $this->envoyer('POST', '/api/cotisations', ['adhesion' => '/api/adhesions/'.$patch['id'], 'debut' => '2031-04-01'], $admin);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Aucun tarif', $sans['detail']);

        // Accès : cotisation d'avril non réglée → refusé ; mai gratuit → accordé.
        $acces = $this->lire("/api/personnes/$id/acces?jour=2027-04-12", $admin);
        self::assertResponseIsSuccessful();
        self::assertFalse($acces['acces']);
        self::assertCount(1, $acces['cotisations']);
        $this->envoyer('POST', '/api/cotisations', ['adhesion' => '/api/adhesions/'.$patch['id'], 'debut' => '2027-05-01',
            'gratuite' => true, 'motifGratuite' => 'Organisation de l\'événement'], $admin);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->lire("/api/personnes/$id/acces?jour=2027-05-20", $admin)['acces']);
        self::assertFalse($this->lire("/api/personnes/$id/acces?jour=2027-05-20&association=".$this->association('JaXaJ Productions'), $admin)['acces']);
        self::assertTrue($this->lire("/api/personnes/$id/acces?jour=2027-05-20&association[]=".$this->association('JaXaJ Productions').'&association[]='.$bearn, $admin)['acces']);

        // Clore l'adhésion full patch.
        $close = $this->envoyer('PATCH', '/api/adhesions/'.$patch['id'], ['fin' => '2027-12-31'], $admin);
        self::assertResponseIsSuccessful();
        self::assertSame('2027-12-31', substr($close['fin'], 0, 10));
    }
}
