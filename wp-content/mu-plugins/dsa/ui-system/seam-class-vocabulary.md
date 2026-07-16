# Seam class vocabulary

This is the searchable class library that Kiwe can push to Bricks from `Kiwe > Framework`.

It is not a recipe system and it is not a starter visual kit.

The class names are neutral handles for designers, developers, Bricks users, and AI tools. They create a shared naming grammar so people can search for `card`, `accordion`, `table`, `hero`, `rail`, `size-xl`, `density-spacious`, `tone-brand`, and similar concepts inside Bricks, then attach their own design to those global classes.

## Rule

- Seam class vocabulary names describe what something is or what design axis it belongs to.
- They do not carry a complete visual style.
- They should be pushed into Bricks as global classes with empty settings unless the class is already a true framework primitive such as a flow, state, motion, or accessibility helper.
- Site CSS or Bricks global-class styles decide the final visual design.
- If a reusable concept is missing, add it generically to this vocabulary. Do not create project-locked names as the first choice.

## Examples

```html
<article class="seam-card seam-size-xl seam-density-spacious seam-emphasis-featured">
```

This means:

- the element is card-like;
- it is intended to be large;
- it has spacious density;
- it is featured.

It does not mean Seam has already decided padding, radius, border, shadow, background, or typography for the card.

```html
<section class="seam-horizontal-rail seam-product-rail seam-gap-md">
```

This means:

- the section is a horizontal rail;
- the content is product-oriented;
- the designer wants medium rhythm.

The actual rail card design remains the site/Bricks/theme author's job.

## Groups

The machine-readable source for the current class library is `seam-class-vocabulary.json`.

The current groups are:

- Flow
- Role Core
- Content
- Commerce
- Navigation
- Disclosure
- Data
- Media
- Form
- Tone
- Size
- Density
- Emphasis
- Scene
- State
- Motion
- Shape
- Placement
- Aspect
- Flow Control
- Utility

## Bricks behavior

When pushed to Bricks, these classes appear as Kiwe Seam global classes/categories. Existing non-Kiwe Bricks classes are not overwritten.

Designers can then:

- search for a class;
- add it to a Bricks element;
- style the global class in Bricks;
- combine semantic classes with variant classes such as size, density, tone, shape, or emphasis.

## AI behavior

AI tools should prefer this vocabulary before inventing new names.

Good:

```html
<div class="seam-accordion seam-density-comfortable">
```

Good:

```html
<article class="seam-story seam-card seam-size-lg">
```

Only invent a new class when:

- the concept is genuinely absent from the vocabulary;
- the class is clearly site-specific art direction;
- or the work proposes a generic vocabulary addition for Kiwe Framework.
