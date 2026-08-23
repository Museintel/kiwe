# /execute /stepbystep

SeamFlow contract: 7.25

Canonical URL: https://start.kiwelaunch.com/execute/stepbystep/
Machine contract: https://start.kiwelaunch.com/execute/stepbystep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/execute/stepbystep/contract.json
Release: 7.25-a818d0ce1642
Source hash: sha256:a818d0ce1642aed2f712ee4fd6f7a967e5d945511d1fa72f85d3c4de4660dd81

## Phase

execute

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/command-manifest.json

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
