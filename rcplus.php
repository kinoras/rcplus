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
    private $virusStatusHeader;
    private $virusDescriptionHeader;
    private $hashHeader;
    private $salt;
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
        $this->virusStatusHeader = $RCMAIL->config->get('rcp_virusStatusHeader');
        $this->virusDescriptionHeader = $RCMAIL->config->get('rcp_virusDescriptionHeader');
        $this->hashHeader = $RCMAIL->config->get('rcp_hashHeader');
        $this->salt = $RCMAIL->config->get('rcp_salt');
        $this->spfHeader = $RCMAIL->config->get('rcp_spfHeader');
    }

    public function storageInit($args)
    {
        $args['fetch_headers'] = trim($args['fetch_headers']
            . ' ' . $this->spamStatusHeader
            . ' ' . $this->spamReasonHeader
            . ' ' . $this->spamDescriptionHeader
            . ' ' . $this->virusStatusHeader
            . ' ' . $this->virusDescriptionHeader
            . ' ' . $this->hashHeader
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
                <div class="notice warning"><div class="content">
                    <b>SPF Check Not Passed</b>
                    <span>This email did not pass the SPF check, indicating possible spoofing. Avoid clicking links or replying with personal information.</span>
                </div></div>
            ');
        }

        $status = $this->getWarningStatus($message->headers, true);
        if ($status !== -2) {
            $message = $this->getWarningMessage($status, $message->headers);
            $element = "<div class='notice $message[0]'><div class='content'>";
            $element .= "<span class='rcp-notice-title'>$message[1]</span>";
            $element .= "<span class='rcp-notice-content'>$message[2]</span>";
            if (isset($message[3])) $element .= "<span class='rcp-notice-remarks'>$message[3]</span>";
            $element .= "</div></div>";
            array_push($content, $element);
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
                $spam = $this->getWarningStatus($message) >= 1 || $this->isSpfPass($message);

                // Get avatar
                $avatar = $this->getAvatar($from, $spam);

                $banner_avatar[$message->uid]['from'] = $from['mailto'] ?? '';
                $banner_avatar[$message->uid]['bold'] = $avatar['bold'] ?? false;
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

    /**
     * Get avatar of a user
     * @param mixed $from 
     * @param bool $spam 
     * @return array 
     *  - `color` (string): Background colour of the avatar (when no gravatar profile)
     *  - `text` (string): The letter in the middle of the avatar (when no gravatar profile)
     *  - `image` (string): The gravatar url of the user (transparent image when no gravatar profile)
     *  - `bold` (bool): Whether the letter in the middle should be bold (error) (false by default)
     */
    private function getAvatar($from, $spam = false): array
    {
        // Case 1: Spam mail
        if ($spam)
            return ['color' => '#ff5552', 'text' => '!', 'image' => 'https://gravatar.com/avatar/?s=96&d=blank', 'bold' => true];

        // Case 2: Unknown user
        if (!isset($from))
            return ['color' => '#adb5bd', 'text' => '?', 'image' => 'https://gravatar.com/avatar/?s=96&d=blank', 'bold' => true];

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

    /**
     * Get warning status of an email
     * @param mixed $headers 
     * @param bool $checksum Whether to check data integrity (affect performance)
     * @return int One of the following status codes:
     *  - -2: Neutral (cannot evaluate, show no message)
     *  - -1: Error (error occured, show notice)
     *  -  0: Pass (show OK message)
     *  -  1: Failed (show warning)
     *  -  2: Failed (report modified) (show warning)
     */
    private function getWarningStatus($headers, $checksum = false): int
    {
        $spam = $this->first($headers->others[strtolower($this->spamStatusHeader)]);
        $virus = $this->first($headers->others[strtolower($this->virusStatusHeader)]);
        if ($checksum) {
            $spamReason = $this->first($headers->others[strtolower($this->spamReasonHeader)]) ?? '';
            $spamDescription = $this->first($headers->others[strtolower($this->spamDescriptionHeader)]) ?? '';
            $virusDescription = $this->first($headers->others[strtolower($this->virusDescriptionHeader)]) ?? '';
            $hash = $this->first($headers->others[strtolower($this->hashHeader)]) ?? '';
            $salt = "iy9Rd@CG!MemBt";
            if (!isset($spam) || !isset($virus) || !isset($spamReason) || !isset($spamDescription) || !isset($virusDescription) || !isset($hash)) return -2;
            if (hash('sha256', $spam . $spamReason . $spamDescription . $virus . $virusDescription . $salt) !== $hash) return 2;
        }
        if ((isset($spam) && $spam === 'Probable' || $spam === 'Potential') || (isset($virus) && $virus === 'Failed')) return 1;
        if (!isset($spam) || !isset($virus) || $spam === 'Error' || $virus === 'Error') return -1;
        return 0;
    }

    /**
     * Get strings for warning box
     * @param mixed $status Return value of `getWarningStatus`
     * @param mixed $headers 
     * @return array
     *  - [0]: Type of message box
     *  - [1]: Spam information title
     *  - [2]: Spam information description
     *  - [3]: Virus information description
     */
    private function getWarningMessage($status, $headers): array
    {
        if ($status === -2)
            return ["", "", ""];

        if ($status === 2)
            return ["error", "Modified Scan Result", "The spam scan result for this email has been altered. Please review with care and avoid clicking on any suspicious links."];

        $spamStatus = $this->first($headers->others[strtolower($this->spamStatusHeader)]) ?? '';
        $spamReason = $this->first($headers->others[strtolower($this->spamReasonHeader)]) ?? '';
        $spamDescription = $this->first($headers->others[strtolower($this->spamDescriptionHeader)]) ?? '';
        $virusStatus = $this->first($headers->others[strtolower($this->virusStatusHeader)]);
        $virusDescription = $this->first($headers->others[strtolower($this->virusDescriptionHeader)]) ?? '';

        if ($spamStatus === "Error" && $virusStatus === "Error")
            return ["information", "Unable to Inspect This Email", "We're currently to check this email for spam or virus due to an error. Please be cautious and avoid clicking on any suspicious links or attachments."];

        $type = ($status === -1) ? "information" : (($status === 0) ? "confirmation" : "error");

        $spamTitle = ($spamStatus !== "Error") ? "$spamStatus Spam: $spamReason" : "Unable to Scan for Spam";
        $spamText = ($spamStatus !== "Error") ? $spamDescription : "We're currently to check this email for spam due to an error. Please be cautious and avoid clicking on any suspicious links.";
        if (!isset($virusStatus) || $virusStatus === "Skipped")
            return [$type, $spamTitle, $spamText];

        $virusText = ($virusStatus !== "Error") ? $virusDescription : "We're currently to check this email for spam due to an error. Please be cautious and avoid clicking on any suspicious links.";
        return [$type, $spamTitle, $spamText, $virusText];
    }
}
