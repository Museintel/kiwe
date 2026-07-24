#!/usr/bin/env node
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';
import { createHandoff, diagnoseCommand, getBricksConversionContext, getContext, getDynamicContext, getWorkflowContext, listClassVocabulary, listModes, prepareApplyPlan, routeCommand, startDynamicPass, startProject, validateBindings, validateBricksConversion, validateHandoff } from '../lib/kiwe-core.js';

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
      name: 'kiwe_get_workflow',
      description: 'Return the Kiwe phased AI workflow and slash-command vocabulary. Use this before broad creative work so the model does one small phase at a time.',
      inputSchema: { type: 'object', properties: {} }
    },
    {
      name: 'kiwe_route_command',
      description: 'Route a short canonical command such as /ideate /webdraft, /rebuild /seamframework, /create /dsatheme, /create /preview /dsatheme, /assemble /combined, /create /preview /combined, /dynamic /sitegraph, /convert /bricks, /audit /bricksconversion, or /audit /combined to the smallest relevant Kiwe context.',
      inputSchema: {
        type: 'object',
        properties: {
          command: { type: 'string', description: 'Human command, e.g. /rebuild /seamframework. May include /usecompanion.' },
          brief: { type: 'string', description: 'Plain-language human brief for this phase.' },
          artifactSummary: { type: 'string', description: 'Short summary of the previous phase artifact, if any.' },
          siteGraphSummary: { type: 'string', description: 'Short target Site Graph summary for dynamic phases.' },
          useCompanion: { type: 'boolean', description: 'Optional equivalent of appending /usecompanion. Companion is bounded and non-blocking; if unavailable, continue with the normal route.' }
        },
        required: ['command']
      }
    },
    {
      name: 'kiwe_diagnose_command',
      description: 'Cheaply validate a Kiwe slash command before generation. Returns ok, rejected, needs_input, or noop with exact next-command suggestions so the AI does not waste tokens on nonexistent or useless phases.',
      inputSchema: {
        type: 'object',
        properties: {
          command: { type: 'string', description: 'Human slash command to diagnose.' },
          artifactSummary: { type: 'string', description: 'Short summary of available files/artifacts.' },
          siteGraphSummary: { type: 'string', description: 'Short target Site Graph/API context summary.' }
        },
        required: ['command']
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
      name: 'kiwe_get_bricks_conversion_context',
      description: 'Return the Kiwe Bricks conversion context for /convert /bricks and /audit /bricksconversion without reading the full plugin codebase.',
      inputSchema: { type: 'object', properties: {} }
    },
    {
      name: 'kiwe_validate_bricks_conversion',
      description: 'Validate a reviewable Bricks conversion package, optionally against a target-site Site Graph JSON file.',
      inputSchema: {
        type: 'object',
        properties: {
          targetDir: { type: 'string', description: 'Handoff folder, bricks-conversion folder, or kiwe-bricks-conversion.json path.' },
          siteGraphPath: { type: 'string', description: 'Optional path to kiwe.site-graph.v1 JSON for deep validation.' },
          optional: { type: 'boolean', description: 'If true, missing conversion package is informational instead of failing.' }
        },
        required: ['targetDir']
      }
    },
    {
      name: 'kiwe_prepare_apply_plan',
      description: 'Prepare a dry-run, non-mutating Bricks apply plan from a validated Kiwe binding plan and target-site Site Graph.',
      inputSchema: {
        type: 'object',
        properties: {
          targetDir: { type: 'string', description: 'Handoff folder, bricks-bindings folder, or kiwe-bindings.json path.' },
          siteGraphPath: { type: 'string', description: 'Required path to kiwe.site-graph.v1 JSON for target-site capabilities.' },
          write: { type: 'boolean', description: 'If true, writes bricks-apply/kiwe-apply-plan.json and APPLY-NOTES.md into the handoff folder.' }
        },
        required: ['targetDir', 'siteGraphPath']
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
    case 'kiwe_get_workflow':
      result = getWorkflowContext();
      break;
    case 'kiwe_route_command':
      result = routeCommand(args);
      break;
    case 'kiwe_diagnose_command':
      result = diagnoseCommand(args);
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
    case 'kiwe_get_bricks_conversion_context':
      result = getBricksConversionContext();
      break;
    case 'kiwe_validate_bricks_conversion':
      result = validateBricksConversion(args.targetDir, { siteGraphPath: args.siteGraphPath || '', optional: Boolean(args.optional) });
      break;
    case 'kiwe_prepare_apply_plan':
      result = prepareApplyPlan(args.targetDir, { siteGraphPath: args.siteGraphPath || '', write: Boolean(args.write) });
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
