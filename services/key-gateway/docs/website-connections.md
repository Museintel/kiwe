# Website connections

## Primary by default

Adding a website creates an isolated tenant key ID and signing secret. The website begins in `account_primary` mode and uses the owner’s primary WhatsApp number without creating another transport.

## Client-specific number

An owner can choose **Use a client number** for one website. The existing primary connection continues serving it while a new QR is waiting. Only after the client scan succeeds does Key.kiwe atomically switch that website to `dedicated` mode.

The connected client number is encrypted at rest and retained as the website’s serving-number identity. It is visible to the owner and, without masking, to the master in Network Oversight.

## Replacement and removal

- Replacing a client number affects only that website.
- Returning to primary closes the dedicated runtime and clears its serving-number association.
- Deactivation pauses signed delivery but preserves configuration and history.
- Reactivation restores the prior connection mode.
- Replacing the account primary keeps the current number live until the matching replacement scan succeeds; inherited websites then switch together.

## Status meanings

- `Connected`: the selected transport is ready.
- `Awaiting pairing`: a new isolated connection has not completed its scan.
- `Inactive`: signed delivery is intentionally paused.
- `Session replaced` or `Logged out`: the connection needs a fresh QR.
