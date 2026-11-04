# Okno

**L'édition visuelle pour WordPress headless.** Vos rédacteurs voient le vrai site (Next.js, Astro, Remix, Vite, Nuxt, SvelteKit ou HTML simple) directement dans wp-admin. Ils cliquent sur un titre, le modifient, voient le changement en direct, enregistrent et publient. Vos champs ACF restent l'unique source de vérité : pas de nouveau modèle de contenu, pas de constructeur de pages, pas d'enfermement.

> *Okno* veut dire *fenêtre* en polonais, tchèque et russe : une fenêtre sur le vrai site.

[English](README.md) · [Changelog](CHANGELOG.md) · [Paquet bridge](bridge/) · [Exemples par framework](examples/)

> **État : 1.0 bêta.** L’interface est en français et en anglais (elle suit la langue du site WordPress).

---

## Pourquoi

Le headless donne aux développeurs un front moderne, mais retire aux rédacteurs le « ce que je vois ». Ils remplissent des formulaires à l'aveugle, attendent un build et découvrent le résultat sur le site en ligne. Okno leur remet le site sous les yeux, sans demander aux développeurs de changer de stack.

- **Compatible avec n'importe quel front.** Un bridge d'environ 10 Ko, sans dépendance, dialogue avec wp-admin par `postMessage`. Les apps React ont un composant d'une ligne.
- **Votre modèle de données, intact.** Okno lit et écrit les champs ACF existants : repeaters, groupes, contenu flexible, relations, images.
- **Rien de public.** Le bridge n'appelle jamais l'API WordPress et ne détient aucun secret. Chaque message est vérifié par son origine, des deux côtés.
- **Une mise en ligne adaptée à votre hébergement.** Contenu en direct, build hook (Vercel, Netlify, Cloudflare Pages), GitHub Actions avec suivi du build, commit de publication ou Coolify.

## Démarrage rapide

### 1. Installer le plugin

[Téléchargez `okno.zip`](https://github.com/pixelersagency/okno/releases/latest/download/okno.zip) (dernière version, aussi listée sur la [page des versions](https://github.com/pixelersagency/okno/releases)) et téléversez-le dans **Extensions → Ajouter → Téléverser**. Les versions suivantes apparaissent dans **Extensions** comme n’importe quelle mise à jour. Nécessite WordPress 6.0+, PHP 7.4+ et [ACF](https://www.advancedcustomfields.com/) (gratuit ou Pro). Sans ACF, seuls le titre et l'image mise en avant sont modifiables.

### 2. Le relier au site

**Okno → Réglages** : l'adresse du site, les types de contenu ouverts aux rédacteurs et le modèle d'URL de chacun (`/{slug}` par défaut ; la page d'accueil correspond à `/`).

### 3. Autoriser wp-admin à afficher le site

Le front doit envoyer cet en-tête (**Okno → Démarrer** l'affiche avec votre domaine et le vérifie pour vous) :

```
Content-Security-Policy: frame-ancestors 'self' https://votre-wordpress.fr
```

### 4. Installer le bridge et annoter le balisage

**React (Next.js, Remix, Vite…)**

```bash
npm install @pixelersagency/okno-bridge
```

```jsx
// Layout racine : app/layout.tsx, root.tsx, App.tsx…
import { OknoBridge } from '@pixelersagency/okno-bridge/react';

<OknoBridge wpOrigin="https://votre-wordpress.fr" />
```

**Autres sites (Astro, Nuxt, SvelteKit, HTML)** : copiez `okno-bridge.js` (téléchargeable depuis **Okno → Démarrer**) dans le dossier public, puis dans le `<head>` :

```html
<script>
  // Ne charge le bridge que dans l'éditeur : ~150 octets pour les visiteurs.
  if (window.self !== window.top) {
    var s = document.createElement('script');
    s.src = '/okno-bridge.js';
    s.setAttribute('data-wp-origin', 'https://votre-wordpress.fr');
    document.head.appendChild(s);
  }
</script>
```

Annotez ensuite ce qui doit être modifiable :

```html
<main data-wp-post="12">
  <h1 data-wp-field="_title">À propos</h1>
  <p data-wp-field="intro">Nous fabriquons des choses.</p>
  <img data-wp-field="hero_image" src="/hero.jpg" alt="" />
</main>
```

Dans wp-admin, **Okno → Démarrer → Tester la connexion** confirme que le bridge répond. Les intégrations pas à pas par framework sont dans [`examples/`](examples/).

> **Vous travaillez avec un agent de code ?** **Okno → Démarrer** fournit des prompts prêts à coller et le contrat d'intégration complet ([`plugin/docs/agent-instructions.md`](plugin/docs/agent-instructions.md)) pour Claude Code, Cursor, Codex, etc.

## Annotations

| Attribut | Rôle |
|---|---|
| `data-wp-post="12"` | Conteneur : ID du contenu WordPress des champs qu'il contient |
| `data-wp-field="hero_titre"` | Élément modifiable : nom du champ ACF, ou `_title` / `_thumbnail` |
| `data-wp-field="cartes.0.titre"` | Les chemins pointés atteignent repeaters, contenu flexible et groupes |
| `data-okno-apply="html"` | La valeur contient du balisage : injectée en HTML filtré plutôt qu'en texte |
| `data-okno-refresh="save"` | Pas d'aperçu en direct ; la page est rechargée après l'enregistrement |
| `data-okno-section="Hero"` | Nomme une section dans l'arbre de structure |
| `data-okno-layout="sections.2"` | Lie une section à une ligne de contenu flexible : ajout, déplacement, duplication, suppression |
| `data-okno-managed="menu"` | Contenu géré ailleurs (menus, listes d'articles, WooCommerce) : un clic explique où le modifier |
| `data-okno-edit-url="…"` | L'écran wp-admin où modifier ce contenu géré |

**Une seule règle :** affichez toujours un élément annoté, même quand sa valeur est vide. `{titre && <h2 data-wp-field="titre">…}` empêche l'édition en direct : un élément absent de la page ne peut pas être rempli.

## Modes de mise en ligne

| Mode | Pour | Ce que fait « Publier » |
|---|---|---|
| **Contenu en direct** | Sites qui lisent WordPress à chaque visite (SSR, ISR) | Rien : enregistrer suffit, le bouton disparaît |
| **Build hook** | Vercel, Netlify, Cloudflare Pages, toute URL de rebuild | Appelle l'URL |
| **GitHub Actions** | Builds statiques lancés par Actions | Déclenche un workflow et suit le build en direct |
| **Commit GitHub** | Hébergeurs qui déploient à chaque push | Pousse un commit vide sur la branche |
| **Coolify** | Coolify auto-hébergé | Appelle le webhook de déploiement |

La documentation complète (champs pris en charge, sécurité, extensions, développement) est dans le [README anglais](README.md).

## API de contenu

Okno sert aussi le contenu au front : WordPress reste la seule source de vérité, le dépôt du front ne garde que le code.

```ts
import { loadOknoContent, indexOknoContent } from '@pixelersagency/okno-bridge/content';

const { data } = await loadOknoContent( { url: 'https://cms.exemple.fr' } );
const contenu = indexOknoContent( data );
contenu.byPath( '/services/audit' );
```

- `GET /wp-json/okno/v1/export` : tout le contenu publié en une réponse, mis en cache, avec ETag (un build sans changement reçoit un 304). Une requête par build au lieu d'une par page : la base ne sature plus sur un mutualisé.
- Instantané : chaque chargement réussi est écrit dans `.okno/content.json` ; si WordPress ne répond pas, le build continue avec le dernier contenu connu et le signale.
- Types : `npx okno types --url https://cms.exemple.fr` écrit `src/okno.d.ts`, généré depuis les groupes de champs ACF.
- Accès public par défaut (contenu publié seulement), ou restreint par jeton (`OKNO_TOKEN`).
- Publication automatique après chaque modification, en option.
- À la première installation, WordPress est durci pour le headless : pas d'indexation du CMS, pages publiques redirigées vers le front, XML-RPC coupé, liste des utilisateurs fermée aux visiteurs.

Détails dans le [README anglais](README.md#content-api).

## Licence

[GPL-2.0-or-later](LICENSE). Réalisé par [Pixelers](https://pixelers.fr).
