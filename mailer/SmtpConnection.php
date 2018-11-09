<?php
/**
 * SmtpConnection.php
 * @copyright ©Iterios
 * @author Valentin Stepanenko catomik13@gmail.com
 */

namespace app\utilities\mailer;


class SmtpConnection
{

    private $transport;
    private $login;
    private $pass;
    private $host;
    private $port;
    private $enc;

    public function __construct($login,$pass,$host,$port,$enc = null)
    {
        $this->login = $login;
        $this->pass = $pass;
        $this->host = $host;
        $this->port = (int)$port;
        $this->enc = $enc;
        $this->transport = new \Swift_SmtpTransport($this->host,$this->port,$this->enc);
        $this->transport->setUsername($login);
        $this->transport->setPassword($pass);

    }

    public static function createFromArray(array $config)
    {
        $object = new self(($config['login']??null),($config['pass']??null),($config['host']??null),($config['port']??null),($config['enc']??null));
        return $object;
    }

    public function checkConnection() {
        try {
            $this->transport->start();
            $this->transport->stop();
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    public function setLogin($login) {
        $this->login = $login;
        $this->transport->setUsername($login);
    }

    public function setPass($pass) {
        $this->pass = $pass;
        $this->transport->setPassword($pass);
    }

    public function setHost($host) {
        $this->host = $host;
        $this->transport->setHost($host);
    }

    public function setPort($port) {
        $this->port = (int)$port;
        $this->transport->setPort((int)$port);
    }

    public function setEnc($enc = null) {
        $this->enc = $enc;
        $this->transport->setEncryption($enc);
    }

    public function getCurrentConfig(){
        $config = [
            'login' => $this->login,
            'pass' => $this->pass,
            'host' => $this->host,
            'port' => $this->port,
            'enc' => $this->enc
        ];
        return $config;
    }

    /**
     * @param $from
     * @param $to
     * @param $subject
     * @param $body
     * @param null $cc
     * @param null $bcc
     * @return \Swift_Message
     */
    public function send($from,$to,$subject,$body,$cc = null,$bcc = null)
    {
        $mailer = new \Swift_Mailer($this->transport);
        $message = new \Swift_Message();
        if($cc != null)
            $message->setCc($cc);
        if($bcc != null)
            $message->setBcc($bcc);
        $message->setFrom($from);
        $message->setTo($to);
        $message->setSubject($subject);
        $message->setBody($body,'text/html');
        $message->addPart(html_entity_decode(strip_tags($body)),'text/plain');
        $mailer->send($message);
        return $message;
    }
}