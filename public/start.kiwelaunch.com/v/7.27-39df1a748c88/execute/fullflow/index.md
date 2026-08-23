# /execute /fullflow

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/execute/fullflow/
Machine contract: https://start.kiwelaunch.com/execute/fullflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/execute/fullflow/contract.json
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
