// WordPress owns React in the native build. The standalone prototype still uses npm React.
const element=window.wp.element;
export const {createElement,createRoot,useState,useEffect,useRef}=element;
export default element;
