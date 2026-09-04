# Key.kiwe documentation

Key.kiwe is a WhatsApp-first account and website-connection service for Kiwe. The public application lives at [key.kiwelaunch.com](https://key.kiwelaunch.com/) and the published documentation lives at [start.kiwelaunch.com/key/](https://start.kiwelaunch.com/key/).

## Documentation map

- [Product and account model](account-model.md)
- [Website connections](website-connections.md)
- [WordPress and Kiwe integration](wordpress-integration.md)
- [Signed delivery API](api.md)
- [Security and privacy](security.md)
- [Operations and recovery](operations.md)

## Core guarantees

1. An account begins with a WhatsApp number and a matching linked-device scan.
2. Every website has isolated signing credentials.
3. Websites inherit the account primary number unless explicitly assigned a client number.
4. Changing one client number cannot disturb other websites.
5. An offline owner can reconnect only by scanning from the already-registered number.
6. OTP bodies are never retained and secrets and phone numbers are encrypted at rest.
7. Network Oversight exposes full account and serving numbers only to the master account for abuse response.
