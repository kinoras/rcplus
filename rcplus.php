<?php

/**
 * RC+PLUS
 *
 * Displays warnings to the user under various contexts
 *
 * @license MIT License: <http://opensource.org/licenses/MIT>
 * @author kinoRAS
 * @category  Plugin for RoundCube WebMail
 */
class rcplus extends rcube_plugin
{
    public $task = 'mail';

    private $mailRegex;
    private $x_spam_status_header;
    private $x_spam_level_header;
    private $received_spf_header;
    private $spam_level_threshold;
    private $showAvatar;

    function init()
    {
        $this->load_config('config.inc.php.dist');
        $this->load_config('config.inc.php');

        $this->include_script('rcplus.js');
        $this->include_stylesheet('rcplus.css');

        $this->add_hook('storage_init', array($this, 'storageInit'));
        $this->add_hook('message_objects', array($this, 'warn'));
        $this->add_hook('messages_list', array($this, 'messageList'));

        // Get RCMAIL object
        $RCMAIL = rcmail::get_instance();

        // Get config
        $this->showAvatar = $RCMAIL->config->get('rcp_showAvatar');
        $this->mailRegex  = $RCMAIL->config->get('rcp_mailRegex');
        $this->x_spam_status_header = $RCMAIL->config->get('x_spam_status_header');
        $this->x_spam_level_header = $RCMAIL->config->get('x_spam_level_header');
        $this->received_spf_header = $RCMAIL->config->get('received_spf_header');
        $this->spam_level_threshold = $RCMAIL->config->get('spam_level_threshold');
    }

    public function storageInit($args)
    {
        $args['fetch_headers'] = trim($args['fetch_headers'] . ' ' . strtoupper($this->x_spam_status_header) . ' ' . strtoupper($this->x_spam_level_header) . ' ' . strtoupper($this->received_spf_header));
        return $args;
    }

    public function warn($args)
    {
        $this->add_texts('localization/');

        // Preserve exiting headers
        $content = $args['content'];
        $message = $args['message'];

        // Safety check
        if ($message === NULL || $message->sender === NULL || $message->headers->others === NULL) {
            return array();
        }

        // Warn users if mail from outside organization
        if ($this->addressExternal($message->sender['mailto'])) {
            array_push($content, '<div class="notice warning">' . $this->gettext('from_outsite') . '</div>');
        }

        // Check X-Spam-Status
        if ($this->isSpam($message->headers)) {
            array_push($content, '<div class="notice error">' . $this->gettext('posible_spam') . '</div>');
        }

        // Check Received-SPF
        if ($this->spfFails($message->headers)) {
            array_push($content, '<div class="notice error">' . $this->gettext('spf_fail') . '</div>');
        }

        return array('content' => $content);
    }

    public function messageList($args)
    {
        if (!empty($args['messages'])) {
            $RCMAIL = rcmail::get_instance();

            // Check if avatars enabled
            if (!$this->showAvatar)
                return;

            $banner_avatar = array();
            foreach ($args['messages'] as $index => $message) {
                // Create entry
                $banner_avatar[$message->uid] = array();

                // Parse address and check spam
                $from = rcube_mime::decode_address_list($message->from, 1, true, null, false)[1] ?? null;
                $spam = $this->isSpam($message) || $this->spfFails($message);

                // Get avatar
                $avatar = $this->getAvatar($from, $spam);

                $banner_avatar[$message->uid]['from'] = $from['mailto'] ?? '';
                $banner_avatar[$message->uid]['type'] = $avatar['type'] ?? 'normal';
                $banner_avatar[$message->uid]['text'] = $avatar['text'] ?? '';
                $banner_avatar[$message->uid]['color'] = $avatar['color'] ?? '';
                $banner_avatar[$message->uid]['image'] = $avatar['image'] ?? '';
            }

            $RCMAIL->output->set_env('banner_avatar', $banner_avatar);
            $RCMAIL->output->set_env('banner_showAvatar', $this->showAvatar);
        }

        return $args;
    }

    // ===================================================
    //  Helper Functions
    // ===================================================


    private function first($obj)
    {
        return (isset($obj) && is_array($obj)) ? $obj[0] : $obj;
    }

    private function addressExternal($address)
    {
        return !preg_match($this->mailRegex, $address);
    }

    private function spfFails($headers)
    {
        $spfStatus = $this->first($headers->others[strtolower($this->received_spf_header)]);
        return (isset($spfStatus) && (strpos(strtolower($spfStatus), 'pass') !== 0));
    }

    private function isSpam($headers)
    {
        $spamStatus = $this->first($headers->others[strtolower($this->x_spam_status_header)]);
        if (isset($spamStatus) && (strpos(strtolower($spamStatus), 'yes') === 0)) return true;

        $spamLevel = $this->first($headers->others[strtolower($this->x_spam_level_header)]);
        return (isset($spamLevel) && substr_count($spamLevel, '*') >= $this->spam_level_threshold);
    }

    private function getAvatar($from, $spam = false): array
    {
        // Case 1: Spam mail
        if ($spam)
            return ['color' => '#ff5552', 'text' => '!', 'type' => 'textonly'];

        // Case 2: Unknown user
        if (!isset($from))
            return ['color' => '#adb5bd', 'text' => '?', 'type' => 'textonly'];

        $attrs = array();

        // Get sender's info
        $name = $from['name'];
        $mailto = $from['mailto'];
        $hash = hash('sha256', strtolower(trim($mailto)));

        // Set avatar
        $attrs['image'] = 'https://gravatar.com/avatar/' . $hash . '?s=96&d=blank';

        // Set name
        $name = empty($from['name']) ? $from['mailto'] : $from['name'];
        $name = preg_replace('/[^A-Za-z0-9 ]/', '', $name);
        $attrs['text'] = strtoupper($name[0]);

        // Set background color
        $color = substr(md5($mailto), 0, 6);
        list($r, $g, $b) = sscanf($color, '%02x%02x%02x');
        $r = max(0, min(255, round($r * 0.8)));
        $g = max(0, min(255, round($g * 0.8)));
        $b = max(0, min(255, round($b * 0.8)));
        $attrs['color'] = sprintf('#%02x%02x%02x', $r, $g, $b);

        /* Case 3: Normal user */
        return $attrs;
    }
}
