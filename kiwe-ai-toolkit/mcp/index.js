#!/usr/bin/env node
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';
import { createHandoff, getContext, getDynamicContext, listClassVocabulary, listModes, startDynamicPass, startProject, validateBindings, validateHandoff } from '../lib/kiwe-core.js';

const server = new Server(
  { name: 'kiwe', version: '0.1.0' },
  { capabilities: { tools: {} } }
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
  tools: [
    {
      name: 'kiwe_start_project',
      description: 'Start a Kiwe project from a plain-language human brief. Returns the correct compact context and output contract so the human prompt can stay short.',
      inputSchema: {
        type: 'object',
        properties: {
          mode: { type: 'string', enum: ['auto', 'website', 'theme', 'combined'], default: 'auto' },
          brief: { type: 'string', description: 'Plain-language human design brief.' },
          name: { type: 'string', description: 'Optional handoff/project name.' }
        },
        required: ['brief']
      }
    },
    {
      name: 'kiwe_list_modes',
      description: 'List Kiwe output modes: website, theme, combined.',
      inputSchema: { type: 'object', properties: {} }
    },
    {
      name: 'kiwe_get_context',
      description: 'Return compact mode-specific Kiwe context. Use instead of reading the full plugin codebase.',
      inputSchema: {
        type: 'object',
        properties: { mode: { type: 'string', enum: ['website', 'theme', 'combined'] } },
        required: ['mode']
      }
    },
    {
      name: 'kiwe_create_handoff',
      description: 'Create a scaffolded Kiwe handoff folder for website, theme, or combined work.',
      inputSchema: {
        type: 'object',
        properties: {
          mode: { type: 'string', enum: ['website', 'theme', 'combined'] },
          outputDir: { type: 'string' },
          name: { type: 'string' },
          brief: { type: 'string' }
        },
        required: ['mode', 'outputDir']
      }
    },
    {
      name: 'kiwe_validate_handoff',
      description: 'Validate basic Kiwe handoff structure.',
      inputSchema: {
        type: 'object',
        properties: {
          mode: { type: 'string', enum: ['website', 'theme', 'combined'] },
          targetDir: { type: 'string' }
        },
        required: ['mode', 'targetDir']
      }
    },
    {
      name: 'kiwe_validate_bindings',
      description: 'Validate a Kiwe Bricks dynamic binding plan, optionally against a supplied target-site Site Graph JSON file.',
      inputSchema: {
        type: 'object',
        properties: {
          targetDir: { type: 'string', description: 'Handoff folder, bricks-bindings folder, or kiwe-bindings.json path.' },
          siteGraphPath: { type: 'string', description: 'Optional path to kiwe.site-graph.v1 JSON for deep validation.' },
          optional: { type: 'boolean', description: 'If true, missing binding plan is informational instead of failing.' }
        },
        required: ['targetDir']
      }
    },
    {
      name: 'kiwe_list_class_vocabulary',
      description: 'Return Seam Class Vocabulary groups/classes for Bricks/global-class authoring.',
      inputSchema: { type: 'object', properties: {} }
    },
    {
      name: 'kiwe_get_dynamic_context',
      description: 'Return Kiwe dynamic binding context for revising a passed handoff with WordPress/Bricks/Woo query loops and dynamic data using a target Site Graph.',
      inputSchema: { type: 'object', properties: {} }
    },
    {
      name: 'kiwe_start_dynamic_pass',
      description: 'Start a v5-style dynamic binding pass from a plain-language request, current handoff summary, and Site Graph summary.',
      inputSchema: {
        type: 'object',
        properties: {
          brief: { type: 'string', description: 'Plain-language dynamic binding request.' },
          siteGraphSummary: { type: 'string', description: 'Short summary of the supplied kiwe.site-graph.v1 JSON.' },
          currentHandoffSummary: { type: 'string', description: 'Short summary of the current handoff being revised.' }
        },
        required: ['brief']
      }
    }
  ]
}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const args = request.params.arguments || {};
  let result;
  switch (request.params.name) {
    case 'kiwe_start_project':
      result = startProject(args);
      break;
    case 'kiwe_list_modes':
      result = listModes();
      break;
    case 'kiwe_get_context':
      result = getContext(args.mode);
      break;
    case 'kiwe_create_handoff':
      result = createHandoff(args);
      break;
    case 'kiwe_validate_handoff':
      result = validateHandoff(args.targetDir, args.mode);
      break;
    case 'kiwe_validate_bindings':
      result = validateBindings(args.targetDir, { siteGraphPath: args.siteGraphPath || '', optional: Boolean(args.optional) });
      break;
    case 'kiwe_list_class_vocabulary':
      result = listClassVocabulary();
      break;
    case 'kiwe_get_dynamic_context':
      result = getDynamicContext();
      break;
    case 'kiwe_start_dynamic_pass':
      result = startDynamicPass(args);
      break;
    default:
      throw new Error(`Unknown tool: ${request.params.name}`);
  }
  return {
    content: [{ type: 'text', text: typeof result === 'string' ? result : JSON.stringify(result, null, 2) }],
    structuredContent: typeof result === 'string' ? { text: result } : result
  };
});

const transport = new StdioServerTransport();
await server.connect(transport);
