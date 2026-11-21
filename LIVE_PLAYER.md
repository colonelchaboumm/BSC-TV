# BSC TV — Live Player 24/7

Lecteur ajouté dans BSC TV 1.5.0. Filtres rétro superposés à l’image dans la version 1.5.2 sur la branche `feature/player-crt`. Aucun déploiement en production.

Le nouveau widget compose une antenne commune à partir des émissions existantes et des confirmations de direct déjà enregistrées. Il conserve un début et une fin de diffusion côté WordPress. Un visiteur qui rejoint une émission commencée depuis quinze minutes demande au lecteur officiel une lecture à quinze minutes. Les replays alternent avec les vrais directs prioritaires, puis reprennent au point d’interruption lorsque le direct se termine.

## Mise en service

1. Installer la version sur un WordPress de test, puis ouvrir **BSC TV → Réglages et imports → BSC TV LIVE 24/7**. Le lecteur est désactivé par défaut.
2. Vérifier les chaînes et les émissions déjà enregistrées. Dans une chaîne, le bloc **Diffusion BSC TV 24/7** autorise les directs et les replays et règle la priorité entre directs. Une chaîne dépubliée est retirée du lecteur.
3. Dans une émission existante ou archivée, choisir **Inclure ce replay dans la programmation** et renseigner son lien vidéo, sa durée exacte en secondes (de 1 seconde à 24 heures) et sa date de publication. Les liens YouTube vidéo et Twitch `/videos/ID` sont acceptés. Un lien Twitch de chaîne correspond à un vrai direct, pas à un replay.
4. Le mode **Automatique** utilise les archives terminées dont la lecture intégrée, la durée et la date sont confirmées. La collecte YouTube quotidienne existante complète au plus 50 archives connues par passage avec les détails officiels. Il n’y a pas de recherche de nouvelles vidéos ni de nouveau catalogue de chaînes. Les archives de brouillons non approuvées, les brouillons, les éléments privés, protégés ou à la corbeille sont exclus.
5. Activer l’antenne, régler la priorité aux nouveautés, le délai entre rediffusions et la tolérance de synchronisation. La première activation met la collecte existante en attente d’exécution pour compléter les métadonnées connues, sans ajouter de nouveau cron récurrent. L’antenne attend si le catalogue ne permet pas de respecter le délai choisi. Ajouter suffisamment de replays ou réduire ce délai pour couvrir 24 heures.
6. Pour privilégier une émission disponible, utiliser **Programmer ensuite**. La liste prioritaire peut contenir vingt références. Elle respecte les dates de publication et le délai de rediffusion. **Passer au programme suivant** agit sur le replay courant, pas sur un vrai direct. Les cinq prochains programmes sont des estimations qui peuvent évoluer à la découverte de nouveaux contenus.
7. Déposer **BSC TV — Live Player 24/7** dans Elementor. Le shortcode `[bsc_tv_player]` est également disponible. Les anciens widgets et la grille conservent leur fonctionnement.

## Réglages Elementor

- Contenu : titre, libellé du bouton, logo fixe, affichage de l’avatar BSC BDD, créateur, titre d’émission, lien BSC BDD, programme suivant, horaires, progression et cinq badges.
- Lecture : démarrage sur clic par défaut, tentative d’autoplay facultative et démarrage sans son. Les contrôles du volume et du plein écran restent ceux de la plateforme.
- Style : format 16:9 par défaut, couleurs, typographies, marges, espacements et arrondis. Dimensions et positions du logo et de l’avatar disposent de réglages responsive ordinateur/tablette/mobile. Le logo a une opacité, un fond, des marges et une bordure. L’avatar peut être rond ou carré, avec bordure et ombre.
- **Style → Écran bombé et CRT** : deux activations indépendantes, désactivées par défaut. Le verre bombé propose une profondeur de relief, une courbure et une teinte d’ombre. Le filtre CRT propose une intensité de 0 à 100 %, un espacement des lignes, un rayon de lueur et une teinte. Relief, courbure, intensité, espacement et lueur ont des réglages ordinateur/tablette/mobile. L’aperçu et la page publiée utilisent les mêmes styles générés par Elementor.
- Les filtres sont placés **devant l’image** : reflets et ombres du verre sur une première couche, lignes de balayage et trame RVB sur une seconde. Le précédent cadre extérieur est retiré, sans ajout de marges autour de la vidéo. Le rendu bombé est une simulation de verre par ombrage et reflet, sans déformation géométrique de la vidéo. Les calques laissent traverser tous les clics grâce à `pointer-events: none`. Ils ne demandent ni animation, ni script, ni requête supplémentaire. Le shortcode conserve son apparence par défaut ; cette personnalisation est disponible dans Elementor.
- Logo, avatar et badges restent hors de l’image du lecteur officiel. L’avatar et le lien viennent de la fiche BSC BDD publique existante. Il n’y a pas de deuxième base d’avatars.
- Les métadonnées et le programme sont prévisualisés dans l’éditeur Elementor. Le bouton permet de charger le véritable lecteur dans l’aperçu.

## Fonctionnement et performances

`includes/player.php` gère la sélection et l’horloge. Les références de replays sont stockées sur les émissions existantes dans `_bsc_tv_player_media`. Les autorisations et priorités sont sur les chaînes existantes dans `_bsc_tv_player_channel`. L’option `bsc_tv_player_clock`, non autoloadée, conserve la référence en cours, les temps de diffusion, les interruptions, la file manuelle et l’historique nécessaire aux rediffusions.

Le catalogue local examine les 1 000 émissions publiées ou archivées les plus récemment modifiées. Il est mis en cache une minute et invalidé à la modification des émissions, chaînes ou états synchronisés. Les transitions sont protégées par un verrou en base. Les options sont relues sous verrou pour éviter qu’une requête concurrente remette l’horloge à zéro. Une nouvelle émission n’interrompt pas le replay en cours. Après plus de 200 transitions non traitées pendant une longue inactivité, l’antenne repart sur un créneau commun actuel pour borner le travail serveur.

La route publique `GET /wp-json/bsc-tv/v1/player` retourne uniquement l’antenne actuelle, l’heure serveur et les cinq programmes suivants. Elle ne contient ni clé API ni réglage privé. Aucun appel externe n’est réalisé pour construire cette réponse. Le navigateur l’actualise toutes les 60 secondes lorsqu’il est visible et à proximité d’une transition, avec un minimum de cinq secondes entre les passages automatiques aux limites. Les vérifications de position sont locales au lecteur. Aucune nouvelle tâche cron n’est créée. Les scripts et styles sont chargés uniquement pour le nouveau widget ou shortcode, et les SDK externes uniquement à la demande de lecture.

Les détails `contentDetails` et `status` sont ajoutés à la requête `videos.list` existante. L’enrichissement porte uniquement sur des vidéos déjà connues ; au plus une tranche supplémentaire de 50 identifiants par collecte quotidienne lorsque le lecteur est activé. Aucun téléchargement, stockage, réencodage ou proxy vidéo n’est ajouté.

Pour une émission YouTube terminée, la priorité de fraîcheur utilise la plus récente de la date de publication et de la fin du direct, afin qu’une ancienne annonce devenue replay puisse être traitée comme une nouveauté. La date saisie dans la fiche peut remplacer cette valeur.

## Limites des plateformes

- La synchronisation est au mieux de ce que permet le lecteur officiel. Publicités, restrictions géographiques, connexion requise, refus d’intégration, latence des vrais directs et politiques du navigateur peuvent retarder ou empêcher la lecture et le positionnement. Aucune garantie de synchronisation à l’image près.
- Les contrôles de direct restent ceux de BSC TV : contrôle proche d’une émission annoncée, puis suivi du direct connu. Un direct spontané non découvert ne peut pas prendre la priorité. La confirmation expire selon le délai existant de vingt minutes. Si plusieurs directs sont présents, la priorité choisit le premier ; il reste stable jusqu’à sa fin ou son retrait.
- Le lecteur Twitch officiel nécessite au moins 400 × 300 px et les bons domaines `parent`. En dessous de 400 px, un lien vers Twitch est proposé. L’intégration Twitch exige HTTPS en production. Les VOD supprimés ou expirés deviennent indisponibles.
- YouTube nécessite au moins 200 × 200 px et un `Referer` valide. Ne pas supprimer le referrer avec une politique `no-referrer`. Un YouTube refusant l’intégration ou une erreur 153 conserve un accès à la source.
- Une vidéo indisponible affiche une erreur, un lien officiel et une possibilité de réessayer. Elle ne modifie pas l’antenne globale depuis le navigateur d’un visiteur. Le prochain programme reste à son horaire ; un administrateur peut exclure la vidéo ou passer à la suivante.
- Aucun nouveau système ne recherche tous les replays des chaînes. Les collecteurs actuels couvrent les émissions connues. Les VOD Twitch et les anciennes archives sans métadonnées fiables peuvent être préparés manuellement depuis leurs fiches.

Sources : [YouTube IFrame API](https://developers.google.com/youtube/iframe_api_reference), [exigences du lecteur YouTube](https://developers.google.com/youtube/terms/required-minimum-functionality), [lecteur Twitch officiel](https://dev.twitch.tv/docs/embed/video-and-clips/).

Les filtres restent dans la page : le plein écran déclenché dans le lecteur officiel n’emporte pas les calques CSS de BSC TV. Les règles d’intégration restreignent les superpositions devant les lecteurs : [exigences YouTube](https://developers.google.com/youtube/terms/required-minimum-functionality) et [conditions d’intégration Twitch, § 1.3](https://dev.twitch.tv/docs/embed/). L’option visuelle demandée n’est pas présentée comme un mode d’intégration approuvé par ces plateformes.

## Validation

```sh
php tests/player-clock.php
node tests/player-browser.mjs
php tests/player-integration.php /chemin/vers/wordpress-de-test
```

Le test WordPress utilise une installation locale contenant Elementor, BSC BDD et le pilote SQLite. Il copie `wp-content/database/bsc-tv-integration.sqlite` dans un répertoire temporaire avant toute modification. Ne pas l’utiliser avec une base de production. Le fichier de configuration de la fixture peut fournir ses réglages WordPress habituels, sans accès API réel.

Les tests de l’horloge couvrent les visiteurs successifs, les changements de programmes, les six tranches de nouveauté, l’alternance, les priorités manuelles et de direct, l’absence de basculements répétés, la reprise d’un replay, les délais, les futures publications, les premières et les longues inactivités. L’intégration vérifie les profils BSC BDD, les exclusions, les contrôles Elementor et les anciens widgets, la route REST et l’absence d’appels externes. Les tests JavaScript emploient des doubles des SDK officiels pour simuler blocage d’autoplay, refus de vidéo, changement d’avatar et passage YouTube/Twitch ; ils ne valident pas les publicités ou autorisations réelles de chaque compte.

Validation 1.5.2 : 24 contrôles d’horloge, 44 contrôles WordPress/Elementor et 20 contrôles du navigateur avec SDK simulés. Les contrôles sauvegardent un document Elementor puis génèrent son CSS : effets désactivés, verre seul, CRT seul, effets combinés, couleurs et dimensions, réglages mobiles distincts. Le navigateur vérifie les calques devant une lecture YouTube réelle, la transmission des clics vers l’iframe et l’affichage à 390 px. La profondeur passe de 22 à 12 px et l’intensité de 55 à 25 % selon les réglages mobiles enregistrés. La désactivation rend les deux filtres transparents. Aucun cadre extérieur n’est ajouté.

La livraison initiale 1.5.0 a également vérifié les scénarios de grille, collecte quotidienne et découverte, ainsi qu’une véritable lecture YouTube dans deux onglets locaux rejoignant le même programme à des positions différentes selon l’heure d’arrivée. Les basculements vers de véritables comptes Twitch restent à valider en préproduction HTTPS. L’installation de test émet deux avertissements de dépréciation Elementor sous PHP 8.4, indépendants du nouveau lecteur.

Avant mise en service, tester les véritables sources YouTube et Twitch sur le domaine de préproduction HTTPS et vérifier les politiques de consentement et de cache du site. La production n’est pas modifiée par la livraison.

## Fichiers

Ajouts : `includes/player.php`, `includes/player-admin.php`, `includes/player-view.php`, `includes/player-widget.php`, `assets/player.js`, `assets/player.css`, `tests/player-clock.php`, `tests/player-browser.mjs`, `tests/player-integration.php`, `LIVE_PLAYER.md`.

Modifications : `bsc-tv.php` (version et branchements), `includes/admin.php` (section dans la page actuelle), `includes/sync.php` (métadonnées de vidéos déjà connues et réutilisation du passage quotidien), `README.md` (présentation du widget).
