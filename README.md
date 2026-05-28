# Prédictions d'Éclipses Solaires

Carte interactive des éclipses solaires du XXIe siècle, avec calcul en temps réel des circonstances locales.

**[Voir le site](https://eclipsemaps.fr/)**

## Fonctionnalités

- Carte interactive (Leaflet / OpenStreetMap) des 180 éclipses solaires de 2021 à 2100
- Bande de totalité/annularité tracée pour chaque éclipse centrale
- Contour de la zone de visibilité partielle et frontière jour/nuit
- Clic sur la carte : calcul en temps réel des circonstances locales (heures de contact, magnitude, obscuration, durée de totalité, position du Soleil)
- Filtre par pays traversé
- Éclipses partielles incluses (optionnel)
- URLs partageables avec vue (position + zoom)
- Responsive (desktop + mobile)

## Stack technique

- Backend : PHP 8+ / MySQL 8+
- Frontend : HTML5, CSS3, JavaScript vanilla
- Cartographie : Leaflet.js + OpenStreetMap
- Algorithmes : éléments de Bessel polynomiaux (NASA/Espenak)
- Aucun framework, aucune dépendance externe côté serveur

## Installation

1. Cloner le dépôt dans un hébergement LAMP
2. Copier `config.example.php` en `config.php` et renseigner les identifiants MySQL
3. Créer la base de données et exécuter `scripts/schema.sql`
4. Exécuter les scripts de scraping dans l'ordre (voir `docs/documentation.md`)
5. Accéder à `public/index.php`

## Données

Les données proviennent du catalogue de Fred Espenak (NASA/GSFC) couvrant cinq millénaires d'éclipses solaires. L'architecture supporte l'extension à la période complète (-1999 à +3000) sans modification de code.

- 180 éclipses (117 centrales + 63 partielles)
- 180 jeux d'éléments de Bessel
- ~14 500 points de tracé de bande
- ~500 entrées pays traversés (107 pays)

## Précision

- Durée de totalité : ±1-2 secondes
- Heures de contact : ±quelques secondes
- Validation : éclipse du 12 août 2026, durée calculée 2m18.0s (NASA : 2m18s), altitude Soleil 25.7° (NASA : 25.8°)

## Crédits

- Eclipse Predictions by Fred Espenak, NASA/GSFC Emeritus
- *Five Millennium Canon of Solar Eclipses: -1999 to +3000*, NASA TP-2006-214141
- Inspiré par le travail de [Xavier Jubier](http://xjubier.free.fr/) (UAI, Working Group on Solar Eclipses)
- Développé par Claude — Opus 4.6 (Anthropic) sous la supervision de R. Bel

## Licence

GPL v3 — voir [LICENSE](LICENSE)
