# BSC TV

Programme WordPress des directs craft beer. La mise en page reprend la une illustrée, le turquoise `#00d1b4`, le magenta `#bd009f` et les grands titres de BSC News. BSC BDD fournit le nom, l’avatar et le lien des créateurs associés.

## Installation

1. Dans **Extensions → Ajouter une extension → Téléverser une extension**, installez le ZIP `bsc-tv.zip`, puis activez BSC TV. Le plugin requiert WordPress 6.2 et PHP 7.4 ou supérieur.
2. Dans **BSC TV → Réglages et imports**, choisissez **Catalogue inclus · créateurs**. Le catalogue contient des sources vérifiées et des pistes signalées. Dans **Chaînes**, publiez les comptes que vous souhaitez suivre. Recherchez et associez leur fiche BSC BDD publiée dans le champ prévu.
3. Importez **Catalogue inclus · émissions annoncées** pour récupérer les dates sourcées. Les dates et les liens peuvent évoluer. Les événements en brouillon sont modifiables avant publication. Ajoutez une image WordPress ou une URL de visuel si nécessaire.
4. Renseignez les clés des plateformes utilisées, puis lancez un passage de synchronisation. Le dernier passage WP-Cron figure dans les réglages et les éventuelles erreurs d’API figurent dans la fiche chaîne.

## Associer une fiche créateur BSC BDD

1. Dans **BSC TV → Chaînes**, utilisez la colonne **Fiche créateur BSC BDD** pour repérer les chaînes à associer.
2. Ouvrez une chaîne, recherchez la fiche par son nom dans **Fiche créateur BSC BDD**, sélectionnez le résultat et enregistrez. Vous pouvez associer plusieurs chaînes Twitch, YouTube ou autres à la même fiche.
3. Dans **BSC TV → Émissions**, choisissez la **Chaîne du créateur** pour reprendre sa fiche. Un lien de chaîne reconnu de façon unique suffit aussi à faire le lien entre les deux. Pour une vidéo YouTube, la synchronisation peut retrouver la chaîne d’origine. Le champ **Fiche BSC BDD propre à cette émission** permet de choisir un autre profil pour une émission.

Une modification du rattachement sur une chaîne est répercutée sur les deux widgets, pour les émissions synchronisées et celles qui reprennent cette chaîne. Si la fiche devient inaccessible, le nom du créateur reste visible lorsqu’il est renseigné sur la chaîne ou l’émission.

## Elementor et méga-menu

- **BSC TV – Programme** présente les directs en priorité, suivis des prochaines émissions. La une met en avant le premier direct ou la prochaine date. Réglez la période, le nombre d’émissions, la plateforme, les images, les couleurs, la typographie, les colonnes et les espacements.
- **BSC TV – Méga-menu** utilise une liste avec vignettes. Déposez le widget dans le modèle ou le conteneur de contenu du méga-menu du site. Le réglage **Lien vers le programme complet** ajoute un lien vers la page de l’agenda. Le constructeur du menu contrôle l’ouverture du panneau et son déclencheur.
- Sans Elementor, utilisez `[bsc_tv]` ou `[bsc_tv format="menu" days="7" limit="3" agenda="https://votre-site.fr/programme/"]` dans un bloc Shortcode. BSC BDD peut être activé indépendamment.

Les horaires s’affichent dans le fuseau réglé dans **Réglages → Général**. La période par défaut est de 45 jours pour la page et de 7 jours pour le méga-menu. Une page ouverte rafraîchit les cartes chaque minute à partir de WordPress. La fréquence de collecte et le délai de découverte sur la plateforme déterminent la rapidité réelle du badge.

## Plateformes

| Plateforme | Programme à venir | Direct en cours |
| --- | --- | --- |
| Twitch | API Helix, créneaux publiés sur les chaînes suivies, sur 30 jours | API Helix, vérification toutes les 5 minutes via WP-Cron |
| YouTube | Data API, recherche par chaîne ou ajout d’une URL vidéo précise | Statut des vidéos connues vérifié toutes les 5 minutes. Détection des nouveaux directs via la recherche périodique |
| Kick | Ajout manuel ou CSV | API publique, vérification toutes les 5 minutes via WP-Cron |
| Facebook, Instagram, TikTok, Dailymotion, Vimeo, BeerTube, Zoom et autres | Ajout manuel ou CSV avec un lien public ou d’inscription | État éditorial « En direct » avec une heure de fin obligatoire |

Twitch et Kick nécessitent un Client ID et un secret d’application. YouTube utilise une clé Data API v3. Les champs de réglages peuvent être remplacés par les constantes `BSC_TV_YOUTUBE_KEY`, `BSC_TV_TWITCH_ID`, `BSC_TV_TWITCH_SECRET`, `BSC_TV_KICK_ID`, `BSC_TV_KICK_SECRET` dans `wp-config.php`.

YouTube réserve par défaut 90 appels `search.list` par jour de quota, à raison de deux recherches par chaîne. La découverte parcourt les chaînes par rotation et peut prendre plusieurs heures si elles sont nombreuses. Pour garantir le suivi d’une vidéo précise, saisissez son URL `watch?v=…`, `youtu.be/…` ou `live/…` dans une émission. Certaines premières YouTube sont présentées par l’API comme des diffusions programmées. Les canaux Twitch ou Kick généralistes peuvent filtrer les titres ou catégories avec les mots-clés de la fiche chaîne. Pour YouTube, le filtre porte sur le titre. Les clés et les réponses brutes des fournisseurs restent côté WordPress.

Les créneaux Twitch sont rafraîchis par lots. Un état automatique « En direct » expire si la dernière confirmation date de plus de 20 minutes. WP-Cron dépend du trafic quand il est déclenché par les visites. Un hébergeur qui pilote WP-Cron séparément doit également exécuter les tâches de WordPress à la fréquence souhaitée.

## Import CSV

Le formulaire accepte un fichier ou un tableau collé, en UTF-8. Séparateurs pris en charge `,`, `;` et tabulation. Jusqu’à 500 lignes par lot.

| Type | En-têtes requis | En-têtes facultatifs |
| --- | --- | --- |
| Chaînes | `name,url` | `platform,bdd_id,source,country,language,activity,notes,keywords` |
| Émissions | `title,url,starts_at` | `ends_at,platform,creator,channel_id,bdd_id,image,status,source` |

Pour `starts_at` et `ends_at`, utilisez `2026-12-03T21:00:00+01:00` ou `2026-12-03T21:00` dans le fuseau du site. Pour `status`, utilisez `upcoming`, `live` ou `cancelled`. Pour `channel_id`, utilisez l’identifiant d’une chaîne publiée dans BSC TV. Pour `bdd_id`, utilisez l’identifiant d’une fiche publique de votre installation. Les imports ignorent les doublons repérables et indiquent les lignes invalides. Les fiches existantes se modifient dans WordPress.

Le catalogue fourni est une photographie de sources publiques au 14 novembre 2026. Il comprend des créateurs, brasseries, médias et organisateurs. Un compte peut avoir plusieurs lignes si ses chaînes sont distinctes. Les vidéos montées, les replays et les émissions seulement annoncées par un tiers ont été traités selon les preuves décrites dans le [recensement](RECENSEMENT.md).

## Références techniques

- [Twitch Helix – diffusions et horaires](https://dev.twitch.tv/docs/api/reference/)
- [YouTube – recherche par état](https://developers.google.com/youtube/data/reference/rest/v3/search/list) et [quotas](https://developers.google.com/youtube/data/determine_quota_cost)
- [Kick – API des chaînes](https://docs.kick.com/apis/channels)
