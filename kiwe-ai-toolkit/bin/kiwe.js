#!/usr/bin/env node
import { createHandoff, getContext, listClassVocabulary, listModes, startProject, validateHandoff } from '../lib/kiwe-core.js';

function print(value) {
  if (typeof value === 'string') {
    process.stdout.write(value.endsWith('\n') ? value : `${value}\n`);
  } else {
    process.stdout.write(`${JSON.stringify(value, null, 2)}\n`);
  }
}

function usage() {
  print(`Kiwe AI Toolkit

Commands:
  kiwe modes
  kiwe start [auto|website|theme|combined] --brief text [--name name]
  kiwe context <website|theme|combined>
  kiwe create <website|theme|combined> <output-dir> [--name name] [--brief text]
  kiwe validate <website|theme|combined> <output-dir>
  kiwe vocabulary
`);
}

const [, , command, ...args] = process.argv;

try {
  if (!command || command === '--help' || command === '-h') {
    usage();
  } else if (command === 'modes') {
    print(listModes());
  } else if (command === 'start') {
    const mode = args[0] && !args[0].startsWith('--') ? args[0] : 'auto';
    const nameIndex = args.indexOf('--name');
    const briefIndex = args.indexOf('--brief');
    const name = nameIndex >= 0 ? args[nameIndex + 1] : '';
    const brief = briefIndex >= 0 ? args.slice(briefIndex + 1).join(' ') : '';
    print(startProject({ mode, name, brief }));
  } else if (command === 'context') {
    print(getContext(args[0] || 'website'));
  } else if (command === 'vocabulary') {
    print(listClassVocabulary());
  } else if (command === 'create') {
    const mode = args[0] || 'website';
    const outputDir = args[1] || '';
    const nameIndex = args.indexOf('--name');
    const briefIndex = args.indexOf('--brief');
    const name = nameIndex >= 0 ? args[nameIndex + 1] : '';
    const brief = briefIndex >= 0 ? args.slice(briefIndex + 1).join(' ') : '';
    print(createHandoff({ mode, outputDir, name, brief }));
  } else if (command === 'validate') {
    const result = validateHandoff(args[1] || '.', args[0] || 'website');
    print(result);
    process.exitCode = result.ok ? 0 : 1;
  } else {
    usage();
    process.exitCode = 1;
  }
} catch (error) {
  console.error(error && error.message ? error.message : String(error));
  process.exitCode = 1;
}
