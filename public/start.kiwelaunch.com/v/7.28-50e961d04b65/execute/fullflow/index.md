# /execute /fullflow

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/execute/fullflow/
Machine contract: https://start.kiwelaunch.com/execute/fullflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/execute/fullflow/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

execute

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/command-manifest.json

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
