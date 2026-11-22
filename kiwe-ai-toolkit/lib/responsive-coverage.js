/** A bounded test plan, not a claim that a browser was run or a design is sound. */
export function responsiveCoveragePlan(sources = [], { minimum = 320, maximum = 1440, limit = 64 } = {}) {
  if (!Number.isFinite(minimum) || !Number.isFinite(maximum)) throw Error('Finite responsive limits required.');
  const min = Math.max(240, Math.ceil(minimum)), max = Math.min(10000, Math.floor(maximum));
  if (min >= max || !Number.isInteger(limit) || limit < 8 || limit > 128) throw Error('Bounded responsive range required.');
  const boundaries = new Set(), unsupported = new Set();
  for (const source of sources) {
    const css = String(source).replace(/\/\*[\s\S]*?\*\//g, '');
    for (const match of css.matchAll(/@media\s*([^{}]+)\{/gi)) {
      const query = match[1];
      const admitted = [...query.matchAll(/\b(?:min|max)-width\s*:\s*(\d+(?:\.\d+)?)px\b/gi)];
      for (const item of admitted) { const width = Number(item[1]); if (width > min && width < max) boundaries.add(width); }
      if (/\b(?:width|inline-size)\b/i.test(query) && (!admitted.length || /\b(?:min|max)-width\s*:\s*[^,)]+(?:rem|em|vw|var\()/i.test(query))) unsupported.add(query.trim());
    }
    if (/@container\b/i.test(css)) unsupported.add('Container queries require container-size checks in their rendered parent.');
  }
  const points = new Map(), add = (width, reason) => {
    if (width < min || width > max) return;
    const reasons = points.get(width) || []; if (!reasons.includes(reason)) reasons.push(reason); points.set(width, reasons);
  };
  for (const width of [min, 390, 768, 846, 1024, max]) add(width, 'representative-width');
  const edges = [min, ...[...boundaries].sort((a,b)=>a-b), max];
  for (let i = 0; i < edges.length; i++) {
    if (i > 0 && i < edges.length - 1) for (const width of [Math.floor(edges[i])-1, Math.ceil(edges[i]), Math.floor(edges[i])+1]) add(width, 'media-boundary');
    if (i < edges.length - 1 && edges[i+1] - edges[i] > 2) add(Math.round((edges[i]+edges[i+1])/2), 'interval-interior');
  }
  const all = [...points].sort(([a],[b])=>a-b).map(([width,reasons])=>({width,reasons}));
  return { schema:'kiwe.responsive-coverage.v1', minimum:min, maximum:max, boundaries:[...boundaries].sort((a,b)=>a-b), probes:all.slice(0,limit), complete:all.length<=limit && !unsupported.size, omitted:Math.max(0,all.length-limit), requiresReview:[...unsupported], browserExecuted:false };
}
