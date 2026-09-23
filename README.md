# Roundcube Autosignature

Appends a text or HTML block (company signature, legal disclaimer, banner...)
to every message sent from [Roundcube](https://roundcube.net) webmail.

The block is loaded when the message is sent, from a file on the server or
from an external URL. The URL can carry the sender's data, so a single
intranet page can serve a personal signature to each user:

```
https://intranet.example.com/signature.html?id={username}
```

## Features

- **Added on send**, server side: users cannot remove or edit it, and it does
  not appear in the compose screen nor in drafts.
- **HTML or plain text** source. The source is told the format of the
  message (`{format}` placeholder, `Accept` header) and may provide a version
  for each; otherwise the block is converted (HTML to text for plain text
  messages, text to HTML otherwise).
- **Placeholders** in the source path/URL and in the content itself:
  `{username}`, `{email}`, `{local}`, `{domain}`, `{name}`.
- **Fallback sources**: a list of sources tried in order, e.g. a file per
  user, then a default file.
- **Cache** of remote blocks per user, with a configurable lifetime.
- **Before the history** in replies and forwards: the block goes between
  the answer and the quoted (or forwarded) message.
- **Read-only preview** in the settings: users see the HTML and text versions
  of their block, for each identity, without seeing the source URL.
- **Failure policy**: send without the block, or refuse to send.

## Requirements

- Roundcube 1.6 or later
- PHP 8.0 or later

## Installation

```bash
composer require cymdeveloppement/roundcube-autosignature
```

Then copy and edit the configuration file:
```bash
cp plugins/roundcube_autosignature/config.inc.php.dist plugins/roundcube_autosignature/config.inc.php
```

Add the plugin to your Roundcube configuration:
```php
$config['plugins'] = ['roundcube_autosignature'];
```

## Configuration

All options are documented in `config.inc.php.dist`.

| Option | Default | Description |
|---|---|---|
| `autosignature_source` | `signatures/default.html` | URL or file (absolute, or relative to the plugin folder). A list of sources is tried in order. |
| `autosignature_type` | `auto` | `auto`, `html` or `text`. `auto` uses the `Content-Type` of URLs and the extension of files (`.txt` or `.text` = text). |
| `autosignature_replace_vars` | `true` | Also replace the placeholders inside the content. |
| `autosignature_timeout` | `5` | Timeout of the HTTP request, in seconds. |
| `autosignature_http_headers` | `[]` | Extra HTTP headers, e.g. `['Authorization' => 'Bearer xxx']`. |
| `autosignature_cache_ttl` | `3600` | Cache lifetime of remote blocks, in seconds (`0` = no cache). |
| `autosignature_on_error` | `skip` | `skip` sends without the block, `block` refuses to send. |
| `autosignature_reply_position` | `auto` | Replies and forwards: `auto`, `before` (before the history) or `end`. See below. |
| `autosignature_text_separator` | `"\n\n"` | Inserted before the block in plain text messages. |

### Placeholders

| Placeholder | Value |
|---|---|
| `{username}` | Roundcube login |
| `{email}` | Address of the sender identity |
| `{local}` | Part of `{email}` before the `@` |
| `{domain}` | Part of `{email}` after the `@` |
| `{name}` | Name of the sender identity |
| `{format}` | Format of the message being sent: `html` or `text`. Sources only (URL or file path), not replaced in the content. |

In URLs the values are URL-encoded. In file paths, `/` and `\` are removed
so a value cannot leave the configured folder. In HTML content they are
HTML-escaped.

### Examples

One signature per user, served by the intranet:
```php
$config['autosignature_source'] = 'https://intranet.example.com/signature.html?id={username}';
```

One signature per user, in the format of each message (see
[Choosing the format](#choosing-the-format)):
```php
$config['autosignature_source'] = 'https://intranet.example.com/signature.php?id={username}&format={format}';
```

One file per user, with an optional hand-written text version
(`yann@pcm-ensemblier.com.html`, `yann@pcm-ensemblier.com.text`) and a common
fallback:
```php
$config['autosignature_source'] = [
    '/etc/roundcube/signatures/{email}.{format}',
    '/etc/roundcube/signatures/{email}.html',   // no .text file: converted
    '/etc/roundcube/signatures/default.html',
];
```

A per domain disclaimer, mandatory:
```php
$config['autosignature_source'] = 'disclaimers/{domain}.txt';
$config['autosignature_on_error'] = 'block';
```

### Position in replies and forwards

The history starts at the reply header added by Roundcube ("On ..., X
wrote:"), at the first quoted block if the user removed that header, or at
the "Original Message" header of a forwarded message.

With `auto`, the block goes before the history, except in replies when the
user writes below the quote (Settings > Preferences > Composing Messages >
"When replying: start new message below the quote", the Roundcube default):
the answer then comes after the quote, so the block stays at the end.

## Remote source: what the server must return

### Request

Roundcube sends a plain `GET` request to the configured URL, with the
placeholders replaced and URL-encoded:

```
GET /signature.php?id=yann%40pcm-ensemblier.com&format=html HTTP/1.1
Host: intranet.example.com
Accept: text/html                  <- text/plain for a plain text message
Authorization: Bearer xxxxx        <- only if set in autosignature_http_headers
```

The request comes from the Roundcube server, not from the user's browser: no
cookie nor user session is sent. To restrict access, use a token in
`autosignature_http_headers` or filter on the IP address of the Roundcube
server.

### Response

| | |
|---|---|
| **Status** | `200` only. Any other status (`404`, `500`...) counts as "no signature". Redirects are followed. |
| **Content-Type** | `text/html` for an HTML block, `text/plain` for a text block. Any other or missing type is treated as HTML. `autosignature_type` forces the type whatever the header says. |
| **Charset** | Taken from the `charset=` parameter of the `Content-Type` (e.g. `text/html; charset=ISO-8859-1`) and converted. Without it, UTF-8 is assumed. |
| **Body** | An HTML fragment, or a full HTML document: only the content of its `<body>` is kept (the whole `<head>` is dropped). An empty body counts as "no signature". |
| **Time** | Must answer within `autosignature_timeout` seconds (5 by default). |

When the response counts as "no signature", the next source of the list is
tried, then `autosignature_on_error` applies. For a user without signature,
answering `404` is therefore the way to fall back on a default source.

### Choosing the format

Messages are written either in HTML or in plain text, depending on the
editor used. The plugin tells the server which one is being sent, in two
ways:

- the **`{format}` placeholder**, if the URL contains it: `html` or `text`;
- the **`Accept` header**, always sent: `text/html` or `text/plain`
  (unless `autosignature_http_headers` sets its own `Accept`).

The server then chooses what to return, and says what it returned with the
`Content-Type` of the response:

| Message | Server returns | Result |
|---|---|---|
| HTML | `text/html` | Used as is. |
| HTML | `text/plain` | Escaped, line breaks become `<br>`: no formatting, no clickable link. |
| Text | `text/plain` | Used as is. |
| Text | `text/html` | Converted: paragraphs and `<br>` become line breaks, tags are removed, links become numbered notes (`site [1]`) listed under a `Links:` heading at the end of the block. |

So a server has two options:

- **Always return HTML** and ignore the requested format: the simplest, the
  plugin builds the text version.
- **Return the requested format**, to control the text version (layout,
  links written in full...).

Both versions can be checked in the user's settings page, which requests each
format separately. When `autosignature_type` is set to `html` or `text`, the
`Content-Type` of the response is ignored.

### Writing the HTML

The block is inserted in an email, not in a web page. Email clients (Outlook,
Gmail...) only support a limited subset of HTML:

- Use **inline styles** (`style="..."`) only: many email clients ignore
  `<style>` blocks and external stylesheets, and a `<style>` in the `<head>`
  of the response is dropped anyway.
- Use **absolute `https://` URLs** for images, served publicly. Set `width`
  and `height`. Many clients block remote images until the reader allows them.
- Prefer simple tags (`<p>`, `<br>`, `<b>`, `<a>`, `<img>`, `<table>` for
  layout). No JavaScript, no forms.
- Keep it short: the block is added to every message and repeated in each
  quoted reply.

The content is inserted as is, without filtering: the server must be trusted.
Values coming from users or a directory (names, titles) must be HTML-escaped
by the server.

### Placeholders in the response

With `autosignature_replace_vars` enabled (default), `{name}`, `{email}`,
`{username}`, `{local}` and `{domain}` are also replaced in the response
(HTML-escaped in HTML). The server may thus return the same template for
everyone and let Roundcube fill in the sender. `{email}` and `{name}` are
those of the identity chosen in the "From" field, which the server does not
know unless they are passed in the URL.

### Cache

A response is kept for `autosignature_cache_ttl` seconds for each user, URL
and format, so a change on the server shows in sent messages after that delay at
most. The cache of a user is refreshed as soon as they open their "Automatic
signature" settings page. Error responses are never cached.

### Example endpoint (PHP)

```php
<?php
// signature.php?id={username}&format={format}
$users = [
    'yann@pcm-ensemblier.com' => ['name' => 'Yann Challet', 'title' => 'Developer', 'phone' => '+33 1 23 45 67 89'],
];

$user = $users[$_GET['id'] ?? ''] ?? null;
if (!$user) {
    http_response_code(404);   // no signature: next source, or on_error policy
    exit;
}

$e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Requested format: the {format} parameter, else the Accept header
$format = $_GET['format'] ?? (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/plain') ? 'text' : 'html');

if ($format == 'text') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "{$user['name']}\n{$user['title']}\nTel. {$user['phone']}\nhttps://www.example.com";
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
echo '<table cellpadding="0" cellspacing="0" style="font-family: Arial, sans-serif; font-size: 13px; color: #333;">'
    . '<tr><td style="padding-right: 12px;"><img src="https://www.example.com/logo.png" width="80" height="80" alt="Logo"></td>'
    . '<td><b>' . $e($user['name']) . '</b><br>' . $e($user['title']) . '<br>'
    . '<a href="tel:' . $e($user['phone']) . '" style="color: #0a58ca;">' . $e($user['phone']) . '</a></td></tr>'
    . '</table>';
```

## Settings page

A read-only "Automatic signature" page is added to the settings. It shows the
HTML version (in a sandboxed frame) and the text version of the block, with an
identity selector when the user has several. The source URL is never shown.
Opening the page reloads the block from its source and refreshes the cache.

## Notes

- New messages get the block at the very end. In HTML messages it is wrapped
  in `<div id="autosignature">`.
- The content of the source is trusted: it is inserted as is in the message.
  Only use sources you control.
- If the remote page returns a full HTML document, only the content of its
  `<body>` is kept.
- The signature of the Roundcube identity is not affected: both can be used
  together.

## License

MIT, see [LICENSE](LICENSE).
