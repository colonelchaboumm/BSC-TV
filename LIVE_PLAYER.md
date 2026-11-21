# BSC TV — Le live 24/7

Version 1.9.0, branche `feature/live-daily-infomaniak`. Cette livraison ajoute la page d’administration **BSC TV → Le live**, un import quotidien réservé à Infomaniak et une rotation mélangée entre chaînes et épisodes. Elle ne déploie aucune modification sur WordPress ou sur l’hébergement.

## Mise en service

1. Installer le ZIP sur une installation de test avec les chaînes BSC TV déjà enregistrées. Les accès YouTube et Twitch restent dans **BSC TV → Réglages et imports**.
2. Ouvrir **BSC TV → Le live** et activer le lecteur 24/7. Il est désactivé par défaut sur une nouvelle installation.
3. Dans le bloc **Import quotidien · Infomaniak**, copier le lien confidentiel dans le planificateur Infomaniak et programmer un passage par jour. Le lien n’est pas une clé YouTube ou Twitch.
4. Consulter le bilan global et les résultats par chaîne après ce passage. Le premier lot utilisable peut démarrer l’antenne ; les lots suivants complètent son catalogue et sa grille sans interrompre le programme courant.
5. Ajouter **BSC TV — Live Player 24/7** dans Elementor ou utiliser `[bsc_tv_player]`. Les autres widgets et la grille des véritables directs conservent leur fonctionnement.

## Import quotidien avec Infomaniak

### Tâche à créer dans le Manager

Dans le Manager Infomaniak : **site → Outils avancés → Planificateur de tâches → Planifier une tâche**. Coller le lien réservé affiché dans **BSC TV → Le live**, choisir une exécution **une fois par jour**, par exemple à 03:00, avec le même fuseau que WordPress, puis activer la tâche.

Le lien appelle `bsc-tv/v1/import-daily` avec un secret propre à cette installation. HTTPS est obligatoire en production. Un administrateur peut renouveler le lien ; il faut alors remplacer l’ancien dans Infomaniak. Ne pas publier ce lien dans un ticket public, une capture ou un dépôt Git. La réponse JSON ne contient aucune clé API ni le secret du lien.

Le plugin sauvegarde le jour du lancement avant toute collecte, sous un verrou en base. Les appels suivants le même jour ne font aucun appel aux plateformes, même après un traitement partiel ou un renouvellement du lien. La limite suit les jours calendaires du fuseau WordPress, y compris lors du changement d’heure. Une erreur de verrou avant le démarrage ne consomme pas un passage qui n’a pas commencé.

Le même appel traite les pages successives. Il dispose d’un budget de 240 secondes et conserve au moins 30 secondes pour terminer une page en cours. Une limite de l’hébergement peut interrompre le processus plus tôt. La progression est sauvegardée à chaque page et reprend **au passage du lendemain** ; aucun événement WP-Cron de continuation n’est créé. Un bilan partiel reste visible dans Le live.

Pour un catalogue volumineux sur Serveur Cloud avec WP-CLI, une tâche serveur peut lancer :

```sh
wp --path=/chemin/vers/wordpress bsc-tv import
```

La commande est aussi limitée à une exécution par jour et autorise un budget de 480 secondes. Utiliser les chemins réels de l’installation et de WP-CLI, avec le compte propriétaire du site. L’URL HTTP et la commande partagent la même limite quotidienne.

La version 1.9.0 supprime les anciens événements `bsc_tv_player_replay_import_batch`. Les visites, les modifications de chaînes et l’enregistrement des accès ne relancent plus cet import. Le bouton d’une ancienne page d’administration ne lance pas de collecte et ne renouvelle pas le lien. Les publications à venir et les contrôles des vrais directs restent des traitements séparés. Il n’est pas nécessaire de désactiver globalement WP-Cron pour retirer cet import de ses tâches.

Sources : [planificateur Infomaniak](https://www.infomaniak.com/fr/support/faq/2161/programmer-des-taches-sur-hebergement-web), [Crontab sur Serveur Cloud](https://www.infomaniak.com/fr/support/faq/350/utiliser-crontab-sur-serveur-cloud).

### Sources récupérées

- **YouTube** : playlist officielle des vidéos publiées de chaque chaîne suivie, puis détails par lots de 50. Vidéos ordinaires et anciens directs terminés sont acceptés avec titre, image, publication et durée confirmés. Les vidéos privées, futures, encore en traitement ou refusant l’intégration sont exclues. L’historique n’utilise pas `search.list` ; il consomme le quota habituel de la Data API.
- **Twitch** : VOD `archive` encore disponibles, avec leur durée officielle. L’archive du direct en cours, les clips et les highlights sont écartés. Les VOD supprimés ou expirés ne peuvent pas être récupérés.
- La fenêtre couvre les deux mois calendaires précédant le début du passage, avec ajustement au dernier jour du mois cible si nécessaire. Une publication future et une durée absente ou supérieure à 24 heures sont exclues.
- Seules les chaînes WordPress publiées, avec un accès API configuré et les replays autorisés, participent. Leurs mots-clés filtrent les titres. Aucune nouvelle chaîne n’est créée.
- Les émissions récupérées vont dans les **Archives BSC TV**, avec le lien de chaîne et donc la fiche BSC BDD héritée. Elles ne deviennent pas des annonces de futurs directs.
- Le rapprochement par identifiant vidéo inclut les liens YouTube courts, les brouillons, la corbeille et les entrées protégées. Les exclusions, annulations et archives de brouillons non approuvées restent conservées. Les titres, contenus, extraits, slugs, visuels, profils et durées éditoriales des fiches existantes restent présents.
- Une page contient au plus 50 vidéos. Une erreur est retentée jusqu’à trois fois dans le même passage. Une pagination répétée ou dépassant 100 pages par chaîne produit un bilan partiel ou une erreur, sans boucle infinie.

Références : [vidéos YouTube d’une chaîne](https://developers.google.com/youtube/data/reference/rest/v3/playlistItems/list), [métadonnées YouTube](https://developers.google.com/youtube/data/reference/rest/v3/videos), [vidéos Twitch](https://dev.twitch.tv/docs/api/reference/videos).

## Grille de diffusion dans l’administration

**BSC TV → Le live** présente l’antenne actuelle puis exactement trois dates : **Aujourd’hui**, **Demain**, **Après-demain**. Les bornes vont de minuit aujourd’hui à minuit après la troisième journée dans le fuseau WordPress. Une journée de changement d’heure peut durer 23 ou 25 heures. Les programmes traversant minuit apparaissent comme une suite dans la journée suivante.

Chaque ligne indique l’horaire, le titre, la chaîne et l’état **Diffusé**, **En cours**, **LIVE** ou **À venir**. Le nombre de chaînes présentes apparaît dans le titre de la journée. L’historique commence avec cette version ; les programmes passés avant son installation ne sont pas inventés. Le journal et la file restent bornés à 500 entrées chacun ; des vidéos très courtes peuvent réduire la période couverte.

- **Décaler** modifie le début d’un programme futur et recale les suivants. Un chevauchement est refusé. Une plage laissée libre est affichée comme une pause de programmation.
- **Remplacer** choisit un autre replay du catalogue utilisable. Sa durée recale les suivants ; sa disponibilité et le délai entre rediffusions restent respectés.
- **Programmer ensuite** ajoute une priorité à la prochaine transition. Elle est consommée une seule fois.
- **Remélanger la programmation automatique** crée une nouvelle séquence pour les prochains programmes et remplace les choix manuels futurs. Le programme courant reste stable.
- **Passer au programme suivant** agit sur un replay courant, jamais sur un vrai direct. Un ancien formulaire de saut est refusé si le programme courant a changé.

Un vrai direct confirmé reste prioritaire lorsque cette option est activée. Il suspend la rotation puis le replay reprend à sa position d’interruption. Les horaires suivants sont prévisionnels pendant le direct et sont décalés à sa fin. Les formulaires d’édition sont protégés par une capacité administrateur, un nonce et une empreinte de la file ; une grille obsolète ne peut pas écraser une modification concurrente.

## Programmation automatique

La rotation sélectionne d’abord les replays disponibles en respectant les dates, exclusions et délais de rediffusion. Elle équilibre ensuite le temps de présence des chaînes dans la journée. Une chaîne avec de nombreux épisodes ne reçoit pas davantage de créneaux du seul fait de la taille de son catalogue. À temps de présence égal, elle évite de reprendre la même chaîne ou le même créateur et départage les possibilités par un tirage pseudo-aléatoire.

À l’intérieur d’une chaîne, les épisodes pas encore diffusés dans la journée et les archives encore jamais diffusées sont privilégiés. Le dernier épisode est évité lorsqu’une autre option équivalente est disponible ; les autres possibilités sont départagées par le tirage. La priorité aux nouveautés reste un critère parmi les programmes disponibles. Des durées différentes peuvent produire des nombres de créneaux différents : l’équilibrage tient compte du temps de diffusion.

Le tirage est stable et enregistré dans l’horloge commune. Une visite ne remélange pas la grille. Les dates et la progression font varier les séquences entre journées. Une nouvelle commande de mélange change la graine des futurs choix sans modifier l’émission en cours.

La diversité dépend du catalogue et des choix manuels : une seule chaîne utilisable ne peut pas alterner avec des sources absentes. La page signale ce cas. Le délai entre rediffusions reste prioritaire ; si aucune source n’est disponible, le lecteur attend. Les choix manuels et les créneaux qui les précèdent sont protégés lors d’une actualisation automatique. Les anciennes lignes dépourvues de marqueur manuel sont préservées par précaution ; le bouton de mélange permet de repartir sur la nouvelle rotation.

La file couvre jusqu’à 72 heures, dans une limite de 500 programmes. Elle se complète chaque heure et lors des mises à jour du catalogue ; un secours local du lecteur la complète si nécessaire. Ce travail de programmation ne collecte aucune vidéo et ne fait aucun appel API. Il reste distinct de l’import Infomaniak quotidien.

## Réglages Elementor

- Contenu : titre, bouton, logo fixe, avatar et lien BSC BDD, créateur, émission, programme suivant, horaires, progression et badges.
- Lecture : démarrage sur clic par défaut, autoplay facultatif, démarrage sans son. Les commandes du volume et du plein écran restent celles de la plateforme.
- Style : format 16:9, couleurs, typographies, marges, espacements et arrondis. Le logo et l’avatar disposent de dimensions et positions par appareil.
- **Style → Écran bombé et CRT** : deux options indépendantes, désactivées par défaut. Reflets, ombres du verre et lignes de balayage sont placés devant l’image avec `pointer-events: none`. Relief, courbure, intensité, espacement et lueur sont personnalisables par appareil. L’effet bombé simule un verre, sans déformation géométrique de la vidéo.
- **Couleur à l’extérieur des arrondis** : le lecteur et les filtres sont rognés dans les coins selon la courbure ; la couleur choisie remplit les parties extérieures. Les grandes courbures peuvent rogner les éléments proches des coins.
- Logo, avatar et badges restent hors de l’image du lecteur officiel. L’avatar et le lien sont ceux de la fiche publique BSC BDD existante.

## Fonctionnement et limites

Les replays restent sur leurs plateformes : aucun téléchargement, stockage, réencodage ou proxy vidéo. Les références et métadonnées utilisent les émissions et chaînes BSC TV existantes. Le catalogue local examine au plus les 1 000 émissions publiées ou archivées les plus récemment modifiées et reste en cache une minute, avec invalidation à la modification des sources.

L’option non autoloadée `bsc_tv_player_clock` contient l’antenne, la file `plan`, les diffusions récentes `aired`, l’historique et la graine du mélange. L’import conserve ses curseurs dans `bsc_tv_player_replay_import` et son passage quotidien dans `bsc_tv_player_import_daily`. Les traitements relisent l’état sous verrou. Après plus de 200 transitions pendant une longue inactivité, le rattrapage est borné.

La route publique `GET /wp-json/bsc-tv/v1/player` renvoie l’émission en cours et seulement les cinq suivantes, sans réglage privé ni appel externe. Son rafraîchissement est généralement de 60 secondes et se rapproche d’une transition. Les SDK externes sont chargés à la demande de lecture.

Les plateformes peuvent bloquer ou retarder une intégration selon le compte, la région, la publicité, l’autoplay ou la disponibilité de la vidéo. La synchronisation reste celle permise par leurs lecteurs officiels. Un direct spontané non découvert ne peut pas prendre la priorité ; une confirmation de direct expire selon la durée existante de vingt minutes. Une erreur de lecture conserve un lien vers la source et ne permet pas à un visiteur de changer l’antenne globale.

L’intégration Twitch nécessite HTTPS en production et les bons domaines `parent`. Les filtres CSS ne suivent pas le plein écran interne des plateformes. Les règles d’intégration des plateformes restent applicables aux calques visuels demandés.

## Validation

Version 1.9.0 : **73 contrôles d’horloge et de grille, 73 contrôles WordPress/Elementor, 66 contrôles d’import historique et quotidien, 20 contrôles JavaScript avec SDK simulés**, soit 232 contrôles locaux.

Les scénarios couvrent les trois jours calendaires, les changements d’heure, les programmes traversant minuit, l’historique du jour, la stabilité de la grille, la diversité malgré un catalogue déséquilibré, les durées différentes, les épisodes successifs et les interventions manuelles. L’import vérifie une exécution quotidienne authentifiée traitant plusieurs pages, les appels répétés sans travail API, les limites après interruption, la reprise du lendemain, les erreurs et verrous, les nonces, la rotation du secret et l’absence de tâches WP-Cron d’import.

```sh
php tests/player-clock.php
php tests/player-integration.php /chemin/vers/wordpress-de-test
php tests/player-import.php /chemin/vers/wordpress-de-test
node tests/player-browser.mjs
```

Les tests WordPress copient une base SQLite jetable avant toute écriture. Les API et SDK sont simulés : ils ne prouvent pas l’accès réel à chaque chaîne ni la configuration d’une tâche Infomaniak. La fixture émet deux avertissements Elementor préexistants sous PHP 8.4. La livraison doit être installée et la tâche quotidienne configurée dans le Manager pour être active sur le site.
