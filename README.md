# AI connector for TYPO3

Connects a TYPO3 site to Claude and other AI clients through the Solution25 MCP gateway (`node-mcp`).
An admin pairs a site with the one-time code shown on the gateway's sign-in page, picks what the AI
client may do, and can change or remove that access at any time. The gateway then works with the
site through a small JSON API that this extension provides.

- Package: `solution25/typo3-ai-connector`, extension key `ai_connector`
- TYPO3 14.3 (tested), 13.4 (supported, not tested yet), PHP 8.2+
- Backend module: **Administration > Integrations > AI connector** (TYPO3 13.4: **Admin Tools > AI connector**), admins only

## Install

```bash
composer require solution25/typo3-ai-connector
vendor/bin/typo3 extension:setup -e ai_connector
```

## How it works

**Pairing.** In the AI client the user adds the Solution25 connector and signs in. The gateway's sign-in
page shows a code like `S25-K7QM-4XTD` (30 minutes, single use). The admin pastes it in the module,
clicks *Check code* to see who asks for what, picks permissions and clicks *Connect*. Only one
connection per TYPO3 site.

On connect the extension, in one database transaction:

1. creates a backend group with only the picked rights, mounted on the site's root page, and a backend
   user in it (random unknown password, not admin, no modules);
2. creates a random API token (`t3ai_…`), stores only its SHA-256 hash, and sends the token once to
   the gateway in `pairing/complete`;
3. stores the connection.

If anything fails, all of it is rolled back, and if the gateway had already paired, it is told to
disconnect.

**Disconnect** deletes the user, group and token first (access ends at once, even if the gateway is
unreachable), then tells the gateway.

**Permissions** can be changed later, within what the AI client asked for. They apply on the site
first, then the gateway is told.

**Audit trail.** Every connect, disconnect and permission change is recorded with its outcome and shown
in the module.

Connect, disconnect and permission changes run one at a time (lock).

## Permissions and TYPO3 rights

| Permission | TYPO3 rights of the connector group |
| --- | --- |
| `page.viewer` / `content.viewer` | read `pages`, `tt_content` |
| `page.editor` | modify `pages`, field `nav_title` |
| `content.creator` | modify `tt_content`, field `subheader`, set `hidden` (new content is always saved hidden), content types below |
| `content.editor` | modify `tt_content`, field `subheader`, content types below |
| `seo.editor` | modify `pages`, fields `seo_title`, `description` |

Content types the AI client may create or edit: `header`, `text`, `textpic`, `textmedia`, `bullets`,
`table`. Never raw HTML, plugins, forms, shortcuts or file lists. The group never gets backend
modules, file mounts, users, groups or system tables.

## JSON API

Base: `https://<site>/ai-connector/api/`, `Authorization: Bearer <API token>`. Full contract for the
gateway: [Documentation/GatewayContract.md](Documentation/GatewayContract.md).

Reads use the extension's own queries, limited to pages of the connected site and to the group's table
rights. Writes go through TYPO3's DataHandler as the connector user, so TYPO3 enforces table, field and
content type rights and records history under that user. Only TYPO3's page ownership check is skipped
(`bypassAccessCheckForRecords`), because the connector group does not own the site's pages; the
extension's site check takes its place. Default language and live workspace only.

## Configuration

- **Connector address:** the gateway's one MCP address for every platform, fixed to
  `GatewayConfiguration::DEFAULT_MCP_URL` (`https://shopware-mcp.development-s25.com/mcp`). The module
  shows it; it is what you add in the AI client. The plugin API is called on the same host
  (`<origin>/api/v1/…`). For development the server environment can override it with
  `AI_CONNECTOR_GATEWAY_URL`, e.g. `https://<ngrok>/mcp` (https, or http only to localhost or in the
  Development context; an address without a path gets `/mcp`).
- **Site address:** the site's base URL, which must be https on the site's own domain. A base without a
  domain (e.g. `/camino/`) uses the host the backend was opened on.

## Testing

In the AI client, add the connector `https://<gateway>/mcp` and sign in; paste the code
in the module. The `/mcp` address serves every platform, so its code asks for the permissions of all of
them; the module offers only the TYPO3 ones, and refuses a code that has none. The gateway refuses private addresses, so a local site needs a public https URL
(`ddev share`); open the backend through that URL before connecting.

## Gateway

The gateway (`node-mcp`, `main`) accepts TYPO3 sites (`platform: 'typo3'` in `pairing/complete`) on its
`/mcp` address.

## Out of scope

Workspaces (new content is saved hidden instead), translations, file and image uploads, records of
other extensions (news etc.), several connections per site.

## Development and release

User documentation for docs.typo3.org is in `Documentation/` (reST, `Documentation/guides.xml`).
Render it locally, it must finish without warnings:

```bash
docker run --rm --pull always -v $(pwd):/project ghcr.io/typo3-documentation/render-guides:latest --config=Documentation --minimal-test
```

Coding guidelines (TYPO3 coding standards):

```bash
composer install
composer cs:check   # composer cs:fix to apply
```

Release to TER with [tailor](https://github.com/TYPO3/tailor) (token from `tailor ter:token:create`):

```bash
export TYPO3_API_TOKEN=...
vendor/bin/tailor set-version 0.1.0
git tag -a 0.1.0 -m "0.1.0" && git push origin --tags
vendor/bin/tailor ter:publish 0.1.0 --comment "First release"
```
