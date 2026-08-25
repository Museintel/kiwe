# /execute /fullflow

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/execute/fullflow/
Machine contract: https://start.kiwelaunch.com/execute/fullflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/execute/fullflow/contract.json
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
