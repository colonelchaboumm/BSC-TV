# BSC TV — Music Interludes

Depuis **1.25.0**, la piste fournie **The Mountain · Soft Inspirational** est distribuée comme musique locale des animations BSC. Elle ne constitue pas un clip dans la bibliothèque YouTube, n’ajoute aucune durée aux créneaux et fonctionne aussi pendant le chargement et l’attente d’un direct lorsque les interludes musicaux sont désactivés. Les réglages sont dans **Identité visuelle** : activation, fichier audio personnalisé et volume. La musique boucle sans interruption entre les films d’une même attente, puis s’arrête lorsque le programme commence. Le son original du film est coupé lorsque cette piste est utilisée. Les prévisualisations d’entrée et de sortie utilisent aussi cette sélection.

Version 1.21.0, branche `fix/programme-diversity`. Module facultatif du plugin existant, **désactivé par défaut**, sans bibliothèque musicale préremplie. Les clips peuvent être ajoutés par URL ou importés depuis une playlist, puis validés individuellement ou en masse. La musique et les animations d’identité sont choisies côté serveur, avec grille recalculée et affichage dans le widget quotidien. La recomposition commune dans **Le live** active l’insertion flexible lorsqu’au moins un clip est prêt. Aucune installation ni activation sur le site de production n’a été effectuée pour cette version.

## Architecture réutilisée

Le lecteur repose sur `BSC_TV_Player::advance()` et l’option partagée `bsc_tv_player_clock`. Sa file `plan` est préparée par `BSC_TV_Player_Schedule`, sur 72 heures et 500 créneaux au maximum, sous le verrou existant. Les choix automatiques portent `fixed: false` ; les modifications éditoriales et les anciennes entrées sans marqueur sont protégées comme horaires fixés. Depuis 1.22.0, les directs sont prioritaires sur une grille à horaires fixes : ils ne décalent plus la rotation.

Le module ajoute une référence musicale dans cette même file. Un créneau musical couvre l’entrée, le clip et la sortie. Les trois phases publiques sont dérivées de ses timestamps sauvegardés : aucun tirage ni horaire indépendant par visiteur. La route REST existante, le widget Elementor LIVE 24/7, la liste quotidienne et la grille d’administration restent utilisés. Les chaînes, émissions et fiches BSC BDD ne sont pas recréées.

## Mise en service

1. Installer le ZIP 1.21.0 sur une installation de test et conserver une sauvegarde de la base et du plugin précédent.
2. Ouvrir **BSC TV → Music Interludes**. Le lecteur général doit aussi être activé dans **BSC TV → Le live**.
3. Ajouter des clips depuis leurs URL YouTube ou utiliser **Clips → Importer une playlist YouTube**. Compléter ou vérifier artiste, titre, durée exacte en secondes, catégories, origine US/France si pertinente et chaîne d’origine.
4. Si une clé YouTube est configurée dans les réglages existants, cocher **Récupérer les métadonnées**. À défaut, vérifier manuellement disponibilité et durée. Une vidéo officiellement signalée comme bloquée ne peut pas être débloquée avec la case manuelle.
5. Vérifier la source officielle, renseigner la référence ou note d’autorisation, choisir **Autorisations confirmées**, cocher la confirmation explicite et activer le clip, ou utiliser la **Validation groupée** ci-dessous pour un lot. L’intégration YouTube disponible ne constitue pas cette autorisation. Un changement de vidéo exige une nouvelle confirmation.
6. Configurer les horaires, l’identité et les politiques, puis activer le module. Prévoir au moins deux artistes différents pour maintenir l’alternance ; les règles peuvent volontairement laisser passer plusieurs émissions sans clip.
7. Par défaut, seuls les espaces libres assez longs sont utilisés. Pour insérer la musique dans une rotation automatique, utiliser **Programmer la musique aléatoire et recalculer la grille**. Cette action active les interludes et le mode flexible, renouvelle les choix futurs et réserve les durées complètes. Le programme en cours et les horaires fixés sont conservés. Elle ne valide aucun clip.
8. Vérifier **BSC TV → Le live** et l’état musical. Activer la vérification habituelle des véritables directs dans les réglages de synchronisation pour permettre leur détection. La tâche Infomaniak quotidienne existante est conservée.

## Validation en masse

Dans **BSC TV → Music Interludes → Validation groupée**, choisir les clips cochés sur la page ou **Toute la bibliothèque, toutes les pages**. La seconde option traite jusqu’aux 500 références de la bibliothèque, sans être limitée aux 25 lignes visibles. Le choix de catégorie limite l’action à cette catégorie ; il ne filtre pas l’affichage du tableau.

Pour **Valider les clips**, renseigner une référence ou note commune, confirmer les sources officielles et les autorisations pour le lot, puis cliquer sur **Appliquer**. **Activer aussi les clips validés** est coché par défaut et peut être désactivé pour valider sans mettre de nouveaux clips en service. Les confirmations sont saisies par l’administrateur ; le plugin n’infère aucune autorisation de la seule présence d’une vidéo sur YouTube. Les notes d’autorisations déjà confirmées sont conservées ; une note antérieure non confirmée est complétée par la nouvelle référence.

**Activer les clips déjà validés** ne confirme pas leurs autorisations ni leur source à leur place. **Désactiver les clips** conserve leurs références et notes. Les liens incohérents, vidéos bloquées ou non vérifiées, durées absentes ou supérieures à une heure, artistes/titres manquants et références sans catégorie restent ignorés pour la validation ou l’activation. Le bilan affiche les clips concernés, modifiés, déjà à jour, prêts à diffuser et ignorés, avec le motif des exclusions.

Les actions exigent un compte administrateur et un nonce WordPress ; elles utilisent le verrou de l’antenne et les contrôles d’éligibilité existants. Elles ne téléchargent pas de vidéo, ne lancent pas de nouvel appel YouTube et n’ajoutent pas de tâche d’import. La musique reste désactivée si son module l’était. Les contrôles de disponibilité et la récupération des métadonnées restent ceux déjà disponibles dans le plugin.

Validation locale de la version 1.19.0 : **163 contrôles ciblés** (38 de validation groupée, 106 du module et de sa programmation, 19 de l’import de playlists côté navigateur), plus **36 vérifications dans un navigateur local** à 1 280 et 390 pixels. Les scénarios couvrent les confirmations manquantes, les nonces, les accès non administrateurs, le verrou occupé, les notes privées, les doublons, les clips bloqués/incomplets, la sélection sur plusieurs pages, les catégories, la validation sans activation et les formulaires réels avec cases associées au lot. Aucune bibliothèque de production n’a été modifiée.

## Contrôle du site et diagnostic de la bibliothèque

Le 22 novembre 2026 vers 20 h 02 à Paris, les routes publiques de [l’antenne](https://brewerssocialclub.com/wp-json/bsc-tv/v1/player) et du [programme du jour](https://brewerssocialclub.com/wp-json/bsc-tv/v1/day?page_size=50&show_past=yes) ont répondu HTTP 200. Les ressources publiques indiquaient BSC TV 1.20.0. Le programme quotidien contenait 38 entrées : 32 The Craft Beer Channel et 6 Beer In Front, sans interlude musical. Ce relevé confirme une concentration sur deux sources ; il ne permet pas de connaître l’éligibilité des autres chaînes privées de l’administration. Il précède cette mise à jour et ne constitue pas une vérification après déploiement.

Un nombre élevé de clips prêts ne suffit pas à insérer de la musique si l’insertion utilise uniquement les espaces libres d’une grille contiguë. Une priorité manuelle obsolète pouvait également bloquer les insertions et ne le fait plus. Les clips incomplets restent exclus. Aucun accès à l’administration connectée n’a été utilisé pour cette version.

Après installation de la version 1.21.0, **Le live → Diversité et musique → Recomposer avec les chaînes et la musique** active l’insertion flexible des clips déjà prêts, conserve les tranches enregistrées et recalcule les émissions automatiques avec les durées complètes du clip et des animations. Le programme courant et les créneaux fixés restent protégés. Cette action ne change aucune validation, ne lance aucune recherche API et attend le prochain import quotidien pour enrichir les chaînes peu fournies. Le bouton de programmation existant dans Music Interludes reste disponible pour ne recomposer que la musique.

**Music Interludes → État actuel → Diagnostic de la programmation musicale** affiche désormais le total importé, les clips réellement prêts, les interludes planifiés et les éléments à compléter : activation, autorisations et référence, source officielle, disponibilité, durée, artiste/titre. Les raisons peuvent se cumuler. Le détail par catégorie et les durées d’entrée/sortie sont également visibles. Le nombre de références importées ne représente pas nécessairement le nombre de clips diffusables.

Après installation, vérifier le nombre **Prêt à diffuser**, puis utiliser le bouton de programmation. Le bilan annonce immédiatement combien de clips sont prêts et combien d’interludes ont été enregistrés. S’il reste zéro clip prêt, compléter les validations dans la bibliothèque. Les références non validées restent exclues.

## Importer une playlist dans une catégorie

Dans **BSC TV → Music Interludes → Clips** :

1. Coller l’URL, par exemple `https://www.youtube.com/playlist?list=PLvLT_VV4Sfx3mwWY9T3Zn5OLLhVo-f7Vo`.
2. Choisir **BSC Morning Chill** dans **Catégorie de destination**.
3. Cliquer sur **Importer les clips**. Les pages sont traitées successivement, avec un bilan et une barre de progression.
4. Ouvrir **Voir les clips importés**, puis vérifier et valider les références avant leur activation.

La clé YouTube configurée dans les réglages existants est nécessaire. Le plugin utilise l’API officielle `playlistItems.list`, jusqu’à 50 éléments par requête, puis récupère les métadonnées des vidéos en un lot. Les playlists doivent être accessibles via cette clé ; l’accès aux playlists privées avec connexion OAuth n’est pas ajouté. L’URL ci-dessus est un exemple de saisie : son contenu réel n’a pas été importé ni vérifié pendant les tests locaux. [Pagination officielle YouTube](https://developers.google.com/youtube/v3/docs/playlistItems/list).

Les nouvelles références reçoivent la catégorie, le lien YouTube, le titre, la chaîne propriétaire de la vidéo, la miniature et la durée lorsque l’API les fournit. Un titre sous la forme « Artiste - Titre » permet une suggestion d’artiste, à vérifier. L’année n’est pas déduite de la date de publication. Tous les nouveaux clips sont **inactifs, sans source officielle confirmée et en attente de validation des autorisations**. Un import ne démarre pas leur diffusion.

Une vidéo déjà présente n’est pas copiée : sa catégorie est ajoutée aux catégories existantes. Les titres, artistes, réglages d’activité, notes et validations éditoriales sont conservés. La disponibilité technique connue peut être actualisée ; une restriction nouvellement signalée reste bloquante. Les doublons dans plusieurs pages sont ignorés. Les références privées, supprimées ou absentes de la réponse complète de métadonnées sont comptabilisées comme indisponibles. En cas d’erreur de métadonnées, les nouveaux liens peuvent être conservés comme non vérifiés, sans durée exploitable ni autorisation de lecture.

L’import est ponctuel, lancé par un administrateur ; il n’ajoute pas de suivi quotidien des playlists. Le passage Infomaniak conserve son fonctionnement existant pour les métadonnées des références déjà importées. Aucun appel d’import n’est effectué par un visiteur du lecteur.

L’état de reprise est privé à chaque administrateur, dans un transient `bsc_tv_music_playlist_<user_id>` valable 24 heures. Une requête de reprise déjà traitée renvoie sa progression enregistrée, sans rejouer la page. Après une erreur ou un rechargement, le bouton **Reprendre l’import** continue le même travail. Après expiration, relancer la même URL évite les doublons. Les requêtes réseau n’immobilisent pas le verrou de l’antenne ; les modifications de bibliothèque utilisent ensuite ce verrou existant.

La limite de bibliothèque reste de 500 clips. Un import traite au plus 50 pages, soit 2 500 éléments, et annonce explicitement un arrêt partiel si une limite est atteinte. La progression indique séparément les créations, catégories ajoutées, doublons et indisponibilités.

## Paramètres ajoutés

| Réglage | Défaut / comportement |
| --- | --- |
| Activation générale | Désactivée ; bouton distinct d’arrêt des futurs interludes |
| Seuil de durée | **40 minutes ou plus** ; la valeur du seuil est incluse |
| Insertion flexible | Désactivée ; peut décaler le préfixe automatique si la place et les délais de rediffusion avant les horaires fixés le permettent |
| Délai de rediffusion d’un clip | 6 heures, réglable entre 0 et 720 heures |
| Désactivation en cours | Laisser terminer l’interlude ; interruption immédiate possible |
| Arrivée d’un vrai direct | Interrompre la musique ; option explicite pour terminer d’abord l’interlude |
| Entrée / sortie | Durée complète des vidéos choisies ; 11 secondes réservées pour Cirque et Lune. Sans durée vidéo connue : secours de 3 secondes, réglable de 1 à 30 |
| Logo | Aucun ; média image officiel choisi par l’administrateur |
| Animations | Animation Brewers Social Club déjà fournie dans le plugin, activée par défaut pour la musique ; les vidéos choisies restent prioritaires. Désactivable pour utiliser une identité CSS ou neutre |
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

- Après 2, 15 ou 35 minutes, aucun interlude n’est sélectionné avec le réglage initial. Dès 40 minutes, puis après 41 ou 90 minutes, il devient éligible si les autres conditions sont remplies.
- Le créneau doit contenir **entrée + clip intégral + sortie**. Un manque d’une seconde suffit à refuser l’insertion ; le clip n’est pas raccourci.
- En mode normal, l’horaire du programme suivant reste identique. Le mode flexible peut repousser les émissions automatiques avant le prochain horaire fixé, dans la limite du temps disponible. Les émissions après cette limite ne sont pas déplacées. Les marges nécessaires aux rediffusions fixées ultérieures sont également protégées. Un manque de place annule le break, sans raccourcir la vidéo.
- Les annonces de directs déjà connues dans les calendriers de chaînes constituent des limites supplémentaires : un nouvel interlude ne doit pas traverser leur heure de début. Un direct confirmé empêche le démarrage d’un nouvel interlude et ne sera jamais remplacé par la musique.
- Un direct confirmé interrompt immédiatement l’interlude, même si un ancien réglage demandait de le terminer. La grille musicale conserve ses horaires : à la fin réelle du direct, reprise de la vidéo ou de la phase musicale prévue à cet instant, avec son décalage, puis continuation aux heures initiales. L’attente avant un direct annoncé utilise les animations BSC en boucle. La détection existante doit être opérationnelle. [Détails et limites](LIVE_PLAYER.md#priorité-des-directs-et-attente).
- Le choix est effectué côté serveur : clips actifs, source officielle et autorisations confirmées avec note, disponibilité connue, durée positive de 3 600 secondes au maximum et catégorie de Paris correspondant au **début de l’interlude**.
- Les artistes consécutifs identiques sont exclus. Le tirage utilise une graine enregistrée dans l’antenne, l’heure du créneau et les identifiants éligibles ; il privilégie les clips encore non diffusés et l’alternance US/France dans la catégorie hip-hop, puis mélange les candidats. Les délais de rediffusion restent prioritaires. Aucun candidat compatible signifie aucune musique.
- Les compteurs sont conservés dans l’horloge commune : dernier passage et nombre de diffusions. L’entrée seule ne compte pas comme clip diffusé. Les projections futures ne modifient pas les compteurs réels.
- Une révocation de droits, une désactivation du clip ou une indisponibilité confirmée retire la référence. Le retrait d’une source courante interrompt son interlude ; l’option « terminer à la désactivation » ne permet pas de continuer une source retirée.

Les interventions musicales utilisent le même verrou que l’antenne. En cas d’interruption, seuls les programmes automatiques avant le prochain horaire fixé peuvent utiliser le temps libéré ; les horaires fixés sont conservés. Le bouton Passer de la page Le live applique également cette règle aux interludes. Les clips ne passent pas par la file manuelle des émissions.

Les choix musicaux sont enregistrés dans `plan` ; aucun visiteur ne fait son propre tirage. La graine `music_seed` persiste dans l’horloge commune. Un simple rafraîchissement ne remélange pas les morceaux. Le bouton de recalcul renouvelle cette graine sous verrou, retire les anciens interludes futurs et reconstruit les horaires avec les durées actuelles d’entrée, de vidéo et de sortie. Une modification des réglages musicaux recalcule également les créneaux futurs. Les compteurs de diffusion et le programme courant restent conservés.

## Musiques dans le programme de la journée

Le widget Elementor existant **Programme de la journée** inclut par défaut les interludes présents dans l’antenne commune : titre, artiste, miniature, lien réel du clip et badge **MUSIC BREAK**, sans fausse fiche BSC BDD ni badge LIVE. La même plage réserve l’entrée, la vidéo complète et la sortie. Une note indique les horaires précis du clip et les secondes d’animation BSC ; les émissions suivantes utilisent les horaires recalculés.

Elementor permet d’afficher/masquer les musiques, de masquer le détail des animations, de personnaliser le libellé et la couleur du badge, et de choisir la précision des heures. Le défaut affiche les secondes pour la musique et les minutes pour les émissions. Le chargement initial reste limité à cinq entrées futures plus celle en cours ; **Afficher plus** inclut les morceaux supplémentaires. Masquer les musiques dans le widget ne modifie pas l’antenne ni les horaires des émissions. La grille d’administration Le live identifie également les breaks et détaille leurs durées.

## Animations et lecteur

Les deux fichiers fournis **BSC-présentationcirque.mp4** et **BSC-presentationmoon.mp4** sont intégrés sans modification sous les noms `assets/media/ident-circus.mp4` et `assets/media/ident-moon.mp4`. Ils remplacent l’ancienne animation unique pour l’entrée et la sortie des interludes. Leur durée est d’environ 10,03 secondes ; la grille réserve 11 secondes pour laisser apparaître la dernière image. L’animation générale de chargement du lecteur reste distincte de ces phases programmées.

Dans **BSC TV → Music Interludes → Identité visuelle**, **Inclure les animations BSC fournies : Manège, Lune, Poussin et Chevalier** active les quatre références incluses depuis la version 1.26.0. **Bibliothèque d’animations aléatoires → Ajouter des animations** ouvre la médiathèque WordPress et permet de sélectionner ou téléverser plusieurs vidéos. Jusqu’à 50 références personnalisées de 120 secondes chacune peuvent compléter le tirage ; les références dupliquées, sans durée exploitable, supprimées ou d’un autre type ne sont pas choisies. Les durées entières arrondies par WordPress reçoivent une seconde de réserve. **Retirer** enlève une animation de la sélection sans supprimer le fichier de la médiathèque. Enregistrer les réglages recalcule les futurs interludes en conservant le programme courant et les horaires fixés.

Le tirage utilise la graine et l’horloge partagées. Avec plusieurs vidéos, l’entrée évite l’entrée précédente et la sortie évite l’animation d’entrée du même interlude. Les URL et durées choisies sont enregistrées dans chaque créneau ; elles ne changent pas selon le visiteur ou un rafraîchissement. Le total du créneau comprend les deux animations complètes et le clip musical. Un espace trop court ne tronque aucune vidéo.

Les champs **Vidéo d’entrée fixe** et **Vidéo de sortie fixe** restent des remplacements explicites prioritaires : les retirer pour utiliser le tirage sur la phase concernée. Les durées de secours règlent le logo/fond ou une ancienne vidéo fixe sans durée connue. Sans animation ni logo, le fond et le texte facultatif constituent l’identité. Un visiteur arrivant pendant une phase rejoint son offset serveur ; le logo CSS utilise un délai négatif correspondant à la portion écoulée. Les aperçus d’entrée/sortie utilisent la sélection courante et sa durée complète.

Validation locale de la version 1.20.0 : **251 contrôles ciblés** (107 du moteur musical, 46 de la grille et de la liste quotidienne, 20 dédiés aux animations, 38 de validation groupée, 21 du lecteur musical et 19 de l’import de playlists dans le navigateur), plus **68 vérifications dans un navigateur local** des animations réelles et de l’administration groupée à 1 280 et 390 pixels. Les deux MP4 se décodent dans Chrome ; sélection multiple, aperçu aléatoire, durées complètes, seuil exact de 40 minutes, persistance des choix, vidéos personnalisées et exclusions ont été vérifiés. Les fichiers intégrés ont été comparés octet par octet aux originaux. Aucun import ni clip de production n’a été modifié.

Avant une identité, l’iframe précédente est détruite. Avant le clip, l’écran d’identité est retiré. Les identités musicales ne sont pas superposées à YouTube et ne remplacent pas une publicité par un faux écran. L’ancienne animation générale de chargement n’est pas relancée sur les phases musicales. Les erreurs tardives d’un lecteur précédent ne peuvent pas changer la nouvelle phase. La préférence de réduction des animations utilise l’identité statique.

Le clip utilise le lecteur officiel existant avec son offset partagé. Le badge **MUSIC BREAK** est distinct de LIVE et REDIFFUSION ; aucun profil ou avatar BSC BDD n’est inventé pour l’artiste. Le logo fixe de la chaîne TV et les associations des émissions habituelles conservent leur fonctionnement. Les styles Elementor du lecteur existant restent disponibles.

## Métadonnées et performances

Les clips sont des enregistrements privés `bsc_tv_music_clip`, avec le champ `_bsc_tv_music`. Ils contiennent uniquement des références et informations éditoriales, jamais des fichiers de musique. Le cache de bibliothèque dure une minute et est invalidé lors des changements administratifs ; sa révision est stockée dans `bsc_tv_music_revision`.

La clé YouTube existante est réutilisée uniquement sur demande administrative et pendant le passage Infomaniak déjà authentifié et limité à un lancement par jour. Le module vérifie jusqu’à **50 références, les moins récemment contrôlées**, dans ce passage. Une bibliothèque de plus de 50 clips est donc vérifiée progressivement. Aucun nouvel événement WP-Cron n’est ajouté. Le bilan technique est enregistré dans `bsc_tv_music_metadata_report`.

Les API peuvent fournir durée, miniature, chaîne d’origine et disponibilité d’intégration. Les restrictions d’âge et les restrictions régionales connues pour la France excluent la musique. Un échec de requête conserve une restriction déjà connue et ne valide jamais les droits. Aucune recherche Internet ni collecte de nouveaux clips n’est lancée automatiquement. [Ressource officielle YouTube videos](https://developers.google.com/youtube/v3/docs/videos).

La route REST du lecteur n’appelle pas YouTube. Elle expose les métadonnées publiques et les timestamps de la phase, sans notes d’autorisation ni clé privée. Les requêtes continuent sur le rythme existant, en se rapprochant des limites de phase ; une phase de trois secondes ne subit pas l’ancien minimum de cinq secondes. Le contrôle local se fonde sur l’heure serveur reçue et ne recharge pas la page.

Module désactivé sans break en cours : aucun accès à la bibliothèque ni vérification musicale d’API. Les réglages musicaux inactifs ne modifient pas la signature de la grille normale. La nouvelle limite de programmes consécutifs est un réglage indépendant du lecteur et peut renouveler ses choix automatiques.

## Vérifications effectuées

Version 1.21.0 : **521 contrôles PHP ciblés et 16 vérifications de navigateur local** réussis. La nouvelle simulation utilise 50 chaînes, des émissions de 50 minutes, les tranches enregistrées et les animations incluses : recomposition avec musique, conservation du programme courant et d’un horaire fixé, variété des sources et enrichissement d’une chaîne sans replay récent. Elle exerce aussi le nonce invalide, l’accès anonyme et le verrou occupé. Les suites existantes du lecteur, des imports, de la grille, de la liste quotidienne, des animations et des validations groupées passent. Le formulaire et le tableau sont vérifiés à 1 280 et 390 pixels. Les plateformes sont simulées ; aucune configuration de production n’a changé.

**695 contrôles locaux** : les 649 précédents et 46 vérifications du choix aléatoire partagé, du recalcul avec protection des horaires fixés et des délais de rediffusion, des changements de durée, du diagnostic et des morceaux dans le widget quotidien. Les tests utilisent une copie SQLite jetable de WordPress, Elementor et BSC BDD. API et SDK sont simulés. Les scénarios couvrent aussi les 25 points du cahier des charges : limites de durée, activation, tranches, trois phases et retours, arrivées tardives, visiteurs multiples, absence de validation, indisponibilité, manque d’espace, directs, désactivation, Elementor, grilles et changements d’heure. Les formulaires et le contrôleur AJAX sont exercés avec capacités et nonces WordPress.

L’import vérifie notamment la pagination, la reprise après réponse perdue, les droits d’accès, les jetons et sessions d’administrateurs, les doublons, les catégories multiples, la conservation des réglages existants, les restrictions nouvelles, les erreurs réseau et de métadonnées, les verrous, les réponses qui se répètent et la bibliothèque à sa limite de 500 clips. L’interface arrête une réponse qui n’avance pas au lieu de boucler.

```sh
php tests/music-integration.php /chemin/wordpress-de-test
node tests/music-browser.mjs
php tests/music-playlist.php /chemin/wordpress-de-test
node tests/music-playlist-browser.mjs
php tests/music-grid.php /chemin/wordpress-de-test
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

L’aperçu local de l’entrée, du clip, de la sortie et de l’émission suivante ainsi que l’administration ont été vérifiés à 1 200 et 390 pixels, sans débordement horizontal. Le formulaire d’import, la liste quotidienne avec morceaux et pagination AJAX, les notes de durée, le diagnostic et la prévisualisation de l’animation incluse ont également été contrôlés sur des fixtures locales aux deux largeurs. Ces contrôles locaux n’utilisent pas la session Firefox du site. Les tests ne constituent ni une validation des droits réels ni une preuve de lecture de chaque clip sur YouTube. Deux avertissements Elementor préexistants peuvent apparaître dans la fixture PHP 8.4.

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

La version 1.16.0 ajoute `includes/music-playlist.php`, `tests/music-playlist.php` et `tests/music-playlist-browser.mjs`, avec adaptation de l’administration musicale, de son script, de ses styles et de l’accès YouTube existant. Pour revenir de 1.16.0 à 1.15.0, réinstaller le ZIP précédent : les références importées restent des clips ordinaires de la bibliothèque ; l’état de reprise inutilisé expire après 24 heures.

La version 1.17.0 ajoute `tests/music-grid.php` et adapte le moteur musical, son administration, l’horloge/programmation, le widget quotidien et ses styles. Les clips et notes d’autorisation existants restent conservés. Pour revenir à 1.16.0, désactiver les futurs interludes avec la politique d’interruption souhaitée, sauvegarder puis réinstaller le ZIP précédent ; les réglages de présentation supplémentaires et la graine musicale sont ignorés par l’ancienne version.


## Bibliothèques par chaîne

Depuis 1.24.0, **1 · Musique** conserve les clips existants ; **2 · Musique FR** accueille les playlists distinctes de la chaîne française. Ses tranches initiales ciblent le hip-hop / rap français de 1990 à 1999. Le filtre utilise l’année du morceau renseignée dans la fiche, pas la date de publication YouTube. Les imports, catégories, validations et réglages restent propres à chaque bibliothèque. [Configuration des deux chaînes](STATIONS.md).


### Bibliothèque à quatre animations (1.26.0)

Les films Poussin et Chevalier complètent les deux vidéos Lune et Manège sans les remplacer. Tous les quatre sont disponibles par défaut au lancement, entre les programmes, dans les interludes et pendant l’attente des directs. Chaque nouveau MP4 dure 10,027 secondes ; 11 secondes sont réservées pour garder la dernière image. Les vidéos supplémentaires de la médiathèque restent propres à chaque antenne.

Après 20 minutes sans démarrage d’un direct annoncé, la bibliothèque musicale validée peut fournir un seul clip de transition, dans un budget de cinq minutes avec les animations depuis 1.28.0, puis une émission reprend. Cette utilisation n’exige pas une émission précédente de 40 minutes : elle répond à l’absence du direct. Les règles de validation, les tranches horaires, l’alternance des artistes et le délai entre clips restent appliqués. Sans temps suffisant pour un clip complet, une émission reprend directement. La grille initiale garde ses horaires, et un direct confirmé reprend immédiatement la priorité. Voir [le lecteur](LIVE_PLAYER.md#direct-absent-après-20-minutes-1260).
