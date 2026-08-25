# /execute /stepbystep

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/execute/stepbystep/
Machine contract: https://start.kiwelaunch.com/execute/stepbystep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/execute/stepbystep/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

execute

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/command-manifest.json

## Requirements

- classified current artifact or explicit current command path

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- the next safe phase artifact only

## Forbidden

- running the whole flow
- using prior test material
- docs unless /document
- ZIP

## Final response

Run only the next command in the current SeamFlow path. If /audit /fix /eachstep is selected, run audit/fix/audit until PASS or NEEDS_INPUT. If /report is selected, return the artifact plus compact report and wait for human continue.
