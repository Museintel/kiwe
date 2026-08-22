# /fix /frameworkprofile

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/fix/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/fix/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.22/fix/frameworkprofile/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.22/contexts/workflow-lite.md

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
