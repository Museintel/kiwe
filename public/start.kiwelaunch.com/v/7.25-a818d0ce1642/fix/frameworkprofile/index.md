# /fix /frameworkprofile

SeamFlow contract: 7.25

Canonical URL: https://start.kiwelaunch.com/fix/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/fix/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/fix/frameworkprofile/contract.json
Release: 7.25-a818d0ce1642
Source hash: sha256:a818d0ce1642aed2f712ee4fd6f7a967e5d945511d1fa72f85d3c4de4660dd81

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/contexts/workflow-lite.md

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
