import './native-media.css';
import './native-order.css';
import './native-product-display.css';
import React from 'react';
import {createRoot} from 'react-dom/client';
import {NativeCommerce} from './native-workflow.jsx';
createRoot(document.getElementById('kiwe-commerce-app')).render(<NativeCommerce config={globalThis.KIWE_COMMERCE_APP}/>);
