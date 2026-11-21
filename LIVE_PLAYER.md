# BSC TV — Live Player 24/7

Lecteur ajouté dans BSC TV 1.5.0. Filtres rétro superposés à l’image et couleur des coins arrondis dans la version 1.5.3 sur la branche `feature/player-crt`. Aucun déploiement en production.

La version 1.6.0 ajoute la grille d’administration modifiable sur 48 ou 72 heures, sur la branche `feature/player-schedule`.

La version 1.6.1 réconcilie l’horloge sous le verrou partagé avant de modifier les priorités, de recréer la grille ou d’éditer un créneau. Une page d’administration laissée ouverte après une transition ne redémarre plus le replay déjà commencé ni ne repousse artificiellement les suivants. Un ancien bouton « Passer au programme suivant » est refusé si le programme courant a changé.

Le nouveau widget compose une antenne commune à partir des émissions existantes et des confirmations de direct déjà enregistrées. Il conserve un début et une fin de diffusion côté WordPress. Un visiteur qui rejoint une émission commencée depuis quinze minutes demande au lecteur officiel une lecture à quinze minutes. Les replays alternent avec les vrais directs prioritaires, puis reprennent au point d’interruption lorsque le direct se termine.

## Mise en service

1. Installer la version sur un WordPress de test, puis ouvrir **BSC TV → Réglages et imports → BSC TV LIVE 24/7**. Le lecteur est désactivé par défaut.
2. Vérifier les chaînes et les émissions déjà enregistrées. Dans une chaîne, le bloc **Diffusion BSC TV 24/7** autorise les directs et les replays et règle la priorité entre directs. Une chaîne dépubliée est retirée du lecteur.
3. Dans une émission existante ou archivée, choisir **Inclure ce replay dans la programmation** et renseigner son lien vidéo, sa durée exacte en secondes (de 1 seconde à 24 heures) et sa date de publication. Les liens YouTube vidéo et Twitch `/videos/ID` sont acceptés. Un lien Twitch de chaîne correspond à un vrai direct, pas à un replay.
4. Le mode **Automatique** utilise les archives terminées dont la lecture intégrée, la durée et la date sont confirmées. La collecte YouTube quotidienne existante complète au plus 50 archives connues par passage avec les détails officiels. Il n’y a pas de recherche de nouvelles vidéos ni de nouveau catalogue de chaînes. Les archives de brouillons non approuvées, les brouillons, les éléments privés, protégés ou à la corbeille sont exclus.
5. Activer l’antenne, régler la priorité aux nouveautés, le délai entre rediffusions et la tolérance de synchronisation. La première activation met la collecte existante en attente d’exécution pour compléter les métadonnées connues, sans ajouter de nouveau cron récurrent. L’antenne attend si le catalogue ne permet pas de respecter le délai choisi. Ajouter suffisamment de replays ou réduire ce délai pour couvrir 24 heures.
6. Dans **Grille de diffusion**, choisir **48 heures** ou **72 heures**. La grille est enregistrée et le lecteur suit ces créneaux. Utiliser **Décaler** pour modifier le début d’un programme et décaler les suivants ; utiliser **Remplacer** pour sélectionner un autre replay disponible. Le programme en cours est conservé. **Programmer ensuite** intègre une priorité à la grille en respectant les disponibilités et le délai de rediffusion. **Passer au programme suivant** agit sur le replay courant, pas sur un vrai direct.
7. Déposer **BSC TV — Live Player 24/7** dans Elementor. Le shortcode `[bsc_tv_player]` est également disponible. Les anciens widgets et la grille conservent leur fonctionnement.

## Réglages Elementor

- Contenu : titre, libellé du bouton, logo fixe, affichage de l’avatar BSC BDD, créateur, titre d’émission, lien BSC BDD, programme suivant, horaires, progression et cinq badges.
- Lecture : démarrage sur clic par défaut, tentative d’autoplay facultative et démarrage sans son. Les contrôles du volume et du plein écran restent ceux de la plateforme.
- Style : format 16:9 par défaut, couleurs, typographies, marges, espacements et arrondis. Dimensions et positions du logo et de l’avatar disposent de réglages responsive ordinateur/tablette/mobile. Le logo a une opacité, un fond, des marges et une bordure. L’avatar peut être rond ou carré, avec bordure et ombre.
- **Style → Écran bombé et CRT** : deux activations indépendantes, désactivées par défaut. Le verre bombé propose une profondeur de relief, une courbure et une teinte d’ombre. Le filtre CRT propose une intensité de 0 à 100 %, un espacement des lignes, un rayon de lueur et une teinte. Relief, courbure, intensité, espacement et lueur ont des réglages ordinateur/tablette/mobile. L’aperçu et la page publiée utilisent les mêmes styles générés par Elementor.
- Les filtres sont placés **devant l’image** : reflets et ombres du verre sur une première couche, lignes de balayage et trame RVB sur une seconde. Le précédent cadre extérieur est retiré, sans ajout de marges autour de la vidéo. Le rendu bombé est une simulation de verre par ombrage et reflet, sans déformation géométrique de la vidéo. Les calques laissent traverser tous les clics grâce à `pointer-events: none`. Ils ne demandent ni animation, ni script, ni requête supplémentaire. Le shortcode conserve son apparence par défaut ; cette personnalisation est disponible dans Elementor.
- **Couleur à l’extérieur des arrondis** : lorsque le verre bombé est activé, la vidéo et les filtres sont rognés dans les coins suivant la **Courbure du verre**. La couleur choisie remplit uniquement ces zones extérieures ; par défaut, elle reprend le fond du widget. Aucun espacement ni cadre supplémentaire. Les grandes courbures peuvent rogner les éléments proches des coins du lecteur. La courbure reste réglable par appareil.
- Logo, avatar et badges restent hors de l’image du lecteur officiel. L’avatar et le lien viennent de la fiche BSC BDD publique existante. Il n’y a pas de deuxième base d’avatars.
- Les métadonnées et le programme sont prévisualisés dans l’éditeur Elementor. Le bouton permet de charger le véritable lecteur dans l’aperçu.

## Grille de diffusion dans l’administration

La grille est intégrée à **BSC TV → Réglages et imports → BSC TV LIVE 24/7**. Les programmes à venir sont regroupés par jour, avec horaires, titre, créateur, plateforme, durée et actions. Les vues couvrent les 48 ou 72 prochaines heures à partir de l’heure actuelle, dans le fuseau WordPress. L’émission en cours est affichée séparément et ne propose pas les actions de modification.

- **Décaler** ouvre un éditeur de date et d’heure. Le programme et les suivants sont déplacés de la même durée. Il peut être avancé dans une plage libre après le précédent ; un chevauchement est refusé. Un déplacement plus tardif peut créer une **Pause de programmation**, affichée dans la grille. Le lecteur attend ce créneau et un visiteur arrivant après la pause rejoint la bonne position de lecture.
- **Remplacer** ouvre une liste des replays utilisables du catalogue existant. La durée du nouveau replay recale les suivants. Sa date de publication et le délai entre deux rediffusions sont respectés, ce qui peut repousser le début demandé. Les brouillons, sources exclues et vidéos indisponibles dans le catalogue ne sont pas proposés.
- **Recréer la grille automatique** remplace les modifications futures par une nouvelle rotation selon les priorités actuelles. Le replay en cours est conservé. La grille enregistrée garde ses choix malgré les nouvelles découvertes ; ce bouton permet de les prendre en compte immédiatement.
- Un vrai direct prioritaire suspend le replay courant et la grille. À sa fin, la même durée est ajoutée au replay et aux créneaux suivants. Pendant le direct, les horaires sont prévisionnels. Afficher la grille pendant un long direct ne décale pas deux fois les programmes.
- Un formulaire devenu obsolète est refusé si la grille a été modifiée ou si le programme a commencé. Les mutations nécessitent un administrateur et un nonce WordPress valide, puis relisent l’état sous le verrou partagé du lecteur.

L’ouverture de l’administration complète la grille jusqu’à 72 heures, dans une limite de 500 programmes. Avec des vidéos très courtes, elle peut couvrir une durée inférieure. Les créneaux repoussés au-delà de la fenêtre visible restent conservés, dans cette limite. À la fin de la file enregistrée, le lecteur reprend sa sélection automatique. Aucun nouveau cron ni appel externe n’est ajouté et le navigateur public continue de recevoir seulement les cinq programmes suivants.

## Fonctionnement et performances

`includes/player.php` gère la sélection et l’horloge. Les références de replays sont stockées sur les émissions existantes dans `_bsc_tv_player_media`. Les autorisations et priorités sont sur les chaînes existantes dans `_bsc_tv_player_channel`. L’option `bsc_tv_player_clock`, non autoloadée, conserve la référence en cours, les temps de diffusion, les interruptions, la file manuelle et l’historique nécessaire aux rediffusions.

`includes/player-schedule.php` enregistre la file future dans la clé `plan` de cette même option. Il réutilise la sélection et le démarrage des créneaux du lecteur. La projection s’exécute uniquement dans l’administration et les actions de programmation ; les visiteurs consomment la file déjà enregistrée. Une empreinte de cette file protège les formulaires contre les modifications concurrentes. Une durée modifiée ou une source devenue inutilisable est réévaluée ; une émission retirée est exclue de la file.

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

Version 1.6.0 : **45 contrôles d’horloge et de grille, 56 contrôles WordPress/Elementor, 20 contrôles JavaScript avec SDK simulés**. Les nouveaux scénarios couvrent la conservation de trois jours, les décalages, les remplacements et le recalage des durées, la diffusion réelle d’un créneau modifié, l’arrivée après une pause, les délais de rediffusion, les longs directs, la limite de 500 créneaux, les conflits de formulaires, les droits d’accès, les nonces et l’identité entre la file stockée et la réponse REST. Aucun appel API externe pour ces opérations.

Version 1.6.1 : **45 contrôles d’horloge et de grille, 62 contrôles WordPress/Elementor et 20 contrôles JavaScript**, soit 127 contrôles. Les six nouveaux contrôles vérifient notamment une priorité soumise quinze minutes après une transition sans visite publique : le replay courant conserve son début prévu et son décalage de lecture. Ils couvrent aussi la recréation automatique, le refus d’un ancien bouton de saut, la préparation d’une grille sur une horloge périmée, les permissions et l’absence d’appels externes.

Les formulaires de remplacement et de décalage ont également été soumis dans un navigateur sur une copie locale de WordPress : les changements sont enregistrés, les horaires suivants décalés et la pause de programmation affichée, avec le programme courant conservé. Ce test utilise une base SQLite jetable exportée par sauvegarde, sans accès à la production.

```sh
php tests/player-clock.php
node tests/player-browser.mjs
php tests/player-integration.php /chemin/vers/wordpress-de-test
```

Le test WordPress utilise une installation locale contenant Elementor, BSC BDD et le pilote SQLite. Il copie `wp-content/database/bsc-tv-integration.sqlite` dans un répertoire temporaire avant toute modification. Ne pas l’utiliser avec une base de production. Le fichier de configuration de la fixture peut fournir ses réglages WordPress habituels, sans accès API réel.

Les extensions PHP `pdo_sqlite`, `sqlite3` et `mbstring` sont utilisées par cette fixture. La sauvegarde SQLite exportée pour le navigateur reste dans `work/tv-schedule-qa`, hors du plugin et de l’archive de livraison.

Les tests de l’horloge couvrent les visiteurs successifs, les changements de programmes, les six tranches de nouveauté, l’alternance, les priorités manuelles et de direct, l’absence de basculements répétés, la reprise d’un replay, les délais, les futures publications, les premières et les longues inactivités. L’intégration vérifie les profils BSC BDD, les exclusions, les contrôles Elementor et les anciens widgets, la route REST et l’absence d’appels externes. Les tests JavaScript emploient des doubles des SDK officiels pour simuler blocage d’autoplay, refus de vidéo, changement d’avatar et passage YouTube/Twitch ; ils ne valident pas les publicités ou autorisations réelles de chaque compte.

Validation : les 44 contrôles WordPress/Elementor sauvegardent un document Elementor puis génèrent son CSS pour chaque combinaison d’effets, les couleurs et dimensions, et les réglages mobiles distincts. La version 1.5.3 vérifie également la couleur des coins enregistrée, le rognage de la vidéo et des filtres sur ordinateur et à 390 px, ainsi que le retour à des coins carrés lorsque le verre bombé est désactivé. La couleur d’exemple est rose, avec une courbure de 44 px sur ordinateur et 24 px sur mobile.

La vérification visuelle 1.5.3 utilise un aperçu des styles enregistrés avec le lecteur YouTube intégré directement ; elle ne reteste pas l’horloge ou le SDK de synchronisation.

Les 24 contrôles d’horloge et 20 contrôles du navigateur avec SDK simulés ont passé pour la version 1.5.2. Une lecture YouTube réelle avec les calques devant l’image a confirmé la transmission des clics, la pause et la reprise. La profondeur passe de 22 à 12 px et l’intensité de 55 à 25 % selon les réglages mobiles enregistrés. La désactivation rend les deux filtres transparents. Aucun cadre extérieur n’est ajouté.

La livraison initiale 1.5.0 a également vérifié les scénarios de grille, collecte quotidienne et découverte, ainsi qu’une véritable lecture YouTube dans deux onglets locaux rejoignant le même programme à des positions différentes selon l’heure d’arrivée. Les basculements vers de véritables comptes Twitch restent à valider en préproduction HTTPS. L’installation de test émet deux avertissements de dépréciation Elementor sous PHP 8.4, indépendants du nouveau lecteur.

Avant mise en service, tester les véritables sources YouTube et Twitch sur le domaine de préproduction HTTPS et vérifier les politiques de consentement et de cache du site. La production n’est pas modifiée par la livraison.

## Fichiers

Ajouts : `includes/player.php`, `includes/player-admin.php`, `includes/player-view.php`, `includes/player-widget.php`, `assets/player.js`, `assets/player.css`, `tests/player-clock.php`, `tests/player-browser.mjs`, `tests/player-integration.php`, `LIVE_PLAYER.md`.

Modifications : `bsc-tv.php` (version et branchements), `includes/admin.php` (section dans la page actuelle), `includes/sync.php` (métadonnées de vidéos déjà connues et réutilisation du passage quotidien), `README.md` (présentation du widget).

Version 1.6.0 : ajout de `includes/player-schedule.php` et `assets/player-admin.css` ; modifications de `includes/player.php`, `includes/player-admin.php`, `bsc-tv.php`, `tests/player-clock.php`, `tests/player-integration.php`, `README.md` et du présent guide.
