# Operations and recovery

## Health

`GET https://key.kiwelaunch.com/health` reports the service, transport, library version, negotiated protocol, and bounded connection state. HTTP 200 means the transport is open; HTTP 503 means delivery must fall back or the connection needs attention.

## Owner reconnection

Open `/reconnect`, enter the registered WhatsApp number, and scan the QR from that same number. Key.kiwe will not accept a different number. Successful pairing rotates only the primary connection ID, preserves sites and signing credentials, and creates a new authenticated browser session.

## Website recovery

For one dedicated website, use **Replace this website’s client number**. The reset archives that connection directory, creates a clean isolated directory, and shows a new QR. Other websites remain untouched.

## Deployment

Production secrets are supplied as Hostinger Node environment variables. Source archives exclude `node_modules`, runtime configuration, instance configuration, and all state directories. The state root is an absolute durable path outside either the old or current domain’s deployment root.

Required release checks:

```sh
npm ci
npm test
npm run check:compat
npm run build
```

After deployment, verify `/`, `/register`, `/login`, `/reconnect`, `/health`, authenticated dashboard behavior, Network Oversight, and one signed delivery/fallback transaction.

## Incident sequence

1. Deactivate or suspend the affected website/account.
2. Record the account number, website origin, serving number, last transaction, and state from Network Oversight.
3. Rotate only the affected website secret and connection.
4. Confirm origin allowlisting and recent signed-request history.
5. Restore service only after the expected owner/client re-pairs the number.
