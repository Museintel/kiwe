# /redo

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/redo/
Machine contract: https://start.kiwelaunch.com/redo/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/redo/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

redo

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/command-manifest.json

## Requirements

- the immediate previous output-producing instruction or canonical slash command
- that instruction's exact approved input/source snapshot, attachments, brief, refinements and target scope in the current conversation

## Required behavior

- treat /redo itself as sufficient notice that the last output failed; do not request a failure explanation
- fetch fresh discovery with a unique nonce
- load the current content-hash release route for the previous producing instruction
- discard the previous generated candidate as authority
- inspect the failed candidate only as negative evidence to avoid repeating its observable failure
- regenerate from the preserved approved input snapshot
- run every validator and render proof required by the refreshed route

## Outputs

- one newly generated replacement candidate for the immediate previous output

## Forbidden

- asking the human why the output failed before rerunning
- localized patching that belongs to /fix
- using the failed candidate as source
- changing instruction scope
- searching old conversations or downloads
- mixing command releases
- returning the same candidate without new execution
- claiming PASS without current validator proof

## Final response

Replace only the immediate previous output and report the refreshed release/validator proof. If the previous producing instruction or approved input snapshot is unavailable or ambiguous, stop with ERROR: KIWE_PREVIOUS_COMMAND_MISSING.
