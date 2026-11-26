# TYPO3 AI connector ↔ node-mcp gateway: contract

Version 1, extension version 0.1.0, 2026-11-03.

This describes how the TYPO3 extension and the gateway (`node-mcp`) work together:

- **Part A, pairing:** the TYPO3 extension calls the gateway (same endpoints as the Shopware plugin, new TYPO3 body).
- **Part B, site API:** the gateway calls the TYPO3 site's JSON API.
- **Part C, the gateway side.**

**Address:** the gateway has one MCP address for every platform, `https://<gateway>/mcp` (see
`docs/ADDING_A_PLATFORM.md` in `node-mcp`). There is no separate `/typo3` address. The gateway supports
TYPO3 there on `main` (Part C). An earlier
version with a separate `/typo3` address (branch `feature/typo3-connector`) was tested end to end.

---

## Part A: pairing (TYPO3 → gateway)

The flow is the same as for Shopware: the AI client signs in at the gateway, the sign-in page shows a
one-time code, the TYPO3 admin pastes it in the backend module *AI connector*, checks the request, picks
permissions and connects.

The extension only calls `<gateway origin>/api/v1/…`, always `POST`, JSON, 10 s timeout, never follows
redirects. Unchanged from Shopware: rate limits, `{ reason, message }` errors, 204 responses, 404 on
disconnect counting as "already disconnected".

### A1. Sign-in for TYPO3

- AI clients add `https://<gateway>/mcp`, the address shared by every platform.
- Its sign-in page offers the permissions of every platform, the TYPO3 ones below included, and
  `pairing/request` returns all of them. The extension offers only the TYPO3 keys and refuses a code
  that has none (it was created at `/shopware` or `/magento`).
- A code from `/shopware` or `/magento` completed with `shop.platform = "typo3"` gets
  `409 { reason: "other_platform" }`, and the code stays valid.
- At `/mcp` only the tools of the connected site's platform are offered for it; TYPO3 tools are named
  `solution25-typo3-*`.
- The extension's address is `GatewayConfiguration::DEFAULT_MCP_URL`
  (`https://shopware-mcp.development-s25.com/mcp`). It calls the API on the same host
  (`<origin>/api/v1/…`). `AI_CONNECTOR_GATEWAY_URL` overrides it for development.

### A2. Permission keys

Same `area.level` format and the same level dependencies as `src/permissions/permissions.ts`
(Edit → View, Create → View + Edit).

| Key | Label on the sign-in page | TYPO3 meaning |
| --- | --- | --- |
| `page.viewer` | Pages: View | read the page tree and pages |
| `page.editor` | Pages: Edit | change page title and navigation title |
| `content.viewer` | Content: View | read and search content elements |
| `content.creator` | Content: Create | create content elements (always saved **hidden**) |
| `content.editor` | Content: Edit | change header, subheader and text of content elements |
| `seo.editor` | SEO: Edit | change the page SEO title and meta description |

Notes:

- `seo` has no `viewer` level; `seo.editor` needs nothing else. Reading pages needs `page.viewer` and
  reading content needs `content.viewer` (B2); a write answers with the changed record without them.
- Presets: **Read only** = `page.viewer`, `content.viewer`; **Content editor** = all six.
- The extension refuses any key not in this list, and any key the client did not request.

### A3. `POST /api/v1/pairing/request`: unchanged

Request `{ "code": "S25-K7QM-4XTD" }` → `200 { clientName, permissions[], startedAt, verifiedClient, returnHost }`.
For TYPO3 codes, `permissions` contains TYPO3 keys from A2.

### A4. `POST /api/v1/pairing/complete`: TYPO3 body

```json
{
  "code": "S25-K7QM-4XTD",
  "shop": {
    "platform": "typo3",
    "url": "https://www.example.com",
    "apiUrl": "https://www.example.com/ai-connector/api/",
    "name": "Example site",
    "typo3Version": "14.3.10",
    "extensionVersion": "0.1.0"
  },
  "credentials": {
    "apiToken": "t3ai_9f2c…(69 characters)"
  },
  "accessLevel": "Custom",
  "permissions": ["content.creator", "content.editor", "content.viewer", "page.viewer"]
}
```

| Field | Rules |
| --- | --- |
| `shop.platform` | always `"typo3"`; store it on the connection to pick the TYPO3 adapter |
| `shop.url` | the site's public address, https, may contain a path (e.g. `https://host/camino`); no query, fragment or credentials |
| `shop.apiUrl` | base of Part B, always `<origin of url>/ai-connector/api/`; same origin as `url`, so the existing `shopAddressProblem()` checks fit (`apiUrl` takes the role of `mcpUrl`) |
| `shop.name` | site title, ≤ 100 characters |
| `shop.typo3Version`, `shop.extensionVersion` | ≤ 64 characters |
| `credentials.apiToken` | `t3ai_` + 64 hex characters. Store encrypted like the Shopware keys. Sent **only once**: the site keeps only a SHA-256 hash and cannot send it again |
| `accessLevel` | display label, ≤ 255 characters |
| `permissions` | subset of what the code requested |

Response unchanged: `200 { "connectionId": "…", "connectionSecret": "…" }`.

If the extension fails after you answered (e.g. its database write fails), it calls `disconnect` for that
connection right away.

### A5. `POST /api/v1/connections/:id/permissions`: unchanged

Bearer `connectionSecret`, body `{ "accessLevel": "Custom", "permissions": [...] }`, `204`. Sent when the
admin changes permissions in the backend. The new rights already apply on the site when you receive this;
please update the connection's permissions so the client gets the matching tools. The extension only
allows keys the client requested at pairing.

### A6. `POST /api/v1/connections/:id/disconnect`: unchanged

Bearer `connectionSecret`, `204`, `404` = already disconnected. The site has already deleted the
connector user and the API token before calling this.

---

## Part B: site API (gateway → TYPO3)

### B1. Common rules

- Base URL: `shop.apiUrl` from pairing, e.g. `https://www.example.com/ai-connector/api/`.
- Auth: `Authorization: Bearer <apiToken>` on every request.
- JSON in and out, UTF-8. Send `Content-Type: application/json` on POST/PATCH. Body ≤ 512 KiB.
- Responses carry `Cache-Control: no-store`. Timestamps are ISO 8601 (`2026-11-02T14:05:08+01:00`).
- Only the connected site's pages, default language, live workspace.
- Errors, same shape as yours:

```json
{ "reason": "forbidden", "message": "This connection does not have the \"seo.editor\" permission." }
```

| Status | `reason` | Meaning for the gateway |
| --- | --- | --- |
| 400 | `invalid_request` | bad body or query; `message` says what. Show it to the model |
| 401 | `unauthorized` | token unknown or revoked: the connection was disconnected on the site or its connector user was deleted. The tool tells the user to connect the site again |
| 403 | `forbidden` | the connection lacks the permission or TYPO3 right |
| 404 | `not_found` | unknown route, or the page or element does not exist **or is not part of this site** |
| 405 | `method_not_allowed` | wrong HTTP method for the route |
| 413 | `payload_too_large` | body over 512 KiB |
| 422 | `rejected` | TYPO3 refused the change (DataHandler error); `message` contains TYPO3's reason |
| 500 | `server_error` | unexpected; details are in the TYPO3 log |

Network rules on your side stay as for Shopware: use `shopFetch` (no private addresses, no redirects,
size cap), and only call URLs under the stored `apiUrl`.

### B2. Permissions per endpoint

| Endpoint | Needs |
| --- | --- |
| `GET status` | any connection |
| `GET pages/tree`, `GET pages/{uid}` | `page.viewer`; the content of `GET pages/{uid}` only with `content.viewer` |
| `GET content/search` | `page.viewer` or `content.viewer`; page results only with `page.viewer`, content results only with `content.viewer` |
| `GET content/{uid}` | `content.viewer` |
| `PATCH pages/{uid}` | `page.editor` |
| `PATCH pages/{uid}/seo` | `seo.editor` |
| `POST content` | `content.creator` |
| `PATCH content/{uid}` | `content.editor` |

Each write checks both the permission key and TYPO3's own rights of the connector user. Please still
register tools only for granted permissions (`{ needs, register }`), so the model never sees tools that
would answer 403.

### B3. `GET status`

Use it to check a connection (e.g. after pairing or for a health check).

```json
{
  "site": { "identifier": "camino", "rootPageId": 1, "url": "https://www.example.com/camino" },
  "connectionId": "a1b2c3…",
  "permissions": ["content.creator", "content.editor", "content.viewer", "page.editor", "page.viewer", "seo.editor"],
  "rights": { "readPages": true, "readContent": true, "modifyPages": true, "modifyContent": true },
  "extensionVersion": "0.1.0"
}
```

`readPages` is true only with `page.viewer`, and `readContent` only with `content.viewer`; both also need
the TYPO3 table rights of the connector user.

### B4. `GET pages/tree?root={uid}&depth={0-10}`

`root` defaults to the site's root page, `depth` to 3 (max 10). At most 1000 pages; `truncated: true` if cut.

```json
{
  "tree": {
    "uid": 1, "pid": 0, "title": "Camino", "slug": "/", "doktype": 1, "hidden": false, "hiddenInMenu": false,
    "children": [
      { "uid": 7, "pid": 1, "title": "Camino Route Comparison", "slug": "/camino-route-comparison",
        "doktype": 1, "hidden": false, "hiddenInMenu": false, "children": [] }
    ]
  },
  "truncated": false
}
```

`slug` is relative to `shop.url` (page URL = `shop.url` + `slug`). `doktype`: 1 = standard page,
254 = folder (no frontend page), others see TYPO3 docs. `hidden` = not published.

### B5. `GET pages/{uid}`

The page plus all its content elements in display order (`colPos`, then sorting).

```json
{
  "page": {
    "uid": 5, "pid": 1, "title": "FAQs", "slug": "/faqs", "doktype": 1, "hidden": false, "hiddenInMenu": false,
    "navTitle": "", "seoTitle": "", "description": "", "updatedAt": "2026-11-02T14:05:08+01:00"
  },
  "content": [
    {
      "uid": 30, "pid": 5, "type": "text", "colPos": 0,
      "header": "What is the Camino de Compostela?", "subheader": "",
      "bodytext": "<p>The Camino de Compostela, or Way of St. James, is …</p>",
      "hidden": false, "updatedAt": "2026-11-02T13:00:12+01:00"
    }
  ]
}
```

- `bodytext` is the stored rich text (HTML subset). For `bullets` it is one item per line, for `table`
  rows per line with `|` between cells.
- `type` is TYPO3's `CType`. Elements of other types (theme or plugin elements, e.g. `camino_hero`) are
  listed so the model sees the whole page, but cannot be edited through the API.

### B6. `GET content/search?q={text}&limit={1-50}`

`q` 2–100 characters, `limit` default 20. Matches page title, navigation title, SEO title and
description, and content header, subheader and text, within the site.

```json
{
  "query": "pilgrim",
  "pages": [],
  "content": [
    {
      "uid": 46, "pid": 1, "type": "text", "colPos": 0,
      "header": "What Is the Camino de Compostela?", "subheader": "", "hidden": false,
      "updatedAt": "2026-11-02T13:00:12+01:00",
      "snippet": "The Camino de Compostela, also known as the Way of St. James, is one of the world's most …"
    }
  ]
}
```

Search results carry a plain-text `snippet` (≤ 200 characters) instead of `bodytext`; read the full
element with `GET content/{uid}`, which needs `content.viewer` and the connector user's TYPO3 read right
on `tt_content` (`tables_select`), but not `page.viewer`.

### B7. `POST content`: create a content element

```json
{
  "pid": 5,
  "type": "text",
  "header": "How much does the Camino cost?",
  "subheader": "",
  "bodytext": "<p>Most pilgrims spend 30 to 50 euros a day …</p>",
  "colPos": 0
}
```

| Field | Rules |
| --- | --- |
| `pid` | required, a page of the site |
| `type` | required, one of `header`, `text`, `textpic`, `textmedia`, `bullets`, `table` |
| `header`, `subheader` | optional, ≤ 255 characters |
| `bodytext` | optional, ≤ 100 000 characters, rich text HTML; TYPO3 cleans it (e.g. `<script>` is escaped) |
| `colPos` | optional, default 0 (main column) |
| `after` | optional, uid of an element on the same page; the new one is placed right after it, in its column (`colPos` is then ignored) |

Without `after` the element goes to the top of the column. `201 { "content": { …same shape as in B5… } }`.

**The element is always created hidden** (`"hidden": true`): an editor publishes it in TYPO3. Please say so
in the tool description and result, so the model tells the user.

### B8. `PATCH content/{uid}`: change a content element

Body: any of `header`, `subheader`, `bodytext` (same limits). Only elements of the six types in B7.
Visibility (`hidden`) cannot be changed through the API. `200 { "content": { … } }`.

### B9. `PATCH pages/{uid}/seo`: change SEO fields

Body: any of `seoTitle` (≤ 255), `description` (≤ 1000). `200 { "page": { …as in B5 "page"… } }`.
These change the live page immediately (there is no hidden draft for page fields).

### B10. `PATCH pages/{uid}`: change page titles

Body: any of `title` (not empty, ≤ 255), `navTitle` (≤ 255, empty = use `title`).
`200 { "page": { … } }`. Live immediately.

### B11. History and undo

Every write goes through TYPO3's DataHandler as the connection's own backend user, so TYPO3's history
(and undo) and log show exactly what the AI client changed. Nothing to do on your side.

---

### B12. `GET content/{uid}`: one content element

`200 { "content": { …as in B5… } }`, or 404 if it is not part of the site. Used by the gateway to show the
current values before a change.

---

## Part C: the gateway side

Merged on `main` through the platform registry: TYPO3 is a platform at `/mcp`, and the tools are named
`solution25-typo3-*`.

1. **Pairing for TYPO3** (A1, A2, A4): `/typo3` endpoint and protected resource, TYPO3 sign-in page with
   the TYPO3 permissions, the TYPO3 `pairing/complete` body, `platform` stored on pairing requests and
   connections (migration 7), the `apiToken` encrypted like the Shopware keys.
2. **TYPO3 API client** (`src/typo3/typo3-api.ts`): Bearer token, `shopFetch`, only URLs under `apiUrl`,
   no redirects, 20 s timeout, the errors of B1 turned into messages for the model. On 401 the tool
   tells the user to connect the site again; the connection itself ends when the extension disconnects.
3. **Tools** (`src/mcp/typo3/`, registered by permission, writes with the preview → `confirmationToken` →
   apply flow):

| Tool | Needs | Calls | Preview (dry run) |
| --- | --- | --- | --- |
| `ping`, `typo3-list-sites`, `typo3-connect-site` | nothing | `GET status` | none |
| `typo3-page-tree` | `page.viewer` | `GET pages/tree` | none |
| `typo3-page-read` | `page.viewer` | `GET pages/{uid}` | none |
| `typo3-content-search` | `content.viewer` | `GET content/search` | none |
| `typo3-content-create` | `content.creator` | `POST content` | the element and its position, read from `GET pages/{pid}`; says it will be hidden |
| `typo3-content-update` | `content.editor` | `PATCH content/{uid}` | current vs. new values from `GET content/{uid}`; other content types are refused here |
| `typo3-page-seo-update` | `seo.editor` | `PATCH pages/{uid}/seo` | current vs. new SEO title and description |
| `typo3-page-update` | `page.editor` | `PATCH pages/{uid}` | current vs. new titles |

4. **Tests:** `test/integration/typo3-sites.test.ts` against a fake TYPO3 site that follows Part B, plus unit
   tests for the TYPO3 permissions. The whole suite passes (267 tests).

## Extension side

The extension pairs with the TYPO3 contract (`Gateway\Typo3Protocol`), so it needs a gateway that accepts
TYPO3 at `/mcp`. The address is `GatewayConfiguration::DEFAULT_MCP_URL`.
