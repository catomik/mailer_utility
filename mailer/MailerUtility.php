<?php
/**
 * MailerUtility.php
 * @copyright ©Iterios
 * @author Valentin Stepanenko catomik13@gmail.com
 */

namespace app\utilities\mailer;


use app\helpers\PrintHelper;
use app\models\ContactsEmails;
use app\models\MailerCache;
use app\models\Tenants;

class MailerUtility
{
    /**
     * @var $imap_connection ImapConnection
     * @var $smtp_connection SmtpConnection
     * @var $tenant Tenants
     */
    private $imap_connection;
    private $smtp_connection;
    private $tenant;
    private $attachments_base_folder;

    const ERROR_INVALID_IMAP_CONFIGURATION = 1;
    const ERROR_INVALID_SMTP_CONFIGURATION = 2;

    /**
     * MailerUtility constructor
     * @param $tenant Tenants
     */
    public function __construct($tenant,$attachments_base_folder = null)
    {
        $config = $tenant->mailer_config;
        $this->attachments_base_folder = $attachments_base_folder;
        if(!empty($config)){
            $config = \json_decode($config,true);
            if(!isset($config['imap']) || !isset($config['smtp']))
                return;
            $this->imap_connection = ImapConnection::createFromArray($config['imap']);
            $this->smtp_connection = SmtpConnection::createFromArray($config['smtp']);

        }
        $this->tenant = $tenant;
    }

    public function checkConnection()
    {
        $this->getImapConnection();
        $this->getSmtpConnection();
    }

    /**
     * @return ImapConnection
     * @throws \Exception
     */
    private function getImapConnection() {
        if($this->imap_connection === null || !$this->imap_connection->checkConnection())
            throw new \Exception("Invalid IMAP confoguration",self::ERROR_INVALID_IMAP_CONFIGURATION);
        return $this->imap_connection;
    }

    /**
     * @return SmtpConnection
     * @throws \Exception
     */
    private function getSmtpConnection() {
        if($this->smtp_connection === null && !$this->smtp_connection->checkConnection())
            throw new \Exception("Invalid SMTP confoguration",self::ERROR_INVALID_SMTP_CONFIGURATION);
        return $this->smtp_connection;
    }

    public function getFoldersList()
    {
        $cache = $this->tenant->mailer_folders_config;
        if($cache == null) {
            $connection = $this->getImapConnection();
            $list = $connection->getFoldersList();
            $this->tenant->mailer_folders_config = \json_encode($list);
            $this->tenant->save();
            return $list;
        }
        $list = \json_decode($cache,true);

        $folders = \array_column($list,'id');

        $messages = MailerCache::find()->where(['in','folder',$folders])->andWhere(['seen'=>0])
            ->andWhere(['tenant_id'=>$this->tenant->id])->asArray()->all();

        foreach ($list as $k => $folder) {
            $useen = \array_filter($messages,function($item)use($folder){
                return $folder['id'] == $item['folder'];
            });
            $list[$k]['unseen'] = \count($useen);
        }
        return $list;
    }

    public function getMessagesFromFolder($folder,$page = 1 ,$count = 10)
    {
        $query = MailerCache::find()->where(['folder'=>$folder])->andWhere(['tenant_id'=>$this->tenant->id]);
        $total = $query->count();

        if($page*$count > $total && ($page-1)*$count >= $total) {
            $page = 1;
        }

        $messages = $query->limit($count)->offset(($page-1)*$count)->orderBy(['timestamp'=>SORT_DESC])->asArray()->all();
        foreach ($messages as $k => $message) {
            $messages[$k]['attachments'] = json_decode($message['attachments'],true);
        }
        $data = [
            'count' => \count($messages),
            'messages' => $messages,
            'page' => $page,
            'total' => $total,
            'per_page' => $count
        ];

        return $data;
    }

    public function flagMessage($id,$folder)
    {
        $connection = $this->getImapConnection();
        $result = $connection->flagMessage((int)$id,$folder);
        if($result) {
            /**
             * @var $cache MailerCache
             */
            $cache = MailerCache::find()->where(['uid'=>(int)$id])->andWhere(['folder'=>$folder])->andWhere(['tenant_id'=>$this->tenant->id])->one();
            if($cache != null) {
                $cache->flagged = 1;
                $cache->save();
            }
            return true;
        }
        return false;
    }

    public function unflagMessage($id,$folder)
    {
        $connection = $this->getImapConnection();
        $connection->
        $result = $connection->unflagMessage((int)$id,$folder);
        if($result) {
            /**
             * @var $cache MailerCache
             */
            $cache = MailerCache::find()->where(['uid'=>(int)$id])->andWhere(['folder'=>$folder])->andWhere(['tenant_id'=>$this->tenant->id])->one();
            if($cache != null) {
                $cache->flagged = 0;
                $cache->save();
            }
            return true;
        }
        return false;
    }

    public function deleteMessages(array $ids,$folder)
    {
        $connection = $this->getImapConnection();
        if($connection->deleteMessages($ids,$folder)) {
            MailerCache::deleteAll(['and',['in','uid',$ids],['tenant_id'=>$this->tenant->id]]);
            return true;
        }
        return false;
    }

    public function getMessageById($id, $folder)
    {
        $cache = MailerCache::find()->where(['folder'=>$folder])->andWhere(['num'=>$id])->andWhere(['tenant_id'=>$this->tenant->id])->one();
        if($cache != null && $cache->contact_id != null) {
            $data = $cache->toArray();
            $data['attachments'] = json_decode($data['attachments'],true);
            return $data;
        }

//        $att_folder_uid = $this->tenant->id.'|'.$id.'|'.$folder;
//        $att_folder_uid = base64_encode($att_folder_uid);
//        $att_folder_path = $this->attachments_base_folder.'/'.$att_folder_uid;

        $connection = $this->getImapConnection();
        $message = $connection->getMessageById($id,$folder);
        return $message;
    }

    public function getAttachmentFromMessage($id,$folder,$attachment_id) {
        $message = $this->getMessageById($id,$folder);
        if(empty($message['attachments']))
            return false;
        if(isset($message['attachments']['inline'])) {
            foreach ($message['attachments']['inline'] as $attachment) {
                if($attachment['id'] == $attachment_id) {
                    $attachment['data'] = $this->getImapConnection()->getAttachment($id,$folder,$attachment['section'],$attachment['encoding']);
                    return $attachment;
                }
            }
        }
        if(isset($message['attachments']['attachment'])) {
            foreach ($message['attachments']['attachment'] as $attachment) {
                if($attachment['id'] == $attachment_id) {
                    $attachment['data'] = $this->getImapConnection()->getAttachment($id,$folder,$attachment['section'],$attachment['encoding']);
                    return $attachment;
                }
            }
        }
        return false;
    }

    public function makeRead($id, $folder)
    {
        $connection = $this->getImapConnection();
        $result = $connection->makeRead((int)$id,$folder);
        if($result) {
            /**
             * @var $cache MailerCache
             */
            $cache = $cache = MailerCache::find()->where(['folder'=>$folder])->andWhere(['num'=>$id])->andWhere(['tenant_id'=>$this->tenant->id])->one();
            if($cache != null) {
                $cache->seen = 1;
                $cache->save();
            }
            return true;
        }
        return false;
    }

    public function importMessage($uid,$folder)
    {
        $connection = $this->getImapConnection();
        $message = $connection->getMessageById(['uid'=>$uid],$folder);
        $cache = MailerCache::find()->where(['uid'=>$uid])->andWhere(['folder'=>$folder])
            ->andWhere(['tenant_id'=>$this->tenant->id])->one();
        if($cache == null) {
            $cache = new MailerCache();
        }
        $emails = [$message['from_email'],$message['to_email']];
        $contact = ContactsEmails::find()->where(['in','value',$emails])->one();
        if($contact != null) {
            $cache->contact_id = $contact->contact_id;
        } else {
            unset($message['message']);
        }
        $cache->attachments = \json_encode($message['attachments']);
        unset($message['attachments']);
        $cache->attributes = $message;

        $cache->folder = $folder;
        $cache->tenant_id = $this->tenant->id;
        $cache->save();
    }

    public function synchronizeFolder($folder)
    {
        $connection = $this->getImapConnection();
        if(\Yii::$app->id == 'basic-console') {
            PrintHelper::printMessage('Checking folder: '.$folder['name']);
        }
        $messages = MailerCache::find()->where(['like','folder',$folder['id']])->indexBy('uid')->all();
        $info = $connection->getFolderInfo($folder['id']);
//        PrintHelper::printMessage('info: '.\json_encode($info['all_uid']));

        $all_ids = \array_keys($messages);
        /*
         * Total messages
         */
        if(isset($info['all_uid']) && $info['all_uid']) {
            $diff = \array_diff($all_ids,$info['all_uid']);

            if(\Yii::$app->id == 'basic-console') {
                PrintHelper::printMessage('Messages to delete: '.\count($diff));
            }
            foreach ($diff as $i) {
                $messages[$i]->delete();
            }

            $diff = \array_diff($info['all_uid'],$all_ids);
            $total = \count($diff);
            if(\Yii::$app->id == 'basic-console') {
                PrintHelper::printMessage('Messages to add: '.$total);
            }

            $k = 0;
            \arsort($diff);
            foreach ($diff as $i) {
                if(\Yii::$app->id == 'basic-console') {
                    PrintHelper::printMessage('Import message [uid='.$i.']',PrintHelper::WARNING_TYPE);
                }
                $this->importMessage($i,$folder['id']);
                $k++;
                if(\Yii::$app->id == 'basic-console') {
                    PrintHelper::printMessage('Message imported ['.$k.'/'.$total.']',PrintHelper::SUCCESS_TYPE);
                }

            }
        }

        /*
         * Seen messages
         */
        if(isset($info['seen_uid']) && $info['seen_uid']) {
            $messages_seen = \array_filter($messages,function($item){
                return $item->seen == 1;
            });

            $seen_ids = \array_keys($messages_seen);
            $diff = \array_diff($seen_ids,$info['seen_uid']);
            if(\Yii::$app->id == 'basic-console') {
                PrintHelper::printMessage('Messages to unseen: '.\count($diff));
            }
            foreach ($diff as $i) {
                if(isset($messages[$i])){
                    $messages[$i]->seen = 0;
                    $messages[$i]->save();
                }
            }

            $diff = \array_diff($info['seen_uid'],$seen_ids);
            if(\Yii::$app->id == 'basic-console') {
                PrintHelper::printMessage('Messages to seen: '.\count($diff));
            }
            foreach ($diff as $i) {
                if(isset($messages[$i])){
                    $messages[$i]->seen = 1;
                    $messages[$i]->save();
                }
            }
        }

        /*
         * Flagged messages
         */
        if(isset($info['flagged_uid']) && $info['flagged_uid']) {
            $messages_flagged = \array_filter($messages,function($item){
                return $item->flagged == 1;
            });
            $flagged_ids = \array_keys($messages_flagged);
            $diff = \array_diff($flagged_ids,$info['flagged_uid']);
            if(\Yii::$app->id == 'basic-console') {
                PrintHelper::printMessage('Messages to unflag: '.\count($diff));
            }
            foreach ($diff as $i) {
                if(isset($messages[$i])){
                    $messages[$i]->flagged = 0;
                    $messages[$i]->save();
                }
            }

            $diff = \array_diff($info['flagged_uid'],$flagged_ids);
            if(\Yii::$app->id == 'basic-console') {
                PrintHelper::printMessage('Messages to flag: '.\count($diff));
            }
            foreach ($diff as $i) {
                if(isset($messages[$i])){
                    $messages[$i]->flagged = 1;
                    $messages[$i]->save();
                }
            }
        }
    }

    public function synchronize($folder = null)
    {
        $connection = $this->getImapConnection();
        $folders = $connection->getFoldersList();
        $this->tenant->mailer_folders_config = \json_encode($folders);
        $this->tenant->save();
        if(\Yii::$app->id === 'basic-console') {
            PrintHelper::printMessage('Start messages synchronize for tenant: '.$this->tenant->id,PrintHelper::INFO_TYPE);
        }
        $folders = \array_combine(\array_column($folders,'id'),$folders);
        if($folder) {
            if(isset($folders[$folder])) {
                $this->synchronizeFolder($folders[$folder]);
                return;
            } else {
                throw new \Exception('Folder nor found');
            }
        }
        foreach ($folders as $folder_i) {
            $this->synchronizeFolder($folder_i);
        }
    }

    public function sendMessage($from,$to,$subject,$body,$cc = null,$bcc = null)
    {
        $smtp = $this->getSmtpConnection();
        $message = $smtp->send($from,$to,$subject,$body,$cc,$bcc);

        $imap = $this->getImapConnection();
        $move = $imap->saveMessageInSent($message->toString());
        $errors = imap_errors();
        $folders = $this->getFoldersList();
        $sent = $imap->getSent();
        $ids = \array_column($folders,'id');
        if(\in_array($sent,$ids)) {
            return $sent;
        }
        return true;
    }
}