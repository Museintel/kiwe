# /fix /frameworkprofile

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/fix/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/fix/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/fix/frameworkprofile/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/contexts/workflow-lite.md

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
