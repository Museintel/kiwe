# /redo

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/redo/
Machine contract: https://start.kiwelaunch.com/redo/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/redo/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

redo

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/command-manifest.json

## Requirements

- the immediate previous canonical slash command
- that command's exact approved input/source snapshot, attachments, brief, refinements and target scope in the current conversation

## Required behavior

- fetch fresh discovery with a unique nonce
- load the current content-hash release route for the previous canonical command
- discard the previous generated candidate as authority
- rerun from the preserved approved input snapshot
- run the same validators required by the refreshed route

## Outputs

- one replacement candidate for the immediate previous command

## Forbidden

- localized patching that belongs to /fix
- using the failed candidate as source
- changing command scope
- searching old conversations or downloads
- mixing command releases
- claiming PASS without current validator proof

## Final response

Replace only the immediate previous command's candidate and report the refreshed release/validator proof. If the previous command or approved input snapshot is unavailable or ambiguous, stop with ERROR: KIWE_PREVIOUS_COMMAND_MISSING.
