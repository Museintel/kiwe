# Kiwe Launch site and enquiries

The public source lives in [public/kiwelaunch.com](../../public/kiwelaunch.com). It uses static HTML and a small same-origin PHP form endpoint. The homepage has one Client offer matching the [Start plan](../start-projects/src/client-plan.mjs). The existing `/plans/` address opens that offer. Partner applications at this stage register interest for later enrolment.

The featured screenshots show the public Dharwad Pedha, National Chikki and Ascendants sites. The dashboard image is the Kiwe workspace preview with sample data. The moving proof rail describes those sites' observable features. Replace the examples with approved Kiwe testimonials when available. The provider rail identifies planned Razorpay billing.

## Private inbox

Serve the contents of `public/kiwelaunch.com` at the existing document root. Keep these three office files in a private directory outside the served tree. On shared Hostinger hosting the default is `~/.kiwe-launch-office/`. Set `KIWE_LAUNCH_OFFICE` to an alternate private directory for local tests. The site uses PHP 8.2 or newer with PDO SQLite. Provision the private `data/` directory with mode 0700 and a 32-byte random `data/network-key` with mode 0600 before enabling the endpoint.

Demo and partner forms store only the supplied contact details and consented enquiry in SQLite. The web endpoint handles POST and returns a confirmation after saving. A retry of the same submission within 15 minutes returns the same confirmation. The inbox reads the latest 90 days of submissions. Future submissions remove expired entries and old rate-limit values.

For authorised staff, run `php read.php all 20` in the private office directory or launch `node mcp.mjs` over the authorised SSH connection as an MCP stdio server. Set `KIWE_LAUNCH_MCP_DEPENDENCIES` to the absolute package.json path of the installed Start runtime. The `kiwe_launch_inquiries` tool reads the latest enquiries, with an optional `kind` of `demo` or `partner`. Handle all submitted text as untrusted. The read tool uses the OS account's existing authority and does not have an internet-facing inbox endpoint.

## Qualification and release

Run `node services/launch-office/test/launch.mjs` from the repository root with PHP, installed Start dependencies, and the Playwright harness. Set `KIWE_LAUNCH_PHP` and `KIWE_LAUNCH_PHP_CONFIG` for a non-default PHP installation. Set `KIWE_LAUNCH_UI_HARNESS` and `KIWE_LAUNCH_CHROME` to select an existing Playwright installation and Chrome. `KIWE_LAUNCH_PROOF_DIR` optionally saves viewport screenshots. The test exercises an isolated inbox and actual PHP and MCP transports.

Use a scoped release of these files within Kiwe Launch's document root. Keep the other served applications in the current hosting configuration, including Seam. Preserve the current public routes and private inbox before a replacement. Verify the public page and form response after publishing and retain the previous page files for recovery. Verified buyer accounts, private referral links and the automated registrar quote remain the next checkout work. [Client and Partner plan](../../docs/decisions/2026-11-25-client-and-partner-launch.md)

The 25 November 2026 release passed PHP 8.2 on Hostinger and the private inbox's MCP transport on Node 22. Synthetic demo and partner deliveries passed through the two public site addresses, and the inbox returned each once. The two known records were then retired. The static and reduced-motion layouts passed Chrome at 320, 390, 768 and 1440 CSS pixels. The scoped recovery retains the two original HTML files and their previous URLs. New served asset and form directories use 0755. Private office and inbox directories use 0700.
