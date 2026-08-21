# Kiwe SiteGraph client adapters

These adapters expose the same short-lived, public-data-only Kiwe task capsule to standards-based clients. They do not create a second permission system and they never expose Kiwe staging, controlled execution, WordPress mutation, WooCommerce mutation, authentication, cart, checkout or publishing routes.

1. In `Kiwe > SiteGraph`, download a short-lived external-client connection.
2. Put its `connection.baseUrl` in `KIWE_SITEGRAPH_BASE_URL`.
3. Put its `connection.authentication.token` in the local secret `KIWE_SITEGRAPH_TASK_TOKEN`.
4. For an OpenAPI/action client, import `connection.taskOpenapiUrl` or the adapter bundle's task-only OpenAPI URL and configure HTTP Bearer authentication in the client's secret field.
5. For Claude, Cursor or another MCP client, run `node /absolute/path/to/kiwe-ai-toolkit/mcp/sitegraph-client.js` with those two environment variables.
6. Verify with `kiwe_sitegraph_status`, do the bounded task, then revoke the capsule and remove the downloaded connection file.

Never put the token in a prompt, URL, repository, screenshot, project-shared MCP file or browser page. The maintained SEAM Compiler extension can import the connection file and stores the token only in `chrome.storage.local`.
