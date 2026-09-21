# Provenance — animate

Copie versionnée du skill `animate` publié par
[`delphi-ai/animate-skill`](https://github.com/delphi-ai/animate-skill).

| | |
|---|---|
| Amont | `delphi-ai/animate-skill` |
| Skill | `animate` |
| Commit | `71bc617e7000e69b679549f13ea132e696420d6c` (2026-01-28) |
| Emplacement | `.claude/skills/animate/` |
| Licence | **aucune** — voir ci-dessous |

## Deux réserves, écrites ici pour qu'elles ne se perdent pas

**1. Le dépôt amont ne porte aucune licence.** Pas de `LICENSE`, aucune mention
dans le `README` ni dans le `SKILL.md`. Sans licence explicite, le droit d'auteur
s'applique par défaut : rien n'autorise formellement la redistribution. De plus
le contenu se présente lui-même comme tiré du cours payant « Animations on the
Web » d'Emil Kowalski. Pour un dépôt de travail, l'usage ne pose pas de question
pratique ; pour le dépôt d'un produit vendu sous licence, c'en est une — la même
que celle que `MARKETPLACES.md` tranche pour le catalogue borghei, mais dans
l'autre sens, faute d'avoir ici une marketplace où renvoyer.

La copie reste donc révocable d'une commande :

```bash
git rm -r .claude/skills/animate
```

**2. Ce skill ne parle pas la langue de ce dépôt.** Il traite de React, Next.js
et Framer Motion. Les deux éditions du CMS sont en Node/Express avec des gabarits
EJS et en PHP, et toutes les animations du produit sont en CSS écrit à la main.
Ce qui s'y applique vraiment : la feuille d'assouplissement, les durées, la règle
des sorties plus rapides que les entrées, et la page
`references/performance-accessibility.md` — c'est-à-dire les parties qui ne
dépendent d'aucune bibliothèque. Tout le reste ne se déclenchera pas ici.

## Ce que contient la copie

Le dépôt amont en entier moins son `README.md`, qui ne décrit que l'installation
par `npx skills add` :

- `SKILL.md` — la fiche, avec son front-matter `name: animate`.
- `examples/` — huit composants React complets.
- `references/` — quatre pages : assouplissement et durées, animations CSS,
  Framer Motion, performance et accessibilité.

Rien n'est exécutable : aucun script, aucun crochet, aucune commande réseau. La
seule adresse citée dans les douze fichiers est celle du dépôt amont lui-même.

## Mettre à jour

```bash
git clone --depth 1 https://github.com/delphi-ai/animate-skill /tmp/animate
# recopier SKILL.md, examples/ et references/, puis noter ici le nouveau commit.
```
