#!/usr/bin/env node
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';
import { createHandoff, getContext, listClassVocabulary, listModes, validateHandoff } from '../lib/kiwe-core.js';

const server = new Server(
  { name: 'kiwe', version: '0.1.0' },
  { capabilities: { tools: {} } }
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
  tools: [
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
      name: 'kiwe_list_class_vocabulary',
      description: 'Return Seam Class Vocabulary groups/classes for Bricks/global-class authoring.',
      inputSchema: { type: 'object', properties: {} }
    }
  ]
}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const args = request.params.arguments || {};
  let result;
  switch (request.params.name) {
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
    case 'kiwe_list_class_vocabulary':
      result = listClassVocabulary();
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
