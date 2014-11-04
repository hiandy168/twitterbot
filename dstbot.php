<?php
require_once('./twitteroauth.php');
require_once('./dstbot.inc.php');

/*
 * TODO:
 * . get data for all dst settings per country
 * - tweet warning about DST clock change 7 days, 1 day in advance + moment of change
 * - apply for multiple countries, timezones (?)
 * - reply to command: when is next DST in (country)?
 * - reply to command: what time is it now in (country)?
 *   - or get country from profile
 *
 * SPECIAL CASES:
 * - brazil dst end is delayed by 1 week during carnival week, so would be 4th sunday of february instead of 3rd
 */

$o = new DstBot(array('sUsername' => 'DSTnotify'));
$o->run();

class DstBot {

    private $sUsername;
    private $sLogFile;

    /*
     * GROUPS:
     * - Europe, except Armenia, Belarus, Georgia, Iceland, Russia (and Crimea of Ukrain)
     *   - also includes Lebanon, Morocco, Western Sahara
     * - North America, except Mexico and Greenland
     *   - also includes Cuba, Haiti, Turks and Caicos
     * - Jordan, Palestine, Syria
     * - Samoa, New Zealand
     * - everything else single countries
     * - no DST group
     */

    private $aSettings;

    public function __construct($aArgs) {

        //connect to twitter
        $this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
        $this->oTwitter->host = "https://api.twitter.com/1.1/";

        //make output visible in browser
        if (!empty($_SERVER['HTTP_HOST'])) {
            echo '<pre>';
        }

        //load args
        $this->parseArgs($aArgs);
    }

    private function parseArgs($aArgs) {

        $this->sUsername        = (!empty($aArgs['sUsername'])      ? $aArgs['sUsername']       : '');
        $this->sSettingsFile    = (!empty($aArgs['sSettingsFile'])  ? $aArgs['sSettingsFile']   : strtolower(__CLASS__) . '.json');
        $this->sLogFile         = (!empty($aArgs['sLogFile'])       ? $aArgs['sLogFile']        : strtolower(__CLASS__) . '.log');

        $this->aSettings = @json_decode(file_get_contents($this->sSettingsFile), TRUE);
        if (!$this->aSettings) {
            $this->logger(1, sprintf('Failed to load settings file. (%s)', json_last_error_msg()));
            $this->halt(sprintf('Failed to load settings files. (%s)', json_last_error_msg()));
            die();
        }

        if ($this->sLogFile == '.log') {
            $this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
        }
    }

    public function run() {

        //check if auth is ok
        if ($this->getIdentity()) {

            //check for upcoming DST changes and tweet about it
            $this->checkDST();

            //check for menions and reply
            $this->checkMentions();

            $this->halt('Done.');
        }
    }

    private function getIdentity() {

        //DEBUG
        return true;

        echo "Fetching identity..\n";

        if (!$this->sUsername) {
            $this->logger(2, 'No username');
            $this->halt('- No username! Set username when calling constructor.');
            return FALSE;
        }

        $oCurrentUser = $this->oTwitter->get('account/verify_credentials', array('include_entities' => FALSE, 'skip_status' => TRUE));

        if (is_object($oCurrentUser) && !empty($oCurrentUser->screen_name)) {
            if ($oCurrentUser->screen_name == $this->sUsername) {
                printf("- Allowed: @%s, continuing.\n\n", $oCurrentUser->screen_name);
            } else {
                $this->logger(2, sprintf('Authenticated username was unexpected: %s (expected: %s)', $oCurrentUser->screen_name, $this->sUsername));
                $this->halt(sprintf('- Not allowed: @%s (expected: %s), halting.', $oCurrentUser->screen_name, $this->sUsername));
                return FALSE;
            }
        } else {
            $this->logger(2, sprintf('Twitter API call failed: GET account/verify_credentials (%s)', $oCurrentUser->errors[0]->message));
            $this->halt(sprintf('- Call failed, halting. (%s)', $oCurrentUser->errors[0]->message));
            return FALSE;
        }

        return TRUE;
    }

    private function checkDST() {

        //check if any of the countries are switching to DST (summer time) NOW
        echo "Checking for DST start..\n";
        if ($aGroups = $this->checkDSTStart(time())) {

            $this->postTweetDSTStart($aGroups, 'now');
        }

        die('stop');

        //check if any of the countries are switching to DST (summer time) in 24 hours
        echo "Checking for DST start in 24 hours..\n";
        if ($aGroups = $this->checkDSTStart(time() + 24 * 3600)) {

            $this->postTweetDSTStart($aGroups, '24 hours');
        }

        //check if any of the countries are switching to DST (summer time) in 7 days
        echo "Checking for DST start in 1 week..\n";
        if ($aGroups = $this->checkDSTStart(time() + 7 * 24 * 3600)) {

            $this->postTweetDSTStart($aGroups, '1 week');
        }

        //check if any of the countries are switching from DST (winter time) NOW
        echo "Checking for DST end..\n";
        $this->checkDSTEnd(time());

        //check if any of the countries are switching from DST (winter time) in 24 hours
        echo "Checking for DST end in 24 hours..\n";
        $this->checkDSTEnd(time() + 24 * 3600);

        //check if any of the countries are switching from DST (winter time) in 7 days
        echo "Checking for DST end in 1 week..\n";
        $this->checkDSTEnd(time() + 7 * 24 * 3600);

        return TRUE;
    }

    //check if DST starts (summer time start) for any of the countries
    private function checkDSTStart($iTimestamp) {

        $aGroupsDSTStart = array();
        foreach ($this->aSettings as $sGroup => $aSetting) {

            if ($sGroup != 'no dst') {

                //convert 'last sunday of march 2014' to timestamp
                $iDSTStart = strtotime($aSetting['start'] . ' ' . date('Y'));

                //error margin of 1 minute
                if ($iDSTStart >= $iTimestamp - 60 && $iDSTStart <= $iTimestamp + 60) {

                    //DST will start here
                    $aGroupsDSTStart[] = $sGroup;
                }
            }
        }

        return ($aGroupsDSTStart ? $aGroupsDSTStart : FALSE);
    }

    //check if DST ends (winter time start) for any of the countries
    private function checkDSTEnd($iTimestamp) {
    }

    private function postTweetDSTStart($aGroups) {
    }

    private function postTweetDSTEnd($aGroups) {
    }

    private function halt($sMessage = '') {
        echo $sMessage . "\n\nDone!\n\n";
        return FALSE;
    }

    private function logger($iLevel, $sMessage) {

        $sLogLine = "%s [%s] %s\n";
        $sTimestamp = date('Y-m-d H:i:s');

        switch($iLevel) {
        case 1:
            $sLevel = 'FATAL';
            break;
        case 2:
            $sLevel = 'ERROR';
            break;
        case 3:
            $sLevel = 'WARN';
            break;
        case 4:
        default:
            $sLevel = 'INFO';
            break;
        case 5:
            $sLevel = 'DEBUG';
            break;
        case 6:
            $sLevel = 'TRACE';
            break;
        }

        $iRet = file_put_contents($this->sLogFile, sprintf($sLogLine, $sTimestamp, $sLevel, $sMessage), FILE_APPEND);

        if ($iRet === FALSE) {
            die($sTimestamp . ' [FATAL] Unable to write to logfile!');
        }
    }
}