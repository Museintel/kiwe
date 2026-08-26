#!/usr/bin/env node
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';
import {
  diagnoseCommand,
  getCommandManifest,
  getStartEntrypoint,
  planFlow,
  routeCommand,
  validateAccessibility,
  validateBindings,
  validateBricksConversion,
  validateBricksThemeStyle,
  validateFrameworkProfile
} from '../lib/kiwe-core.js';

const server = new Server({ name: 'seam', version: '8.5.0' }, { capabilities: { tools: {} } });
const object = (properties = {}, required = []) => ({ type: 'object', properties, ...(required.length ? { required } : {}) });
const routeProperties = {
  command: { type: 'string' },
  brief: { type: 'string' },
  artifactSummary: { type: 'string' },
  siteGraphSummary: { type: 'string' },
  reportSummary: { type: 'string' },
  attachmentSummary: { type: 'string' }
};
const validationProperties = {
  target: { type: 'string' },
  siteGraphPath: { type: 'string' },
  optional: { type: 'boolean' },
  documented: { type: 'boolean' }
};

const tools = [
  { name: 'seam_get_start', description: 'Return the current strict SEAM entry contract.', inputSchema: object() },
  { name: 'seam_get_manifest', description: 'Return the six-command SEAM manifest.', inputSchema: object() },
  { name: 'seam_diagnose', description: 'Validate one exact SEAM command and its required inputs.', inputSchema: object(routeProperties, ['command']) },
  { name: 'seam_route', description: 'Route one exact SEAM command to its bounded context.', inputSchema: object(routeProperties, ['command']) },
  { name: 'seam_plan', description: 'Inspect attachment evidence and suggest the smallest next SEAM command without executing it.', inputSchema: object({ command: { type: 'string' }, attachmentSummary: { type: 'string' }, artifactSummary: { type: 'string' }, desiredOutcome: { type: 'string' }, brief: { type: 'string' }, reportSummary: { type: 'string' } }) },
  { name: 'seam_validate_bindings', description: 'Validate Bricks/SiteGraph binding metadata.', inputSchema: object(validationProperties, ['target']) },
  { name: 'seam_validate_bricks_conversion', description: 'Validate a native Bricks conversion artifact.', inputSchema: object(validationProperties, ['target']) },
  { name: 'seam_validate_framework_profile', description: 'Validate a Seam Framework Profile.', inputSchema: object(validationProperties, ['target']) },
  { name: 'seam_validate_bricks_theme_style', description: 'Validate Bricks theme-style data.', inputSchema: object(validationProperties, ['target']) },
  { name: 'seam_validate_accessibility', description: 'Validate a deterministic accessibility artifact.', inputSchema: object(validationProperties, ['target']) }
];

server.setRequestHandler(ListToolsRequestSchema, async () => ({ tools }));
server.setRequestHandler(CallToolRequestSchema, async ({ params }) => {
  const args = params.arguments || {};
  let result;
  if (params.name === 'seam_get_start') result = getStartEntrypoint();
  else if (params.name === 'seam_get_manifest') result = getCommandManifest();
  else if (params.name === 'seam_diagnose') result = diagnoseCommand(args);
  else if (params.name === 'seam_route') result = routeCommand(args);
  else if (params.name === 'seam_plan') result = planFlow(args);
  else if (params.name === 'seam_validate_bindings') result = validateBindings(args.target, args);
  else if (params.name === 'seam_validate_bricks_conversion') result = validateBricksConversion(args.target, args);
  else if (params.name === 'seam_validate_framework_profile') result = validateFrameworkProfile(args.target, args);
  else if (params.name === 'seam_validate_bricks_theme_style') result = validateBricksThemeStyle(args.target, args);
  else if (params.name === 'seam_validate_accessibility') result = validateAccessibility(args.target, args);
  else throw new Error(`Unknown SEAM tool: ${params.name}`);
  return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
});

await server.connect(new StdioServerTransport());
