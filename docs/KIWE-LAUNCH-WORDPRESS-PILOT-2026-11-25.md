# Kiwe Launch WordPress pilot

25 November 2026 · Version 1.x

The Kiwe Launch homepage, header and footer have rendered as editable Bricks content on isolated WordPress 7.1.5 with Bricks 2.5-beta. The public domain still serves the existing Launch site. A cutover needs the final signed Kiwe components, the Bricks production version selected for Launch and a private Hostinger rehearsal.

## Prepare the homepage

1. In the Kiwe Launch checkout, run **`node tools/release/prepare-launch-wordpress-home.mjs OUTPUT_DIRECTORY`**. This prepares the existing homepage and media in **`kiwe-launch-home-r7`**. Its reviewed behavior script and local font live in **`site-assets`**.
2. In the Seam Compiler checkout that includes the fluid typography fix, run **`npm run compile:folder -- OUTPUT_DIRECTORY/kiwe-launch-home-r7 COMPILER_OUTPUT KiweLaunch-wp-r7 --framework --review-package`**. Use the exact **`seam-framework.zip`** with its byte count and checksum when staging through Kiwe MCP or the native Framework import.
3. Import the three templates in the private WordPress rehearsal. Assign the header and footer to the homepage, copy the imported content into the front page and check the current Bricks rendering. Serve the reviewed behavior script and licensed font on the homepage from the site's own assets. The isolated importer reviews its SVG assets and leaves executable source outside the ZIP.

The local Framework audit passed with three templates. The front page contains 187 native elements. At 390, 768 and 1,440 px, the responsive hero and the Powered by rail rendered on load, with WordPress, Hostinger, Cloudflare, Razorpay and Shiprocket. The edit preserves the existing demo form action and links into Start. The local preview did not send demo inquiries.

## Rehearse on Hostinger

- Carry forward the current homepage, plans, partners, privacy and public assets. Exercise the contact form and partner application with synthetic data before opening the new root. Take a rollback copy of the existing public files and keep provider and DNS credentials in their current protected locations.
- Keep the launch registration, referral, Razorpay webhook receiver and Start entitlement service on their current routes while testing WooCommerce. A successful native order must reconcile to the same purchase and Start site exactly once before the new path becomes the billing authority.
- Test the ₹8,400 direct and ₹7,200 referred offers, their month-13 renewal amounts, affiliate attribution and reversal, and continued access to existing Start projects. For an ordinary product, also exercise card, UPI, COD, coupon, address edit, interrupted payment and refund. Kiwe subscriptions use WooCommerce core and the Razorpay API. Bricks is the required paid plugin.
- Keep the original root and billing authority ready for recovery until the new homepage and order/mandate transitions pass their checks on the hosted rehearsal. Verify the public homepage, login, forms and protected callbacks after any approved cutover.
