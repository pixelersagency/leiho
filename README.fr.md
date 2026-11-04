<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/leiho-logo-dark.svg">
    <img src=".github/assets/leiho-logo.svg" alt="Leiho" width="260">
  </picture>
</p>

<h1 align="center">Modifiez le vrai site.</h1>


**L'édition visuelle pour WordPress headless.** Vos rédacteurs voient le vrai site (Next.js, Astro, Remix, Vite, Nuxt, SvelteKit ou HTML simple) directement dans wp-admin. Ils cliquent sur un titre, le modifient, voient le changement en direct, enregistrent et publient. Définissez vos types de contenu dans Leiho, ou gardez vos champs ACF : dans les deux cas WordPress reste l'unique source de vérité, sans constructeur de pages ni enfermement.

> *Leiho* veut dire *fenêtre* en basque : une fenêtre sur le vrai site. Leiho s’appelait **Okno** jusqu’à la 1.0.0-beta.11 : voir [Vous veniez d’Okno](#vous-veniez-dokno).

[English](README.md) · [Changelog](CHANGELOG.md) · [Paquet bridge](bridge/) · [Exemples par framework](examples/)

> **État : 1.0 bêta.** L’interface est en français et en anglais (elle suit la langue du site WordPress).

---

## Pourquoi

Le headless donne aux développeurs un front moderne, mais retire aux rédacteurs le « ce que je vois ». Ils remplissent des formulaires à l'aveugle, attendent un build et découvrent le résultat sur le site en ligne. Leiho leur remet le site sous les yeux, sans demander aux développeurs de changer de stack.

- **Compatible avec n'importe quel front.** Un bridge d'environ 10 Ko, sans dépendance, dialogue avec wp-admin par `postMessage`. Les apps React ont un composant d'une ligne.
- **Votre modèle de données, intact.** Leiho lit et écrit les champs ACF existants : repeaters, groupes, contenu flexible, relations, images.
- **Rien de public.** Le bridge n'appelle jamais l'API WordPress et ne détient aucun secret. Chaque message est vérifié par son origine, des deux côtés.
- **Une mise en ligne adaptée à votre hébergement.** Contenu en direct, build hook (Vercel, Netlify, Cloudflare Pages), GitHub Actions avec suivi du build, commit de publication ou Coolify.

## Leiho, c'est le back-office

Un clic sur **Leiho** dans wp-admin et il s'ouvre en plein écran : menu WordPress, barre d'administration, pied de page et notices des autres extensions disparaissent (sur cette page seulement), remplacés par la navigation de Leiho. WordPress + Leiho suffisent, les rédacteurs n'ont jamais besoin d'en sortir.

| | |
|---|---|
| **Accueil** | Dernières modifications (avant / après), état de la connexion et des publications, raccourcis. |
| **Éditeur visuel** | Le site tel que le voient les visiteurs, modifiable sur place. |
| **Collections** | Tous les contenus en trois volets, quel que soit leur moteur : les types à gauche (pages et articles WordPress, types ACF ou du thème, collections et pages uniques Leiho, avec leur nombre d'entrées), les entrées du type choisi au milieu (recherche, filtre par statut, tri, création, duplication, mise en ligne ou retour en brouillon, corbeille et restauration), et l'entrée choisie éditée sur place à droite avec tous ses champs, y compris ceux de WordPress : contenu (éditeur de texte riche avec titres, listes, liens, citations et images de la médiathèque), extrait, adresse (slug), catégories, étiquettes et autres taxonomies publiques (pastilles avec recherche ; une nouvelle étiquette est créée à l'enregistrement), auteur, date de publication, parent et ordre, selon ce que le type prend en charge (annuler / rétablir, brouillons locaux, protection des conflits, historique), son statut, **Enregistrer**, et un lien vers l'éditeur visuel ou la page en ligne quand elle en a une. L'onglet **Champs** d'une collection Leiho ouvre le constructeur de schéma (administrateurs, voir plus bas) ; les types ACF renvoient vers ACF. |
| **Médias** | La médiathèque : grille, recherche, envoi (bouton ou glisser-déposer), texte alternatif. |
| **Publications** | L'historique des déploiements. Le bouton **Publier** de la barre du haut signale les changements pas encore publiés. |
| **Réglages** | Tout ce dont Leiho a besoin (administrateurs). |

Éditeurs et auteurs arrivent dans Leiho dès la connexion (réglage activé par défaut) ; les administrateurs gardent le tableau de bord WordPress et un lien **Retour à WordPress**. La navigation se replie, se pilote au clavier et devient un tiroir sur petit écran ; le thème clair ou sombre est partagé avec l'éditeur et gardé par utilisateur côté serveur : chaque page s'ouvre directement dans le bon thème (sombre par défaut). Les anciennes adresses (`admin.php?page=leiho-settings`, `leiho-editor`…) redirigent vers leur vue.

Un contenu écrit avec l'éditeur de blocs de WordPress n'est jamais réécrit par Leiho : son champ **Contenu** montre un aperçu en lecture seule et un lien **Ouvrir dans l'éditeur WordPress** (le serveur refuse de l'écraser), tous les autres champs de l'entrée restent modifiables. Un contenu classique est enregistré au format de l'éditeur classique.

## Démarrage rapide

### 1. Installer le plugin

[Téléchargez `leiho.zip`](https://github.com/pixelersagency/leiho/releases/latest/download/leiho.zip) (dernière version, aussi listée sur la [page des versions](https://github.com/pixelersagency/leiho/releases)) et téléversez-le dans **Extensions → Ajouter → Téléverser**. Les versions suivantes apparaissent dans **Extensions** comme n’importe quelle mise à jour. Nécessite WordPress 6.0+, PHP 7.4+ et [ACF](https://www.advancedcustomfields.com/) (gratuit ou Pro). Sans ACF, seuls le titre et l'image mise en avant sont modifiables.

### 2. Le relier au site

**Leiho → Réglages** : l'adresse du site, les types de contenu ouverts aux rédacteurs et le modèle d'URL de chacun (`/{slug}` par défaut ; la page d'accueil correspond à `/`).

### 3. Autoriser wp-admin à afficher le site

Le front doit envoyer cet en-tête (**Leiho → Démarrer** l'affiche avec votre domaine et le vérifie pour vous) :

```
Content-Security-Policy: frame-ancestors 'self' https://votre-wordpress.fr
```

### 4. Installer le bridge et annoter le balisage

**React (Next.js, Remix, Vite…)**

```bash
npm install leiho
```

```jsx
// Layout racine : app/layout.tsx, root.tsx, App.tsx…
import { LeihoBridge } from 'leiho/react';

<LeihoBridge wpOrigin="https://votre-wordpress.fr" />
```

**Autres sites (Astro, Nuxt, SvelteKit, HTML)** : copiez `leiho-bridge.js` (téléchargeable depuis **Leiho → Démarrer**) dans le dossier public, puis dans le `<head>` :

```html
<script>
  // Ne charge le bridge que dans l'éditeur : ~150 octets pour les visiteurs.
  if (window.self !== window.top) {
    var s = document.createElement('script');
    s.src = '/leiho-bridge.js';
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

Dans wp-admin, **Leiho → Démarrer → Tester la connexion** confirme que le bridge répond. Les intégrations pas à pas par framework sont dans [`examples/`](examples/).

> **Vous travaillez avec un agent de code ?** **Leiho → Démarrer** fournit des prompts prêts à coller et le contrat d'intégration complet ([`plugin/docs/agent-instructions.md`](plugin/docs/agent-instructions.md)) pour Claude Code, Cursor, Codex, etc.

## Annotations

Dans un framework JavaScript, laissez le paquet `leiho` écrire les attributs : `field()`, `block()` et `entry()` renvoient un objet à étaler sur l’élément (JSX, Astro, Svelte ; `v-bind` en Vue).

```jsx
import { field, block } from 'leiho';

<h1 {...field(page, 'hero_titre')}>{page.fields.hero_titre}</h1>
<section {...block(page, 'sections', i, 'Hero')}>…</section>
```

`field(page, 'intro', { html: true })` ajoute `data-leiho-apply="html"` ; `{ refresh: true }` ajoute `data-leiho-refresh="save"`. En HTML ou dans des gabarits serveur, écrivez à la main les attributs ci-dessous.

| Attribut | Rôle |
|---|---|
| `data-wp-post="12"` | Conteneur : ID du contenu WordPress des champs qu'il contient |
| `data-wp-field="hero_titre"` | Élément modifiable : nom du champ ACF, ou `_title` / `_thumbnail` |
| `data-wp-field="cartes.0.titre"` | Les chemins pointés atteignent repeaters, contenu flexible et groupes |
| `data-leiho-apply="html"` | La valeur contient du balisage : injectée en HTML filtré plutôt qu'en texte |
| `data-leiho-refresh="save"` | Pas d'aperçu en direct ; la page est rechargée après l'enregistrement |
| `data-leiho-section="Hero"` | Nomme une section dans l'arbre de structure |
| `data-leiho-layout="sections.2"` | Lie une section à une ligne de contenu flexible : ajout, déplacement, duplication, suppression |
| `data-leiho-managed="menu"` | Contenu géré ailleurs (menus, listes d'articles, WooCommerce) : un clic explique où le modifier |
| `data-leiho-edit-url="…"` | L'écran wp-admin où modifier ce contenu géré |

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

Leiho sert aussi le contenu au front : WordPress reste la seule source de vérité, le dépôt du front ne garde que le code.

```ts
import { loadLeihoContent, indexLeihoContent } from 'leiho/content';

const { data } = await loadLeihoContent( { url: 'https://cms.exemple.fr' } );
const contenu = indexLeihoContent( data );
contenu.byPath( '/services/audit' );
```

- `GET /wp-json/leiho/v1/export` : tout le contenu publié en une réponse, mis en cache, avec ETag (un build sans changement reçoit un 304). Une requête par build au lieu d'une par page : la base ne sature plus sur un mutualisé.
- Instantané : chaque chargement réussi est écrit dans `.leiho/content.json` ; si WordPress ne répond pas, le build continue avec le dernier contenu connu et le signale.
- Types : `npx leiho types --url https://cms.exemple.fr` écrit `src/leiho.d.ts`, généré depuis les groupes de champs ACF.
- Accès public par défaut (contenu publié seulement), ou restreint par jeton (`LEIHO_TOKEN`).
- Publication automatique après chaque modification, en option.
- À la première installation, WordPress est durci pour le headless : pas d'indexation du CMS, pages publiques redirigées vers le front, XML-RPC coupé, liste des utilisateurs fermée aux visiteurs.

Détails dans le [README anglais](README.md#content-api).

## Collections

Leiho Collections est un modèle de contenu intégré à Leiho, sans ACF : des **collections** (Services, Articles : plusieurs entrées) et des **singletons** (Accueil, Contact, Réglages du site : une seule entrée).

- **Chaque entrée est un contenu WordPress** (un type de contenu par collection) : rôles, brouillons, corbeille, révisions, médiathèque, extensions SEO et multilingues continuent de fonctionner.
- **Les valeurs des champs vivent dans une table par collection** (`wp_leiho_<collection>`), pas dans les métas : colonnes typées et indexées pour les champs simples, JSON pour les listes, blocs et groupes. MySQL 5.7+, MariaDB et SQLite.
- **Schéma versionné** : export et import d'un fichier `leiho.schema.json`, à garder dans le dépôt du front.
- **Migrations sûres** : renommer un champ ne coûte rien (clé stable), un changement de type copie les valeurs dans une nouvelle colonne, les colonnes retirées sont archivées, jamais supprimées. `dry_run` montre le plan avant.
- **Révisions** : restaurer une révision restaure aussi les champs.
- **Même éditeur, même API** que pour ACF : chemins pointés (`sections.2.items.1.title`), `/leiho/v1/export`, `/content` et `/types`.
- **Constructeur de schéma** dans Leiho → **Collections** : collections et pages uniques, libellés, modèle d'URL, fonctionnalités, et un éditeur de champs avec les 19 types et leurs options (choix, min / max, cibles des références, sous-champs des listes et groupes, types de blocs), réordonnés par glisser-déposer ou au clavier. La clé d'un champ est générée à partir de son nom et figée une fois appliquée : seuls le nom et le libellé changent ensuite. **Vérifier** lance la migration à blanc et liste ce qui va se passer (nouvelles colonnes, conversions, colonnes archivées) avant de rien appliquer ; les erreurs pointent le champ concerné. Colonnes archivées listées avec un bouton de purge (confirmée en tapant l'identifiant), import et export d'`leiho.schema.json` en un clic. Les entrées s'éditent ensuite dans Leiho → **Collections** (et dans l'éditeur visuel quand la collection a une URL). **+ Nouvelle collection**, en bas de la liste des types, en crée une.

### Importer depuis ACF

Un site ACF passe à Leiho Collections un type de contenu à la fois. Dans Leiho → **Collections**, un type ACF propose **Importer dans Leiho** (administrateurs) :

1. **Aperçu** : les groupes de champs ACF rattachés au type, ce que devient chaque champ dans Leiho (`text` → Texte, `repeater` → Liste, `flexible_content` → Blocs, `relationship` → Référence…) et ce qui n'est pas repris (champs sans équivalent comme `page_link`, `taxonomy` ou `user`, logique conditionnelle, valeurs par défaut, restrictions de médias, règles d'emplacement autres que le type, options de répéteur d'ACF Pro), avec le nombre d'entrées, de champs et de valeurs stockées.
2. **Import** : Leiho crée la collection avec les mêmes noms d'API (camelCase et tirets compris), puis copie les entrées par lots en passant par la validation de l'éditeur, avec une barre de progression. Une valeur refusée n'arrête pas l'import : elle est listée avec un lien vers l'entrée. Chaque entrée est ensuite vérifiée : le JSON servi par Leiho est comparé, champ par champ, à celui que servait ACF, et chaque écart est listé.
3. Le type est alors **servi par Leiho** : `/export`, `/content` et `/types` donnent le même JSON qu'avant (objets médias, références, lignes de répéteurs, `_layout` des blocs, texte riche rendu, choix, dates, nombres, booléens), et les chemins pointés de l'éditeur visuel fonctionnent toujours. Les groupes ACF restent en place mais ne s'appliquent plus à ce type.
4. **Revenir à ACF** (réglages de la collection) sert de nouveau les valeurs ACF et supprime la table Leiho de la collection.

Rien n'est jamais supprimé côté ACF : ni métas, ni groupe de champs, ni type de contenu. Les valeurs modifiées dans Leiho après l'import ne sont **pas** réécrites dans ACF : revenir à ACF rétablit les valeurs du jour de l'import. Un type déclaré par le thème ou une extension y reste déclaré (Leiho ne reprend que ses champs) ; un type déclaré dans l'écran **Post Types** d'ACF est enregistré par Leiho avec les mêmes réglages si ACF est désactivé. Les pages et articles ne peuvent pas devenir des collections : leurs champs ACF restent dans ACF.

API REST (administrateurs) : `GET /leiho/v1/collections/import-acf` (types et état), `GET …/import-acf/preview?post_type=ville`, `POST …/import-acf/run` (`{ "post_type": "ville" }`, un lot par appel jusqu'à `status: done`, reprise possible), `GET …/import-acf/status?post_type=ville`, `POST …/import-acf/rollback` (`{ "post_type": "ville", "confirm": "ville" }`). WP-CLI : `wp leiho acf-import list|preview|run|rollback`.

Format et API : [plugin/docs/collections-spec.md](plugin/docs/collections-spec.md) (en anglais).

## IA (WordPress 7.0+)

Leiho écrit avec le fournisseur d’IA configuré dans **Réglages → Connecteurs**, par le client d’IA de WordPress (`wp_ai_client_prompt()`). Leiho ne stocke aucune clé d’API et ne choisit aucun fournisseur ; sans WordPress 7.0, ou sans fournisseur capable de générer du texte, les outils d’IA n’apparaissent pas (les administrateurs ont un lien vers les connecteurs).

- **Contexte de marque** (Réglages, administrateurs) : nom, offre, public, ton, mots à privilégier et à éviter, **formulations interdites**, faits obligatoires, langue et jusqu’à trois textes d’exemple. Export / import en JSON. Chaque collection peut préciser à quoi elle sert et pour qui.
- **IA sur les champs texte** (formulaire et éditeur visuel) : Générer, Réécrire, Raccourcir, Développer, Corriger, Changer le ton, Traduire, et *Proposer* un titre ou une description SEO (champs SEO, extrait). Le contexte de marque, celui de la collection et les autres champs de l’entrée sont envoyés. La proposition s’ouvre avec les différences mot à mot ; les formulations interdites sont surlignées et un texte trop long est signalé avant d’accepter. Accepter écrit dans le champ comme une saisie : Annuler fonctionne, rien n’est enregistré avant « Enregistrer ».
- **Texte alternatif** des images (champs image, Médias), si le fournisseur lit les images.
- **Nouvelle entrée avec l’IA** dans Collections : un brief, une réponse JSON structurée selon les champs du type (texte, texte riche, listes, sections), un aperçu, puis un brouillon.
- **Capacités** pour les agents externes (adaptateur MCP, agents WordPress), catégorie `leiho` : `leiho/get-brand-context`, `leiho/list-collections`, `leiho/get-entry`, `leiho/update-entry-fields` (brouillons et entrées en attente seulement, validées comme dans l’éditeur, ne publie jamais).

## Vous veniez d’Okno

Okno s’appelle **Leiho** depuis la 1.0.0-beta.12, parce qu’un autre projet portait déjà ce nom. Contenus, réglages et front sont repris.

1. Sauvegardez la base de données.
2. Téléversez `leiho.zip` dans **Extensions → Ajouter → Téléverser**, désactivez Okno, puis activez Leiho. Si les deux sont actifs, Leiho attend et vous demande de désactiver Okno.
3. Leiho reprend les réglages, les jetons, les types de contenu et leurs valeurs, l’historique et les préférences de chaque utilisateur, puis le confirme dans wp-admin. Vous pouvez ensuite supprimer Okno : sa désinstallation ne touche pas aux données de Leiho.

Mettez d’abord le CMS à jour. Le front continue de fonctionner sans changement jusqu’à Leiho 2.0 : les routes `okno/v1`, l’en-tête `X-Okno-Token`, les attributs `data-okno-*`, le bridge d’Okno, `OKNO_URL` / `OKNO_TOKEN` et l’instantané `.okno/` sont encore compris. Le tableau de correspondance des noms est dans le [README anglais](README.md#coming-from-okno).

## Licence

[GPL-2.0-or-later](LICENSE). Réalisé par [Pixelers](https://pixelers.fr).

## Crédits / tiers

La mise en page et les styles de l'application Leiho (vue Collections en trois volets, lignes de liste, réglages des champs, boîtes de dialogue) sont en partie dérivés de [Stacki](https://github.com/flowtricks/stacki), Copyright (c) 2026 Timothy Ricks, sous licence MIT : voir [plugin/licenses/stacki-MIT.txt](plugin/licenses/stacki-MIT.txt). Les fichiers concernés le signalent dans leur en-tête.
