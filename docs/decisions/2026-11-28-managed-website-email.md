# Default sender setup for Kiwe websites

Use the existing account-primary WhatsApp sender for managed installations. Key owns the sender and gives each verified WordPress site its own signed credential. Start can request a dedicated connection for a client. Verify the site connection as part of the managed installer before the client starts their design journey.

The founder chose `delivery@kiwelaunch.com` as the platform email sender. The existing mailbox remains available in Hostinger Webmail. WordPress uses its site credential to send supported messages through Key. Key keeps the mailbox credentials in private operator configuration and supports Hostinger SMTP for campaigns and transactional messages.

Kiwe installs select the managed transport after Key confirms the site and sender. Existing local sender settings or active SMTP plugins require individual migration. Site administrators use **Kiwe → Apps → Email** to review their sender, switch to their own SMTP account and run a delivery test. Saving this screen updates only email settings and clears the previous transport test receipt.

The managed transport handles one-recipient text and HTML, including account verification and ordinary notifications. For campaigns, it preserves both one-click unsubscribe headers and requires a same-origin unsubscribe URL. WordPress handles other custom headers, attachments and multiple recipients through its existing mail path. The Kiwe Launch account mailer has separate configuration.

Plum Giraffe WordPress sent a real test message through an isolated Key candidate. The Hostinger inbox contained the expected sender and both unsubscribe headers. Qualify the signed package on the lab and check the site-specific connection and delivery on Launch, National Chikki, Dharwad Pedha, Ascendants and Evergreen Basin. National Chikki uses WP Mail SMTP. Verify its replacement, deactivate the plugin, and confirm delivery again before uninstalling. Ascendants has a custom Kiwe SMTP sender. Kiwe Launch needs a Key connection.
