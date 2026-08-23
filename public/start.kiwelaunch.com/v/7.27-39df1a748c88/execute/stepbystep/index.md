# /execute /stepbystep

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/execute/stepbystep/
Machine contract: https://start.kiwelaunch.com/execute/stepbystep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/execute/stepbystep/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

execute

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/command-manifest.json

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
