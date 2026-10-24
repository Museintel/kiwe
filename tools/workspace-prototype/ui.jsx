import React, {useEffect,useRef,useState,useId} from 'react';
import './tile-workspace.css';
function Tile({ color, kind = '', title, children, onClick, label }) {
  return <button className={`live-tile ${color} ${kind}`} onClick={onClick} aria-label={label || title}><span className="live-tile-label">{title}<span aria-hidden="true">↗</span></span>{children}</button>;
}

function Sheet({ title, children, onClose, wide = false }) {
  const dialog = useRef(null);
  const titleId = useId();
  useEffect(() => { const previous = document.activeElement; dialog.current.showModal(); return () => previous?.focus(); }, []);
  return <dialog ref={dialog} className={"tile-sheet" + (wide ? " wide-sheet" : "")} aria-labelledby={titleId} onCancel={event => { event.preventDefault(); onClose(); }}><div className="tile-sheet-heading"><h2 id={titleId}>{title}</h2><button type="button" aria-label="Close editor" onClick={onClose}>×</button></div>{children}</dialog>;
}

function ImageChoice({ name, value, square, onChange }) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  async function choose(event) {
    const input = event.currentTarget, file = input.files?.[0];
    if (!file) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) { setError('Choose a PNG, JPEG or WebP image up to 2 MB.'); input.value = ''; return; }
    setError(''); setBusy(true);
    try {
      const data = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(file); });
      const img = new Image(); img.src = data; await img.decode();
      if (square && img.naturalHeight !== img.naturalWidth) { setError('Choose a square image for the browser icon.'); return; }
      onChange(data);
    } catch { setError('This image could not be opened.'); }
    finally { setBusy(false); input.value = ''; }
  }
  return <div className="tile-image-choice"><div className={'tile-image-stage' + (name.includes('dark') ? ' dark-stage' : '')}>{value ? <img src={value} alt={name + ' preview'} /> : <span aria-hidden="true">+</span>}</div><label className="tile-file">{busy ? 'Opening image…' : value ? 'Change image' : 'Choose image'}<input aria-label={name} type="file" accept="image/png,image/jpeg,image/webp" disabled={busy} onChange={choose} /></label>{value && <button className="text-button" type="button" onClick={() => onChange('')}>Remove image</button>}{error && <p role="alert">{error}</p>}</div>;
}


export {Tile,Sheet,ImageChoice};
