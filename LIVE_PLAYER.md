# BSC TV — Le live 24/7

Version 1.17.0, branche `feature/music-programme-grid`. La musique est choisie aléatoirement dans l’antenne commune, avec recalcul des horaires automatiques incluant les animations BSC. Les morceaux à venir apparaissent dans le widget de la journée. [Diagnostic, programmation et configuration musicale](MUSIC_INTERLUDES.md). Les clips doivent rester validés individuellement. Le ZIP doit être installé sur WordPress pour activer cette version.

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

Dans l’onglet **Style**, les sections **Boutons · style commun**, **Bouton Lecture / Pause**, **Bouton Son** et **Bouton Plein écran** règlent séparément les couleurs de l’icône et du fond, le type, la couleur et l’épaisseur du contour, les arrondis, les ombres, les marges intérieures, la taille du bouton et celle de l’icône. Les onglets **Normal**, **Survol et focus**, **Actif** et **Désactivé / chargement** permettent de différencier les états. Les réglages d’un bouton particulier complètent le style commun. Le repère du focus clavier possède sa propre couleur.

**Barre de commandes et volume** personnalise le fond et les contours de la barre, ses marges, ses espacements, son alignement et le curseur de volume. **Lien vers la plateforme** propose ses propres couleurs, contours, arrondis et états ; sa typographie se règle dans **Lecteur et habillage**. **Logo de la plateforme** règle la taille et les couleurs YouTube/Twitch sans effacer le triangle blanc de YouTube. **Cadre de l’écran**, **Textes et progression**, ainsi que les bordures du widget et des badges complètent l’habillage. Les champs laissés vides conservent le style habituel ; les commandes internes aux lecteurs officiels restent gérées par les plateformes.

- Contenu : titre, commandes, logo fixe, image de l’émission avant lecture, avatar et lien BSC BDD, créateur, émission, programme suivant, horaires, progression et badges.
- Lecture : **démarrage automatique et son activé par défaut**. Dans **Contenu → Démarrage de la vidéo**, choisir **Automatique quand le lecteur est visible** ou **Après un clic sur Lecture**. Les anciens widgets dont le réglage de démarrage était vide adoptent le nouveau défaut automatique. La lecture est tentée lorsqu’au moins la moitié du widget est visible, dans un onglet actif ; un seul lecteur par page démarre automatiquement. Un lecteur ouvert dans un onglet masqué peut démarrer lorsque l’onglet devient actif. Un clic manuel réserve aussi cette priorité pour éviter qu’un second widget ajoute une autre piste sonore.
- **Démarrer sans son** permet toujours une lecture silencieuse. Un widget déjà sauvegardé avec cette option activée conserve son choix : désactivez-la et enregistrez la page pour activer son audio. YouTube reçoit explicitement `unMute()` et Twitch `muted: false` lorsque le son est actif. Les commandes YouTube de pause, son, volume et plein écran sont placées sous la vidéo ; Twitch garde ses commandes officielles. Un navigateur peut exiger un clic pour autoriser la lecture avec le son. Le bouton Lecture reste alors disponible, sans désactiver silencieusement l’audio demandé. [Événement YouTube de lecture automatique bloquée](https://developers.google.com/youtube/iframe_api_reference#onAutoplayBlocked).
- Le bouton de démarrage séparé a été supprimé. L’icône **Lecture/Pause** de la barre permet aussi de démarrer manuellement, de reprendre une lecture bloquée ou de réessayer après une erreur. Avant le chargement de la vidéo, les commandes audio restent masquées. Aucun message demandant de cliquer sur l’ancien bouton n’est affiché lorsque la lecture automatique est bloquée. Le raccourci `[bsc_tv_player start_mode="click"]` force le démarrage manuel ; une demande explicite `autoplay="no"` reste également reconnue. Twitch conserve ses commandes officielles une fois chargé.
- **Image de l’émission avant lecture**, activée par défaut : l’aperçu est présent dès le HTML initial, puis suit le programme courant lors des rafraîchissements. Il reprend l’image mise en avant de l’émission, son visuel enregistré ou le visuel du vrai direct ; à défaut, le logo de la chaîne ou l’avatar BSC BDD. Sans image disponible, l’écran conserve son fond. Une image qui échoue au chargement est masquée. Le lecteur officiel apparaît au-dessus dès sa création ; ses commandes restent accessibles. L’aperçu ne charge aucun SDK ni iframe. Dans **Style → Cadrage de l’image avant lecture**, choisir **Remplir l’écran** ou **Afficher l’image entière**.
- **Contenu → Animation entre les programmes**, activée par défaut : l’animation Brewers Social Club fournie est incluse dans le plugin. Elle démarre après la demande de lecture, à la fin d’une émission et lors du changement de programme, puis boucle sans son pendant le chargement. Elle s’arrête à l’événement de lecture effective **PLAYING** de YouTube ou Twitch, et non au simple événement **READY**. Sa durée de dix secondes n’ajoute aucun créneau dans la programmation commune : elle peut s’arrêter avant la fin de sa boucle. **Animation personnalisée** permet de choisir une autre vidéo dans la médiathèque ; **Style → Cadrage de l’animation** règle son remplissage.
- La transition s’efface immédiatement si la plateforme bloque la lecture automatique, signale une erreur ou si l’animation est indisponible. Les clics atteignent les commandes du lecteur officiel et l’icône Lecture/Pause reste en dehors de l’animation. Une attente maximale de **20 secondes**, réglable de 5 à 60 secondes, rend le lecteur visible même sans confirmation de lecture. Les pauses, publicités et mises en mémoire tampon d’un programme déjà commencé ne relancent pas l’animation. Un changement de programme ne peut pas être validé par un événement tardif du lecteur précédent.
- La transition est suspendue dans un onglet masqué et arrêtée quand le widget est retiré ou l’antenne désactivée. La préférence système **Réduire les animations** affiche une image fixe sans lancer le MP4. Le fichier vidéo n’est pas chargé avant la première transition. L’import Infomaniak reste quotidien ; la transition n’ajoute aucun appel aux API des plateformes ni tâche WP-Cron.
- Style : format 16:9, couleurs, typographies, marges, espacements et arrondis. Le logo et l’avatar disposent de dimensions et positions par appareil.
- **Style → Écran bombé et CRT** : deux options indépendantes, désactivées par défaut. Reflets, ombres du verre et lignes de balayage sont placés devant l’image avec `pointer-events: none`. Relief, courbure, intensité, espacement et lueur sont personnalisables par appareil. L’effet bombé simule un verre, sans déformation géométrique de la vidéo.
- **Couleur à l’extérieur des arrondis** : le lecteur et les filtres sont rognés dans les coins selon la courbure ; la couleur choisie remplit les parties extérieures. Les grandes courbures peuvent rogner les éléments proches des coins.
- Logo, avatar et badges restent hors de l’image du lecteur officiel. L’avatar et le lien sont ceux de la fiche publique BSC BDD existante.

## Widget Programme du jour en liste

Lorsque la chaîne et le créateur portent le même nom, la liste conserve uniquement le nom cliquable vers BSC BDD. Sans lien de fiche activé ou disponible, le nom reste visible une seule fois. Les chaînes et créateurs dont les noms diffèrent conservent leurs lignes respectives. Cette règle s’applique aussi aux programmes chargés par **Afficher plus**.

Dans Elementor, ajouter **BSC TV — Programme du jour (liste)** à la page souhaitée. Ce widget affiche par défaut le programme en cours et les **cinq prochaines diffusions de la journée** de l’antenne 24/7 gérée dans **BSC TV → Le live**. Le bouton **Afficher plus** charge les cinq suivantes en AJAX. Il disparaît lorsqu’aucun autre programme n’est disponible aujourd’hui. Le nombre de programmes par chargement est personnalisable de 1 à 50 ; le programme courant reste visible en plus de cette limite. Les programmes déjà diffusés peuvent être réactivés dans les réglages et participent alors aux lots affichés dans l’ordre chronologique.

Le rafraîchissement conserve le nombre de lots ouverts, reprend une liste à cinq programmes lors du changement de journée et recalcule les créneaux depuis la grille commune. Il remplace la liste cumulative pour éviter les doublons après un changement d’horaire. Un double clic ne lance pas deux chargements ; une erreur conserve les lignes déjà visibles et permet de réessayer. Il utilise les mêmes horaires et la même grille enregistrée que le lecteur. Une visite ne déclenche pas de recherche de nouvelles vidéos.

Chaque ligne peut afficher les heures, le visuel de l’émission, le titre, la chaîne, le créateur et sa fiche BSC BDD. Le visuel reprend l’image mise en avant de l’émission ou son URL de visuel, puis le logo de chaîne ou l’avatar BSC BDD à défaut. Un pictogramme apparaît si aucune image n’est disponible. Les sources retirées, privées et les brouillons non approuvés sont exclus du rendu public, y compris de l’historique.

- **Contenu** : nombre de programmes par chargement, texte du bouton, titre, date, horaires, images, chaîne, créateur, lien BSC BDD, badges, programmes passés et libellés personnalisés. Le titre de chaque émission peut mener à la plateforme d’origine, à la page du live choisie ou rester sans lien.
- **Style** : fonds, couleurs du programme courant et du point LIVE, séparateurs, typographies, bordure et ombre. **Lignes des programmes** règle les contours et ombres des lignes, leurs couleurs au survol et le cadre du programme courant. **Vignettes et icône de remplacement** règle le fond, la couleur et la taille de l’icône sans image, le cadrage, les arrondis, contours et ombres. **Textes et liens** sépare les couleurs des titres, chaînes, créateurs, liens BSC BDD et heures de début/fin. **Badges et point LIVE** règle leurs couleurs, fonds, contours, marges et arrondis.
- **Bouton Afficher plus** possède ses couleurs de texte et de fond, contours, ombre, arrondis et marges intérieures, avec les états **Normal**, **Survol et focus**, **Actif** et **Désactivé / chargement**. Sa position, sa largeur et son espacement se règlent dans **Position du bouton Afficher plus** ; sa typographie reste dans **Typographies**. Les dimensions et espacements portant l’icône d’appareil se règlent indépendamment sur ordinateur, tablette et mobile. Les programmes chargés en AJAX héritent automatiquement des styles du widget.
- Un replay courant porte **En cours** ; un vrai direct confirmé porte un point rouge et **LIVE**. Les créneaux après un direct sont signalés comme prévisionnels.

La journée suit le fuseau WordPress, de minuit à minuit, y compris lors du changement d’heure. Un programme commencé la veille apparaît comme une suite. L’historique est celui enregistré depuis la version 1.9.0 ; les diffusions antérieures ne sont pas inventées. Si l’antenne est désactivée ou la grille vide, un message personnalisable apparaît.

La liste s’actualise toutes les 60 secondes depuis `GET /wp-json/bsc-tv/v1/day`, avec un délai plus court à l’approche des transitions et du changement de journée. Le rafraîchissement est suspendu lorsque l’onglet est masqué ou le widget éloigné de la zone visible. Il conserve l’affichage en cas d’erreur et préserve le lien ayant le focus clavier. Ce widget charge ses propres petits fichiers CSS et JavaScript ; il ne charge aucun lecteur ou SDK vidéo et n’ajoute aucune tâche d’import.

Sans Elementor, utiliser `[bsc_tv_day]`, ou par exemple `[bsc_tv_day title="La journée sur BSC TV" show_past="no" show_images="yes" link_mode="watch" watch_url="https://votre-site.fr/le-live/"]`.

## Vidéos soumises à une limite d’âge

Les lecteurs officiels conservent leurs restrictions. L’indicateur YouTube `contentDetails.contentRating.ytRating = ytAgeRestricted` est lu dans les réponses `videos.list` déjà utilisées par le plugin. Une vidéo signalée est écartée des nouveaux imports de replays et du catalogue du lecteur, y compris si son mode est **Inclure**. Un vrai direct signalé ne prend pas la priorité à l’antenne. Les fiches d’émission, chaînes, liens BSC BDD et annonces restent conservés.

Lorsqu’une collecte relit une vidéo déjà connue, elle mémorise la restriction avant de filtrer les résultats de l’import. La vidéo sort alors du catalogue, du programme courant et de la file à la prochaine actualisation de l’horloge. Les sources déjà présentes avant cette version nécessitent cette relecture pour connaître leur restriction ; aucune recherche supplémentaire n’est déclenchée par un visiteur. L’import historique reste limité au passage quotidien Infomaniak et à sa fenêtre habituelle de deux mois. Les vidéos anciennes hors de cette fenêtre et les URL de remplacement ne sont vérifiées automatiquement que lorsqu’une collecte existante demande leurs métadonnées.

Dans **Émission → Diffusion BSC TV 24/7**, la case **Exclure cette vidéo du lecteur : limite d’âge ou lecture bloquée** permet de retirer immédiatement une source connue comme bloquée, notamment pour Twitch dont l’API VOD ne fournit pas cet indicateur. Cette exclusion suit l’identifiant de la vidéo choisie et peut être décochée après vérification. Une restriction officielle reste prioritaire même si la case est décochée ; elle est levée après une nouvelle réponse complète de YouTube indiquant sa suppression. Une réponse incomplète ou une erreur réseau conserve une restriction déjà confirmée. Le changement vers une autre URL ne lui transmet pas la restriction de l’ancienne vidéo.

Les erreurs du lecteur d’un visiteur ne modifient pas la programmation globale : elles peuvent provenir de sa connexion, de sa région ou de son navigateur. Le lien vers la plateforme reste proposé. Ce filtre ne garantit pas la lecture de toutes les vidéos dans tous les navigateurs.

Références : [indicateur d’âge YouTube](https://developers.google.com/youtube/data/reference/rest/v3/videos#contentDetails.contentRating.ytRating), [restrictions des vidéos intégrées](https://support.google.com/youtube/answer/171780), [champs de l’API des vidéos Twitch](https://dev.twitch.tv/docs/api/reference/videos).

## Pastille LIVE liée à l’antenne

Le widget existant **BSC TV – Pastille Live** lit la même horloge locale que le lecteur de **Le live**. Lorsqu’un programme est à l’antenne, son titre et son horaire apparaissent dans le bouton à côté du point rouge **LIVE**. Ce libellé indique la diffusion actuelle sur BSC TV ; la carte dans le déroulant distingue explicitement **DIRECT** et **REDIFFUSION**. Elle reprend le visuel et le lien vers le créateur BSC BDD. Un véritable direct déjà diffusé dans l’antenne est retiré de la section des autres directs pour éviter un doublon.

Le libellé **LIVE** utilise par défaut le rouge du point et une graisse de 900. Le point clignote toutes les 1,4 seconde ; le texte reste fixe. **Animer le point du direct** est activé par défaut dans Elementor et se désactive au besoin. Un widget qui avait explicitement enregistré cette option désactivée conserve son choix. Le raccourci `pulse="no"` permet aussi de le désactiver. La préférence système de réduction des animations laisse le point fixe. Les couleurs et typographies personnalisées dans Elementor restent prioritaires.

Le bouton **Regarder Le live**, ainsi que le titre de cette carte, mènent par défaut à `https://brewerssocialclub.com/le-live/`. Dans Elementor → **Bouton et ouverture**, régler **Afficher l’émission BSC TV en cours**, **Bouton pour regarder Le live**, **Lien vers la page Le live** et **Texte du bouton Le live**. Sa typographie se règle dans **Style → Bouton et pastille**. Le lien vers l’agenda complet reste disponible séparément. Le filtre de plateforme porte sur les annonces ; l’émission BSC TV représente l’antenne commune.

Lorsque l’antenne est désactivée, attend un créneau ou ne possède aucun programme utilisable, la pastille reprend les directs et annonces des plateformes. Désactiver **Afficher l’émission BSC TV en cours** conserve également ce fonctionnement. Une source retirée ne peut pas laisser son ancien titre dans la pastille. Le raccourci `[bsc_tv format="badge" watch_label="Regarder la chaîne"]` propose la même fonction ; `show_player_current="no"` désactive la priorité à l’antenne et `show_watch_link="no"` masque le bouton supplémentaire.

Le rafraîchissement utilise la route locale publique du programme avec `format=badge`, sans chargement de vidéo, SDK, clé privée ou nouvel import. Il passe généralement chaque minute et se rapproche des changements d’émission, avec un délai minimal de cinq secondes. Plusieurs pastilles de même configuration partagent une requête. Un échec conserve l’affichage précédent ; l’onglet masqué suspend les requêtes. L’actualisation garde le menu ouvert, le bouton Regarder et le lien qui a le focus clavier. Le comportement du survol, du clic extérieur et d’Échap est conservé.

## Lecture à l’heure de l’antenne

Le comportement s’applique aux widgets existants dès la mise à jour, sans réglage Elementor à réenregistrer. Sur YouTube, les paramètres officiels `controls: 0` et `disablekb: 1` retirent la barre de navigation et les raccourcis clavier. Des boutons à icônes placés sous le lecteur permettent de mettre en pause, reprendre le direct, couper le son et passer en plein écran lorsque le navigateur le permet ; le curseur de volume reste disponible. Leurs noms accessibles et infobulles suivent l’action disponible, et leurs icônes changent selon la lecture, le son et le plein écran. La préférence audio choisie reste conservée entre les émissions YouTube sur ce widget. [Paramètres YouTube](https://developers.google.com/youtube/player_parameters).

Les commandes utilisent des icônes SVG épaisses : pause pleine, triangle de lecture, haut-parleur et flèches de plein écran. Le lien **Regarder sur la plateforme et soutenir le créateur**, accompagné du logo YouTube ou Twitch du programme courant, suit immédiatement le bouton plein écran dans la même barre. Il ouvre la source dans un nouvel onglet et reste disponible avant le démarrage ou en cas de lecture bloquée. Le plein écran du widget est également accessible avec Twitch lorsque le navigateur le permet ; ses commandes audio et de lecture restent celles du lecteur officiel. Sur petit écran, les groupes se répartissent sur plusieurs lignes en gardant le lien à droite du plein écran.

Sur les rediffusions, une reprise ou un déplacement signalé par Twitch rejoint la position calculée depuis l’horaire serveur. Le lecteur vérifie aussi localement sa position chaque seconde lorsqu’il joue. Les déplacements supérieurs à trois secondes sont corrigés ; cette marge évite les allers-retours dus aux images clés. Les corrections sont espacées d’au moins deux secondes, y compris quand un appel `seek()` déclenche lui-même un événement Twitch `SEEK`. Un déplacement pendant une pause est corrigé sans forcer la lecture ; une pause ordinaire ne déclenche pas de repositionnement continu. Une tentative d’aller directement à la fin ne fait pas passer toute l’antenne au programme suivant. La reprise rejoint le programme diffusé à ce moment, sans reprendre le retard de la pause.

Twitch ne fournit pas de paramètre documenté permettant de supprimer uniquement la navigation d’un replay : son interface reste visible et le repositionnement est corrigé par l’API officielle. Il peut subsister un déplacement visible pendant environ une à deux secondes, davantage si le lecteur refuse ou retarde le positionnement. Cela ne constitue pas un verrou de sécurité : les publicités, le chargement, le navigateur et les fonctionnalités natives de la plateforme restent sous leur contrôle. Le lien vers la plateforme ouvre son expérience de lecture habituelle. [API et événement SEEK Twitch](https://dev.twitch.tv/docs/embed/video-and-clips/).

Les vrais directs ne reçoivent aucun positionnement de replay ni changement de vitesse. Les corrections se font dans le navigateur, sans nouvel import, tâche WP-Cron ni appel aux API de collecte. Aucun déplacement individuel ne modifie l’horaire partagé. Les erreurs et événements tardifs d’un ancien lecteur ne changent pas l’émission suivante.

## Fonctionnement et limites

Les replays restent sur leurs plateformes : aucun téléchargement, stockage, réencodage ou proxy vidéo. Les références et métadonnées utilisent les émissions et chaînes BSC TV existantes. Le catalogue local examine au plus les 1 000 émissions publiées ou archivées les plus récemment modifiées et reste en cache une minute, avec invalidation à la modification des sources.

L’option non autoloadée `bsc_tv_player_clock` contient l’antenne, la file `plan`, les diffusions récentes `aired`, l’historique et la graine du mélange. L’import conserve ses curseurs dans `bsc_tv_player_replay_import` et son passage quotidien dans `bsc_tv_player_import_daily`. Les traitements relisent l’état sous verrou. Après plus de 200 transitions pendant une longue inactivité, le rattrapage est borné.

La route publique `GET /wp-json/bsc-tv/v1/player` renvoie l’émission en cours et seulement les cinq suivantes, sans réglage privé ni appel externe. Son rafraîchissement est généralement de 60 secondes et se rapproche d’une transition. Les SDK externes sont chargés lorsque le lecteur visible tente le démarrage automatique ou lors du clic en mode manuel.

Les plateformes peuvent bloquer ou retarder une intégration selon le compte, la région, la publicité, l’autoplay ou la disponibilité de la vidéo. La synchronisation reste celle permise par leurs lecteurs officiels. Un direct spontané non découvert ne peut pas prendre la priorité ; une confirmation de direct expire selon la durée existante de vingt minutes. Une erreur de lecture conserve un lien vers la source et ne permet pas à un visiteur de changer l’antenne globale.

L’intégration Twitch nécessite HTTPS en production et les bons domaines `parent`. Les filtres CSS ne suivent pas le plein écran interne des plateformes. Les règles d’intégration des plateformes restent applicables aux calques visuels demandés.

## Validation

Version 1.18.0 : **174 contrôles ciblés** : 91 WordPress/Elementor du lecteur, 42 de la liste du jour, 26 scénarios AJAX de la liste et 15 contrôles d’apparence utilisant le CSS réellement généré par Elementor (`php tests/widget-styles.php /chemin/wordpress-jetable`). **212 assertions dans un navigateur local** vérifient les couleurs et contours rendus, les icônes, les états de survol, focus clavier, actif et chargement, les réglages mobiles, la pagination AJAX et l’isolation entre widgets. Les styles ont été vérifiés à 1 280 et 390 pixels, avec les feuilles Elementor chargées avant et après celles du plugin ; YouTube est simulé et aucun appel de plateforme n’est effectué. Syntaxe PHP et diff vérifiés. Les aperçus de validation utilisent des couleurs de démonstration ; aucun style n’est appliqué au site en production.

Version 1.17.4 : **210 contrôles ciblés** : 91 WordPress/Elementor, 49 scénarios JavaScript du lecteur, 35 de synchronisation, 14 de démarrage automatique et 21 de phases musicales. Les scénarios utilisent désormais l’icône Lecture/Pause sans ancien bouton : démarrage manuel, reprise après blocage du navigateur, relance après erreur et entrée musicale. Dix aperçus locaux avec SDK simulés vérifient les commandes YouTube/Twitch, l’absence du bouton et du message, le démarrage manuel et la lecture bloquée sur ordinateur et mobile. Syntaxe PHP/JavaScript et diff vérifiés.

Version 1.17.3 : **208 contrôles ciblés** : 42 WordPress/Elementor de la liste du jour, dont cinq scénarios de doublon du créateur, 91 du lecteur, 49 scénarios JavaScript du lecteur et 26 de la liste AJAX. Le lien **Regarder sur la plateforme et soutenir le créateur** et ses logos ont été vérifiés dans six aperçus locaux YouTube/Twitch à 1 200, 390 et 320 pixels, sans débordement. Syntaxe PHP/JavaScript et diff vérifiés.

Version 1.17.2 : **105 contrôles ciblés réexécutés** : 91 WordPress/Elementor du lecteur et 14 de démarrage automatique. L’assertion existante vérifie désormais l’absence de l’ancien triangle et le libellé de secours visible. Syntaxe PHP et diff vérifiés ; les six aperçus locaux YouTube/Twitch de la barre ont été contrôlés à nouveau après suppression de l’icône.

Version 1.17.1 : **210 contrôles ciblés réexécutés** : 91 WordPress/Elementor du lecteur, 49 du lecteur JavaScript, 35 de lecture synchronisée, 14 de démarrage automatique et 21 des phases musicales. Syntaxe PHP/JavaScript et diff vérifiés. Six aperçus locaux, avec SDK simulés, couvrent YouTube et Twitch à 1 200, 390 et 320 pixels : bon logo, lien après le plein écran, commandes accessibles et absence de débordement horizontal. Le lien Twitch reste accessible lorsque la largeur minimale de 400 pixels empêche l’intégration. Ces vérifications ne constituent pas un essai sur le site en production.

Version 1.17.0 : **695 contrôles locaux**, dont 46 pour les choix musicaux partagés, le recalcul des horaires protégés et le widget quotidien. [Détails, contrôle public du site et limites de validation](MUSIC_INTERLUDES.md).

Version 1.16.0 : **649 contrôles locaux**, dont 50 contrôles WordPress/API des playlists et 19 de leur interface AJAX, en complément des 580 contrôles de la version précédente.

Version 1.15.0 : **580 contrôles locaux**, dont 106 WordPress/programmation des interludes et 21 scénarios JavaScript de leurs phases. La suite antérieure complète de 453 contrôles est également exécutée.

Version 1.14.1 : **189 contrôles ciblés réexécutés** (91 WordPress/Elementor du lecteur, 49 du lecteur JavaScript, 14 de lecture automatique, 35 de lecture synchronisée). Syntaxe PHP/JavaScript et diff vérifiés. Les icônes et la suppression de la phrase ont été contrôlées dans un aperçu local à 1 200 et 390 pixels, avec SDK simulé et sans débordement horizontal.

Version 1.14.0 : **453 contrôles locaux exécutés** : 73 d’horloge et programmation, 91 WordPress/Elementor du lecteur, 81 d’import, 49 du lecteur JavaScript, 14 de lecture automatique, 35 de lecture synchronisée, 37 + 26 de liste quotidienne et 25 + 22 de pastille. La syntaxe PHP/JavaScript et le diff sont également vérifiés. Les nouveaux scénarios couvrent les déplacements dans les deux sens, les clics rapprochés, la pause/reprise, les changements de vitesse YouTube, le son, le plein écran, la fin anticipée, les refus temporaires du SDK et les événements récursifs Twitch. Un aperçu local avec SDK simulé a également été contrôlé à 1 200 et 390 pixels : commandes visibles et absence de débordement horizontal.

Les scénarios couvrent les trois jours calendaires, les changements d’heure, les programmes traversant minuit, l’historique du jour, la stabilité de la grille, la diversité malgré un catalogue déséquilibré, les durées différentes, les épisodes successifs et les interventions manuelles. L’import vérifie une exécution quotidienne authentifiée traitant plusieurs pages, les appels répétés sans travail API, les limites après interruption, la reprise du lendemain, les erreurs et verrous, les nonces, la rotation du secret et l’absence de tâches WP-Cron d’import.

```sh
php tests/player-clock.php
php tests/player-integration.php /chemin/vers/wordpress-de-test
php tests/player-import.php /chemin/vers/wordpress-de-test
node tests/player-browser.mjs
node tests/player-autoplay.mjs
node tests/player-linear.mjs
php tests/day-integration.php /chemin/vers/wordpress-de-test
node tests/day-browser.mjs
php tests/badge-integration.php /chemin/vers/wordpress-de-test
node tests/badge-browser.mjs
```

Les tests WordPress copient une base SQLite jetable avant toute écriture. Les API et SDK sont simulés : ils ne prouvent pas l’accès réel à chaque chaîne ni la configuration d’une tâche Infomaniak. La fixture émet deux avertissements Elementor préexistants sous PHP 8.4. La livraison doit être installée et la tâche quotidienne configurée dans le Manager pour être active sur le site.
