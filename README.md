# NodePulse — Node Guide

## Do not delete

These folders and files are essential for your node to work on the Plexum network:

- **`nodepulse/`** — Node backend (API, gossip, cryptographic verification, configuration)
- **`beacon/`** — Recovery Browser: lets visitors find your node even when the tunnel URL changes
- **`nodepulse-sw.js`** — Service Worker registration script and connectivity monitor
- **`cli/`** — Shell Manager do not remove or rename if want to use shell
- **`auth_gate.php`** — Shared session authentication gate for all protected apps
- **`change-password.php`** — Password change page for the auth gate
- **`cli-auth.php`** — Internal auth endpoint used by nginx `auth_request` for the shell
- **`cli-login.php`** — Login wrapper that routes unauthenticated shell access through the auth gate
- **`logout.php`** — Logout from all procedure

If removed, the node will stop working on the network.

## Everything else is yours

You can remove any other file or folder and deploy whatever you want: your website, your app, your cms, shop, blog.

The only requirement to integrate your content into the Plexum network is to include `nodepulse-sw.js` in every HTML page, for example in the footer:

```html
<script src="/nodepulse-sw.js"></script>
```

### What this script does

When a visitor opens one of your pages, the script does two things:

1. **Registers a Service Worker** with scope `/` that caches the beacon pages. If the tunnel goes down and the site becomes unreachable, the Service Worker automatically serves the Recovery Browser from the visitor's browser cache — no server needed.

2. **Monitors connectivity** with a ping every 5 seconds. After 2 consecutive failed pings, it saves the current page and redirects the visitor to the Recovery Browser (`/beacon/`), where they can look up the node's new address on the network and return exactly where they were.

In short: your visitors never lose you. Even if the tunnel URL changes, their browser already knows how to find you again.
