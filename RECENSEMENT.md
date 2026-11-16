# Créateurs et émissions craft beer en direct

Recensement au **16 novembre 2026**. Le [CSV des créateurs](data/createurs.csv) compte 37 canaux, dont 22 avec un direct vidéo documenté ou annoncé. Il indique le pays, la langue, l’état de l’activité et la source. Le [CSV des émissions](data/programmes.csv) contient les dates, le fuseau, le lien de visionnage ou d’inscription et le visuel lorsqu’il est disponible.

## Créateurs ayant déjà une fiche BSC BDD

| Créateur ou chaîne | Direct vérifié |
| --- | --- |
| Les Cités d’Orge | Brassage amateur sur Twitch depuis la Suisse romande. Dernier direct relevé le 18 octobre 2026. [Fiche BSC BDD](https://brewerssocialclub.com/acteur-evenement/les-cites-dorge/) · [Historique](https://twitracker.com/streamers/lescitesdorge) |
| Bière et Moustache | Le podcast publie son compte Twitch. Le site BSC référence aussi une rediffusion YouTube datée du 13 octobre 2026. [Fiche BSC BDD](https://brewerssocialclub.com/acteur-evenement/biere-et-moustache/) · [Archives du podcast](https://biereetmoustache.lepodcast.fr/page/5) · [Rediffusion](https://www.youtube.com/watch?v=aWM8EYetARk) |
| The Craft Beer Channel | Dégustation interactive annoncée le 3 décembre 2026 sur YouTube. [Fiche BSC BDD](https://brewerssocialclub.com/acteur-evenement/the-craft-beer-chanel/) · [Annonce](https://beermerchants.com/beer-style-mixed-cases/cbc-christmas-case-2026) |
| Real Ale Craft Beer, Simon Martin | Entretiens et dégustations dans The Stoned Crow. BSC utilise le nom Red Ale Craft Beer et renvoie vers le même compte YouTube. [Fiche BSC BDD](https://brewerssocialclub.com/acteur-evenement/red-ale-craft-beer/) · [Direct avec Meteor](https://www.beersofeurope.co.uk/live-beer-tasting-with-meteor-brewery-and-real-ale-craft-beer-on-youtube/) |
| Genus Brewing | Le diffuseur documente son format vidéo en direct sur YouTube. Le rythme actuel reste à confirmer. [Fiche BSC BDD](https://brewerssocialclub.com/acteur-evenement/genus-brewing/) · [Notes de diffusion](https://podcast.genusbrewing.com/episodes/raw-ale-methods-benefits-and-risks-helles-bock-genus-livestream-dxwwjwwd) |
| Brew York | Dégustation avec les fondateurs annoncée le 16 décembre 2026. La fiche BSC BDD est une fiche de la brasserie. [Fiche BSC BDD](https://brewerssocialclub.com/acteur-evenement/brew-york/) · [Calendrier Brew York](https://brewyork.co.uk/event/youtube-live-christmas-beers-2026/) |

Les slugs des fiches vérifiées figurent dans le CSV. Ils permettent de récupérer l’identifiant propre au WordPress qui exécute l’import. Les fiches des autres chaînes se choisissent dans la colonne **Fiche créateur BSC BDD** si aucun rapprochement n’est trouvé.

## Chaînes à examiner en priorité

| Créateur ou diffuseur | Pays et langue | Activité publique observée |
| --- | --- | --- |
| Les Cités d’Orge | Suisse · français | Brassage en direct sur Twitch. Un brassin de Noël figure dans l’historique du 18 octobre 2026. [Historique Twitch](https://twitracker.com/streamers/lescitesdorge) |
| The Craft Beer Channel | Royaume-Uni · anglais | Jonny Garrett et Brad Evans organisent une dégustation de Noël sur YouTube. [Présentation de la chaîne](https://craftbeerchannel.com/about/) · [Annonce de la dégustation](https://beermerchants.com/beer-style-mixed-cases/cbc-christmas-case-2026) |
| Brew York | Royaume-Uni · anglais | Wayne Smith et Lee Grabham animent des dégustations. L’édition de Noël figure au calendrier de la brasserie sous YouTube Live. [Annonce Brew York](https://brewyork.co.uk/event/youtube-live-christmas-beers-2026/) |
| Störtebeker Braumanufaktur | Allemagne · allemand | La série de dégustations interactives a un programme prévisionnel jusqu’avril 2027. [Calendrier Abenteuerreise](https://www.stoertebeker.com/verkostung/stoertebeker-abenteurreise/programm) |
| Frans Ruiter, De Bierverbinding | Pays-Bas · néerlandais | La treizième dégustation PINT a été diffusée sur BeerTube en septembre 2026. [Émission et rediffusion](https://www.beertube.tv/bierverbinding/videos/dertiende-pint-online-bierproeverij-4-september-2026-gewoon-lekker-bier/) |
| European Beer Consumers’ Union | Europe · anglais | Webinaires accessibles sur inscription. Les enregistrements paraissent sur YouTube. [Calendrier et rediffusions](https://www.ebcu.org/webinars/) |
| Homebrew Happy Hour | États-Unis · anglais | L’équipe indique animer des sessions de brassage en direct. [Présentation du média](https://www.homebrewhappyhour.com/about-us/) |
| Cerveja com Ciência | Brésil · portugais | Le projet universitaire publie des directs sur la science du brassage. [Présentation du projet](https://www.unifal-mg.edu.br/lme/cervejacomciencia/) |

## Dates à importer

Heures de Paris. Les liens YouTube des deux émissions de décembre ouvrent la page des directs de la chaîne tant que le lien vidéo exact reste à renseigner.

| Date | Émission | Accès |
| --- | --- | --- |
| 23 novembre 2026, 20 h | 50 Shades of Pink, avec Michela Cimatoribus | Inscription Zoom. [EBCU](https://www.ebcu.org/webinars/) |
| 3 décembre 2026, 21 h | Dégustation des bières de Noël, The Craft Beer Channel | YouTube. [Beer Merchants](https://beermerchants.com/beer-style-mixed-cases/cbc-christmas-case-2026) |
| 16 décembre 2026, 20 h | Christmas Beers 2026, Brew York | Événement hybride annoncé sous YouTube Live. [Brew York](https://brewyork.co.uk/event/youtube-live-christmas-beers-2026/) |
| 12 février, 12 mars et 17 avril 2027, 20 h 15 | Abenteuerreise, Störtebeker | Dates prévisionnelles. [Programme de la saison](https://www.stoertebeker.com/verkostung/stoertebeker-abenteurreise/programm) |

## Lecture du catalogue

La recherche couvre des sources francophones, anglophones, germanophones, néerlandophones, italiennes, lusophones, hispanophones et japonaises. Elle s’appuie sur des annonces, chaînes publiques et archives de diffuseurs. La proximité avec la bière artisanale ou le brassage est vérifiée séparément du format en direct. Les collaborations anciennes et les comptes principalement consacrés aux vidéos montées méritent une vérification avant publication. Une apparition en tant qu’invité renseigne une collaboration et le compte de l’hôte reste la source à suivre.

Les plateformes n’exposent pas un index mondial des créateurs craft beer ni des directs à venir. Les listes publiques changent avec les annonces et reposent sur les chaînes suivies. Les comptes historiques sont signalés dans le CSV. BAOS Craft Beer, The Hopper Reviews, Une Bière et Jivay et Sirotons Le Houblon figurent dans le catalogue général, en attendant une preuve de direct sur les chaînes indiquées.
