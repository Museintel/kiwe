import React from 'react';
import {statusPresentation} from './status-presentation.mjs';
import './status-presentation.css';
export function StatusLabel({domain,status,label}){const value=statusPresentation(domain,status,label);return <span className="kiwe-status-label" data-tone={value.tone}><span aria-hidden="true" className="kiwe-status-dot"/>{value.label}</span>;}
