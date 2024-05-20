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

                $banner_avatar[$message->uid]['name'] = $avatar['text'] ?? '';
                $banner_avatar[$message->uid]['from'] = $from['mailto'] ?? '';
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
            return ["color" => "#ff5552", "text" => "!", "class" => "warning"];

        // Case 2: Unknown user
        if (!isset($from))
            return ["color" => "#adb5bd", "text" => "?"];

        // Get sender's info & gravatar
        $name = $from['name'];
        $mailto = $from['mailto'];
        $image = $this->getAvatarImage($mailto);

        // Case 3: User with gravatar
        if ($image)
            return ["image" => $image];

        // Get first letter of name
        $name = empty($from['name']) ? $from['mailto'] : $from['name'];
        $name = preg_replace('/[^A-Za-z0-9 ]/', '', $name);
        $name = strtoupper($name[0]);

        // Generate colour based on email address
        $color = $this->getAvatarColor($mailto);

        /* Case 4: Use a letter for the user */
        return ["color" => $color, "text" => $name];
    }

    private function getAvatarImage($address): mixed
    {
        // Generate Gravatar image URL
        $hash = hash('sha256', strtolower(trim($address)));
        $image = 'https://gravatar.com/avatar/' . $hash;

        // Test if Gravatar image exists
        $headers = get_headers($image, 1);
        $status_line = $headers[0];
        preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);

        return ((int)$match[1] === 200) ? $image : null;
    }

    private function getAvatarColor($address, $factor = 20): string
    {
        // Original colour
        $color = substr(md5($address), 0, 6);

        // Get factors
        list($r, $g, $b) = sscanf($color, "%02x%02x%02x");
        $factor = (100 - $factor) / 100;

        // Darken each color component
        $r = max(0, min(255, round($r * $factor)));
        $g = max(0, min(255, round($g * $factor)));
        $b = max(0, min(255, round($b * $factor)));

        // Convert back to hex
        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }
}
