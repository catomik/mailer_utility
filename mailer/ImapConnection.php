<?php
/**
 * ImapConnection.php
 * @copyright ©Iterios
 * @author Valentin Stepanenko catomik13@gmail.com
 */

namespace app\utilities\mailer;


use app\components\mailers\imap\ImapClient;
use SSilence\ImapClient\ImapConnect;

class ImapConnection
{
    /**
     * @var $transport ImapClient
     */
    private $transport;
    private $login;
    private $pass;
    private $host;
    private $port;
    private $enc;

    private $errors = [];

    /**
     * @param array $config
     * @return ImapConnection
     */
    public static function createFromArray(array $config)
    {
        $object = new self(($config['login']??null),($config['pass']??null),($config['host']??null),($config['port']??null),($config['enc']??null));
        return $object;
    }

    /**
     * @param int $length
     * @return string
     */
    protected function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * @param $error
     */
    private function addError($error)
    {
        $this->errors[] = $error;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * ImapConnection constructor.
     * @param $login
     * @param $pass
     * @param $host
     * @param $port
     * @param null $enc
     */
    public function __construct($login,$pass,$host,$port,$enc = null)
    {
        $this->errors = [];
        $this->login = $login;
        $this->pass = $pass;
        $this->host = $host;
        $this->port = (int)$port;
        $this->enc = $enc;
    }

    /**
     *
     */
    private function createConnection()
    {
        $encrypt = ImapConnect::ENCRYPT_NOTLS;
        if($this->enc == 'SSL')
            $encrypt = ImapConnect::ENCRYPT_SSL;
        if($this->enc == 'TLS')
            $encrypt = ImapConnect::ENCRYPT_TLS;
        $this->transport = new ImapClient([
            'flags' => [
                'service' => \SSilence\ImapClient\ImapConnect::SERVICE_IMAP,
                'encrypt' => $encrypt,
                /* This NOVALIDATE_CERT is used when the server connecting to the imap
                 * servers is not https but the imap is. This ignores the failure.
                 */
//                    'validateCertificates' => \SSilence\ImapClient\ImapConnect::NOVALIDATE_CERT
            ],
            'mailbox' => [
                'remote_system_name' => $this->host,
                'port' => $this->port
            ],
            'connect' => [
                'username' => $this->login,
                'password' => $this->pass
            ]
        ]);
    }

    /**
     * @return bool
     */
    public function checkConnection()
    {
//        error_reporting(E_ALL & ~E_WARNING);
        $this->errors = [];
        if($this->transport !== null && $this->transport->isConnected()) {
            return true;
        }
        try {
            $this->createConnection();
        } catch (\Exception $e) {
            $this->addError($e->getMessage());
            error_clear_last();
            return false;
        }
        return true;
    }

    /**
     * @param $login
     */
    public function setLogin($login)
    {
        $this->login = $login;
    }

    /**
     * @param $pass
     */
    public function setPass($pass)
    {
        $this->pass = $pass;
    }

    /**
     * @param $host
     */
    public function setHost($host)
    {
        $this->host = $host;
    }

    /**
     * @param $port
     */
    public function setPort($port)
    {
        $this->port = (int)$port;
    }

    /**
     * @param null $enc
     */
    public function setEnc($enc = null)
    {
        $this->enc = $enc;
    }

    /**
     * @return array
     */
    public function getCurrentConfig()
    {
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
     * @param $folder
     * @return bool|int
     */
    public function getFolderMessageCount($folder)
    {
        if ($this->transport == null) {
            if (!$this->checkConnection())
                return false;
        }

        $folder = $this->transport->selectFolder($folder);
        if(!$folder) {
            $this->addError('Folder not found!');
            return false;
        }

        return $this->transport->countMessages();
    }

    private function fetchPartsAttachments($parts,$section_prefix,&$attachments) {
        $i = 1;
        foreach ($parts as $part) {
            if(isset($part->parts)) {
                $prefix = empty($section_prefix)?$i:$section_prefix.'.'.$i;
                $this->fetchPartsAttachments($part->parts,$prefix,$attachments);
            } else {
                if( array_search($part->type,[TYPETEXT,TYPEMULTIPART,TYPEMESSAGE]) === false) {
                    $info = [];
                    $info['section'] = empty($section_prefix)?$i:$section_prefix.'.'.$i;
                    if(!$part->ifid) {
                        $info['id'] = $info['section'].'|'.$part->bytes;
                    } else {
                        $info['id'] = $part->id;
                    }
                    if($part->ifparameters) {
                        foreach ($part->parameters as $parameter) {
                            if($parameter->attribute == "NAME") {
                                $info['name'] = $parameter->value;
                            }
                        }
                    } elseif ($part->ifdparameters) {
                        foreach ($part->dparameters as $parameter) {
                            if($parameter->attribute == "FILENAME") {
                                $info['name'] = $parameter->value;
                            }
                        }
                    }
                    if(empty($info['name'])) {
                        $info['name'] = $info['id'];
                    }
                    $info['type'] = $part->type;
                    $info['size'] = $part->bytes;
                    $info['encoding'] = $part->encoding;
                    if($part->ifdisposition) {
                        if(strtolower($part->disposition) == 'inline') {
                            $attachments['inline'][] = $info;
                        } elseif (strtolower($part->disposition) == 'attachment') {
                            $attachments['attachment'][] = $info;
                        }
                    }
                }
            }
            $i++;
        }
    }

    public function getAttachmentsInfo($message) {
        $attachment_sections = [];
        foreach ($message->section as $s) {
            if($s >= 2) {
                $attachment_sections[] = $s;
            }
        }
        $attachments = [];

        if(!empty($message->structure->parts)) {
            $this->fetchPartsAttachments($message->structure->parts,'',$attachments);
        }
        return $attachments;
    }

    public function getAttachment($num,$folder,$section,$encoding = null) {
        if(!$this->transport->selectFolder($folder)) {
            $this->addError('Folder not found!');
            return false;
        }
        $data = \imap_fetchbody($this->transport->imap,$num,$section);
        switch ($encoding) {
            case ENCBASE64: return base64_decode($data);
            default: return $data;
        }
    }

    /**
     * @param $message
     * @param null $attachment_path
     * @return array
     */
    private function parseMessage($message)
    {
        $data = [];

        $data['uid'] = $message->header->uid;
        $data['num'] = $message->header->msgno;
        $data['from'] = $message->header->from ?? '';
        $data['to'] = $message->header->to ?? '';
        $data['subject'] = $message->header->subject ?? '';
        $data['timestamp'] = $message->header->udate ?? '';
        $data['seen'] = $message->header->seen ?? 0;
        $data['flagged'] = $message->header->flagged ?? 0;
        $from = $message->header->details->from;
        $to = $message->header->details->to;
        $data['from_email'] = $from[0]->mailbox.'@'.$from[0]->host;
        $data['to_email'] = $to[0]->mailbox.'@'.$to[0]->host;

        $types = $message->message->types;
        $types = \array_flip($types);
        if(isset($types['html'])) {
            $data['message'] = $message->message->html->body;
        } elseif (isset($types['text'])) {
            $data['message'] = $message->message->text->body;
        } elseif (isset($types['plain'])) {
            $data['message'] = $message->message->plain->body;
        } else {
            $data['message'] = '';
        }

        $data['attachments'] = $this->getAttachmentsInfo($message);
        return $data;
    }

    /**
     * @param array $messages
     * @return array
     */
    private function decodeMessages(array $messages)
    {
        $decoded_messages = [];
        foreach ($messages as $message) {
            $decoded_messages[] = $this->parseMessage($message);
        }
        return $decoded_messages;
    }

    /**
     * @param $folder
     * @param int $page
     * @param int $count
     * @return array|bool
     */
    public function getMessagesFromFolder($folder,$page = 1 ,$count = 10)
    {
        if ($this->transport == null) {
            if (!$this->checkConnection())
                return false;
        }

        if(!$this->transport->selectFolder($folder)) {
            $this->addError('Folder not found!');
            return false;
        }

        $total = $this->getFolderMessageCount($folder);
        if($page*$count > $total  && ($page-1)*$count >= $total) {
            $page = 1;
        }
        $page -= 1;
        $messages = $this->transport->getMessages($count,$page);
        $messages = $this->decodeMessages($messages);
        $data = [
            'count' => \count($messages),
            'messages' => $messages,
            'page' => ++$page,
            'total' => $total,
            'per_page' => $count
        ];
        return $data;
    }

    /**
     * @return array
     */
    public function getFoldersList()
    {
        if ($this->transport == null) {
            if (!$this->checkConnection())
                return [];
        }
        $folders =  $this->transport->getFoldersDecode();

        foreach ($folders as $k => $v) {
            $this->transport->selectFolder($v['id']);
            $folders[$k]['unseen'] = $this->transport->countUnreadMessages();
        }
        return $folders;
    }

    /**
     * @param $id
     * @param null $folder
     * @param null $attachment_path
     * @return \app\components\mailers\imap\IncomingMessage|array|bool|object|\SSilence\ImapClient\IncomingMessage
     */
    public function getMessageById($id, $folder = null)
    {
        if ($this->transport == null) {
            if (!$this->checkConnection())
                return false;
        }
        if($folder != null) {
            $this->transport->selectFolder($folder);
        }
        if(!is_array($id))
            $id = (int)$id;

        $message = $this->transport->getMessage($id);
        $message = $this->parseMessage($message);
        return $message;
    }

    /**
     * @param $id
     * @param null $folder
     * @return bool
     */
    public function makeRead($id, $folder = null)
    {
        if ($this->transport == null) {
            if (!$this->checkConnection())
                return false;
        }
        if($folder != null) {
            $this->transport->selectFolder($folder);
        }
        $this->transport->setReaded((int)$id);
        return true;
    }

    /**
     * @param array $ids
     * @param null $folder
     * @return bool
     */
    public function deleteMessages(array $ids,$folder = null)
    {
        if ($this->transport == null) {
            if (!$this->checkConnection())
                return false;
        }
        if($folder != null) {
            $this->transport->selectFolder($folder);
        }
        $this->transport->deleteMessages($ids);
        return true;
    }

    /**
     * @param $id
     * @param $folder
     * @return bool
     */
    public function flagMessage($id,$folder)
    {
        if ($this->transport == null) {
            if (!$this->checkConnection())
                return false;
        }
        $this->transport->selectFolder($folder);
        return $this->transport->setFlagged((int)$id);
    }

    /**
     * @param $id
     * @param $folder
     * @return bool
     */
    public function unflagMessage($id,$folder)
    {
        if ($this->transport == null) {
            if (!$this->checkConnection())
                return false;
        }
        $this->transport->selectFolder($folder);
        return $this->transport->unsetFlagged((int)$id);
    }

    /**
     * @param null $folder
     * @return array|bool
     */
    public function getFolderInfo($folder = null)
    {
        if ($this->transport == null) {
            if (!$this->checkConnection())
                return false;
        }
        if($folder != null) {
            $this->transport->selectFolder($folder);
        }
        return $this->transport->getMailInfo();
    }

    /**
     * @param $message
     * @return bool
     */
    public function saveMessageInSent($message)
    {
        return $this->transport->saveMessageInSentA($message);
    }

    /**
     * @return string
     */
    public function getSent()
    {
        return $this->transport->getSent();
    }
}