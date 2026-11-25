# Kiwe Launch WordPress pilot

25 November 2026 · Version 1.x

The Kiwe Launch homepage, header and footer imported as editable Bricks content on isolated WordPress 7.1.5. The first rehearsal used Bricks 2.5-beta. The final package was imported with the licensed Bricks 2.4.5 build read from Hostinger's Plum Giraffe lab. Hosted checkout qualification and public cutover are pending.

The audited Framework ZIP is 3,438,199 bytes and has SHA-256 **2C22BFBC9896CB71D012941038914689BABCE0E834ED27140358DFB291D63473**. [Kiwe checkout candidate](https://github.com/Museintel/kiwe-ecosystem/commit/064df4ee1c75354387ceae1be8a5241c81870df5) · [Seam compiler revision used for the ZIP](https://github.com/Museintel/seam-compiler/commit/1a2d9847e97f1274cc374b3a756aeb9288cccd9c)

## Prepare the homepage

1. In the Kiwe Launch checkout, run **`node tools/release/prepare-launch-wordpress-home.mjs OUTPUT_DIRECTORY`**. This prepares the homepage and media in **`kiwe-launch-home`**. Its reviewed behavior script and local font live in **`site-assets`**.
2. In the Seam Compiler checkout linked above, run **`npm run compile:folder -- OUTPUT_DIRECTORY/kiwe-launch-home COMPILER_OUTPUT KiweLaunch-wp-r10 --framework --review-package`**. Use the exact **`seam-framework.zip`** with its byte count and checksum when staging through Kiwe MCP or the native Framework import.
3. Import the three templates in the private WordPress rehearsal. Assign the header and footer to the homepage, copy the imported content into the front page and check the current Bricks rendering. Serve the reviewed behavior script and licensed font on the homepage from the site's own assets. The isolated importer reviews its SVG assets and leaves executable source outside the ZIP.

The Framework audit and the authorized local import passed with three templates. The hero uses eager WordPress Media for the Dharwad Pedha screenshots and raster partner logos. The Bricks HTML elements for the mobile menu and demo form keep their authored markup. The carousel and form script use data attributes that survive conversion. The demo control posts the original fields to the existing intake route and starts with consent unchecked.

The front page rendered 187 Bricks elements. Both hero screenshots and all five partner logos loaded at nine viewport sizes from 360 × 640 to 1,440 × 970 with JavaScript disabled. In the standard browser run, the carousel paused and resumed and the demo form handled both a rejected response and a successful retry through synthetic intake responses.

The matching local checkout opened the address sheet on the first tap, preserved the native fields through a stalled or failed request, and recorded a ₹199 cash-on-delivery test order with processing status. The address link also reached the native checkout form with JavaScript disabled.

## Rehearse on Hostinger

- Carry forward the current homepage, plans, partners, privacy and public assets. Exercise the contact form and partner application with synthetic data before opening the new root. Take a rollback copy of the existing public files and keep provider and DNS credentials in their current protected locations.
- Keep the launch registration, referral, Razorpay webhook receiver and Start entitlement service on their current routes while testing WooCommerce. A successful native order must reconcile to the same purchase and Start site exactly once before the new path becomes the billing authority.
- Test the ₹8,400 direct and ₹7,200 referred offers, their month-13 renewal amounts, affiliate attribution and reversal, and continued access to existing Start projects. For an ordinary product, also exercise card, UPI, COD, coupon, address edit, interrupted payment and refund. Kiwe subscriptions use WooCommerce core and the Razorpay API. Bricks is the required paid plugin.
- Keep the original root and billing authority ready for recovery until the new homepage and order/mandate transitions pass their checks on the hosted rehearsal. Verify the public homepage, login, forms and protected callbacks after any approved cutover.
