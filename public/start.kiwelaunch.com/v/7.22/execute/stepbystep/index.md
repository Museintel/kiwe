# /execute /stepbystep

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/execute/stepbystep/
Machine contract: https://start.kiwelaunch.com/execute/stepbystep/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

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
