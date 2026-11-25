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

    private function creeeHier(string $personne): void
    {
        static::getContainer()->get(Connection::class)->executeStatement(
            "update annuaire.personne set cree_le = now() - interval '1 day' where id = :id", ['id' => $personne]);
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
        $this->creeeHier($id);

        // L'accueil lit une fiche ancienne sans les coordonnées ni la date de naissance.
        $fiche = $this->lire('/api/personnes/'.$id, $accueil);
        self::assertSame('Durand', $fiche['nom']);
        self::assertArrayNotHasKey('telephone', $fiche);
        self::assertArrayNotHasKey('dateNaissance', $fiche);

        // Il inscrit une personne sur place, et la fait adhérer comme adhérent événement à Productions.
        $nouveau = $this->envoyer('POST', '/api/personnes', ['nom' => 'Martin', 'email' => 'martin@example.org'], $accueil);
        self::assertResponseStatusCodeSame(201);

        // Une fiche du jour, il la relit en entier et peut la corriger ; une ancienne, non.
        $fiche = $this->lire('/api/personnes/'.$nouveau['id'], $accueil);
        self::assertSame('martin@example.org', $fiche['email']);
        self::assertTrue($fiche['detail']);  // signale à l'interface que le détail est visible
        $this->envoyer('PATCH', '/api/personnes/'.$nouveau['id'], ['email' => 'p.martin@example.org'], $accueil);
        self::assertResponseIsSuccessful();
        $this->envoyer('PATCH', '/api/personnes/'.$id, ['nom' => 'Dupont'], $accueil);
        self::assertResponseStatusCodeSame(403);
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

        // Chevauchement refusé, avec l'adhésion qui gêne.
        $conflit = $this->envoyer('POST', '/api/adhesions', $base + ['categorie' => '/api/categories/support', 'debut' => '2027-03-15'], $admin);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('déjà une adhésion Suiveur à JaXaJ MC Béarn du 01/01/2027 au 31/03/2027', $conflit['detail']);

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

    public function testListeEtPaiementDuMois(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $bearn = $this->association('JaXaJ MC Béarn');
        $avec = $this->envoyer('POST', '/api/personnes', ['nom' => 'Aaaa-Liste', 'prenom' => 'Avec'], $admin)['id'];
        $sans = $this->envoyer('POST', '/api/personnes', ['nom' => 'Aaaa-Liste', 'prenom' => 'Sans'], $admin)['id'];
        $this->envoyer('POST', '/api/adhesions', ['membre' => '/api/personnes/'.$avec, 'association' => '/api/organisations/'.$bearn,
            'categorie' => '/api/categories/suiveur', 'debut' => '2027-01-01'], $admin);
        self::assertResponseStatusCodeSame(201);

        // Liste du mois : suiveur à payer 30 €, l'autre sans adhésion ; triée par nom, prénom.
        $liste = $this->lire('/api/liste?mois=2027-03', $admin);
        self::assertSame('2027-03', $liste['mois']);
        $lignes = array_values(array_filter($liste['personnes'], fn ($p) => 'Aaaa-Liste' === $p['nom']));
        self::assertSame(['Avec', 'Sans'], array_column($lignes, 'prenom'));
        self::assertSame(['statut' => 'a_payer', 'du' => '30.00', 'regle' => null, 'reste' => '30.00'], $lignes[0]['mois']);
        self::assertSame('suiveur', $lignes[0]['categorie']);
        self::assertSame('aucune_adhesion', $lignes[1]['mois']['statut']);

        // « Payé ce mois-ci » : cotisation de mars créée au tarif, paiement enregistré.
        $ligne = $this->envoyer('POST', "/api/personnes/$avec/paiement-mois", ['mois' => '2027-03', 'moyen' => 'cheque'], $admin);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['statut' => 'paye', 'du' => '30.00', 'regle' => '30.00', 'reste' => '0.00'], $ligne['mois']);
        self::assertSame('2027-03-31', $ligne['derniere_cotisation']);
        self::assertTrue($this->lire("/api/personnes/$avec/acces?jour=2027-03-20", $admin)['acces']);
        $cotisations = $this->lire("/api/cotisations?membre=$avec", $admin);
        self::assertCount(1, $cotisations);
        self::assertSame('2027-03-31', substr($cotisations[0]['fin'], 0, 10));

        // Deuxième fois : refusé, rien n'est enregistré en double.
        $refus = $this->envoyer('POST', "/api/personnes/$avec/paiement-mois", ['mois' => '2027-03'], $admin);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('déjà réglée', $refus['detail']);
        self::assertSame('30.00', static::getContainer()->get(Connection::class)->fetchOne(
            'select sum(p.montant) from adhesion.paiement p where p.payeur_id = :id', ['id' => $avec]));

        // Sans adhésion, moyen inconnu : refusés.
        $this->envoyer('POST', "/api/personnes/$sans/paiement-mois", ['mois' => '2027-03'], $admin);
        self::assertResponseStatusCodeSame(422);
        $this->envoyer('POST', "/api/personnes/$avec/paiement-mois", ['mois' => '2027-04', 'moyen' => 'troc'], $admin);
        self::assertResponseStatusCodeSame(400);

        // L'accueil (Productions) ne peut pas encaisser une cotisation de membre du club du Béarn.
        $accueil = $this->jeton(['membres:accueil:'.$this->association('JaXaJ Productions')]);
        self::assertNotEmpty($this->lire('/api/liste?mois=2027-04', $accueil)['personnes']);
        $this->envoyer('POST', "/api/personnes/$avec/paiement-mois", ['mois' => '2027-04'], $accueil);
        self::assertResponseStatusCodeSame(403);
    }

    public function testChangementDeNiveau(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $euskadi = $this->association('JaXaJ MC Euskadi');
        $id = $this->envoyer('POST', '/api/personnes', ['nom' => 'Etcheverry', 'prenom' => 'Ane'], $admin)['id'];
        $suiveur = $this->envoyer('POST', '/api/adhesions', ['membre' => '/api/personnes/'.$id, 'association' => '/api/organisations/'.$euskadi,
            'categorie' => '/api/categories/suiveur', 'debut' => '2027-01-01'], $admin)['id'];

        // Suiveur → full patch au 1er avril : l'ancienne se termine le 31 mars, la nouvelle reste ouverte.
        $r = $this->envoyer('POST', "/api/adhesions/$suiveur/changement", ['categorie' => 'full_patch', 'date' => '2027-04-01'], $admin);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['categorie' => 'suiveur', 'debut' => '2027-01-01', 'fin' => '2027-03-31'], array_diff_key($r['ancienne'], ['id' => 1]));
        self::assertSame(['categorie' => 'full_patch', 'debut' => '2027-04-01', 'fin' => null], array_diff_key($r['nouvelle'], ['id' => 1]));
        self::assertCount(2, $this->lire('/api/adhesions?membre='.$id, $admin));
        self::assertSame('full_patch', array_values(array_filter($this->lire('/api/liste?mois=2027-05', $admin)['personnes'], fn ($p) => $p['id'] === $id))[0]['categorie']);

        // Même niveau, date hors période, catégorie inconnue : refusés.
        $nouvelle = $r['nouvelle']['id'];
        $this->envoyer('POST', "/api/adhesions/$nouvelle/changement", ['categorie' => 'full_patch', 'date' => '2027-06-01'], $admin);
        self::assertResponseStatusCodeSame(422);
        $this->envoyer('POST', "/api/adhesions/$nouvelle/changement", ['categorie' => 'observateur', 'date' => '2027-02-01'], $admin);
        self::assertResponseStatusCodeSame(422);
        $this->envoyer('POST', "/api/adhesions/$nouvelle/changement", ['categorie' => 'inconnue', 'date' => '2027-06-01'], $admin);
        self::assertResponseStatusCodeSame(400);

        // L'accueil ne peut pas faire passer quelqu'un full patch.
        $this->envoyer('POST', "/api/adhesions/$nouvelle/changement", ['categorie' => 'observateur', 'date' => '2027-06-01'],
            $this->jeton(['membres:accueil']));
        self::assertResponseStatusCodeSame(403);
    }

    public function testRoles(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $bearn = $this->association('JaXaJ MC Béarn');
        $mere = $this->association('JaXaJ MC');
        $a = $this->envoyer('POST', '/api/personnes', ['nom' => 'Lassalle', 'prenom' => 'Jean'], $admin)['id'];
        $b = $this->envoyer('POST', '/api/personnes', ['nom' => 'Bordes', 'prenom' => 'Marie'], $admin)['id'];

        $president = $this->envoyer('POST', '/api/roles', ['personne' => '/api/personnes/'.$a, 'organisation' => '/api/organisations/'.$bearn,
            'role' => 'president', 'debut' => '2026-01-17'], $admin);
        self::assertResponseStatusCodeSame(201);
        $this->envoyer('POST', '/api/roles', ['personne' => '/api/personnes/'.$b, 'organisation' => '/api/organisations/'.$mere,
            'role' => 'tresorier', 'debut' => '2026-01-17'], $admin);
        self::assertResponseStatusCodeSame(201);

        // Un seul président à la fois : refusé tant que le mandat en cours n'est pas terminé.
        $nouveau = ['personne' => '/api/personnes/'.$b, 'organisation' => '/api/organisations/'.$bearn, 'role' => 'president', 'debut' => '2027-01-16'];
        $conflit = $this->envoyer('POST', '/api/roles', $nouveau, $admin);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Un seul président(e) à la fois à JaXaJ MC Béarn : Jean Lassalle l\'est déjà depuis le 17/01/2026, sans date de fin', $conflit['detail']);
        $fin = $this->envoyer('PATCH', '/api/roles/'.$president['id'], ['fin' => '2027-01-15'], $admin);
        self::assertSame('2027-01-15', substr($fin['fin'], 0, 10));
        $this->envoyer('POST', '/api/roles', $nouveau, $admin);
        self::assertResponseStatusCodeSame(201);

        // Sergent d'armes et chef de file : un titulaire à la fois, comme les autres.
        $sergent = ['personne' => '/api/personnes/'.$a, 'organisation' => '/api/organisations/'.$bearn, 'role' => 'sergent_armes', 'debut' => '2026-03-01'];
        $this->envoyer('POST', '/api/roles', $sergent, $admin);
        self::assertResponseStatusCodeSame(201);
        $this->envoyer('POST', '/api/roles', ['personne' => '/api/personnes/'.$b] + $sergent, $admin);
        self::assertResponseStatusCodeSame(422);
        $this->envoyer('POST', '/api/roles', ['role' => 'vice_tresorier'] + $sergent, $admin);
        self::assertResponseStatusCodeSame(201);
        $this->envoyer('POST', '/api/roles', ['role' => 'chef_de_file'] + $sergent, $admin);
        self::assertResponseStatusCodeSame(201);

        // Rôle inconnu : refusé. Historique par personne et par association.
        $this->envoyer('POST', '/api/roles', ['role' => 'roi'] + $nouveau, $admin);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(5, $this->lire('/api/roles?organisation='.$bearn, $admin));
        self::assertSame(['tresorier', 'president'], array_column($this->lire('/api/roles?personne='.$b, $admin), 'role'));

        // Les rôles en cours (aujourd'hui) apparaissent dans la liste.
        $ligne = array_values(array_filter($this->lire('/api/liste', $admin)['personnes'], fn ($p) => $p['id'] === $b))[0];
        self::assertSame([['role' => 'tresorier', 'organisation' => $mere]], $ligne['roles']);

        // La gestion du Béarn gère les rôles du Béarn, pas ceux de la mère ; l'accueil, aucun.
        $gestionBearn = $this->jeton(['membres:gestion:'.$bearn]);
        $this->envoyer('POST', '/api/roles', ['personne' => '/api/personnes/'.$a, 'organisation' => '/api/organisations/'.$bearn,
            'role' => 'secretaire', 'debut' => '2026-02-01'], $gestionBearn);
        self::assertResponseStatusCodeSame(201);
        $this->envoyer('POST', '/api/roles', ['personne' => '/api/personnes/'.$a, 'organisation' => '/api/organisations/'.$mere,
            'role' => 'secretaire', 'debut' => '2026-02-01'], $gestionBearn);
        self::assertResponseStatusCodeSame(403);
        $this->envoyer('POST', '/api/roles', ['personne' => '/api/personnes/'.$a, 'organisation' => '/api/organisations/'.$bearn,
            'role' => 'representant', 'debut' => '2026-02-01'], $this->jeton(['membres:accueil']));
        self::assertResponseStatusCodeSame(403);
    }

    /** Corriger l'historique : dates d'une adhésion reprise, début d'un rôle, suppression d'une saisie erronée. */
    public function testCorrectionDeLHistorique(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $gestion = $this->jeton(['membres:gestion']);
        $mere = $this->association('JaXaJ MC');
        $id = $this->envoyer('POST', '/api/personnes', ['nom' => 'Etchart', 'prenom' => 'Paul'], $admin)['id'];
        $base = ['membre' => '/api/personnes/'.$id, 'association' => '/api/organisations/'.$mere, 'categorie' => '/api/categories/full_patch'];

        // Adhésion reprise aux dates approximatives : la nouvelle saisie la recouvre.
        $reprise = $this->envoyer('POST', '/api/adhesions', $base + ['debut' => '2009-01-01', 'fin' => '2018-12-31'], $admin);
        $this->envoyer('POST', '/api/adhesions', $base + ['debut' => '2009-10-17', 'fin' => '2025-12-31'], $admin);
        self::assertResponseStatusCodeSame(422);

        // On corrige ses dates, y compris en avançant le début au-delà de l'ancienne fin.
        $corrigee = $this->envoyer('PATCH', '/api/adhesions/'.$reprise['id'], ['debut' => '2019-06-01', 'fin' => '2020-12-31'], $gestion);
        self::assertResponseIsSuccessful();
        self::assertSame(['2019-06-01', '2020-12-31'], [substr($corrigee['debut'], 0, 10), substr($corrigee['fin'], 0, 10)]);
        $this->envoyer('PATCH', '/api/adhesions/'.$reprise['id'], ['fin' => '2019-01-01'], $gestion);
        self::assertResponseStatusCodeSame(422);

        // Supprimer une saisie erronée : l'administration seulement.
        $this->client->request('DELETE', '/api/adhesions/'.$reprise['id'], server: $gestion);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('DELETE', '/api/adhesions/'.$reprise['id'], server: $admin);
        self::assertResponseStatusCodeSame(204);
        $nouvelle = $this->envoyer('POST', '/api/adhesions', $base + ['debut' => '2009-10-17', 'fin' => '2025-12-31'], $admin);
        self::assertResponseStatusCodeSame(201);

        // Une adhésion avec des cotisations ne se supprime pas.
        $this->envoyer('POST', '/api/cotisations', ['adhesion' => '/api/adhesions/'.$nouvelle['id'], 'debut' => '2025-01-01', 'montant' => '60.00'], $admin);
        self::assertResponseStatusCodeSame(201);
        $this->client->request('DELETE', '/api/adhesions/'.$nouvelle['id'], server: $admin);
        self::assertResponseStatusCodeSame(422);

        // Rôle passé : début et fin modifiables.
        $role = $this->envoyer('POST', '/api/roles', ['personne' => '/api/personnes/'.$id, 'organisation' => '/api/organisations/'.$mere,
            'role' => 'chef_de_file', 'debut' => '2010-01-01', 'fin' => '2011-12-31'], $admin);
        $role = $this->envoyer('PATCH', '/api/roles/'.$role['id'], ['debut' => '2012-01-01', 'fin' => '2013-12-31'], $gestion);
        self::assertSame(['2012-01-01', '2013-12-31'], [substr($role['debut'], 0, 10), substr($role['fin'], 0, 10)]);
    }

    /** Tarifs saisis par l'administration, puis calcul des cotisations d'une personne. */
    public function testTarifsEtCalculDesCotisations(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $gestion = $this->jeton(['membres:gestion']);
        $mere = $this->association('JaXaJ MC');
        $bearn = $this->association('JaXaJ MC Béarn');
        $base = static::getContainer()->get(Connection::class);

        // Tarifs historiques : 50 € l'année jusqu'en 2014, puis 50 € le mois.
        $annuel = ['categorie' => '/api/categories/full_patch', 'periodicite' => 'annuelle', 'montant' => '50', 'debut' => '2009-01-01', 'fin' => '2014-12-31'];
        $this->envoyer('POST', '/api/tarifs', $annuel, $gestion);
        self::assertResponseStatusCodeSame(403);
        $this->envoyer('POST', '/api/tarifs', $annuel + ['decision' => 'AG de 2009'], $admin);
        self::assertResponseStatusCodeSame(201);
        $mensuel = $this->envoyer('POST', '/api/tarifs', ['periodicite' => 'mensuelle', 'debut' => '2015-01-01', 'fin' => '2025-12-31'] + $annuel, $admin);
        self::assertResponseStatusCodeSame(201);
        $this->envoyer('POST', '/api/tarifs', ['periodicite' => 'mensuelle', 'debut' => '2025-06-01', 'fin' => null] + $annuel, $admin);
        self::assertResponseStatusCodeSame(409);  // chevauche le tarif mensuel en vigueur

        $id = $this->envoyer('POST', '/api/personnes', ['nom' => 'Harriet', 'prenom' => 'Luc'], $admin)['id'];
        $p = ['membre' => '/api/personnes/'.$id, 'categorie' => '/api/categories/full_patch'];
        $this->envoyer('POST', '/api/adhesions', $p + ['association' => '/api/organisations/'.$mere, 'debut' => '2013-10-17', 'fin' => '2025-12-31'], $admin);
        $this->envoyer('POST', '/api/adhesions', $p + ['association' => '/api/organisations/'.$bearn, 'debut' => '2026-01-01'], $admin);
        $moisCourant = (int) date('n') + 12 * ((int) date('Y') - 2026);  // 2026 et 2027 : 60 € le mois

        // Pas pour l'accueil ; l'aperçu n'enregistre rien.
        $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', ['simuler' => true], $this->jeton(['membres:accueil']));
        self::assertResponseStatusCodeSame(403);
        $apercu = $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', ['regleesJusquau' => '2025-12-31', 'simuler' => true], $admin);
        self::assertSame(2 + 132 + $moisCourant, $apercu['creees']);
        self::assertSame(134, $apercu['reglees']);
        self::assertSame(number_format(100 + 6600 + 60 * $moisCourant, 2, '.', ''), $apercu['total']);
        self::assertSame([], $this->lire('/api/personnes/'.$id.'/cotisations', $admin));

        $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', ['regleesJusquau' => '2025-12-31'], $admin);
        $etat = $this->lire('/api/personnes/'.$id.'/cotisations', $admin);
        self::assertCount(2 + 132 + $moisCourant, $etat);
        $annee2013 = array_values(array_filter($etat, fn ($c) => '2013-01-01' === $c['debut']))[0];
        self::assertSame(['2013-12-31', '50.00', true, true], [$annee2013['fin'], $annee2013['montant'], $annee2013['aJour'], $annee2013['historique']]);
        self::assertFalse($etat[0]['aJour']);  // le mois en cours reste à payer

        // Un vrai paiement est conservé quand on recalcule après avoir corrigé un tarif.
        $this->envoyer('POST', '/api/personnes/'.$id.'/paiement-mois', ['mois' => date('Y-m'), 'moyen' => 'especes'], $admin);
        self::assertResponseStatusCodeSame(201);
        $this->envoyer('PATCH', '/api/tarifs/'.$mensuel['id'], ['montant' => '55'], $admin);
        $recalcul = $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', ['regleesJusquau' => '2025-12-31'], $admin);
        self::assertSame(1, $recalcul['conservees']);
        $etat = $this->lire('/api/personnes/'.$id.'/cotisations', $admin);
        self::assertTrue($etat[0]['aJour']);
        self::assertFalse($etat[0]['calculee'] && $etat[0]['historique']);
        self::assertSame('55.00', array_values(array_filter($etat, fn ($c) => '2015-03-01' === $c['debut']))[0]['montant']);
        self::assertSame(134, (int) $base->fetchOne("select count(*) from adhesion.paiement where payeur_id = :p and reference = 'Historique reconstitué'", ['p' => $id]));

        // Sans tarif : signalé, rien de créé. Un tarif qui a servi ne se supprime pas.
        $vide = $this->envoyer('POST', '/api/personnes', ['nom' => 'Sans', 'prenom' => 'Tarif'], $admin)['id'];
        $this->envoyer('POST', '/api/adhesions', ['membre' => '/api/personnes/'.$vide, 'association' => '/api/organisations/'.$mere,
            'categorie' => '/api/categories/suiveur', 'debut' => '2016-01-01', 'fin' => '2016-03-31'], $admin);
        $r = $this->envoyer('POST', '/api/personnes/'.$vide.'/cotisations/calcul', [], $admin);
        self::assertSame([0, [['association' => $mere, 'categorie' => 'suiveur', 'debut' => '2016-01', 'fin' => '2016-03']]], [$r['creees'], $r['sans_tarif']]);
        $this->client->request('DELETE', '/api/tarifs/'.$mensuel['id'], server: $admin);
        self::assertResponseStatusCodeSame(409);
    }

    /** Productions : catégories de l'événementiel, adhérent exempté de cotisation. */
    public function testVoletEvenementielEtExemption(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $prod = $this->association('JaXaJ Productions');
        $id = $this->envoyer('POST', '/api/personnes', ['nom' => 'Larrieu', 'prenom' => 'Anne'], $admin)['id'];
        $base = ['membre' => '/api/personnes/'.$id, 'association' => '/api/organisations/'.$prod, 'debut' => '2022-04-01'];

        // Pas de full patch chez Productions, ni d'adhérent dans un chapitre.
        $this->envoyer('POST', '/api/adhesions', $base + ['categorie' => '/api/categories/full_patch'], $admin);
        self::assertResponseStatusCodeSame(422);
        $this->envoyer('POST', '/api/adhesions', ['association' => '/api/organisations/'.$this->association('JaXaJ MC Béarn'),
            'categorie' => '/api/categories/adherent'] + $base, $admin);
        self::assertResponseStatusCodeSame(422);

        // Adhérent exempté : une cotisation gratuite par an, avec le motif.
        $adhesion = $this->envoyer('POST', '/api/adhesions', $base + ['categorie' => '/api/categories/adherent', 'exemption' => 'Membre du bureau (statuts)'], $admin);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Membre du bureau (statuts)', $adhesion['exemption']);
        $r = $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', ['regleesJusquau' => '2025-12-31'], $admin);
        $annees = (int) date('Y') - 2022 + 1;
        self::assertSame([1, 1, 0, '0.00', []], [$r['creees'], $r['exemptees'], $r['reglees'], $r['total'], $r['sans_tarif']]);
        $etat = $this->lire('/api/personnes/'.$id.'/cotisations', $admin);
        self::assertCount(1, $etat);  // une seule ligne, sur toute la durée
        self::assertSame([true, true, 'Membre du bureau (statuts)', '2022-04-01', date('Y').'-12-31'],
            [$etat[0]['gratuite'], $etat[0]['aJour'], $etat[0]['motifGratuite'], $etat[0]['debut'], $etat[0]['fin']]);

        // La liste donne toutes les adhésions du mois, pas seulement la principale.
        $ligne = array_values(array_filter($this->lire('/api/liste', $admin)['personnes'], fn ($l) => $l['id'] === $id))[0];
        self::assertSame([['association' => $prod, 'categorie' => 'adherent']], $ligne['adhesions']);

        // Sans exemption : 20 € par an. Un artiste ne cotise pas, le public non plus (ponctuel).
        $this->envoyer('PATCH', '/api/adhesions/'.$adhesion['id'], ['exemption' => ''], $admin);
        $r = $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', [], $admin);
        self::assertSame(number_format(20 * $annees, 2, '.', ''), $r['total']);
        $artiste = $this->envoyer('POST', '/api/personnes', ['nom' => 'Les Rugissants'], $admin)['id'];
        $this->envoyer('POST', '/api/adhesions', ['membre' => '/api/personnes/'.$artiste, 'categorie' => '/api/categories/artiste'] + $base, $admin);
        self::assertResponseStatusCodeSame(201);
        $r = $this->envoyer('POST', '/api/personnes/'.$artiste.'/cotisations/calcul', [], $admin);
        self::assertSame([0, []], [$r['creees'], $r['sans_tarif']]);
    }

    /** Gestion d'une association : ses personnes seulement (anciens compris), avec leur détail. */
    public function testGestionLimiteeAUneAssociation(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $bearn = $this->association('JaXaJ MC Béarn');
        $euskadi = $this->association('JaXaJ MC Euskadi');
        $prod = $this->association('JaXaJ Productions');
        $personne = fn (string $nom) => $this->envoyer('POST', '/api/personnes', ['nom' => $nom, 'telephone' => '0611223344'], $admin)['id'];
        $adherer = fn (string $p, string $asso, string $cat, string $debut, ?string $fin = null) => $this->envoyer('POST', '/api/adhesions',
            ['membre' => '/api/personnes/'.$p, 'association' => '/api/organisations/'.$asso, 'categorie' => '/api/categories/'.$cat, 'debut' => $debut, 'fin' => $fin], $admin);
        $ancien = $personne('Ancien-du-Bearn');
        $adherer($ancien, $bearn, 'full_patch', '2015-01-01', '2020-12-31');
        $adherer($ancien, $prod, 'adherent', '2022-01-01');
        $basque = $personne('Basque');
        $adherer($basque, $euskadi, 'full_patch', '2026-01-01');
        $sergent = $personne('Sergent');
        $this->envoyer('POST', '/api/roles', ['personne' => '/api/personnes/'.$sergent, 'organisation' => '/api/organisations/'.$bearn,
            'role' => 'sergent_armes', 'debut' => '2026-01-01'], $admin);
        foreach ([$ancien, $basque, $sergent] as $p) {
            $this->creeeHier($p);
        }

        $gestion = $this->jeton(['membres:gestion:'.$bearn]);
        $ids = array_column($this->lire('/api/liste', $gestion)['personnes'], 'id');
        self::assertContains($ancien, $ids);   // ancien du Béarn
        self::assertContains($sergent, $ids);  // rôle au Béarn, sans adhésion
        self::assertNotContains($basque, $ids);
        self::assertSame([$ancien], array_values(array_intersect([$ancien, $basque], array_column($this->lire('/api/personnes?q=a', $gestion), 'id'))));

        // Sa fiche, avec le détail ; de son historique, seulement le Béarn.
        self::assertSame('0611223344', $this->lire('/api/personnes/'.$ancien, $gestion)['telephone']);
        // Tout son historique, pour le vérifier avec elle (ici Productions), sans pouvoir y écrire.
        $historique = $this->lire('/api/adhesions?membre='.$ancien, $gestion);
        self::assertEqualsCanonicalizing(['full_patch', 'adherent'], array_map(fn ($a) => basename($a['categorie']), $historique));
        $prodAdh = array_values(array_filter($historique, fn ($a) => 'adherent' === basename($a['categorie'])))[0];
        $this->envoyer('PATCH', '/api/adhesions/'.$prodAdh['id'], ['debut' => '2022-02-01'], $gestion);
        self::assertResponseStatusCodeSame(403);
        $this->lire('/api/personnes/'.$ancien.'/acces', $gestion);
        self::assertResponseIsSuccessful();

        // Calcul des cotisations : seulement les adhésions du Béarn.
        $r = $this->envoyer('POST', '/api/personnes/'.$ancien.'/cotisations/calcul', ['simuler' => true], $gestion);
        self::assertResponseIsSuccessful();
        self::assertSame([], array_filter($r['sans_tarif'], fn ($t) => $t['association'] !== $bearn));

        // Doublon : il voit qu'une fiche existe ailleurs, sans la voir.
        $h = $this->lire('/api/homonymes?nom=basque', $gestion);
        self::assertSame([1, []], [$h['total'], $h['visibles']]);
        self::assertSame(1, $this->lire('/api/homonymes?nom=Ancien-du-Béarn', $gestion)['total']);

        // Une personne d'ailleurs : refusée partout.
        foreach (['', '/acces', '/cotisations'] as $suite) {
            $this->lire('/api/personnes/'.$basque.$suite, $gestion);
            self::assertResponseStatusCodeSame(403);
        }
        $this->envoyer('PATCH', '/api/personnes/'.$basque, ['nom' => 'X'], $gestion);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->lire('/api/adhesions?membre='.$basque, $gestion));

        // Nouvelle fiche : visible dès que l'adhésion au Béarn est posée.
        $nouveau = $this->envoyer('POST', '/api/personnes', ['nom' => 'Prospect'], $gestion)['id'];
        $this->envoyer('POST', '/api/adhesions', ['membre' => '/api/personnes/'.$nouveau, 'association' => '/api/organisations/'.$bearn,
            'categorie' => '/api/categories/suiveur', 'debut' => '2026-11-01'], $gestion);
        self::assertResponseStatusCodeSame(201);
        $this->lire('/api/personnes/'.$nouveau, $gestion);
        self::assertResponseIsSuccessful();

        // Avec l'accueil en plus : tout le monde, le détail du Béarn seulement (et des fiches du jour).
        $lesDeux = $this->jeton(['membres:gestion:'.$bearn, 'membres:accueil:'.$prod]);
        $fiche = $this->lire('/api/personnes/'.$basque, $lesDeux);
        self::assertArrayNotHasKey('telephone', $fiche);
        self::assertArrayNotHasKey('detail', $fiche);
        self::assertSame('0611223344', $this->lire('/api/personnes/'.$ancien, $lesDeux)['telephone']);
    }

    /** Club et événementiel se calculent chacun de leur côté : l'annuel de Productions ne couvre pas les mois d'un chapitre. */
    public function testCalculClubEtEvenementielIndependants(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $id = $this->envoyer('POST', '/api/personnes', ['nom' => 'Courtade'], $admin)['id'];
        $p = ['membre' => '/api/personnes/'.$id];
        $this->envoyer('POST', '/api/adhesions', $p + ['association' => '/api/organisations/'.$this->association('JaXaJ Productions'),
            'categorie' => '/api/categories/adherent', 'debut' => '2022-04-01', 'exemption' => 'Bureau (statuts)'], $admin);
        $this->envoyer('POST', '/api/adhesions', $p + ['association' => '/api/organisations/'.$this->association('JaXaJ MC Euskadi'),
            'categorie' => '/api/categories/full_patch', 'debut' => '2026-01-01'], $admin);
        $r = $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', [], $admin);
        $mois = (int) date('n') + 12 * ((int) date('Y') - 2026);
        self::assertSame([1 + $mois, 0], [$r['creees'], $r['conservees']]);
        self::assertSame(number_format(60 * $mois, 2, '.', ''), $r['total']);
    }

    /** Membre de plusieurs associations, ne cotise qu'à une : les autres exemptées ne bloquent rien. */
    public function testExemptionNeBloquePasLaCotisationAilleurs(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $id = $this->envoyer('POST', '/api/personnes', ['nom' => 'Courtade', 'prenom' => 'Greg'], $admin)['id'];
        $adherer = fn (string $asso, string $debut, ?string $exemption = null) => $this->envoyer('POST', '/api/adhesions',
            ['membre' => '/api/personnes/'.$id, 'association' => '/api/organisations/'.$this->association($asso),
             'categorie' => '/api/categories/full_patch', 'debut' => $debut, 'exemption' => $exemption], $admin);
        $adherer('JaXaJ MC', '2020-01-01', 'Cotise à Euskadi');      // ouverte, plus de tarif pour la mère
        $adherer('JaXaJ MC Béarn', '2026-02-01', 'Cotise à Euskadi');  // exemptée, plus récente qu'Euskadi
        $adherer('JaXaJ MC Euskadi', '2026-01-01');
        $r = $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', [], $admin);
        $mois = (int) date('n') + 12 * ((int) date('Y') - 2026);
        self::assertSame(number_format(60 * $mois, 2, '.', ''), $r['total']);  // Euskadi, chaque mois
        self::assertSame([2 + $mois, 2, []], [$r['creees'], $r['exemptees'], $r['sans_tarif']]);  // + une ligne gratuite par exemption

        // Dans la liste, l'état du mois est celui d'Euskadi, où il paie, pas celui du Béarn exempté.
        $ligne = array_values(array_filter($this->lire('/api/liste', $admin)['personnes'], fn ($l) => $l['id'] === $id))[0];
        self::assertSame('2020-01-01', $ligne['premier_full_patch']);  // premier passage, toutes associations
        self::assertSame([$this->association('JaXaJ MC Euskadi'), 'a_payer'], [$ligne['association'], $ligne['mois']['statut']]);
    }

    /** Complétude : ce qu'on attend d'un membre du club, moins d'une personne du public. */
    public function testCompletudeDesFiches(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $completude = fn (string $id) => $this->lire('/api/personnes/'.$id.'/completude', $admin);

        $public = $this->envoyer('POST', '/api/personnes', ['nom' => 'Spectateur'], $admin)['id'];
        self::assertSame(['manquants' => ['prenom', 'email', 'adhesion'], 'total' => 4], $completude($public));
        $this->envoyer('PATCH', '/api/personnes/'.$public, ['prenom' => 'Léa', 'email' => 'lea@example.org'], $admin);
        $this->envoyer('POST', '/api/adhesions', ['membre' => '/api/personnes/'.$public, 'association' => '/api/organisations/'.$this->association('JaXaJ Productions'),
            'categorie' => '/api/categories/public', 'debut' => '2026-11-01'], $admin);
        self::assertSame(['manquants' => [], 'total' => 4], $completude($public));

        $membre = $this->envoyer('POST', '/api/personnes', ['nom' => 'Motard', 'prenom' => 'Paul', 'telephone' => '0600000000'], $admin)['id'];
        $this->envoyer('POST', '/api/adhesions', ['membre' => '/api/personnes/'.$membre, 'association' => '/api/organisations/'.$this->association('JaXaJ MC Béarn'),
            'categorie' => '/api/categories/full_patch', 'debut' => '2026-01-01'], $admin);
        self::assertSame(['manquants' => ['naissance', 'email', 'adresse', 'cotisations'], 'total' => 8], $completude($membre));
        $ligne = array_values(array_filter($this->lire('/api/liste', $admin)['personnes'], fn ($l) => $l['id'] === $membre))[0];
        self::assertSame($completude($membre), $ligne['completude']);
        self::assertSame('personne', $ligne['type']);

        // Un groupe : nom, e-mail, téléphone, adhésion. Un club : nom, e-mail, adresse, adhésion.
        $groupe = $this->envoyer('POST', '/api/personnes', ['nom' => 'Les Rugissants', 'type' => 'artiste', 'email' => 'groupe@example.org'], $admin)['id'];
        self::assertSame(['manquants' => ['telephone', 'adhesion'], 'total' => 4], $completude($groupe));
        $club = $this->envoyer('POST', '/api/personnes', ['nom' => 'MC des Gaves', 'type' => 'structure'], $admin)['id'];
        self::assertSame(['manquants' => ['email', 'adresse', 'adhesion'], 'total' => 4], $completude($club));
        $this->envoyer('POST', '/api/personnes', ['nom' => 'X', 'type' => 'robot'], $admin);
        self::assertResponseStatusCodeSame(422);

        // Un club qui n'existe plus : statut « disparu », toujours dans la liste (l'interface le met à part).
        $fin = $this->envoyer('PATCH', '/api/personnes/'.$club, ['statut' => 'disparu', 'disparuLe' => '2019-06-30'], $admin);
        self::assertSame(['disparu', '2019-06-30'], [$fin['statut'], substr($fin['disparuLe'], 0, 10)]);
        $ligne = array_values(array_filter($this->lire('/api/liste', $admin)['personnes'], fn ($l) => $l['id'] === $club))[0];
        self::assertSame(['disparu', '2019-06-30'], [$ligne['statut'], $ligne['disparu_le']]);
        $actif = $this->envoyer('PATCH', '/api/personnes/'.$club, ['statut' => 'actif'], $admin);
        self::assertSame('actif', $actif['statut']);
        self::assertArrayNotHasKey('disparuLe', $actif);  // la date s'efface
    }

    /** Capacités données une par une, mission après mission : inscription, historique, trésorerie. */
    public function testCapacitesParMission(): void
    {
        $admin = $this->jeton(['membres:admin']);
        $bearn = $this->association('JaXaJ MC Béarn');
        $inscription = $this->jeton(['membres:inscription:'.$bearn]);
        $historique = $this->jeton(['membres:historique:'.$bearn]);
        $tresorerie = $this->jeton(['membres:tresorerie:'.$bearn]);

        // Inscription : une fiche et son adhésion support ; pas de correction, pas d'encaissement.
        $id = $this->envoyer('POST', '/api/personnes', ['nom' => 'Nouveau', 'prenom' => 'Support'], $inscription)['id'];
        $adhesion = $this->envoyer('POST', '/api/adhesions', ['membre' => '/api/personnes/'.$id, 'association' => '/api/organisations/'.$bearn,
            'categorie' => '/api/categories/support', 'debut' => '2026-11-01'], $inscription);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->lire('/api/personnes/'.$id, $inscription)['detail']);  // il voit sa fiche, en détail
        $this->envoyer('PATCH', '/api/adhesions/'.$adhesion['id'], ['debut' => '2026-10-01'], $inscription);
        self::assertResponseStatusCodeSame(403);
        $this->envoyer('POST', '/api/personnes/'.$id.'/paiement-mois', ['mois' => '2026-11', 'moyen' => 'especes'], $inscription);
        self::assertResponseStatusCodeSame(403);

        // Historique : corriger les dates, un rôle, calculer ; pas de nouvelle adhésion ni d'encaissement.
        $this->envoyer('PATCH', '/api/adhesions/'.$adhesion['id'], ['debut' => '2026-10-01'], $historique);
        self::assertResponseIsSuccessful();
        $this->envoyer('POST', '/api/roles', ['personne' => '/api/personnes/'.$id, 'organisation' => '/api/organisations/'.$bearn,
            'role' => 'representant', 'debut' => '2026-10-01'], $historique);
        self::assertResponseStatusCodeSame(201);
        $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', ['simuler' => true], $historique);
        self::assertResponseIsSuccessful();
        $this->envoyer('POST', '/api/personnes/'.$id.'/cotisations/calcul', ['simuler' => true], $tresorerie);
        self::assertResponseStatusCodeSame(403);
        $this->envoyer('POST', '/api/adhesions', ['membre' => '/api/personnes/'.$id, 'association' => '/api/organisations/'.$bearn,
            'categorie' => '/api/categories/suiveur', 'debut' => '2027-01-01'], $historique);
        self::assertResponseStatusCodeSame(403);

        // Trésorerie : encaisser le mois.
        $this->envoyer('POST', '/api/personnes/'.$id.'/paiement-mois', ['mois' => '2026-11', 'moyen' => 'especes'], $tresorerie);
        self::assertResponseStatusCodeSame(201);
        $this->envoyer('POST', '/api/personnes/'.$id.'/paiement-mois', ['mois' => '2026-11', 'moyen' => 'especes'], $historique);
        self::assertResponseStatusCodeSame(403);
    }
}
