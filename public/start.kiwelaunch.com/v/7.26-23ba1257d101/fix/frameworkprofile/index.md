# /fix /frameworkprofile

SeamFlow contract: 7.26

Canonical URL: https://start.kiwelaunch.com/fix/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/fix/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.26-23ba1257d101/fix/frameworkprofile/contract.json
Release: 7.26-23ba1257d101
Source hash: sha256:23ba1257d1015aa0e9d28526dcab493a6dd6b6eba6cd2165024b7f6421dbd36d

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/contexts/workflow-lite.md

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
