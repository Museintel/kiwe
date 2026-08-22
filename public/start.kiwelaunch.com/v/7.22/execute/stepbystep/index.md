# /execute /stepbystep

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/execute/stepbystep/
Machine contract: https://start.kiwelaunch.com/execute/stepbystep/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

execute

## Requirements

- classified current artifact or explicit current command path

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- the next safe phase artifact only

## Forbidden

- running the whole flow
- using prior test material
- docs unless /document
- ZIP

## Final response

Run only the next command in the current SeamFlow path. If /audit /fix /eachstep is selected, run audit/fix/audit until PASS or NEEDS_INPUT. If /report is selected, return the artifact plus compact report and wait for human continue.
