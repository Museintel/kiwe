# /execute /fullflow

SeamFlow contract: 7.26

Canonical URL: https://start.kiwelaunch.com/execute/fullflow/
Machine contract: https://start.kiwelaunch.com/execute/fullflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.26-23ba1257d101/execute/fullflow/contract.json
Release: 7.26-23ba1257d101
Source hash: sha256:23ba1257d1015aa0e9d28526dcab493a6dd6b6eba6cd2165024b7f6421dbd36d

## Phase

execute

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/command-manifest.json

## Requirements

- classified current artifact and human approval for full-flow execution

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- final canonical artifacts for the selected flow

## Forbidden

- using prior test material
- loading all contexts upfront
- general web search
- docs unless /document
- ZIP
- DSA/AppShell output unless target requests DSA or combined
- treating missing Site Graph as a blocker for static Bricks conversion

## Final response

Return only final canonical artifacts after every required closing audit for the detected start point has passed, plus compact pass/fail/warn status.
