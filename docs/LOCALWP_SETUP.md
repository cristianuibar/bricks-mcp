# LocalWP Setup Guide

This guide prepares a LocalWP site for Bricks MCP Extensions and an AI client.

## Prerequisites

- A LocalWP site running WordPress 7.1+ and PHP 8.2+
- Bricks Builder 2.5+ installed and active as the theme
- The MCP Adapter plugin (`mcp-adapter`) installed and active
- Bricks MCP Extensions installed and active

## 1. Pretty permalinks

Open Settings > Permalinks and pick any structure except Plain. The adapter endpoint is a REST route and the diagnostics expect pretty permalinks.

## 2. Application Passwords

Application Passwords need HTTPS or a local environment type. In LocalWP, use one of:

- Site > Overview > SSL > Trust, then use the `https://` site URL.
- Set the environment type in `app/public/wp-config.php`: `define( 'WP_ENVIRONMENT_TYPE', 'local' );`

## 3. Increase the PHP timeout

Ability calls that import media or write large pages can run longer than 30 seconds. Raise the limits.

**File:** `conf/php/php.ini.hbs` in the site folder, or `conf/php/php.ini` if your LocalWP version has no `.hbs` file:

```ini
max_execution_time = 300
```

> If a `.hbs` file exists, edit it. LocalWP regenerates the plain file from it on restart.

If the PHP-FPM config has `request_terminate_timeout`, set it to `300` in the `[www]` section of the `.conf` file under `conf/php/`.

Site folders by OS:

| OS | Site root |
|----|-----------|
| macOS | `~/Local Sites/<site-name>/` |
| Windows | `C:\Users\<username>\Local Sites\<site-name>\` |
| Linux | `~/Local Sites/<site-name>/` |

## 4. Restart the site

In LocalWP, stop and start the site so the changes apply.

## 5. Connect your AI client

1. Open Bricks > Settings > AI.
2. Create an Application Password and copy the client config.
3. Paste it into your AI client.

The endpoint is `https://<site>.local/wp-json/mcp/mcp-adapter-default-server`. Use the `https://` URL if you trusted the certificate, otherwise the `http://` URL. Bricks > Bricks MCP Extensions shows the endpoint for your site.

AI clients run on your machine, so the `.local` hostname resolves through LocalWP. If a client cannot reach the site, check that LocalWP is running and the site is started.

## 6. Verify

1. Open Bricks > Bricks MCP Extensions.
2. Click Run Diagnostics. HTTPS, permalinks, Application Passwords, REST reachability, MCP endpoint and PHP Execution Time should pass.
3. Ask your AI client to list its tools. You should see `bricks/*` and `bricks-mcp/*` abilities.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| MCP endpoint check fails | Adapter inactive, or plain permalinks | Activate `mcp-adapter` and set pretty permalinks. |
| Application Passwords unavailable | Site is HTTP and not `local` | Trust the SSL certificate or set `WP_ENVIRONMENT_TYPE` to `local`. |
| Loopback request failed | Site cannot reach its own URL | Check LocalWP's router mode and Site Health. |
| PHP Execution Time warns after restart | Edited a generated file | Edit `php.ini.hbs` and restart again. |
| `429` errors from an agent | Rate limit reached | Raise the limit in Bricks > Bricks MCP Extensions. |
| Only `bricks-mcp/*` tools appear | Bricks abilities are switched off | Turn them on in Bricks > Settings > AI. |
