<?php

/**
 * Roundcube Autosignature
 *
 * Appends a text or HTML block (signature, disclaimer...) to every sent
 * message, loaded from a file on the server or from an external URL.
 *
 * @license   MIT License: <http://opensource.org/licenses/MIT>
 * @author    Yann Challet (CymDeveloppement)
 * @category  Plugin for RoundCube WebMail
 */
class roundcube_autosignature extends rcube_plugin
{
    public $task = 'mail|settings';

    /** Set when the block could not be loaded for the message being sent */
    private $failed = false;

    /** @var rcmail */
    private $rc;

    function init()
    {
        $this->rc = rcmail::get_instance();
        $this->load_config('config.inc.php.dist');
        $this->load_config('config.inc.php');

        if ($this->rc->task == 'settings') {
            $this->add_texts('localization/');
            $this->include_stylesheet($this->local_skin_path() . '/roundcube_autosignature.css');
            $this->add_hook('settings_actions', array($this, 'settings_actions'));
            $this->register_action('plugin.autosignature', array($this, 'settings_page'));
        } else {
            $this->add_hook('message_outgoing_body', array($this, 'message_outgoing_body'));
            $this->add_hook('message_before_send', array($this, 'message_before_send'));
        }
    }

    /**
     * Adds the block to the body of the message being sent
     */
    function message_outgoing_body($args)
    {
        // Only messages sent from the compose screen (not drafts, nor the
        // messages built by other plugins such as markasjunk). The plain text
        // alternative of HTML messages is generated from the HTML part, which
        // already contains the block.
        if ($this->rc->action != 'send' || !empty($_POST['_draft']) || $args['type'] == 'alternative') {
            return $args;
        }

        $from = rcube_utils::get_input_string('_from', rcube_utils::INPUT_POST, true);
        $html = $args['type'] == 'html';
        $content = $this->render($this->sender_vars($from), $html ? 'html' : 'text');

        if ($content === null) {
            $this->failed = true;
            return $args;
        }

        // The body is in the charset of the message
        $charset = rcube_utils::get_input_string('_charset', rcube_utils::INPUT_POST) ?: $this->rc->output->get_charset();
        if ($charset && strtoupper($charset) != RCUBE_CHARSET) {
            $content = rcube_charset::convert($content, RCUBE_CHARSET, $charset);
        }

        $body = $args['body'];
        $pos = $this->block_position($body, $html);

        if ($html) {
            $content = '<div id="autosignature">' . $content . '</div>' . "\r\n";
            $args['body'] = substr($body, 0, $pos) . $content . substr($body, $pos);
        } else {
            $separator = (string) $this->rc->config->get('autosignature_text_separator', "\n\n");
            $before = rtrim(substr($body, 0, $pos));
            $after = substr($body, $pos);

            $args['body'] = ($before === '' ? '' : $before . $separator) . trim($content)
                . ($after === '' ? '' : "\n\n" . $after);
        }

        return $args;
    }

    /**
     * Refuses to send the message when the block is missing, if configured so
     */
    function message_before_send($args)
    {
        if ($this->failed && $this->rc->config->get('autosignature_on_error') == 'block') {
            $this->add_texts('localization/');

            $args['abort'] = true;
            $args['error'] = array('label' => 'roundcube_autosignature.unavailable', 'vars' => array());
        }

        return $args;
    }

    /**
     * Adds the "Automatic signature" page to the settings menu
     */
    function settings_actions($args)
    {
        $args['actions'][] = array(
            'action' => 'plugin.autosignature',
            'class'  => 'autosignature',
            'label'  => 'autosignature',
            'domain' => 'roundcube_autosignature',
            'title'  => 'autosignature',
        );

        return $args;
    }

    /**
     * Settings page showing the block, read only
     */
    function settings_page()
    {
        $this->register_handler('plugin.body', array($this, 'settings_body'));

        $this->rc->output->set_pagetitle($this->gettext('autosignature'));
        $this->rc->output->send('plugin');
    }

    /**
     * Content of the settings page: HTML and text previews of the block,
     * for the identity chosen in the list
     */
    function settings_body()
    {
        $identities = $this->rc->user->list_identities();
        $iid = rcube_utils::get_input_string('_iid', rcube_utils::INPUT_GET);
        $identity = null;

        foreach ($identities as $item) {
            if ($item['identity_id'] == $iid || ($identity === null && $iid === null)) {
                $identity = $item;
            }
        }

        $vars = $this->sender_vars($identity ? $identity['identity_id'] : null);
        $table = new html_table(array('cols' => 2, 'class' => 'propform'));

        if (count($identities) > 1) {
            $select = new html_select(array('name' => '_iid', 'id' => 'autosignature-identity', 'onchange' => 'this.form.submit()'));
            foreach ($identities as $item) {
                $select->add(format_email_recipient($item['email'], $item['name']), $item['identity_id']);
            }

            $form = html::tag('form', array('method' => 'get', 'action' => './'),
                html::tag('input', array('type' => 'hidden', 'name' => '_task', 'value' => 'settings'))
                . html::tag('input', array('type' => 'hidden', 'name' => '_action', 'value' => 'plugin.autosignature'))
                . $select->show($identity ? $identity['identity_id'] : null));

            $table->add('title', html::label('autosignature-identity', rcube::Q($this->gettext('identity'))));
            $table->add('', $form);
        }

        // Always load a fresh copy: the page also refreshes the cache
        $html = $this->render($vars, 'html', true);

        if ($html === null) {
            $table->add('title', '');
            $table->add('', html::p('autosignature-none', rcube::Q($this->gettext('none'))));
        } else {
            $doc = '<!DOCTYPE html><html><head><meta charset="' . RCUBE_CHARSET . '">'
                . '<style>body { margin: 8px; font-family: sans-serif; font-size: 14px; }</style>'
                . '</head><body>' . $html . '</body></html>';

            $table->add('title', rcube::Q($this->gettext('html_version')));
            $table->add('', html::tag('iframe', array(
                'class'   => 'autosignature-html',
                'srcdoc'  => $doc,
                'sandbox' => '',
                'title'   => $this->gettext('html_version'),
            ), ''));

            $table->add('title', rcube::Q($this->gettext('text_version')));
            $table->add('', html::tag('pre', 'autosignature-text', rcube::Q($this->render($vars, 'text', true), 'strict', false)));
        }

        $out = html::p('autosignature-info', rcube::Q($this->gettext('info')))
            . html::tag('fieldset', '', html::tag('legend', '', rcube::Q($this->gettext('preview'))) . $table->show());

        return html::div(array('class' => 'box formcontent'),
            html::div(array('class' => 'boxtitle'), rcube::Q($this->gettext('autosignature')))
            . html::div(array('class' => 'boxcontent formcontainer'), $out)
        );
    }

    /**
     * Builds the block for a sender, in the format of the message
     *
     * @param array  $vars    Placeholder values
     * @param string $format  'html' or 'text'
     * @param bool   $refresh Ignore the cached copy of remote blocks
     *
     * @return string|null Block (UTF-8), null if no source answered
     */
    private function render($vars, $format, $refresh = false)
    {
        $block = $this->load_block($vars, $format, $refresh);
        if ($block === null) {
            return null;
        }

        list($content, $type) = $block;

        if ($this->rc->config->get('autosignature_replace_vars', true)) {
            $content = $this->replace_vars($content, $vars, $type == 'html' ? 'html' : null);
        }

        if ($format == 'html' && $type == 'text') {
            $content = nl2br(rcube::Q(trim($content), 'strict', false));
        } elseif ($format == 'text' && $type == 'html') {
            $content = $this->rc->html2text($content, array('width' => 0, 'charset' => RCUBE_CHARSET));
        }

        return trim($content);
    }

    /**
     * Position of the block in the body: before the history (quoted reply
     * or forwarded message) when there is one, at the end otherwise
     *
     * @param string $body Message body
     * @param bool   $html HTML body
     *
     * @return int Offset in the body
     */
    private function block_position($body, $html)
    {
        $end = $html && ($pos = strripos($body, '</body>')) !== false ? $pos : strlen($body);
        $position = $this->rc->config->get('autosignature_reply_position', 'auto');

        // Only answers and forwards have a history, a new message may contain
        // pasted quotes
        $compose = $_SESSION['compose_data_' . rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC)] ?? array();
        $modes = array(rcmail_sendmail::MODE_REPLY, rcmail_sendmail::MODE_FORWARD, rcmail_sendmail::MODE_DRAFT, rcmail_sendmail::MODE_EDIT);

        if ($position == 'end' || !in_array($compose['mode'] ?? '', $modes)) {
            return $end;
        }

        $forward = '-------- ' . $this->rc->gettext('originalmessage') . ' --------';
        $found = array();

        if ($html) {
            // Reply header added by Roundcube, or first quoted block if the
            // user removed it
            if (preg_match('/<p\b[^>]*\bid="reply-intro"/i', $body, $m, PREG_OFFSET_CAPTURE)) {
                $found[$m[0][1]] = 'reply';
            } elseif (preg_match('/<blockquote\b[^>]*\btype="cite"/i', $body, $m, PREG_OFFSET_CAPTURE)) {
                $found[$m[0][1]] = 'reply';
            }

            // Header of a forwarded message, from the paragraph containing it
            if (($pos = strpos($body, $forward)) !== false) {
                $start = strripos(substr($body, 0, $pos), '<p');
                $found[$start === false ? $pos : $start] = 'forward';
            }
        } else {
            // First quoted line, with the "X wrote:" line above it
            if (preg_match('/^>/m', $body, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                if (preg_match('/(?:^|\n)([^\n>][^\n]*)\n$/', substr($body, 0, $pos), $m, PREG_OFFSET_CAPTURE)) {
                    $pos = $m[1][1];
                }
                $found[$pos] = 'reply';
            }

            if (preg_match('/^' . preg_quote($forward, '/') . '\s*$/m', $body, $m, PREG_OFFSET_CAPTURE)) {
                $found[$m[0][1]] = 'forward';
            }
        }

        if (!$found) {
            return $end;
        }

        ksort($found);
        $pos = key($found);

        // When the answer is written below the quote, the end of the message
        // is the right place
        if ($found[$pos] == 'reply' && $position == 'auto' && (int) $this->rc->config->get('reply_mode') <= 0) {
            return $end;
        }

        return $pos;
    }

    /**
     * Data of the sender, used to fill the placeholders
     *
     * @param string|null $from Identity ID, or an address typed by the user
     *
     * @return array Placeholder name => value
     */
    private function sender_vars($from = null)
    {
        $user = $this->rc->user;
        $email = '';
        $name = '';

        if (is_numeric($from) && ($identity = $user->get_identity($from))) {
            $email = $identity['email'];
            $name = $identity['name'];
        } elseif ($from && ($addresses = rcube_mime::decode_address_list($from, 1, false))) {
            $address = reset($addresses);
            $email = $address['mailto'];
            $name = $address['name'];
        }

        if (!$email && ($identity = $user->get_identity())) {
            $email = $identity['email'];
            $name = $name ?: $identity['name'];
        }

        $at = strrpos($email, '@');

        return array(
            'username' => (string) $this->rc->get_user_name(),
            'email' => (string) $email,
            'local' => $at === false ? (string) $email : substr($email, 0, $at),
            'domain' => $at === false ? '' : substr($email, $at + 1),
            'name' => (string) $name,
        );
    }

    /**
     * Loads the block from the first source that returns content
     *
     * @param array  $vars    Placeholder values
     * @param string $format  Format of the message, 'html' or 'text'
     * @param bool   $refresh Ignore the cached copy of remote blocks
     *
     * @return array|null Content and its type ('html' or 'text'), null if no source answered
     */
    private function load_block($vars, $format, $refresh = false)
    {
        // {format} is only available in sources: the server or the file may
        // provide a version written for the format of the message
        $source_vars = $vars + array('format' => $format);

        foreach ((array) $this->rc->config->get('autosignature_source') as $source) {
            if (!is_string($source) || trim($source) === '') {
                continue;
            }

            $remote = (bool) preg_match('#^https?://#i', $source);
            $block = $remote
                ? $this->load_url($this->replace_vars($source, $source_vars, 'url'), $format, $refresh)
                : $this->load_file($this->replace_vars($source, $source_vars, 'file'));

            if ($block !== null) {
                $type = $this->rc->config->get('autosignature_type', 'auto');
                if ($type == 'html' || $type == 'text') {
                    $block[1] = $type;
                }

                if ($block[1] == 'html') {
                    $block[0] = $this->html_fragment($block[0]);
                }

                if (trim($block[0]) !== '') {
                    return $block;
                }
            }
        }

        return null;
    }

    /**
     * Loads the block from a URL, through the user cache
     *
     * @param string $url     URL
     * @param string $format  Format of the message, 'html' or 'text'
     * @param bool   $refresh Ignore the cached copy (a fresh one is stored)
     *
     * @return array|null Content and type
     */
    private function load_url($url, $format, $refresh = false)
    {
        $ttl = (int) $this->rc->config->get('autosignature_cache_ttl', 3600);
        $cache = $ttl > 0 ? $this->rc->get_cache('autosignature', 'db', $ttl) : null;
        // The answer may depend on the Accept header, not only on the URL
        $key = md5($format . ':' . $url);

        if ($cache && !$refresh && ($block = $cache->get($key))) {
            return $block;
        }

        try {
            $timeout = (int) $this->rc->config->get('autosignature_timeout', 5);
            $response = $this->rc->get_http_client()->get($url, array(
                'timeout' => $timeout,
                'connect_timeout' => $timeout,
                'headers' => array_merge(
                    array('Accept' => $format == 'text' ? 'text/plain' : 'text/html'),
                    (array) $this->rc->config->get('autosignature_http_headers', array())
                ),
                'http_errors' => false,
            ));
        } catch (Exception $e) {
            rcube::raise_error("autosignature: cannot load $url: " . $e->getMessage(), true);
            return null;
        }

        if ($response->getStatusCode() != 200) {
            rcube::raise_error("autosignature: $url returned HTTP " . $response->getStatusCode(), true);
            return null;
        }

        $content = (string) $response->getBody();
        $content_type = $response->getHeaderLine('Content-Type');
        $type = stripos($content_type, 'text/plain') === 0 ? 'text' : 'html';

        if (preg_match('/charset=["\']?([\w-]+)/i', $content_type, $m) && strtoupper($m[1]) != RCUBE_CHARSET) {
            $content = rcube_charset::convert($content, $m[1], RCUBE_CHARSET);
        }

        $block = array($content, $type);

        if ($cache) {
            $cache->set($key, $block);
        }

        return $block;
    }

    /**
     * Loads the block from a file on the server
     *
     * @return array|null Content and type
     */
    private function load_file($path)
    {
        if ($path[0] != '/') {
            $path = $this->home . '/' . $path;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $type = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), array('txt', 'text')) ? 'text' : 'html';

        return array($content, $type);
    }

    /**
     * Keeps only the content of the <body> of a full HTML document
     */
    private function html_fragment($html)
    {
        if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $m)) {
            $html = $m[1];
        }

        return trim($html);
    }

    /**
     * Replaces the placeholders in a string
     *
     * @param string      $string String with {placeholders}
     * @param array       $vars   Placeholder values
     * @param string|null $mode   'url' (URL-encoded), 'file' (safe file name part),
     *                            'html' (HTML-escaped) or null (raw)
     */
    private function replace_vars($string, $vars, $mode = null)
    {
        $replace = array();

        foreach ($vars as $name => $value) {
            switch ($mode) {
                case 'url':
                    $value = rawurlencode($value);
                    break;
                case 'file':
                    // A value must not be able to leave the configured folder
                    $value = str_replace(array('/', '\\', "\0"), '', $value);
                    $value = $value == '.' || $value == '..' ? '' : $value;
                    break;
                case 'html':
                    $value = rcube::Q($value);
                    break;
            }

            $replace['{' . $name . '}'] = $value;
        }

        return strtr($string, $replace);
    }
}
