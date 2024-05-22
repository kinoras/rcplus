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

    private $showAvatar;
    private $mailRegex;
    private $spamStatusHeader;
    private $spamReasonHeader;
    private $spamDescriptionHeader;
    private $spamHashHeader;
    private $spfHeader;

    function init()
    {
        $this->load_config('config.inc.php.dist');
        $this->load_config('config.inc.php');

        $this->include_script('rcplus.js');
        $this->include_stylesheet('rcplus.css');

        $this->add_hook('storage_init', array($this, 'storageInit'));
        $this->add_hook('message_objects', array($this, 'message_objects'));
        $this->add_hook('messages_list', array($this, 'messageList'));

        // Get RCMAIL object
        $RCMAIL = rcmail::get_instance();

        // Get config
        $this->showAvatar = $RCMAIL->config->get('rcp_showAvatar');
        $this->mailRegex = $RCMAIL->config->get('rcp_mailRegex');
        $this->spamStatusHeader = $RCMAIL->config->get('rcp_spamStatusHeader');
        $this->spamReasonHeader = $RCMAIL->config->get('rcp_spamReasonHeader');
        $this->spamDescriptionHeader = $RCMAIL->config->get('rcp_spamDescriptionHeader');
        $this->spamHashHeader = $RCMAIL->config->get('rcp_spamHashHeader');
        $this->spfHeader = $RCMAIL->config->get('rcp_spfHeader');
    }

    public function storageInit($args)
    {
        $args['fetch_headers'] = trim($args['fetch_headers']
            . ' ' . $this->spamStatusHeader
            . ' ' . $this->spamReasonHeader
            . ' ' . $this->spamDescriptionHeader
            . ' ' . $this->spamHashHeader
            . ' ' . $this->spfHeader);
        return $args;
    }

    public function message_objects($args)
    {
        $content = $args['content'];
        $message = $args['message'];

        // Safety check
        if ($message === NULL || $message->sender === NULL || $message->headers->others === NULL) {
            return array();
        }

        // Outside organisation warning
        if ($this->isExternal($message->sender['mailto'])) {
            // array_push($content, '<div class="notice warning"></div>');
        }

        // Check Received-SPF
        if ($this->isSpfPass($message->headers)) {
            array_push($content, '
                <div class="notice warning">
                    <b>SPF Check Not Passed</b><br>
                    <span>This email did not pass the SPF check, indicating possible spoofing. Avoid clicking links or replying with personal information.</span>
                </div>
            ');
        }

        array_push($content, $this->getSpamMessage($message->headers));

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
                $spam = $this->getSpamStatus($message) >= 1 || $this->isSpfPass($message);
                $unknown = $this->getSpamStatus($message) === -1;

                // Get avatar
                $avatar = $this->getAvatar($from, $spam, $unknown);

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

    private function isExternal($address): bool
    {
        return !preg_match($this->mailRegex, $address);
    }

    private function isSpfPass($headers): bool
    {
        $spfStatus = $this->first($headers->others[strtolower($this->spfHeader)]);
        return (isset($spfStatus) && (strpos(strtolower($spfStatus), 'pass') !== 0));
    }

    private function getAvatar($from, $spam = false, $unknown = true): array
    {
        // Case 1: Spam mail
        if ($spam)
            return ['color' => '#ff5552', 'text' => '!', 'image' => 'https://gravatar.com/avatar/?s=96&d=blank'];

        // Case 2: Unknown user
        if (!isset($from) || $unknown)
            return ['color' => '#adb5bd', 'text' => '?', 'image' => 'https://gravatar.com/avatar/?s=96&d=blank'];

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

    private function getSpamStatus($headers): int
    {
        $status = $this->first($headers->others[strtolower($this->spamStatusHeader)]);
        $reason = $this->first($headers->others[strtolower($this->spamReasonHeader)]) ?? '';
        $description = $this->first($headers->others[strtolower($this->spamDescriptionHeader)]) ?? '';
        $hash = $this->first($headers->others[strtolower($this->spamHashHeader)]) ?? '';
        $salt = "iy9Rd@CG!MemBt";

        if (!isset($status) || hash('sha256', $status . $reason . $description . $salt) !== $hash) return 10;
        if ($status === 'Probable') return 2;
        if ($status === 'Potential') return 1;
        if ($status === 'Unlikely') return 0;
        return -1;
    }

    private function getSpamMessage($headers): string
    {
        $status = $this->getSpamStatus($headers);
        $reason = $this->first($headers->others[strtolower($this->spamReasonHeader)]) ?? '';
        $description = $this->first($headers->others[strtolower($this->spamDescriptionHeader)]) ?? '';
        switch ($status) {
            case -1:
                return '
                    <div class="notice information">
                        <b>Unable to Scan</b><br>
                        <span>We\'re currently to check this email for spam due to an error. Please be cautious and avoid clicking on any suspicious links.</span>
                    </div>
                ';
            case 0:
                return '<div class="notice confirmation"><b>Unlikely Spam: ' . $reason . '</b><br><span>' . $description . '</span></div>';
            case 1:
                return '<div class="notice error"><b>Potential Spam: ' . $reason . '</b><br><span>' . $description . '</span></div>';
            case 2:
                return '<div class="notice error"><b>Probable Spam: ' . $reason . '</b><br><span>' . $description . '</span></div>';
            default:
                return '
                    <div class="notice error">
                        <b>Modified Scan Result</b><br>
                        <span>The spam scan result for this email has been altered. Please review with care and avoid clicking on any suspicious links.</span>
                    </div>
                ';
        }
    }
}

// 