# /fix /frameworkprofile

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/fix/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/fix/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/fix/frameworkprofile/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/contexts/workflow-lite.md

## Requirements

- framework profile file and audit findings

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- framework/kiwe-framework-profile.json

## Forbidden

- Bricks template JSON
- DSA theme package
- docs unless /document
- ZIP

## Final response

Return fixed profile and compact pass/fail summary.
