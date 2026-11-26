# Deux chaînes BSC TV

Depuis **1.24.0**, le lecteur propose deux antennes distinctes. Les anciennes pages, widgets et options continuent à utiliser la **chaîne 1 · BSC TV Monde**. La **chaîne 2 · BSC TV Français** a sa propre horloge et reste désactivée jusqu’à son activation dans ses réglages.

## Organisation de l’administration

Dans BSC TV, les menus distinguent les espaces communs des deux chaînes :

- **Sources vidéo · communes** : fiches des créateurs, plateformes, langue et association BSC BDD.
- **Catalogue · commun** : émissions importées, métadonnées et exclusions de diffusion.
- **1 · Le live Monde** et **1 · Musique** : programmation et musique existantes.
- **2 · Le live Français** et **2 · Musique FR** : programmation et bibliothèque de la chaîne 2.

Chaque écran d’antenne affiche un bandeau avec son numéro, son nom, un repère coloré et des liens vers sa programmation et sa musique. Les formulaires, notifications, liens de pagination, reprises de playlists et éditions de créneaux restent attachés à cette antenne. Un formulaire de la chaîne 1 ne peut pas autoriser une modification de la chaîne 2.

## Préparer la chaîne française

1. Ouvrir **2 · Le live Français → Choisir les sources des émissions françaises**. Sans sélection manuelle, les sources dont la langue est déclarée `fr`, `fr-FR`, `fr_CA`, `Français`, `French`, `fra` ou `fre` sont utilisées. Le pays France seul ne suffit pas.
2. Vérifier les sources et enregistrer leur sélection pour garder un choix explicite. Une source dont la langue n’est pas renseignée peut être sélectionnée après vérification de ses émissions françaises. **Revenir à la sélection automatique par langue** réactive le suivi automatique des nouvelles sources francophones.
3. Les émissions et événements explicitement déclarés dans une autre langue sont exclus. En l’absence de langue pour l’émission, la déclaration de sa source sélectionnée est utilisée. Le plugin ne fait pas d’analyse audio pour vérifier la langue. Les brouillons, vidéos non intégrables ou soumises à une limite d’âge restent exclus.
4. Vérifier les replays disponibles et la grille des trois jours, puis activer le lecteur dans les réglages de cette chaîne. Une sélection vide reste vide : aucun programme international n’est ajouté pour la remplir.
5. Créer une page avec le widget lecteur réglé sur **Chaîne 2 · BSC TV Français**, puis renseigner son URL dans **Page de lecture de cette chaîne sur le site**. La pastille ne renvoie pas par défaut vers la page de la chaîne 1.

Chaque antenne conserve ses choix aléatoires, délais de rediffusion, historique, directs prioritaires et modifications manuelles. Un direct français interrompt la grille française et conserve la priorité jusqu’à sa fin confirmée. La reprise rejoint l’heure initialement prévue de cette grille, sans décaler l’autre antenne.

## Hip-hop et rap français des années 90

La bibliothèque de la chaîne 2 démarre vide : les clips et validations existants restent sur la chaîne 1. Les quatre tranches de la chaîne 2 sont personnalisables :

| Tranche | Horaire initial, Europe/Paris |
| --- | --- |
| BSC FR · Hip-hop 90 · Matin | 06:00–10:00 |
| BSC FR · Rap 90 · Journée | 10:00–18:00 |
| BSC FR · Classiques 90 · Soirée | 18:00–00:00 |
| BSC FR · Underground 90 · Nuit | 00:00–06:00 |

Dans **2 · Musique FR**, importer les playlists dans ces catégories. Les références nouvelles restent inactives et en attente de validation. La chaîne 2 applique initialement la sélection **hip-hop / rap français**, de **1990 à 1999 inclus**. Vérifier et renseigner l’année du morceau et sa sélection dans chaque fiche ; la date de publication de la vidéo YouTube ne représente pas l’année de sortie du morceau. Une année absente est exclue lorsqu’un filtre de période est actif. Le diagnostic compte séparément les clips hors sélection ou dont l’année manque.

Les années, la sélection musicale, les horaires et les noms des catégories peuvent être modifiés. Choisir 0 pour retirer une borne d’année, et **Toutes les sélections** pour élargir aux autres références. Les filtres de la chaîne 1 restent sans limite de période par défaut.

Chaque bibliothèque dispose de 500 références au maximum. Une même URL peut être enregistrée dans les deux bibliothèques avec ses propres catégories et validations. La programmation musicale, les animations et leur durée suivent les réglages de l’antenne concernée.

## Elementor et shortcodes

Le **Live Player 24/7**, le **Programme du jour**, la **Pastille Live** et les widgets du programme proposent un champ **Chaîne TV**. Choisir la même chaîne pour le lecteur, sa liste et sa pastille. Les réglages de style existants restent disponibles pour chaque widget.

```text
[bsc_tv_player station="fr"]
[bsc_tv_day station="fr"]
[bsc_tv format="badge" station="fr"]
```

`station="main"` sélectionne la chaîne 1 ; l’absence d’attribut conserve ce comportement. Les routes publiques `/player`, `/day` et `/programme` acceptent `station=main` ou `station=fr`. Une autre valeur est refusée.

## Import quotidien et Infomaniak

Les deux tâches Infomaniak existantes suffisent. La découverte des annonces et l’import de replays restent communs et limités à un passage quotidien. Les replays ne sont pas importés une deuxième fois pour la chaîne française. Le passage quotidien vérifie au maximum 50 références musicales par bibliothèque activée et prépare chaque grille séparément. Le contrôle léger actualise les mêmes états de plateformes puis les deux horloges, sans import supplémentaire.

En mode Infomaniak, l’ajout ou l’activation de la chaîne 2 ne recrée aucun événement BSC TV dans WP-Cron. Les URL confidentielles et bilans de collecte restent dans **1 · Le live Monde → Import Infomaniak**, avec un lien depuis la chaîne 2.

Pour arrêter la chaîne 2, désactiver son lecteur et ses interludes dans ses propres écrans. La chaîne 1 conserve sa programmation et ses réglages.

## Validation locale

`tests/stations-integration.php` utilise une copie jetable de WordPress/SQLite. Il vérifie le filtrage de langue, les horloges et modifications indépendantes, les directs simultanés, la reprise après direct, les bibliothèques et imports de playlists séparés, les filtres 1990–1999, les formulaires, les routes publiques et le traitement commun par Infomaniak. Les plateformes sont simulées ; aucun identifiant de production n’est utilisé.


## Pastilles World et France (1.27.0)

Dans **BSC TV – Pastille Live → Programme → Chaînes affichées**, **World et France** est le choix par défaut. Deux pastilles indépendantes montrent le programme diffusé sur chaque antenne. **Une seule chaîne** permet de conserver une pastille World ou France. Le choix ne modifie ni les bibliothèques ni les horloges enregistrées.

Les logos fournis sont inclus sans modification : **World** ouvre [Le live](https://brewerssocialclub.com/le-live/) et **France** ouvre [Brewers Television France](https://brewerssocialclub.com/brewers-television-club-france/). Chaque lien est séparé du bouton qui ouvre le déroulant, pour conserver une navigation et un usage au clavier distincts. Le clic sur le programme affiche le programme courant, les prochains créneaux de l’antenne et les directs annoncés par ses sources. Le survol à la souris, le clic tactile, la fermeture extérieure et la touche Échap conservent leur fonctionnement.

**Logos et pages des chaînes** permet de remplacer chaque fichier et chaque destination. **Style → Logos et disposition des chaînes** règle la taille par appareil, l’espacement entre les chaînes et entre logo et titre, le contour, l’arrondi, le fond et la typographie du nom. Les réglages de la pastille LIVE, du titre, des horaires et du panneau restent disponibles. Les deux pastilles se placent sur une seule colonne quand la largeur manque.

Sans Elementor : `[bsc_tv format="badge" badge_stations="both"]`. Pour une seule antenne : `[bsc_tv format="badge" station="fr"]`. Un shortcode existant sans `badge_stations` conserve son antenne unique. Les paramètres `show_station_logo=""` et `show_station_name=""` masquent les éléments correspondants.

Chaque pastille utilise sa propre réponse publique WordPress. Les widgets identiques partagent un rafraîchissement ; World et France reçoivent des titres distincts. Aucun SDK vidéo ni nouvel import de plateforme n’est chargé par les logos. Les logos ne changent pas lors d’une actualisation du programme.

Tests : `php tests/badge-stations.php /chemin/vers/wordpress-de-test` et `node tests/badge-browser.mjs`.
