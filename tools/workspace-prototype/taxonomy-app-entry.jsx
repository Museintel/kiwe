import React from 'react';
import {createRoot} from 'react-dom/client';
import {NativeTaxonomy} from './native-taxonomy.jsx';
createRoot(document.getElementById('kiwe-taxonomy-app')).render(<NativeTaxonomy config={globalThis.KIWE_TAXONOMY_APP}/>);
