# BSC TV — Music Interludes

Version 1.15.0, branche `feature/music-interludes`. Module facultatif du plugin existant, **désactivé par défaut**, sans bibliothèque musicale préremplie. Aucune installation ni activation sur le site de production n’a été effectuée.

## Architecture réutilisée

Le lecteur repose sur `BSC_TV_Player::advance()` et l’option partagée `bsc_tv_player_clock`. Sa file `plan` est préparée par `BSC_TV_Player_Schedule`, sur 72 heures et 500 créneaux au maximum, sous le verrou existant. Les choix automatiques portent `fixed: false` ; les modifications éditoriales et les anciennes entrées sans marqueur sont protégées comme horaires fixés. Un vrai direct confirmé utilise déjà une antenne prioritaire et suspend la rotation.

Le module ajoute une référence musicale dans cette même file. Un créneau musical couvre l’entrée, le clip et la sortie. Les trois phases publiques sont dérivées de ses timestamps sauvegardés : aucun tirage ni horaire indépendant par visiteur. La route REST existante, le widget Elementor LIVE 24/7, la liste quotidienne et la grille d’administration restent utilisés. Les chaînes, émissions et fiches BSC BDD ne sont pas recréées.

## Mise en service

1. Installer le ZIP 1.15.0 sur une installation de test et conserver une sauvegarde de la base et du plugin précédent.
2. Ouvrir **BSC TV → Music Interludes**. Le lecteur général doit aussi être activé dans **BSC TV → Le live**.
3. Ajouter manuellement des clips depuis leurs URL YouTube officielles. Compléter artiste, titre, durée exacte en secondes, catégories, origine US/France si pertinente et chaîne d’origine.
4. Si une clé YouTube est configurée dans les réglages existants, cocher **Récupérer les métadonnées**. À défaut, vérifier manuellement disponibilité et durée. Une vidéo officiellement signalée comme bloquée ne peut pas être débloquée avec la case manuelle.
5. Vérifier la source officielle, renseigner la référence ou note d’autorisation, choisir **Autorisations confirmées**, cocher la confirmation explicite et activer le clip. L’intégration YouTube disponible ne constitue pas cette autorisation. Un changement de vidéo exige une nouvelle confirmation.
6. Configurer les horaires, l’identité et les politiques, puis activer le module. Prévoir au moins deux artistes différents pour maintenir l’alternance ; les règles peuvent volontairement laisser passer plusieurs émissions sans clip.
7. Par défaut, seuls les espaces libres assez longs sont utilisés. Pour une antenne qui enchaîne uniquement des choix automatiques, activer **Autoriser l’insertion dans une rotation entièrement automatique sans horaire fixé**. Cette option reste désactivée par défaut.
8. Vérifier **BSC TV → Le live** et l’état musical. Activer la vérification habituelle des véritables directs dans les réglages de synchronisation pour permettre leur détection. La tâche Infomaniak quotidienne existante est conservée.

## Paramètres ajoutés

| Réglage | Défaut / comportement |
| --- | --- |
| Activation générale | Désactivée ; bouton distinct d’arrêt des futurs interludes |
| Seuil de durée | 40 minutes ; une émission doit être **strictement plus longue** que le seuil |
| Insertion flexible | Désactivée ; autorisée uniquement dans une file sans aucun horaire fixé |
| Délai de rediffusion d’un clip | 6 heures, réglable entre 0 et 720 heures |
| Désactivation en cours | Laisser terminer l’interlude ; interruption immédiate possible |
| Arrivée d’un vrai direct | Interrompre la musique ; option explicite pour terminer d’abord l’interlude |
| Entrée / sortie | 3 secondes chacune, réglables entre 1 et 30 secondes |
| Logo | Aucun ; média image officiel choisi par l’administrateur |
| Animations | CSS légère : apparition, glissement ou sans mouvement ; vidéo de médiathèque facultative pour chaque phase |
| Identité | Fond, texte, couleurs, textes d’entrée/sortie et son des fichiers d’animation |
| Elementor | Badge MUSIC BREAK, affichage titre/artiste, fond et texte des transitions, couleur du badge, dimension du logo, typographie |

Les paramètres se trouvent dans l’option non autoloadée `bsc_tv_music_settings`. Les noms des catégories, leurs heures et les catégories jouées par chaque tranche sont modifiables. Les chevauchements actifs sont refusés ; une tranche désactivée peut laisser une période sans musique. La nuit peut réutiliser une autre catégorie.

| Horaire de Paris | Catégorie initiale | Orientation éditoriale |
| --- | --- | --- |
| 06:00–10:00 | BSC Morning Chill | Downtempo, électronique douce, trip-hop : Moby, Air, Morcheeba, Massive Attack, Portishead, Zero 7 |
| 10:00–18:00 | BSC Hip-Hop Classics | Rap américain et français des années 80/90 : Run-DMC, Public Enemy, Rakim, De La Soul, A Tribe Called Quest, Beastie Boys, Dr. Dre, Snoop Dogg, 2Pac, The Notorious B.I.G., Wu-Tang Clan, Nas, Gang Starr, IAM, NTM, MC Solaar, Assassin, Ministère A.M.E.R., Alliance Ethnik |
| 18:00–00:00 | BSC MTV Legends | Pop, rock, funk, soul, R&B : Michael Jackson, Prince, Queen, Madonna, George Michael, Phil Collins, Tears for Fears, Depeche Mode, Whitney Houston, INXS, U2, Dire Straits |
| 00:00–06:00 | BSC Night Sessions | Trip-hop, downtempo, soul et électronique calme |

Ces références ne sont pas des clips ajoutés ou validés. L’administrateur choisit les URL et confirme les autorisations pour chaque vidéo. La bibliothèque permet ajout, modification, corbeille, activation, désactivation, catégories multiples, vérification des métadonnées et prévisualisation sur YouTube. Elle est limitée à 500 références, affichées par pages de 25 ; une vidéo ne doit pas être dupliquée pour changer de catégorie.

## Moteur et protection de la programmation

- Après 2, 15, 35 ou 40 minutes, aucun interlude n’est sélectionné avec le réglage initial. Après 41 ou 90 minutes, il devient éligible si les autres conditions sont remplies.
- Le créneau doit contenir **entrée + clip intégral + sortie**. Un manque d’une seconde suffit à refuser l’insertion ; le clip n’est pas raccourci.
- En mode normal, l’horaire du programme suivant reste identique. Le mode flexible peut repousser une file entièrement automatique du temps du break ; la présence d’un seul programme fixé, même plus loin dans la file, interdit ce déplacement. Cette règle volontairement conservatrice protège aussi les anciennes grilles.
- Les annonces de directs déjà connues dans les calendriers de chaînes constituent des limites supplémentaires : un nouvel interlude ne doit pas traverser leur heure de début. Un direct confirmé empêche le démarrage d’un nouvel interlude et ne sera jamais remplacé par la musique.
- Par défaut, l’arrivée d’un direct interrompt l’interlude ; celui-ci n’est pas repris après le direct. Le choix **Terminer** peut retarder l’accès au direct de la durée restante du clip et de la sortie. La priorité aux directs s’applique lorsque la musique est active, même si la priorité facultative de la rotation normale était désactivée ; leur détection existante doit être opérationnelle.
- Le choix est effectué côté serveur : clips actifs, source officielle et autorisations confirmées avec note, disponibilité connue, durée positive de 3 600 secondes au maximum et catégorie de Paris correspondant au **début de l’interlude**.
- Les artistes consécutifs identiques sont exclus. Le clip diffusé le moins récemment est préféré, avec alternance US/France pour la catégorie hip-hop quand des candidats existent. Le délai du même clip reste prioritaire. Aucun candidat compatible signifie aucune musique.
- Les compteurs sont conservés dans l’horloge commune : dernier passage et nombre de diffusions. L’entrée seule ne compte pas comme clip diffusé. Les projections futures ne modifient pas les compteurs réels.
- Une révocation de droits, une désactivation du clip ou une indisponibilité confirmée retire la référence. Le retrait d’une source courante interrompt son interlude ; l’option « terminer à la désactivation » ne permet pas de continuer une source retirée.

Les interventions musicales utilisent le même verrou que l’antenne. En cas d’interruption, seuls les programmes automatiques avant le prochain horaire fixé peuvent utiliser le temps libéré ; les horaires fixés sont conservés. Le bouton Passer de la page Le live applique également cette règle aux interludes. Les clips ne passent pas par la file manuelle des émissions.

## Animations et lecteur

Un visiteur arrivant pendant l’entrée ou la sortie rejoint la phase correspondant à l’horloge serveur. Le logo CSS utilise un délai négatif correspondant à la portion déjà écoulée. Un fichier d’animation se positionne à l’offset de la phase lorsque le navigateur le permet ; il est distinct du clip YouTube. Sans logo, le fond et le texte facultatif forment une transition neutre. Le module ne fabrique pas de logo.

Avant une identité, l’iframe précédente est détruite. Avant le clip, l’écran d’identité est retiré. Les identités musicales ne sont pas superposées à YouTube et ne remplacent pas une publicité par un faux écran. L’ancienne animation générale de chargement n’est pas relancée sur les phases musicales. Les erreurs tardives d’un lecteur précédent ne peuvent pas changer la nouvelle phase. La préférence de réduction des animations utilise l’identité statique.

Le clip utilise le lecteur officiel existant avec son offset partagé. Le badge **MUSIC BREAK** est distinct de LIVE et REDIFFUSION ; aucun profil ou avatar BSC BDD n’est inventé pour l’artiste. Le logo fixe de la chaîne TV et les associations des émissions habituelles conservent leur fonctionnement. Les styles Elementor du lecteur existant restent disponibles.

## Métadonnées et performances

Les clips sont des enregistrements privés `bsc_tv_music_clip`, avec le champ `_bsc_tv_music`. Ils contiennent uniquement des références et informations éditoriales, jamais des fichiers de musique. Le cache de bibliothèque dure une minute et est invalidé lors des changements administratifs ; sa révision est stockée dans `bsc_tv_music_revision`.

La clé YouTube existante est réutilisée uniquement sur demande administrative et pendant le passage Infomaniak déjà authentifié et limité à un lancement par jour. Le module vérifie jusqu’à **50 références, les moins récemment contrôlées**, dans ce passage. Une bibliothèque de plus de 50 clips est donc vérifiée progressivement. Aucun nouvel événement WP-Cron n’est ajouté. Le bilan technique est enregistré dans `bsc_tv_music_metadata_report`.

Les API peuvent fournir durée, miniature, chaîne d’origine et disponibilité d’intégration. Les restrictions d’âge et les restrictions régionales connues pour la France excluent la musique. Un échec de requête conserve une restriction déjà connue et ne valide jamais les droits. Aucune recherche Internet ni collecte de nouveaux clips n’est lancée automatiquement. [Ressource officielle YouTube videos](https://developers.google.com/youtube/v3/docs/videos).

La route REST du lecteur n’appelle pas YouTube. Elle expose les métadonnées publiques et les timestamps de la phase, sans notes d’autorisation ni clé privée. Les requêtes continuent sur le rythme existant, en se rapprochant des limites de phase ; une phase de trois secondes ne subit pas l’ancien minimum de cinq secondes. Le contrôle local se fonde sur l’heure serveur reçue et ne recharge pas la page.

Module désactivé sans break en cours : aucun accès à la bibliothèque ni vérification musicale d’API. La signature de la grille normale reste identique à celle de la version précédente pour éviter de mélanger les émissions à la simple installation.

## Vérifications effectuées

**580 contrôles locaux** : les 453 contrôles existants, 106 vérifications WordPress/programmation musicale et 21 scénarios de phases dans le navigateur simulé. Les tests utilisent une copie SQLite jetable de WordPress, Elementor et BSC BDD. API et SDK sont simulés. Les scénarios couvrent les 25 points du cahier des charges : limites de durée, activation, tranches, trois phases et retours, arrivées tardives, visiteurs multiples, absence de validation, indisponibilité, manque d’espace, directs, désactivation, Elementor, grilles et changements d’heure. Les formulaires réels sont exercés avec capacités et nonces WordPress ; leur traitement, la corbeille et le verrou commun sont contrôlés.

```sh
php tests/music-integration.php /chemin/wordpress-de-test
node tests/music-browser.mjs
php tests/player-clock.php
php tests/player-integration.php /chemin/wordpress-de-test
php tests/player-import.php /chemin/wordpress-de-test
php tests/day-integration.php /chemin/wordpress-de-test
php tests/badge-integration.php /chemin/wordpress-de-test
node tests/player-browser.mjs
node tests/player-autoplay.mjs
node tests/player-linear.mjs
node tests/day-browser.mjs
node tests/badge-browser.mjs
```

L’aperçu local de l’entrée, du clip, de la sortie et de l’émission suivante ainsi que l’administration ont été vérifiés à 1 200 et 390 pixels, sans débordement horizontal. Les tests ne constituent ni une validation des droits réels ni une preuve de lecture de chaque clip sur YouTube. Deux avertissements Elementor préexistants peuvent apparaître dans la fixture PHP 8.4.

## Limites techniques

La lecture, les publicités, les restrictions et le positionnement restent sous le contrôle de YouTube et du navigateur. Il peut exister un délai de chargement et de requête aux changements de phase ; la synchronisation n’est pas garantie à l’image près. Une erreur de lecture d’un visiteur ne coupe pas l’antenne de tous les autres. Le lien officiel reste disponible jusqu’au prochain créneau. [Exigences du lecteur intégré YouTube](https://developers.google.com/youtube/terms/required-minimum-functionality).

L’animation CSS est implémentée, avec vidéo de médiathèque facultative. Aucun moteur Lottie ni transcodage n’est ajouté. Une animation plus courte que sa phase peut conserver sa dernière image. Le navigateur peut refuser un son automatique ; une interaction utilisateur reste parfois nécessaire. La configuration d’Infomaniak et les autorisations réelles doivent être vérifiées sur l’installation cible.

## Retour arrière

1. Dans **Music Interludes**, choisir **Interrompre** pour la désactivation, enregistrer, puis utiliser **Désactiver immédiatement les futurs interludes**. Vérifier qu’aucune musique ne reste à l’antenne ou dans la file.
2. Sauvegarder le plugin et la base. Si nécessaire, réinstaller le ZIP précédent 1.14.1. Les créateurs BSC BDD, chaînes et émissions restent conservés.
3. Les références musicales privées et leurs réglages sont conservés en base pour une réactivation ultérieure ; la version précédente ne les utilise pas. Ne supprimer ces données qu’après une sauvegarde et une décision explicite.

## Fichiers concernés

Ajouts : `includes/music.php`, `includes/music-admin.php`, `assets/music-admin.js`, `assets/music-admin.css`, les deux tests musicaux et ce document.

Adaptations : `bsc-tv.php`, `includes/player.php`, `includes/player-schedule.php`, `includes/player-daily.php`, `includes/player-admin.php`, `includes/player-view.php`, `includes/player-widget.php`, `includes/sync.php`, `assets/player.js`, `assets/player.css`, `README.md` et `LIVE_PLAYER.md`.
