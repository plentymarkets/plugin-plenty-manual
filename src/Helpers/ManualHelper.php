<?php
/**
 * Created by PhpStorm.
 * User: externaldev
 * Date: 21.01.19
 * Time: 09:37
 */

namespace PlentyManual\Helpers;

use Plenty\Plugin\Mail\Contracts\MailerContract;


class ManualHelper
{

    /**
     * @var MailerContract
     */
    private $mailerContract;

    public function __construct( MailerContract $mailerContract)
    {
        $this->mailerContract = $mailerContract;
    }


    /**
     * @param $mailContent
     * @param $recipients
     * @param $mailSubject
     */
    public function sendMail( $mailContent, $recipients, $mailSubject )
    {
        $this->mailerContract->sendHtml( $mailContent, $recipients, $mailSubject );
    }

}