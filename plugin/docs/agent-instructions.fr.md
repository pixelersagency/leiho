# Leiho — instructions pour l'agent qui construit ou intègre le site

## Ton rôle

Tu construis, ou tu adaptes, le front d'un site dont le contenu vit dans WordPress
(champs ACF et champs natifs). Le front peut être écrit avec n'importe quelle technologie :
React, Next.js, Remix, Vite, Astro, Nuxt, SvelteKit, ou du HTML généré.

Leiho donne au client une édition visuelle de ce front depuis wp-admin : il voit son vrai
site dans une iframe, clique sur un texte ou une image, modifie la valeur, voit le
résultat immédiatement, puis enregistre. Pour que ça fonctionne, le front doit respecter
le contrat ci-dessous **dès le premier composant**.

Répartition des rôles :

- **WordPress** possède le contenu : pages, champs ACF, médias, menus.
- **Le front** possède la mise en page, le style, le comportement, et affiche le contenu
  qu'il lit dans WordPress (API REST, WPGraphQL, ou export au build).
- **Leiho** relie les deux : il lit les annotations posées par le front, et écrit les
  modifications du client dans les champs WordPress existants. Il ne stocke aucun contenu
  à lui.

## Règles non négociables

1. Chaque valeur visible par un visiteur a **un seul propriétaire** : un champ WordPress.
   Pas de texte en dur dans les composants, sauf ce qui relève de l'interface (libellés
   d'accessibilité, icônes décoratives).
2. Chaque élément qui affiche un champ porte `data-wp-field` avec le **chemin exact** du
   champ, à l'intérieur d'un conteneur `data-wp-post` qui porte l'ID du contenu.
3. **Ne conditionne jamais le rendu d'un élément annoté à la présence de sa valeur.**
   `{titre && <h2>…</h2>}` est interdit : un élément absent du HTML ne peut pas être
   rempli par l'aperçu, et le client ne peut plus jamais saisir ce champ. Rends
   l'élément vide, masque-le en CSS si nécessaire (`:empty`).
4. Les noms de champs sont **stables**. Renommer un champ ACF déconnecte le contenu déjà
   saisi. Nouveau contenu = nouveau champ.
5. La mise en page ne se modifie jamais depuis un champ : pas de classes, de tailles, de
   couleurs ou de HTML de structure stockés dans ACF.
6. Le site doit fonctionner **sans Leiho** : le bridge ne se charge que dans l'éditeur,
   les annotations sont de simples attributs `data-*` ignorés par les visiteurs.

## Installer le bridge

Le bridge n'est actif que dans l'iframe de l'éditeur. Ne le charge **jamais** pour les
visiteurs : il ne leur sert à rien.

HTML, Astro, Nuxt, SvelteKit, tout site rendu côté serveur — dans le `<head>` du layout :

```html
<script>
  if (window.self !== window.top) {
    var s = document.createElement('script');
    s.src = '/leiho-bridge.js';
    s.setAttribute('data-wp-origin', 'https://ADRESSE-DU-WORDPRESS');
    document.head.appendChild(s);
  }
</script>
```

Copie `leiho-bridge.js` (fourni par le paquet `leiho`, dossier `dist/`) dans le
dossier public du site.

React, Next.js, Remix, Vite — une fois, dans le layout racine :

```jsx
import { LeihoBridge } from 'leiho/react';

export default function RootLayout({ children }) {
  return (
    <>
      <LeihoBridge wpOrigin="https://ADRESSE-DU-WORDPRESS" />
      {children}
    </>
  );
}
```

Autorise ensuite wp-admin à afficher le site dans une iframe, par un en-tête HTTP du front :

```
Content-Security-Policy: frame-ancestors 'self' https://ADRESSE-DU-WORDPRESS
```

Ne pose pas `X-Frame-Options: DENY` ou `SAMEORIGIN` : il bloquerait l'éditeur.

## Annoter les contenus

```jsx
<section data-wp-post={page.id} data-leiho-section="Hero">
  <h1 data-wp-field="hero_titre">{page.acf.hero_titre}</h1>
  <div data-wp-field="hero_intro" data-leiho-apply="html"
       dangerouslySetInnerHTML={{ __html: page.acf.hero_intro }} />
  <img data-wp-field="hero_image" src={page.acf.hero_image.url} alt={page.acf.hero_image.alt} />
</section>
```

| Attribut | Rôle |
|---|---|
| `data-wp-post="12"` | Conteneur : ID du contenu WordPress auquel appartiennent les champs dedans |
| `data-wp-field="chemin"` | Élément qui affiche ce champ |
| `data-leiho-apply="html"` | La valeur contient du balisage (un `<span>` d'accent dans un titre, un wysiwyg) |
| `data-leiho-section="Libellé"` | Nom de la section dans l'arbre de structure de l'éditeur |
| `data-leiho-layout="sections.2"` | La section est la ligne 2 du flexible content `sections` : le client peut l'ajouter, la déplacer, la dupliquer, la supprimer |
| `data-leiho-refresh="save"` | Pas d'aperçu instantané : l'éditeur recharge la page après enregistrement (rendu complexe) |
| `data-leiho-managed="menu"` | Contenu géré ailleurs (menu, liste d'articles, produit WooCommerce) |
| `data-leiho-managed-label="…"` | Texte affiché au client pour ce contenu géré ailleurs |
| `data-leiho-edit-url="…"` | Écran de wp-admin où le modifier (doit être sur l'adresse de wp-admin) |

### Chemins

- Champ simple : `hero_titre`
- Ligne d'un repeater ou d'un flexible content : `cartes.0.titre`, `sections.2.intro`
- Sous-champ d'un group : `coordonnees.telephone`
- Champs natifs : `_title` (titre du contenu), `_thumbnail` (image mise en avant)

Le chemin d'un élément de liste utilise l'**index de rendu** : dans un `map`, construis-le
avec l'index de la boucle, pas avec un identifiant.

```jsx
{page.acf.cartes.map((carte, i) => (
  <article key={i}>
    <h3 data-wp-field={`cartes.${i}.titre`}>{carte.titre}</h3>
  </article>
))}
```

### Un élément = une valeur

Le bridge écrit la valeur dans l'élément annoté. Pose `data-wp-field` sur l'élément qui
contient **directement** la valeur, pas sur un parent qui contient aussi de la mise en
page. Si un titre a besoin d'un mot mis en avant, ne découpe pas le titre en deux champs :
stocke le balisage dans le champ et annote avec `data-leiho-apply="html"`.

### Images

Toute image de contenu vient de la médiathèque WordPress, via un champ image ACF ou
l'image mise en avant. Chaque emplacement d'image a un **format fixé par la mise en page** :

```css
.hero__media { aspect-ratio: 3 / 2; overflow: hidden; }
.hero__media img { width: 100%; height: 100%; object-fit: cover; }
```

Remplacer une image par une autre de format différent ne doit jamais changer la taille du
composant. L'éditeur prévient le client quand l'image choisie sera fortement recadrée.

### Composer une page

Une page que le client doit pouvoir composer (ajouter une FAQ, déplacer un bloc) est un
champ **flexible content** ACF. Chaque layout du flexible correspond à un composant du front.
Annote chaque bloc rendu avec `data-leiho-layout="nom_du_champ.index"` et
`data-leiho-section="Libellé lisible"`.

### Contenu géré ailleurs

Menus WordPress, listes d'articles, produits, données calculées : ne les recopie jamais dans
un champ. Rends-les normalement et entoure le plus petit bloc utile d'une région gérée :

```html
<nav data-leiho-managed="menu" data-leiho-edit-url="https://ADRESSE-DU-WORDPRESS/wp-admin/nav-menus.php">…</nav>
```

## Modéliser le contenu dans ACF

- Un groupe de champs par modèle de page, rattaché par règle d'emplacement.
- Noms de champs en `snake_case` descriptif (`hero_titre`, pas `field_1`).
- Donne une **limite de caractères** aux textes courts qui cassent la mise en page quand ils
  débordent (titres, boutons) : Leiho l'applique dans l'éditeur.
- Marque **requis** ce qui ne peut pas être vide : Leiho le fait respecter à l'enregistrement.
- Un lien avec libellé = champ `link` (URL, texte, nouvel onglet), pas deux champs texte.

## Charger le contenu

Lis le contenu dans l'API de contenu d'Leiho, pas dans l'API REST de WordPress ni dans des
fichiers JSON du dépôt : WordPress est la seule source de vérité.

```js
import { loadLeihoContent, indexLeihoContent } from 'leiho/content';

const { data } = await loadLeihoContent( { url: process.env.LEIHO_URL } );
const contenu = indexLeihoContent( data );
const page = contenu.byPath( Astro.url.pathname );
```

- Une requête par build (`/wp-json/leiho/v1/export`). Charge-la une fois et partage-la entre
  les pages (cache au niveau du module), jamais une fois par page.
- Les champs sont indexés par nom de champ ACF, déjà mis en forme : `fields.hero.image.url`,
  `fields.sections[0]._layout`. N'appelle jamais ACF ni `/wp/v2` pour les lire.
- Génère les types avec `npx leiho types --url $LEIHO_URL` et type les composants avec.
  Régénère-les après chaque changement de groupe de champs.
- Versionne `src/leiho.d.ts`, ignore `.leiho/` (l'instantané est un cache de build).
- API restreinte : le jeton va dans l'environnement de build (`LEIHO_TOKEN`), jamais dans le code.

## Publication

- Site qui lit WordPress **à chaque visite** (rendu serveur à la demande, SPA qui interroge
  l'API) : choisir le mode « Contenu en direct » dans les réglages d'Leiho. Enregistrer suffit.
- Site **généré au build** (Astro statique, export Next) : configurer le déploiement dans les
  réglages (build hook, GitHub Actions, Coolify, commit). Le client clique « Publier ».

## Vérifications avant de livrer

- [ ] Le bridge ne se charge que dans l'iframe (vérifier l'onglet Réseau en navigation normale).
- [ ] L'en-tête `frame-ancestors` autorise l'adresse de wp-admin.
- [ ] Leiho → Démarrer → « Tester la connexion » est vert.
- [ ] Chaque texte, image et lien visible appartient à un champ annoté, ou à une région gérée.
- [ ] Aucun élément annoté n'est rendu conditionnellement à sa valeur.
- [ ] Dans l'éditeur, aucun champ de la page n'affiche « non visible dans l'aperçu » sans raison.
- [ ] Les listes utilisent l'index de boucle dans leurs chemins.
- [ ] Remplacer chaque image par une image carrée, puis panoramique : la mise en page ne bouge pas.
- [ ] Sur une page à flexible content, ajouter / déplacer / supprimer un bloc depuis l'arbre de
      structure, enregistrer : l'aperçu reflète la nouvelle structure.
- [ ] Cliquer un lien interne dans l'aperçu bascule l'éditeur sur la bonne page.
- [ ] Le site public fonctionne avec le plugin Leiho désactivé.
- [ ] Le build ne fait qu'une requête de contenu (`/leiho/v1/export`) et survit à une panne
      de WordPress (instantané dans `.leiho/`).

## À ne jamais faire

- Charger le bridge pour les visiteurs.
- Mettre du texte de contenu en dur dans un composant.
- Rendre un élément annoté seulement si sa valeur existe.
- Renommer un champ ACF qui contient déjà du contenu.
- Découper une valeur en plusieurs champs pour contourner la mise en forme.
- Stocker de la mise en page (classes, tailles, couleurs, HTML de structure) dans un champ.
- Copier dans ACF un contenu déjà géré ailleurs (menu, produit, article).
- Laisser la taille naturelle d'une image décider de la taille d'un composant.
