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
    private $dkimHeader;

    function init()
    {
        $this->load_config('config.inc.php.dist');
        $this->load_config('config.inc.php');

        $this->include_script('rcplus.js');
        $this->include_stylesheet('rcplus.css');

        $this->add_hook('storage_init', array($this, 'storageInit'));
        $this->add_hook('message_objects', array($this, 'messageObjects'));
        $this->add_hook('messages_list', array($this, 'messageList'));

        // Get RCMAIL object
        $rcmail = rcmail::get_instance();

        // Get config
        $this->showAvatar = $rcmail->config->get('rcp_showAvatar');
        $this->mailRegex = $rcmail->config->get('rcp_mailRegex') ?? '/.+/i';
        $this->spamStatusHeader = $rcmail->config->get('rcp_spamStatusHeader');
        $this->spamReasonHeader = $rcmail->config->get('rcp_spamReasonHeader');
        $this->spamDescriptionHeader = $rcmail->config->get('rcp_spamDescriptionHeader');
        $this->virusStatusHeader = $rcmail->config->get('rcp_virusStatusHeader');
        $this->virusDescriptionHeader = $rcmail->config->get('rcp_virusDescriptionHeader');
        $this->hashHeader = $rcmail->config->get('rcp_hashHeader');
        $this->dkimHeader = $rcmail->config->get('rcp_dkimHeader');
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
            . ' ' . $this->dkimHeader);
        return $args;
    }

    public function messageObjects($args)
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

        // $mbox

        return array('content' => $content);
    }

    public function messageList($args)
    {
        if (!empty($args['messages'])) {
            $rcmail = rcmail::get_instance();
            $mymail = $rcmail->user->get_identity()['email'];

            // Check if avatars enabled
            if (!$this->showAvatar)
                return;

            $banner_avatar = array();
            foreach ($args['messages'] as $index => $message) {
                // Create entry
                $banner_avatar[$message->uid] = array();

                // Parse address
                $from = rcube_mime::decode_address_list($message->from, 1, true, null, false)[1] ?? null;
                $to = rcube_mime::decode_address_list($message->to, 1, true, null, false)[1] ?? null;
                $profile = ($from !== $mymail) ? $from : $to;

                // Check spam
                $spam = $this->getWarningStatus($message) >= 1;

                // Get avatar
                $avatar = $this->getAvatar($profile, $spam);

                $banner_avatar[$message->uid]['address'] = $from['mailto'] ?? '';
                $banner_avatar[$message->uid]['bold'] = $avatar['bold'] ?? false;
                $banner_avatar[$message->uid]['text'] = $avatar['text'] ?? '';
                $banner_avatar[$message->uid]['color'] = $avatar['color'] ?? '';
                $banner_avatar[$message->uid]['image'] = $avatar['image'] ?? '';
            }

            $rcmail->output->set_env('banner_avatar', $banner_avatar);
            $rcmail->output->set_env('banner_showAvatar', $this->showAvatar);
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

    /**
     * Get avatar of a user
     * @param mixed $profile 
     * @param bool $spam 
     * @return array 
     *  - `color` (string): Background colour of the avatar (when no gravatar profile)
     *  - `text` (string): The letter in the middle of the avatar (when no gravatar profile)
     *  - `image` (string): The gravatar url of the user (transparent image when no gravatar profile)
     *  - `bold` (bool): Whether the letter in the middle should be bold (error) (false by default)
     */
    private function getAvatar($profile, $spam = false): array
    {
        // Case 1: Spam mail
        if ($spam)
            return ['color' => '#ff5552', 'text' => '!', 'image' => 'https://gravatar.com/avatar/?s=96&d=blank', 'bold' => true];

        // Case 2: Unknown user
        if (!isset($profile))
            return ['color' => '#adb5bd', 'text' => '?', 'image' => 'https://gravatar.com/avatar/?s=96&d=blank', 'bold' => true];

        $attrs = array();

        // Get sender's info
        $name = $profile['name'];
        $mailto = $profile['mailto'];
        $hash = hash('sha256', strtolower(trim($mailto)));

        // Set avatar
        $attrs['image'] = 'https://gravatar.com/avatar/' . $hash . '?s=96&d=blank';

        // Set name
        $name = empty($profile['name']) ? $profile['mailto'] : $profile['name'];
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
        $rcmail = rcmail::get_instance();
        $rcbox = $rcmail->get_storage()->get_folder();

        $spam = $this->first($headers->others[strtolower($this->spamStatusHeader)]);
        $virus = $this->first($headers->others[strtolower($this->virusStatusHeader)]);
        $mailbox = strtoupper(strval($rcbox));

        // Check hash in detail mode
        if ($checksum) {
            $spamReason = $this->first($headers->others[strtolower($this->spamReasonHeader)]);
            $spamDescription = $this->first($headers->others[strtolower($this->spamDescriptionHeader)]);
            $virusDescription = $this->first($headers->others[strtolower($this->virusDescriptionHeader)]);
            $dkim = $this->first($headers->others[strtolower($this->dkimHeader)]) ?? '';
            $hash = $this->first($headers->others[strtolower($this->hashHeader)]);

            // Unable to evaluate, if all fields missing
            if (!isset($spam) && !isset($virus) && !isset($spamReason) && !isset($spamDescription) && !isset($virusDescription) && !isset($hash))
                return -2;

            // Return error, if only some of the fields are missing
            if (!isset($spam) || !isset($virus) || !isset($spamReason) || !isset($spamDescription) || !isset($virusDescription) || !isset($hash))
                return -2;

            // Return error, if hash does't match
            if (hash('sha256', $spam . $spamReason . $spamDescription . $virus . $virusDescription . $dkim) !== $hash)
                return 2;
        }

        // Return junk, if the mail is in Junk mailbox
        if ($mailbox === 'JUNK')
            return 1;

        // Return undecidable, if the spam or virus status is error
        if (!isset($spam) || !isset($virus) || $spam === 'Error' || $virus === 'Error')
            return -1;

        // Otherwise (mail not in junk), return 0
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
        // Check hash in detail mode
        $dkimStatus = $this->first($headers->others[strtolower($this->dkimHeader)]) ?? '';
        $spamStatus = $this->first($headers->others[strtolower($this->spamStatusHeader)]) ?? '';
        $spamReason = $this->first($headers->others[strtolower($this->spamReasonHeader)]) ?? '';
        $spamDescription = $this->first($headers->others[strtolower($this->spamDescriptionHeader)]) ?? '';
        $virusStatus = $this->first($headers->others[strtolower($this->virusStatusHeader)]);
        $virusDescription = $this->first($headers->others[strtolower($this->virusDescriptionHeader)]) ?? '';

        switch ($status) {
            case -1:
                // Cannot check spam, but can check virus
                if ($virusStatus !== "Error")
                    return [
                        "information",
                        "Unable to Scan for Spam",
                        "We're currently unable to check this email for spam due to an error. Please be cautious and avoid clicking on any suspicious links.",
                        ($virusStatus === "Skipped") ? null : $virusDescription
                    ];

                // Can check spam, but cannot check virus
                if ($spamStatus !== "Error")
                    return [
                        "information",
                        "$spamStatus Spam: $spamReason",
                        $spamDescription,
                        "We're currently unable to inspect the attachments due to an error. Please be cautious and avoid opening the attached files.",
                    ];

                // Can check neither
                return [
                    "information",
                    "Unable to Inspect This Email",
                    "We're currently to check this email for spam or virus due to an error. Please be cautious and avoid clicking on any suspicious links or attachments."
                ];

            case 0:
                // Moved from JUNK to INBOX
                if ($spamStatus === 'Probable' || $spamStatus === 'Potential' || $virusStatus === 'Failed' || $dkimStatus === 'Failed')
                    return [
                        "confirmation",
                        "You've moved this mail into your Inbox",
                        "We'll keep an eye on similar messages to improve your email experience."
                    ];

                // Real safe mails
                return [
                    "confirmation",
                    "$spamStatus Spam: $spamReason",
                    $spamDescription,
                    ($virusStatus === "Skipped") ? null : $virusDescription
                ];

            case 1:
                // Moved from INBOX to JUNK
                if ($spamStatus === 'Unlikely' && $virusStatus !== 'Failed')
                    return [
                        "error",
                        "You've moved this mail into your Junk",
                        "We' improve our filters to help keep your Inbox free of unwanted messages"
                    ];

                // DKIM not pass
                if ($dkimStatus === 'Failed')
                    return [
                        "error",
                        "DKIM Verification Failed: Exercise Caution",
                        "This email failed DKIM verification. Exercise caution as it may not be from the stated sender. Avoid clicking on any links or disclosing personal information."
                    ];

                // Real spam mails
                return [
                    "error",
                    "$spamStatus Spam: $spamReason",
                    $spamDescription,
                    ($virusStatus === "Skipped") ? null : $virusDescription
                ];

            case 2:
                // Scan result modified
                return [
                    "error",
                    "Modified Scan Result",
                    "The spam scan result for this email has been altered. Please review with care and avoid clicking on any suspicious links."
                ];

            default:
                return [];
        }
    }
}
