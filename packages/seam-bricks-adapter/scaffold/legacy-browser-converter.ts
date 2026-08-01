/**
 * LEGACY SCAFFOLD ONLY.
 *
 * Preserved from the former browser converter for regression archaeology and
 * mapping extraction. The supported compiler never imports this module: it
 * must compile rendered evidence through typed SEAM IR and a capability-proven
 * Bricks plan. Random IDs and DOM-only CSS parsing make this unsuitable for
 * deterministic production output.
 */
export const LEGACY_BROWSER_CONVERTER_STATUS = "unsupported-production-scaffold" as const;

export type BricksValue = string | number | boolean | null | BricksValue[] | { [key: string]: BricksValue };

export type BricksElement = {
  id: string;
  name: string;
  parent: string | 0;
  children: string[];
  settings: Record<string, BricksValue>;
  label?: string;
  selectors?: BricksSelector[];
};

export type BricksSelector = {
  id: string;
  selector: string;
  settings: Record<string, BricksValue>;
  label?: string;
};

export type BricksGlobalClass = {
  id: string;
  name: string;
  settings: Record<string, BricksValue>;
  selectors?: BricksSelector[];
};

export type ConversionReport = {
  elements: number;
  nativeElements: number;
  globalClasses: number;
  globalVariables: number;
  cssRules: number;
  nativeDeclarations: number;
  customCssDeclarations: number;
  interactions: number;
  conditions: number;
  dynamicBindings: number;
  queryLoops: number;
  codeElements: number;
  unsupportedScripts: number;
  coverage: number;
  elementTypes: Record<string, number>;
  residualProperties: Record<string, number>;
  warnings: string[];
};

export type ConversionResult = {
  template: Record<string, unknown>;
  content: BricksElement[];
  report: ConversionReport;
};

const BREAKPOINTS = {
  tablet_portrait: 991,
  mobile_landscape: 767,
  mobile_portrait: 478,
} as const;

const nativeHandledProperties = new Set([
  "align-content", "align-items", "align-self", "aspect-ratio", "background", "background-attachment",
  "background-color", "background-image", "background-position", "background-position-x", "background-position-y", "background-repeat", "background-size",
  "border", "border-bottom", "border-bottom-color", "border-bottom-left-radius", "border-bottom-right-radius",
  "border-bottom-style", "border-bottom-width", "border-color", "border-left", "border-left-color",
  "border-left-style", "border-left-width", "border-radius", "border-right", "border-right-color",
  "border-right-style", "border-right-width", "border-style", "border-top", "border-top-color",
  "border-top-left-radius", "border-top-right-radius", "border-top-style", "border-top-width", "border-width",
  "bottom", "box-shadow", "box-sizing", "color", "content", "cursor", "display", "flex", "flex-basis",
  "flex-direction", "flex-grow", "flex-shrink", "flex-wrap", "font", "font-family", "font-size", "font-style",
  "font-weight", "gap", "column-gap", "row-gap", "grid-auto-columns", "grid-auto-flow", "grid-auto-rows", "grid-column", "grid-column-end", "grid-column-start", "grid-row", "grid-row-end", "grid-row-start",
  "grid-template-columns", "grid-template-rows", "height", "isolation", "justify-content", "justify-items", "justify-self", "left",
  "letter-spacing", "line-height", "margin", "margin-block", "margin-block-end", "margin-block-start", "margin-bottom", "margin-inline", "margin-inline-end", "margin-inline-start", "margin-left",
  "margin-right", "margin-top", "max-height", "max-width", "min-height", "min-width", "mix-blend-mode",
  "object-fit", "object-position", "opacity", "order", "overflow", "overflow-x", "overflow-y", "padding",
  "padding-block", "padding-block-end", "padding-block-start", "padding-bottom", "padding-inline", "padding-inline-end", "padding-inline-start", "padding-left", "padding-right", "padding-top", "perspective",
  "perspective-origin", "place-items", "pointer-events", "position", "right", "scroll-snap-align", "scroll-snap-type", "text-align",
  "text-decoration", "text-decoration-color", "text-decoration-line", "text-decoration-style", "text-decoration-thickness", "text-transform", "top", "transform", "transform-origin", "transition", "transition-behavior", "transition-delay", "transition-duration", "transition-property", "transition-timing-function", "visibility", "width",
  "z-index",
]);

const ignoredProperties = new Set(["box-sizing"]);

const dynamicTagMap: Record<string, string> = {
  "post.title": "{post_title}",
  "post.url": "{post_url}",
  "post.excerpt": "{post_excerpt}",
  "post.content": "{post_content}",
  "post.featured_image": "{featured_image}",
  "user.id": "{wp_user_id}",
  "user.name": "{wp_user_display_name}",
  "site.title": "{site_title}",
  "site.url": "{site_url}",
};

function hash(input: string): string {
  let value = 2166136261;
  for (let index = 0; index < input.length; index += 1) {
    value ^= input.charCodeAt(index);
    value = Math.imul(value, 16777619);
  }
  return (value >>> 0).toString(36).padStart(6, "0").slice(0, 6);
}

function uid(seed: string): string {
  return hash(`${seed}:${Math.random().toString(36).slice(2)}:${Date.now()}`);
}

function deterministicId(seed: string): string {
  return hash(`seam-legacy:${seed}`);
}

function cleanText(value: string): string {
  return value.replace(/\s+/g, " ").trim();
}

function dynamicText(value: string, report: ConversionReport): string {
  let output = value;
  output = output.replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (match, key: string) => {
    const tag = dynamicTagMap[key];
    if (!tag) return match;
    report.dynamicBindings += 1;
    return tag;
  });
  return output;
}

function splitTopLevel(value: string, delimiter = ","): string[] {
  const items: string[] = [];
  let depth = 0;
  let quote = "";
  let start = 0;
  for (let index = 0; index < value.length; index += 1) {
    const character = value[index];
    if (quote) {
      if (character === quote && value[index - 1] !== "\\") quote = "";
      continue;
    }
    if (character === '"' || character === "'") quote = character;
    else if (character === "(") depth += 1;
    else if (character === ")") depth = Math.max(0, depth - 1);
    else if (character === delimiter && depth === 0) {
      items.push(value.slice(start, index).trim());
      start = index + 1;
    }
  }
  items.push(value.slice(start).trim());
  return items.filter(Boolean);
}

function normalizeLength(value: string): string {
  const trimmed = value.trim();
  const px = trimmed.match(/^(-?(?:\d+|\d*\.\d+))px$/i);
  return px ? px[1] : trimmed;
}

function colorValue(value: string): Record<string, BricksValue> {
  const trimmed = value.trim();
  if (/^#[\da-f]{3,8}$/i.test(trimmed)) return { hex: trimmed };
  return { raw: trimmed };
}

function spacing(style: CSSStyleDeclaration, prefix: "margin" | "padding"): Record<string, BricksValue> | null {
  const logical = style as CSSStyleDeclaration & Record<string, string>;
  const result = {
    top: normalizeLength(style.getPropertyValue(`${prefix}-top`) || logical[`${prefix}BlockStart`] || ""),
    right: normalizeLength(style.getPropertyValue(`${prefix}-right`) || logical[`${prefix}InlineEnd`] || ""),
    bottom: normalizeLength(style.getPropertyValue(`${prefix}-bottom`) || logical[`${prefix}BlockEnd`] || ""),
    left: normalizeLength(style.getPropertyValue(`${prefix}-left`) || logical[`${prefix}InlineStart`] || ""),
  };
  return Object.values(result).some(Boolean) ? result : null;
}

function parseGradient(value: string, seed: string): Record<string, BricksValue> | null {
  const match = value.trim().match(/^(repeating-)?(linear|radial|conic)-gradient\(([\s\S]*)\)$/i);
  if (!match) return null;
  const [, repeating, kind, inside] = match;
  const parts = splitTopLevel(inside);
  const gradient: Record<string, BricksValue> = {
    gradientType: kind,
    colors: [],
  };
  if (repeating) gradient.repeat = true;

  if (kind === "linear" && /(?:deg|turn|rad|to\s)/i.test(parts[0] || "")) {
    const direction = parts.shift() || "";
    const degree = direction.match(/(-?[\d.]+)deg/i);
    gradient.angle = degree ? degree[1] : direction;
  }
  if (kind === "radial" && parts[0] && /\bat\b|circle|ellipse|closest|farthest/i.test(parts[0])) {
    const descriptor = parts.shift() || "";
    const at = descriptor.split(/\s+at\s+/i);
    gradient.radialPosition = at[1] || "center";
    gradient.radialShape = /ellipse/i.test(at[0]) ? "ellipse" : "circle";
  }
  if (kind === "conic" && parts[0] && /\bfrom\b|\bat\b/i.test(parts[0])) {
    const descriptor = parts.shift() || "";
    const angle = descriptor.match(/from\s+(-?[\d.]+)deg/i);
    const position = descriptor.match(/at\s+(.+)$/i);
    if (angle) gradient.conicAngle = angle[1];
    if (position) gradient.conicPosition = position[1];
  }

  gradient.colors = parts.map((part, index) => {
    const stopMatch = part.match(/^(.*?)(?:\s+(-?[\d.]+)%?)?$/);
    const rawColor = (stopMatch?.[1] || part).trim();
    const item: Record<string, BricksValue> = {
      id: deterministicId(`${seed}:color:${index}`),
      color: colorValue(rawColor),
    };
    if (stopMatch?.[2]) item.stop = stopMatch[2];
    return item;
  });
  return gradient;
}

function parseShadow(value: string): Record<string, BricksValue> | null {
  const first = splitTopLevel(value)[0];
  if (!first || first === "none") return null;
  const inset = /\binset\b/i.test(first);
  const colorMatch = first.match(/(rgba?\([^)]*\)|hsla?\([^)]*\)|#[\da-f]{3,8}|\btransparent\b)/i);
  const withoutColor = first.replace(/\binset\b/i, "").replace(colorMatch?.[0] || "", "").trim();
  const lengths = withoutColor.split(/\s+/).map(normalizeLength);
  if (lengths.length < 2) return null;
  return {
    values: {
      offsetX: lengths[0],
      offsetY: lengths[1],
      blur: lengths[2] || "0",
      spread: lengths[3] || "0",
      ...(inset ? { inset: true } : {}),
    },
    color: colorValue(colorMatch?.[0] || "rgba(0,0,0,.15)"),
  };
}

function parseTransform(value: string): Record<string, BricksValue> | null {
  if (!value || value === "none") return null;
  const transform: Record<string, BricksValue> = {};
  const patterns: Array<[RegExp, string[]]> = [
    [/translate3d\(([^,]+),([^,]+),([^)]+)\)/i, ["translateX", "translateY", "translateZ"]],
    [/translate\(([^,]+),([^)]+)\)/i, ["translateX", "translateY"]],
    [/translateX\(([^)]+)\)/i, ["translateX"]],
    [/translateY\(([^)]+)\)/i, ["translateY"]],
    [/scale3d\(([^,]+),([^,]+),([^)]+)\)/i, ["scaleX", "scaleY", "scaleZ"]],
    [/scale\(([^,()]+)(?:,([^)]+))?\)/i, ["scaleX", "scaleY"]],
    [/rotate\(([^)]+)\)/i, ["rotateZ"]],
    [/rotateX\(([^)]+)\)/i, ["rotateX"]],
    [/rotateY\(([^)]+)\)/i, ["rotateY"]],
    [/skewX\(([^)]+)\)/i, ["skewX"]],
    [/skewY\(([^)]+)\)/i, ["skewY"]],
  ];
  for (const [pattern, keys] of patterns) {
    const match = value.match(pattern);
    if (!match) continue;
    keys.forEach((key, index) => {
      const found = match[index + 1] || (key === "scaleY" ? match[1] : "");
      if (found) transform[key] = normalizeLength(found.trim().replace(/deg$/i, ""));
    });
  }
  return Object.keys(transform).length ? transform : null;
}

function breakpointFor(condition: string): keyof typeof BREAKPOINTS | null {
  const max = condition.match(/max-width\s*:\s*([\d.]+)px/i);
  if (!max) return null;
  const width = Number(max[1]);
  if (width <= 478) return "mobile_portrait";
  if (width <= 780) return "mobile_landscape";
  return "tablet_portrait";
}

function setSetting(settings: Record<string, BricksValue>, key: string, value: BricksValue | null | undefined, suffix: string) {
  if (value === null || value === undefined || value === "") return;
  settings[`${key}${suffix}`] = value;
}

function mergeSettings(target: Record<string, BricksValue>, incoming: Record<string, BricksValue>) {
  for (const [key, value] of Object.entries(incoming)) {
    const current = target[key];
    if (
      current && value
      && typeof current === "object" && !Array.isArray(current)
      && typeof value === "object" && !Array.isArray(value)
    ) {
      mergeSettings(current as Record<string, BricksValue>, value as Record<string, BricksValue>);
    } else {
      target[key] = value;
    }
  }
}

function declarationSettings(
  source: CSSStyleDeclaration,
  suffix: string,
  seed: string,
  report: ConversionReport,
  customCssRoot = "%root%",
): Record<string, BricksValue> {
  const style = document.createElement("div").style;
  style.cssText = source.cssText;
  const settings: Record<string, BricksValue> = {};

  setSetting(settings, "_display", style.display, suffix);
  setSetting(settings, "_visibility", style.visibility, suffix);
  setSetting(settings, "_position", style.position, suffix);
  setSetting(settings, "_top", normalizeLength(style.top), suffix);
  setSetting(settings, "_right", normalizeLength(style.right), suffix);
  setSetting(settings, "_bottom", normalizeLength(style.bottom), suffix);
  setSetting(settings, "_left", normalizeLength(style.left), suffix);
  setSetting(settings, "_zIndex", style.zIndex, suffix);
  setSetting(settings, "_width", normalizeLength(style.width), suffix);
  setSetting(settings, "_widthMin", normalizeLength(style.minWidth), suffix);
  setSetting(settings, "_widthMax", normalizeLength(style.maxWidth), suffix);
  setSetting(settings, "_height", normalizeLength(style.height), suffix);
  setSetting(settings, "_heightMin", normalizeLength(style.minHeight), suffix);
  setSetting(settings, "_heightMax", normalizeLength(style.maxHeight), suffix);
  setSetting(settings, "_aspectRatio", style.aspectRatio, suffix);
  const overflow = style.overflow || (style.overflowX && style.overflowY && style.overflowX !== style.overflowY
    ? `${style.overflowX} ${style.overflowY}`
    : style.overflowX || style.overflowY);
  setSetting(settings, "_overflow", overflow, suffix);
  setSetting(settings, "_opacity", style.opacity, suffix);
  setSetting(settings, "_cursor", style.cursor, suffix);
  setSetting(settings, "_isolation", style.isolation, suffix);
  setSetting(settings, "_mixBlendMode", style.mixBlendMode, suffix);
  setSetting(settings, "_pointerEvents", style.pointerEvents, suffix);
  setSetting(settings, "_perspective", normalizeLength(style.perspective), suffix);
  setSetting(settings, "_perspectiveOrigin", style.perspectiveOrigin, suffix);
  setSetting(settings, "_order", style.order, suffix);

  const margin = spacing(style, "margin");
  const padding = spacing(style, "padding");
  setSetting(settings, "_margin", margin, suffix);
  setSetting(settings, "_padding", padding, suffix);

  setSetting(settings, "_flexWrap", style.flexWrap, suffix);
  setSetting(settings, "_direction", style.flexDirection, suffix);
  setSetting(settings, "_alignSelf", style.alignSelf, suffix);
  setSetting(settings, "_justifyContent", style.justifyContent, suffix);
  setSetting(settings, "_alignItems", style.alignItems, suffix);
  setSetting(settings, "_columnGap", normalizeLength(style.columnGap), suffix);
  setSetting(settings, "_rowGap", normalizeLength(style.rowGap), suffix);
  setSetting(settings, "_flexGrow", style.flexGrow, suffix);
  setSetting(settings, "_flexShrink", style.flexShrink, suffix);
  setSetting(settings, "_flexBasis", normalizeLength(style.flexBasis), suffix);

  setSetting(settings, "_gridTemplateColumns", style.gridTemplateColumns, suffix);
  setSetting(settings, "_gridTemplateRows", style.gridTemplateRows, suffix);
  setSetting(settings, "_gridAutoColumns", style.gridAutoColumns, suffix);
  setSetting(settings, "_gridAutoRows", style.gridAutoRows, suffix);
  setSetting(settings, "_gridAutoFlow", style.gridAutoFlow, suffix);
  setSetting(settings, "_justifyItemsGrid", style.justifyItems, suffix);
  setSetting(settings, "_alignItemsGrid", style.alignItems, suffix);
  setSetting(settings, "_justifyContentGrid", style.justifyContent, suffix);
  setSetting(settings, "_alignContentGrid", style.alignContent, suffix);
  if (/^span\s+\d+$/i.test(style.gridColumn)) {
    setSetting(settings, "_gridItemColumnSpan", style.gridColumn.match(/\d+/)?.[0], suffix);
  }
  if (/^span\s+\d+$/i.test(style.gridRow)) {
    setSetting(settings, "_gridItemRowSpan", style.gridRow.match(/\d+/)?.[0], suffix);
  }
  setSetting(settings, "_gridItemJustifySelf", style.justifySelf, suffix);

  const typography: Record<string, BricksValue> = {};
  if (style.fontFamily) {
    const primaryFamily = splitTopLevel(style.fontFamily)[0]?.replace(/^['"]|['"]$/g, "").trim();
    if (primaryFamily && !/^(inherit|initial|revert|unset)$/i.test(primaryFamily)) typography["font-family"] = primaryFamily;
  }
  if (style.fontSize) typography["font-size"] = normalizeLength(style.fontSize);
  if (style.fontWeight) typography["font-weight"] = style.fontWeight;
  if (style.fontStyle) typography["font-style"] = style.fontStyle;
  if (style.lineHeight) typography["line-height"] = normalizeLength(style.lineHeight);
  if (style.letterSpacing) typography["letter-spacing"] = normalizeLength(style.letterSpacing);
  if (style.textAlign) typography["text-align"] = style.textAlign;
  if (style.textTransform) typography["text-transform"] = style.textTransform;
  if (style.textDecoration) typography["text-decoration"] = style.textDecoration;
  if (style.color) typography.color = colorValue(style.color);
  setSetting(settings, "_typography", Object.keys(typography).length ? typography : null, suffix);

  const background: Record<string, BricksValue> = {};
  const backgroundShorthand = source.getPropertyValue("background").trim();
  const backgroundImageValue = style.backgroundImage
    || source.getPropertyValue("background-image").trim()
    || (/gradient\(|url\(/i.test(backgroundShorthand) ? backgroundShorthand : "");
  if (style.backgroundColor && style.backgroundColor !== "transparent") background.color = colorValue(style.backgroundColor);
  else if (backgroundShorthand && !/gradient\(|url\(/i.test(backgroundShorthand)) background.color = colorValue(backgroundShorthand);
  if (backgroundImageValue && backgroundImageValue !== "none" && !/gradient\(/i.test(backgroundImageValue)) {
    const url = backgroundImageValue.match(/url\(["']?(.*?)["']?\)/i)?.[1];
    if (url) background.image = { url, full: url, size: "full", filename: url.split("/").pop() || "background" };
  }
  if (style.backgroundPosition) background.position = style.backgroundPosition;
  if (style.backgroundRepeat) background.repeat = style.backgroundRepeat;
  if (style.backgroundSize) background.size = style.backgroundSize;
  if (style.backgroundAttachment) background.attachment = style.backgroundAttachment;
  setSetting(settings, "_background", Object.keys(background).length ? background : null, suffix);

  const gradient = parseGradient(backgroundImageValue, `${seed}${suffix}`);
  setSetting(settings, "_gradient", gradient, suffix);

  const border: Record<string, BricksValue> = {};
  const borderWidths = {
    top: normalizeLength(style.borderTopWidth), right: normalizeLength(style.borderRightWidth),
    bottom: normalizeLength(style.borderBottomWidth), left: normalizeLength(style.borderLeftWidth),
  };
  const radii = {
    top: normalizeLength(style.borderTopLeftRadius), right: normalizeLength(style.borderTopRightRadius),
    bottom: normalizeLength(style.borderBottomRightRadius), left: normalizeLength(style.borderBottomLeftRadius),
  };
  if (Object.values(borderWidths).some(Boolean)) border.width = borderWidths;
  if (style.borderTopStyle && style.borderTopStyle !== "none") border.style = style.borderTopStyle;
  if (style.borderTopColor) border.color = colorValue(style.borderTopColor);
  if (Object.values(radii).some(Boolean)) border.radius = radii;
  setSetting(settings, "_border", Object.keys(border).length ? border : null, suffix);

  setSetting(settings, "_boxShadow", parseShadow(style.boxShadow), suffix);
  setSetting(settings, "_transform", parseTransform(style.transform), suffix);
  setSetting(settings, "_transformOrigin", style.transformOrigin, suffix);
  setSetting(settings, "_cssTransition", style.transition, suffix);
  setSetting(settings, "_scrollSnapType", style.scrollSnapType, suffix);
  setSetting(settings, "_scrollSnapAlign", style.scrollSnapAlign, suffix);
  setSetting(settings, "_objectFit", style.objectFit, suffix);
  setSetting(settings, "_objectPosition", style.objectPosition, suffix);
  if (style.content) setSetting(settings, "_content", style.content.replace(/^['"]|['"]$/g, ""), suffix);

  const residual: string[] = [];
  let emittedWhiteSpace = false;
  for (const property of Array.from(source)) {
    if (property.startsWith("--") || nativeHandledProperties.has(property) || ignoredProperties.has(property)) continue;
    const value = source.getPropertyValue(property).trim();
    if (!value) continue;

    // CSSOM expands several authored shorthands into implementation longhands. Collapse
    // those back to the authored semantic declaration before deciding that custom CSS is needed.
    if ((property === "white-space-collapse" || property === "text-wrap-mode") && style.whiteSpace) {
      if (!emittedWhiteSpace) {
        residual.push(`white-space: ${style.whiteSpace};`);
        report.residualProperties["white-space"] = (report.residualProperties["white-space"] || 0) + 1;
        report.customCssDeclarations += 1;
        emittedWhiteSpace = true;
      }
      continue;
    }
    if (property.startsWith("font-") && style.getPropertyValue("font")) continue;
    if (property.startsWith("border-image-") && style.borderImageSource === "none") continue;
    if ((property === "background-origin" || property === "background-clip") && style.getPropertyValue("background")) continue;
    residual.push(`${property}: ${value}${source.getPropertyPriority(property) ? " !important" : ""};`);
    report.residualProperties[property] = (report.residualProperties[property] || 0) + 1;
    report.customCssDeclarations += 1;
  }
  if (residual.length) settings[`_cssCustom${suffix}`] = `${customCssRoot} { ${residual.join(" ")} }`;

  const nativeCount = Array.from(source).filter((property) => nativeHandledProperties.has(property)).length;
  report.nativeDeclarations += nativeCount;
  return settings;
}

function selectorParts(selector: string): string[] {
  return splitTopLevel(selector);
}

function normalizeSelector(selector: string, rootClass: string): string {
  let normalized = selector.trim();
  normalized = normalized.replace(/:where\(([^)]+)\)/g, "$1");
  normalized = normalized.replace(new RegExp(`^(?:html\\s+|body\\s+)*\\.${rootClass.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}\\s*`), "");
  normalized = normalized.replace(/^(?:html|body)\s*/i, "");
  return normalized.trim() || `.${rootClass}`;
}

function firstClass(selector: string, fallback: string): string {
  const match = selector.match(/\.([_a-zA-Z]+[\w-]*)/);
  return match?.[1] || fallback;
}

function selectorForClass(selector: string, className: string): string {
  const pattern = new RegExp(`\\.${className.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}(?![\\w-])`);
  const replaced = selector.replace(pattern, "&").trim();
  return replaced || "&";
}

function parseJsonAttribute<T>(element: Element, name: string): T | null {
  const value = element.getAttribute(name);
  if (!value) return null;
  try {
    return JSON.parse(value) as T;
  } catch {
    return null;
  }
}

function linkSetting(element: Element): Record<string, BricksValue> | null {
  const href = element.getAttribute("href");
  if (!href) return null;
  const value: Record<string, BricksValue> = {
    type: href.startsWith("#") ? "external" : "external",
    url: href,
  };
  if (element.getAttribute("target") === "_blank") value.newTab = true;
  if (element.getAttribute("rel")) value.rel = element.getAttribute("rel") || "";
  if (element.getAttribute("title")) value.title = element.getAttribute("title") || "";
  if (element.getAttribute("aria-label")) value.ariaLabel = element.getAttribute("aria-label") || "";
  return value;
}

function detectIcon(element: Element): Record<string, BricksValue> | null {
  const label = (element.getAttribute("aria-label") || "").toLowerCase();
  const path = element.querySelector("svg path")?.getAttribute("d") || "";
  const icons: Array<[RegExp, string]> = [
    [/save|heart|20\.8 4\.6/, "fas fa-heart"],
    [/search/, "fas fa-magnifying-glass"],
    [/menu/, "fas fa-bars"],
    [/cart|bag/, "fas fa-bag-shopping"],
    [/user|profile|account/, "fas fa-user"],
    [/home/, "fas fa-house"],
    [/bell|notification/, "fas fa-bell"],
    [/arrow/, "fas fa-arrow-right-long"],
  ];
  const match = icons.find(([pattern]) => pattern.test(`${label} ${path}`));
  return match ? { icon: match[1], library: "fontawesomeSolid" } : null;
}

function elementType(element: Element): string {
  const override = element.getAttribute("data-bricks-element");
  if (override) return override;
  const tag = element.tagName.toLowerCase();
  if (/^h[1-6]$/.test(tag)) return "heading";
  if (tag === "img" || tag === "picture") return "image";
  if (tag === "video" || tag === "iframe") return "video";
  if (tag === "button") return "button";
  if (tag === "a" && element.children.length === 0) return "text-link";
  if (["p", "span", "strong", "em", "small", "label", "time", "figcaption"].includes(tag) && element.children.length === 0) return "text-basic";
  if (tag === "section") return "section";
  return "div";
}

function elementSettings(element: Element, type: string, report: ConversionReport): Record<string, BricksValue> {
  const tag = element.tagName.toLowerCase();
  const settings: Record<string, BricksValue> = {};
  const text = dynamicText(cleanText(element.textContent || ""), report);

  if (type === "heading") {
    settings.text = text;
    settings.tag = tag;
  } else if (type === "text-basic") {
    settings.text = text;
    if (tag !== "p") settings.tag = tag;
  } else if (type === "text-link") {
    settings.text = text;
    const link = linkSetting(element);
    if (link) settings.link = link;
  } else if (type === "button") {
    settings.text = text.replace(/^\+$/, "+") || " ";
    const icon = detectIcon(element);
    if (icon) settings.icon = icon;
    const link = linkSetting(element);
    if (link) settings.link = link;
  } else if (type === "image") {
    const image = tag === "picture" ? element.querySelector("img") : element;
    const src = image?.getAttribute("src") || "";
    settings.image = {
      url: src,
      full: src,
      size: "full",
      filename: src.startsWith("data:") ? "embedded-image.svg" : src.split("/").pop()?.split("?")[0] || "image",
    };
    settings.tag = "figure";
    settings.caption = "none";
    if (image?.getAttribute("alt")) settings.altText = image.getAttribute("alt") || "";
    if (image?.getAttribute("loading")) settings.loading = image.getAttribute("loading") || "";
  } else if (type === "video") {
    const src = element.getAttribute("src") || element.querySelector("source")?.getAttribute("src") || "";
    if (/youtu(?:\.be|be\.com)/i.test(src)) {
      settings.videoType = "youtube";
      settings.youTubeId = src.match(/(?:v=|youtu\.be\/|embed\/)([\w-]+)/)?.[1] || src;
    } else if (/vimeo/i.test(src)) {
      settings.videoType = "vimeo";
      settings.vimeoId = src.match(/vimeo\.com\/(?:video\/)?(\d+)/)?.[1] || src;
    } else {
      settings.videoType = "file";
      settings.fileUrl = src;
      if (element.hasAttribute("autoplay")) settings.fileAutoplay = true;
      if (element.hasAttribute("loop")) settings.fileLoop = true;
      if (element.hasAttribute("muted")) settings.fileMute = true;
      if (element.hasAttribute("controls")) settings.fileControls = true;
    }
  } else {
    if (!["div", "section"].includes(tag)) settings.tag = tag;
    const link = linkSetting(element);
    if (link) settings.link = link;
  }

  const classes = Array.from(element.classList);
  if (classes.length) {
    settings._cssGlobalClasses = classes.map((className) => deterministicId(`class:${className}`));
  }
  if (element.id) settings._cssId = element.id;

  const inlineStyle = element.getAttribute("style");
  if (inlineStyle) {
    const declaration = document.createElement("div").style;
    declaration.cssText = inlineStyle;
    Object.assign(settings, declarationSettings(declaration, "", `inline:${element.id || tag}`, report));
  }

  const conditions = parseJsonAttribute<BricksValue[][]>(element, "data-bricks-conditions");
  if (conditions) {
    settings._conditions = conditions;
    report.conditions += 1;
  }
  const interactions = parseJsonAttribute<BricksValue[]>(element, "data-bricks-interactions");
  if (interactions) {
    settings._interactions = interactions;
    report.interactions += interactions.length;
  }
  const query = parseJsonAttribute<Record<string, BricksValue>>(element, "data-bricks-query");
  if (query) {
    settings.hasLoop = true;
    settings.query = query;
    report.queryLoops += 1;
  }
  const dynamic = element.getAttribute("data-bricks-dynamic");
  if (dynamic && ["heading", "text-basic", "text-link", "button"].includes(type)) {
    settings.text = dynamic;
    report.dynamicBindings += 1;
  }

  const skipped = new Set([
    "class", "id", "style", "href", "src", "srcset", "sizes", "alt", "loading", "target", "rel", "title",
    "data-bricks-element", "data-bricks-conditions", "data-bricks-interactions", "data-bricks-query", "data-bricks-dynamic",
  ]);
  const attributes = Array.from(element.attributes)
    .filter((attribute) => !skipped.has(attribute.name) && !attribute.name.startsWith("on"))
    .map((attribute, index) => ({ id: deterministicId(`attr:${tag}:${attribute.name}:${index}`), name: attribute.name, value: attribute.value }));
  if (attributes.length) settings._attributes = attributes;
  return settings;
}

function safeLabel(element: Element, type: string): string {
  const classes = Array.from(element.classList);
  const preferred = classes.find((name) => !/^(active|current|open|is-|has-)/.test(name));
  return (preferred || element.id || type).replace(/[-_]+/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase()).slice(0, 64);
}

export async function convertHtmlToBricks(html: string, templateTitle = "Converted page"): Promise<ConversionResult> {
  const report: ConversionReport = {
    elements: 0,
    nativeElements: 0,
    globalClasses: 0,
    globalVariables: 0,
    cssRules: 0,
    nativeDeclarations: 0,
    customCssDeclarations: 0,
    interactions: 0,
    conditions: 0,
    dynamicBindings: 0,
    queryLoops: 0,
    codeElements: 0,
    unsupportedScripts: 0,
    coverage: 100,
    elementTypes: {},
    residualProperties: {},
    warnings: [],
  };

  const parser = new DOMParser();
  const source = parser.parseFromString(html, "text/html");
  const scripts = Array.from(source.querySelectorAll("script"));
  const inlineHandlers = Array.from(source.querySelectorAll("*")).reduce(
    (count, element) => count + Array.from(element.attributes).filter((attribute) => attribute.name.startsWith("on")).length,
    0,
  );
  report.unsupportedScripts = scripts.length + inlineHandlers;
  if (report.unsupportedScripts) {
    report.warnings.push(`${report.unsupportedScripts} script or inline event block(s) were not executed. Add data-bricks-interactions annotations for native interaction conversion.`);
  }
  scripts.forEach((script) => script.remove());

  const styleText = Array.from(source.querySelectorAll("style")).map((style) => style.textContent || "").join("\n");
  source.querySelectorAll("style, link[rel='stylesheet'], noscript, meta, title").forEach((element) => element.remove());

  const roots = Array.from(source.body.children).filter((element) => element.tagName.toLowerCase() !== "script");
  if (!roots.length) throw new Error("No renderable elements were found in the pasted HTML.");
  const rootClass = Array.from(roots[0].classList)[0] || "seam-legacy-root";

  const classNames = new Set<string>();
  source.querySelectorAll("[class]").forEach((element) => element.classList.forEach((className) => classNames.add(className)));
  classNames.add(rootClass);

  const globalClassMap = new Map<string, BricksGlobalClass>();
  const ensureClass = (name: string) => {
    if (!globalClassMap.has(name)) {
      globalClassMap.set(name, { id: deterministicId(`class:${name}`), name, settings: {} });
    }
    return globalClassMap.get(name)!;
  };
  classNames.forEach(ensureClass);

  const variables = new Map<string, { id: string; name: string; value: string }>();
  const processStyleRule = (rule: CSSStyleRule, breakpoint: keyof typeof BREAKPOINTS | null) => {
    const suffix = breakpoint ? `:${breakpoint}` : "";
    for (const property of Array.from(rule.style)) {
      if (!property.startsWith("--")) continue;
      const name = property.slice(2);
      variables.set(name, { id: deterministicId(`variable:${name}`), name, value: rule.style.getPropertyValue(property).trim() });
    }

    for (const rawSelector of selectorParts(rule.selectorText)) {
      const normalized = normalizeSelector(rawSelector, rootClass);
      const scope = firstClass(normalized, rootClass);
      const globalClass = ensureClass(scope);
      const scopedSelector = selectorForClass(normalized, scope);
      const customCssRoot = scopedSelector.replace(/&/g, `.${scope}`);
      const settings = declarationSettings(rule.style, suffix, `${scope}:${scopedSelector}`, report, customCssRoot);
      if (!Object.keys(settings).length) continue;
      if (scopedSelector === "&") {
        mergeSettings(globalClass.settings, settings);
      } else {
        globalClass.selectors ||= [];
        let selectorEntry = globalClass.selectors.find((entry) => entry.selector === scopedSelector);
        if (!selectorEntry) {
          selectorEntry = {
            id: deterministicId(`selector:${scope}:${scopedSelector}`),
            selector: scopedSelector,
            settings: {},
            label: scopedSelector.replace(/^&\s*/, "").slice(0, 48),
          };
          globalClass.selectors.push(selectorEntry);
        }
        mergeSettings(selectorEntry.settings, settings);
      }
      report.cssRules += 1;
    }
  };

  if (styleText.trim()) {
    try {
      const sheet = new CSSStyleSheet();
      sheet.replaceSync(styleText);
      const walkRules = (rules: CSSRuleList, inheritedBreakpoint: keyof typeof BREAKPOINTS | null = null) => {
        for (const rule of Array.from(rules)) {
          if (rule instanceof CSSStyleRule) processStyleRule(rule, inheritedBreakpoint);
          else if (rule instanceof CSSMediaRule) walkRules(rule.cssRules, breakpointFor(rule.conditionText) || inheritedBreakpoint);
          else if ("cssRules" in rule) walkRules((rule as CSSGroupingRule).cssRules, inheritedBreakpoint);
        }
      };
      walkRules(sheet.cssRules);
    } catch (error) {
      report.warnings.push(`A stylesheet could not be parsed: ${error instanceof Error ? error.message : "unknown CSS error"}`);
    }
  }

  const elements: BricksElement[] = [];
  const appendElement = (element: Element, parent: string | 0): string | null => {
    const tag = element.tagName.toLowerCase();
    if (["script", "style", "link", "meta", "title", "noscript"].includes(tag)) return null;

    if (tag === "svg") {
      const src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(element.outerHTML)}`;
      const placeholder = source.createElement("img");
      placeholder.setAttribute("src", src);
      placeholder.setAttribute("alt", element.getAttribute("aria-label") || "Decorative graphic");
      element.classList.forEach((className) => placeholder.classList.add(className));
      return appendElement(placeholder, parent);
    }

    const type = elementType(element);
    const id = uid(`${tag}:${elements.length}`);
    const node: BricksElement = {
      id,
      name: type,
      parent,
      children: [],
      settings: elementSettings(element, type, report),
      label: safeLabel(element, type),
    };
    elements.push(node);
    report.elementTypes[type] = (report.elementTypes[type] || 0) + 1;

    const nestable = ["section", "div", "block", "container"].includes(type);
    if (nestable) {
      for (const child of Array.from(element.children)) {
        const childId = appendElement(child, id);
        if (childId) node.children.push(childId);
      }

      const textNodes = Array.from(element.childNodes).filter((child) => child.nodeType === Node.TEXT_NODE && cleanText(child.textContent || ""));
      for (const textNode of textNodes) {
        const textId = uid(`text:${elements.length}`);
        const textElement: BricksElement = {
          id: textId,
          name: "text-basic",
          parent: id,
          children: [],
          settings: { text: dynamicText(cleanText(textNode.textContent || ""), report), tag: "span" },
          label: "Inline text",
        };
        elements.push(textElement);
        node.children.unshift(textId);
        report.elementTypes["text-basic"] = (report.elementTypes["text-basic"] || 0) + 1;
      }
    }
    return id;
  };

  roots.forEach((root) => appendElement(root, 0));

  report.elements = elements.length;
  report.nativeElements = elements.length;
  report.globalClasses = globalClassMap.size;
  report.globalVariables = variables.size;
  const totalDeclarations = report.nativeDeclarations + report.customCssDeclarations;
  report.coverage = totalDeclarations ? Math.round((report.nativeDeclarations / totalDeclarations) * 1000) / 10 : 100;
  if (report.customCssDeclarations) {
    report.warnings.push(`${report.customCssDeclarations} declaration(s) use scoped custom CSS because Bricks 2.3 has no equivalent native control.`);
  }

  const now = new Date();
  const template = {
    title: templateTitle,
    name: templateTitle.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""),
    date: now.toISOString().slice(0, 19).replace("T", " "),
    date_formatted: now.toLocaleDateString("en-GB", { year: "numeric", month: "long", day: "numeric" }),
    author: { name: "SEAM Compiler legacy scaffold", avatar: "", url: "" },
    type: "content",
    templateType: "content",
    bundles: [],
    tags: ["SEAM Compiler legacy scaffold", "HTML conversion"],
    content: elements,
    global_classes: Array.from(globalClassMap.values()),
    globalVariables: Array.from(variables.values()),
    pageSettings: {},
    generator: { name: "SEAM Compiler legacy scaffold", bricksVersion: "2.3.10", schemaVersion: "2.3" },
  };

  return { template, content: elements, report };
}

export function safePreviewHtml(html: string): string {
  const parser = new DOMParser();
  const documentValue = parser.parseFromString(html, "text/html");
  documentValue.querySelectorAll("script, object, embed").forEach((element) => element.remove());
  documentValue.querySelectorAll("*").forEach((element) => {
    Array.from(element.attributes).forEach((attribute) => {
      if (attribute.name.startsWith("on")) element.removeAttribute(attribute.name);
    });
  });
  return `<!doctype html>${documentValue.documentElement.outerHTML}`;
}
