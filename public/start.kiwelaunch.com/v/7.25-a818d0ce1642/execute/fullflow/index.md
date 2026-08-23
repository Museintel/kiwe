# /execute /fullflow

SeamFlow contract: 7.25

Canonical URL: https://start.kiwelaunch.com/execute/fullflow/
Machine contract: https://start.kiwelaunch.com/execute/fullflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/execute/fullflow/contract.json
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
