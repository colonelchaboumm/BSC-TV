# BSC TV

Programme WordPress des directs craft beer. La mise en page reprend la une illustrée, le turquoise `#00d1b4`, le magenta `#bd009f` et les grands titres de BSC News. BSC BDD fournit le nom, l’avatar et le lien des créateurs associés.

## Installation

1. Dans **Extensions → Ajouter une extension → Téléverser une extension**, installez le ZIP `bsc-tv.zip`, puis activez BSC TV. Le plugin requiert WordPress 6.2 et PHP 7.4 ou supérieur.
2. Dans **BSC TV → Réglages et imports**, choisissez **Catalogue inclus · chaînes aux directs vérifiés**. Pour démarrer le suivi des nouveaux comptes dès l’import, cochez **Publier les nouvelles entrées**. Le catalogue complet comprend aussi les pistes dont le format direct reste à documenter. Les chaînes déjà enregistrées gardent leur statut.
3. Importez **Catalogue inclus · émissions annoncées** pour récupérer les dates sourcées. Les dates et les liens peuvent évoluer. Les événements en brouillon sont modifiables avant publication. Ajoutez une image WordPress ou une URL de visuel si nécessaire.
4. Renseignez les clés des plateformes utilisées, puis lancez **Collecter le programme maintenant**. Le dernier passage et les chaînes en attente figurent dans les réglages. Les erreurs d’API ou de flux figurent dans la fiche chaîne.

## Associer une fiche créateur BSC BDD

1. Dans **BSC TV → Chaînes**, utilisez la colonne **Fiche créateur BSC BDD** pour repérer les chaînes à associer.
2. Ouvrez une chaîne, recherchez la fiche par son nom dans **Fiche créateur BSC BDD**, sélectionnez le résultat et enregistrez. Vous pouvez associer plusieurs chaînes Twitch, YouTube ou autres à la même fiche.
3. Dans **BSC TV → Émissions**, choisissez la **Chaîne du créateur** pour reprendre sa fiche. Un lien de chaîne reconnu de façon unique suffit aussi à faire le lien entre les deux. Pour une vidéo YouTube, la synchronisation peut retrouver la chaîne d’origine. Le champ **Fiche BSC BDD propre à cette émission** permet de choisir un autre profil pour une émission.

Une modification du rattachement sur une chaîne est répercutée sur les deux widgets, pour les émissions synchronisées et celles qui reprennent cette chaîne. Si la fiche devient inaccessible, le nom du créateur reste visible lorsqu’il est renseigné sur la chaîne ou l’émission.

À l’import de chaînes, BSC TV rapproche un identifiant de fiche explicitement fourni, un slug de fiche vérifié dans le catalogue ou une URL de chaîne identique enregistrée sur une fiche candidate. Le rapprochement se fait avec les fiches publiques de l’installation locale. Une chaîne déjà importée sans fiche peut être associée lors d’un nouvel import. Un rattachement existant et le statut de la chaîne sont conservés. Les profils à compléter sont visibles dans la colonne **Fiche créateur BSC BDD**.

## Elementor et méga-menu

- **BSC TV – Programme** présente les directs en priorité, suivis des prochaines émissions. La une met en avant le premier direct ou la prochaine date. Réglez la période, le nombre d’émissions, la plateforme, les images, les couleurs, la typographie, les colonnes et les espacements.
- **BSC TV – Méga-menu** utilise une liste avec vignettes. Déposez le widget dans le modèle ou le conteneur de contenu du méga-menu du site. Le réglage **Lien vers le programme complet** ajoute un lien vers la page de l’agenda. Le constructeur du menu contrôle l’ouverture du panneau et son déclencheur.
- **BSC TV – Pastille Live** affiche un bouton compact à placer dans un en-tête, un menu ou une page. Le bouton met en avant le titre du direct avec un point rouge et le libellé « LIVE ». Hors direct, il indique la prochaine émission avec sa date. Au clic ou au clavier, il ouvre les directs et les prochaines émissions. Les onglets **Contenu** et **Style** règlent les libellés, le titre, l’horaire, le point, les couleurs, la typographie, l’alignement et les dimensions du panneau. L’ouverture peut se faire en panneau déroulant ou sous le bouton dans le flux de la page.
- Sans Elementor, utilisez `[bsc_tv]`, `[bsc_tv format="menu" days="7" limit="3" agenda="https://votre-site.fr/programme/"]` ou `[bsc_tv format="badge"]` dans un bloc Shortcode. BSC BDD peut être activé indépendamment.

Les horaires s’affichent dans le fuseau réglé dans **Réglages → Général**. La période par défaut est de 45 jours pour la page et de 7 jours pour le méga-menu. Une page ouverte rafraîchit les cartes chaque minute à partir de WordPress. La fréquence de collecte et le délai de découverte sur la plateforme déterminent la rapidité réelle du badge.

## Collecte et vérification des directs

| Plateforme | Programme à venir | Direct en cours |
| --- | --- | --- |
| Twitch | Calendrier Helix des chaînes suivies, collecté quotidiennement sur 45 jours | API Helix sur les chaînes dont une émission approche ou déjà en direct |
| YouTube | Recherche `upcoming` quotidienne, détails et actualisation par identifiant vidéo, sur 45 jours | `videos.list` pour les vidéos connues dont la diffusion approche ou est en cours |
| Kick | Agenda public compatible, saisie ou CSV | API des chaînes pour un lien Kick suivi, à proximité d’une émission |
| Facebook, Instagram, TikTok, Dailymotion, Vimeo, BeerTube, Zoom et autres | Agenda public compatible, saisie ou CSV | État éditorial « En direct » avec une heure de fin obligatoire |

Twitch et Kick nécessitent un Client ID et un secret d’application. YouTube utilise une clé Data API v3. Les champs de réglages peuvent être remplacés par les constantes `BSC_TV_YOUTUBE_KEY`, `BSC_TV_TWITCH_ID`, `BSC_TV_TWITCH_SECRET`, `BSC_TV_KICK_ID`, `BSC_TV_KICK_SECRET` dans `wp-config.php`.

La collecte quotidienne se poursuit par lots. Les calendriers YouTube et Twitch des chaînes suivies alimentent directement le programme. YouTube réserve jusqu’à 90 appels `search.list` par jour par défaut, généralement un par chaîne et un par page supplémentaire. Les chaînes les moins récemment examinées sont prioritaires lorsque le quota limite la collecte. Les premières YouTube peuvent aussi apparaître comme des diffusions programmées. Un lien vidéo YouTube saisi dans une émission permet également de suivre son statut précis.

Les vérifications automatiques de statut sont planifiées si l’option est activée et qu’au moins un accès API complet est renseigné. La collecte quotidienne des agendas publics est disponible avec ou sans accès API.

Activez **Vérifier le statut des directs toutes les cinq minutes** pour interroger les émissions à partir de 30 minutes avant leur début et suivre les directs déjà détectés. Sur une nouvelle installation, cette option est désactivée. Une mise à jour depuis une installation qui avait déjà synchronisé conserve le contrôle automatique. Les directs spontanés sans émission connue peuvent échapper à cette vérification. Une confirmation du direct expire côté public après 20 minutes. Une erreur de collecte conserve le dernier programme connu. Une annulation confirmée est retirée et les émissions terminées sont conservées dans **BSC TV → Émissions → Archives BSC TV**. Pour une émission à heure de fin inconnue, l’archivage utilise un délai de six heures après l’heure de début, sauf indication du fournisseur.

WP-Cron dépend du trafic lorsque les visites le déclenchent. Pour des passages réguliers, faites exécuter par votre hébergeur les événements WordPress arrivés à échéance chaque minute, par exemple avec `wp cron event run --due-now` depuis la racine WordPress. [Configuration du cron système](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/)

## Agendas et flux publics

Dans une chaîne publiée, renseignez **URL de l’agenda ou du flux public**. Pour un agenda iCalendar avec horaires flottants, indiquez le fuseau de l’organisateur. Les mots-clés de la chaîne permettent de filtrer les titres d’un agenda généraliste. Les formats pris en charge sont les entrées iCalendar `VEVENT` datées avec `URL` et `UID`, le RSS avec dates explicites du module événement, et les pages HTML contenant des événements en ligne structurés en JSON-LD. Les agendas iCalendar doivent déjà détailler les occurrences. Les règles `RRULE` et `RDATE` ne sont pas développées. Une date de publication RSS seule ne suffit pas.

Par défaut, les annonces issues d’un flux sont enregistrées en brouillon. Un administrateur peut autoriser la publication automatique des annonces confirmées d’une source officielle. Les annonces provisoires restent à valider, même pour une source officielle. Une annulation explicite retire l’annonce connue. La disparition d’une entrée d’un flux ne suffit pas à annuler une émission, car les flux peuvent ne contenir qu’une liste partielle. Pour les chaînes déjà importées, renseignez le flux dans leur fiche. L’[agenda officiel Störtebeker](https://www.stoertebeker.com/fileadmin/CDE/Bilder_SBS/Verkostung/Abenteuerreise/S-LIVE_2026-2027.ics) est compatible avec le fuseau `Europe/Berlin`. Son [programme prévisionnel](https://www.stoertebeker.com/verkostung/stoertebeker-abenteurreise/programm) doit être validé avant publication.

## Import CSV

Le formulaire accepte un fichier ou un tableau collé, en UTF-8. Séparateurs pris en charge `,`, `;` et tabulation. Jusqu’à 500 lignes par lot.

| Type | En-têtes requis | En-têtes facultatifs |
| --- | --- | --- |
| Chaînes | `name,url` | `platform,bdd_id,bdd_slug,source,country,language,activity,notes,keywords,feed_url,feed_timezone,feed_official` |
| Émissions | `title,url,starts_at` | `ends_at,platform,creator,channel_id,bdd_id,image,status,source` |

Pour `starts_at` et `ends_at`, utilisez `2026-12-03T21:00:00+01:00` ou `2026-12-03T21:00` dans le fuseau du site. Pour `status`, utilisez `upcoming`, `live` ou `cancelled`. Pour `channel_id`, utilisez l’identifiant d’une chaîne publiée dans BSC TV. Pour `bdd_id`, utilisez l’identifiant d’une fiche publique de votre installation. Les imports ignorent les doublons repérables et indiquent les lignes invalides. Les fiches existantes se modifient dans WordPress.

Le catalogue fourni est une photographie de sources publiques au 16 novembre 2026. Il comprend 22 canaux pour lesquels un direct est documenté ou annoncé, dont certains historiques. Un créateur peut avoir plusieurs lignes s’il publie sur différentes plateformes. Chaque ligne contient une source et un état d’activité. [Recensement](RECENSEMENT.md)

## Références techniques

- [Twitch Helix – diffusions et horaires](https://dev.twitch.tv/docs/api/reference/)
- [YouTube – recherche par état](https://developers.google.com/youtube/data/reference/rest/v3/search/list) et [quotas](https://developers.google.com/youtube/data/determine_quota_cost)
- [Kick – API des chaînes](https://docs.kick.com/apis/channels)
